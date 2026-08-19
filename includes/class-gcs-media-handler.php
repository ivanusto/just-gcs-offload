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

		// Fetch offloaded files back from GCS on demand when a local copy is needed
		// (e.g. the built-in image editor) but was deleted after offload
		add_filter( 'get_attached_file', array( $this, 'maybe_rehydrate_local_file' ), 10, 2 );
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

		// Some attachments, such as the site icon, must remain on local storage.
		if ( $this->should_skip_attachment( $attachment_id ) ) {
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

			// The object is in the bucket again, so a previously failed
			// rehydration attempt must no longer be suppressed.
			delete_transient( $this->rehydrate_failure_key( $attachment_id ) );

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
	 * Determine whether a missing local file may be fetched back from GCS.
	 *
	 * `get_attached_file` also fires on read-only paths, most importantly
	 * wp_prepare_attachment_for_js(), which core runs once per attachment
	 * whenever the Media Library grid, the block editor media picker, or any
	 * similar browser loads a page of results. Rehydrating there turns a single
	 * screen into dozens of full-size bucket downloads, so the default is to
	 * refuse and allow only the requests that genuinely need bytes on disk.
	 *
	 * @since 1.4.1
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True when rehydration is allowed for the current request.
	 */
	private function should_rehydrate( $attachment_id ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$allow = true;
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads the action name to identify the context; core authorises the request itself.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$allow  = in_array( $action, array( 'image-editor', 'imgedit-preview', 'crop-image' ), true );
		}

		/**
		 * Filters whether a missing local file may be downloaded back from GCS.
		 *
		 * Tools that legitimately need the local original, such as thumbnail
		 * regenerators, can opt in through this filter.
		 *
		 * @since 1.4.1
		 *
		 * @param bool $allow         Whether rehydration is allowed.
		 * @param int  $attachment_id Attachment post ID.
		 */
		return (bool) apply_filters( 'just_wp_gcs_rehydrate', $allow, $attachment_id );
	}

	/**
	 * Download the attached file back from GCS when the local copy is missing.
	 *
	 * Runs only in the contexts allowed by should_rehydrate(), so browsing the
	 * Media Library never triggers bucket downloads. Each attachment is
	 * attempted at most once per request, and a failed attempt is remembered for
	 * an hour so a missing object is not re-requested on every page load.
	 *
	 * @param string $file          Local file path.
	 * @param int    $attachment_id Attachment post ID.
	 * @return string The local file path (unchanged).
	 */
	public function maybe_rehydrate_local_file( $file, $attachment_id ) {
		static $attempted = array();

		if ( empty( $file ) || isset( $attempted[ $attachment_id ] ) || file_exists( $file ) ) {
			return $file;
		}

		if ( ! $this->should_rehydrate( $attachment_id ) ) {
			return $file;
		}

		$failure_key = $this->rehydrate_failure_key( $attachment_id );
		if ( get_transient( $failure_key ) ) {
			return $file;
		}

		$gcs_info = get_post_meta( $attachment_id, '_wp_gcs_info', true );
		if ( ! $gcs_info || ! is_array( $gcs_info ) || empty( $gcs_info['bucket'] ) ) {
			return $file;
		}

		$attempted[ $attachment_id ] = true;

		$relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( empty( $relative_path ) ) {
			return $file;
		}

		$prefix = isset( $gcs_info['prefix'] ) ? $gcs_info['prefix'] : '';
		$result = $this->client->download_file( $this->build_gcs_key( $prefix, $relative_path ), $file );

		if ( is_wp_error( $result ) ) {
			// Remember the failure so an object that is missing from the bucket
			// is not requested again on every subsequent request.
			set_transient( $failure_key, 1, HOUR_IN_SECONDS );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'Just GCS Offload: Rehydrate failed for attachment %d: %s', $attachment_id, $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG is enabled.
			}
		}

		return $file;
	}

	/**
	 * Transient key holding the last failed rehydration attempt.
	 *
	 * @since 1.4.1
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string Transient key.
	 */
	private function rehydrate_failure_key( $attachment_id ) {
		return 'just_wp_gcs_nodl_' . (int) $attachment_id;
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
			$gcs_url       = $this->get_gcs_url( $gcs_info, $relative_path );
			if ( ! empty( $gcs_url ) ) {
				return $gcs_url;
			}
		}

		return $url;
	}

	/**
	 * Short-circuit image downsizing to return GCS URL and size details.
	 *
	 * @param bool|array   $downsize      Short-circuit value from earlier filters.
	 * @param int          $attachment_id Attachment post ID.
	 * @param string|int[] $size          Registered size name, or array( width, height ).
	 * @return array|false array( url, width, height, is_intermediate ), or false to defer to WordPress.
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
			$width     = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
			$height    = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;
		} else {
			/*
			 * Let core resolve the requested size. This covers registered size
			 * names and array( width, height ) requests, for which it picks the
			 * smallest sub-size that is large enough and matches the aspect ratio,
			 * and constrains the reported dimensions to the requested box. It
			 * reads metadata only and never calls image_downsize(), so it cannot
			 * recurse back into this filter.
			 */
			$intermediate = image_get_intermediate_size( $attachment_id, $size );

			if ( ! is_array( $intermediate ) || empty( $intermediate['file'] ) ) {
				/*
				 * No sub-size matched, e.g. sub-sizes were never generated.
				 * Returning false hands control back to image_downsize(), which
				 * falls back to the original via wp_get_attachment_url() - already
				 * rewritten to GCS by gcs_get_attachment_url() - and applies
				 * image_constrain_size_for_editor() to the reported dimensions.
				 */
				return false;
			}

			/*
			 * 'file' is a bare basename sitting next to $metadata['file'], the same
			 * relationship image_downsize() assumes when it swaps the basename of
			 * the full-size URL. $intermediate['path'] is deliberately not used: it
			 * is uploads-relative rather than absolute, is missing when
			 * $metadata['file'] is empty, and gains a './' prefix on flat upload
			 * structures.
			 */
			$file_name       = $intermediate['file'];
			$width           = isset( $intermediate['width'] ) ? (int) $intermediate['width'] : 0;
			$height          = isset( $intermediate['height'] ) ? (int) $intermediate['height'] : 0;
			$is_intermediate = true;
		}

		if ( empty( $file_name ) ) {
			return false;
		}

		$relative_path = $relative_dir ? $relative_dir . '/' . $file_name : $file_name;
		$url           = $this->get_gcs_url( $gcs_info, $relative_path );

		if ( empty( $url ) ) {
			return false;
		}

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
				$gcs_url       = $this->get_gcs_url( $gcs_info, $relative_path );
				if ( ! empty( $gcs_url ) ) {
					$sources[ $width ]['url'] = $gcs_url;
				}
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
	 * Determine whether an attachment must stay on local storage.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True when the attachment must not be offloaded.
	 */
	public function should_skip_attachment( $attachment_id ) {
		return just_wp_gcs_should_skip_attachment( $attachment_id );
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
	 *
	 * Returns an empty string when there is no object to point at, so callers
	 * keep the local URL. This matters beyond correctness: a URL of
	 * https://storage.googleapis.com/{bucket}/ addresses the bucket rather than
	 * an object, and GCS accounts for that as a ListObjects request. An
	 * attachment carrying GCS metadata but no file path would otherwise make
	 * every page view issue one.
	 *
	 * @param array  $gcs_info      Stored GCS metadata for the attachment.
	 * @param string $relative_path Uploads-relative path of the file.
	 * @return string Fully-qualified URL, or an empty string when none applies.
	 */
	private function get_gcs_url( $gcs_info, $relative_path ) {
		if ( ! is_array( $gcs_info ) ) {
			return '';
		}

		$relative_path = ltrim( (string) $relative_path, '/' );
		if ( '' === $relative_path ) {
			return '';
		}

		$custom_domain = get_option( 'just_wp_gcs_custom_domain' );
		$prefix        = isset( $gcs_info['prefix'] ) ? $gcs_info['prefix'] : '';
		$bucket        = isset( $gcs_info['bucket'] ) ? $gcs_info['bucket'] : '';

		$gcs_key = $this->build_gcs_key( $prefix, $relative_path );
		if ( '' === $gcs_key ) {
			return '';
		}

		// Encode each segment separately so directory separators survive while
		// characters that would otherwise terminate or reshape the path, such as
		// '#', '?' and '%', are escaped.
		$encoded_key = implode( '/', array_map( 'rawurlencode', explode( '/', $gcs_key ) ) );

		if ( ! empty( $custom_domain ) ) {
			return rtrim( $custom_domain, '/' ) . '/' . $encoded_key;
		}

		if ( empty( $bucket ) ) {
			return '';
		}

		return 'https://storage.googleapis.com/' . $bucket . '/' . $encoded_key;
	}
}
