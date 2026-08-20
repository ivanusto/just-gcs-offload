<?php
/**
 * Plugin Name: Just GCS Offload
 * Plugin URI:  https://yblog.org
 * Description: A lightweight, dependency-free plugin to offload WordPress Media Library to Google Cloud Storage (GCS) using Service Account JWT authentication.
 * Version:     1.5.1
 * Author:      Ivan Lin
 * Author URI:  https://yblog.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: just-gcs-offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define Constants
define( 'JUST_WP_GCS_VERSION', '1.5.1' );
define( 'JUST_WP_GCS_PATH', plugin_dir_path( __FILE__ ) );
define( 'JUST_WP_GCS_URL', plugin_dir_url( __FILE__ ) );

// Load classes
require_once JUST_WP_GCS_PATH . 'includes/class-gcs-client.php';
require_once JUST_WP_GCS_PATH . 'includes/class-gcs-settings.php';
require_once JUST_WP_GCS_PATH . 'includes/class-gcs-media-handler.php';

/**
 * Determine whether an attachment must stay on local storage.
 *
 * The site icon is excluded by default: WordPress prints its URLs into the
 * document head on every page load, so it should not depend on the bucket or
 * CDN being reachable.
 *
 * This is a write-side policy -- it decides whether an attachment is offloaded
 * at all. It deliberately does not affect URL rewriting for attachments that
 * were already offloaded, because their local sub-size files may have been
 * removed by the "delete local files" option.
 *
 * @since 1.4.0
 *
 * @param int $attachment_id Attachment post ID.
 * @return bool True when the attachment must not be offloaded.
 */
function just_wp_gcs_should_skip_attachment( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	$site_icon     = (int) get_option( 'site_icon' );

	$skip = ( $site_icon && $attachment_id === $site_icon );

	/*
	 * The cropped site icon attachment is created by wp_ajax_crop_image()
	 * before the `site_icon` option is updated, so the option check alone
	 * misses a freshly uploaded icon. wp_insert_attachment() has already
	 * stored the context by the time wp_update_attachment_metadata() runs.
	 */
	if ( ! $skip && 'site-icon' === get_post_meta( $attachment_id, '_wp_attachment_context', true ) ) {
		$skip = true;
	}

	/**
	 * Filters whether an attachment is excluded from GCS offload.
	 *
	 * @since 1.4.0
	 *
	 * @param bool $skip          Whether to skip the attachment.
	 * @param int  $attachment_id Attachment post ID.
	 */
	return (bool) apply_filters( 'just_wp_gcs_skip_attachment', $skip, $attachment_id );
}

/**
 * Attachment metadata keys holding a companion file of the main attachment.
 *
 * Each key holds a bare basename that sits in the same directory as
 * `$metadata['file']`:
 *
 * - `original_image` - the pre-conversion or pre-scaled source, e.g. the JPEG
 *   behind a WebP derivative or the original of a scaled-down upload.
 * - `source_image` - the source-format original kept by client-side media
 *   processing, e.g. the HEIC behind its JPEG derivative. WordPress 7.1+.
 * - `animated_video` and `animated_video_poster` - the MP4/WebM an animated GIF
 *   is converted to in the browser, and its static first-frame poster.
 *   WordPress 7.1+.
 *
 * @since 1.5.0
 *
 * @return string[] Metadata keys.
 */
function just_wp_gcs_companion_meta_keys() {
	/**
	 * Filters the attachment metadata keys treated as companion files.
	 *
	 * @since 1.5.0
	 *
	 * @param string[] $keys Metadata keys.
	 */
	return (array) apply_filters(
		'just_wp_gcs_companion_meta_keys',
		array( 'original_image', 'source_image', 'animated_video', 'animated_video_poster' )
	);
}

/**
 * File names recorded in a Modern Image Formats `sources` array.
 *
 * The WordPress Performance team's webp-uploads module stores one file per
 * output MIME type under `sources`, both on the attachment metadata and on each
 * sub-size. Where it applies, the converted WebP (or AVIF) exists only there:
 * reading `file` alone finds the original and misses every derivative, so they
 * would never reach the bucket and the `<picture>` sources on the front end
 * would point at objects that do not exist.
 *
 * @since 1.5.1
 *
 * @param mixed $sources A `sources` value from attachment or sub-size metadata.
 * @return string[] Bare file names, in the order they appear.
 */
function just_wp_gcs_source_files( $sources ) {
	if ( ! is_array( $sources ) ) {
		return array();
	}

	$files = array();

	foreach ( $sources as $source ) {
		if ( is_array( $source ) && ! empty( $source['file'] ) && is_string( $source['file'] ) ) {
			$files[] = $source['file'];
		}
	}

	return $files;
}

/**
 * Collect every uploads-relative file path belonging to an attachment.
 *
 * Returns the main file, its companion files, every Modern Image Formats
 * derivative, and every sub-size with its own derivatives. Paths are deduplicated: since WordPress 7.1 one physical file can be
 * registered under several size names when those sizes share dimensions, and
 * uploading or deleting it once per name would multiply the GCS API calls for
 * no benefit.
 *
 * @since 1.5.0
 *
 * @param array  $metadata  Attachment metadata.
 * @param string $main_file Uploads-relative path of the main file. Falls back
 *                          to `$metadata['file']`.
 * @return string[] Uploads-relative paths, without duplicates. Empty when there
 *                  is no main file to anchor the other paths to.
 */
function just_wp_gcs_collect_attachment_files( $metadata, $main_file = '' ) {
	$metadata = is_array( $metadata ) ? $metadata : array();

	if ( '' === $main_file && ! empty( $metadata['file'] ) ) {
		$main_file = $metadata['file'];
	}

	if ( empty( $main_file ) || ! is_string( $main_file ) ) {
		return array();
	}

	$relative_dir = dirname( $main_file );
	if ( '.' === $relative_dir ) {
		$relative_dir = '';
	}

	$paths = array( $main_file );

	foreach ( just_wp_gcs_companion_meta_keys() as $key ) {
		if ( empty( $metadata[ $key ] ) || ! is_string( $metadata[ $key ] ) ) {
			continue;
		}
		$paths[] = $relative_dir ? $relative_dir . '/' . $metadata[ $key ] : $metadata[ $key ];
	}

	foreach ( just_wp_gcs_source_files( isset( $metadata['sources'] ) ? $metadata['sources'] : null ) as $source_file ) {
		$paths[] = $relative_dir ? $relative_dir . '/' . $source_file : $source_file;
	}

	if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		foreach ( $metadata['sizes'] as $size_info ) {
			if ( ! is_array( $size_info ) ) {
				continue;
			}

			if ( ! empty( $size_info['file'] ) && is_string( $size_info['file'] ) ) {
				$paths[] = $relative_dir ? $relative_dir . '/' . $size_info['file'] : $size_info['file'];
			}

			foreach ( just_wp_gcs_source_files( isset( $size_info['sources'] ) ? $size_info['sources'] : null ) as $source_file ) {
				$paths[] = $relative_dir ? $relative_dir . '/' . $source_file : $source_file;
			}
		}
	}

	// array_unique keeps the first occurrence, so the main file wins over a
	// companion key or sub-size that points at the same physical file.
	return array_values( array_unique( $paths ) );
}

// Initialize Plugin
function just_wp_gcs_init() {
	// Initialize GCS Client with settings
	$client = new Just_WP_GCS_Client();

	// Initialize Settings Page
	new Just_WP_GCS_Settings( $client );

	// Initialize Media Handler
	new Just_WP_GCS_Media_Handler( $client );
}
add_action( 'plugins_loaded', 'just_wp_gcs_init' );

// Register WP-CLI command if active
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once JUST_WP_GCS_PATH . 'includes/class-gcs-cli.php';
	WP_CLI::add_command( 'gcs-offload', 'Just_WP_GCS_CLI' );
}
