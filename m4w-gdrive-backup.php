<?php
/*
Plugin Name: M4W Google Drive Backup
Description: Syncs uploads folder to Google Drive automatically.
Version: 1.0
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$base = plugin_dir_path(__FILE__);
require_once $base . 'google-drive-client.php';
require_once $base . 'backup-manager.php';
require_once $base . 'cron.php';
require_once $base . 'oauth.php';
require_once $base . 'settings.php';
