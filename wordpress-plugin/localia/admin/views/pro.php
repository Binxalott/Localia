<?php
/**
 * Free vs Pro tab.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$localia_features = array(
	array( __( 'Connect the AI on your own PC (Ollama, LM Studio and more)', 'localia' ), true, true ),
	array( __( '“Generate with my AI” box in the post editor', 'localia' ), true, true ),
	array( __( 'Write new posts automatically, at set times every day', 'localia' ), true, true ),
	array( __( 'Rewrite and refresh existing posts', 'localia' ), true, true ),
	array( __( 'Unlimited tasks, topic lists and repeat-avoidance', 'localia' ), true, true ),
	array( __( 'Dashboard, activity log and pre-configured agent download', 'localia' ), true, true ),
	array( __( 'Calendar scheduling: plan posts for specific dates weeks ahead', 'localia' ), false, true ),
	array( __( 'Recurring rules: “every 2nd Tuesday”, “first of the month”…', 'localia' ), false, true ),
	array( __( 'AI comment spam check with your own yes / no / unknown actions', 'localia' ), false, true ),
	array( __( 'AI reply drafts for comments, approved by you', 'localia' ), false, true ),
	array( __( 'Image and audio generation (coming soon)', 'localia' ), false, true ),
	array( __( 'Priority email support', 'localia' ), false, true ),
);
$localia_pro_url  = add_query_arg( 'utm_source', 'plugin', LOCALIA_PRO_URL );
?>
<div class="localia-pro">
	<div class="localia-pro-intro">
		<h2><?php esc_html_e( 'Localia Pro', 'localia' ); ?></h2>
		<p><?php esc_html_e( 'The free plugin is complete and yours to keep. Pro is a separate add-on for sites that want to plan further ahead and let their AI help with comments too.', 'localia' ); ?></p>
	</div>

	<table class="localia-compare">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Feature', 'localia' ); ?></th>
				<th><?php esc_html_e( 'Free', 'localia' ); ?></th>
				<th class="is-pro"><?php esc_html_e( 'Pro', 'localia' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $localia_features as $localia_f ) : ?>
				<tr>
					<td><?php echo esc_html( $localia_f[0] ); ?></td>
					<td class="localia-mark"><?php echo $localia_f[1] ? '<span class="dashicons dashicons-yes" aria-label="' . esc_attr__( 'Included', 'localia' ) . '"></span>' : '<span class="localia-dash" aria-label="' . esc_attr__( 'Not included', 'localia' ) . '">—</span>'; ?></td>
					<td class="localia-mark is-pro"><span class="dashicons dashicons-yes" aria-label="<?php esc_attr_e( 'Included', 'localia' ); ?>"></span></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr>
				<td></td>
				<td><span class="description"><?php esc_html_e( 'You have this', 'localia' ); ?></span></td>
				<td class="is-pro"><a class="button button-primary" href="<?php echo esc_url( $localia_pro_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Localia Pro', 'localia' ); ?></a></td>
			</tr>
		</tfoot>
	</table>

	<p class="description localia-pro-foot">
		<?php esc_html_e( 'Pro is installed next to this plugin; your tasks and settings stay as they are.', 'localia' ); ?>
		<a href="<?php echo esc_url( LOCALIA_PRO_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'localia' ); ?></a>
	</p>
</div>
