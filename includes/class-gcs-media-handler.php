<?php
/**
 * Just GCS Offload Media Handler
 * Hooks into WordPress media upload, URL rewrite, and deletion processes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Just_WP_GCS_Media_Handler {

	/**
	 * @var Just_WP_GCS_Client
	 */
	private $client;

	/**
	 * Constructor
	 */
	public function __construct( $client ) {
		$this->client = $client;

		// Hook into metadata generation to upload files to GCS
		add_filter( 'wp_update_attachment_metadata', array( $this, 'upload_attachment_files' ), 10, 2 );

		// Hook into URL retrieval filters to rewrite local URLs to GCS URLs
		add_filter( 'wp_get_attachment_url', array( $this, 'gcs_get_attachment_url' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 'gcs_image_downsize' ), 10, 3 );
		add_filter( 'wp_calculate_image_srcset_sources', array( $this, 'gcs_image_srcset_sources' ), 10, 5 );

		// Hook into attachment deletion to clean up GCS files
		add_action( 'delete_attachment', array( $this, 'delete_attachment_files' ) );
	}

	/**
	 * Upload attachment original and sub-size files to GCS.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment post ID.
	 * @return array Modified metadata.
	 */
	public function upload_attachment_files( $metadata, $attachment_id ) {
		// Prevent double uploads or processing if already done
		if ( get_post_meta( $attachment_id, '_wp_gcs_processing', true ) ) {
			return $metadata;
		}

		$bucket = get_option( 'just_wp_gcs_bucket' );
		if ( empty( $bucket ) ) {
			return $metadata;
		}

		update_post_meta( $attachment_id, '_wp_gcs_processing', '1' );

		$prefix     = get_option( 'just_wp_gcs_prefix', '' );
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		// Retrieve the main file path
		$main_file = isset( $metadata['file'] ) ? $metadata['file'] : get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( empty( $main_file ) ) {
			delete_post_meta( $attachment_id, '_wp_gcs_processing' );
			return $metadata;
		}

		$local_main_file = $basedir . '/' . $main_file;
		$relative_dir    = dirname( $main_file );
		if ( $relative_dir === '.' ) {
			$relative_dir = '';
		}

		$files_to_upload = array();
		
		// 1. Add main file to upload queue
		if ( file_exists( $local_main_file ) ) {
			$gcs_main_key = $this->build_gcs_key( $prefix, $main_file );
			$files_to_upload[] = array(
				'local_path' => $local_main_file,
				'gcs_key'    => $gcs_main_key,
			);
		}

		// 2. Add the pre-conversion/pre-scaled original image (e.g. the JPEG source of a
		// WebP conversion) so wp_get_original_image_url() also resolves on GCS
		if ( ! empty( $metadata['original_image'] ) ) {
			$relative_original_path = $relative_dir ? $relative_dir . '/' . $metadata['original_image'] : $metadata['original_image'];
			$local_original_file    = $basedir . '/' . $relative_original_path;
			if ( $relative_original_path !== $main_file && file_exists( $local_original_file ) ) {
				$files_to_upload[] = array(
					'local_path' => $local_original_file,
					'gcs_key'    => $this->build_gcs_key( $prefix, $relative_original_path ),
				);
			}
		}

		// 3. Add all intermediate size files to upload queue
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}
				$size_file_name = $size_info['file'];
				$relative_size_path = $relative_dir ? $relative_dir . '/' . $size_file_name : $size_file_name;
				$local_size_file = $basedir . '/' . $relative_size_path;
				
				if ( file_exists( $local_size_file ) ) {
					$gcs_size_key = $this->build_gcs_key( $prefix, $relative_size_path );
					$files_to_upload[] = array(
						'local_path' => $local_size_file,
						'gcs_key'    => $gcs_size_key,
					);
				}
			}
		}

		// 4. Perform uploads
		$uploaded_successfully = array();
		$failed_uploads        = array();

		foreach ( $files_to_upload as $file_info ) {
			$upload = $this->client->upload_file( $file_info['local_path'], $file_info['gcs_key'] );
			if ( is_wp_error( $upload ) ) {
				$failed_uploads[] = array(
					'file'  => $file_info['local_path'],
					'error' => $upload->get_error_message()
				);
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'Just GCS Offload: Upload failed for %s. Error: %s', $file_info['local_path'], $upload->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG is enabled.
				}
			} else {
				$uploaded_successfully[] = $file_info;
			}
		}

		// 5. Save metadata flag and delete local files if configured and everything succeeded
		if ( count( $uploaded_successfully ) > 0 && count( $failed_uploads ) === 0 ) {
			// Save sync metadata
			$gcs_info = array(
				'bucket' => $bucket,
				'prefix' => $prefix,
				'file'   => $main_file
			);
			update_post_meta( $attachment_id, '_wp_gcs_info', $gcs_info );

			// Check delete local config
			$delete_local = get_option( 'just_wp_gcs_delete_local', '0' );
			if ( $delete_local === '1' ) {
				foreach ( $uploaded_successfully as $file_info ) {
					wp_delete_file( $file_info['local_path'] );
				}
			}
		}

		delete_post_meta( $attachment_id, '_wp_gcs_processing' );
		return $metadata;
	}

	/**
	 * Rewrite attachment URL to GCS URL.
	 */
	public function gcs_get_attachment_url( $url, $attachment_id ) {
		$gcs_info = get_post_meta( $attachment_id, '_wp_gcs_info', true );
		if ( ! $gcs_info || ! is_array( $gcs_info ) || empty( $gcs_info['bucket'] ) ) {
			return $url;
		}

		$upload_dir = wp_upload_dir();
		$baseurl    = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';
		if ( empty( $baseurl ) || empty( $url ) ) {
			return $url;
		}

		// If URL matches local uploads URL base, rewrite it
		if ( strpos( $url, $baseurl ) === 0 ) {
			$relative_path = substr( $url, strlen( $baseurl ) );
			$relative_path = ltrim( $relative_path, '/' );
			return $this->get_gcs_url( $gcs_info, $relative_path );
		}

		return $url;
	}

	/**
	 * Short-circuit image downsizing to return GCS URL and size details.
	 */
	public function gcs_image_downsize( $downsize, $attachment_id, $size ) {
		$gcs_info = get_post_meta( $attachment_id, '_wp_gcs_info', true );
		if ( ! $gcs_info || ! is_array( $gcs_info ) || empty( $gcs_info['bucket'] ) ) {
			return false; // Skip and let WP handle locally
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! $metadata || ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return false;
		}

		$relative_dir = dirname( $metadata['file'] );
		if ( $relative_dir === '.' ) {
			$relative_dir = '';
		}

		$width           = 0;
		$height          = 0;
		$is_intermediate = false;
		$file_name       = '';

		if ( $size === 'full' ) {
			$file_name = basename( $metadata['file'] );
			$width     = isset( $metadata['width'] ) ? $metadata['width'] : 0;
			$height    = isset( $metadata['height'] ) ? $metadata['height'] : 0;
		} elseif ( is_string( $size ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) && isset( $metadata['sizes'][ $size ] ) && is_array( $metadata['sizes'][ $size ] ) ) {
			$size_data = $metadata['sizes'][ $size ];
			$file_name       = isset( $size_data['file'] ) ? $size_data['file'] : '';
			$width           = isset( $size_data['width'] ) ? $size_data['width'] : 0;
			$height          = isset( $size_data['height'] ) ? $size_data['height'] : 0;
			$is_intermediate = true;
		} else {
			// Fallback: If it's a width/height array or unregistered size
			if ( is_array( $size ) && isset( $size[0] ) && isset( $size[1] ) ) {
				$file_name = basename( $metadata['file'] );
				$width     = $size[0];
				$height    = $size[1];
			} else {
				return false;
			}
		}

		if ( empty( $file_name ) ) {
			return false;
		}

		$relative_path = $relative_dir ? $relative_dir . '/' . $file_name : $file_name;
		$url           = $this->get_gcs_url( $gcs_info, $relative_path );

		return array( $url, $width, $height, $is_intermediate );
	}

	/**
	 * Rewrite URLs inside the image srcset attribute.
	 */
	public function gcs_image_srcset_sources( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$gcs_info = get_post_meta( $attachment_id, '_wp_gcs_info', true );
		if ( ! $gcs_info || ! is_array( $gcs_info ) || empty( $gcs_info['bucket'] ) || ! is_array( $sources ) ) {
			return $sources;
		}

		$upload_dir = wp_upload_dir();
		$baseurl    = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';
		if ( empty( $baseurl ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( ! is_array( $source ) || empty( $source['url'] ) ) {
				continue;
			}
			$source_url = $source['url'];
			if ( strpos( $source_url, $baseurl ) === 0 ) {
				$relative_path = substr( $source_url, strlen( $baseurl ) );
				$relative_path = ltrim( $relative_path, '/' );
				$sources[ $width ]['url'] = $this->get_gcs_url( $gcs_info, $relative_path );
			}
		}

		return $sources;
	}

	/**
	 * Clean up GCS objects when attachment is deleted.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public function delete_attachment_files( $attachment_id ) {
		$gcs_info = get_post_meta( $attachment_id, '_wp_gcs_info', true );
		if ( ! $gcs_info || ! is_array( $gcs_info ) || empty( $gcs_info['bucket'] ) ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! $metadata || ! is_array( $metadata ) ) {
			return;
		}

		$prefix       = isset( $gcs_info['prefix'] ) ? $gcs_info['prefix'] : '';
		$main_file    = isset( $metadata['file'] ) ? $metadata['file'] : '';
		$relative_dir = dirname( $main_file );
		if ( $relative_dir === '.' ) {
			$relative_dir = '';
		}

		// Delete original GCS key
		if ( ! empty( $main_file ) ) {
			$gcs_main_key = $this->build_gcs_key( $prefix, $main_file );
			$this->client->delete_file( $gcs_main_key );
		}

		// Delete the pre-conversion original image key
		if ( ! empty( $metadata['original_image'] ) ) {
			$relative_original_path = $relative_dir ? $relative_dir . '/' . $metadata['original_image'] : $metadata['original_image'];
			if ( $relative_original_path !== $main_file ) {
				$this->client->delete_file( $this->build_gcs_key( $prefix, $relative_original_path ) );
			}
		}

		// Delete sizes keys
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}
				$size_file_name = $size_info['file'];
				$relative_size_path = $relative_dir ? $relative_dir . '/' . $size_file_name : $size_file_name;
				$gcs_size_key = $this->build_gcs_key( $prefix, $relative_size_path );
				$this->client->delete_file( $gcs_size_key );
			}
		}
	}

	/**
	 * Build GCS Key combining prefix and relative file path.
	 */
	private function build_gcs_key( $prefix, $relative_path ) {
		$key = $relative_path;
		if ( ! empty( $prefix ) ) {
			$key = $prefix . '/' . $key;
		}
		return ltrim( $key, '/' );
	}

	/**
	 * Generate fully-qualified GCS or CDN URL.
	 */
	private function get_gcs_url( $gcs_info, $relative_path ) {
		if ( ! is_array( $gcs_info ) ) {
			return '';
		}
		$custom_domain = get_option( 'just_wp_gcs_custom_domain' );
		$prefix        = isset( $gcs_info['prefix'] ) ? $gcs_info['prefix'] : '';
		$bucket        = isset( $gcs_info['bucket'] ) ? $gcs_info['bucket'] : '';

		$gcs_key = $this->build_gcs_key( $prefix, $relative_path );

		if ( ! empty( $custom_domain ) ) {
			return rtrim( $custom_domain, '/' ) . '/' . $gcs_key;
		} else {
			return 'https://storage.googleapis.com/' . $bucket . '/' . $gcs_key;
		}
	}
}
