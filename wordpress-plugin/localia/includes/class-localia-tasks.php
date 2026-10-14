<?php
/**
 * Tasks: reusable recipes ("write a post about X") that run on a daily schedule.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table.

class Localia_Tasks {

	/** Task types. Add-ons can register more through the `localia_task_types` filter. */
	public static function types() {
		return apply_filters(
			'localia_task_types',
			array(
				'create_post'  => __( 'Write a new post', 'localia' ),
				'rewrite_post' => __( 'Rewrite an existing post', 'localia' ),
			)
		);
	}

	public static function default_config( $type ) {
		if ( 'rewrite_post' === $type ) {
			return array(
				'target'       => 'post',
				'post_id'      => 0,
				'category'     => 0,
				'skip_days'    => 30,
				'instructions' => __( 'Improve this post: make it clearer and more engaging, fix any mistakes, and keep the same meaning, facts and overall length.', 'localia' ),
				'save_mode'    => 'replace',
			);
		}
		return array(
			'prompt'        => __( 'Write a helpful, engaging blog post of about 600 words about: {topic}', 'localia' ),
			'topics'        => '',
			'topic_mode'    => 'sequence',
			'title'         => '',
			'post_status'   => Localia_Settings::get( 'default_status' ),
			'category'      => 0,
			'tags'          => '',
			'author'        => (int) Localia_Settings::get( 'default_author' ),
			'avoid_repeats' => true,
		);
	}

	private static function t() {
		return Localia_DB::table( 'tasks' );
	}

	private static function decode( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row['id']          = (int) $row['id'];
		$row['enabled']     = (bool) $row['enabled'];
		$row['config']      = array_merge( self::default_config( $row['type'] ), (array) json_decode( (string) $row['config'], true ) );
		$row['times']       = array_values( array_filter( (array) json_decode( (string) $row['times'], true ) ) );
		$row['days']        = '' === $row['days'] ? array() : array_map( 'intval', explode( ',', $row['days'] ) );
		$row['state']       = (array) json_decode( (string) $row['state'], true );
		$row['next_run_at'] = null === $row['next_run_at'] ? null : (int) $row['next_run_at'];
		$row['last_run_at'] = null === $row['last_run_at'] ? null : (int) $row['last_run_at'];
		return $row;
	}

	public static function all() {
		global $wpdb;
		return array_map( array( __CLASS__, 'decode' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', self::t() ), ARRAY_A ) );
	}

	public static function get( $id ) {
		global $wpdb;
		return self::decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t(), $id ), ARRAY_A ) );
	}

	public static function due( $now ) {
		global $wpdb;
		return array_map( array( __CLASS__, 'decode' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= %d', self::t(), $now ), ARRAY_A ) );
	}

	/**
	 * Validate and store a task.
	 *
	 * @param array $data Raw (unslashed) form data.
	 * @return int|WP_Error Task id.
	 */
	public static function save( array $data ) {
		global $wpdb;
		$types = self::types();
		$type  = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'create_post';
		if ( ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'invalid_type', __( 'Unknown task type.', 'localia' ) );
		}
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'name_required', __( 'Please give the task a name.', 'localia' ) );
		}

		$times = array();
		foreach ( (array) ( isset( $data['times'] ) ? $data['times'] : array() ) as $time ) {
			if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( (string) $time ), $m ) ) {
				$times[] = sprintf( '%02d:%02d', $m[1], $m[2] );
			}
		}
		$times = array_values( array_unique( $times ) );
		sort( $times );

		$days = array();
		foreach ( (array) ( isset( $data['days'] ) ? $data['days'] : array() ) as $d ) {
			if ( is_numeric( $d ) && $d >= 0 && $d <= 6 ) {
				$days[] = (int) $d;
			}
		}
		$days = array_values( array_unique( $days ) );
		sort( $days );

		$config = self::sanitize_config( $type, isset( $data['config'] ) ? (array) $data['config'] : array() );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$enabled = ! empty( $data['enabled'] );
		$id      = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$now     = time();
		$row     = array(
			'name'        => $name,
			'type'        => $type,
			'enabled'     => $enabled ? 1 : 0,
			'config'      => wp_json_encode( $config ),
			'times'       => wp_json_encode( $times ),
			'days'        => implode( ',', $days ),
			'next_run_at' => $enabled ? Localia_Scheduler::next_run( $times, $days, $now ) : null,
			'updated_at'  => $now,
		);
		$formats = array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d' );

		if ( $id && self::get( $id ) ) {
			$wpdb->update( self::t(), $row, array( 'id' => $id ), $formats, array( '%d' ) );
		} else {
			$row['created_at'] = $now;
			$row['state']      = wp_json_encode( array() );
			$formats[]         = '%d';
			$formats[]         = '%s';
			$wpdb->insert( self::t(), $row, $formats );
			$id = (int) $wpdb->insert_id;
		}
		return $id;
	}

	private static function sanitize_config( $type, array $c ) {
		if ( ! in_array( $type, array( 'create_post', 'rewrite_post' ), true ) ) {
			/**
			 * Validate the settings of a task type added by an add-on.
			 * Return the clean config array, or a WP_Error to show the user.
			 */
			return apply_filters( 'localia_sanitize_task_config', new WP_Error( 'unsupported', __( 'This task type is not available.', 'localia' ) ), $c, $type );
		}
		$d = self::default_config( $type );
		if ( 'rewrite_post' === $type ) {
			$out = array(
				'target'       => isset( $c['target'] ) && 'oldest_in_category' === $c['target'] ? 'oldest_in_category' : 'post',
				'post_id'      => isset( $c['post_id'] ) ? absint( $c['post_id'] ) : 0,
				'category'     => isset( $c['rewrite_category'] ) ? absint( $c['rewrite_category'] ) : ( isset( $c['category'] ) ? absint( $c['category'] ) : 0 ),
				'skip_days'    => isset( $c['skip_days'] ) ? max( 1, min( 3650, absint( $c['skip_days'] ) ) ) : $d['skip_days'],
				'instructions' => isset( $c['instructions'] ) ? sanitize_textarea_field( $c['instructions'] ) : $d['instructions'],
				'save_mode'    => isset( $c['save_mode'] ) && 'draft_copy' === $c['save_mode'] ? 'draft_copy' : 'replace',
			);
			if ( 'post' === $out['target'] && ! get_post( $out['post_id'] ) ) {
				return new WP_Error( 'post_required', __( 'Choose the post to rewrite (enter a valid post ID).', 'localia' ) );
			}
			if ( '' === trim( $out['instructions'] ) ) {
				return new WP_Error( 'instructions_required', __( 'Tell the AI how to rewrite the post.', 'localia' ) );
			}
			return $out;
		}

		$status = isset( $c['post_status'] ) ? sanitize_key( $c['post_status'] ) : $d['post_status'];
		$out    = array(
			'prompt'        => isset( $c['prompt'] ) ? sanitize_textarea_field( $c['prompt'] ) : $d['prompt'],
			'topics'        => isset( $c['topics'] ) ? sanitize_textarea_field( $c['topics'] ) : '',
			'topic_mode'    => isset( $c['topic_mode'] ) && 'random' === $c['topic_mode'] ? 'random' : 'sequence',
			'title'         => isset( $c['title'] ) ? sanitize_text_field( $c['title'] ) : '',
			'post_status'   => in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ? $status : 'draft',
			'category'      => isset( $c['category'] ) ? absint( $c['category'] ) : 0,
			'tags'          => isset( $c['tags'] ) ? sanitize_text_field( $c['tags'] ) : '',
			'author'        => isset( $c['author'] ) ? absint( $c['author'] ) : 0,
			'avoid_repeats' => ! empty( $c['avoid_repeats'] ),
		);
		if ( '' === trim( $out['prompt'] ) ) {
			return new WP_Error( 'prompt_required', __( 'The prompt can not be empty.', 'localia' ) );
		}
		return $out;
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::t(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function set_enabled( $id, $enabled ) {
		global $wpdb;
		$task = self::get( $id );
		if ( ! $task ) {
			return;
		}
		$wpdb->update(
			self::t(),
			array(
				'enabled'     => $enabled ? 1 : 0,
				'next_run_at' => $enabled ? Localia_Scheduler::next_run( $task['times'], $task['days'], time() ) : null,
				'updated_at'  => time(),
			),
			array( 'id' => (int) $id ),
			array( '%d', '%d', '%d' ),
			array( '%d' )
		);
	}

	/** Partial update of bookkeeping columns. */
	public static function update_fields( $id, array $fields ) {
		global $wpdb;
		if ( isset( $fields['state'] ) ) {
			$fields['state'] = wp_json_encode( $fields['state'] );
		}
		$wpdb->update( self::t(), $fields, array( 'id' => (int) $id ) );
	}

	/**
	 * Atomically move next_run_at forward. Returns false if another request already did,
	 * so a task never runs twice for the same slot.
	 */
	public static function claim_slot( $id, $expected, $next ) {
		global $wpdb;
		if ( null === $next ) {
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_run_at = NULL WHERE id = %d AND next_run_at = %d', self::t(), $id, $expected ) );
		} else {
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_run_at = %d WHERE id = %d AND next_run_at = %d', self::t(), $next, $id, $expected ) );
		}
		return 1 === (int) $updated;
	}

	/** Human readable schedule, e.g. "06:00, 18:00 · Mon–Fri". */
	public static function describe_schedule( array $task ) {
		if ( ! $task['times'] ) {
			return __( 'Manual only', 'localia' );
		}
		return implode( ', ', array_map( array( 'Localia_Scheduler', 'format_time' ), $task['times'] ) ) . ' · ' . self::describe_days( $task['days'] );
	}

	public static function describe_days( array $days ) {
		sort( $days );
		if ( 7 === count( $days ) ) {
			return __( 'Every day', 'localia' );
		}
		if ( array( 1, 2, 3, 4, 5 ) === $days ) {
			return __( 'Weekdays', 'localia' );
		}
		if ( array( 0, 6 ) === $days ) {
			return __( 'Weekends', 'localia' );
		}
		if ( ! $days ) {
			return __( 'No days selected', 'localia' );
		}
		global $wp_locale;
		return implode(
			', ',
			array_map(
				function ( $d ) use ( $wp_locale ) {
					return $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $d ) );
				},
				$days
			)
		);
	}
}
