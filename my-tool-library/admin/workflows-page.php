<?php
/**
 * Workflows admin page.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub-style heading slug: lowercase, strip everything except letters,
 * digits, spaces and existing hyphens, then turn spaces into hyphens
 * (without collapsing runs, e.g. "In-Person & Verifying" -> the "&" is
 * dropped but its surrounding spaces both survive as separate hyphens:
 * "in-person--verifying"). This has to match GitHub's algorithm exactly so
 * the file's own table-of-contents links (e.g. "#1-initial-setup") keep
 * working when rendered here.
 *
 * @param string $text Raw heading text (before any inline HTML is added).
 * @return string Slug suitable for an id="" attribute.
 */
function mtl_markdown_slug( $text ) {
	$slug = strtolower( wp_strip_all_tags( (string) $text ) );
	$slug = preg_replace( '/[^a-z0-9 -]/', '', $slug );
	return str_replace( ' ', '-', $slug );
}

/**
 * Renders inline Markdown (bold, italic, inline code, links) within a single
 * block of text, escaping everything else. Code spans and links are pulled
 * out of the RAW text first and replaced with placeholder tokens, so their
 * contents (which may contain underscores, asterisks, or "&") are never
 * mistaken for other inline syntax or double-escaped; the remaining prose is
 * then escaped and has bold/italic markup applied, and finally the tokens
 * are swapped back in as already-escaped HTML.
 *
 * @param string $text Raw Markdown text (a single logical line/paragraph).
 * @return string Escaped HTML.
 */
function mtl_markdown_inline( $text ) {
	$tokens = array();

	$text = preg_replace_callback(
		'/`([^`]+)`/',
		function ( $m ) use ( &$tokens ) {
			$key            = "\x01" . count( $tokens ) . "\x01";
			$tokens[ $key ] = '<code>' . esc_html( $m[1] ) . '</code>';
			return $key;
		},
		(string) $text
	);

	$text = preg_replace_callback(
		'/\[([^\]]+)\]\(([^)]+)\)/',
		function ( $m ) use ( &$tokens ) {
			$key            = "\x01" . count( $tokens ) . "\x01";
			$tokens[ $key ] = '<a href="' . esc_url( trim( $m[2] ) ) . '">' . esc_html( $m[1] ) . '</a>';
			return $key;
		},
		$text
	);

	$text = esc_html( $text );
	$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
	$text = preg_replace( '/(?<![a-zA-Z0-9])_([^_]+)_(?![a-zA-Z0-9])/', '<em>$1</em>', $text );

	return strtr( $text, $tokens );
}

/**
 * Converts a constrained subset of Markdown -- headings, horizontal rules,
 * blockquotes, ordered/unordered lists (with basic nesting by indentation),
 * paragraphs, and inline bold/italic/code/links -- to HTML. This is NOT a
 * general-purpose CommonMark parser; it's a small, dependency-free renderer
 * sized specifically for this plugin's own documentation/staff-workflows.md
 * (see mtl_render_workflows_page()), matching the plugin's no-3rd-party-
 * dependency rule (see the font presets in my-tool-library.php for the same
 * rule applied elsewhere).
 *
 * @param string $markdown Raw Markdown source.
 * @return string HTML. Every heading gets a slug id (see mtl_markdown_slug())
 *         so the file's own table-of-contents anchor links keep working.
 */
function mtl_markdown_to_html( $markdown ) {
	$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $markdown ) );

	$html         = '';
	$list_stack   = array(); // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- describes the array shape, not commented-out code: each entry is array( 'type' => 'ul'|'ol', 'indent' => int ).
	$para_buffer  = array();
	$quote_buffer = array();

	foreach ( $lines as $line ) {
		// Blank line: ends whatever block is currently accumulating.
		if ( '' === trim( $line ) ) {
			if ( ! empty( $para_buffer ) ) {
				$html       .= '<p>' . mtl_markdown_inline( implode( ' ', $para_buffer ) ) . '</p>';
				$para_buffer = array();
			}
			if ( ! empty( $quote_buffer ) ) {
				$html        .= '<blockquote><p>' . mtl_markdown_inline( implode( ' ', $quote_buffer ) ) . '</p></blockquote>';
				$quote_buffer = array();
			}
			while ( ! empty( $list_stack ) ) {
				$closing = array_pop( $list_stack );
				$html   .= '</' . $closing['type'] . '>';
			}
			continue;
		}

		// Heading.
		if ( preg_match( '/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m ) ) {
			$level = strlen( $m[1] );
			$text  = trim( $m[2] );
			$html .= '<h' . $level . ' id="' . esc_attr( mtl_markdown_slug( $text ) ) . '">' . mtl_markdown_inline( $text ) . '</h' . $level . '>';
			continue;
		}

		// Horizontal rule: 3+ of the same -, * or _ and nothing else on the line.
		if ( preg_match( '/^ {0,3}(-{3,}|\*{3,}|_{3,})\s*$/', $line ) ) {
			$html .= '<hr>';
			continue;
		}

		// Blockquote.
		if ( preg_match( '/^>\s?(.*)$/', $line, $m ) ) {
			$quote_buffer[] = $m[1];
			continue;
		}
		if ( ! empty( $quote_buffer ) ) {
			$html        .= '<blockquote><p>' . mtl_markdown_inline( implode( ' ', $quote_buffer ) ) . '</p></blockquote>';
			$quote_buffer = array();
		}

		// Ordered ("1. ") or unordered ("- "/"* "/"+ ") list item, with
		// indentation-based nesting via $list_stack.
		if ( preg_match( '/^( *)(\d+\.|[-*+])\s+(.+)$/', $line, $m ) ) {
			if ( ! empty( $para_buffer ) ) {
				$html       .= '<p>' . mtl_markdown_inline( implode( ' ', $para_buffer ) ) . '</p>';
				$para_buffer = array();
			}
			$indent = strlen( $m[1] );
			$type   = ( '.' === substr( $m[2], -1 ) ) ? 'ol' : 'ul';

			while ( ! empty( $list_stack ) ) {
				$top = end( $list_stack );
				if ( $top['indent'] > $indent || ( $top['indent'] === $indent && $top['type'] !== $type ) ) {
					array_pop( $list_stack );
					$html .= '</' . $top['type'] . '>';
				} else {
					break;
				}
			}
			$top = end( $list_stack );
			if ( false === $top || $top['indent'] < $indent ) {
				$list_stack[] = array(
					'type'   => $type,
					'indent' => $indent,
				);
				$html        .= '<' . $type . '>';
			}
			$html .= '<li>' . mtl_markdown_inline( trim( $m[3] ) ) . '</li>';
			continue;
		}

		// Otherwise: part of a paragraph. Any open list closes here.
		while ( ! empty( $list_stack ) ) {
			$closing = array_pop( $list_stack );
			$html   .= '</' . $closing['type'] . '>';
		}
		$para_buffer[] = trim( $line );
	}

	if ( ! empty( $para_buffer ) ) {
		$html .= '<p>' . mtl_markdown_inline( implode( ' ', $para_buffer ) ) . '</p>';
	}
	if ( ! empty( $quote_buffer ) ) {
		$html .= '<blockquote><p>' . mtl_markdown_inline( implode( ' ', $quote_buffer ) ) . '</p></blockquote>';
	}
	while ( ! empty( $list_stack ) ) {
		$closing = array_pop( $list_stack );
		$html   .= '</' . $closing['type'] . '>';
	}

	return $html;
}

/**
 * Renders the Workflows admin page: documentation/staff-workflows.md,
 * converted live to HTML on every page load (never cached or copied), so
 * editing that file is immediately reflected here. Nothing else is shown on
 * this page -- the rendered doc fills the whole admin content area.
 */
function mtl_render_workflows_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$md_path = MTL_PLUGIN_DIR . 'documentation/staff-workflows.md';

	echo '<div class="wrap mtl-admin-wrapper">';
	?>
	<style>
		.mtl-workflows-doc {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			padding: 30px 40px 40px;
			margin-top: 20px;
			line-height: 1.6;
		}
		.mtl-workflows-doc h1 {
			font-size: 1.8em;
			margin-top: 0;
		}
		.mtl-workflows-doc h2 {
			font-size: 1.4em;
			margin-top: 2em;
			padding-top: 0.6em;
			border-top: 1px solid #eee;
		}
		.mtl-workflows-doc h3 {
			font-size: 1.15em;
			margin-top: 1.6em;
		}
		.mtl-workflows-doc code {
			background: #f0f0f1;
			padding: 1px 5px;
			border-radius: 3px;
			font-size: 0.92em;
		}
		.mtl-workflows-doc blockquote {
			margin: 1em 0;
			padding: 10px 16px;
			background: #fff8e5;
			border-left: 4px solid #f0dca0;
			border-radius: 0 4px 4px 0;
		}
		.mtl-workflows-doc blockquote p {
			margin: 0;
		}
		.mtl-workflows-doc hr {
			border: none;
			border-top: 1px solid #ddd;
			margin: 2em 0;
		}
		.mtl-workflows-doc ul,
		.mtl-workflows-doc ol {
			padding-left: 1.4em;
		}
		.mtl-workflows-doc li {
			margin-bottom: 4px;
		}
		.mtl-workflows-doc a {
			text-decoration: none;
		}
		.mtl-workflows-doc a:hover {
			text-decoration: underline;
		}
	</style>
	<?php

	if ( ! file_exists( $md_path ) ) {
		echo '<div class="notice notice-error"><p><strong>Error:</strong> Could not find <code>documentation/staff-workflows.md</code>.</p></div>';
		echo '</div>';
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the plugin's own bundled local file by a hardcoded path, never user input or a URL; same reasoning as admin/schema.sql in setup-page.php.
	$markdown = file_get_contents( $md_path );

	echo '<div class="mtl-workflows-doc">';
	echo mtl_markdown_to_html( (string) $markdown );
	echo '</div>';

	echo '</div>';
}
