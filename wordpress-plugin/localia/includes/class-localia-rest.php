<?php
/**
 * REST routes:
 *   POST /wp-json/localia/v1/agent                 desktop agent (HMAC signed, see docs/PROTOCOL.md)
 *   POST /wp-json/localia/v1/editor/generate       "Generate with my AI" box (logged-in editors)
 *   GET  /wp-json/localia/v1/editor/job/<id>       poll the result of the above
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_REST {

	const NS = 'localia/v1';

	/** Secret + nonce of the current agent request, used to sign the response. */
	private static $signing = null;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'cors_headers' ) );
		add_filter( 'rest_exposed_cors_headers', array( __CLASS__, 'exposed_headers' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_signed' ), 10, 3 );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/agent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'agent' ),
				// Authenticated by the HMAC signature inside the callback, not by a WordPress login.
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/editor/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'editor_generate' ),
				'permission_callback' => function ( WP_REST_Request $r ) {
					$post_id = (int) $r->get_param( 'post_id' );
					return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'prompt'          => array(
						'type'     => 'string',
						'required' => true,
					),
					'post_id'         => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'context_title'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'context_content' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/editor/job/(?P<id>job_[a-z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'editor_job' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	public static function cors_headers( $headers ) {
		return array_merge( $headers, array( 'X-Localia-Agent', 'X-Localia-Timestamp', 'X-Localia-Nonce', 'X-Localia-Signature' ) );
	}

	public static function exposed_headers( $headers ) {
		$headers[] = 'X-Localia-Server-Signature';
		return $headers;
	}

	private static function fail( $status, $code, $message = '' ) {
		return new WP_REST_Response(
			array(
				'ok'          => false,
				'error'       => $code,
				'message'     => '' !== $message ? $message : $code,
				'server_time' => time(),
			),
			$status
		);
	}

	// ------------------------------------------------------------------ agent

	public static function agent( WP_REST_Request $request ) {
		$auth = Localia_Auth::verify( $request );
		if ( is_wp_error( $auth ) ) {
			$data = $auth->get_error_data();
			return self::fail( isset( $data['status'] ) ? $data['status'] : 401, $auth->get_error_code(), $auth->get_error_message() );
		}
		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return self::fail( 400, 'invalid_json' );
		}

		$action = isset( $body['action'] ) ? $body['action'] : '';
		switch ( $action ) {
			case 'ping':
				Localia_Settings::touch_agent( isset( $body['agent'] ) ? $body['agent'] : null );
				$out = array(
					'ok'      => true,
					'pending' => Localia_Queue::pending(),
				);
				break;

			case 'poll':
				Localia_Settings::touch_agent( isset( $body['agent'] ) ? $body['agent'] : null );
				Localia_Scheduler::tick_if_stale();
				$job = Localia_Queue::claim( $auth['agent_id'] );
				$out = array(
					'ok'      => true,
					'job'     => $job ? Localia_Queue::to_agent( $job ) : null,
					'pending' => Localia_Queue::pending(),
				);
				break;

			case 'result':
				$r = Localia_Queue::complete( $auth['agent_id'], $body );
				if ( is_wp_error( $r ) ) {
					$data = $r->get_error_data();
					return self::fail( $data['status'], $r->get_error_code() );
				}
				if ( isset( $r['final'] ) ) {
					Localia_Actions::handle_finished( $r['final'] );
				}
				$out = array(
					'ok'       => true,
					'accepted' => true,
					'requeued' => ! empty( $r['requeued'] ),
					'pending'  => Localia_Queue::pending(),
				);
				break;

			default:
				return self::fail( 400, 'unknown_action' );
		}

		$out['server_time'] = time();
		self::$signing      = $auth;
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Print signed agent responses ourselves, so the signature covers the exact bytes sent.
	 */
	public static function serve_signed( $served, $result, $request ) {
		if ( $served || null === self::$signing || '/' . self::NS . '/agent' !== $request->get_route() || 200 !== $result->get_status() ) {
			return $served;
		}
		$raw = wp_json_encode( $result->get_data() );
		if ( ! headers_sent() ) {
			header( 'X-Localia-Server-Signature: ' . Localia_Auth::sign_response( self::$signing['secret'], self::$signing['nonce'], $raw ) );
		}
		self::$signing = null;
		echo $raw; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body.
		return true;
	}

	// ------------------------------------------------------------------ editor

	public static function editor_generate( WP_REST_Request $request ) {
		$prompt = trim( sanitize_textarea_field( (string) $request->get_param( 'prompt' ) ) );
		if ( '' === $prompt ) {
			return new WP_Error( 'prompt_required', __( 'Tell the AI what to write.', 'localia' ), array( 'status' => 400 ) );
		}
		$job_id = Localia_Queue::enqueue(
			Localia_Actions::build_editor_job(
				$prompt,
				(int) $request->get_param( 'post_id' ),
				sanitize_text_field( (string) $request->get_param( 'context_title' ) ),
				wp_kses_post( (string) $request->get_param( 'context_content' ) )
			)
		);
		$status = Localia_Settings::agent_status();
		return array(
			'id'           => $job_id,
			'agent_online' => $status['online'],
		);
	}

	public static function editor_job( WP_REST_Request $request ) {
		$job = Localia_Queue::get( (string) $request['id'] );
		if ( ! $job || ! isset( $job['meta']['source'] ) || 'editor' !== $job['meta']['source'] ) {
			return new WP_Error( 'not_found', __( 'Not found.', 'localia' ), array( 'status' => 404 ) );
		}
		if ( get_current_user_id() !== (int) $job['meta']['user_id'] && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Not allowed.', 'localia' ), array( 'status' => 403 ) );
		}
		$status = Localia_Settings::agent_status();
		$out    = array(
			'status'       => $job['status'],
			'agent_online' => $status['online'],
			'html'         => '',
			'error'        => (string) $job['error'],
		);
		if ( 'done' === $job['status'] ) {
			$out['html'] = Localia_Content::to_html( $job['response'] );
		}
		return $out;
	}
}
