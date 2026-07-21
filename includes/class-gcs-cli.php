<?php
/**
 * Just GCS Offload WP-CLI Command Integration
 * Batch syncs existing media library files and metadata to GCS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Just_WP_GCS_CLI {

	/**
	 * Sync database metadata to GCS for files that are already uploaded to GCS.
	 *
	 * ## OPTIONS
	 *
	 * [--bucket=<bucket>]
	 * : GCS bucket name to associate with the media. Defaults to settings value.
	 *
	 * [--prefix=<prefix>]
	 * : Folder path prefix. Defaults to settings value.
	 *
	 * [--overwrite]
	 * : Overwrite existing GCS meta if already synced.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gcs-offload sync-metadata --bucket=my-wp-bucket
	 *
	 * @subcommand sync-metadata
	 */
	public function sync_metadata( $args, $assoc_args ) {
		$bucket    = isset( $assoc_args['bucket'] ) ? sanitize_text_field( $assoc_args['bucket'] ) : get_option( 'just_wp_gcs_bucket' );
		$prefix    = isset( $assoc_args['prefix'] ) ? sanitize_text_field( $assoc_args['prefix'] ) : get_option( 'just_wp_gcs_prefix', '' );
		$overwrite = isset( $assoc_args['overwrite'] );

		if ( empty( $bucket ) ) {
			WP_CLI::error( 'GCS bucket name is not configured. Please define it in settings or pass --bucket=<bucket>.' );
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$attachment_ids = get_posts( $query_args );
		$total          = count( $attachment_ids );

		if ( $total === 0 ) {
			WP_CLI::success( 'No attachments found.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d attachments. Syncing database metadata...', $total ) );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Syncing Metadata', $total );

		$synced_count  = 0;
		$skipped_count = 0;

		foreach ( array_chunk( $attachment_ids, 500 ) as $chunk ) {
			// Prime the meta cache for the whole chunk with a single query.
			update_meta_cache( 'post', $chunk );

			foreach ( $chunk as $attachment_id ) {
				$progress->tick();

				$gcs_info = get_post_meta( $attachment_id, '_wp_gcs_info', true );
				if ( ! empty( $gcs_info ) && ! $overwrite ) {
					$skipped_count++;
					continue;
				}

				$main_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
				if ( empty( $main_file ) ) {
					continue;
				}

				$gcs_info = array(
					'bucket' => $bucket,
					'prefix' => $prefix,
					'file'   => $main_file
				);

				update_post_meta( $attachment_id, '_wp_gcs_info', $gcs_info );
				$synced_count++;
			}

			// The in-process object cache grows unbounded in long CLI loops; release it
			// per chunk so memory stays flat on large media libraries.
			$this->flush_runtime_cache();
		}

		$progress->finish();
		WP_CLI::success( sprintf( 'Metadata sync completed. Synced: %d, Skipped: %d', $synced_count, $skipped_count ) );
	}

	/**
	 * Upload existing media library files to GCS and mark them as synced in the database.
	 *
	 * ## OPTIONS
	 *
	 * [--delete-local]
	 * : Delete local files after successful GCS upload.
	 *
	 * [--overwrite]
	 * : Re-upload files even if already synced.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gcs-offload sync-all --delete-local
	 *
	 * @subcommand sync-all
	 */
	public function sync_all( $args, $assoc_args ) {
		$delete_local = isset( $assoc_args['delete-local'] );
		$overwrite    = isset( $assoc_args['overwrite'] );

		$bucket = get_option( 'just_wp_gcs_bucket' );
		$prefix = get_option( 'just_wp_gcs_prefix', '' );

		if ( empty( $bucket ) ) {
			WP_CLI::error( 'GCS bucket name is not configured in settings.' );
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$attachment_ids = get_posts( $query_args );
		$total          = count( $attachment_ids );

		if ( $total === 0 ) {
			WP_CLI::success( 'No attachments found.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d attachments. Uploading files to GCS...', $total ) );

		$client     = new Just_WP_GCS_Client();
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		$success_count = 0;
		$failed_count  = 0;
		$skipped_count = 0;

		foreach ( array_chunk( $attachment_ids, 100 ) as $chunk ) {
			// Prime the meta cache for the whole chunk with a single query.
			update_meta_cache( 'post', $chunk );

			foreach ( $chunk as $attachment_id ) {
				$gcs_info = get_post_meta( $attachment_id, '_wp_gcs_info', true );
				if ( ! empty( $gcs_info ) && ! $overwrite ) {
					$skipped_count++;
					continue;
				}

				$main_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
				if ( empty( $main_file ) ) {
					continue;
				}

				$local_main_file = $basedir . '/' . $main_file;
				if ( ! file_exists( $local_main_file ) ) {
					WP_CLI::warning( sprintf( 'Local file not found for ID %d: %s', $attachment_id, $local_main_file ) );
					$failed_count++;
					continue;
				}

				WP_CLI::log( sprintf( 'Uploading Attachment ID %d (%s)...', $attachment_id, basename( $main_file ) ) );

				$metadata     = wp_get_attachment_metadata( $attachment_id );
				$relative_dir = dirname( $main_file );
				if ( $relative_dir === '.' ) {
					$relative_dir = '';
				}

				$files_to_upload = array();

				// Add main file
				$gcs_main_key = $this->build_gcs_key( $prefix, $main_file );
				$files_to_upload[] = array(
					'local_path' => $local_main_file,
					'gcs_key'    => $gcs_main_key
				);

				// Add size files
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
								'gcs_key'    => $gcs_size_key
							);
						}
					}
				}

				// Perform uploads
				$uploaded_successfully = array();
				$failed_uploads        = array();

				foreach ( $files_to_upload as $file_info ) {
					$upload = $client->upload_file( $file_info['local_path'], $file_info['gcs_key'] );
					if ( is_wp_error( $upload ) ) {
						$failed_uploads[] = $upload->get_error_message();
					} else {
						$uploaded_successfully[] = $file_info;
					}
				}

				if ( count( $uploaded_successfully ) > 0 && count( $failed_uploads ) === 0 ) {
					$gcs_info_new = array(
						'bucket' => $bucket,
						'prefix' => $prefix,
						'file'   => $main_file
					);
					update_post_meta( $attachment_id, '_wp_gcs_info', $gcs_info_new );
					$success_count++;

					if ( $delete_local ) {
						foreach ( $uploaded_successfully as $file_info ) {
							wp_delete_file( $file_info['local_path'] );
						}
					}
				} else {
					WP_CLI::warning( sprintf( 'Failed to upload ID %d. Errors: %s', $attachment_id, implode( ', ', $failed_uploads ) ) );
					$failed_count++;
				}
			}

			// The in-process object cache grows unbounded in long CLI loops; release it
			// per chunk so memory stays flat on large media libraries.
			$this->flush_runtime_cache();
		}

		WP_CLI::success( sprintf( 'Sync file uploads completed. Success: %d, Failed: %d, Skipped: %d', $success_count, $failed_count, $skipped_count ) );
	}

	/**
	 * Clear the in-process object cache without touching persistent caches
	 * (Redis/Memcached) where possible. wp_cache_flush_runtime() requires WP 6.1+.
	 */
	private function flush_runtime_cache() {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} else {
			wp_cache_flush();
		}
	}

	private function build_gcs_key( $prefix, $relative_path ) {
		$key = $relative_path;
		if ( ! empty( $prefix ) ) {
			$key = $prefix . '/' . $key;
		}
		return ltrim( $key, '/' );
	}
}
