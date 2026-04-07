<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/google-drive-client.php';
require_once __DIR__ . '/backup-manager.php';
require_once __DIR__ . '/cron.php';
require_once __DIR__ . '/oauth.php';

add_action( 'wp_ajax_backup_gdrive_sync_start', 'backup_gdrive_ajax_sync_start' );
add_action( 'wp_ajax_backup_gdrive_sync_process', 'backup_gdrive_ajax_sync_process' );
add_action( 'wp_ajax_backup_gdrive_sync_status', 'backup_gdrive_ajax_sync_status' );
add_action( 'wp_ajax_backup_gdrive_sync_cancel', 'backup_gdrive_ajax_sync_cancel' );
add_action( 'wp_ajax_backup_gdrive_get_queue_count', 'backup_gdrive_ajax_get_queue_count' );
add_action( 'wp_ajax_backup_gdrive_get_error_log', 'backup_gdrive_ajax_get_error_log' );
add_action( 'wp_ajax_backup_gdrive_clear_error_log', 'backup_gdrive_ajax_clear_error_log' );
add_action( 'wp_ajax_backup_gdrive_get_sync_log', 'backup_gdrive_ajax_get_sync_log' );
add_action( 'wp_ajax_backup_gdrive_clear_sync_log', 'backup_gdrive_ajax_clear_sync_log' );
add_action( 'add_attachment', 'backup_gdrive_on_upload' );
add_action( 'delete_attachment', 'backup_gdrive_on_delete' );

function backup_gdrive_ajax_get_queue_count() {
	check_ajax_referer( 'backup_gdrive_sync_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$manager = new BackupToGoogleDriveManager();
	$count = $manager->get_queue_count();

	wp_send_json_success( array( 'count' => $count ) );
}

function backup_gdrive_ajax_get_error_log() {
	check_ajax_referer( 'backup_gdrive_sync_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$manager = new BackupToGoogleDriveManager();
	$log = $manager->get_error_log();

	wp_send_json_success( array( 'log' => $log ) );
}

function backup_gdrive_ajax_clear_error_log() {
	check_ajax_referer( 'backup_gdrive_sync_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$manager = new BackupToGoogleDriveManager();
	$manager->clear_error_log();

	wp_send_json_success();
}

function backup_gdrive_ajax_get_sync_log() {
	check_ajax_referer( 'backup_gdrive_sync_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$manager = new BackupToGoogleDriveManager();
	$log = $manager->get_sync_log();

	wp_send_json_success( array( 'log' => $log ) );
}

function backup_gdrive_ajax_clear_sync_log() {
	check_ajax_referer( 'backup_gdrive_sync_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$manager = new BackupToGoogleDriveManager();
	$manager->clear_sync_log();

	wp_send_json_success();
}

function backup_gdrive_on_upload( $attachment_id ) {
	$manager = new BackupToGoogleDriveManager();
	$file_path = get_attached_file( $attachment_id );

	if ( $manager->is_original_image( $file_path ) ) {
		$manager->add_to_queue( $file_path );
	}
}

function backup_gdrive_on_delete( $attachment_id ) {
	$manager = new BackupToGoogleDriveManager();
	$file_path = get_attached_file( $attachment_id );
	$manager->remove_from_queue( $file_path );
}

function backup_gdrive_ajax_sync_start() {
	check_ajax_referer( 'backup_gdrive_sync_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : 'queue';
	$manager = new BackupToGoogleDriveManager();
	$result = $manager->init_sync( $mode );

	if ( is_wp_error( $result ) ) {
		error_log( 'GDrive sync_start error: ' . $result->get_error_message() );
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}

function backup_gdrive_ajax_sync_process() {
	check_ajax_referer( 'backup_gdrive_sync_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$manager = new BackupToGoogleDriveManager();
	$result = $manager->process_chunk( 5 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}

function backup_gdrive_ajax_sync_status() {
	check_ajax_referer( 'backup_gdrive_sync_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$manager = new BackupToGoogleDriveManager();
	$result = $manager->get_status();

	wp_send_json_success( $result );
}

function backup_gdrive_ajax_sync_cancel() {
	check_ajax_referer( 'backup_gdrive_sync_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ) );
	}

	$manager = new BackupToGoogleDriveManager();
	$result = $manager->cancel_sync();

	wp_send_json_success( $result );
}

class BackupToGoogleDriveSettings {
	private $option_group = 'backup_to_gdrive_group';
	private $option_name  = 'backup_to_gdrive_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_backup_to_gdrive_options', array( $this, 'handle_option_update' ), 10, 2 );
	}

	public function handle_option_update( $old_value, $new_value ) {
		if (
			isset( $new_value['schedule'] ) && $new_value['schedule'] !== $old_value['schedule'] ||
			isset( $new_value['schedule_hour'] ) && $new_value['schedule_hour'] !== $old_value['schedule_hour'] ||
			isset( $new_value['schedule_minute'] ) && $new_value['schedule_minute'] !== $old_value['schedule_minute']
		) {
			$cron = new BackupToGoogleDriveCron();
			$cron->unschedule_backup();
			$cron->schedule_backup();
		}
	}

	public function add_settings_page() {
		global $menu;
		
		$parent_exists = false;
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && $item[2] === 'ar_custom_theme_options' ) {
				$parent_exists = true;
				break;
			}
		}

		if ( $parent_exists ) {
			add_submenu_page(
				'ar_custom_theme_options',
				'Backup to Google Drive',
				'Backup to Google Drive',
				'manage_options',
				'backup-to-gdrive',
				array( $this, 'render_settings_page' )
			);
		} else {
			add_menu_page(
				'Backup to Google Drive',
				'Backup to Google Drive',
				'manage_options',
				'backup-to-gdrive',
				array( $this, 'render_settings_page' ),
				'dashicons-cloud-upload',
				80
			);
		}
	}

	public function register_settings() {
		register_setting( $this->option_group, $this->option_name );

		add_settings_section(
			'gdrive_section',
			'Google Drive Configuration',
			array( $this, 'render_section_info' ),
			'backup-to-gdrive'
		);

		add_settings_field(
			'client_id',
			'Client ID',
			array( $this, 'render_client_id_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'client_secret',
			'Client Secret',
			array( $this, 'render_client_secret_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'refresh_token',
			'Refresh Token',
			array( $this, 'render_refresh_token_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'folder_id',
			'Folder ID',
			array( $this, 'render_folder_id_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'sync_folders',
			'Folders to Sync',
			array( $this, 'render_sync_folders_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'exclude_patterns',
			'Exclude Patterns',
			array( $this, 'render_exclude_patterns_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'avoid_resmush_duplicates',
			'Avoid Resmush.it Duplicates',
			array( $this, 'render_avoid_resmush_duplicates_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'verbose_logging',
			'Verbose Logging',
			array( $this, 'render_verbose_logging_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'schedule',
			'Backup Schedule',
			array( $this, 'render_schedule_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'schedule_hour',
			'Run Hour',
			array( $this, 'render_schedule_hour_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);

		add_settings_field(
			'schedule_minute',
			'Run Minute',
			array( $this, 'render_schedule_minute_field' ),
			'backup-to-gdrive',
			'gdrive_section'
		);
	}

	public function render_section_info() {
		echo '<p>Syncs your uploads folder to Google Drive. Only new files are uploaded - existing files are skipped.</p>';
	}

	public function render_client_id_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['client_id'] ) ? trim( $options['client_id'] ) : '';
		echo '<input type="text" name="' . $this->option_name . '[client_id]" value="' . esc_attr( $value ) . '" class="regular-text" />';
	}

	public function render_client_secret_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['client_secret'] ) ? trim( $options['client_secret'] ) : '';
		echo '<input type="password" name="' . $this->option_name . '[client_secret]" value="' . esc_attr( $value ) . '" class="regular-text" />';
	}

	public function render_refresh_token_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['refresh_token'] ) ? trim( $options['refresh_token'] ) : '';
		echo '<input type="text" name="' . $this->option_name . '[refresh_token]" value="' . esc_attr( $value ) . '" class="regular-text" readonly />';
		echo '<p class="description">Obtain this via OAuth Authorization below.</p>';
	}

	public function render_folder_id_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['folder_id'] ) ? $options['folder_id'] : '';
		echo '<input type="text" name="' . $this->option_name . '[folder_id]" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">The ID of the Google Drive folder where uploads will be synced. Leave empty to use root folder.</p>';
	}

	public function render_sync_folders_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['sync_folders'] ) ? $options['sync_folders'] : "wp-content/uploads\nwp-content/plugins";
		echo '<textarea name="' . $this->option_name . '[sync_folders]" rows="4" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">One folder path per line, relative to WordPress root (e.g., wp-content/uploads). New uploads are auto-queued for sync.</p>';
	}

	public function render_exclude_patterns_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['exclude_patterns'] ) ? $options['exclude_patterns'] : "";
		echo '<textarea name="' . $this->option_name . '[exclude_patterns]" rows="4" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">One regex pattern per line. Files matching any pattern will be excluded from sync (e.g., <code>\.log$</code> to exclude .log files).</p>';
	}

	public function render_avoid_resmush_duplicates_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['avoid_resmush_duplicates'] ) ? $options['avoid_resmush_duplicates'] : '';
		echo '<label for="backup_avoid_resmush_duplicates">';
		echo '<input type="checkbox" id="backup_avoid_resmush_duplicates" name="' . $this->option_name . '[avoid_resmush_duplicates]" value="1"' . checked( $value, '1', false ) . ' /> ';
		echo 'Avoid Resmush.it duplicates</label>';
		echo '<p class="description">When enabled, only the original images (with <code>-unsmushed</code> suffix) will be kept. Optimized copies without this suffix will be removed from the sync queue.</p>';
	}

	public function render_verbose_logging_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['verbose_logging'] ) ? $options['verbose_logging'] : '';
		echo '<label for="backup_verbose_logging">';
		echo '<input type="checkbox" id="backup_verbose_logging" name="' . $this->option_name . '[verbose_logging]" value="1"' . checked( $value, '1', false ) . ' /> ';
		echo 'Enable verbose logging</label>';
		echo '<p class="description">Log all sync actions including uploads, skips, and deletions. Useful for debugging.</p>';
	}

	public function render_schedule_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['schedule'] ) ? $options['schedule'] : 'daily';
		?>
		<select name="<?php echo $this->option_name; ?>[schedule]" id="backup_schedule_select">
			<option value="hourly" <?php selected( $value, 'hourly' ); ?>>Hourly</option>
			<option value="twicedaily" <?php selected( $value, 'twicedaily' ); ?>>Twice Daily</option>
			<option value="daily" <?php selected( $value, 'daily' ); ?>>Daily</option>
			<option value="weekly" <?php selected( $value, 'weekly' ); ?>>Weekly</option>
		</select>
		<?php
	}

	public function render_schedule_hour_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['schedule_hour'] ) ? intval( $options['schedule_hour'] ) : 2;
		?>
		<select name="<?php echo $this->option_name; ?>[schedule_hour]" id="backup_schedule_hour">
			<?php for ( $i = 0; $i < 24; $i++ ) : ?>
				<option value="<?php echo $i; ?>" <?php selected( $value, $i ); ?>>
					<?php echo sprintf( '%02d:00', $i ); ?>
				</option>
			<?php endfor; ?>
		</select>
		<p class="description">Hour of the day to run (for daily/weekly/twicedaily schedules).</p>
		<?php
	}

	public function render_schedule_minute_field() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['schedule_minute'] ) ? intval( $options['schedule_minute'] ) : 0;
		?>
		<select name="<?php echo $this->option_name; ?>[schedule_minute]" id="backup_schedule_minute">
			<?php for ( $i = 0; $i < 60; $i++ ) : ?>
				<option value="<?php echo $i; ?>" <?php selected( $value, $i ); ?>>
					<?php echo sprintf( ':%02d', $i ); ?>
				</option>
			<?php endfor; ?>
		</select>
		<p class="description">Minute of the hour to run.</p>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = get_option( $this->option_name );
		$client_id     = isset( $options['client_id'] ) ? trim( $options['client_id'] ) : '';
		$client_secret = isset( $options['client_secret'] ) ? trim( $options['client_secret'] ) : '';
		$refresh_token = isset( $options['refresh_token'] ) ? trim( $options['refresh_token'] ) : '';
		$has_oauth_creds = ! empty( $client_id ) && ! empty( $client_secret );

		$this->handle_actions( $client_id, $client_secret, $refresh_token );
		?>
		<div class="wrap">
			<h1>Backup to Google Drive</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_group );
				do_settings_sections( 'backup-to-gdrive' );
				submit_button( 'Save Settings' );
				?>
			</form>

			<hr>

			<h2>OAuth Authorization</h2>
			<p>Step 1: Enter Client ID and Client Secret above, save. Step 2: Click button below to get authorization URL.</p>

			<form method="post">
				<?php wp_nonce_field( 'backup_to_gdrive_oauth', 'backup_to_gdrive_oauth_nonce' ); ?>
				<input type="hidden" name="get_auth_url" value="1" />
				<p>
					<input type="submit" class="button" value="<?php echo $has_oauth_creds ? 'Get Google Authorization URL' : 'Enter Client ID and Secret First'; ?>" <?php echo $has_oauth_creds ? '' : 'disabled'; ?> />
				</p>
			</form>

			<?php
			if ( isset( $_POST['get_auth_url'] ) && isset( $_POST['backup_to_gdrive_oauth_nonce'] ) && wp_verify_nonce( $_POST['backup_to_gdrive_oauth_nonce'], 'backup_to_gdrive_oauth' ) && $has_oauth_creds ) {
				$oauth = new BackupToGoogleDriveOAuth( $client_id, $client_secret );
				$auth_url = $oauth->get_auth_url();
				?>
				<p><strong>Step 3:</strong> Click the button below to authorize:</p>
				<p><a href="<?php echo esc_url( $auth_url ); ?>" target="_blank" class="button button-primary">Authorize in Google</a></p>
				<p><strong>Step 4:</strong> After authorization, paste the code from the URL here:</p>
				<form method="get">
					<input type="hidden" name="page" value="backup-to-gdrive" />
					<input type="hidden" name="oauth_step" value="exchange" />
					<p>
						<input type="text" name="oauth_code" class="regular-text" style="width: 400px;" placeholder="Paste authorization code here..." required />
					</p>
					<p>
						<input type="submit" class="button button-primary" value="Get Refresh Token" />
					</p>
				</form>
				<?php
			}

			if ( isset( $_GET['oauth_step'] ) && 'exchange' === $_GET['oauth_step'] && isset( $_GET['oauth_code'] ) ) {
				$raw_code = isset( $_GET['oauth_code'] ) ? $_GET['oauth_code'] : '';
				$code = urldecode( $raw_code );
				
				$oauth = new BackupToGoogleDriveOAuth( $client_id, $client_secret );
				$result = $oauth->exchange_code_for_tokens( $code );

				if ( is_wp_error( $result ) ) {
					echo '<div class="notice notice-error"><p>Failed to get refresh token: ' . esc_html( $result->get_error_message() ) . '</p></div>';
				} elseif ( is_array( $result ) && isset( $result['refresh_token'] ) ) {
					$options['refresh_token'] = $result['refresh_token'];
					update_option( $this->option_name, $options );
					echo '<div class="notice notice-success"><p>Refresh token obtained successfully! You can now test the connection.</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>Unexpected response - no refresh token found.</p></div>';
					echo '<pre style="background:#f0f0f0;padding:10px;">' . esc_html( print_r( $result, true ) ) . '</pre>';
				}
			}
			?>

			<hr>

			<h2>Manual Actions</h2>
			<form method="post">
				<?php wp_nonce_field( 'backup_to_gdrive_test', 'backup_to_gdrive_test_nonce' ); ?>
				<input type="hidden" name="test_connection" value="1" />
				<p>
					<input type="submit" class="button button-secondary" value="Test Google Drive Connection" />
				</p>
			</form>

			<hr>

			<h2>Sync Uploads to Google Drive</h2>
			<p id="backup-gdrive-status"></p>
			<div id="backup-gdrive-progress-container" style="display: none; margin: 15px 0;">
				<div style="background: #f0f0f0; border-radius: 4px; height: 24px; overflow: hidden;">
					<div id="backup-gdrive-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
				</div>
				<p id="backup-gdrive-progress-text" style="margin: 10px 0 5px 0;"></p>
			</div>
			<div id="backup-gdrive-actions">
				<p>
					<button type="button" id="backup-gdrive-start-queue-btn" class="button button-primary">Sync Queued Files (<?php
						$manager = new BackupToGoogleDriveManager();
						echo intval( $manager->get_queue_count() );
					?> pending)</button>
					<button type="button" id="backup-gdrive-start-full-btn" class="button button-secondary">Full Sync (all folders)</button>
				</p>
				<button type="button" id="backup-gdrive-cancel-btn" class="button button-secondary" style="display: none;">Cancel</button>
			</div>

			<h3 style="margin-top: 30px;">Sync Log</h3>
			<div id="backup-gdrive-log-container">
				<label>
					<input type="checkbox" id="backup-gdrive-auto-scroll" checked="checked" />
					Auto-scroll to latest
				</label>
				<button type="button" id="backup-gdrive-refresh-log-btn" class="button button-secondary" style="margin-left: 10px;">Refresh</button>
				<button type="button" id="backup-gdrive-clear-log-btn" class="button button-secondary">Clear</button>
			</div>
			<div id="backup-gdrive-sync-log" style="margin-top: 10px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;"></div>

			<h3 style="margin-top: 30px;">Error Log</h3>
			<div id="backup-gdrive-error-container">
				<button type="button" id="backup-gdrive-refresh-errors-btn" class="button button-secondary">Refresh</button>
				<button type="button" id="backup-gdrive-clear-errors-btn" class="button button-secondary">Clear</button>
			</div>
			<div id="backup-gdrive-error-log" style="margin-top: 10px; max-height: 300px; overflow-y: auto;"></div>

			<script>
			(function() {
				var nonce = '<?php echo wp_create_nonce( 'backup_gdrive_sync_nonce' ); ?>';
				var statusEl = document.getElementById('backup-gdrive-status');
				var progressContainer = document.getElementById('backup-gdrive-progress-container');
				var progressBar = document.getElementById('backup-gdrive-progress-bar');
				var progressText = document.getElementById('backup-gdrive-progress-text');
				var startQueueBtn = document.getElementById('backup-gdrive-start-queue-btn');
				var startFullBtn = document.getElementById('backup-gdrive-start-full-btn');
				var cancelBtn = document.getElementById('backup-gdrive-cancel-btn');
				var polling = false;
				var currentMode = 'queue';

				function updateStatus(msg, type) {
					statusEl.innerHTML = '<div class="notice notice-' + type + '"><p>' + msg + '</p></div>';
				}

				function poll() {
					if (!polling) return;

					jQuery.post(ajaxurl, {
						action: 'backup_gdrive_sync_process',
						nonce: nonce
					}, function(response) {
						console.log('Poll response:', response);
						if (!response.success) {
							updateStatus('Error: ' + (response.data.message || 'Unknown error'), 'error');
							stopPolling();
							return;
						}

						var data = response.data;
						var percent = data.total > 0 ? Math.round((data.index / data.total) * 100) : 0;
						progressBar.style.width = percent + '%';
						var statusHtml = 'Uploaded: ' + data.uploaded + ' | Skipped: ' + data.skipped + ' | Deleted: ' + data.deleted + ' | GDrive Deleted: ' + (data.gdrive_deleted || 0) + ' | Errors: ' + data.errors + ' (' + data.index + '/' + data.total + ')';
						if (data.last_error) {
							statusHtml += '<br><small style="color: #d63638;">Last error: ' + data.last_error + '</small>';
						}
						progressText.innerHTML = statusHtml;

						if (data.log_entries && data.log_entries.length > 0) {
							data.log_entries.forEach(function(entry) {
								addLogEntry(entry.type, entry.message);
							});
						}

						if (data.status === 'completed') {
							var noticeType = data.errors > 0 ? 'warning' : 'success';
							updateStatus('Sync completed! Uploaded: ' + data.uploaded + ', Skipped: ' + data.skipped + ', Deleted: ' + data.deleted + ', GDrive Deleted: ' + (data.gdrive_deleted || 0) + ', Errors: ' + data.errors, noticeType);
							stopPolling();
						} else if (data.status === 'running') {
							setTimeout(poll, 1000);
						}
					}).fail(function(xhr, status, error) {
						console.error('Poll failed:', status, error);
						updateStatus('Request failed, retrying...', 'error');
						setTimeout(poll, 2000);
					});
				}

				function stopPolling() {
					polling = false;
					progressContainer.style.display = 'none';
					startQueueBtn.style.display = 'inline-block';
					startFullBtn.style.display = 'inline-block';
					startQueueBtn.disabled = false;
					startFullBtn.disabled = false;
					cancelBtn.style.display = 'none';
					refreshQueueCount();
				}

				function refreshQueueCount() {
					jQuery.post(ajaxurl, {
						action: 'backup_gdrive_get_queue_count',
						nonce: nonce
					}, function(response) {
						if (response.success) {
							startQueueBtn.textContent = 'Sync Queued Files (' + response.data.count + ' pending)';
						}
					});
				}

				function startSync(mode) {
					currentMode = mode;
					startQueueBtn.disabled = true;
					startFullBtn.disabled = true;
					updateStatus('Initializing sync...', 'info');
					progressContainer.style.display = 'block';
					progressBar.style.width = '0%';
					progressText.innerHTML = 'Counting files...';
					syncLogDiv.innerHTML = '';

					jQuery.post(ajaxurl, {
						action: 'backup_gdrive_sync_start',
						nonce: nonce,
						mode: mode
					}, function(response) {
						if (!response.success) {
							updateStatus('Error: ' + (response.data.message || 'Unknown error'), 'error');
							stopPolling();
							return;
						}

						if (response.data.total === 0) {
							updateStatus('No files to sync.', 'success');
							stopPolling();
							return;
						}

						progressText.innerHTML = 'Starting sync...';
						polling = true;
						cancelBtn.style.display = 'inline-block';
						poll();
					}).fail(function() {
						updateStatus('Failed to start sync', 'error');
						stopPolling();
					});
				}

				startQueueBtn.addEventListener('click', function() { startSync('queue'); });
				startFullBtn.addEventListener('click', function() { startSync('full'); });

				cancelBtn.addEventListener('click', function() {
					jQuery.post(ajaxurl, {
						action: 'backup_gdrive_sync_cancel',
						nonce: nonce
					}, function() {
						stopPolling();
						updateStatus('Sync cancelled.', 'warning');
					});
				});

				var refreshErrorsBtn = document.getElementById('backup-gdrive-refresh-errors-btn');
				var clearErrorsBtn = document.getElementById('backup-gdrive-clear-errors-btn');
				var errorLogDiv = document.getElementById('backup-gdrive-error-log');

				var syncLogDiv = document.getElementById('backup-gdrive-sync-log');
				var autoScrollCheckbox = document.getElementById('backup-gdrive-auto-scroll');
				var refreshLogBtn = document.getElementById('backup-gdrive-refresh-log-btn');
				var clearLogBtn = document.getElementById('backup-gdrive-clear-log-btn');

				function addLogEntry(type, message) {
					var timestamp = new Date().toLocaleTimeString();
					var colorClass = '';
					switch(type) {
						case 'upload':
							colorClass = 'color: #2271b1;';
							break;
						case 'skip':
							colorClass = 'color: #777;';
							break;
						case 'delete':
						case 'gdrive_delete':
							colorClass = 'color: #d63638;';
							break;
						case 'error':
							colorClass = 'color: #d63638; font-weight: bold;';
							break;
						case 'info':
							colorClass = 'color: #00a32a;';
							break;
						default:
							colorClass = 'color: #333;';
					}
					var entry = '<div style="' + colorClass + '">[' + timestamp + '] ' + message + '</div>';
					syncLogDiv.insertAdjacentHTML('beforeend', entry);
					if (syncLogDiv.childElementCount > 100) {
						syncLogDiv.removeChild(syncLogDiv.firstChild);
					}
					if (autoScrollCheckbox.checked) {
						syncLogDiv.scrollTop = syncLogDiv.scrollHeight;
					}
				}

				function loadSyncLog() {
					jQuery.post(ajaxurl, {
						action: 'backup_gdrive_get_sync_log',
						nonce: nonce
					}, function(response) {
						if (response.success && response.data.log) {
							syncLogDiv.innerHTML = '';
							response.data.log.forEach(function(entry) {
								var timestamp = entry.timestamp ? new Date(entry.timestamp * 1000).toLocaleString() : '';
								var colorClass = '';
								switch(entry.type) {
									case 'upload':
										colorClass = 'color: #2271b1;';
										break;
									case 'skip':
										colorClass = 'color: #777;';
										break;
									case 'delete':
									case 'gdrive_delete':
										colorClass = 'color: #d63638;';
										break;
									case 'error':
										colorClass = 'color: #d63638; font-weight: bold;';
										break;
									case 'info':
										colorClass = 'color: #00a32a;';
										break;
									default:
										colorClass = 'color: #333;';
								}
								syncLogDiv.insertAdjacentHTML('beforeend', '<div style="' + colorClass + '">[' + timestamp + '] ' + entry.message + '</div>');
							});
							if (autoScrollCheckbox.checked) {
								syncLogDiv.scrollTop = syncLogDiv.scrollHeight;
							}
						}
					});
				}

				refreshLogBtn.addEventListener('click', loadSyncLog);
				clearLogBtn.addEventListener('click', function() {
					if (confirm('Clear all sync logs?')) {
						jQuery.post(ajaxurl, {
							action: 'backup_gdrive_clear_sync_log',
							nonce: nonce
						}, function() {
							syncLogDiv.innerHTML = '';
						});
					}
				});

				function loadErrorLog() {
					jQuery.post(ajaxurl, {
						action: 'backup_gdrive_get_error_log',
						nonce: nonce
					}, function(response) {
						if (response.success && response.data.log.length > 0) {
							var html = '<table class="widefat" style="width: 100%; border-collapse: collapse;">';
							html += '<thead><tr><th style="padding: 8px; border: 1px solid #ddd; background: #f5f5f5;">Time</th><th style="padding: 8px; border: 1px solid #ddd; background: #f5f5f5;">File</th><th style="padding: 8px; border: 1px solid #ddd; background: #f5f5f5;">Error</th></tr></thead>';
							html += '<tbody>';
							response.data.log.forEach(function(entry) {
								var date = new Date(entry.timestamp * 1000);
								var timeStr = date.toLocaleString();
								html += '<tr><td style="padding: 8px; border: 1px solid #ddd;">' + timeStr + '</td><td style="padding: 8px; border: 1px solid #ddd;">' + entry.file + '</td><td style="padding: 8px; border: 1px solid #ddd; color: #d63638;">' + entry.message + '</td></tr>';
							});
							html += '</tbody></table>';
							errorLogDiv.innerHTML = html;
						} else {
							errorLogDiv.innerHTML = '<p>No errors logged.</p>';
						}
					});
				}

				refreshErrorsBtn.addEventListener('click', loadErrorLog);
				clearErrorsBtn.addEventListener('click', function() {
					if (confirm('Clear all error logs?')) {
						jQuery.post(ajaxurl, {
							action: 'backup_gdrive_clear_error_log',
							nonce: nonce
						}, function() {
							loadErrorLog();
						});
					}
				});

				loadErrorLog();
				loadSyncLog();
			})();
			</script>
		</div>
		<?php
	}

	private function handle_actions( $client_id, $client_secret, $refresh_token ) {
		if ( isset( $_POST['test_connection'] ) && isset( $_POST['backup_to_gdrive_test_nonce'] ) && wp_verify_nonce( $_POST['backup_to_gdrive_test_nonce'], 'backup_to_gdrive_test' ) ) {
			if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
				echo '<div class="notice notice-error"><p>Please configure all Google Drive credentials first (Client ID, Client Secret, and Refresh Token).</p></div>';
			} else {
				$gdrive = new BackupToGoogleDriveClient( $client_id, $client_secret, $refresh_token );
				$result = $gdrive->test_connection();

				if ( is_wp_error( $result ) ) {
					echo '<div class="notice notice-error"><p>Connection failed: ' . esc_html( $result->get_error_message() ) . '</p></div>';
				} else {
					$email = isset( $result['emailAddress'] ) ? $result['emailAddress'] : 'Connected';
					echo '<div class="notice notice-success"><p>Connection successful! Connected as: ' . esc_html( $email ) . '</p></div>';
				}
			}
		}
	}
}

new BackupToGoogleDriveSettings();
