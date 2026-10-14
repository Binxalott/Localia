<?php
/**
 * Turns LLM output into WordPress post content.
 *
 * Local models are best at Markdown, so we ask for Markdown and convert it here.
 * If a model answers in HTML anyway, that is accepted (and sanitized) too.
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_Content {

	/**
	 * Split "TITLE: ...\n\nbody" into title and body.
	 *
	 * @param string $text          LLM output.
	 * @param bool   $allow_heading Also treat a leading "# Heading" or "**Bold**" line as the title.
	 *                              Off for rewrites, where the body may legitimately start with a heading.
	 * @return array{0:string,1:string}
	 */
	public static function split_title( $text, $allow_heading = true ) {
		$text  = self::unwrap_fence( trim( (string) $text ) );
		$lines = preg_split( '/\R/', $text );
		$title = '';
		while ( $lines && '' === trim( $lines[0] ) ) {
			array_shift( $lines );
		}
		if ( $lines ) {
			$first = trim( $lines[0] );
			if ( preg_match( '/^(?:\*\*)?(?:title|titel|t[ií]tulo|titre|titolo)(?:\*\*)?\s*[:\-]\s*(.+)$/iu', $first, $m ) ) {
				$title = $m[1];
			} elseif ( $allow_heading && preg_match( '/^#{1,2}\s+(.+)$/', $first, $m ) ) {
				$title = $m[1];
			} elseif ( $allow_heading && preg_match( '/^\*\*(.+)\*\*$/', $first, $m ) ) {
				$title = $m[1];
			}
			if ( '' !== $title ) {
				array_shift( $lines );
			}
		}
		$title = trim( wp_strip_all_tags( str_replace( array( '**', '__' ), '', $title ) ), " \t\"'" );
		return array( $title, trim( implode( "\n", $lines ) ) );
	}

	/** Remove a ```lang ... ``` fence wrapped around the whole answer. */
	public static function unwrap_fence( $text ) {
		if ( preg_match( '/^```[a-zA-Z]*\R(.*)\R```$/s', trim( $text ), $m ) ) {
			return $m[1];
		}
		return $text;
	}

	/** LLM output (Markdown or HTML) to safe post HTML. */
	public static function to_html( $text ) {
		$text = self::unwrap_fence( trim( (string) $text ) );
		if ( preg_match( '/<(p|h[1-6]|ul|ol|blockquote|div|table)[\s>]/i', $text ) ) {
			return wp_kses_post( $text );
		}
		return wp_kses_post( self::markdown( $text ) );
	}

	/** Small Markdown subset: headings, paragraphs, lists, quotes, code blocks, rules, inline styles, links. */
	public static function markdown( $md ) {
		$md    = str_replace( "\r\n", "\n", $md );
		$lines = explode( "\n", $md );
		$html  = array();
		$para  = array();
		$list  = null; // Open list tag (ul or ol), if any.
		$quote = array();
		$code  = null;

		$flush_para  = function () use ( &$para, &$html ) {
			if ( $para ) {
				$html[] = '<p>' . self::inline( implode( ' ', $para ) ) . '</p>';
				$para   = array();
			}
		};
		$flush_list  = function () use ( &$list, &$html ) {
			if ( $list ) {
				$html[] = '</' . $list . '>';
				$list   = null;
			}
		};
		$flush_quote = function () use ( &$quote, &$html ) {
			if ( $quote ) {
				$html[] = '<blockquote><p>' . self::inline( implode( ' ', $quote ) ) . '</p></blockquote>';
				$quote  = array();
			}
		};

		foreach ( $lines as $line ) {
			if ( null !== $code ) {
				if ( preg_match( '/^```/', trim( $line ) ) ) {
					$html[] = '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
					$code   = null;
				} else {
					$code[] = $line;
				}
				continue;
			}
			$trim = trim( $line );
			if ( preg_match( '/^```/', $trim ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$code = array();
				continue;
			}
			if ( '' === $trim ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				continue;
			}
			if ( preg_match( '/^(#{1,6})\s+(.+?)\s*#*$/', $trim, $m ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$level  = max( 2, strlen( $m[1] ) ); // h1 is the post title
				$html[] = "<h{$level}>" . self::inline( $m[2] ) . "</h{$level}>";
				continue;
			}
			if ( preg_match( '/^([-*_])\1{2,}$/', str_replace( ' ', '', $trim ) ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$html[] = '<hr />';
				continue;
			}
			if ( preg_match( '/^>\s?(.*)$/', $trim, $m ) ) {
				$flush_para();
				$flush_list();
				$quote[] = $m[1];
				continue;
			}
			if ( preg_match( '/^(?:[-*+])\s+(.+)$/', $trim, $m ) || preg_match( '/^\d+[.)]\s+(.+)$/', $trim, $m2 ) ) {
				$type = isset( $m[1] ) ? 'ul' : 'ol';
				$item = isset( $m[1] ) ? $m[1] : $m2[1];
				$flush_para();
				$flush_quote();
				if ( $list !== $type ) {
					$flush_list();
					$html[] = '<' . $type . '>';
					$list   = $type;
				}
				$html[] = '<li>' . self::inline( $item ) . '</li>';
				continue;
			}
			if ( $list ) {
				$flush_list();
			}
			$para[] = $trim;
		}
		if ( null !== $code ) {
			$html[] = '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
		}
		$flush_para();
		$flush_list();
		$flush_quote();
		return implode( "\n\n", $html );
	}

	private static function inline( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*(.+?)\*\*|__(.+?)__/', '<strong>$1$2</strong>', $text );
		$text = preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])|(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/', '<em>$1$2</em>', $text );
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			function ( $m ) {
				return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '">' . $m[1] . '</a>';
			},
			$text
		);
		return $text;
	}

	/** Post content as plain-ish text for a prompt (drops block comments and tags, keeps line breaks). */
	public static function post_to_prompt_text( $content ) {
		$content = preg_replace( '/<!--.*?-->/s', '', (string) $content );
		$content = preg_replace( '/<\/(p|h[1-6]|li|blockquote|pre|div)>/i', "\n\n", $content );
		$content = preg_replace( '/<br\s*\/?>/i', "\n", $content );
		$content = preg_replace( '/<h([1-6])[^>]*>/i', '## ', $content );
		$content = preg_replace( '/<li[^>]*>/i', '- ', $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $content ) );
	}

	/** Replace {placeholders} in a prompt template. */
	public static function fill( $template, array $vars ) {
		return strtr(
			(string) $template,
			array_combine(
				array_map(
					function ( $k ) {
						return '{' . $k . '}';
					},
					array_keys( $vars )
				),
				array_values( $vars )
			)
		);
	}
}
