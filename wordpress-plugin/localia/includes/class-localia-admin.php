<?php
/**
 * Admin pages: Dashboard, Tasks, Schedule, Settings, Free vs Pro.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_Admin {

	const SLUG = 'localia';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LOCALIA_FILE ), array( __CLASS__, 'action_links' ) );
		foreach ( array( 'save_settings', 'regenerate_secret', 'save_task', 'task_action', 'clear_log' ) as $action ) {
			add_action( 'admin_post_localia_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	public static function tabs() {
		return apply_filters(
			'localia_admin_tabs',
			array(
				'dashboard' => __( 'Dashboard', 'localia' ),
				'tasks'     => __( 'Tasks', 'localia' ),
				'schedule'  => __( 'Schedule', 'localia' ),
				'settings'  => __( 'Settings', 'localia' ),
				'pro'       => __( 'Free vs Pro', 'localia' ),
			)
		);
	}

	public static function url( $tab = 'dashboard', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	public static function menu() {
		add_menu_page( __( 'Localia', 'localia' ), __( 'Localia', 'localia' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ), 'dashicons-desktop', 58 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'localia' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'localia-admin', LOCALIA_URL . 'assets/admin.css', array(), LOCALIA_VERSION );
		wp_enqueue_script( 'localia-admin', LOCALIA_URL . 'assets/admin.js', array(), LOCALIA_VERSION, true );
		wp_localize_script(
			'localia-admin',
			'LocaliaAdmin',
			array(
				'i18n' => array(
					'confirmRegenerate' => __( 'Create a new secret? Your PC will stop working until you download the agent again (or paste the new pairing code).', 'localia' ),
					'confirmDelete'     => __( 'Delete this task?', 'localia' ),
					'copied'            => __( 'Copied!', 'localia' ),
					'removeTime'        => __( 'Remove', 'localia' ),
				),
			)
		);
	}

	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'dashboard';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab = self::current_tab();
		?>
		<div class="wrap localia-wrap">
			<h1 class="localia-title"><span class="dashicons dashicons-desktop" aria-hidden="true"></span> <?php esc_html_e( 'Localia', 'localia' ); ?>
				<?php self::render_status_badge(); ?>
			</h1>
			<nav class="nav-tab-wrapper localia-tabs">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?> <?php echo 'pro' === $key ? 'localia-tab-pro' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php self::render_notice(); ?>
			<div class="localia-body">
				<?php
				$file = LOCALIA_DIR . 'admin/views/' . $tab . '.php';
				if ( file_exists( $file ) ) {
					include $file;
				} else {
					do_action( 'localia_render_tab_' . $tab );
				}
				?>
			</div>
		</div>
		<?php
	}

	public static function render_status_badge() {
		$s = Localia_Settings::agent_status();
		if ( ! $s['last_seen'] ) {
			echo '<span class="localia-badge is-off">' . esc_html__( 'PC not connected', 'localia' ) . '</span>';
		} elseif ( $s['online'] ) {
			echo '<span class="localia-badge is-on">' . esc_html__( 'PC online', 'localia' ) . '</span>';
		} else {
			/* translators: %s: time since the PC was last seen, e.g. "3 hours" */
			echo '<span class="localia-badge is-warn">' . esc_html( sprintf( __( 'PC offline · seen %s ago', 'localia' ), human_time_diff( $s['last_seen'] ) ) ) . '</span>';
		}
	}

	// ------------------------------------------------------------------ notices

	private static function messages() {
		return array(
			'settings_saved' => array( 'success', __( 'Settings saved.', 'localia' ) ),
			'secret_new'     => array( 'warning', __( 'New secret created. Download the agent again (or paste the new pairing code) on your PC.', 'localia' ) ),
			'task_saved'     => array( 'success', __( 'Task saved.', 'localia' ) ),
			'task_queued'    => array( 'success', __( 'Task queued. Your PC will pick it up on its next check.', 'localia' ) ),
			'task_deleted'   => array( 'success', __( 'Task deleted.', 'localia' ) ),
			'task_toggled'   => array( 'success', __( 'Task updated.', 'localia' ) ),
			'log_cleared'    => array( 'success', __( 'Log cleared.', 'localia' ) ),
		);
	}

	private static function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$code = isset( $_GET['localia_msg'] ) ? sanitize_key( wp_unslash( $_GET['localia_msg'] ) ) : '';
		// phpcs:enable
		$messages = self::messages();
		$error    = get_transient( 'localia_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'localia_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}
	}

	private static function redirect( $tab, $msg = '', array $args = array() ) {
		if ( $msg ) {
			$args['localia_msg'] = $msg;
		}
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	private static function fail( $tab, $message, array $args = array() ) {
		set_transient( 'localia_error_' . get_current_user_id(), $message, 60 );
		self::redirect( $tab, '', $args );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'localia' ), 403 );
		}
		check_admin_referer( $action );
	}

	// ------------------------------------------------------------------ handlers

	public static function handle_save_settings() {
		self::guard( 'localia_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$in = isset( $_POST['localia'] ) ? wp_unslash( (array) $_POST['localia'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		// phpcs:enable
		$status = isset( $in['default_status'] ) ? sanitize_key( $in['default_status'] ) : 'draft';
		$agent  = isset( $in['agent_id'] ) ? sanitize_key( $in['agent_id'] ) : 'home-pc';
		Localia_Settings::update(
			array(
				'agent_id'            => '' !== $agent ? $agent : 'home-pc',
				'site_instructions'   => isset( $in['site_instructions'] ) ? sanitize_textarea_field( $in['site_instructions'] ) : '',
				'default_status'      => in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ? $status : 'draft',
				'default_author'      => isset( $in['default_author'] ) ? absint( $in['default_author'] ) : 0,
				'lease_minutes'       => isset( $in['lease_minutes'] ) ? max( 5, min( 240, absint( $in['lease_minutes'] ) ) ) : 30,
				'log_days'            => isset( $in['log_days'] ) ? max( 1, min( 365, absint( $in['log_days'] ) ) ) : 30,
				'delete_on_uninstall' => ! empty( $in['delete_on_uninstall'] ),
			)
		);
		self::redirect( 'settings', 'settings_saved' );
	}

	public static function handle_regenerate_secret() {
		self::guard( 'localia_regenerate_secret' );
		Localia_Settings::regenerate_secret();
		Localia_Log::add( 'warning', __( 'A new agent secret was created. The PC must be set up again.', 'localia' ) );
		self::redirect( 'settings', 'secret_new' );
	}

	public static function handle_save_task() {
		self::guard( 'localia_save_task' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); sanitized in Localia_Tasks::save().
		$data = isset( $_POST['task'] ) ? wp_unslash( (array) $_POST['task'] ) : array();
		$id   = Localia_Tasks::save( $data );
		if ( is_wp_error( $id ) ) {
			set_transient( 'localia_task_draft_' . get_current_user_id(), $data, 300 );
			$args = array( 'action' => 'edit' );
			if ( ! empty( $data['id'] ) ) {
				$args['task'] = absint( $data['id'] );
			}
			self::fail( 'tasks', $id->get_error_message(), $args );
		}
		if ( ! empty( $_POST['run_now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$result = Localia_Scheduler::run_task( Localia_Tasks::get( $id ), 'manual' );
			if ( is_wp_error( $result ) ) {
				self::fail( 'tasks', $result->get_error_message() );
			}
			self::redirect( 'tasks', 'task_queued' );
		}
		self::redirect( 'tasks', 'task_saved' );
	}

	public static function handle_task_action() {
		self::guard( 'localia_task_action' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified in guard().
		$id   = isset( $_GET['task'] ) ? absint( $_GET['task'] ) : 0;
		$what = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		// phpcs:enable
		$task = Localia_Tasks::get( $id );
		if ( ! $task ) {
			self::fail( 'tasks', __( 'Task not found.', 'localia' ) );
		}
		switch ( $what ) {
			case 'run':
				$result = Localia_Scheduler::run_task( $task, 'manual' );
				if ( is_wp_error( $result ) ) {
					self::fail( 'tasks', $result->get_error_message() );
				}
				self::redirect( 'tasks', 'task_queued' );
				break;
			case 'enable':
			case 'disable':
				Localia_Tasks::set_enabled( $id, 'enable' === $what );
				self::redirect( 'tasks', 'task_toggled' );
				break;
			case 'delete':
				Localia_Tasks::delete( $id );
				self::redirect( 'tasks', 'task_deleted' );
				break;
		}
		self::redirect( 'tasks' );
	}

	public static function handle_clear_log() {
		self::guard( 'localia_clear_log' );
		Localia_Log::clear();
		self::redirect( 'dashboard', 'log_cleared' );
	}

	public static function task_action_url( $id, $what ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'localia_task_action',
					'task'   => $id,
					'do'     => $what,
				),
				admin_url( 'admin-post.php' )
			),
			'localia_task_action'
		);
	}

	// ------------------------------------------------------------------ small view helpers

	public static function time_ago( $ts ) {
		if ( ! $ts ) {
			return '—';
		}
		$diff = time() - $ts;
		/* translators: %s: human time difference */
		return $diff >= 0 ? sprintf( __( '%s ago', 'localia' ), human_time_diff( $ts ) ) : sprintf( __( 'in %s', 'localia' ), human_time_diff( $ts ) );
	}

	public static function datetime( $ts ) {
		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—';
	}

	public static function status_pill( $status ) {
		$labels = array(
			'queued'  => __( 'Waiting', 'localia' ),
			'done'    => __( 'Done', 'localia' ),
			'error'   => __( 'Failed', 'localia' ),
			'skipped' => __( 'Skipped', 'localia' ),
		);
		if ( ! $status || ! isset( $labels[ $status ] ) ) {
			return '<span class="localia-pill">' . esc_html__( 'Never run', 'localia' ) . '</span>';
		}
		return '<span class="localia-pill is-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
	}
}
