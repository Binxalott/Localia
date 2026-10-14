<?php
/**
 * Builds the prompt for each task type and applies the AI's answer to WordPress.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_Actions {

	const TITLE_FORMAT = "\n\nReply in exactly this format and nothing else:\nTITLE: <a short, catchy post title>\n\n<the post body in Markdown, using ## for section headings>";
	const BODY_FORMAT  = "\n\nReply with only the post body in Markdown (use ## for section headings). Do not include a title, a preamble or any notes.";

	/** Placeholders available in every prompt. */
	public static function base_vars() {
		return array(
			'site_name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_tagline' => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'date'         => wp_date( get_option( 'date_format' ) ),
		);
	}

	/**
	 * Build the queue job for one run of a task.
	 *
	 * @return array|WP_Error ['job' => queue input, 'state' => new task state]
	 */
	public static function build_job( array $task ) {
		$custom = apply_filters( 'localia_build_job', null, $task );
		if ( null !== $custom ) {
			return $custom;
		}
		if ( 'rewrite_post' === $task['type'] ) {
			return self::build_rewrite( $task );
		}
		return self::build_create( $task );
	}

	private static function build_create( array $task ) {
		$c     = $task['config'];
		$state = $task['state'];
		$vars  = self::base_vars();

		$topics = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $c['topics'] ) ) ) );
		$topic  = '';
		if ( $topics ) {
			if ( 'random' === $c['topic_mode'] ) {
				$topic = $topics[ wp_rand( 0, count( $topics ) - 1 ) ];
			} else {
				$index                = isset( $state['topic_index'] ) ? (int) $state['topic_index'] : 0;
				$topic                = $topics[ $index % count( $topics ) ];
				$state['topic_index'] = ( $index + 1 ) % count( $topics );
			}
		}
		$vars['topic'] = $topic;

		$cat_name         = $c['category'] ? get_cat_name( $c['category'] ) : '';
		$vars['category'] = $cat_name;

		$recent = '';
		if ( $c['avoid_repeats'] ) {
			$titles = wp_list_pluck(
				get_posts(
					array(
						'numberposts' => 15,
						'post_status' => array( 'publish', 'draft', 'pending', 'future' ),
						'category'    => $c['category'],
						'fields'      => 'all',
					)
				),
				'post_title'
			);
			foreach ( array_filter( $titles ) as $t ) {
				$recent .= '- ' . $t . "\n";
			}
			$recent = trim( $recent );
		}
		$vars['recent_titles'] = $recent;

		$prompt = Localia_Content::fill( $c['prompt'], $vars );
		if ( '' !== $topic && false === strpos( $c['prompt'], '{topic}' ) ) {
			$prompt .= "\n\nTopic: " . $topic;
		}
		if ( '' !== $recent && false === strpos( $c['prompt'], '{recent_titles}' ) ) {
			$prompt .= "\n\nThese posts already exist on the site, so pick a different angle and title:\n" . $recent;
		}
		$prompt .= '' === $c['title'] ? self::TITLE_FORMAT : self::BODY_FORMAT;

		return array(
			'job'   => array(
				'prompt' => $prompt,
				'system' => (string) Localia_Settings::get( 'site_instructions' ),
				'meta'   => array(
					'source'  => 'task',
					'task_id' => $task['id'],
					'type'    => 'create_post',
					'topic'   => $topic,
				),
			),
			'state' => $state,
		);
	}

	private static function build_rewrite( array $task ) {
		$c    = $task['config'];
		$post = null;
		if ( 'post' === $c['target'] ) {
			$post = get_post( $c['post_id'] );
		} else {
			$found = get_posts(
				array(
					'numberposts' => 1,
					'post_status' => 'publish',
					'category'    => $c['category'],
					'orderby'     => 'modified',
					'order'       => 'ASC',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array(
							'key'     => '_localia_rewritten_at',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_localia_rewritten_at',
							'value'   => time() - DAY_IN_SECONDS * (int) $c['skip_days'],
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				)
			);
			$post  = $found ? $found[0] : null;
		}
		if ( ! $post ) {
			return new WP_Error( 'no_post', __( 'no post matched (nothing left to rewrite right now).', 'localia' ) );
		}

		$vars               = self::base_vars();
		$vars['post_title'] = $post->post_title;
		$text               = Localia_Content::post_to_prompt_text( $post->post_content );
		$prompt             = Localia_Content::fill( $c['instructions'], $vars )
			. "\n\nPost title: " . $post->post_title
			. "\n\nCurrent post:\n" . $text
			. self::BODY_FORMAT;

		return array(
			'job'   => array(
				'prompt' => $prompt,
				'system' => (string) Localia_Settings::get( 'site_instructions' ),
				'meta'   => array(
					'source'  => 'task',
					'task_id' => $task['id'],
					'type'    => 'rewrite_post',
					'post_id' => (int) $post->ID,
				),
			),
			'state' => $task['state'],
		);
	}

	/**
	 * Job for the "Generate with my AI" box in the post editor.
	 *
	 * @param string $instruction What to write.
	 * @param int    $post_id     Post being edited (0 for none).
	 * @param string $title       Current (unsaved) title, optional context.
	 * @param string $content     Current (unsaved) content, optional context.
	 */
	public static function build_editor_job( $instruction, $post_id, $title = '', $content = '' ) {
		$prompt = $instruction;
		$text   = Localia_Content::post_to_prompt_text( $content );
		if ( '' !== trim( $title . $text ) ) {
			$prompt .= "\n\nFor context, here is the post so far.\nTitle: " . $title . "\n\n" . $text;
		}
		$prompt .= "\n\nReply with only the requested content in Markdown, without a preamble or notes.";
		return array(
			'prompt'       => $prompt,
			'system'       => (string) Localia_Settings::get( 'site_instructions' ),
			'priority'     => 10, // someone is waiting in the editor
			'max_attempts' => 1,
			'meta'         => array(
				'source'  => 'editor',
				'user_id' => get_current_user_id(),
				'post_id' => (int) $post_id,
			),
		);
	}

	/** Called once when a job reaches done/error. */
	public static function handle_finished( $job ) {
		if ( ! $job ) {
			return;
		}
		$meta = $job['meta'];
		do_action( 'localia_job_finished', $job );
		if ( ! isset( $meta['source'] ) || 'task' !== $meta['source'] ) {
			return;
		}

		$task_id = isset( $meta['task_id'] ) ? (int) $meta['task_id'] : 0;
		$task    = Localia_Tasks::get( $task_id );
		$name    = $task ? $task['name'] : '#' . $task_id;
		$ctx     = array(
			'task_id' => $task_id,
			'job_id'  => $job['id'],
		);

		if ( 'done' !== $job['status'] ) {
			/* translators: 1: task name, 2: error message */
			Localia_Log::add( 'error', sprintf( __( '"%1$s" failed: %2$s', 'localia' ), $name, (string) $job['error'] ), $ctx );
			if ( $task ) {
				Localia_Tasks::update_fields( $task_id, array( 'last_status' => 'error' ) );
			}
			return;
		}

		try {
			$type    = isset( $meta['type'] ) ? $meta['type'] : '';
			$post_id = 'rewrite_post' === $type ? self::apply_rewrite( $job, $task ) : self::apply_create( $job, $task );
		} catch ( Exception $e ) {
			$post_id = new WP_Error( 'exception', $e->getMessage() );
		}

		if ( is_wp_error( $post_id ) ) {
			/* translators: 1: task name, 2: error message */
			Localia_Log::add( 'error', sprintf( __( '"%1$s": could not save the result: %2$s', 'localia' ), $name, $post_id->get_error_message() ), $ctx );
			if ( $task ) {
				Localia_Tasks::update_fields( $task_id, array( 'last_status' => 'error' ) );
			}
			return;
		}

		$ctx['post_id'] = $post_id;
		$verb           = 'rewrite_post' === $type ? __( 'updated', 'localia' ) : __( 'created', 'localia' );
		/* translators: 1: task name, 2: "created" or "updated", 3: post title */
		Localia_Log::add( 'success', sprintf( __( '"%1$s" %2$s "%3$s".', 'localia' ), $name, $verb, get_post_field( 'post_title', $post_id ) ), $ctx );
		if ( $task ) {
			Localia_Tasks::update_fields( $task_id, array( 'last_status' => 'done' ) );
		}
	}

	private static function apply_create( array $job, $task ) {
		$c = $task ? $task['config'] : Localia_Tasks::default_config( 'create_post' );
		if ( '' !== $c['title'] ) {
			$title = Localia_Content::fill(
				$c['title'],
				array_merge(
					self::base_vars(),
					array( 'topic' => isset( $job['meta']['topic'] ) ? $job['meta']['topic'] : '' )
				)
			);
			$body  = $job['response'];
		} else {
			list( $title, $body ) = Localia_Content::split_title( $job['response'] );
		}
		$html = Localia_Content::to_html( $body );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error( 'empty', __( 'the AI returned an empty post.', 'localia' ) );
		}
		if ( '' === $title ) {
			$title = wp_trim_words( wp_strip_all_tags( $html ), 8, '' );
		}

		$author = (int) $c['author'];
		if ( ! $author || ! get_userdata( $author ) ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			$author = $admins ? (int) $admins[0] : 0;
		}

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $html,
			'post_status'  => $c['post_status'],
			'post_author'  => $author,
			'post_type'    => 'post',
			'meta_input'   => array(
				'_localia_job'  => $job['id'],
				'_localia_task' => $task ? $task['id'] : 0,
			),
		);
		if ( $c['category'] ) {
			$postarr['post_category'] = array( (int) $c['category'] );
		}
		if ( '' !== $c['tags'] ) {
			$postarr['tags_input'] = array_filter( array_map( 'trim', explode( ',', $c['tags'] ) ) );
		}
		return wp_insert_post( wp_slash( $postarr ), true );
	}

	private static function apply_rewrite( array $job, $task ) {
		$post = get_post( isset( $job['meta']['post_id'] ) ? (int) $job['meta']['post_id'] : 0 );
		if ( ! $post ) {
			return new WP_Error( 'missing', __( 'the post no longer exists.', 'localia' ) );
		}
		$html = Localia_Content::to_html( Localia_Content::split_title( $job['response'], false )[1] );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error( 'empty', __( 'the AI returned an empty post.', 'localia' ) );
		}
		$mode = $task ? $task['config']['save_mode'] : 'replace';

		if ( 'draft_copy' === $mode ) {
			$new_id = wp_insert_post(
				wp_slash(
					array(
						/* translators: %s: original post title */
						'post_title'    => sprintf( __( '%s (AI rewrite)', 'localia' ), $post->post_title ),
						'post_content'  => $html,
						'post_status'   => 'draft',
						'post_author'   => $post->post_author,
						'post_type'     => $post->post_type,
						'post_category' => wp_get_post_categories( $post->ID ),
						'tags_input'    => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
						'meta_input'    => array(
							'_localia_job'        => $job['id'],
							'_localia_rewrite_of' => $post->ID,
						),
					)
				),
				true
			);
			if ( ! is_wp_error( $new_id ) ) {
				update_post_meta( $post->ID, '_localia_rewritten_at', time() );
			}
			return $new_id;
		}

		// Replace in place; WordPress keeps the old version under Revisions.
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $html,
				)
			),
			true
		);
		if ( ! is_wp_error( $result ) ) {
			update_post_meta( $post->ID, '_localia_rewritten_at', time() );
			update_post_meta( $post->ID, '_localia_job', $job['id'] );
		}
		return $result;
	}
}
