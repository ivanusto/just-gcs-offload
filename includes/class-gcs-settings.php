<?php
/**
 * Just WP GCS Settings Page
 * Sets up admin menus, registers options, and handles connection testing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Just_WP_GCS_Settings {

	/**
	 * @var Just_WP_GCS_Client
	 */
	private $client;

	/**
	 * Constructor
	 */
	public function __construct( $client ) {
		$this->client = $client;

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_notices', array( $this, 'display_notices' ) );
	}

	/**
	 * Add Settings submenu to WP Admin Settings menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'GCS Offload Settings', 'just-wp-gcs' ),
			__( 'GCS Offload', 'just-wp-gcs' ),
			'manage_options',
			'just-wp-gcs',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_settings() {
		register_setting( 'just_wp_gcs_options', 'just_wp_gcs_service_account', array(
			'sanitize_callback' => array( $this, 'sanitize_service_account' ),
		) );
		register_setting( 'just_wp_gcs_options', 'just_wp_gcs_bucket', 'sanitize_text_field' );
		register_setting( 'just_wp_gcs_options', 'just_wp_gcs_prefix', array(
			'sanitize_callback' => array( $this, 'sanitize_prefix' ),
		) );
		register_setting( 'just_wp_gcs_options', 'just_wp_gcs_custom_domain', 'esc_url_raw' );
		register_setting( 'just_wp_gcs_options', 'just_wp_gcs_set_public_acl', 'sanitize_text_field' );
		register_setting( 'just_wp_gcs_options', 'just_wp_gcs_delete_local', 'sanitize_text_field' );
		register_setting( 'just_wp_gcs_options', 'just_wp_gcs_cache_control', 'sanitize_text_field' );

		// Credentials Section
		add_settings_section(
			'just_wp_gcs_section_credentials',
			__( 'Google Cloud Storage Credentials', 'just-wp-gcs' ),
			null,
			'just-wp-gcs'
		);

		add_settings_field(
			'just_wp_gcs_service_account',
			__( 'Service Account JSON Key', 'just-wp-gcs' ),
			array( $this, 'render_service_account_field' ),
			'just-wp-gcs',
			'just_wp_gcs_section_credentials'
		);

		add_settings_field(
			'just_wp_gcs_bucket',
			__( 'GCS Bucket Name', 'just-wp-gcs' ),
			array( $this, 'render_bucket_field' ),
			'just-wp-gcs',
			'just_wp_gcs_section_credentials'
		);

		// General Behavior Section
		add_settings_section(
			'just_wp_gcs_section_behavior',
			__( 'Plugin Behavior & Offload Settings', 'just-wp-gcs' ),
			null,
			'just-wp-gcs'
		);

		add_settings_field(
			'just_wp_gcs_prefix',
			__( 'Folder Path Prefix', 'just-wp-gcs' ),
			array( $this, 'render_prefix_field' ),
			'just-wp-gcs',
			'just_wp_gcs_section_behavior'
		);

		add_settings_field(
			'just_wp_gcs_custom_domain',
			__( 'Custom Domain / CDN URL', 'just-wp-gcs' ),
			array( $this, 'render_custom_domain_field' ),
			'just-wp-gcs',
			'just_wp_gcs_section_behavior'
		);

		add_settings_field(
			'just_wp_gcs_cache_control',
			__( 'Cache-Control Header', 'just-wp-gcs' ),
			array( $this, 'render_cache_control_field' ),
			'just-wp-gcs',
			'just_wp_gcs_section_behavior'
		);

		add_settings_field(
			'just_wp_gcs_set_public_acl',
			__( 'Set Public ACL', 'just-wp-gcs' ),
			array( $this, 'render_public_acl_field' ),
			'just-wp-gcs',
			'just_wp_gcs_section_behavior'
		);

		add_settings_field(
			'just_wp_gcs_delete_local',
			__( 'Delete Local Files', 'just-wp-gcs' ),
			array( $this, 'render_delete_local_field' ),
			'just-wp-gcs',
			'just_wp_gcs_section_behavior'
		);
	}

	/**
	 * Sanitization callbacks.
	 */
	public function sanitize_service_account( $value ) {
		$value = trim( $value );
		if ( empty( $value ) ) {
			return '';
		}

		// Verify it is a valid JSON
		$json = json_decode( $value, true );
		if ( ! is_array( $json ) ) {
			add_settings_error(
				'just_wp_gcs_service_account',
				'invalid_json',
				__( 'The Service Account Key must be valid JSON.', 'just-wp-gcs' )
			);
			return get_option( 'just_wp_gcs_service_account', '' );
		}

		return $value;
	}

	public function sanitize_prefix( $value ) {
		$value = trim( $value, '/ ' );
		return $value;
	}

	/**
	 * Form field renders.
	 */
	public function render_service_account_field() {
		$value = get_option( 'just_wp_gcs_service_account', '' );
		?>
		<textarea name="just_wp_gcs_service_account" id="just_wp_gcs_service_account" rows="10" class="large-text code" placeholder='{
  "type": "service_account",
  "project_id": "your-project-id",
  ...
}'><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php _e( 'Paste the content of your Google Cloud Service Account private key JSON file.', 'just-wp-gcs' ); ?></p>
		<?php
	}

	public function render_bucket_field() {
		$value = get_option( 'just_wp_gcs_bucket', '' );
		?>
		<input type="text" name="just_wp_gcs_bucket" id="just_wp_gcs_bucket" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php _e( 'Enter the GCS bucket name (e.g. <code>my-wordpress-bucket</code>).', 'just-wp-gcs' ); ?></p>
		<?php
	}

	public function render_prefix_field() {
		$value = get_option( 'just_wp_gcs_prefix', '' );
		?>
		<input type="text" name="just_wp_gcs_prefix" id="just_wp_gcs_prefix" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="e.g. wp-content/uploads" />
		<p class="description"><?php _e( 'Optional folder path prefix inside the bucket. Do not include leading or trailing slashes.', 'just-wp-gcs' ); ?></p>
		<?php
	}

	public function render_custom_domain_field() {
		$value = get_option( 'just_wp_gcs_custom_domain', '' );
		?>
		<input type="url" name="just_wp_gcs_custom_domain" id="just_wp_gcs_custom_domain" value="<?php echo esc_url( $value ); ?>" class="regular-text" placeholder="https://cdn.example.com" />
		<p class="description"><?php _e( 'Optional custom domain or CDN URL pointing to your GCS bucket. Leave blank to use the default GCS URL: <code>https://storage.googleapis.com/{bucket}</code>', 'just-wp-gcs' ); ?></p>
		<?php
	}

	public function render_cache_control_field() {
		$value = get_option( 'just_wp_gcs_cache_control', 'public, max-age=31536000' );
		?>
		<input type="text" name="just_wp_gcs_cache_control" id="just_wp_gcs_cache_control" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php _e( 'The <code>Cache-Control</code> header applied to uploaded objects (e.g., <code>public, max-age=31536000</code>).', 'just-wp-gcs' ); ?></p>
		<?php
	}

	public function render_public_acl_field() {
		$value = get_option( 'just_wp_gcs_set_public_acl', '0' );
		?>
		<label for="just_wp_gcs_set_public_acl">
			<input type="checkbox" name="just_wp_gcs_set_public_acl" id="just_wp_gcs_set_public_acl" value="1" <?php checked( $value, '1' ); ?> />
			<?php _e( 'Set uploaded objects ACL to public-read', 'just-wp-gcs' ); ?>
		</label>
		<p class="description"><?php _e( 'Enable this if your bucket uses Fine-grained access control. Disable this if your bucket has Uniform Bucket-Level Access enabled (in which case you must configure IAM to grant public access).', 'just-wp-gcs' ); ?></p>
		<?php
	}

	public function render_delete_local_field() {
		$value = get_option( 'just_wp_gcs_delete_local', '0' );
		?>
		<label for="just_wp_gcs_delete_local">
			<input type="checkbox" name="just_wp_gcs_delete_local" id="just_wp_gcs_delete_local" value="1" <?php checked( $value, '1' ); ?> />
			<?php _e( 'Delete local files after successful GCS upload', 'just-wp-gcs' ); ?>
		</label>
		<p class="description" style="color: #d63638; font-weight: 500;"><?php _e( 'Warning: If enabled, WordPress will delete the server local copy. Built-in image editing (crop/rotate) in WP Admin requires local files and might fail.', 'just-wp-gcs' ); ?></p>
		<?php
	}

	/**
	 * Display Settings page content.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<form action="options.php" method="post">
				<?php
				settings_fields( 'just_wp_gcs_options' );
				do_settings_sections( 'just-wp-gcs' );
				submit_button( __( 'Save Settings', 'just-wp-gcs' ) );
				?>
			</form>

			<hr />

			<h2><?php _e( 'Test Connection', 'just-wp-gcs' ); ?></h2>
			<p><?php _e( 'Click the button below to test GCS bucket authentication and write permissions using the currently saved settings.', 'just-wp-gcs' ); ?></p>
			
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="just_wp_gcs_test" />
				<?php wp_nonce_field( 'just_wp_gcs_test_action', 'just_wp_gcs_test_nonce' ); ?>
				<?php
				$bucket = get_option( 'just_wp_gcs_bucket', '' );
				$sa     = get_option( 'just_wp_gcs_service_account', '' );
				$disabled = ( empty( $bucket ) || empty( $sa ) ) ? 'disabled' : '';
				submit_button( __( 'Run Connection Test', 'just-wp-gcs' ), 'secondary', 'run_test', true, array( $disabled => $disabled ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle Connection Test Action (POST request to admin-post.php).
	 */
	public function handle_test_connection() {
		if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'just_wp_gcs_test' ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized access.', 'just-wp-gcs' ) );
		}

		check_admin_referer( 'just_wp_gcs_test_action', 'just_wp_gcs_test_nonce' );

		// Clear transient token cache to force a fresh OAuth attempt
		delete_transient( 'just_wp_gcs_oauth_token' );

		$result = $this->client->test_connection();

		if ( is_wp_error( $result ) ) {
			$url = add_query_arg( array(
				'page'            => 'just-wp-gcs',
				'gcs_test_status' => 'failed',
				'gcs_error_msg'   => urlencode( $result->get_error_message() )
			), admin_url( 'options-general.php' ) );
		} else {
			$url = add_query_arg( array(
				'page'            => 'just-wp-gcs',
				'gcs_test_status' => 'success'
			), admin_url( 'options-general.php' ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Display admin notices on success or failure of connection test.
	 */
	public function display_notices() {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'just-wp-gcs' ) {
			return;
		}

		if ( isset( $_GET['gcs_test_status'] ) ) {
			if ( $_GET['gcs_test_status'] === 'success' ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php _e( 'GCS Connection Test Succeeded!', 'just-wp-gcs' ); ?></strong> <?php _e( 'The plugin successfully authenticated with Google, wrote a test file to the bucket, and deleted it.', 'just-wp-gcs' ); ?></p>
				</div>
				<?php
			} elseif ( $_GET['gcs_test_status'] === 'failed' ) {
				$msg = isset( $_GET['gcs_error_msg'] ) ? sanitize_text_field( urldecode( $_GET['gcs_error_msg'] ) ) : __( 'Unknown error.', 'just-wp-gcs' );
				?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php _e( 'GCS Connection Test Failed!', 'just-wp-gcs' ); ?></strong></p>
					<p><?php echo esc_html( $msg ); ?></p>
				</div>
				<?php
			}
		}
	}
}
