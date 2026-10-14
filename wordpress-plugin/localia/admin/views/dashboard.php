<?php
/**
 * Dashboard tab.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$localia_agent    = Localia_Settings::agent_status();
$localia_counts   = Localia_Queue::counts();
$localia_cron     = Localia_Scheduler::cron_health();
$localia_changes  = Localia_Log::recent( 10, true );
$localia_log      = Localia_Log::recent( 60 );
$localia_upcoming = array_filter(
	Localia_Tasks::all(),
	function ( $t ) {
		return $t['enabled'] && $t['next_run_at'];
	}
);
usort(
	$localia_upcoming,
	function ( $a, $b ) {
		return $a['next_run_at'] - $b['next_run_at'];
	}
);
$localia_upcoming = array_slice( $localia_upcoming, 0, 5 );
?>

<?php if ( ! $localia_agent['last_seen'] ) : ?>
	<div class="localia-hero">
		<h2><?php esc_html_e( 'Welcome! Let’s connect your PC.', 'localia' ); ?></h2>
		<p><?php esc_html_e( 'Localia lets your site use the AI model running on your own computer. Setup takes about five minutes: install a local AI app, download the Localia agent, and start it.', 'localia' ); ?></p>
		<p><a class="button button-primary button-hero" href="<?php echo esc_url( Localia_Admin::url( 'settings' ) ); ?>"><?php esc_html_e( 'Connect your PC', 'localia' ); ?></a></p>
	</div>
<?php endif; ?>

<?php if ( $localia_cron['disabled'] || $localia_cron['stale'] ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e( 'WordPress’s scheduler (WP-Cron) has not run recently, so scheduled tasks may start late. Your PC’s checks also trigger it, but for exact timing set up a server cron job.', 'localia' ); ?>
			<a href="<?php echo esc_url( Localia_Admin::url( 'schedule' ) . '#localia-cron' ); ?>"><?php esc_html_e( 'How?', 'localia' ); ?></a>
		</p>
	</div>
<?php endif; ?>

<div class="localia-cards">
	<div class="localia-card">
		<h3><?php esc_html_e( 'Your PC', 'localia' ); ?></h3>
		<?php if ( ! $localia_agent['last_seen'] ) : ?>
			<p class="localia-big is-off"><?php esc_html_e( 'Not connected', 'localia' ); ?></p>
			<p class="description"><a href="<?php echo esc_url( Localia_Admin::url( 'settings' ) ); ?>"><?php esc_html_e( 'Set it up', 'localia' ); ?></a></p>
		<?php else : ?>
			<p class="localia-big <?php echo $localia_agent['online'] ? 'is-on' : 'is-warn'; ?>"><?php echo $localia_agent['online'] ? esc_html__( 'Online', 'localia' ) : esc_html__( 'Offline', 'localia' ); ?></p>
			<p class="description">
				<?php
				/* translators: %s: e.g. "2 minutes ago" */
				echo esc_html( sprintf( __( 'Last check: %s', 'localia' ), Localia_Admin::time_ago( $localia_agent['last_seen'] ) ) );
				if ( ! empty( $localia_agent['info']['model'] ) ) {
					echo '<br>' . esc_html( sprintf( /* translators: %s: model name */ __( 'Model: %s', 'localia' ), $localia_agent['info']['model'] ) );
				}
				?>
			</p>
		<?php endif; ?>
	</div>
	<div class="localia-card">
		<h3><?php esc_html_e( 'Queue', 'localia' ); ?></h3>
		<p class="localia-big"><?php echo esc_html( number_format_i18n( $localia_counts['queued'] + $localia_counts['leased'] ) ); ?></p>
		<p class="description">
			<?php
			/* translators: 1: jobs waiting, 2: jobs in progress */
			echo esc_html( sprintf( __( '%1$d waiting · %2$d in progress', 'localia' ), $localia_counts['queued'], $localia_counts['leased'] ) );
			?>
		</p>
	</div>
	<div class="localia-card localia-card-wide">
		<h3><?php esc_html_e( 'Coming up', 'localia' ); ?></h3>
		<?php if ( ! $localia_upcoming ) : ?>
			<p class="description">
				<?php esc_html_e( 'Nothing scheduled.', 'localia' ); ?>
				<a href="<?php echo esc_url( Localia_Admin::url( 'tasks', array( 'action' => 'edit' ) ) ); ?>"><?php esc_html_e( 'Create a task', 'localia' ); ?></a>
			</p>
		<?php else : ?>
			<ul class="localia-list">
				<?php foreach ( $localia_upcoming as $localia_t ) : ?>
					<li><strong><?php echo esc_html( Localia_Admin::datetime( $localia_t['next_run_at'] ) ); ?></strong> — <?php echo esc_html( $localia_t['name'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>

<h2><?php esc_html_e( 'Recent changes', 'localia' ); ?></h2>
<?php if ( ! $localia_changes ) : ?>
	<p class="description"><?php esc_html_e( 'Posts your AI creates or updates will appear here.', 'localia' ); ?></p>
<?php else : ?>
	<table class="widefat striped localia-table">
		<thead><tr><th><?php esc_html_e( 'When', 'localia' ); ?></th><th><?php esc_html_e( 'What happened', 'localia' ); ?></th><th><?php esc_html_e( 'Post', 'localia' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $localia_changes as $localia_row ) : ?>
			<tr>
				<td><?php echo esc_html( Localia_Admin::time_ago( (int) $localia_row['created_at'] ) ); ?></td>
				<td><?php echo esc_html( $localia_row['message'] ); ?></td>
				<td>
					<?php if ( get_post( (int) $localia_row['post_id'] ) ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $localia_row['post_id'] ) ); ?>"><?php esc_html_e( 'Edit', 'localia' ); ?></a> ·
						<a href="<?php echo esc_url( (string) get_permalink( (int) $localia_row['post_id'] ) ); ?>"><?php esc_html_e( 'View', 'localia' ); ?></a> ·
						<span class="localia-post-status"><?php echo esc_html( get_post_status_object( get_post_status( (int) $localia_row['post_id'] ) )->label ); ?></span>
					<?php else : ?>
						<span class="description"><?php esc_html_e( 'deleted', 'localia' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<div class="localia-row-between">
	<h2><?php esc_html_e( 'Activity log', 'localia' ); ?></h2>
	<?php if ( $localia_log ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="localia_clear_log">
			<?php wp_nonce_field( 'localia_clear_log' ); ?>
			<button class="button button-small"><?php esc_html_e( 'Clear log', 'localia' ); ?></button>
		</form>
	<?php endif; ?>
</div>
<?php if ( ! $localia_log ) : ?>
	<p class="description"><?php esc_html_e( 'No activity yet.', 'localia' ); ?></p>
<?php else : ?>
	<div class="localia-log">
		<?php foreach ( $localia_log as $localia_row ) : ?>
			<div class="localia-log-line is-<?php echo esc_attr( $localia_row['level'] ); ?>">
				<span class="localia-log-time"><?php echo esc_html( Localia_Admin::datetime( (int) $localia_row['created_at'] ) ); ?></span>
				<?php echo esc_html( $localia_row['message'] ); ?>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
