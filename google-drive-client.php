<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupToGoogleDriveClient {
	private $client_id;
	private $client_secret;
	private $refresh_token;
	private $access_token = null;

	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
	const API_URL = 'https://www.googleapis.com/drive/v3';
	const CHUNK_SIZE = 5 * 1024 * 1024;

	public function __construct( $client_id, $client_secret, $refresh_token ) {
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->refresh_token = $refresh_token;
	}

	private function get_access_token( $force_refresh = false ) {
		if ( ! $force_refresh && $this->access_token !== null ) {
			return $this->access_token;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'body' => array(
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'refresh_token' => $this->refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['access_token'] ) ) {
			$this->access_token = $data['access_token'];
			return $data['access_token'];
		}

		return new WP_Error( 'token_error', 'Failed to get access token', $data );
	}

	private function handle_response( $code, $body ) {
		if ( 200 !== $code && 201 !== $code && 204 !== $code ) {
			$data = json_decode( $body, true );
			$msg = 'API request failed (code: ' . $code . ')';
			if ( is_array( $data ) && isset( $data['error'] ) ) {
				$msg .= ' - ' . ( is_string( $data['error'] ) ? $data['error'] : json_encode( $data['error'] ) );
			} elseif ( is_string( $body ) && strlen( $body ) < 200 ) {
				$msg .= ' - ' . $body;
			}
			$error_data = array_merge( array( 'code' => $code ), is_array( $data ) ? $data : array() );
			return new WP_Error( 'api_error', $msg, $error_data );
		}

		if ( 204 === $code ) {
			return true;
		}

		if ( '' === $body || null === $body ) {
			return true;
		}

		return json_decode( $body, true );
	}

	public function upload_file( $file_path, $relative_path, $folder_id = null ) {
		$file_size = filesize( $file_path );

		$path_parts = explode( '/', $relative_path );
		$file_name_only = array_pop( $path_parts );
		$folder_path = implode( '/', $path_parts );

		if ( empty( $file_name_only ) ) {
			return new WP_Error( 'invalid_filename', 'Could not extract filename from: ' . $relative_path );
		}

		$parent_id = $folder_id;
		if ( $folder_path ) {
			$parent_id = $this->ensure_folder_path( $folder_path, $folder_id );
			if ( is_wp_error( $parent_id ) ) {
				return $parent_id;
			}
		}

		$metadata = array(
			'name' => $file_name_only,
		);

		if ( $parent_id ) {
			$metadata['parents'] = array( $parent_id );
		}

		if ( $file_size > self::CHUNK_SIZE ) {
			return $this->resumable_upload( $file_path, $metadata, $file_size );
		}

		$file_content = file_get_contents( $file_path );

		if ( false === $file_content ) {
			return new WP_Error( 'file_read_error', 'Could not read file: ' . $file_path );
		}

		$boundary   = wp_generate_password( 24 );
		$delimiter  = '--' . $boundary;
		$body_data  = $delimiter . "\r\n";
		$body_data .= 'Content-Type: application/json; charset=UTF-8' . "\r\n\r\n";
		$body_data .= json_encode( $metadata ) . "\r\n";
		$body_data .= $delimiter . "\r\n";
		$body_data .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$body_data .= $file_content . "\r\n";
		$body_data .= $delimiter . '--';

		$response = $this->request_with_retry( 'post', self::UPLOAD_URL . '?uploadType=multipart', array(
			'headers' => array(
				'Content-Type' => 'multipart/related; boundary=' . $boundary,
				'Content-Length' => strlen( $body_data ),
			),
			'body'    => $body_data,
			'timeout' => 300,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['id'] ) ? $response['id'] : true;
	}

	private function resumable_upload( $file_path, $metadata, $file_size ) {
		$access_token = $this->get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$initiate_url = self::UPLOAD_URL . '?uploadType=resumable';
		$initiate_body = wp_json_encode( $metadata );

		$response = wp_remote_post( $initiate_url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'   => 'application/json',
				'Content-Length' => strlen( $initiate_body ),
				'X-Upload-Content-Length' => $file_size,
			),
			'body' => $initiate_body,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! $location ) {
			return new WP_Error( 'upload_init_failed', 'Could not initiate resumable upload' );
		}

		$file = fopen( $file_path, 'rb' );
		if ( ! $file ) {
			return new WP_Error( 'file_read_error', 'Could not open file: ' . $file_path );
		}

		$chunk_size = self::CHUNK_SIZE;
		$start = 0;

		while ( ! feof( $file ) ) {
			fseek( $file, $start );
			$chunk = fread( $file, $chunk_size );
			$end = $start + strlen( $chunk ) - 1;

			$response = wp_remote_request( $location, array(
				'method'  => 'PUT',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Length' => strlen( $chunk ),
					'Content-Range' => 'bytes ' . $start . '-' . $end . '/' . $file_size,
				),
				'body' => $chunk,
				'timeout' => 300,
			) );

			$code = wp_remote_retrieve_response_code( $response );

			if ( 200 === $code || 201 === $code ) {
				fclose( $file );
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				return isset( $data['id'] ) ? $data['id'] : true;
			}

			if ( 308 === $code ) {
				$range = wp_remote_retrieve_header( $response, 'range' );
				if ( $range && preg_match( '/bytes=\d+-(\d+)/', $range, $matches ) ) {
					$start = intval( $matches[1] ) + 1;
				} else {
					$start += $chunk_size;
				}
			} else {
				fclose( $file );
				return new WP_Error( 'upload_failed', 'Resumable upload failed with code: ' . $code );
			}
		}

		fclose( $file );
		return new WP_Error( 'upload_incomplete', 'Resumable upload did not complete' );
	}

	public function ensure_folder_path( $folder_path, $root_folder_id = null ) {
		$folders = explode( '/', trim( $folder_path, '/' ) );
		$current_parent = $root_folder_id;

		foreach ( $folders as $folder_name ) {
			if ( empty( $folder_name ) ) {
				continue;
			}

			$folder_id = $this->find_or_create_folder( $folder_name, $current_parent );
			if ( is_wp_error( $folder_id ) ) {
				return $folder_id;
			}
			$current_parent = $folder_id;
		}

		return $current_parent;
	}

	private function find_or_create_folder( $folder_name, $parent_id = null ) {
		$escaped_name = str_replace( "'", "\\'", $folder_name );

		if ( $parent_id ) {
			$escaped_parent = str_replace( "'", "\\'", $parent_id );
			$query = "'{$escaped_parent}' in parents and name = '{$escaped_name}' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
		} else {
			$query = "name = '{$escaped_name}' and mimeType = 'application/vnd.google-apps.folder' and 'root' in parents and trashed = false";
		}

		$response = $this->request_with_retry( 'get', self::API_URL . '/files?q=' . urlencode( $query ) );

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['code'] ) && 404 === $error_data['code'] ) {
				return false;
			}
			return $response;
		}

		if ( ! empty( $response['files'] ) ) {
			return $response['files'][0]['id'];
		}

		return $this->create_folder( $folder_name, $parent_id );
	}

	private function create_folder( $folder_name, $parent_id = null ) {
		$metadata = array(
			'name'     => $folder_name,
			'mimeType' => 'application/vnd.google-apps.folder',
		);

		if ( $parent_id ) {
			$metadata['parents'] = array( $parent_id );
		}

		$response = $this->request_with_retry( 'post', self::API_URL . '/files', array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $metadata ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['id'] ) ? $response['id'] : new WP_Error( 'folder_create_failed', 'Failed to create folder' );
	}

	public function file_exists_in_folder( $file_name, $folder_path, $root_folder_id = null ) {
		$escaped_name = str_replace( "'", "\\'", $file_name );

		if ( $folder_path && $root_folder_id ) {
			$parent_id = $this->ensure_folder_path( $folder_path, $root_folder_id );
			if ( is_wp_error( $parent_id ) ) {
				return $parent_id;
			}
			$escaped_parent = str_replace( "'", "\\'", $parent_id );
			$query = "'{$escaped_parent}' in parents and name = '{$escaped_name}' and trashed = false";
		} elseif ( $root_folder_id ) {
			$escaped_parent = str_replace( "'", "\\'", $root_folder_id );
			$query = "'{$escaped_parent}' in parents and name = '{$escaped_name}' and trashed = false";
		} else {
			$query = "'root' in parents and name = '{$escaped_name}' and trashed = false";
		}

		$url = self::API_URL . '/files?q=' . urlencode( $query );

		$response = $this->request_with_retry( 'get', $url );

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['code'] ) && 404 === $error_data['code'] ) {
				return false;
			}
			return $response;
		}

		return ! empty( $response['files'] ) ? $response['files'][0] : false;
	}

	public function file_exists( $file_name, $folder_id = null ) {
		$escaped_name = str_replace( "'", "\\'", $file_name );

		if ( $folder_id ) {
			$escaped_folder_id = str_replace( "'", "\\'", $folder_id );
			$query = "'{$escaped_folder_id}' in parents and name = '{$escaped_name}' and trashed = false";
		} else {
			$query = "'root' in parents and name = '{$escaped_name}' and trashed = false";
		}

		$url = self::API_URL . '/files?q=' . urlencode( $query );

		$response = $this->request_with_retry( 'get', $url );

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['code'] ) && 404 === $error_data['code'] ) {
				return false;
			}
			return $response;
		}

		return ! empty( $response['files'] ) ? $response['files'][0] : false;
	}

	public function test_connection() {
		$response = $this->request_with_retry( 'get', self::API_URL . '/about' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['user'] ) ? $response['user'] : true;
	}

	public function delete_file( $file_id ) {
		$response = $this->request_with_retry( 'delete', self::API_URL . '/files/' . $file_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	public function trash_file( $file_id ) {
		$response = $this->request_with_retry( 'patch', self::API_URL . '/files/' . $file_id, array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body' => wp_json_encode( array( 'trashed' => true ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	public function list_all_files( $folder_id = null, $page_token = null ) {
		$files = array();
		$params = array(
			'q'         => "trashed = false and mimeType != 'application/vnd.google-apps.folder'",
			'fields'    => 'files(id,name,mimeType,parents),nextPageToken',
			'pageSize'  => 100,
		);

		if ( $folder_id ) {
			$escaped_folder_id = str_replace( "'", "\\'", $folder_id );
			$params['q'] = "'{$escaped_folder_id}' in parents and trashed = false and mimeType != 'application/vnd.google-apps.folder'";
		}

		if ( $page_token ) {
			$params['pageToken'] = $page_token;
		}

		$url = self::API_URL . '/files?' . http_build_query( $params );

		do {
			if ( $page_token ) {
				$url = self::API_URL . '/files?' . http_build_query( array(
					'q'         => $params['q'],
					'fields'    => $params['fields'],
					'pageSize'  => $params['pageSize'],
					'pageToken' => $page_token,
				) );
			}

			$response = $this->request_with_retry( 'get', $url );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! empty( $response['files'] ) ) {
				$files = array_merge( $files, $response['files'] );
			}

			$page_token = isset( $response['nextPageToken'] ) ? $response['nextPageToken'] : null;

		} while ( $page_token );

		return $files;
	}

	public function list_files_in_folder_recursive( $folder_id = null, $base_path = '' ) {
		$all_files = array();
		$folders_to_process = array( array( 'id' => $folder_id, 'path' => $base_path ) );

		while ( ! empty( $folders_to_process ) ) {
			$current = array_shift( $folders_to_process );
			$current_folder_id = $current['id'];
			$current_path = $current['path'];
			$page_token = null;

			do {
				$params = array(
					'q'         => $current_folder_id
						? "'" . str_replace( "'", "\\'", $current_folder_id ) . "' in parents and trashed = false"
						: "'root' in parents and trashed = false",
					'fields'    => 'files(id,name,mimeType,parents),nextPageToken',
					'pageSize'  => 100,
				);

				if ( $page_token ) {
					$params['pageToken'] = $page_token;
				}

				$url = self::API_URL . '/files?' . http_build_query( $params );
				$response = $this->request_with_retry( 'get', $url );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				if ( ! empty( $response['files'] ) ) {
					foreach ( $response['files'] as $file ) {
						$file_path = $current_path ? $current_path . '/' . $file['name'] : $file['name'];

						if ( $file['mimeType'] === 'application/vnd.google-apps.folder' ) {
							$folders_to_process[] = array(
								'id'   => $file['id'],
								'path' => $file_path,
							);
						} else {
							$all_files[] = array(
								'id'   => $file['id'],
								'name' => $file['name'],
								'path' => $file_path,
							);
						}
					}
				}

				$page_token = isset( $response['nextPageToken'] ) ? $response['nextPageToken'] : null;

			} while ( $page_token );
		}

		return $all_files;
	}

	private function request_with_retry( $method, $url, $args = array() ) {
		$access_token = $this->get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$response = $this->do_http_request( $method, $url, $access_token, $args );

		if ( 401 === $response['code'] ) {
			$access_token = $this->get_access_token( true );
			if ( is_wp_error( $access_token ) ) {
				return $access_token;
			}
			$response = $this->do_http_request( $method, $url, $access_token, $args );
		}

		if ( 401 === $response['code'] ) {
			return new WP_Error( 'auth_failed', 'Authentication failed after token refresh' );
		}

		return $this->handle_response( $response['code'], $response['body'] );
	}

	private function do_http_request( $method, $url, $access_token, $args ) {
		$args['headers']['Authorization'] = 'Bearer ' . $access_token;

		if ( 'get' === $method ) {
			$response = wp_remote_get( $url, $args );
		} elseif ( 'post' === $method ) {
			$response = wp_remote_post( $url, $args );
		} else {
			$args['method'] = strtoupper( $method );
			$response = wp_remote_request( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return array( 'code' => 0, 'body' => $response->get_error_message() );
		}

		return array(
			'code' => wp_remote_retrieve_response_code( $response ),
			'body' => wp_remote_retrieve_body( $response ),
		);
	}
}
