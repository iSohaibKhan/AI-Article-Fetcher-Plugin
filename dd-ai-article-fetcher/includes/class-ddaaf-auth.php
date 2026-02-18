<?php
/**
 * Auth helper – Bearer token validation for REST endpoints
 *
 * File: includes/class-ddaaf-auth.php
 *
 * Usage in REST route:
 *  'permission_callback' => [ 'DDAAF_Auth', 'rest_permission' ]
 *
 * Logic:
 * 1. Read "Authorization: Bearer XXXXX" header.
 * 2. Compare with token saved in WP option `ddaaf_api_token`.
 * 3. Allow if match, else return WP_Error (401).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DDAAF_Auth {

	/** Option key where token is stored */
	const OPTION_TOKEN = 'ddaaf_api_token';

	/**
	 * Permission callback for REST routes.
	 * Accepts either a WP_REST_Request or raw header string (for flexibility).
	 *
	 * @param  WP_REST_Request|string|null $request_or_header
	 * @return bool|WP_Error
	 */
	public static function rest_permission( $request_or_header = null ) {

		// Super admins / admins in wp-admin can always pass (optional)
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$header = '';
		if ( $request_or_header instanceof WP_REST_Request ) {
			$header = $request_or_header->get_header( 'authorization' );
		} elseif ( is_string( $request_or_header ) ) {
			$header = $request_or_header;
		} else {
			// Try raw header as last resort
			$header = self::get_server_auth_header();
		}

		$sent_token = self::extract_bearer( $header );
		$stored     = trim( get_option( self::OPTION_TOKEN, '' ) );

		if ( empty( $stored ) ) {
			// If no token stored, block by default (safer)
			return new WP_Error(
				'ddaaf_auth_missing_token',
				'Bearer token not configured on this site.',
				[ 'status' => 401 ]
			);
		}

		if ( ! hash_equals( $stored, $sent_token ) ) {
			return new WP_Error(
				'ddaaf_auth_failed',
				'Invalid or missing Bearer token.',
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Extract Bearer token from an Authorization header.
	 *
	 * @param  string $header
	 * @return string
	 */
	public static function extract_bearer( $header ) {
		if ( ! $header ) {
			return '';
		}
		// Typical format: "Bearer abc123"
		if ( preg_match( '/Bearer\\s+(.*)$/i', $header, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Fallback: some servers put the header in different places.
	 *
	 * @return string
	 */
	private static function get_server_auth_header() {
		$keys = [
			'HTTP_AUTHORIZATION',
			'REDIRECT_HTTP_AUTHORIZATION',
			'Authorization',
		];
		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				return $_SERVER[ $k ];
			}
		}
		return '';
	}
}
