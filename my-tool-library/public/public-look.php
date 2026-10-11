<?php
/**
 * The look of the public pages: the saved settings, the fonts, the CSS custom
 * properties built from them, the shared component styles, and the top bar.
 *
 * Everything public is styled from one option, mtl_public_look, an array that
 * is checked against fixed lists on the way in (mtl_sanitize_public_look()), so
 * nothing typed into Setup can reach a stylesheet as free text. The staff
 * appearance options (mtl_header_color and friends) style the admin pages
 * only and are never read here.
 *
 * mtl_render_front_shell() in my-tool-library.php prints the fonts, the
 * variables and the base styles into every public page. The Setup page's
 * preview calls the same functions, which is why every rule below is scoped
 * under :where(.mtl-front): the preview can wrap its markup in that class
 * without the styles leaking into the admin screen, and :where() keeps each
 * selector's specificity what it would be unscoped, so page styles override
 * these as if they were plain class rules.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --------------------------------------------------------------------------
// Settings
// --------------------------------------------------------------------------

/**
 * The default look, which is also the Lending desk preset.
 *
 * @return array
 */
function mtl_public_look_defaults() {
	return array(
		'preset'            => 'lending-desk',
		'page_bg'           => '#E6E9E3',
		'panel_bg'          => '#FFFFFF',
		'text'              => '#1F2D36',
		'heading'           => '#1F2D36',
		'link'              => '#1D5F8C',
		'button'            => '#F5BE00',
		'heading_font'      => 'archivo',
		'text_font'         => 'atkinson',
		'text_size'         => 'standard',
		'corners'           => 'soft',
		'button_style'      => 'raised',
		'button_size'       => 'standard',
		'default_view'      => 'cards',
		'show_categories'   => true,
		'show_tool_numbers' => true,
	);
}

/**
 * The presets Setup offers. Choosing one fills in the six colors, the corners
 * and the button style; fonts, sizes and catalog options are left alone.
 *
 * Every pairing that carries text was checked with mtl_contrast_ratio(): text
 * on page and on panel, links on panel, button text on button, and headings
 * on page are all 4.5:1 or better.
 *
 * @return array slug => array( label, page_bg, panel_bg, text, heading, link, button, corners, button_style ).
 */
function mtl_public_look_presets() {
	return array(
		'lending-desk'  => array(
			'label'        => 'Lending desk',
			'page_bg'      => '#E6E9E3',
			'panel_bg'     => '#FFFFFF',
			'text'         => '#1F2D36',
			'heading'      => '#1F2D36',
			'link'         => '#1D5F8C',
			'button'       => '#F5BE00',
			'corners'      => 'soft',
			'button_style' => 'raised',
		),
		'high-contrast' => array(
			'label'        => 'High contrast',
			'page_bg'      => '#FFFFFF',
			'panel_bg'     => '#FFFFFF',
			'text'         => '#000000',
			'heading'      => '#000000',
			'link'         => '#0A4A85',
			'button'       => '#0A4A85',
			'corners'      => 'square',
			'button_style' => 'flat',
		),
		// Today's orange and blue. The heading orange is a shade darker than
		// the button's, since #D65A00 on white is only 3.9:1.
		'classic'       => array(
			'label'        => 'Classic',
			'page_bg'      => '#FFFFFF',
			'panel_bg'     => '#FFFFFF',
			'text'         => '#2C3338',
			'heading'      => '#B84D00',
			'link'         => '#0B6A9E',
			'button'       => '#FF6600',
			'corners'      => 'soft',
			'button_style' => 'flat',
		),
		'forest'        => array(
			'label'        => 'Forest',
			'page_bg'      => '#E8EEE6',
			'panel_bg'     => '#FFFFFF',
			'text'         => '#1B3A2B',
			'heading'      => '#1B3A2B',
			'link'         => '#2E6B30',
			'button'       => '#2E7D32',
			'corners'      => 'rounded',
			'button_style' => 'raised',
		),
		'evening'       => array(
			'label'        => 'Evening',
			'page_bg'      => '#182127',
			'panel_bg'     => '#212C33',
			'text'         => '#E4E8E3',
			'heading'      => '#F2F4F1',
			'link'         => '#8CC4EE',
			'button'       => '#F5BE00',
			'corners'      => 'soft',
			'button_style' => 'raised',
		),
	);
}

/**
 * The allowed values for every setting that is a choice from a list.
 *
 * @return array key => list of allowed values. The first is not the default;
 *               mtl_public_look_defaults() says which is.
 */
function mtl_public_look_choices() {
	return array(
		'preset'       => array_merge( array_keys( mtl_public_look_presets() ), array( 'custom' ) ),
		'text_size'    => array( 'smaller', 'standard', 'larger' ),
		'corners'      => array( 'square', 'soft', 'rounded', 'pill' ),
		'button_style' => array( 'flat', 'raised', 'outline' ),
		'button_size'  => array( 'small', 'standard', 'large' ),
		'default_view' => array( 'cards', 'table', 'compact' ),
	);
}

/**
 * Makes any input into a complete, valid look.
 *
 * Colors must be hex, fonts must be a key from mtl_public_font_choices(), and
 * every other value must be one from mtl_public_look_choices(). Anything else
 * falls back to the default, and unknown keys are dropped, so the result is
 * always safe to turn into CSS.
 *
 * A missing boolean keeps its default (true). A form that posts a checkbox
 * must post "0" for unticked, as an unticked checkbox sends nothing.
 *
 * @param mixed $raw Submitted or stored values.
 * @return array
 */
function mtl_sanitize_public_look( $raw ) {
	$raw      = is_array( $raw ) ? $raw : array();
	$defaults = mtl_public_look_defaults();
	$choices  = mtl_public_look_choices();
	$fonts    = mtl_public_font_choices();
	$clean    = array();

	foreach ( $defaults as $key => $default ) {
		$value = array_key_exists( $key, $raw ) ? $raw[ $key ] : $default;

		if ( is_bool( $default ) ) {
			$clean[ $key ] = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			continue;
		}

		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( in_array( $key, array( 'page_bg', 'panel_bg', 'text', 'heading', 'link', 'button' ), true ) ) {
			$hex           = sanitize_hex_color( $value );
			$clean[ $key ] = $hex ? strtoupper( $hex ) : $default;
		} elseif ( 'heading_font' === $key || 'text_font' === $key ) {
			$clean[ $key ] = isset( $fonts[ $value ] ) ? $value : $default;
		} else {
			$clean[ $key ] = in_array( $value, $choices[ $key ], true ) ? $value : $default;
		}
	}

	return $clean;
}

/**
 * The saved look, completed with defaults and checked. Read once a request.
 *
 * @return array
 */
function mtl_public_look() {
	static $look = null;
	if ( null === $look ) {
		$saved = get_option( 'mtl_public_look', array() );
		$look  = mtl_sanitize_public_look( wp_parse_args( is_array( $saved ) ? $saved : array(), mtl_public_look_defaults() ) );
	}
	return $look;
}

// --------------------------------------------------------------------------
// Color helpers
// --------------------------------------------------------------------------

/**
 * WCAG relative luminance of a hex color.
 *
 * @param string $hex #RGB or #RRGGBB. Anything else counts as black.
 * @return float 0 (black) to 1 (white).
 */
function mtl_relative_luminance( $hex ) {
	$hex = ltrim( (string) sanitize_hex_color( $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) ) {
		return 0.0;
	}

	$lum = 0.0;
	foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
		$c    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
		$c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		$lum += $weight * $c;
	}
	return $lum;
}

/**
 * WCAG contrast ratio between two hex colors.
 *
 * @param string $hex_a A color.
 * @param string $hex_b The other color.
 * @return float 1 to 21.
 */
function mtl_contrast_ratio( $hex_a, $hex_b ) {
	$a = mtl_relative_luminance( $hex_a );
	$b = mtl_relative_luminance( $hex_b );
	return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
}

/**
 * The text color for a filled background: the default dark ink or white,
 * whichever reads better on it.
 *
 * @param string $bg_hex Background color.
 * @return string '#1F2D36' or '#FFFFFF'.
 */
function mtl_readable_ink( $bg_hex ) {
	return mtl_contrast_ratio( '#1F2D36', $bg_hex ) >= mtl_contrast_ratio( '#FFFFFF', $bg_hex ) ? '#1F2D36' : '#FFFFFF';
}

// --------------------------------------------------------------------------
// Fonts
// --------------------------------------------------------------------------

/**
 * Fonts installed through WordPress's Font Library (WordPress 6.5+), as
 * published wp_font_family posts. Read once a request.
 *
 * @return array post_name => array( id, label, stack, family ).
 */
function mtl_public_library_fonts() {
	static $fonts = null;
	if ( null !== $fonts ) {
		return $fonts;
	}

	$fonts = array();
	$posts = get_posts(
		array(
			'post_type'              => 'wp_font_family',
			'post_status'            => 'publish',
			'posts_per_page'         => 100,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	foreach ( $posts as $post ) {
		$settings = json_decode( (string) $post->post_content, true );
		$stack    = is_array( $settings ) && isset( $settings['fontFamily'] ) && is_string( $settings['fontFamily'] ) ? $settings['fontFamily'] : '';
		$stack    = mtl_css_value( $stack, '' );

		// An unpaired quote would swallow the rest of the declaration.
		if ( 0 !== substr_count( $stack, '"' ) % 2 || 0 !== substr_count( $stack, "'" ) % 2 ) {
			$stack = str_replace( array( '"', "'" ), '', $stack );
		}

		$family = trim( (string) strtok( $stack, ',' ), " \"'" );
		if ( '' === $family || '' === $post->post_name ) {
			continue;
		}

		// A stack that ends without a generic family would fall back to the
		// browser's default serif if the font failed to load.
		$parts   = array_map( 'trim', explode( ',', $stack ) );
		$generic = array( 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded' );
		if ( ! in_array( strtolower( end( $parts ) ), $generic, true ) ) {
			$stack .= ', sans-serif';
		}

		$fonts[ $post->post_name ] = array(
			'id'     => (int) $post->ID,
			'label'  => '' !== $post->post_title ? $post->post_title : $family,
			'stack'  => $stack,
			'family' => $family,
		);
	}

	return $fonts;
}

/**
 * Every font the public pages can use: the two bundled with the plugin, the
 * WordPress system font, four standard fonts, then any installed in the
 * Font Library.
 *
 * @return array key => array( 'label' => string, 'stack' => CSS font stack, 'group' => string ).
 */
function mtl_public_font_choices() {
	$builtin = 'Built in';
	$choices = array(
		'archivo'   => array(
			'label' => 'Archivo (included with the plugin)',
			'stack' => '"Archivo", "Arial Narrow", sans-serif',
			'group' => $builtin,
		),
		'atkinson'  => array(
			'label' => 'Atkinson Hyperlegible Next (included with the plugin)',
			'stack' => '"Atkinson Hyperlegible Next", "Atkinson Hyperlegible", Verdana, sans-serif',
			'group' => $builtin,
		),
		'system'    => array(
			'label' => 'WordPress system font',
			'stack' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
			'group' => $builtin,
		),
		'arial'     => array(
			'label' => 'Arial',
			'stack' => 'Arial, "Helvetica Neue", Helvetica, sans-serif',
			'group' => $builtin,
		),
		'verdana'   => array(
			'label' => 'Verdana',
			'stack' => 'Verdana, Geneva, sans-serif',
			'group' => $builtin,
		),
		'georgia'   => array(
			'label' => 'Georgia',
			'stack' => 'Georgia, "Times New Roman", serif',
			'group' => $builtin,
		),
		'trebuchet' => array(
			'label' => 'Trebuchet MS',
			'stack' => '"Trebuchet MS", "Lucida Grande", sans-serif',
			'group' => $builtin,
		),
	);

	foreach ( mtl_public_library_fonts() as $slug => $font ) {
		$choices[ 'lib-' . $slug ] = array(
			'label' => $font['label'],
			'stack' => $font['stack'],
			'group' => 'Installed in WordPress',
		);
	}

	return $choices;
}

/**
 * Whether a font file URL is on this site, so a page never asks another site
 * for a font. The Font Library downloads what it installs into its own
 * folder, but its REST API will also accept a face pointing anywhere.
 *
 * @param string $url Font file URL.
 * @return bool
 */
function mtl_public_font_url_allowed( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! is_array( $parts ) ) {
		return false;
	}
	if ( ! isset( $parts['host'] ) ) {
		// Root-relative only; a scheme without a host (data:, javascript:) is not a file on this site.
		return ! isset( $parts['scheme'] ) && 0 === strpos( (string) $url, '/' );
	}
	if ( ! in_array( strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ), array( 'http', 'https' ), true ) ) {
		return false;
	}

	$hosts = array( home_url(), site_url(), content_url() );
	if ( function_exists( 'wp_get_font_dir' ) ) {
		$dir     = wp_get_font_dir();
		$hosts[] = isset( $dir['url'] ) ? $dir['url'] : '';
	}
	foreach ( $hosts as $site_url ) {
		if ( strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) ) === strtolower( $parts['host'] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The @font-face rules for the fonts a look uses, ready for <head>.
 *
 * The bundled fonts are variable, so one file covers every weight (and, for
 * Archivo, the narrow widths the headings use). Font Library fonts go through
 * core's wp_print_font_faces(), the same printer the block editor uses.
 *
 * @param array $look A look from mtl_public_look().
 * @return string Zero, one or two <style> elements.
 */
function mtl_public_font_faces_html( $look ) {
	$in_use = array_unique( array( $look['heading_font'], $look['text_font'] ) );
	$html   = '';

	$css = '';
	if ( in_array( 'archivo', $in_use, true ) ) {
		$css .= '@font-face{font-family:"Archivo";src:url("' . esc_url( plugins_url( 'fonts/archivo.woff2', __FILE__ ) ) . '") format("woff2");'
			. 'font-weight:100 900;font-stretch:62% 125%;font-style:normal;font-display:swap;}' . "\n";
	}
	if ( in_array( 'atkinson', $in_use, true ) ) {
		foreach ( array( 'normal', 'italic' ) as $style ) {
			$css .= '@font-face{font-family:"Atkinson Hyperlegible Next";src:url("' . esc_url( plugins_url( 'fonts/atkinson-hyperlegible-next-' . $style . '.woff2', __FILE__ ) ) . '") format("woff2");'
				. 'font-weight:200 800;font-style:' . $style . ';font-display:swap;}' . "\n";
		}
	}
	if ( '' !== $css ) {
		$html .= "<style>\n" . $css . "</style>\n";
	}

	$library = mtl_public_library_fonts();
	$fonts   = array();
	foreach ( $in_use as $key ) {
		$slug = 0 === strpos( $key, 'lib-' ) ? substr( $key, 4 ) : '';
		if ( '' === $slug || ! isset( $library[ $slug ] ) ) {
			continue;
		}
		$faces = mtl_public_library_font_faces( $library[ $slug ] );
		if ( $faces ) {
			$fonts[] = $faces;
		}
	}

	// Never call it with an empty list: core then prints the theme's fonts.
	if ( $fonts ) {
		ob_start();
		wp_print_font_faces( $fonts );
		$html .= ob_get_clean();
	}

	return $html;
}

/**
 * One Font Library family's faces, in the shape wp_print_font_faces() takes.
 *
 * Each wp_font_face post stores its settings as camelCase JSON; core's
 * printer wants kebab-case keys and src as a list of URLs.
 *
 * @param array $font An entry from mtl_public_library_fonts().
 * @return array List of faces; empty when none has a usable file.
 */
function mtl_public_library_font_faces( $font ) {
	$posts = get_posts(
		array(
			'post_type'              => 'wp_font_face',
			'post_status'            => 'publish',
			'post_parent'            => $font['id'],
			'posts_per_page'         => 100,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$faces = array();
	foreach ( $posts as $post ) {
		$settings = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $settings ) || empty( $settings['src'] ) ) {
			continue;
		}

		$src = array_values( array_filter( array_map( 'esc_url_raw', (array) $settings['src'] ), 'mtl_public_font_url_allowed' ) );
		if ( ! $src ) {
			continue;
		}

		$face = array();
		foreach ( $settings as $key => $value ) {
			$face[ strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', (string) $key ) ) ] = $value;
		}
		$face['src']         = $src;
		$face['font-family'] = $font['family'];
		if ( empty( $face['font-display'] ) ) {
			$face['font-display'] = 'swap';
		}
		$faces[] = $face;
	}

	return $faces;
}

// --------------------------------------------------------------------------
// CSS
// --------------------------------------------------------------------------

/**
 * The CSS custom properties for a look, as one rule.
 *
 * Every value printed comes from the sanitized look or a fixed table here,
 * and still goes through mtl_css_value(). The derived tints are color-mix()
 * expressions over the variables themselves, so they need no PHP color math.
 *
 * @param array  $look     A look; it is sanitized again here.
 * @param string $selector Where the properties go. The public pages use
 *                         :root; the Setup preview passes its own wrapper.
 *                         A literal from code, never from input.
 * @return string CSS.
 */
function mtl_public_look_vars_css( $look, $selector = ':root' ) {
	$look     = mtl_sanitize_public_look( $look );
	$fonts    = mtl_public_font_choices();
	$selector = preg_replace( '/[^a-zA-Z0-9_\-.:#\[\]=" ]/', '', (string) $selector );
	$selector = '' !== $selector ? $selector : ':root';

	// Panel radius, then button radius.
	$corners = array(
		'square'  => array( '0', '0' ),
		'soft'    => array( '6px', '6px' ),
		'rounded' => array( '12px', '10px' ),
		'pill'    => array( '14px', '999px' ),
	);
	$scales  = array(
		'small'    => '0.88',
		'standard' => '1',
		'large'    => '1.15',
	);
	$sizes   = array(
		'smaller'  => '15px',
		'standard' => '17px',
		'larger'   => '19px',
	);

	// Status colors are fixed so their meaning can't be themed away. On a dark
	// panel the light set would be unreadable, so the brighter set is used.
	$dark_panel = mtl_relative_luminance( $look['panel_bg'] ) < 0.4;
	$status     = $dark_panel
		? array(
			'ok'      => '#6FCF97',
			'out'     => '#F2A65A',
			'maint'   => '#E3C55A',
			'late'    => '#F2877C',
			'wait'    => '#8CC4EE',
			'retired' => '#A3AEA9',
		)
		: array(
			'ok'      => '#2E7D4F',
			'out'     => '#A34D06',
			'maint'   => '#7A6200',
			'late'    => '#B42318',
			'wait'    => '#2B5C8A',
			'retired' => '#5F6B72',
		);

	$values = array(
		'--mtl-page'         => $look['page_bg'],
		'--mtl-panel'        => $look['panel_bg'],
		'--mtl-ink'          => $look['text'],
		'--mtl-heading'      => $look['heading'],
		'--mtl-link'         => $look['link'],
		'--mtl-btn'          => $look['button'],
		'--mtl-btn-ink'      => mtl_readable_ink( $look['button'] ),
		'--mtl-radius'       => $corners[ $look['corners'] ][0],
		'--mtl-btn-radius'   => $corners[ $look['corners'] ][1],
		'--mtl-btn-scale'    => $scales[ $look['button_size'] ],
		'--mtl-font-display' => $fonts[ $look['heading_font'] ]['stack'],
		'--mtl-font-text'    => $fonts[ $look['text_font'] ]['stack'],
		'--mtl-text-size'    => $sizes[ $look['text_size'] ],
	);
	foreach ( $status as $name => $hex ) {
		$values[ '--mtl-' . $name ] = $hex;
	}

	$css = $selector . " {\n";
	foreach ( $values as $name => $value ) {
		$css .= "\t" . $name . ': ' . mtl_css_value( $value, '0' ) . ";\n";
	}

	// Secondary text is mixed at 75% rather than 70% so it still clears
	// 4.5:1 on the default page color, not only on panels.
	$css .= "\t--mtl-ink-2: color-mix(in srgb, var(--mtl-ink) 75%, var(--mtl-panel));\n"
		. "\t--mtl-rule: color-mix(in srgb, var(--mtl-ink) 22%, var(--mtl-panel));\n"
		. "\t--mtl-rule-soft: color-mix(in srgb, var(--mtl-ink) 9%, var(--mtl-panel));\n"
		. "\t--mtl-field-border: color-mix(in srgb, var(--mtl-ink) 55%, var(--mtl-panel));\n"
		. "\t--mtl-highlight: color-mix(in srgb, var(--mtl-btn) 22%, var(--mtl-panel));\n"
		. "\t--mtl-hover: color-mix(in srgb, var(--mtl-ink) 4%, var(--mtl-panel));\n";

	// Native controls, scrollbars and autofill follow the page.
	if ( mtl_relative_luminance( $look['page_bg'] ) < 0.4 ) {
		$css .= "\tcolor-scheme: dark;\n";
	}

	return $css . "}\n";
}

/**
 * The variables plus the shared component styles: everything a public page,
 * or the Setup preview, needs inside one <style> element.
 *
 * @param array|null $look     A look; null for the saved one.
 * @param string     $selector Passed to mtl_public_look_vars_css().
 * @return string CSS.
 */
function mtl_public_look_css( $look = null, $selector = ':root' ) {
	return mtl_public_look_vars_css( null === $look ? mtl_public_look() : $look, $selector ) . mtl_public_base_css();
}

/**
 * Shared component CSS for every public page. Uses only the custom
 * properties, so it is the same text whatever the look.
 *
 * The button style is a class beside .mtl-front (mtl-btn-style-flat, -raised
 * or -outline), which mtl_render_front_shell() puts on <body>.
 *
 * @return string CSS.
 */
function mtl_public_base_css() {
	ob_start();
	?>
:where(.mtl-front),
:where(.mtl-front) *,
:where(.mtl-front) *::before,
:where(.mtl-front) *::after {
	box-sizing: border-box;
}

.mtl-front {
	background: var(--mtl-page);
	color: var(--mtl-ink);
	font-family: var(--mtl-font-text);
	font-size: var(--mtl-text-size);
	line-height: 1.55;
	font-variant-numeric: tabular-nums;
	-webkit-text-size-adjust: 100%;
	text-size-adjust: 100%;
}

body.mtl-front {
	margin: 0;
	min-height: 100vh;
	display: flex;
	flex-direction: column;
}

:where(.mtl-front) a {
	color: var(--mtl-link);
	text-underline-offset: 2px;
}

:where(.mtl-front) :is(h1, h2, h3) {
	font-family: var(--mtl-font-display);
	font-stretch: 82%;
	font-weight: 800;
	line-height: 1.12;
	color: var(--mtl-heading);
	margin: 0;
}

:where(.mtl-front) h3 {
	font-weight: 700;
}

:where(.mtl-front) :focus-visible {
	outline: 3px solid var(--mtl-link);
	outline-offset: 2px;
}

/* ---------- Top bar ---------- */

:where(.mtl-front) .mtl-topbar {
	background: var(--mtl-panel);
	border-bottom: 1px solid var(--mtl-rule);
}

:where(.mtl-front) .mtl-topbar-inner {
	max-width: 1320px;
	margin: 0 auto;
	padding: 0 24px;
	display: flex;
	align-items: center;
	gap: 24px;
	min-height: 64px;
}

:where(.mtl-front) .mtl-brand {
	display: flex;
	min-width: 0;
	align-items: center;
	gap: 10px;
	min-height: 44px;
	padding: 6px 0;
	color: var(--mtl-heading);
	text-decoration: none;
	font-family: var(--mtl-font-display);
	font-stretch: 82%;
	font-weight: 800;
	font-size: 23px;
	line-height: 1.1;
	letter-spacing: -0.01em;
}

:where(.mtl-front) .mtl-brand img {
	display: block;
	max-height: 40px;
	width: auto;
}

:where(.mtl-front) .mtl-nav {
	margin-left: auto;
	display: flex;
	gap: 4px;
	align-self: stretch;
}

/* Transparent top and bottom borders keep the label centered when the
	current item's underline appears. */
:where(.mtl-front) .mtl-nav a {
	display: flex;
	align-items: center;
	padding: 0 14px;
	color: var(--mtl-ink);
	text-decoration: none;
	font-size: 15.5px;
	white-space: nowrap;
	border-top: 3px solid transparent;
	border-bottom: 3px solid transparent;
	margin-bottom: -1px;
}

:where(.mtl-front) .mtl-nav a:hover {
	color: var(--mtl-link);
}

:where(.mtl-front) .mtl-nav a[aria-current="page"] {
	font-weight: 700;
	border-bottom-color: var(--mtl-btn);
}

:where(.mtl-front) .mtl-nav a.mtl-nav-quiet {
	color: var(--mtl-ink-2);
}

/* Phone menu: a <details>, so it needs no script. */
:where(.mtl-front) .mtl-menu {
	display: none;
	margin-left: auto;
	position: relative;
}

:where(.mtl-front) .mtl-menu > summary {
	list-style: none;
	cursor: pointer;
	display: flex;
	align-items: center;
	gap: 10px;
	min-height: 44px;
	padding: 0 14px;
	border: 1px solid var(--mtl-rule);
	border-radius: var(--mtl-radius);
	font-weight: 700;
	font-size: 15px;
}

:where(.mtl-front) .mtl-menu > summary::-webkit-details-marker {
	display: none;
}

:where(.mtl-front) .mtl-menu > summary::after {
	content: "";
	width: 8px;
	height: 8px;
	border-right: 2px solid currentColor;
	border-bottom: 2px solid currentColor;
	transform: translateY(-2px) rotate(45deg);
}

:where(.mtl-front) .mtl-menu[open] > summary::after {
	transform: translateY(2px) rotate(-135deg);
}

:where(.mtl-front) .mtl-menu-panel {
	position: absolute;
	right: 0;
	top: calc(100% + 8px);
	z-index: 60;
	min-width: 250px;
	display: flex;
	flex-direction: column;
	padding: 6px 0;
	background: var(--mtl-panel);
	border: 1px solid var(--mtl-rule);
	border-radius: var(--mtl-radius);
	box-shadow: 0 8px 24px rgba(0, 0, 0, .16);
}

:where(.mtl-front) .mtl-menu-panel a {
	display: flex;
	align-items: center;
	min-height: 44px;
	padding: 0 16px 0 13px;
	color: var(--mtl-ink);
	text-decoration: none;
	border-left: 3px solid transparent;
}

:where(.mtl-front) .mtl-menu-panel a:hover {
	background: var(--mtl-hover);
}

:where(.mtl-front) .mtl-menu-panel a[aria-current="page"] {
	font-weight: 700;
	border-left-color: var(--mtl-btn);
}

:where(.mtl-front) .mtl-menu-panel a.mtl-nav-quiet {
	color: var(--mtl-ink-2);
}

@media (max-width: 760px) {
	:where(.mtl-front) .mtl-nav {
		display: none;
	}

	:where(.mtl-front) .mtl-menu {
		display: block;
	}

	:where(.mtl-front) .mtl-topbar-inner {
		padding: 0 16px;
		min-height: 56px;
		gap: 12px;
	}

	:where(.mtl-front) .mtl-brand {
		font-size: 20px;
	}

	:where(.mtl-front) .mtl-brand img {
		max-height: 32px;
	}
}

/* ---------- Page frame ---------- */

/* Pages lay themselves out; the shell only makes the main area fill the
	height, which keeps the footer at the bottom of a short page. */
:where(.mtl-front) .mtl-front-content {
	flex: 1 0 auto;
	width: 100%;
}

:where(.mtl-front) .mtl-page-title {
	font-size: 40px;
	font-weight: 800;
	letter-spacing: -0.015em;
}

:where(.mtl-front) .mtl-section-head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 12px;
	margin: 38px 0 12px;
}

:where(.mtl-front) .mtl-section-head h2 {
	font-size: 26px;
}

/* The count or note beside the heading. */
:where(.mtl-front) .mtl-section-head > :not(h2) {
	color: var(--mtl-ink-2);
	font-size: 16px;
}

:where(.mtl-front) .mtl-section-head > .mtl-section-aside {
	margin-left: auto;
	color: var(--mtl-ink);
	font-size: 15px;
}

/* A bordered surface. No padding of its own, so lists inside can run edge
	to edge; give the content its padding. */
:where(.mtl-front) .mtl-panel {
	background: var(--mtl-panel);
	border: 1px solid var(--mtl-rule);
	border-radius: var(--mtl-radius);
}

:where(.mtl-front) .mtl-chip {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 5px 12px;
	background: var(--mtl-panel);
	border: 1px solid var(--mtl-rule);
	border-radius: 999px;
	color: var(--mtl-ink);
	font-size: 15px;
	line-height: 1.3;
	text-decoration: none;
}

:where(.mtl-front) .mtl-sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
	border: 0;
}

/* ---------- Buttons ---------- */

:where(.mtl-front) .mtl-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	min-height: max(44px, calc(44px * var(--mtl-btn-scale)));
	padding: 0 calc(20px * var(--mtl-btn-scale));
	margin: 0;
	border: 1px solid var(--mtl-ink);
	border-radius: var(--mtl-btn-radius);
	background: var(--mtl-panel);
	color: var(--mtl-ink);
	font-family: var(--mtl-font-text);
	font-size: calc(16px * var(--mtl-btn-scale));
	font-weight: 700;
	line-height: 1.15;
	text-align: center;
	text-decoration: none;
	cursor: pointer;
	-webkit-appearance: none;
	appearance: none;
}

:where(.mtl-front) .mtl-btn:hover {
	background: var(--mtl-hover);
}

:where(.mtl-front) .mtl-btn-primary {
	background: var(--mtl-btn);
	border-color: var(--mtl-btn);
	color: var(--mtl-btn-ink);
}

:where(.mtl-front) .mtl-btn-primary:hover {
	background: color-mix(in srgb, var(--mtl-btn) 88%, var(--mtl-ink));
}

:where(.mtl-btn-style-raised) .mtl-btn-primary {
	box-shadow: inset 0 -3px 0 rgba(0, 0, 0, .22);
}

:where(.mtl-btn-style-outline) .mtl-btn-primary {
	background: var(--mtl-panel);
	border: 2px solid var(--mtl-btn);
	color: var(--mtl-ink);
}

:where(.mtl-btn-style-outline) .mtl-btn-primary:hover {
	background: var(--mtl-highlight);
}

:where(.mtl-front) .mtl-btn-small {
	padding: 0 calc(14px * var(--mtl-btn-scale));
	font-size: calc(15px * var(--mtl-btn-scale));
}

:where(.mtl-front) .mtl-btn-wide {
	width: 100%;
}

/* Text-link buttons. Listed after the others so a .mtl-btn .mtl-btn-text
	pair still comes out as a link. */
:where(.mtl-front) :is(.mtl-btn-text, .mtl-btn-danger) {
	display: inline;
	min-height: 0;
	padding: 0;
	margin: 0;
	border: 0;
	border-radius: 0;
	background: none;
	box-shadow: none;
	color: var(--mtl-link);
	font: inherit;
	text-align: inherit;
	text-decoration: underline;
	text-underline-offset: 2px;
	cursor: pointer;
	-webkit-appearance: none;
	appearance: none;
}

:where(.mtl-front) :is(.mtl-btn-text, .mtl-btn-danger):hover {
	background: none;
	text-decoration-thickness: 2px;
}

:where(.mtl-front) .mtl-btn-danger {
	color: var(--mtl-late);
}

/* ---------- Form fields ---------- */

:where(.mtl-front) .mtl-field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0 0 16px;
}

:where(.mtl-front) .mtl-field label {
	font-weight: 700;
	font-size: 15.5px;
}

:where(.mtl-front) .mtl-field label small {
	font-weight: 400;
	font-size: 14px;
	color: var(--mtl-ink-2);
}

/* 16px is the floor: phones zoom into any smaller field on focus. */
:where(.mtl-front) .mtl-field :is(input:not([type="checkbox"]):not([type="radio"]):not([type="file"]), select, textarea),
:where(.mtl-front) .mtl-front-card :is(input[type="text"], input[type="email"], input[type="password"]) {
	width: 100%;
	min-height: 46px;
	padding: 0 12px;
	border: 1px solid var(--mtl-field-border);
	border-radius: var(--mtl-radius);
	background: var(--mtl-panel);
	color: var(--mtl-ink);
	font-family: var(--mtl-font-text);
	font-size: max(16px, calc(var(--mtl-text-size) * .94));
	line-height: 1.3;
}

:where(.mtl-front) .mtl-field textarea {
	min-height: 120px;
	padding: 10px 12px;
	resize: vertical;
}

/* A drawn chevron in place of the browser's own arrow, so menus match the
	text boxes beside them. */
:where(.mtl-front) .mtl-field select {
	-webkit-appearance: none;
	appearance: none;
	padding-right: 36px;
	background-image: linear-gradient(45deg, transparent 50%, var(--mtl-ink) 50%), linear-gradient(135deg, var(--mtl-ink) 50%, transparent 50%);
	background-position: calc(100% - 18px) 50%, calc(100% - 13px) 50%;
	background-size: 5px 5px;
	background-repeat: no-repeat;
}

:where(.mtl-front) .mtl-field select[multiple] {
	padding: 6px 12px;
	background-image: none;
}

:where(.mtl-front) input[type="checkbox"],
:where(.mtl-front) input[type="radio"] {
	accent-color: var(--mtl-ink);
}

:where(.mtl-front) .mtl-hint {
	margin: 0;
	font-size: 14px;
	color: var(--mtl-ink-2);
}

:where(.mtl-front) .mtl-field-row {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 0 18px;
}

@media (max-width: 760px) {
	:where(.mtl-front) .mtl-field-row {
		grid-template-columns: 1fr;
	}

	:where(.mtl-front) .mtl-page-title {
		font-size: 30px;
	}
}

/* ---------- Notices and status ---------- */

/* The result of an action: a plain box with a colored edge. */
:where(.mtl-front) .mtl-front-notice {
	margin: 0 0 18px;
	padding: 12px 16px;
	background: var(--mtl-panel);
	border: 1px solid var(--mtl-rule);
	border-left: 5px solid var(--mtl-ink-2);
	border-radius: var(--mtl-radius);
	color: var(--mtl-ink);
}

:where(.mtl-front) .mtl-front-notice-success {
	border-left-color: var(--mtl-ok);
}

:where(.mtl-front) .mtl-front-notice-error {
	border-left-color: var(--mtl-late);
}

/* A standing state of the account, not the result of a click, so it stays
	solid red. Fixed colors: the themed --mtl-late is too light under white
	text on a dark look. */
:where(.mtl-front) .mtl-locked-banner {
	margin: 0 0 16px;
	padding: 14px 18px;
	background: #B42318;
	border-radius: var(--mtl-radius);
	color: #FFFFFF;
	line-height: 1.5;
}

:where(.mtl-front) .mtl-locked-banner a {
	color: #FFFFFF;
	text-decoration: underline;
}

:where(.mtl-front) .mtl-status {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	font-size: 14.5px;
	font-weight: 700;
	color: var(--mtl-ink);
}

:where(.mtl-front) .mtl-status::before {
	content: "";
	flex: none;
	width: 9px;
	height: 9px;
	border-radius: 2px;
	background: currentColor;
}

:where(.mtl-front) .mtl-status-ok {
	color: var(--mtl-ok);
}

:where(.mtl-front) .mtl-status-out {
	color: var(--mtl-out);
}

:where(.mtl-front) .mtl-status-maint {
	color: var(--mtl-maint);
}

:where(.mtl-front) .mtl-status-late {
	color: var(--mtl-late);
}

:where(.mtl-front) .mtl-status-wait {
	color: var(--mtl-wait);
}

:where(.mtl-front) .mtl-status-retired {
	color: var(--mtl-retired);
}

:where(.mtl-front) .mtl-status-note {
	font-size: 14.5px;
	font-weight: 400;
	color: var(--mtl-ink-2);
}

/* ---------- Sign-in card ---------- */

/* The one centered thing on the public pages: the sign-in, password and
	Coming soon card. */
:where(.mtl-front) .mtl-front-card {
	width: calc(100% - 32px);
	max-width: 460px;
	margin: 48px auto;
	padding: 28px;
	background: var(--mtl-panel);
	border: 1px solid var(--mtl-rule);
	border-radius: var(--mtl-radius);
}

:where(.mtl-front) .mtl-front-card :is(h1, h2) {
	font-size: 30px;
	margin: 0 0 14px;
}

:where(.mtl-front) .mtl-front-card p {
	margin: 0 0 14px;
}

/* Core's wp_login_form() markup, matched to .mtl-field. */
:where(.mtl-front) .mtl-front-card form p {
	margin: 0 0 16px;
}

:where(.mtl-front) .mtl-front-card label {
	display: block;
	margin-bottom: 6px;
	font-weight: 700;
	font-size: 15.5px;
}

:where(.mtl-front) .mtl-front-card .login-remember label {
	display: flex;
	align-items: center;
	gap: 10px;
	min-height: 44px;
	margin: 0;
	font-weight: 400;
}

:where(.mtl-front) .mtl-front-card .login-remember input {
	width: 20px;
	height: 20px;
	margin: 0;
}

:where(.mtl-front) .mtl-front-card input[type="submit"],
:where(.mtl-front) .mtl-front-card .button-primary {
	width: 100%;
	min-height: max(44px, calc(44px * var(--mtl-btn-scale)));
	padding: 0 calc(20px * var(--mtl-btn-scale));
	border: 1px solid var(--mtl-btn);
	border-radius: var(--mtl-btn-radius);
	background: var(--mtl-btn);
	color: var(--mtl-btn-ink);
	font-family: var(--mtl-font-text);
	font-size: calc(16px * var(--mtl-btn-scale));
	font-weight: 700;
	cursor: pointer;
	-webkit-appearance: none;
	appearance: none;
}

:where(.mtl-btn-style-raised) .mtl-front-card :is(input[type="submit"], .button-primary) {
	box-shadow: inset 0 -3px 0 rgba(0, 0, 0, .22);
}

:where(.mtl-btn-style-outline) .mtl-front-card :is(input[type="submit"], .button-primary) {
	background: var(--mtl-panel);
	border: 2px solid var(--mtl-btn);
	color: var(--mtl-ink);
}

:where(.mtl-front) .mtl-front-card > :last-child {
	margin-bottom: 0;
}

:where(.mtl-front) .mtl-front-card .mtl-hint {
	display: block;
	margin-top: 6px;
}

/* The links under a card's form ("Forgot your password?", "Back to sign in"). */
:where(.mtl-front) .mtl-front-card .mtl-front-card-after {
	margin: 20px 0 0;
	padding-top: 16px;
	border-top: 1px solid var(--mtl-rule-soft);
}

@media (max-width: 760px) {
	:where(.mtl-front) .mtl-front-card {
		margin: 24px auto;
		padding: 22px;
	}

	:where(.mtl-front) .mtl-front-card :is(h1, h2) {
		font-size: 26px;
	}
}

/* ---------- Footer and preview bar ---------- */

:where(.mtl-front) .mtl-front-footer {
	flex: none;
	width: 100%;
	max-width: 1320px;
	margin: 0 auto;
	padding: 0 24px;
}

:where(.mtl-front) .mtl-front-footer-inner {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 20px;
	padding: 26px 0 40px;
	border-top: 1px solid var(--mtl-rule);
	font-size: 14.5px;
	color: var(--mtl-ink-2);
}

:where(.mtl-front) .mtl-front-footer a {
	color: var(--mtl-ink-2);
}

:where(.mtl-front) .mtl-front-contact {
	margin: 0 0 0 auto;
}

/* Staff only, before launch: what customers see in place of this page.
	Sticky rather than fixed, so it covers nothing at the end of the page. */
:where(.mtl-front) .mtl-front-preview-bar {
	position: sticky;
	bottom: 0;
	z-index: 40;
	padding: 10px 16px;
	background: var(--mtl-ink);
	color: var(--mtl-panel);
	font-size: 15px;
	line-height: 1.5;
	text-align: center;
}

:where(.mtl-front) .mtl-front-preview-bar a {
	color: inherit;
}

@media (max-width: 760px) {
	:where(.mtl-front) .mtl-front-footer {
		padding: 0 16px;
	}

	:where(.mtl-front) .mtl-front-contact {
		margin-left: 0;
		flex-basis: 100%;
	}
}

@media (prefers-reduced-motion: reduce) {
	:where(.mtl-front) *,
	:where(.mtl-front) *::before,
	:where(.mtl-front) *::after {
		transition: none !important;
		animation: none !important;
		scroll-behavior: auto !important;
	}
}
	<?php
	return ob_get_clean();
}

// --------------------------------------------------------------------------
// Top bar navigation
// --------------------------------------------------------------------------

/**
 * The top bar's links for whoever is looking.
 *
 * Before launch, a customer gets the catalog link only, and only while the
 * catalog preview is on, since every other page is closed. Anybody left
 * signed in when the library was closed again still gets Log out.
 *
 * @return array key => array( label, url, quiet ). key is the $current value
 *               that marks it; quiet items are drawn in the secondary color.
 */
function mtl_front_nav_items() {
	$logout = array( 'Log out', wp_logout_url( mtl_front_page_url( 'main' ) ), true );
	$browse = array( 'Browse tools', mtl_front_page_url( 'main' ), false );

	if ( mtl_is_prelaunch_visitor() ) {
		$items = mtl_catalog_preview_enabled() ? array( 'browse' => $browse ) : array();
		if ( is_user_logged_in() ) {
			$items['logout'] = $logout;
		}
		return $items;
	}

	if ( ! is_user_logged_in() ) {
		return array(
			'browse' => $browse,
			'signin' => array( 'Sign in', mtl_front_page_url( 'login' ), false ),
			'join'   => array( 'Join', mtl_front_page_url( 'signup' ), false ),
		);
	}

	if ( mtl_can_manage_library() ) {
		return array(
			'browse' => $browse,
			'admin'  => array( 'Admin portal', admin_url( 'admin.php?page=mtl-dashboard' ), false ),
			'logout' => $logout,
		);
	}

	return array(
		'browse'  => $browse,
		'loans'   => array( 'My loans & reservations', mtl_front_page_url( 'reservations' ), false ),
		'account' => array( 'Account', mtl_front_page_url( 'account' ), false ),
		'logout'  => $logout,
	);
}

/**
 * The top bar's navigation: a row of links on wider screens and the same
 * links in a <details> menu under 760px. Both are printed, and CSS shows one,
 * so the menu needs no script.
 *
 * @param string $current One of browse, loans, account, signin, join, or ''.
 * @return string HTML, or '' when there is nothing to link to.
 */
function mtl_front_nav_html( $current ) {
	$items = mtl_front_nav_items();
	if ( ! $items ) {
		return '';
	}

	$links = '';
	foreach ( $items as $key => $item ) {
		$links .= '<a href="' . esc_url( $item[1] ) . '"'
			. ( $item[2] ? ' class="mtl-nav-quiet"' : '' )
			. ( $key === $current ? ' aria-current="page"' : '' )
			. '>' . esc_html( $item[0] ) . '</a>';
	}

	return '<nav class="mtl-nav" aria-label="Library">' . $links . '</nav>'
		. '<details class="mtl-menu"><summary>Menu</summary>'
		. '<nav class="mtl-menu-panel" aria-label="Library">' . $links . '</nav>'
		. '</details>';
}

// --------------------------------------------------------------------------
// Privacy policy text
// --------------------------------------------------------------------------

add_action( 'admin_init', 'mtl_add_privacy_policy_content' );

/**
 * Suggests privacy policy text on WordPress's Policy Guide screen (Settings >
 * Privacy), for staff to copy into the site's policy.
 *
 * @return void
 */
function mtl_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content = '<p class="privacy-policy-tutorial">This is what My Tool Library stores about members and visitors. Copy the parts that apply into your privacy policy and change the wording to fit how your library works.</p>'
		. '<strong class="privacy-policy-tutorial">Suggested text:</strong>'
		. '<h2>Member accounts</h2>'
		. '<p>When you join the tool library we store your name, address, phone number and email address, along with the sign-in account that goes with them. We may also keep links to the documents you showed us to confirm who you are and where you live, a photo of you if staff take one, the trainings you have completed, and a record of the tools you have borrowed and reserved.</p>'
		. '<p>You can delete your account from your Account page, or ask us to delete it. If you have never borrowed or reserved a tool, your member record and sign-in account are removed completely. If you have, your name, address, phone number and email address are replaced with placeholders, your photo and documents are deleted, and your sign-in account is removed. Your past loans and reservations are kept without your details on them, so we can still count how often each tool is used. Deleting an account cannot be undone.</p>'
		. '<p class="privacy-policy-tutorial">Include the next section only if you have turned on member agreements.</p>'
		. '<h2>Member agreements</h2>'
		. '<p>When you agree to one of our member agreements, we keep a record of your name and email address as they were at the time, what you agreed to, and when. We keep this record even after your account is deleted, because its purpose is to show who agreed. It holds nothing else about you: no IP address and no details of your device or browser.</p>'
		. '<h2>Cookies</h2>'
		. '<p>When you sign in, WordPress sets its usual sign-in cookies so the site knows it is you. Logging out removes them.</p>'
		. '<p>If you reset your password, the site sets a cookie that holds your reset link while you choose a new password. It is removed when you finish, or when you close your browser.</p>'
		. '<p>If you switch the tool catalog between its cards, table and compact views, a cookie called mtl_catalog_view remembers which one you picked for one year. It holds only the name of the view, nothing about you, and is set only when you use the view switch.</p>'
		. '<h2>Fonts</h2>'
		. '<p>The fonts on the tool library pages are served from this website, so loading a page does not contact any other site.</p>';

	wp_add_privacy_policy_content( 'My Tool Library', wp_kses_post( $content ) );
}
