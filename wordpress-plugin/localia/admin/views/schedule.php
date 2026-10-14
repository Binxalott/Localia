<?php
/**
 * Schedule tab: a 24-hour overview of when tasks run.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$localia_tasks = array_filter(
	Localia_Tasks::all(),
	function ( $t ) {
		return $t['times'];
	}
);
$localia_cron  = Localia_Scheduler::cron_health();
$localia_tz    = wp_timezone();
$localia_now   = new DateTimeImmutable( 'now', $localia_tz );

// Today's and tomorrow's runs, in order.
$localia_agenda = array();
foreach ( array( 0, 1 ) as $localia_offset ) {
	$localia_day = $localia_now->modify( '+' . $localia_offset . ' day' );
	foreach ( $localia_tasks as $localia_t ) {
		if ( ! $localia_t['enabled'] || ! in_array( (int) $localia_day->format( 'w' ), $localia_t['days'], true ) ) {
			continue;
		}
		foreach ( $localia_t['times'] as $localia_hm ) {
			list( $localia_h, $localia_m )       = array_map( 'intval', explode( ':', $localia_hm ) );
			$localia_at                          = $localia_day->setTime( $localia_h, $localia_m );
			$localia_agenda[ $localia_offset ][] = array(
				'at'   => $localia_at->getTimestamp(),
				'task' => $localia_t,
				'past' => $localia_at < $localia_now,
			);
		}
	}
	if ( ! empty( $localia_agenda[ $localia_offset ] ) ) {
		usort(
			$localia_agenda[ $localia_offset ],
			function ( $a, $b ) {
				return $a['at'] - $b['at'];
			}
		);
	}
}
$localia_now_pct = ( (int) $localia_now->format( 'G' ) * 60 + (int) $localia_now->format( 'i' ) ) / 14.4;
?>
<p class="description">
	<?php
	/* translators: %s: time zone */
	echo esc_html( sprintf( __( 'Every task runs at its times of day on the days you picked. Times use your site’s time zone (%s).', 'localia' ), wp_timezone_string() ) );
	?>
	<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php esc_html_e( 'Change time zone', 'localia' ); ?></a>
</p>

<?php if ( ! $localia_tasks ) : ?>
	<div class="localia-empty">
		<p><?php esc_html_e( 'No scheduled tasks yet.', 'localia' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( Localia_Admin::url( 'tasks', array( 'action' => 'edit' ) ) ); ?>"><?php esc_html_e( 'Create a task', 'localia' ); ?></a></p>
	</div>
<?php else : ?>
	<h2><?php esc_html_e( 'Your day at a glance', 'localia' ); ?></h2>
	<div class="localia-timeline">
		<div class="localia-timeline-hours" aria-hidden="true">
			<?php for ( $localia_i = 0; $localia_i <= 24; $localia_i += 3 ) : ?>
				<span style="left: <?php echo esc_attr( $localia_i / 24 * 100 ); ?>%"><?php echo esc_html( sprintf( '%02d:00', $localia_i % 24 ) ); ?></span>
			<?php endfor; ?>
		</div>
		<?php foreach ( $localia_tasks as $localia_t ) : ?>
			<div class="localia-timeline-row <?php echo $localia_t['enabled'] ? '' : 'is-disabled'; ?>">
				<div class="localia-timeline-label">
					<a href="
					<?php
					echo esc_url(
						Localia_Admin::url(
							'tasks',
							array(
								'action' => 'edit',
								'task'   => $localia_t['id'],
							)
						)
					);
					?>
								"><?php echo esc_html( $localia_t['name'] ); ?></a>
					<span class="description"><?php echo esc_html( Localia_Tasks::describe_days( $localia_t['days'] ) . ( $localia_t['enabled'] ? '' : ' · ' . __( 'paused', 'localia' ) ) ); ?></span>
				</div>
				<div class="localia-timeline-track">
					<span class="localia-timeline-now" style="left: <?php echo esc_attr( round( $localia_now_pct, 2 ) ); ?>%" title="<?php esc_attr_e( 'Now', 'localia' ); ?>"></span>
					<?php foreach ( $localia_t['times'] as $localia_hm ) : ?>
						<?php list( $localia_h, $localia_m ) = array_map( 'intval', explode( ':', $localia_hm ) ); ?>
						<span class="localia-timeline-dot" style="left: <?php echo esc_attr( round( ( $localia_h * 60 + $localia_m ) / 14.4, 2 ) ); ?>%" title="<?php echo esc_attr( Localia_Scheduler::format_time( $localia_hm ) ); ?>"></span>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="localia-cards">
		<?php
		foreach ( array(
			0 => __( 'Today', 'localia' ),
			1 => __( 'Tomorrow', 'localia' ),
		) as $localia_offset => $localia_label ) :
			?>
			<div class="localia-card localia-card-wide">
				<h3><?php echo esc_html( $localia_label ); ?></h3>
				<?php if ( empty( $localia_agenda[ $localia_offset ] ) ) : ?>
					<p class="description"><?php esc_html_e( 'Nothing scheduled.', 'localia' ); ?></p>
				<?php else : ?>
					<ul class="localia-list">
						<?php foreach ( $localia_agenda[ $localia_offset ] as $localia_item ) : ?>
							<li class="<?php echo $localia_item['past'] ? 'is-past' : ''; ?>">
								<strong><?php echo esc_html( wp_date( get_option( 'time_format' ), $localia_item['at'] ) ); ?></strong> — <?php echo esc_html( $localia_item['task']['name'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<div class="localia-pro-hint">
	<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
	<div>
		<strong><?php esc_html_e( 'Plan further ahead with Localia Pro', 'localia' ); ?></strong>
		<p><?php esc_html_e( 'A full calendar for specific dates, recurring rules like “first Monday of the month”, plus AI comment moderation.', 'localia' ); ?>
			<a href="<?php echo esc_url( Localia_Admin::url( 'pro' ) ); ?>"><?php esc_html_e( 'Compare Free and Pro', 'localia' ); ?></a></p>
	</div>
</div>

<h2 id="localia-cron"><?php esc_html_e( 'Running on time', 'localia' ); ?></h2>
<p>
	<?php esc_html_e( 'WordPress only runs its scheduler when someone visits your site. Localia also runs it every time your PC checks in, which is usually enough.', 'localia' ); ?>
	<?php if ( $localia_cron['last_tick'] ) : ?>
		<?php
		/* translators: %s: time ago */
		echo esc_html( sprintf( __( 'Last scheduler run: %s.', 'localia' ), Localia_Admin::time_ago( $localia_cron['last_tick'] ) ) );
		?>
	<?php endif; ?>
</p>
<details class="localia-details">
	<summary><?php esc_html_e( 'For exact timing, add a real cron job (recommended for low-traffic sites)', 'localia' ); ?></summary>
	<p><?php esc_html_e( 'In your hosting control panel (cPanel → Cron Jobs, or crontab on a VPS), add a job that runs every 5 minutes:', 'localia' ); ?></p>
	<pre class="localia-code">*/5 * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt; /dev/null 2&gt;&amp;1</pre>
	<p><?php esc_html_e( 'Optionally add this line to wp-config.php so visitors never wait for the scheduler:', 'localia' ); ?></p>
	<pre class="localia-code">define( 'DISABLE_WP_CRON', true );</pre>
</details>
