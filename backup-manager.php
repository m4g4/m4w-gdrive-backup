<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupToGoogleDriveManager {
	private $options;
	private $upload_dir;
	private $transient_key = 'backup_to_gdrive_progress';
	private $queue_key = 'backup_to_gdrive_queue';
	private $error_log_key = 'backup_to_gdrive_error_log';
	private $sync_log_key = 'backup_to_gdrive_sync_log';

	public function __construct() {
		$this->options    = get_option( 'backup_to_gdrive_options' );

		if ( isset( $this->options['client_id'] ) ) {
			$this->options['client_id'] = trim( $this->options['client_id'] );
		}
		if ( isset( $this->options['client_secret'] ) ) {
			$this->options['client_secret'] = trim( $this->options['client_secret'] );
		}
		if ( isset( $this->options['refresh_token'] ) ) {
			$this->options['refresh_token'] = trim( $this->options['refresh_token'] );
		}

		$this->upload_dir = wp_upload_dir();
	}

	public function add_to_queue( $file_path ) {
		$queue = get_option( $this->queue_key, array() );
		
		$relative_path = $this->get_relative_path( $file_path );
		if ( ! $relative_path ) {
			return false;
		}

		if ( isset( $queue[ $relative_path ] ) ) {
			return false;
		}

		$queue[ $relative_path ] = array(
			'absolute_path' => $file_path,
			'relative_path' => $relative_path,
			'added_at'  => time(),
			'queued_at' => time(),
		);

		update_option( $this->queue_key, $queue );
		return true;
	}

	public function remove_from_queue( $file_path ) {
		$queue = get_option( $this->queue_key, array() );
		$relative_path = $this->get_relative_path( $file_path );

		if ( $relative_path && isset( $queue[ $relative_path ] ) ) {
			unset( $queue[ $relative_path ] );
			update_option( $this->queue_key, $queue );
			return true;
		}

		foreach ( $queue as $key => $item ) {
			if ( $item['absolute_path'] === $file_path || $item['relative_path'] === $relative_path ) {
				unset( $queue[ $key ] );
				update_option( $this->queue_key, $queue );
				return true;
			}
		}

		return false;
	}

	public function get_queue_count() {
		$queue = get_option( $this->queue_key, array() );
		return count( $queue );
	}

	public function init_sync( $mode = 'queue' ) {
		if ( empty( $this->options['client_id'] ) || empty( $this->options['client_secret'] ) || empty( $this->options['refresh_token'] ) ) {
			return new WP_Error( 'config_error', 'Google Drive credentials are not configured' );
		}

		$files = array();
		$files_to_delete = array();

		if ( 'queue' === $mode ) {
			$queue = get_option( $this->queue_key, array() );
			$files = array();
			foreach ( $queue as $item ) {
				$files[] = $item['relative_path'];
			}
			$this->log_sync( 'info', 'Starting queue sync with ' . count( $files ) . ' files' );

			$files = $this->filter_should_keep( $files, array_flip( $files ) );
			$this->log_sync( 'info', 'After filtering non-keep files: ' . count( $files ) . ' files' );
		} else {
			$this->log_sync( 'info', 'Starting full sync...' );

			$folders = $this->get_folders_to_sync();
			foreach ( $folders as $folder ) {
				$folder_path = ABSPATH . $folder;
				if ( is_dir( $folder_path ) ) {
					$folder_files = $this->collect_files( $folder_path, $folder_path, $folder );
					$files = array_merge( $files, $folder_files );
				}
			}

			$this->log_sync( 'info', 'Found ' . count( $files ) . ' local files to process' );

			$files = $this->filter_should_keep( $files, array_flip( $files ) );
			$this->log_sync( 'info', 'After filtering non-keep files: ' . count( $files ) . ' files' );

			$files_to_delete = $this->get_gdrive_files_to_delete();
			if ( ! empty( $files_to_delete ) ) {
				$this->log_sync( 'info', 'Found ' . count( $files_to_delete ) . ' files to delete from GDrive' );
			}
		}

		$state = array(
			'status'     => 'running',
			'files'      => $files,
			'queue_items' => 'queue' === $mode ? $queue : null,
			'index'      => 0,
			'uploaded'   => 0,
			'skipped'    => 0,
			'errors'     => 0,
			'deleted'    => 0,
			'gdrive_deleted' => 0,
			'started_at' => time(),
			'mode'       => $mode,
			'gdrive_files_to_delete' => $files_to_delete,
			'gdrive_delete_index'    => 0,
		);

		set_transient( $this->transient_key, $state, HOUR_IN_SECONDS );

		return array(
			'total'  => count( $files ) + count( $files_to_delete ),
			'status' => 'running',
			'gdrive_to_delete' => count( $files_to_delete ),
		);
	}

	public function process_chunk( $chunk_size = 5 ) {
		$state = get_transient( $this->transient_key );

		if ( ! $state || 'running' !== $state['status'] ) {
			return $this->get_status();
		}

		$gdrive = new BackupToGoogleDriveClient(
			$this->options['client_id'],
			$this->options['client_secret'],
			$this->options['refresh_token']
		);

		$folder_id = ! empty( $this->options['folder_id'] ) ? $this->options['folder_id'] : null;
		$files = is_array( $state['files'] ) ? array_values( $state['files'] ) : array();
		$files = array_values( array_filter( $files, function( $f ) { return ! empty( $f ) && is_string( $f ); } ) );
		$queue_items = isset( $state['queue_items'] ) ? $state['queue_items'] : null;
		$end = min( $state['index'] + $chunk_size, count( $files ) );
		$last_error = '';

		for ( $i = $state['index']; $i < $end; $i++ ) {
			if ( ! isset( $files[ $i ] ) ) {
				continue;
			}
			$relative_path = $files[ $i ];

			if ( 'queue' === $state['mode'] && $queue_items && isset( $queue_items[ $relative_path ] ) ) {
				$absolute_path = $queue_items[ $relative_path ]['absolute_path'];
			} else {
				$absolute_path = ABSPATH . $relative_path;
			}

			if ( empty( $relative_path ) || false !== strpos( $relative_path, 'Untitled' ) ) {
				error_log( 'GDrive sync: invalid relative_path: ' . var_export( $relative_path, true ) );
				$this->log_sync( 'error', 'Invalid path: ' . $relative_path );
				$state['errors']++;
				$last_error = 'Invalid path';
				$state['index']++;
				continue;
			}

			if ( ! file_exists( $absolute_path ) ) {
				$this->log_sync( 'delete', 'Local file not found (will be marked deleted): ' . $relative_path );
				$state['deleted']++;
				$state['index']++;
				continue;
			}

			$path_parts = explode( '/', $relative_path );
			$file_name = array_pop( $path_parts );
			$folder_path = implode( '/', $path_parts );

			$exists = $gdrive->file_exists_in_folder( $file_name, $folder_path, $folder_id );

			if ( is_wp_error( $exists ) ) {
				$this->log_sync( 'error', 'Error checking file existence: ' . $exists->get_error_message() );
				$state['errors']++;
				$last_error = $exists->get_error_message();
				$state['index']++;
				continue;
			}

			if ( $exists ) {
				$this->log_sync( 'skip', 'File already exists in GDrive: ' . $relative_path );
				$state['skipped']++;
				$state['index']++;
				continue;
			}

			$result = $gdrive->upload_file( $absolute_path, $relative_path, $folder_id );

			if ( is_wp_error( $result ) ) {
				$this->log_error( $relative_path, $result->get_error_message(), $result->get_error_code() );
				$this->log_sync( 'error', 'Upload failed: ' . $result->get_error_message() . ' (' . $relative_path . ')' );
				$state['errors']++;
				$last_error = $result->get_error_message() . ' (' . $relative_path . ')';
			} else {
				$this->log_sync( 'upload', 'Uploaded: ' . $relative_path );
				$state['uploaded']++;
				if ( 'queue' === $state['mode'] ) {
					$this->remove_from_queue_by_relative( $relative_path );
				}
			}
			$state['index']++;
		}

		$gdrive_delete_index = isset( $state['gdrive_delete_index'] ) ? $state['gdrive_delete_index'] : 0;
		$gdrive_files_to_delete = isset( $state['gdrive_files_to_delete'] ) ? $state['gdrive_files_to_delete'] : array();

		if ( $gdrive_delete_index < count( $gdrive_files_to_delete ) ) {
			$gdrive_end = min( $gdrive_delete_index + $chunk_size, count( $gdrive_files_to_delete ) );
			for ( $i = $gdrive_delete_index; $i < $gdrive_end; $i++ ) {
				$file_to_delete = $gdrive_files_to_delete[ $i ];
				$result = $gdrive->delete_file( $file_to_delete['id'] );

				if ( is_wp_error( $result ) ) {
					$this->log_error( $file_to_delete['path'], 'Failed to delete from GDrive: ' . $result->get_error_message() );
					$this->log_sync( 'error', 'Failed to delete from GDrive: ' . $result->get_error_message() . ' (' . $file_to_delete['path'] . ')' );
					$state['errors']++;
					$last_error = 'Failed to delete from GDrive: ' . $result->get_error_message();
				} else {
					$this->log_sync( 'gdrive_delete', 'Deleted from GDrive: ' . $file_to_delete['path'] );
					$state['gdrive_deleted']++;
				}
				$gdrive_delete_index++;
			}
			$state['gdrive_delete_index'] = $gdrive_delete_index;
		}

		if ( $state['index'] >= count( $files ) && $gdrive_delete_index >= count( $gdrive_files_to_delete ) ) {
			$state['status'] = 'completed';
			$this->log_sync( 'info', 'Sync completed! Uploaded: ' . $state['uploaded'] . ', Skipped: ' . $state['skipped'] . ', GDrive Deleted: ' . $state['gdrive_deleted'] . ', Errors: ' . $state['errors'] );
		}

		$state['files'] = $files;
		set_transient( $this->transient_key, $state, HOUR_IN_SECONDS );

		return array(
			'status'     => $state['status'],
			'uploaded'   => $state['uploaded'],
			'skipped'    => $state['skipped'],
			'errors'     => $state['errors'],
			'deleted'    => $state['deleted'],
			'gdrive_deleted' => $state['gdrive_deleted'],
			'index'      => $state['index'],
			'total'      => count( $files ) + count( $gdrive_files_to_delete ),
			'last_error' => $last_error,
			'log_entries' => $this->get_sync_log(),
		);
	}

	public function get_status() {
		$state = get_transient( $this->transient_key );

		if ( ! $state ) {
			return array(
				'status'   => 'idle',
				'uploaded' => 0,
				'skipped'  => 0,
				'errors'   => 0,
				'deleted'  => 0,
				'gdrive_deleted' => 0,
				'index'    => 0,
				'total'    => 0,
			);
		}

		$files = is_array( $state['files'] ) ? array_values( $state['files'] ) : array();
		$files = array_values( array_filter( $files, function( $f ) { return ! empty( $f ) && is_string( $f ); } ) );
		$gdrive_files_to_delete = isset( $state['gdrive_files_to_delete'] ) ? $state['gdrive_files_to_delete'] : array();

		return array(
			'status'   => $state['status'],
			'uploaded' => $state['uploaded'],
			'skipped'  => $state['skipped'],
			'errors'   => $state['errors'],
			'deleted'  => $state['deleted'],
			'gdrive_deleted' => isset( $state['gdrive_deleted'] ) ? $state['gdrive_deleted'] : 0,
			'index'    => $state['index'],
			'total'    => count( $files ) + count( $gdrive_files_to_delete ),
		);
	}

	private function log_error( $file_path, $message, $code = '' ) {
		$log = get_option( $this->error_log_key, array() );
		$log[] = array(
			'file'     => $file_path,
			'message'  => $message,
			'code'     => $code,
			'timestamp' => time(),
		);
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}
		update_option( $this->error_log_key, $log );
	}

	public function get_error_log() {
		return get_option( $this->error_log_key, array() );
	}

	public function clear_error_log() {
		delete_option( $this->error_log_key );
	}

	public function get_sync_log() {
		return get_option( $this->sync_log_key, array() );
	}

	public function clear_sync_log() {
		delete_option( $this->sync_log_key );
	}

	private function log_sync( $type, $message ) {
		$verbose = isset( $this->options['verbose_logging'] ) && $this->options['verbose_logging'] === '1';
		$always_log = array( 'upload', 'error', 'gdrive_delete' );

		if ( ! $verbose && ! in_array( $type, $always_log, true ) ) {
			return;
		}

		$log = get_option( $this->sync_log_key, array() );
		$log[] = array(
			'type'      => $type,
			'message'   => $message,
			'timestamp' => time(),
		);
		if ( count( $log ) > 500 ) {
			$log = array_slice( $log, -500 );
		}
		update_option( $this->sync_log_key, $log );
	}

	public function cancel_sync() {
		delete_transient( $this->transient_key );
		return array( 'status' => 'cancelled' );
	}

	public function run_backup() {
		if ( empty( $this->options['client_id'] ) || empty( $this->options['client_secret'] ) || empty( $this->options['refresh_token'] ) ) {
			return new WP_Error( 'config_error', 'Google Drive credentials are not configured' );
		}

		$gdrive = new BackupToGoogleDriveClient(
			$this->options['client_id'],
			$this->options['client_secret'],
			$this->options['refresh_token']
		);

		$folder_id = ! empty( $this->options['folder_id'] ) ? $this->options['folder_id'] : null;

		$stats = array(
			'uploaded' => 0,
			'skipped'  => 0,
			'errors'   => 0,
		);

		$folders = $this->get_folders_to_sync();
		foreach ( $folders as $folder ) {
			$folder_path = ABSPATH . $folder;
			if ( is_dir( $folder_path ) ) {
				$this->sync_directory( $gdrive, $folder_path, $folder_path, $folder, $folder_id, $stats );
			}
		}

		return array(
			'uploaded' => $stats['uploaded'],
			'skipped'  => $stats['skipped'],
			'errors'   => $stats['errors'],
		);
	}

	private function sync_directory( $gdrive, $base_path, $current_path, $relative_path, $folder_id, &$stats ) {
		$items = scandir( $current_path );

		$paths = array();
		foreach ( $items as $item ) {
			$paths[ $item ] = true;
		}

		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}

			$item_path = trailingslashit( $current_path ) . $item;
			$item_relative = $relative_path ? trailingslashit( $relative_path ) . $item : $item;

			if ( is_dir( $item_path ) ) {
				if ( $this->should_skip_path( $item_relative ) ) {
					continue;
				}
				$this->sync_directory( $gdrive, $base_path, $item_path, $item_relative, $folder_id, $stats );
				continue;
			}

			if ( ! $this->should_keep_file( $item_path, $paths ) ) {
				continue;
			}

			$path_parts = explode( '/', $item_relative );
			$file_name = array_pop( $path_parts );
			$folder_path = implode( '/', $path_parts );

			$exists = $gdrive->file_exists_in_folder( $file_name, $folder_path, $folder_id );

			if ( is_wp_error( $exists ) ) {
				$stats['errors']++;
				continue;
			}

			if ( $exists ) {
				$stats['skipped']++;
				continue;
			}

			$result = $gdrive->upload_file( $item_path, $item_relative, $folder_id );

			if ( is_wp_error( $result ) ) {
				$stats['errors']++;
				continue;
			}

			$stats['uploaded']++;
		}
	}

	private function collect_files( $base_path, $current_path, $relative_path ) {
		$files = array();
		$items = scandir( $current_path );

		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}

			$item_path = trailingslashit( $current_path ) . $item;
			$item_relative = $relative_path ? trailingslashit( $relative_path ) . $item : $item;

			if ( is_dir( $item_path ) ) {
				if ( $this->should_skip_path( $item_relative ) ) {
					continue;
				}
				$files = array_merge( $files, $this->collect_files( $base_path, $item_path, $item_relative ) );
				continue;
			}

			$files[] = $item_relative;
		}

		return $files;
	}

	private function get_relative_path( $file_path ) {
		$folders = $this->get_folders_to_sync();
		foreach ( $folders as $folder ) {
			$folder_path = ABSPATH . $folder;
			if ( strpos( $file_path, $folder_path ) === 0 ) {
				return $folder . '/' . ltrim( str_replace( $folder_path, '', $file_path ), '/' );
			}
		}
		return false;
	}

	private function get_folders_to_sync() {
		$folders_option = isset( $this->options['sync_folders'] ) ? trim( $this->options['sync_folders'] ) : 'wp-content/uploads';
		$folders = array_filter( array_map( 'trim', explode( "\n", $folders_option ) ) );
		if ( empty( $folders ) ) {
			$folders = array( 'wp-content/uploads' );
		}
		return $folders;
	}

	private function remove_from_queue_by_relative( $relative_path ) {
		$queue = get_option( $this->queue_key, array() );
		if ( isset( $queue[ $relative_path ] ) ) {
			unset( $queue[ $relative_path ] );
			update_option( $this->queue_key, $queue );
		}

		$state = get_transient( $this->transient_key );
		if ( $state && isset( $state['queue_items'][ $relative_path ] ) ) {
			unset( $state['queue_items'][ $relative_path ] );
			set_transient( $this->transient_key, $state, HOUR_IN_SECONDS );
		}
	}

	public function is_original_image( $file_path ) {
		$filename = wp_basename( $file_path );
		$info = pathinfo( $filename );
		$ext = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';

		$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' );
		if ( ! in_array( $ext, $image_extensions, true ) ) {
			return true;
		}

		$escaped_ext = preg_quote( $ext, '/' );

		$size_pattern = '/-\d+x\d+(?:\@2x)?\.' . $escaped_ext . '$/i';
		if ( preg_match( $size_pattern, $filename ) ) {
			return false;
		}

		$retina_pattern = '/-\d+x\d+\@2x\.' . $escaped_ext . '$/i';
		if ( preg_match( $retina_pattern, $filename ) ) {
			return false;
		}

		$intermediate_sizes = array_merge(
			get_intermediate_image_sizes(),
			array( 'full' )
		);

		foreach ( $intermediate_sizes as $size ) {
			$size = preg_quote( $size, '/' );
			$named_size_pattern = '/-' . $size . '(?:-\d+x\d+)?\.' . $escaped_ext . '$/i';
			if ( preg_match( $named_size_pattern, $filename ) ) {
				return false;
			}
		}
	
		return true;
	}

	public function filter_should_keep( $files, $all_files ) {
		return array_filter( $files, function( $file_path ) use ( $all_files ) {
			return $this->should_keep_file( $file_path, $all_files );
		} );
	}

	private function get_gdrive_files_to_delete( ) {
		$gdrive = new BackupToGoogleDriveClient(
			$this->options['client_id'],
			$this->options['client_secret'],
			$this->options['refresh_token']
		);

		$folder_id = ! empty( $this->options['folder_id'] ) ? $this->options['folder_id'] : null;

		$gdrive_files = $gdrive->list_files_in_folder_recursive( $folder_id );

		if ( is_wp_error( $gdrive_files ) ) {
			error_log( 'GDrive sync: Failed to list GDrive files: ' . $gdrive_files->get_error_message() );
			return array();
		}

		$files_to_delete = array();

		$gdrive_paths = array();
		foreach ( $gdrive_files as $gdrive_file ) {
			$gdrive_paths[ $gdrive_file['path'] ] = true;
		}

		foreach ( $gdrive_files as $gdrive_file ) {
			$path = $gdrive_file['path'];

			if ( $this->should_delete_gdrive_file( $path, $gdrive_paths ) ) {
				$this->log_sync( 'info', 'Deleting GDrive file: ' . $path );
				$files_to_delete[] = array(
					'id'   => $gdrive_file['id'],
					'path' => $path,
				);
			} else {
				$this->log_sync( 'info', 'Keeping GDrive file: ' . $path );
			}
		}

		return $files_to_delete;
	}

	private function should_delete_gdrive_file( $path, $gdrive_paths) {
		return ! $this->should_keep_file( $path, $gdrive_paths );
	}

	private function should_skip_path( $relative_path ) {
		$patterns = $this->get_exclude_patterns();
		if ( empty( $patterns ) ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			if ( @preg_match( $pattern, $relative_path ) ) {
				return true;
			}
		}
		return false;
	}

	public function should_keep_file( $path, $paths ) {
		if ( $this->should_skip_path( $path ) ) {
			return false;
		}

		if ( ! $this->is_original_image( $path ) ) {
			return false;
		}

		$avoid_resmush = isset( $this->options['avoid_resmush_duplicates'] ) && $this->options['avoid_resmush_duplicates'] === '1';
		if ( $avoid_resmush && $this->is_resmush_duplicate( $path, $paths ) ) {
			return false;
		}

		return true;
	}

	private function is_resmush_duplicate( $file_path, $all_files) {
		$info = pathinfo( $file_path );
		$ext = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';

		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return false;
		}

		$basename = isset( $info['basename'] ) ? $info['basename'] : wp_basename( $file_path );
		$dirname = isset( $info['dirname'] ) && $info['dirname'] !== '.' ? $info['dirname'] . '/' : '';
		$filename = isset( $info['filename'] ) ? $info['filename'] : preg_replace( '/\.(jpe?g|png)$/i', '', $basename );

		if ( preg_match( '/-unsmushed$/i', $filename ) ) {
			return false;
		}

		$potential_unsmushed = $dirname . $filename . '-unsmushed.' . $ext;

		return isset( $all_files[ $potential_unsmushed ] );
	}

	private function get_exclude_patterns() {
		$patterns_option = isset( $this->options['exclude_patterns'] ) ? trim( $this->options['exclude_patterns'] ) : '';
		if ( empty( $patterns_option ) ) {
			return array();
		}

		$raw_patterns = array_filter( array_map( 'trim', explode( "\n", $patterns_option ) ) );
		if ( empty( $raw_patterns ) ) {
			return array();
		}

		$patterns = array();
		foreach ( $raw_patterns as $pattern ) {
			$normalized = $this->normalize_regex_pattern( $pattern );
			if ( $normalized ) {
				$patterns[] = $normalized;
			}
		}

		return $patterns;
	}

	private function normalize_regex_pattern( $pattern ) {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			return '';
		}

		$delimiter = $pattern[0];
		if ( ! ctype_alnum( $delimiter ) && '\\' !== $delimiter && ' ' !== $delimiter ) {
			$last = strrpos( $pattern, $delimiter );
			if ( false !== $last && $last > 0 ) {
				$modifiers = substr( $pattern, $last + 1 );
				if ( '' === $modifiers || preg_match( '/^[a-zA-Z]*$/', $modifiers ) ) {
					return $pattern;
				}
			}
		}

		$escaped = str_replace( '/', '\/', $pattern );
		return '/' . $escaped . '/';
	}
}
