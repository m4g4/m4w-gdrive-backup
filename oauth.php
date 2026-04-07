<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupToGoogleDriveOAuth {
	const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	private $client_id;
	private $client_secret;
	private $redirect_uri;

	public function __construct( $client_id, $client_secret, $redirect_uri = null ) {
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$site_url = home_url();
		$site_url = untrailingslashit( $site_url );
		$this->redirect_uri  = $redirect_uri ? $redirect_uri : $site_url;
	}

	public function get_auth_url() {
		$state = wp_generate_password( 24, false );

		$params = array(
			'client_id'    => $this->client_id,
			'redirect_uri' => $this->redirect_uri,
			'response_type' => 'code',
			'scope'         => 'https://www.googleapis.com/auth/drive.file',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		);

		return self::AUTH_URL . '?' . http_build_query( $params );
	}

	public function get_redirect_uri() {
		return $this->redirect_uri;
	}

	public function exchange_code_for_tokens( $code ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'body' => array(
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $this->redirect_uri,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			$error_data = json_decode( $body, true );
			$error_msg = isset( $error_data['error_description'] ) 
				? $error_data['error_description'] 
				: ( isset( $error_data['error'] ) ? $error_data['error'] : 'Failed to exchange code for tokens' );
			return new WP_Error( 'token_exchange_error', $error_msg, $error_data );
		}

		return json_decode( $body, true );
	}
}
