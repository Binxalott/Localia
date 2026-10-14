<?php
/**
 * Tasks tab: list of tasks, or the add/edit form.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
$localia_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
$localia_id     = isset( $_GET['task'] ) ? absint( $_GET['task'] ) : 0;
// phpcs:enable
$localia_types = Localia_Tasks::types();

if ( 'edit' !== $localia_action ) :
	$localia_tasks = Localia_Tasks::all();
	?>
	<div class="localia-row-between">
		<p class="description"><?php esc_html_e( 'A task is a reusable recipe, like “write a post about the next topic on my list”. Give it times of day and Localia queues it for your PC automatically.', 'localia' ); ?></p>
		<a class="button button-primary" href="<?php echo esc_url( Localia_Admin::url( 'tasks', array( 'action' => 'edit' ) ) ); ?>"><?php esc_html_e( 'Add task', 'localia' ); ?></a>
	</div>

	<?php if ( ! $localia_tasks ) : ?>
		<div class="localia-empty">
			<p><?php esc_html_e( 'No tasks yet. Create one to start writing posts automatically.', 'localia' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( Localia_Admin::url( 'tasks', array( 'action' => 'edit' ) ) ); ?>"><?php esc_html_e( 'Create your first task', 'localia' ); ?></a></p>
		</div>
	<?php else : ?>
		<table class="widefat striped localia-table localia-tasks">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Task', 'localia' ); ?></th>
					<th><?php esc_html_e( 'Type', 'localia' ); ?></th>
					<th><?php esc_html_e( 'Runs', 'localia' ); ?></th>
					<th><?php esc_html_e( 'Next run', 'localia' ); ?></th>
					<th><?php esc_html_e( 'Last run', 'localia' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $localia_tasks as $localia_task ) : ?>
				<tr class="<?php echo $localia_task['enabled'] ? '' : 'is-disabled'; ?>">
					<td>
						<strong><a href="
						<?php
						echo esc_url(
							Localia_Admin::url(
								'tasks',
								array(
									'action' => 'edit',
									'task'   => $localia_task['id'],
								)
							)
						);
						?>
											"><?php echo esc_html( $localia_task['name'] ); ?></a></strong>
						<?php if ( ! $localia_task['enabled'] ) : ?>
							<span class="localia-pill"><?php esc_html_e( 'Paused', 'localia' ); ?></span>
						<?php endif; ?>
						<div class="row-actions">
							<span><a href="
							<?php
							echo esc_url(
								Localia_Admin::url(
									'tasks',
									array(
										'action' => 'edit',
										'task'   => $localia_task['id'],
									)
								)
							);
							?>
											"><?php esc_html_e( 'Edit', 'localia' ); ?></a> | </span>
							<span><a href="<?php echo esc_url( Localia_Admin::task_action_url( $localia_task['id'], 'run' ) ); ?>"><?php esc_html_e( 'Run now', 'localia' ); ?></a> | </span>
							<?php if ( $localia_task['enabled'] ) : ?>
								<span><a href="<?php echo esc_url( Localia_Admin::task_action_url( $localia_task['id'], 'disable' ) ); ?>"><?php esc_html_e( 'Pause', 'localia' ); ?></a> | </span>
							<?php else : ?>
								<span><a href="<?php echo esc_url( Localia_Admin::task_action_url( $localia_task['id'], 'enable' ) ); ?>"><?php esc_html_e( 'Resume', 'localia' ); ?></a> | </span>
							<?php endif; ?>
							<span class="trash"><a class="localia-confirm-delete" href="<?php echo esc_url( Localia_Admin::task_action_url( $localia_task['id'], 'delete' ) ); ?>"><?php esc_html_e( 'Delete', 'localia' ); ?></a></span>
						</div>
					</td>
					<td><?php echo esc_html( isset( $localia_types[ $localia_task['type'] ] ) ? $localia_types[ $localia_task['type'] ] : $localia_task['type'] ); ?></td>
					<td><?php echo esc_html( Localia_Tasks::describe_schedule( $localia_task ) ); ?></td>
					<td><?php echo $localia_task['enabled'] && $localia_task['next_run_at'] ? esc_html( Localia_Admin::datetime( $localia_task['next_run_at'] ) ) : '—'; ?></td>
					<td>
						<?php echo Localia_Admin::status_pill( $localia_task['last_status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
						<?php if ( $localia_task['last_run_at'] ) : ?>
							<span class="description"><?php echo esc_html( Localia_Admin::time_ago( $localia_task['last_run_at'] ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
	return;
endif;

// ------------------------------------------------------------------ add / edit form

$localia_task  = $localia_id ? Localia_Tasks::get( $localia_id ) : null;
$localia_draft = get_transient( 'localia_task_draft_' . get_current_user_id() );
if ( $localia_draft ) {
	delete_transient( 'localia_task_draft_' . get_current_user_id() );
}
if ( ! $localia_task ) {
	$localia_task = array(
		'id'      => 0,
		'name'    => '',
		'type'    => 'create_post',
		'enabled' => true,
		'times'   => array( '09:00' ),
		'days'    => array( 0, 1, 2, 3, 4, 5, 6 ),
		'config'  => array(),
	);
}
if ( is_array( $localia_draft ) ) {
	// Re-show what the user typed after a validation error.
	$localia_task            = array_merge( $localia_task, $localia_draft );
	$localia_task['enabled'] = ! empty( $localia_draft['enabled'] );
	$localia_task['days']    = isset( $localia_draft['days'] ) ? array_map( 'intval', (array) $localia_draft['days'] ) : array();
	$localia_task['times']   = isset( $localia_draft['times'] ) ? (array) $localia_draft['times'] : array();
}
$localia_type   = $localia_task['type'];
$localia_create = array_merge( Localia_Tasks::default_config( 'create_post' ), 'create_post' === $localia_type ? (array) $localia_task['config'] : array() );
$localia_rew    = array_merge( Localia_Tasks::default_config( 'rewrite_post' ), 'rewrite_post' === $localia_type ? (array) $localia_task['config'] : array() );
if ( isset( $localia_rew['rewrite_category'] ) ) {
	$localia_rew['category'] = (int) $localia_rew['rewrite_category'];
}
global $wp_locale;
?>
<p><a href="<?php echo esc_url( Localia_Admin::url( 'tasks' ) ); ?>">&larr; <?php esc_html_e( 'All tasks', 'localia' ); ?></a></p>
<h2><?php echo $localia_task['id'] ? esc_html__( 'Edit task', 'localia' ) : esc_html__( 'New task', 'localia' ); ?></h2>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="localia-form" id="localia-task-form">
	<input type="hidden" name="action" value="localia_save_task">
	<input type="hidden" name="task[id]" value="<?php echo esc_attr( $localia_task['id'] ); ?>">
	<?php wp_nonce_field( 'localia_save_task' ); ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="localia-name"><?php esc_html_e( 'Name', 'localia' ); ?></label></th>
			<td><input type="text" id="localia-name" name="task[name]" class="regular-text" required value="<?php echo esc_attr( $localia_task['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Daily gardening tip', 'localia' ); ?>"></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'What it does', 'localia' ); ?></th>
			<td>
				<fieldset class="localia-type-choice">
					<?php foreach ( $localia_types as $localia_key => $localia_label ) : ?>
						<label><input type="radio" name="task[type]" value="<?php echo esc_attr( $localia_key ); ?>" <?php checked( $localia_type, $localia_key ); ?>> <?php echo esc_html( $localia_label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'localia' ); ?></th>
			<td><label><input type="checkbox" name="task[enabled]" value="1" <?php checked( $localia_task['enabled'] ); ?>> <?php esc_html_e( 'Active (runs on its schedule)', 'localia' ); ?></label></td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'When', 'localia' ); ?></h3>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Times of day', 'localia' ); ?></th>
			<td>
				<div id="localia-times">
					<?php foreach ( $localia_task['times'] ? $localia_task['times'] : array( '' ) as $localia_time ) : ?>
						<span class="localia-time"><input type="time" name="task[times][]" value="<?php echo esc_attr( $localia_time ); ?>"> <button type="button" class="button-link localia-remove-time"><?php esc_html_e( 'Remove', 'localia' ); ?></button></span>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button button-small" id="localia-add-time"><?php esc_html_e( '+ Add a time', 'localia' ); ?></button>
				<p class="description">
					<?php
					/* translators: %s: time zone name */
					echo esc_html( sprintf( __( 'Uses your site’s time zone (%s). Leave empty to only run this task with “Run now”.', 'localia' ), wp_timezone_string() ) );
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Days', 'localia' ); ?></th>
			<td>
				<fieldset class="localia-days">
					<?php foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $localia_d ) : ?>
						<label><input type="checkbox" name="task[days][]" value="<?php echo esc_attr( $localia_d ); ?>" <?php checked( in_array( $localia_d, $localia_task['days'], true ) ); ?>> <?php echo esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $localia_d ) ) ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description">
					<?php esc_html_e( 'Want to plan posts for specific dates or set rules like “every 2nd Tuesday”?', 'localia' ); ?>
					<a href="<?php echo esc_url( Localia_Admin::url( 'pro' ) ); ?>"><?php esc_html_e( 'That’s in Localia Pro.', 'localia' ); ?></a>
				</p>
			</td>
		</tr>
	</table>

	<div class="localia-type-section" data-type="create_post">
		<h3><?php esc_html_e( 'The post', 'localia' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="localia-prompt"><?php esc_html_e( 'Instructions for the AI', 'localia' ); ?></label></th>
				<td>
					<textarea id="localia-prompt" name="task[config][prompt]" rows="5" class="large-text"><?php echo esc_textarea( $localia_create['prompt'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Placeholders you can use:', 'localia' ); ?> <code>{topic}</code> <code>{site_name}</code> <code>{site_tagline}</code> <code>{date}</code> <code>{category}</code> <code>{recent_titles}</code></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="localia-topics"><?php esc_html_e( 'Topics', 'localia' ); ?></label></th>
				<td>
					<textarea id="localia-topics" name="task[config][topics]" rows="5" class="large-text" placeholder="<?php esc_attr_e( "How to start seeds indoors\nBest plants for shade\nComposting for beginners", 'localia' ); ?>"><?php echo esc_textarea( $localia_create['topics'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional. One per line; each run uses the next one as {topic}.', 'localia' ); ?></p>
					<label><input type="radio" name="task[config][topic_mode]" value="sequence" <?php checked( $localia_create['topic_mode'], 'sequence' ); ?>> <?php esc_html_e( 'In order (starts over at the end)', 'localia' ); ?></label>
					&nbsp; <label><input type="radio" name="task[config][topic_mode]" value="random" <?php checked( $localia_create['topic_mode'], 'random' ); ?>> <?php esc_html_e( 'Random', 'localia' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="localia-title"><?php esc_html_e( 'Title', 'localia' ); ?></label></th>
				<td>
					<input type="text" id="localia-title" name="task[config][title]" class="regular-text" value="<?php echo esc_attr( $localia_create['title'] ); ?>" placeholder="<?php esc_attr_e( 'Leave empty to let the AI write the title', 'localia' ); ?>">
					<p class="description"><?php esc_html_e( 'Or a fixed title, e.g. “Tip of the day: {topic}” or “News for {date}”.', 'localia' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="localia-status"><?php esc_html_e( 'Save as', 'localia' ); ?></label></th>
				<td>
					<select id="localia-status" name="task[config][post_status]">
						<option value="draft" <?php selected( $localia_create['post_status'], 'draft' ); ?>><?php esc_html_e( 'Draft (you review and publish)', 'localia' ); ?></option>
						<option value="pending" <?php selected( $localia_create['post_status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'localia' ); ?></option>
						<option value="publish" <?php selected( $localia_create['post_status'], 'publish' ); ?>><?php esc_html_e( 'Published immediately', 'localia' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="localia-category"><?php esc_html_e( 'Category', 'localia' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_categories(
						array(
							'name'              => 'task[config][category]',
							'id'                => 'localia-category',
							'selected'          => (int) $localia_create['category'],
							'show_option_none'  => __( '— Default —', 'localia' ),
							'option_none_value' => 0,
							'hide_empty'        => false,
							'hierarchical'      => true,
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="localia-tags"><?php esc_html_e( 'Tags', 'localia' ); ?></label></th>
				<td><input type="text" id="localia-tags" name="task[config][tags]" class="regular-text" value="<?php echo esc_attr( $localia_create['tags'] ); ?>" placeholder="<?php esc_attr_e( 'comma, separated', 'localia' ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="localia-author"><?php esc_html_e( 'Author', 'localia' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_users(
						array(
							'name'              => 'task[config][author]',
							'id'                => 'localia-author',
							'selected'          => (int) $localia_create['author'],
							'show_option_none'  => __( '— Site administrator —', 'localia' ),
							'option_none_value' => 0,
							'capability'        => array( 'edit_posts' ),
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Avoid repeats', 'localia' ); ?></th>
				<td><label><input type="checkbox" name="task[config][avoid_repeats]" value="1" <?php checked( ! empty( $localia_create['avoid_repeats'] ) ); ?>> <?php esc_html_e( 'Show the AI the titles of recent posts so it doesn’t write the same thing twice', 'localia' ); ?></label></td>
			</tr>
		</table>
	</div>

	<div class="localia-type-section" data-type="rewrite_post">
		<h3><?php esc_html_e( 'The rewrite', 'localia' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Which post', 'localia' ); ?></th>
				<td>
					<p><label><input type="radio" name="task[config][target]" value="post" <?php checked( $localia_rew['target'], 'post' ); ?>> <?php esc_html_e( 'A specific post — ID:', 'localia' ); ?></label>
						<input type="number" min="1" name="task[config][post_id]" class="small-text" value="<?php echo esc_attr( $localia_rew['post_id'] ? $localia_rew['post_id'] : '' ); ?>">
						<?php if ( $localia_rew['post_id'] && get_post( $localia_rew['post_id'] ) ) : ?>
							<span class="description">(<?php echo esc_html( get_the_title( $localia_rew['post_id'] ) ); ?>)</span>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e( 'The ID is the number in the address bar when you edit a post (post=123).', 'localia' ); ?></p>
					<p><label><input type="radio" name="task[config][target]" value="oldest_in_category" <?php checked( $localia_rew['target'], 'oldest_in_category' ); ?>> <?php esc_html_e( 'The least recently updated published post in', 'localia' ); ?></label>
						<?php
						wp_dropdown_categories(
							array(
								'name'              => 'task[config][rewrite_category]',
								'selected'          => (int) $localia_rew['category'],
								'show_option_none'  => __( 'any category', 'localia' ),
								'option_none_value' => 0,
								'hide_empty'        => false,
								'hierarchical'      => true,
							)
						);
						?>
					</p>
					<p><label><?php esc_html_e( 'Skip posts Localia rewrote in the last', 'localia' ); ?> <input type="number" min="1" name="task[config][skip_days]" class="small-text" value="<?php echo esc_attr( $localia_rew['skip_days'] ); ?>"> <?php esc_html_e( 'days', 'localia' ); ?></label></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="localia-instructions"><?php esc_html_e( 'Instructions for the AI', 'localia' ); ?></label></th>
				<td>
					<textarea id="localia-instructions" name="task[config][instructions]" rows="4" class="large-text"><?php echo esc_textarea( $localia_rew['instructions'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'The post’s current title and text are sent along automatically.', 'localia' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Save the result', 'localia' ); ?></th>
				<td>
					<p><label><input type="radio" name="task[config][save_mode]" value="replace" <?php checked( $localia_rew['save_mode'], 'replace' ); ?>> <?php esc_html_e( 'Update the post (the old version stays under Revisions)', 'localia' ); ?></label></p>
					<p><label><input type="radio" name="task[config][save_mode]" value="draft_copy" <?php checked( $localia_rew['save_mode'], 'draft_copy' ); ?>> <?php esc_html_e( 'Save as a new draft for me to review', 'localia' ); ?></label></p>
				</td>
			</tr>
		</table>
	</div>

	<?php
	/**
	 * Add-ons render the settings for their own task types here, inside
	 * <div class="localia-type-section" data-type="their_type">.
	 */
	do_action( 'localia_task_form_sections', $localia_task );
	?>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save task', 'localia' ); ?></button>
		<button type="submit" class="button" name="run_now" value="1"><?php esc_html_e( 'Save and run now', 'localia' ); ?></button>
		<a class="button-link" href="<?php echo esc_url( Localia_Admin::url( 'tasks' ) ); ?>"><?php esc_html_e( 'Cancel', 'localia' ); ?></a>
	</p>
</form>
