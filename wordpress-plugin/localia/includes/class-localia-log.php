<?php
/**
 * Activity log shown on the Dashboard.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table.

class Localia_Log {

	/**
	 * @param string $level   info|success|warning|error
	 * @param string $message Plain text.
	 * @param array  $context Optional task_id, post_id, job_id.
	 */
	public static function add( $level, $message, array $context = array() ) {
		global $wpdb;
		$wpdb->insert(
			Localia_DB::table( 'log' ),
			array(
				'created_at' => time(),
				'level'      => $level,
				'message'    => wp_strip_all_tags( $message ),
				'task_id'    => isset( $context['task_id'] ) ? (int) $context['task_id'] : null,
				'post_id'    => isset( $context['post_id'] ) ? (int) $context['post_id'] : null,
				'job_id'     => isset( $context['job_id'] ) ? (string) $context['job_id'] : null,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	public static function recent( $limit = 50, $only_posts = false ) {
		global $wpdb;
		if ( $only_posts ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id IS NOT NULL ORDER BY id DESC LIMIT %d', Localia_DB::table( 'log' ), $limit ), ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Localia_DB::table( 'log' ), $limit ), ARRAY_A );
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Localia_DB::table( 'log' ) ) );
	}

	public static function purge( $before ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %d', Localia_DB::table( 'log' ), $before ) );
	}
}
