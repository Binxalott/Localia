<?php
/**
 * Verifies HMAC-signed requests from the desktop agent.
 *
 * Request signature:  hex(HMAC_SHA256(secret, "v1\n" . timestamp . "\n" . nonce . "\n" . rawBody))
 * Response signature: hex(HMAC_SHA256(secret, "v1-resp\n" . requestNonce . "\n" . rawResponseBody))
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_Auth {

	const TOLERANCE = 300;

	/**
	 * @return array|WP_Error ['agent_id' => ..., 'secret' => ..., 'nonce' => ...]
	 */
	public static function verify( WP_REST_Request $request ) {
		$agent_id  = (string) $request->get_header( 'x_localia_agent' );
		$ts        = (string) $request->get_header( 'x_localia_timestamp' );
		$nonce     = (string) $request->get_header( 'x_localia_nonce' );
		$signature = strtolower( (string) $request->get_header( 'x_localia_signature' ) );

		if ( '' === $agent_id || '' === $ts || '' === $nonce || '' === $signature ) {
			return self::error( 401, 'missing_auth_headers' );
		}
		$settings = Localia_Settings::all();
		if ( ! hash_equals( (string) $settings['agent_id'], $agent_id ) ) {
			return self::error( 401, 'unknown_agent' );
		}
		if ( strlen( (string) $settings['secret'] ) < 16 ) {
			return self::error( 403, 'agent_secret_not_configured' );
		}
		if ( ! preg_match( '/^\d{9,12}$/', $ts ) ) {
			return self::error( 401, 'bad_timestamp' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return self::error( 401, 'bad_nonce' );
		}
		if ( abs( time() - (int) $ts ) > self::TOLERANCE ) {
			return self::error( 401, 'clock_skew', 'The PC clock differs from the server clock by more than 5 minutes.' );
		}
		$expected = hash_hmac( 'sha256', "v1\n{$ts}\n{$nonce}\n" . $request->get_body(), (string) $settings['secret'] );
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::error( 401, 'bad_signature' );
		}
		if ( ! Localia_Queue::use_nonce( $agent_id, $nonce, (int) $ts, self::TOLERANCE * 2 + 60 ) ) {
			return self::error( 401, 'replayed_nonce' );
		}
		return array(
			'agent_id' => $agent_id,
			'secret'   => (string) $settings['secret'],
			'nonce'    => $nonce,
		);
	}

	public static function sign_response( $secret, $nonce, $raw ) {
		return hash_hmac( 'sha256', "v1-resp\n{$nonce}\n{$raw}", $secret );
	}

	private static function error( $status, $code, $message = '' ) {
		return new WP_Error( $code, '' !== $message ? $message : $code, array( 'status' => $status ) );
	}
}
