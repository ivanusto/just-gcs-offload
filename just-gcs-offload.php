<?php
/**
 * Plugin Name: Just GCS Offload
 * Plugin URI:  https://yblog.org
 * Description: A lightweight, dependency-free plugin to offload WordPress Media Library to Google Cloud Storage (GCS) using Service Account JWT authentication.
 * Version:     1.3.0
 * Author:      Ivan Lin
 * Author URI:  https://yblog.org
 * License:     MIT
 * Text Domain: just-gcs-offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define Constants
define( 'JUST_WP_GCS_VERSION', '1.3.0' );
define( 'JUST_WP_GCS_PATH', plugin_dir_path( __FILE__ ) );
define( 'JUST_WP_GCS_URL', plugin_dir_url( __FILE__ ) );

// Load classes
require_once JUST_WP_GCS_PATH . 'includes/class-gcs-client.php';
require_once JUST_WP_GCS_PATH . 'includes/class-gcs-settings.php';
require_once JUST_WP_GCS_PATH . 'includes/class-gcs-media-handler.php';

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
