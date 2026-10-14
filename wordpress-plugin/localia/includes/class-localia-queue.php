<?php
/**
 * The job queue the desktop agent pulls from. Same semantics as the standalone
 * Localia servers (see docs/PROTOCOL.md in the Localia repository).
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables; caching would break the queue's locking.

class Localia_Queue {

	const FINAL_STATUSES = array( 'done', 'error', 'cancelled' );
	const JSON_FIELDS    = array( 'messages', 'options', 'meta', 'result_meta' );
	const INT_FIELDS     = array( 'priority', 'attempts', 'max_attempts', 'not_before', 'lease_until', 'created_at', 'leased_at', 'completed_at' );

	private static function t() {
		return Localia_DB::table( 'jobs' );
	}

	/** Time-sortable id. */
	public static function new_id() {
		$ms = (int) floor( microtime( true ) * 1000 );
		return 'job_' . str_pad( base_convert( (string) $ms, 10, 36 ), 9, '0', STR_PAD_LEFT ) . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Queue a job.
	 *
	 * @param array $input prompt, system, messages, model, options, meta, priority, max_attempts.
	 * @return string Job id.
	 */
	public static function enqueue( array $input ) {
		global $wpdb;
		$id = self::new_id();
		$wpdb->insert(
			self::t(),
			array(
				'id'            => $id,
				'status'        => 'queued',
				'priority'      => isset( $input['priority'] ) ? (int) $input['priority'] : 0,
				'prompt'        => isset( $input['prompt'] ) ? (string) $input['prompt'] : null,
				'system_prompt' => isset( $input['system'] ) && '' !== $input['system'] ? (string) $input['system'] : null,
				'messages'      => isset( $input['messages'] ) ? wp_json_encode( $input['messages'] ) : null,
				'model'         => ! empty( $input['model'] ) ? substr( (string) $input['model'], 0, 191 ) : null,
				'options'       => wp_json_encode( (object) ( isset( $input['options'] ) ? $input['options'] : array() ) ),
				'meta'          => wp_json_encode( isset( $input['meta'] ) ? $input['meta'] : array() ),
				'max_attempts'  => isset( $input['max_attempts'] ) ? max( 1, (int) $input['max_attempts'] ) : 2,
				'not_before'    => 0,
				'created_at'    => time(),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);
		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %s', self::t(), $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	public static function cancel( $id ) {
		global $wpdb;
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', completed_at = %d, lease_until = NULL WHERE id = %s AND status IN ('queued','leased')",
				self::t(),
				time(),
				$id
			)
		);
	}

	public static function is_active( $id ) {
		$job = $id ? self::get( $id ) : null;
		return $job && ! in_array( $job['status'], self::FINAL_STATUSES, true );
	}

	public static function counts() {
		global $wpdb;
		$counts = array_fill_keys( array( 'queued', 'leased', 'done', 'error', 'cancelled' ), 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', self::t() ), ARRAY_A ) as $r ) {
			$counts[ $r['status'] ] = (int) $r['n'];
		}
		return $counts;
	}

	/** Jobs an agent could pick up right now. */
	public static function pending() {
		global $wpdb;
		$t = time();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ((status = 'queued' AND not_before <= %d) OR (status = 'leased' AND lease_until < %d AND attempts < max_attempts))", self::t(), $t, $t ) );
	}

	/** Atomically lease the next job. */
	public static function claim( $agent_id ) {
		global $wpdb;
		$t     = time();
		$token = bin2hex( random_bytes( 16 ) );
		$until = $t + 60 * max( 5, (int) Localia_Settings::get( 'lease_minutes' ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE ((status = 'queued' AND not_before <= %d) OR (status = 'leased' AND lease_until < %d AND attempts < max_attempts)) ORDER BY priority DESC, id ASC LIMIT 1", self::t(), $t, $t ) );
			if ( ! $id ) {
				return null;
			}
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'leased', agent_id = %s, lease_token = %s, lease_until = %d, leased_at = %d, attempts = attempts + 1 WHERE id = %s AND ((status = 'queued' AND not_before <= %d) OR (status = 'leased' AND lease_until < %d AND attempts < max_attempts))",
					self::t(),
					$agent_id,
					$token,
					$until,
					$t,
					$id,
					$t,
					$t
				)
			);
			if ( 1 === (int) $updated ) {
				return self::get( $id );
			}
		}
		return null;
	}

	/**
	 * Record an agent's result.
	 *
	 * @return array|WP_Error ['final' => job] when finished, ['requeued' => true] when it will be retried.
	 */
	public static function complete( $agent_id, array $body ) {
		global $wpdb;
		if ( ! isset( $body['job_id'], $body['lease_token'] ) || ! is_string( $body['job_id'] ) || ! is_string( $body['lease_token'] ) ) {
			return new WP_Error( 'job_id_and_lease_token_required', '', array( 'status' => 400 ) );
		}
		$job = self::get( $body['job_id'] );
		if ( ! $job ) {
			return new WP_Error( 'job_not_found', '', array( 'status' => 404 ) );
		}
		if ( $job['agent_id'] !== $agent_id || ! hash_equals( (string) $job['lease_token'], $body['lease_token'] ) ) {
			return new WP_Error( 'lease_mismatch', '', array( 'status' => 409 ) );
		}
		if ( 'done' === $job['status'] || 'cancelled' === $job['status'] ) {
			return new WP_Error( 'job_already_' . $job['status'], '', array( 'status' => 409 ) );
		}

		$t      = time();
		$result = wp_json_encode(
			array(
				'model'       => isset( $body['model'] ) ? sanitize_text_field( (string) $body['model'] ) : null,
				'duration_ms' => isset( $body['duration_ms'] ) && is_numeric( $body['duration_ms'] ) ? (int) $body['duration_ms'] : null,
				'usage'       => isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : null,
			)
		);

		if ( isset( $body['status'] ) && 'error' === $body['status'] ) {
			$error = substr( sanitize_text_field( isset( $body['error'] ) ? (string) $body['error'] : 'agent_error' ), 0, 2000 );
			// 'rejected': the PC's prompt guard refused the job, so retrying is pointless.
			if ( empty( $body['rejected'] ) && $job['attempts'] < $job['max_attempts'] ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status = 'queued', not_before = %d, error = %s, lease_until = NULL WHERE id = %s AND lease_token = %s AND status = 'leased'",
						self::t(),
						$t + 60,
						$error,
						$job['id'],
						$body['lease_token']
					)
				);
				return array( 'requeued' => true );
			}
			$status   = 'error';
			$response = null;
		} else {
			if ( ! isset( $body['response'] ) || ! is_string( $body['response'] ) ) {
				return new WP_Error( 'response_required', '', array( 'status' => 400 ) );
			}
			$status   = 'done';
			$error    = null;
			$response = $body['response'];
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, error = %s, response = %s, result_meta = %s, completed_at = %d, lease_until = NULL WHERE id = %s AND lease_token = %s AND status NOT IN ('done','cancelled')",
				self::t(),
				$status,
				$error,
				$response,
				$result,
				$t,
				$job['id'],
				$body['lease_token']
			)
		);
		return array( 'final' => self::get( $job['id'] ) );
	}

	/** Record a nonce; false if it was already used (replay). */
	public static function use_nonce( $agent_id, $nonce, $ts, $ttl ) {
		global $wpdb;
		$table = Localia_DB::table( 'nonces' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ts < %d', $table, time() - $ttl ) );
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (agent_id, nonce, ts) VALUES (%s, %s, %d)', $table, $agent_id, $nonce, $ts ) );
	}

	/**
	 * Expire dead leases and purge old finished jobs.
	 *
	 * @return array Jobs that just failed for good (so their task can be updated).
	 */
	public static function maintenance() {
		global $wpdb;
		$t       = time();
		$expired = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'leased' AND lease_until < %d AND attempts >= max_attempts", self::t(), $t ) );
		$failed  = array();
		foreach ( $expired as $id ) {
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = 'error', error = 'The home PC took too long or stopped responding.', completed_at = %d, lease_until = NULL WHERE id = %s AND status = 'leased'",
					self::t(),
					$t,
					$id
				)
			);
			if ( $changed ) {
				$failed[] = self::get( $id );
			}
		}
		$days = max( 1, (int) Localia_Settings::get( 'log_days' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status IN ('done','error','cancelled') AND COALESCE(completed_at, created_at) < %d", self::t(), $t - $days * DAY_IN_SECONDS ) );
		return $failed;
	}

	private static function decode( array $row ) {
		foreach ( self::JSON_FIELDS as $f ) {
			$row[ $f ] = null === $row[ $f ] ? null : json_decode( $row[ $f ], true );
		}
		foreach ( self::INT_FIELDS as $f ) {
			if ( null !== $row[ $f ] ) {
				$row[ $f ] = (int) $row[ $f ];
			}
		}
		if ( ! is_array( $row['meta'] ) ) {
			$row['meta'] = array();
		}
		return $row;
	}

	/** Shape handed to the desktop agent. */
	public static function to_agent( array $job ) {
		return array(
			'id'          => $job['id'],
			'lease_token' => $job['lease_token'],
			'lease_until' => $job['lease_until'],
			'prompt'      => $job['prompt'],
			'system'      => $job['system_prompt'],
			'messages'    => $job['messages'],
			'model'       => $job['model'],
			'options'     => ( is_array( $job['options'] ) && $job['options'] ) ? $job['options'] : new stdClass(),
			'attempt'     => $job['attempts'],
		);
	}
}
