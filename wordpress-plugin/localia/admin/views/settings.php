<?php
/**
 * Settings tab: connect your PC, writing defaults, advanced options.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Localia_Settings::ensure_secret();
$localia_s     = Localia_Settings::all();
$localia_agent = Localia_Settings::agent_status();
?>

<div class="localia-panel" id="localia-connect">
	<h2><?php esc_html_e( 'Connect your PC', 'localia' ); ?>
		<?php Localia_Admin::render_status_badge(); ?>
	</h2>
	<ol class="localia-steps">
		<li>
			<strong><?php esc_html_e( 'Install a local AI app on your PC', 'localia' ); ?></strong>
			<p><?php esc_html_e( 'We recommend Ollama (free, Windows / macOS / Linux) from ollama.com. After installing, open a terminal and download a model, for example:', 'localia' ); ?></p>
			<pre class="localia-code">ollama pull llama3.1:8b</pre>
			<p class="description"><?php esc_html_e( 'LM Studio also works: start its local server and switch on “Enable CORS”.', 'localia' ); ?></p>
		</li>
		<li>
			<strong><?php esc_html_e( 'Download the Localia agent', 'localia' ); ?></strong>
			<?php if ( Localia_Agent_Download::available() ) : ?>
				<p><?php esc_html_e( 'It is already set up for this site. Unzip it anywhere on your PC.', 'localia' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( Localia_Agent_Download::url() ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download agent for this site', 'localia' ); ?></a></p>
			<?php else : ?>
				<p class="localia-warn-text"><?php esc_html_e( 'Your server cannot build zip files, so get the agent from github.com/binxalott/localia (the “client” folder) and paste the pairing code below into its Settings page.', 'localia' ); ?></p>
			<?php endif; ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Start it', 'localia' ); ?></strong>
			<p><?php esc_html_e( 'Double-click start-windows.bat (Windows), start-macos.command (Mac) or run start-linux.sh (Linux). Your browser opens the agent: choose your model in its Settings, then press Start agent. Keep that tab open.', 'localia' ); ?></p>
		</li>
	</ol>

	<details class="localia-details">
		<summary><?php esc_html_e( 'Advanced: connection details', 'localia' ); ?></summary>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Pairing code', 'localia' ); ?></th>
				<td>
					<textarea readonly rows="3" class="large-text code localia-select" id="localia-pairing"><?php echo esc_textarea( Localia_Settings::pairing_code() ); ?></textarea>
					<button type="button" class="button button-small localia-copy" data-target="localia-pairing"><?php esc_html_e( 'Copy', 'localia' ); ?></button>
					<p class="description"><?php esc_html_e( 'Paste this into the agent’s Settings → “Paste pairing code” instead of downloading. It contains your secret: keep it private.', 'localia' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Endpoint URL', 'localia' ); ?></th>
				<td><code><?php echo esc_html( Localia_Settings::endpoint_url() ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Secret', 'localia' ); ?></th>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="localia-inline-form">
						<input type="hidden" name="action" value="localia_regenerate_secret">
						<?php wp_nonce_field( 'localia_regenerate_secret' ); ?>
						<button class="button localia-confirm-regenerate"><?php esc_html_e( 'Regenerate secret', 'localia' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Use this if the agent folder or pairing code was shared by mistake. Your PC disconnects until you set it up again.', 'localia' ); ?></p>
				</td>
			</tr>
		</table>
	</details>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="localia-panel">
	<input type="hidden" name="action" value="localia_save_settings">
	<?php wp_nonce_field( 'localia_save_settings' ); ?>

	<h2><?php esc_html_e( 'Writing defaults', 'localia' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="localia-site-instructions"><?php esc_html_e( 'Voice and style', 'localia' ); ?></label></th>
			<td>
				<textarea id="localia-site-instructions" name="localia[site_instructions]" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'e.g. You write for a friendly gardening blog for beginners in the UK. Use simple words, short paragraphs and British spelling.', 'localia' ); ?>"><?php echo esc_textarea( $localia_s['site_instructions'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Sent with every request, so all your content sounds like your site.', 'localia' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="localia-language"><?php esc_html_e( 'Instructions are sent in', 'localia' ); ?></label></th>
			<td>
				<select id="localia-language" name="localia[instruction_language]">
					<?php foreach ( Localia_Lang::languages() as $localia_code => $localia_name ) : ?>
						<option value="<?php echo esc_attr( $localia_code ); ?>" <?php selected( $localia_s['instruction_language'], $localia_code ); ?>><?php echo esc_html( $localia_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p><label><input type="checkbox" name="localia[language_only]" value="1" <?php checked( $localia_s['language_only'] ); ?>> <?php esc_html_e( 'Tell my PC to disregard prompts in other languages (extra security)', 'localia' ); ?></label></p>
				<p class="description"><?php esc_html_e( 'Localia writes its own instructions to the AI in this language. Write your task instructions and “Voice and style” in it too. The checkbox is sent to the agent when you download it (or copy the pairing code); after that, the setting on your PC is the one that counts, so a hacked site can’t switch it off.', 'localia' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="localia-default-status"><?php esc_html_e( 'New tasks save posts as', 'localia' ); ?></label></th>
			<td>
				<select id="localia-default-status" name="localia[default_status]">
					<option value="draft" <?php selected( $localia_s['default_status'], 'draft' ); ?>><?php esc_html_e( 'Draft (recommended)', 'localia' ); ?></option>
					<option value="pending" <?php selected( $localia_s['default_status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'localia' ); ?></option>
					<option value="publish" <?php selected( $localia_s['default_status'], 'publish' ); ?>><?php esc_html_e( 'Published', 'localia' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="localia-default-author"><?php esc_html_e( 'Default author', 'localia' ); ?></label></th>
			<td>
				<?php
				wp_dropdown_users(
					array(
						'name'              => 'localia[default_author]',
						'id'                => 'localia-default-author',
						'selected'          => (int) $localia_s['default_author'],
						'show_option_none'  => __( '— Site administrator —', 'localia' ),
						'option_none_value' => 0,
						'capability'        => array( 'edit_posts' ),
					)
				);
				?>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Advanced', 'localia' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="localia-agent-id"><?php esc_html_e( 'Agent ID', 'localia' ); ?></label></th>
			<td><input type="text" id="localia-agent-id" name="localia[agent_id]" class="regular-text code" value="<?php echo esc_attr( $localia_s['agent_id'] ); ?>"></td>
		</tr>
		<tr>
			<th scope="row"><label for="localia-lease"><?php esc_html_e( 'Max time per job', 'localia' ); ?></label></th>
			<td>
				<input type="number" min="5" max="240" id="localia-lease" name="localia[lease_minutes]" class="small-text" value="<?php echo esc_attr( $localia_s['lease_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'localia' ); ?>
				<p class="description"><?php esc_html_e( 'If your PC has not answered by then (for example it went to sleep), the job is offered again once.', 'localia' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="localia-log-days"><?php esc_html_e( 'Keep history for', 'localia' ); ?></label></th>
			<td><input type="number" min="1" max="365" id="localia-log-days" name="localia[log_days]" class="small-text" value="<?php echo esc_attr( $localia_s['log_days'] ); ?>"> <?php esc_html_e( 'days', 'localia' ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Uninstall', 'localia' ); ?></th>
			<td><label><input type="checkbox" name="localia[delete_on_uninstall]" value="1" <?php checked( $localia_s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all Localia data (tasks, history, settings) when the plugin is deleted', 'localia' ); ?></label></td>
		</tr>
	</table>

	<?php submit_button( __( 'Save settings', 'localia' ) ); ?>
</form>
