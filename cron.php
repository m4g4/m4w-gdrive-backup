<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupToGoogleDriveCron {
	const HOOK_NAME = 'backup_to_gdrive_cron';

	public function __construct() {
		add_action( self::HOOK_NAME, array( $this, 'run_scheduled_backup' ) );
		add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) );
	}

	public function add_custom_schedules( $schedules ) {
		return $schedules;
	}

	public function schedule_backup() {
		$options = get_option( 'backup_to_gdrive_options' );
		$schedule = isset( $options['schedule'] ) ? $options['schedule'] : 'daily';

		if ( ! wp_next_scheduled( self::HOOK_NAME ) ) {
			$hour = isset( $options['schedule_hour'] ) ? intval( $options['schedule_hour'] ) : 2;
			$minute = isset( $options['schedule_minute'] ) ? intval( $options['schedule_minute'] ) : 0;

			if ( 'hourly' === $schedule ) {
				$timestamp = $this->get_next_hourly_timestamp( $minute );
			} else {
				$timestamp = $this->get_next_daily_timestamp( $hour, $minute, $schedule );
			}

			wp_schedule_event( $timestamp, $schedule, self::HOOK_NAME );
		}
	}

	private function get_next_hourly_timestamp( $minute ) {
		$now = time();
		$current_hour = (int) date( 'G', $now );
		$current_minute = (int) date( 'i', $now );

		$next = mktime( $current_hour, $minute, 0, (int) date( 'n' ), (int) date( 'j' ), (int) date( 'Y' ) );
		if ( $next <= $now ) {
			$next += 3600;
		}
		return $next;
	}

	private function get_next_daily_timestamp( $hour, $minute, $schedule ) {
		$now = time();
		$current_hour = (int) date( 'G', $now );
		$current_minute = (int) date( 'i', $now );

		$next = mktime( $hour, $minute, 0, (int) date( 'n' ), (int) date( 'j' ), (int) date( 'Y' ) );

		if ( $next <= $now ) {
			if ( 'daily' === $schedule ) {
				$next = mktime( $hour, $minute, 0, (int) date( 'n' ), (int) date( 'j' ) + 1, (int) date( 'Y' ) );
			} elseif ( 'twicedaily' === $schedule ) {
				if ( $current_hour < 8 ) {
					$next = mktime( 8, $minute, 0, (int) date( 'n' ), (int) date( 'j' ), (int) date( 'Y' ) );
				} elseif ( $current_hour < 20 ) {
					$next = mktime( 20, $minute, 0, (int) date( 'n' ), (int) date( 'j' ), (int) date( 'Y' ) );
				} else {
					$next = mktime( 8, $minute, 0, (int) date( 'n' ), (int) date( 'j' ) + 1, (int) date( 'Y' ) );
				}
			} elseif ( 'weekly' === $schedule ) {
				$next = mktime( $hour, $minute, 0, (int) date( 'n' ), (int) date( 'j' ) + ( 7 - (int) date( 'w' ) ), (int) date( 'Y' ) );
			}
		}

		return $next;
	}

	public function unschedule_backup() {
		$timestamp = wp_next_scheduled( self::HOOK_NAME );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_NAME );
		}
	}

	public function run_scheduled_backup() {
		$manager = new BackupToGoogleDriveManager();
		$result  = $manager->run_backup();

		$options = get_option( 'backup_to_gdrive_options' );
		$schedule = isset( $options['schedule'] ) ? $options['schedule'] : 'daily';
		$hour = isset( $options['schedule_hour'] ) ? intval( $options['schedule_hour'] ) : 2;
		$minute = isset( $options['schedule_minute'] ) ? intval( $options['schedule_minute'] ) : 0;

		$next_time = $this->get_next_daily_timestamp( $hour, $minute, $schedule );
		wp_clear_scheduled_hook( self::HOOK_NAME );
		wp_schedule_event( $next_time, $schedule, self::HOOK_NAME );

		if ( is_wp_error( $result ) ) {
			error_log( 'Sync to Google Drive failed: ' . $result->get_error_message() );
		} else {
			error_log( sprintf(
				'Sync to Google Drive completed: uploaded=%d, skipped=%d, errors=%d',
				$result['uploaded'],
				$result['skipped'],
				$result['errors']
			) );
		}
	}
}

new BackupToGoogleDriveCron();