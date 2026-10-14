<?php
/**
 * "Generate with my AI" in the post editor: a Post-sidebar panel in the block editor,
 * and a meta box in the classic editor.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_Editor {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	private static function post_types() {
		return array_values(
			array_filter(
				get_post_types( array( 'show_ui' => true ) ),
				function ( $type ) {
					return post_type_supports( $type, 'editor' ) && ! in_array( $type, array( 'attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ), true );
				}
			)
		);
	}

	public static function add_box() {
		add_meta_box(
			'localia-ai',
			__( 'Localia – your AI', 'localia' ),
			array( __CLASS__, 'render' ),
			self::post_types(),
			'side',
			'high',
			// The block editor gets a native sidebar panel instead (see assets/editor.js).
			array( '__back_compat_meta_box' => true )
		);
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::post_types(), true ) ) {
			return;
		}
		$deps = array( 'wp-api-fetch' );
		if ( $screen->is_block_editor() ) {
			$deps = array_merge( $deps, array( 'wp-plugins', 'wp-element', 'wp-data', 'wp-blocks', 'wp-editor' ) );
		}
		wp_enqueue_style( 'localia-admin', LOCALIA_URL . 'assets/admin.css', array(), LOCALIA_VERSION );
		wp_enqueue_script( 'localia-editor', LOCALIA_URL . 'assets/editor.js', $deps, LOCALIA_VERSION, true );

		$status = Localia_Settings::agent_status();
		wp_localize_script(
			'localia-editor',
			'LocaliaEditor',
			array(
				'connected'   => (bool) $status['last_seen'],
				'settingsUrl' => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=localia&tab=settings' ) : '',
				'i18n'        => array(
					'panelTitle'   => __( 'Localia – your AI', 'localia' ),
					'label'        => __( 'What should the AI write?', 'localia' ),
					'placeholder'  => __( 'e.g. Write an introduction for this post, or: Suggest 5 FAQ questions with answers', 'localia' ),
					'useContext'   => __( 'Give the AI this post’s current text as context', 'localia' ),
					'generate'     => __( 'Generate with my AI', 'localia' ),
					'insert'       => __( 'Insert at end', 'localia' ),
					'replace'      => __( 'Replace content', 'localia' ),
					'copy'         => __( 'Copy', 'localia' ),
					'notConnected' => __( 'Your PC is not connected yet.', 'localia' ),
					'setUp'        => __( 'Set it up', 'localia' ),
					'empty'        => __( 'Tell the AI what to write first.', 'localia' ),
					'queued'       => __( 'Waiting for your PC to pick this up…', 'localia' ),
					'offline'      => __( 'Your PC is offline right now. The request will wait until the Localia agent connects.', 'localia' ),
					'working'      => __( 'Your PC is writing… (this can take a minute)', 'localia' ),
					'done'         => __( 'Done. Review it below.', 'localia' ),
					'failed'       => __( 'The AI could not finish this request:', 'localia' ),
					'copied'       => __( 'Copied.', 'localia' ),
					'confirmRepl'  => __( 'Replace the whole post content with this text?', 'localia' ),
				),
			)
		);
	}

	/** Classic editor meta box; editor.js builds the UI inside. */
	public static function render() {
		echo '<div class="localia-editor-root"></div>';
	}
}
