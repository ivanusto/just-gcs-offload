<?php
/**
 * Just GCS Offload Settings Page
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

		add_action( 'wp_ajax_just_wp_gcs_get_attachment_ids', array( $this, 'ajax_get_attachment_ids' ) );
		add_action( 'wp_ajax_just_wp_gcs_process_batch', array( $this, 'ajax_process_batch' ) );
	}

	/**
	 * Add Settings submenu to WP Admin Settings menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'GCS Offload Settings', 'just-gcs-offload' ),
			__( 'GCS Offload', 'just-gcs-offload' ),
			'manage_options',
			'just-gcs-offload',
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
			__( 'Google Cloud Storage Credentials', 'just-gcs-offload' ),
			null,
			'just-gcs-offload'
		);

		add_settings_field(
			'just_wp_gcs_service_account',
			__( 'Service Account JSON Key', 'just-gcs-offload' ),
			array( $this, 'render_service_account_field' ),
			'just-gcs-offload',
			'just_wp_gcs_section_credentials'
		);

		add_settings_field(
			'just_wp_gcs_bucket',
			__( 'GCS Bucket Name', 'just-gcs-offload' ),
			array( $this, 'render_bucket_field' ),
			'just-gcs-offload',
			'just_wp_gcs_section_credentials'
		);

		// General Behavior Section
		add_settings_section(
			'just_wp_gcs_section_behavior',
			__( 'Plugin Behavior & Offload Settings', 'just-gcs-offload' ),
			null,
			'just-gcs-offload'
		);

		add_settings_field(
			'just_wp_gcs_prefix',
			__( 'Folder Path Prefix', 'just-gcs-offload' ),
			array( $this, 'render_prefix_field' ),
			'just-gcs-offload',
			'just_wp_gcs_section_behavior'
		);

		add_settings_field(
			'just_wp_gcs_custom_domain',
			__( 'Custom Domain / CDN URL', 'just-gcs-offload' ),
			array( $this, 'render_custom_domain_field' ),
			'just-gcs-offload',
			'just_wp_gcs_section_behavior'
		);

		add_settings_field(
			'just_wp_gcs_cache_control',
			__( 'Cache-Control Header', 'just-gcs-offload' ),
			array( $this, 'render_cache_control_field' ),
			'just-gcs-offload',
			'just_wp_gcs_section_behavior'
		);

		add_settings_field(
			'just_wp_gcs_set_public_acl',
			__( 'Set Public ACL', 'just-gcs-offload' ),
			array( $this, 'render_public_acl_field' ),
			'just-gcs-offload',
			'just_wp_gcs_section_behavior'
		);

		add_settings_field(
			'just_wp_gcs_delete_local',
			__( 'Delete Local Files', 'just-gcs-offload' ),
			array( $this, 'render_delete_local_field' ),
			'just-gcs-offload',
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
				__( 'The Service Account Key must be valid JSON.', 'just-gcs-offload' )
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
		<p class="description"><?php esc_html_e( 'Paste the content of your Google Cloud Service Account private key JSON file.', 'just-gcs-offload' ); ?></p>
		<?php
	}

	public function render_bucket_field() {
		$value = get_option( 'just_wp_gcs_bucket', '' );
		?>
		<input type="text" name="just_wp_gcs_bucket" id="just_wp_gcs_bucket" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php echo wp_kses( __( 'Enter the GCS bucket name (e.g. <code>my-wordpress-bucket</code>).', 'just-gcs-offload' ), array( 'code' => array() ) ); ?></p>
		<?php
	}

	public function render_prefix_field() {
		$value = get_option( 'just_wp_gcs_prefix', '' );
		?>
		<input type="text" name="just_wp_gcs_prefix" id="just_wp_gcs_prefix" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="e.g. wp-content/uploads" />
		<p class="description"><?php esc_html_e( 'Optional folder path prefix inside the bucket. Do not include leading or trailing slashes.', 'just-gcs-offload' ); ?></p>
		<?php
	}

	public function render_custom_domain_field() {
		$value = get_option( 'just_wp_gcs_custom_domain', '' );
		?>
		<input type="url" name="just_wp_gcs_custom_domain" id="just_wp_gcs_custom_domain" value="<?php echo esc_url( $value ); ?>" class="regular-text" placeholder="https://cdn.example.com" />
		<p class="description"><?php echo wp_kses( __( 'Optional custom domain or CDN URL pointing to your GCS bucket. Leave blank to use the default GCS URL: <code>https://storage.googleapis.com/{bucket}</code>', 'just-gcs-offload' ), array( 'code' => array() ) ); ?></p>
		<?php
	}

	public function render_cache_control_field() {
		$value = get_option( 'just_wp_gcs_cache_control', 'public, max-age=31536000' );
		?>
		<input type="text" name="just_wp_gcs_cache_control" id="just_wp_gcs_cache_control" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php echo wp_kses( __( 'The <code>Cache-Control</code> header applied to uploaded objects (e.g., <code>public, max-age=31536000</code>).', 'just-gcs-offload' ), array( 'code' => array() ) ); ?></p>
		<?php
	}

	public function render_public_acl_field() {
		$value = get_option( 'just_wp_gcs_set_public_acl', '0' );
		?>
		<label for="just_wp_gcs_set_public_acl">
			<input type="checkbox" name="just_wp_gcs_set_public_acl" id="just_wp_gcs_set_public_acl" value="1" <?php checked( $value, '1' ); ?> />
			<?php esc_html_e( 'Set uploaded objects ACL to public-read', 'just-gcs-offload' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Enable this if your bucket uses Fine-grained access control. Disable this if your bucket has Uniform Bucket-Level Access enabled (in which case you must configure IAM to grant public access).', 'just-gcs-offload' ); ?></p>
		<?php
	}

	public function render_delete_local_field() {
		$value = get_option( 'just_wp_gcs_delete_local', '0' );
		?>
		<label for="just_wp_gcs_delete_local">
			<input type="checkbox" name="just_wp_gcs_delete_local" id="just_wp_gcs_delete_local" value="1" <?php checked( $value, '1' ); ?> />
			<?php esc_html_e( 'Delete local files after successful GCS upload', 'just-gcs-offload' ); ?>
		</label>
		<p class="description" style="color: #d63638; font-weight: 500;"><?php esc_html_e( 'Warning: If enabled, WordPress will delete the server local copy. Built-in image editing (crop/rotate) in WP Admin requires local files and might fail.', 'just-gcs-offload' ); ?></p>
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
				do_settings_sections( 'just-gcs-offload' );
				submit_button( __( 'Save Settings', 'just-gcs-offload' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Test Connection', 'just-gcs-offload' ); ?></h2>
			<p><?php esc_html_e( 'Click the button below to test GCS bucket authentication and write permissions using the currently saved settings.', 'just-gcs-offload' ); ?></p>
			
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-bottom: 20px;">
				<input type="hidden" name="action" value="just_wp_gcs_test" />
				<?php wp_nonce_field( 'just_wp_gcs_test_action', 'just_wp_gcs_test_nonce' ); ?>
				<?php
				$bucket = get_option( 'just_wp_gcs_bucket', '' );
				$sa     = get_option( 'just_wp_gcs_service_account', '' );
				$disabled = ( empty( $bucket ) || empty( $sa ) ) ? 'disabled' : '';
				submit_button( __( 'Run Connection Test', 'just-gcs-offload' ), 'secondary', 'run_test', true, array( $disabled => $disabled ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Bulk Operations', 'just-gcs-offload' ); ?></h2>
			<p><?php esc_html_e( 'Sync your existing Media Library with Google Cloud Storage without using command-line tools.', 'just-gcs-offload' ); ?></p>

			<div class="card" style="max-width: 800px; margin-top: 15px; padding: 20px;">
				<!-- Operation 1: Sync Metadata -->
				<div class="bulk-section" style="margin-bottom: 25px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( '1. Sync Database Metadata Only', 'just-gcs-offload' ); ?></h3>
					<p class="description" style="margin-bottom: 15px;"><?php esc_html_e( 'Generate offload metadata in the database for files already copied to the GCS bucket (using gsutil or rclone). This process does not upload any files and is extremely fast.', 'just-gcs-offload' ); ?></p>
					
					<div style="margin-bottom: 15px;">
						<label for="bulk_meta_overwrite">
							<input type="checkbox" id="bulk_meta_overwrite" value="1" />
							<?php esc_html_e( 'Overwrite existing GCS metadata (re-sync)', 'just-gcs-offload' ); ?>
						</label>
					</div>
					
					<button type="button" class="button button-secondary" id="btn_run_bulk_meta" <?php echo esc_attr( $disabled ); ?>>
						<?php esc_html_e( 'Run Metadata Sync', 'just-gcs-offload' ); ?>
					</button>
				</div>

				<hr style="margin: 20px 0;" />

				<!-- Operation 2: Sync All Files -->
				<div class="bulk-section">
					<h3 style="margin-top: 0;"><?php esc_html_e( '2. Batch Upload Local Files to GCS', 'just-gcs-offload' ); ?></h3>
					<p class="description" style="margin-bottom: 15px;"><?php esc_html_e( 'Scan all existing media library attachments, upload them to GCS, and update database records. This runs in secure chunks to prevent PHP timeout errors.', 'just-gcs-offload' ); ?></p>
					
					<div style="margin-bottom: 10px;">
						<label for="bulk_all_overwrite">
							<input type="checkbox" id="bulk_all_overwrite" value="1" />
							<?php esc_html_e( 'Re-upload files even if already synced', 'just-gcs-offload' ); ?>
						</label>
					</div>
					<div style="margin-bottom: 15px;">
						<label for="bulk_all_delete_local" style="color: #d63638; font-weight: 500;">
							<input type="checkbox" id="bulk_all_delete_local" value="1" />
							<?php esc_html_e( 'Delete local files after successful upload (Caution)', 'just-gcs-offload' ); ?>
						</label>
					</div>
					
					<button type="button" class="button button-secondary" id="btn_run_bulk_all" <?php echo esc_attr( $disabled ); ?>>
						<?php esc_html_e( 'Run Batch Upload', 'just-gcs-offload' ); ?>
					</button>
				</div>

				<!-- Progress Area -->
				<div id="just_gcs_bulk_progress_container" style="display:none; margin-top: 25px; padding: 20px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px;">
					<h4 id="just_gcs_bulk_progress_title" style="margin: 0 0 15px 0; font-size: 14px;"></h4>
					
					<div style="background: #dcdcde; border-radius: 10px; height: 16px; overflow: hidden; margin-bottom: 12px; position: relative; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);">
						<div id="just_gcs_bulk_progress_bar" style="background: linear-gradient(90deg, #2271b1, #35b0ff); height: 100%; width: 0%; transition: width 0.2s ease; box-shadow: inset 0 -1px 0 rgba(0,0,0,0.15);"></div>
					</div>
					
					<div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 13px; margin-bottom: 15px;">
						<span id="just_gcs_bulk_progress_text">0%</span>
						<span id="just_gcs_bulk_progress_stats">0 / 0</span>
					</div>

					<div id="just_gcs_bulk_progress_log" style="height: 180px; overflow-y: scroll; background: #1d2327; color: #39ff14; font-family: Consolas, Monaco, monospace; font-size: 12px; padding: 12px; border-radius: 4px; border: 1px solid #3c434a; white-space: pre-wrap; line-height: 1.5;"></div>
					
					<div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
						<span id="just_gcs_bulk_status_label" style="font-style: italic; color: #646970;"></span>
						<button type="button" class="button button-link-delete" id="btn_cancel_bulk" style="color: #d63638; text-decoration: none; font-weight: 500;"><?php esc_html_e( 'Cancel Operation', 'just-gcs-offload' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		var just_gcs_bulk_data = {
			nonce: <?php echo json_encode( wp_create_nonce( 'just_wp_gcs_bulk_nonce' ) ); ?>
		};

		jQuery(document).ready(function($) {
			var totalItems = 0;
			var processedItems = 0;
			var idsQueue = [];
			var batchSize = 10;
			var currentType = '';
			var isRunning = false;
			var deleteLocal = 0;
			var overwrite = 0;

			// Keep the log bounded and render it in a single write per batch, so the
			// DOM never grows past maxLogLines and reflow cost stays constant.
			var maxLogLines = 300;
			var logLines = [];

			function appendLogLines(msgs) {
				var time = new Date().toLocaleTimeString();
				for (var i = 0; i < msgs.length; i++) {
					logLines.push('[' + time + '] ' + msgs[i]);
				}
				if (logLines.length > maxLogLines) {
					logLines = logLines.slice(logLines.length - maxLogLines);
				}
				var $log = $('#just_gcs_bulk_progress_log');
				$log.text(logLines.join('\n') + '\n');
				$log.scrollTop($log[0].scrollHeight);
			}

			function logMessage(msg) {
				appendLogLines([msg]);
			}

			function updateProgress() {
				var percentage = totalItems > 0 ? Math.round((processedItems / totalItems) * 100) : 0;
				$('#just_gcs_bulk_progress_bar').css('width', percentage + '%');
				$('#just_gcs_bulk_progress_text').text(percentage + '%');
				$('#just_gcs_bulk_progress_stats').text(processedItems + ' / ' + totalItems);
			}

			function processNextBatch() {
				if (!isRunning) {
					logMessage('<?php esc_html_e( 'Operation canceled by user.', 'just-gcs-offload' ); ?>');
					$('#just_gcs_bulk_status_label').text('<?php esc_html_e( 'Canceled.', 'just-gcs-offload' ); ?>');
					enableControls();
					return;
				}

				if (idsQueue.length === 0) {
					isRunning = false;
					$('#btn_cancel_bulk').hide();
					logMessage('<?php esc_html_e( '--- Operation completed successfully! ---', 'just-gcs-offload' ); ?>');
					$('#just_gcs_bulk_status_label').text('<?php esc_html_e( 'Completed.', 'just-gcs-offload' ); ?>');
					enableControls();
					return;
				}

				var batch = idsQueue.splice(0, batchSize);
				$('#just_gcs_bulk_status_label').text('<?php esc_html_e( 'Processing...', 'just-gcs-offload' ); ?>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'just_wp_gcs_process_batch',
						nonce: just_gcs_bulk_data.nonce,
						type: currentType,
						ids: batch,
						delete_local: deleteLocal,
						overwrite: overwrite
					},
					success: function(response) {
						if (response.success) {
							if (response.data && response.data.logs && response.data.logs.length) {
								appendLogLines(response.data.logs);
							}
							processedItems += batch.length;
							updateProgress();
							processNextBatch();
						} else {
							var errMsg = response.data && response.data.message ? response.data.message : '<?php esc_html_e( 'Unknown error occurred.', 'just-gcs-offload' ); ?>';
							logMessage('<?php esc_html_e( 'Error: ', 'just-gcs-offload' ); ?>' + errMsg);
							isRunning = false;
							$('#just_gcs_bulk_status_label').text('<?php esc_html_e( 'Failed.', 'just-gcs-offload' ); ?>');
							enableControls();
						}
					},
					error: function() {
						logMessage('<?php esc_html_e( 'Error: AJAX request failed.', 'just-gcs-offload' ); ?>');
						isRunning = false;
						$('#just_gcs_bulk_status_label').text('<?php esc_html_e( 'Failed.', 'just-gcs-offload' ); ?>');
						enableControls();
					}
				});
			}

			function disableControls() {
				$('#btn_run_bulk_meta, #btn_run_bulk_all, #bulk_meta_overwrite, #bulk_all_overwrite, #bulk_all_delete_local').prop('disabled', true);
			}

			function enableControls() {
				$('#btn_run_bulk_meta, #btn_run_bulk_all, #bulk_meta_overwrite, #bulk_all_overwrite, #bulk_all_delete_local').prop('disabled', false);
			}

			function startBulkOperation(type) {
				if (isRunning) return;

				currentType = type;
				deleteLocal = type === 'all' && $('#bulk_all_delete_local').is(':checked') ? 1 : 0;
				overwrite = type === 'metadata' 
					? ($('#bulk_meta_overwrite').is(':checked') ? 1 : 0)
					: ($('#bulk_all_overwrite').is(':checked') ? 1 : 0);

				batchSize = type === 'metadata' ? 50 : 3;

				logLines = [];
				$('#just_gcs_bulk_progress_log').empty();
				$('#just_gcs_bulk_progress_container').show();
				$('#btn_cancel_bulk').show();

				var title = type === 'metadata' 
					? '<?php esc_html_e( 'Syncing Database Metadata', 'just-gcs-offload' ); ?>' 
					: '<?php esc_html_e( 'Batch Uploading Files to GCS', 'just-gcs-offload' ); ?>';
				$('#just_gcs_bulk_progress_title').text(title);
				$('#just_gcs_bulk_status_label').text('<?php esc_html_e( 'Scanning attachments...', 'just-gcs-offload' ); ?>');

				logMessage('<?php esc_html_e( 'Initializing bulk operation...', 'just-gcs-offload' ); ?>');
				logMessage('<?php esc_html_e( 'Scanning Media Library for attachments...', 'just-gcs-offload' ); ?>');

				isRunning = true;
				totalItems = 0;
				processedItems = 0;
				updateProgress();
				disableControls();

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'just_wp_gcs_get_attachment_ids',
						nonce: just_gcs_bulk_data.nonce
					},
					success: function(response) {
						if (response.success && response.data && response.data.ids) {
							idsQueue = response.data.ids;
							totalItems = idsQueue.length;
							logMessage('<?php esc_html_e( 'Scan complete. Found ', 'just-gcs-offload' ); ?>' + totalItems + '<?php esc_html_e( ' attachments to process.', 'just-gcs-offload' ); ?>');
							updateProgress();
							processNextBatch();
						} else {
							logMessage('<?php esc_html_e( 'Error: Failed to scan attachments.', 'just-gcs-offload' ); ?>');
							isRunning = false;
							$('#just_gcs_bulk_status_label').text('<?php esc_html_e( 'Failed.', 'just-gcs-offload' ); ?>');
							enableControls();
						}
					},
					error: function() {
						logMessage('<?php esc_html_e( 'Error: Failed to fetch attachments.', 'just-gcs-offload' ); ?>');
						isRunning = false;
						$('#just_gcs_bulk_status_label').text('<?php esc_html_e( 'Failed.', 'just-gcs-offload' ); ?>');
						enableControls();
					}
				});
			}

			$('#btn_run_bulk_meta').on('click', function() {
				startBulkOperation('metadata');
			});

			$('#btn_run_bulk_all').on('click', function() {
				startBulkOperation('all');
			});

			$('#btn_cancel_bulk').on('click', function() {
				isRunning = false;
				$(this).hide();
				logMessage('<?php esc_html_e( 'Canceling operation...', 'just-gcs-offload' ); ?>');
				$('#just_gcs_bulk_status_label').text('<?php esc_html_e( 'Canceling...', 'just-gcs-offload' ); ?>');
			});
		});
		</script>
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
			wp_die( esc_html__( 'Unauthorized access.', 'just-gcs-offload' ) );
		}

		check_admin_referer( 'just_wp_gcs_test_action', 'just_wp_gcs_test_nonce' );

		// Clear transient token cache to force a fresh OAuth attempt
		delete_transient( 'just_wp_gcs_oauth_token' );

		$result = $this->client->test_connection();

		if ( is_wp_error( $result ) ) {
			$url = add_query_arg( array(
				'page'            => 'just-gcs-offload',
				'gcs_test_status' => 'failed',
				'gcs_error_msg'   => urlencode( $result->get_error_message() )
			), admin_url( 'options-general.php' ) );
		} else {
			$url = add_query_arg( array(
				'page'            => 'just-gcs-offload',
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the connection test result; no state is changed.
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'just-gcs-offload' ) {
			return;
		}

		if ( isset( $_GET['gcs_test_status'] ) ) {
			if ( $_GET['gcs_test_status'] === 'success' ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'GCS Connection Test Succeeded!', 'just-gcs-offload' ); ?></strong> <?php esc_html_e( 'The plugin successfully authenticated with Google, wrote a test file to the bucket, and deleted it.', 'just-gcs-offload' ); ?></p>
				</div>
				<?php
			} elseif ( $_GET['gcs_test_status'] === 'failed' ) {
				$msg = isset( $_GET['gcs_error_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['gcs_error_msg'] ) ) : __( 'Unknown error.', 'just-gcs-offload' );
				?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'GCS Connection Test Failed!', 'just-gcs-offload' ); ?></strong></p>
					<p><?php echo esc_html( $msg ); ?></p>
				</div>
				<?php
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * AJAX handler to scan all attachment IDs in the Media Library.
	 */
	public function ajax_get_attachment_ids() {
		check_ajax_referer( 'just_wp_gcs_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'just-gcs-offload' ) ) );
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$attachment_ids = get_posts( $query_args );

		wp_send_json_success( array( 'ids' => $attachment_ids ) );
	}

	/**
	 * AJAX handler to process a batch of attachments (sync metadata or upload files).
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'just_wp_gcs_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'just-gcs-offload' ) ) );
		}

		$type         = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$ids          = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array();
		$overwrite    = isset( $_POST['overwrite'] ) && $_POST['overwrite'] === '1';
		$delete_local = isset( $_POST['delete_local'] ) && $_POST['delete_local'] === '1';

		$bucket = get_option( 'just_wp_gcs_bucket' );
		$prefix = get_option( 'just_wp_gcs_prefix', '' );

		if ( empty( $bucket ) ) {
			wp_send_json_error( array( 'message' => __( 'GCS bucket name is not configured.', 'just-gcs-offload' ) ) );
		}

		if ( empty( $ids ) ) {
			wp_send_json_success( array( 'logs' => array() ) );
		}

		$logs = array();
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		foreach ( $ids as $attachment_id ) {
			$gcs_info = get_post_meta( $attachment_id, '_wp_gcs_info', true );
			if ( ! empty( $gcs_info ) && ! $overwrite ) {
				/* translators: %d: Attachment ID. */
				$logs[] = sprintf( __( 'ID %d: Already synced, skipped.', 'just-gcs-offload' ), $attachment_id );
				continue;
			}

			$main_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( empty( $main_file ) ) {
				/* translators: %d: Attachment ID. */
				$logs[] = sprintf( __( 'ID %d: No associated file found, skipped.', 'just-gcs-offload' ), $attachment_id );
				continue;
			}

			if ( $type === 'metadata' ) {
				$gcs_info = array(
					'bucket' => $bucket,
					'prefix' => $prefix,
					'file'   => $main_file
				);
				update_post_meta( $attachment_id, '_wp_gcs_info', $gcs_info );
				/* translators: 1: Attachment ID, 2: File name. */
				$logs[] = sprintf( __( 'ID %1$d: Metadata synced successfully (%2$s).', 'just-gcs-offload' ), $attachment_id, basename( $main_file ) );
			} elseif ( $type === 'all' ) {
				$local_main_file = $basedir . '/' . $main_file;
				if ( ! file_exists( $local_main_file ) ) {
					/* translators: 1: Attachment ID, 2: File name. */
					$logs[] = sprintf( __( 'ID %1$d: Local file not found: %2$s', 'just-gcs-offload' ), $attachment_id, basename( $main_file ) );
					continue;
				}

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

				// Add original image (pre-conversion source, e.g. the JPEG of a WebP)
				if ( ! empty( $metadata['original_image'] ) ) {
					$relative_original_path = $relative_dir ? $relative_dir . '/' . $metadata['original_image'] : $metadata['original_image'];
					$local_original_file    = $basedir . '/' . $relative_original_path;
					if ( $relative_original_path !== $main_file && file_exists( $local_original_file ) ) {
						$files_to_upload[] = array(
							'local_path' => $local_original_file,
							'gcs_key'    => $this->build_gcs_key( $prefix, $relative_original_path )
						);
					}
				}

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

				$uploaded_successfully = array();
				$failed_uploads        = array();

				foreach ( $files_to_upload as $file_info ) {
					$upload = $this->client->upload_file( $file_info['local_path'], $file_info['gcs_key'] );
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
					/* translators: 1: Attachment ID, 2: File name. */
					$logs[] = sprintf( __( 'ID %1$d: Uploaded successfully (%2$s).', 'just-gcs-offload' ), $attachment_id, basename( $main_file ) );

					if ( $delete_local ) {
						foreach ( $uploaded_successfully as $file_info ) {
							wp_delete_file( $file_info['local_path'] );
						}
					}
				} else {
					/* translators: 1: Attachment ID, 2: File name, 3: Error messages. */
					$logs[] = sprintf( __( 'ID %1$d: Failed to upload (%2$s). Errors: %3$s', 'just-gcs-offload' ), $attachment_id, basename( $main_file ), implode( ', ', $failed_uploads ) );
				}
			}
		}

		wp_send_json_success( array( 'logs' => $logs ) );
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
}
