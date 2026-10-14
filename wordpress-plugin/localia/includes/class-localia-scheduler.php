<?php
/**
 * The daily (24-hour) scheduler, driven by WP-Cron and by the desktop agent's polls.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_Scheduler {

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- tasks are scheduled to the minute.
		add_action( 'localia_tick', array( __CLASS__, 'tick' ) );
		add_action( 'localia_maintenance', array( __CLASS__, 'maintenance' ) );
		add_action( 'init', array( __CLASS__, 'schedule_events' ) );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['localia_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Localia)', 'localia' ),
		);
		return $schedules;
	}

	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'localia_tick' ) ) {
			wp_schedule_event( time() + 30, 'localia_minute', 'localia_tick' );
		}
		if ( ! wp_next_scheduled( 'localia_maintenance' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'localia_maintenance' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'localia_tick' );
		wp_clear_scheduled_hook( 'localia_maintenance' );
	}

	/** Called on every agent poll, at most twice a minute, so low-traffic sites still run on time. */
	public static function tick_if_stale() {
		if ( false === get_transient( 'localia_tick_lock' ) ) {
			self::tick();
		}
	}

	/** Queue every task whose time has come. */
	public static function tick() {
		set_transient( 'localia_tick_lock', 1, 30 );
		update_option( 'localia_last_tick', time(), false );
		$now = time();
		foreach ( Localia_Tasks::due( $now ) as $task ) {
			$next = self::next_run( $task['times'], $task['days'], $now );
			if ( Localia_Tasks::claim_slot( $task['id'], $task['next_run_at'], $next ) ) {
				self::run_task( $task, 'schedule' );
			}
		}
	}

	public static function maintenance() {
		foreach ( Localia_Queue::maintenance() as $job ) {
			Localia_Actions::handle_finished( $job );
		}
		Localia_Log::purge( time() - max( 1, (int) Localia_Settings::get( 'log_days' ) ) * DAY_IN_SECONDS );
	}

	/**
	 * Put one run of a task in the queue for the home PC.
	 *
	 * @param array  $task    Task.
	 * @param string $trigger 'schedule' | 'manual'.
	 * @return string|WP_Error Job id.
	 */
	public static function run_task( array $task, $trigger ) {
		$ctx = array( 'task_id' => $task['id'] );
		if ( Localia_Queue::is_active( $task['last_job_id'] ) ) {
			/* translators: %s: task name */
			Localia_Log::add( 'warning', sprintf( __( 'Skipped "%s": the previous run is still waiting for your PC.', 'localia' ), $task['name'] ), $ctx );
			Localia_Tasks::update_fields( $task['id'], array( 'last_status' => 'skipped' ) );
			return new WP_Error( 'busy', __( 'The previous run of this task is still waiting for your PC.', 'localia' ) );
		}

		$built = Localia_Actions::build_job( $task );
		if ( is_wp_error( $built ) ) {
			/* translators: 1: task name, 2: reason */
			Localia_Log::add( 'warning', sprintf( __( 'Skipped "%1$s": %2$s', 'localia' ), $task['name'], $built->get_error_message() ), $ctx );
			Localia_Tasks::update_fields(
				$task['id'],
				array(
					'last_status' => 'skipped',
					'last_run_at' => time(),
				)
			);
			return $built;
		}

		$job_id = Localia_Queue::enqueue( $built['job'] );
		Localia_Tasks::update_fields(
			$task['id'],
			array(
				'last_job_id' => $job_id,
				'last_run_at' => time(),
				'last_status' => 'queued',
				'state'       => $built['state'],
			)
		);
		$ctx['job_id'] = $job_id;
		Localia_Log::add(
			'info',
			'manual' === $trigger
				/* translators: %s: task name */
				? sprintf( __( 'Queued "%s" (run now).', 'localia' ), $task['name'] )
				/* translators: %s: task name */
				: sprintf( __( 'Queued "%s" (scheduled).', 'localia' ), $task['name'] ),
			$ctx
		);
		return $job_id;
	}

	/**
	 * Next run time (Unix) after $from for daily times ("HH:MM", site time zone) on the given weekdays.
	 *
	 * @param string[] $times Times of day.
	 * @param int[]    $days  0 = Sunday ... 6 = Saturday.
	 * @param int      $from  Unix time.
	 * @return int|null
	 */
	public static function next_run( array $times, array $days, $from ) {
		if ( ! $times || ! $days ) {
			return null;
		}
		sort( $times );
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( '@' . $from ) )->setTimezone( $tz );
		for ( $i = 0; $i < 8; $i++ ) {
			$day = $start->modify( '+' . $i . ' day' );
			if ( ! in_array( (int) $day->format( 'w' ), $days, true ) ) {
				continue;
			}
			foreach ( $times as $hm ) {
				list( $h, $m ) = array_map( 'intval', explode( ':', $hm ) );
				$candidate     = $day->setTime( $h, $m );
				if ( $candidate->getTimestamp() > $from ) {
					return $candidate->getTimestamp();
				}
			}
		}
		return null;
	}

	public static function format_time( $hm ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $hm ) );
		return wp_date( get_option( 'time_format' ), ( new DateTimeImmutable( 'today', wp_timezone() ) )->setTime( $h, $m )->getTimestamp() );
	}

	/** Is WP-Cron healthy? Used for the Dashboard warning. */
	public static function cron_health() {
		$last = (int) get_option( 'localia_last_tick', 0 );
		return array(
			'disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'last_tick' => $last,
			'stale'     => $last > 0 && ( time() - $last ) > 15 * MINUTE_IN_SECONDS,
		);
	}
}
