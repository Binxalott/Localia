<?php
/**
 * Plugin settings (one option, autoloaded).
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_Settings {

	const OPTION       = 'localia_settings';
	const AGENT_OPTION = 'localia_agent_status';

	public static function defaults() {
		return array(
			'agent_id'             => 'home-pc',
			'secret'               => '',
			'pair_id'              => '',
			'site_instructions'    => '',
			'default_status'       => 'draft',
			'instruction_language' => '',
			'language_only'        => false,
			'default_author'       => 0,
			'lease_minutes'        => 30,
			'log_days'             => 30,
			'delete_on_uninstall'  => false,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		if ( '' === $all['instruction_language'] ) {
			$all['instruction_language'] = Localia_Lang::default_code();
		}
		return $all;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all );
		return $all;
	}

	/** Create the agent secret on first use. */
	public static function ensure_secret() {
		if ( '' === (string) self::get( 'secret' ) ) {
			self::regenerate_secret();
		}
	}

	/** New secret + pairing id. Previously downloaded agents stop working. */
	public static function regenerate_secret() {
		self::update(
			array(
				'secret'  => wp_generate_password( 48, false, false ),
				'pair_id' => wp_generate_password( 12, false, false ),
			)
		);
	}

	/** The URL the desktop agent talks to. */
	public static function endpoint_url() {
		return rest_url( 'localia/v1/agent' );
	}

	/** Connection details handed to the desktop agent (contains the secret!). */
	public static function pairing() {
		self::ensure_secret();
		return array(
			'id'           => (string) self::get( 'pair_id' ),
			'site'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'serverUrl'    => self::endpoint_url(),
			'agentId'      => (string) self::get( 'agent_id' ),
			'secret'       => (string) self::get( 'secret' ),
			// Starting values for the agent's prompt guard; the PC's own setting wins after that.
			'language'     => Localia_Lang::current(),
			'languageOnly' => (bool) self::get( 'language_only' ),
		);
	}

	/** Copy/paste version of pairing(). */
	public static function pairing_code() {
		return 'LOCALIA1:' . base64_encode( wp_json_encode( self::pairing() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding, not obfuscation.
	}

	// ------------------------------------------------------------------ agent status

	public static function touch_agent( $info ) {
		$clean = array();
		if ( is_array( $info ) ) {
			foreach ( array( 'version', 'provider', 'model', 'schedule' ) as $k ) {
				if ( isset( $info[ $k ] ) && is_scalar( $info[ $k ] ) ) {
					$clean[ $k ] = sanitize_text_field( (string) $info[ $k ] );
				}
			}
			if ( isset( $info['poll_interval'] ) && is_numeric( $info['poll_interval'] ) ) {
				$clean['poll_interval'] = max( 5, (int) $info['poll_interval'] );
			}
		}
		update_option(
			self::AGENT_OPTION,
			array(
				'last_seen' => time(),
				'info'      => $clean,
			),
			false
		);
	}

	public static function agent_status() {
		$s        = get_option( self::AGENT_OPTION, array() );
		$last     = isset( $s['last_seen'] ) ? (int) $s['last_seen'] : 0;
		$info     = isset( $s['info'] ) && is_array( $s['info'] ) ? $s['info'] : array();
		$interval = isset( $info['poll_interval'] ) ? (int) $info['poll_interval'] : 60;
		$online   = $last > 0 && ( time() - $last ) <= max( 180, 3 * $interval );
		return array(
			'last_seen' => $last,
			'online'    => $online,
			'info'      => $info,
		);
	}
}
