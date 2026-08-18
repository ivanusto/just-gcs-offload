<?php
/**
 * Plugin Name: Just GCS Offload
 * Plugin URI:  https://yblog.org
 * Description: A lightweight, dependency-free plugin to offload WordPress Media Library to Google Cloud Storage (GCS) using Service Account JWT authentication.
 * Version:     1.4.0
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
define( 'JUST_WP_GCS_VERSION', '1.4.0' );
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
