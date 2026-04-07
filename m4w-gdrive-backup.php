<?php
/*
Plugin Name: M4W Google Drive Backup
Description: Syncs uploads folder to Google Drive automatically.
Version: 1.0
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once './google-drive-client.php';
require_once './bckup-manager.php';
require_once './cron.php';
require_once './oauth.php';
require_once './settings.php';
