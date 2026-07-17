<?php
/**
 * Just WP GCS Client
 * Handles OAuth2 service account token requests and Google Cloud Storage API calls.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Just_WP_GCS_Client {

	/**
	 * Retrieve cached OAuth token or request a new one using Service Account JSON credentials.
	 *
	 * @return string|WP_Error Access token or WP_Error on failure.
	 */
	public function get_oauth_token() {
		$token = get_transient( 'just_wp_gcs_oauth_token' );
		if ( $token ) {
			return $token;
		}

		$sa_json = get_option( 'just_wp_gcs_service_account' );
		if ( empty( $sa_json ) ) {
			return new WP_Error( 'no_credentials', __( 'No Google Service Account JSON provided.', 'just-wp-gcs' ) );
		}

		$credentials = json_decode( $sa_json, true );
		if ( ! is_array( $credentials ) || empty( $credentials['private_key'] ) || empty( $credentials['client_email'] ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Invalid Service Account JSON format.', 'just-wp-gcs' ) );
		}

		$client_email = $credentials['client_email'];
		$private_key  = $credentials['private_key'];

		// Create JWT Header
		$header = $this->base64url_encode( json_encode( array(
			'alg' => 'RS256',
			'typ' => 'JWT'
		) ) );

		// Create JWT Claim
		$now   = time();
		$claim = $this->base64url_encode( json_encode( array(
			'iss'   => $client_email,
			'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'exp'   => $now + 3600,
			'iat'   => $now
		) ) );

		$signature_input = $header . '.' . $claim;
		$signature       = '';

		// Sign JWT using OpenSSL RS256
		if ( ! openssl_sign( $signature_input, $signature, $private_key, 'SHA256' ) ) {
			return new WP_Error( 'signing_failed', __( 'Failed to sign JWT assertion using OpenSSL.', 'just-wp-gcs' ) );
		}

		$jwt = $signature_input . '.' . $this->base64url_encode( $signature );

		// Request Access Token from Google OAuth2
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt
			)
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$error_desc = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : 'Unknown OAuth error' );
			return new WP_Error( 'oauth_error', sprintf( __( 'OAuth token exchange failed: %s', 'just-wp-gcs' ), $error_desc ) );
		}

		$token      = $body['access_token'];
		$expires_in = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) : 3600;

		// Cache token (expire 5 minutes early for safety)
		set_transient( 'just_wp_gcs_oauth_token', $token, $expires_in - 300 );

		return $token;
	}

	/**
	 * Upload a local file to GCS using multipart/related upload.
	 *
	 * @param string $local_path   Absolute path to the local file.
	 * @param string $gcs_path     Destination path inside the GCS bucket.
	 * @param string $content_type Optional mime content type.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function upload_file( $local_path, $gcs_path, $content_type = '' ) {
		if ( ! file_exists( $local_path ) ) {
			return new WP_Error( 'file_not_found', sprintf( __( 'Local file not found: %s', 'just-wp-gcs' ), $local_path ) );
		}

		$token = $this->get_oauth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$bucket = get_option( 'just_wp_gcs_bucket' );
		if ( empty( $bucket ) ) {
			return new WP_Error( 'no_bucket', __( 'GCS bucket name is not configured.', 'just-wp-gcs' ) );
		}

		if ( empty( $content_type ) ) {
			if ( function_exists( 'mime_content_type' ) ) {
				$content_type = mime_content_type( $local_path );
			}
			if ( ! $content_type ) {
				$content_type = 'application/octet-stream';
			}
		}

		$file_content = file_get_contents( $local_path );
		if ( $file_content === false ) {
			return new WP_Error( 'read_error', sprintf( __( 'Failed to read local file: %s', 'just-wp-gcs' ), $local_path ) );
		}

		$set_public_acl = get_option( 'just_wp_gcs_set_public_acl', '0' );

		// Set up multipart/related body
		$boundary = 'wp_gcs_offload_' . md5( time() . $local_path );

		$metadata = array(
			'name'        => $gcs_path,
			'contentType' => $content_type,
		);

		$cache_control = get_option( 'just_wp_gcs_cache_control', 'public, max-age=31536000' );
		if ( ! empty( $cache_control ) ) {
			$metadata['cacheControl'] = $cache_control;
		}

		$metadata_json = json_encode( $metadata );

		$body  = "--" . $boundary . "\r\n";
		$body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
		$body .= $metadata_json . "\r\n";
		$body .= "--" . $boundary . "\r\n";
		$body .= "Content-Type: " . $content_type . "\r\n\r\n";
		$body .= $file_content . "\r\n";
		$body .= "--" . $boundary . "--\r\n";

		// Build Upload API URL
		$url = sprintf(
			'https://storage.googleapis.com/upload/storage/v1/b/%s/o?uploadType=multipart',
			urlencode( $bucket )
		);

		if ( $set_public_acl === '1' ) {
			$url .= '&predefinedAcl=publicRead';
		}

		$args = array(
			'method'  => 'POST',
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'multipart/related; boundary=' . $boundary,
			),
			'body'    => $body,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$res_body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'upload_failed', sprintf( __( 'Upload to GCS failed with status %d. Response: %s', 'just-wp-gcs' ), $code, $res_body ) );
		}

		return true;
	}

	/**
	 * Delete a file from GCS bucket.
	 *
	 * @param string $gcs_path Path of the object in the GCS bucket.
	 * @return bool|WP_Error True on success (or 404), WP_Error on failure.
	 */
	public function delete_file( $gcs_path ) {
		$token = $this->get_oauth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$bucket = get_option( 'just_wp_gcs_bucket' );
		if ( empty( $bucket ) ) {
			return new WP_Error( 'no_bucket', __( 'GCS bucket name is not configured.', 'just-wp-gcs' ) );
		}

		$url = sprintf(
			'https://storage.googleapis.com/storage/v1/b/%s/o/%s',
			urlencode( $bucket ),
			urlencode( $gcs_path )
		);

		$args = array(
			'method'  => 'DELETE',
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		// 200/204 are success; 404 is also fine (already deleted)
		if ( $code !== 204 && $code !== 200 && $code !== 404 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'delete_failed', sprintf( __( 'Failed to delete object from GCS. Response code: %d. Response: %s', 'just-wp-gcs' ), $code, $body ) );
		}

		return true;
	}

	/**
	 * Perform a connection test by uploading and deleting a test file.
	 *
	 * @return bool|WP_Error True if connection test passes, WP_Error otherwise.
	 */
	public function test_connection() {
		$token = $this->get_oauth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$bucket = get_option( 'just_wp_gcs_bucket' );
		if ( empty( $bucket ) ) {
			return new WP_Error( 'no_bucket', __( 'GCS bucket name is not configured.', 'just-wp-gcs' ) );
		}

		// Write a temporary test file
		$test_content = 'Just WP GCS Offload Connection Test: ' . current_time( 'mysql' );
		$temp_file    = wp_tempnam( 'gcs_test' );
		if ( ! $temp_file ) {
			return new WP_Error( 'temp_file_failed', __( 'Failed to create local temp file.', 'just-wp-gcs' ) );
		}

		file_put_contents( $temp_file, $test_content );

		$gcs_path = 'just-wp-gcs-test-connection.txt';
		$upload   = $this->upload_file( $temp_file, $gcs_path, 'text/plain' );
		@unlink( $temp_file );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		// Delete the test file immediately
		$delete = $this->delete_file( $gcs_path );
		if ( is_wp_error( $delete ) ) {
			return new WP_Error( 'delete_failed', sprintf( __( 'Upload succeeded, but GCS deletion failed: %s', 'just-wp-gcs' ), $delete->get_error_message() ) );
		}

		return true;
	}

	/**
	 * Base64 URL encode helper.
	 */
	private function base64url_encode( $data ) {
		return str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $data ) );
	}
}
