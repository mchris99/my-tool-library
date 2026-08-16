<?php
/**
 * Setup admin page.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize a free-text font-family/font-size value for safe use inside an
 * inline <style> block (see mtl_apply_custom_admin_styles() in
 * my-tool-library.php). sanitize_text_field() alone would not strip
 * characters like { } ; ( ) that could break out of a CSS declaration, so
 * anything outside letters, digits, spaces, and a small set of punctuation
 * is dropped rather than rejecting the whole value.
 *
 * @param string $value Raw posted value.
 * @return string Sanitized value safe to echo into CSS.
 */
function mtl_sanitize_css_value( $value ) {
	$value = sanitize_text_field( wp_unslash( $value ) );
	return preg_replace( '/[^a-zA-Z0-9 ,.\-\%\'"]/', '', $value );
}

/**
 * Quick-pick font stacks offered for the Header/Body/Link font fields.
 * Plain web-safe stacks only, never a webfont from Google Fonts or any
 * other CDN, per the plugin's no-3rd-party-dependencies rule. The <select>
 * itself is never submitted; picking an option just fills in the adjacent
 * text field via JS, which remains the actual saved value.
 *
 * @return array<string,string> CSS font-family value => display label.
 */
function mtl_font_preset_options() {
	return array(
		''                                       => 'Quick pick a font…',
		'inherit'                                => 'Inherit (WordPress Default)',
		'Arial, Helvetica, sans-serif'           => 'Arial (Sans-serif)',
		"'Trebuchet MS', sans-serif"             => 'Trebuchet MS',
		'Verdana, Geneva, sans-serif'            => 'Verdana',
		"Georgia, 'Times New Roman', serif"      => 'Georgia (Serif)',
		"'Lexend', 'Century Gothic', sans-serif" => 'Lexend / Rounded',
		"'Courier New', Courier, monospace"      => 'Courier New (Monospace)',
	);
}

/**
 * Single source of truth for the Export Data feature: every My Tool Library
 * table, as bare names (no wp_ prefix), ordered parents-before-children so a
 * re-import with FK checks on still succeeds. `loan_returns` is intentionally
 * absent, since schema.sql drops that table but never creates it.
 *
 * @return string[] Bare table names.
 */
function mtl_export_table_names() {
	return array(
		'members',
		'member_verifications',
		'member_agreements',
		'member_agreement_acceptances',
		'member_trainings',
		'member_training_mappings',
		'tool_inventory',
		'tool_categories',
		'tool_category_mappings',
		'tool_tags',
		'tool_tag_mappings',
		'loans',
		'tool_reservations',
	);
}

/**
 * A usable #rrggbb colour, falling back to a default.
 *
 * <input type="color"> has no empty state: given anything that is not a valid
 * #rrggbb value it displays #000000, and saving the form then persists that
 * black. So a stored colour that is empty or malformed has to become the
 * documented default before it is rendered into the field, or one unrelated
 * save turns the whole site black.
 *
 * @param string $value   Stored option value.
 * @param string $fallback Colour to use when $value is unusable.
 * @return string A valid #rrggbb colour.
 */
function mtl_color_or_default( $value, $fallback ) {
	$value = sanitize_hex_color( trim( (string) $value ) );
	return ( is_string( $value ) && '' !== $value ) ? $value : $fallback;
}

/**
 * The exact phrase an admin must type to confirm the destructive database
 * reset.
 *
 * @return string
 */
function mtl_db_reset_confirmation_phrase() {
	return 'Delete ALL my data';
}

/**
 * The "attach a file" control shared by the add and edit agreement forms.
 *
 * Renders a hidden attachment_id, a readout of the current choice and the two
 * buttons that drive the Media Library modal. The modal is unfiltered, offering both
 * the Upload Files and Media Library tabs and any file type, because a library
 * may reasonably attach a PDF, a scanned form or an image.
 *
 * @param string $field_id      Unique DOM id prefix for this instance.
 * @param int    $attachment_id Currently attached file, or 0 for none.
 */
function mtl_render_agreement_file_picker( $field_id, $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	$file_url      = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : '';
	$file_name     = $file_url ? basename( wp_parse_url( $file_url, PHP_URL_PATH ) ) : '';
	?>
	<div class="mtl-agreement-file-picker" data-mtl-picker="<?php echo esc_attr( $field_id ); ?>">
		<p style="margin-bottom: 4px;"><strong>Attached file</strong> (optional)</p>
		<input type="hidden" name="agreement_attachment_id" id="<?php echo esc_attr( $field_id ); ?>-id" value="<?php echo esc_attr( $attachment_id > 0 ? $attachment_id : '' ); ?>">
		<p style="margin: 0 0 6px 0;">
			<span id="<?php echo esc_attr( $field_id ); ?>-name" style="font-family: monospace;">
				<?php echo $file_name ? esc_html( $file_name ) : '(none chosen)'; ?>
			</span>
			<button type="button" class="button mtl-agreement-file-select" data-target="<?php echo esc_attr( $field_id ); ?>">Select or upload file</button>
			<button type="button" class="button mtl-agreement-file-remove" data-target="<?php echo esc_attr( $field_id ); ?>" <?php echo $attachment_id > 0 ? '' : 'style="display:none;"'; ?>>Remove file</button>
		</p>
		<!-- A standing note, not a dismissible one, placed where the file is
			chosen, because that is the moment the mistake gets made. -->
		<p style="margin: 0; font-size: 0.85em; color: #8a6d3b; background: #fcf8e3; border-left: 4px solid #dba617; padding: 6px 10px;">
			Anyone with the link can open this file, whether or not they have an account. Do not attach anything that should not be public.
		</p>
		<noscript>
			<p style="font-size: 0.85em; color: #666;">Choosing a file needs JavaScript. Upload it under <strong>Media &rarr; Add New</strong> first, then come back with JavaScript enabled.</p>
		</noscript>
	</div>
	<?php
}

add_action( 'admin_init', 'mtl_maybe_export_data' );

/**
 * Serve the Export Data downloads (.sql dump or .zip of CSVs). Must run on
 * admin_init, before any admin HTML is sent, so it can emit
 * file-download headers and a raw body.
 */
function mtl_maybe_export_data() {
	$want_sql = isset( $_POST['mtl_export_sql'] );
	$want_zip = isset( $_POST['mtl_export_zip'] );
	if ( ! $want_sql && ! $want_zip ) {
		return;
	}

	// Exporting exposes ALL member data (including sensitive verification
	// document URLs), so gate it on the admin capability AND a valid nonce.
	if ( ! mtl_can_manage_settings() ) {
		return;
	}
	if ( ! isset( $_POST['mtl_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_export_nonce'] ) ), 'mtl_export_action' ) ) {
		return;
	}

	if ( $want_sql ) {
		mtl_export_as_sql( mtl_export_table_names() );
	} else {
		mtl_export_as_zip( mtl_export_table_names() );
	}
	// Both helpers stream a file and exit; execution never returns here.
}

/**
 * Stream a MySQL-style .sql dump (DROP + CREATE + INSERTs) for every table.
 * Table names keep the WordPress prefix (e.g. wp_members) so re-running the
 * dump restores the tables in place, matching schema.sql. (The CSV/zip
 * export uses bare names instead.)
 *
 * @param string[] $bare_tables Bare table names, see mtl_export_table_names().
 */
function mtl_export_as_sql( $bare_tables ) {
	global $wpdb;
	$prefix = $wpdb->prefix;

	// Discard any buffered output so nothing corrupts the file body.
	while ( ob_get_level() ) {
		ob_end_clean();
	}

	nocache_headers();
	header( 'Content-Type: application/sql; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="my-tool-library-export-' . gmdate( 'Y-m-d' ) . '.sql"' );

	$out  = "-- My Tool Library data export\n";
	$out .= '-- Generated ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
	$out .= '-- Table names keep the WordPress "' . $prefix . "\" prefix, matching how the\n";
	$out .= "-- plugin creates them in schema.sql.\n\n";
	$out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

	// $full below is always a trusted prefix + hardcoded bare name from
	// mtl_export_table_names() (no user input), so it's safe to interpolate
	// into these backtick-quoted identifiers; phpcs can't verify that.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $bare_tables as $bare ) {
		$full = $prefix . $bare;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
		if ( ! $exists ) {
			$out .= "-- (table `$full` not found in the database; skipped)\n\n";
			continue;
		}

		$out .= "-- ------------------------------------------------------------\n";
		$out .= "-- Table: $full\n";
		$out .= "-- ------------------------------------------------------------\n";
		$out .= "DROP TABLE IF EXISTS `$full`;\n";

		// Used verbatim: SHOW CREATE TABLE already emits the prefixed name
		// and any prefixed FK references, keeping the dump aligned with the real tables.
		$create_row = $wpdb->get_row( "SHOW CREATE TABLE `$full`", ARRAY_N );
		$create_sql = isset( $create_row[1] ) ? $create_row[1] : '';
		$out       .= $create_sql . ";\n\n";

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$full`" );
		$rows = $wpdb->get_results( "SELECT * FROM `$full`", ARRAY_A );

		if ( $rows ) {
			$col_list = '`' . implode( '`, `', $cols ) . '`';
			foreach ( $rows as $row ) {
				$vals = array();
				foreach ( $cols as $col ) {
					$v      = array_key_exists( $col, $row ) ? $row[ $col ] : null;
					$vals[] = ( null === $v ) ? 'NULL' : "'" . esc_sql( $v ) . "'";
				}
				$out .= "INSERT INTO `$full` ($col_list) VALUES (" . implode( ', ', $vals ) . ");\n";
			}
			$out .= "\n";
		}
	}

	$out .= "SET FOREIGN_KEY_CHECKS=1;\n";

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this *is* the file being downloaded (a raw .sql dump), not HTML; escaping would corrupt it.
	echo $out;
	exit;
}

/**
 * Stream a .zip containing one CSV per table (bare table name + ".csv").
 * Uses a small self-contained ZIP writer so it depends on nothing beyond
 * core PHP; ZipArchive is not required.
 *
 * @param string[] $bare_tables Bare table names, see mtl_export_table_names().
 */
function mtl_export_as_zip( $bare_tables ) {
	global $wpdb;
	$prefix = $wpdb->prefix;

	$files = array();
	foreach ( $bare_tables as $bare ) {
		$full   = $prefix . $bare;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
		if ( ! $exists ) {
			continue;
		}

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$full`" );
		$rows = $wpdb->get_results( "SELECT * FROM `$full`", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// fputcsv into a memory stream so quoting/escaping matches the inventory import's expectations.
		$fp = fopen( 'php://temp', 'r+' );
		fputcsv( $fp, $cols );
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$line = array();
				foreach ( $cols as $col ) {
					$line[] = array_key_exists( $col, $row ) ? $row[ $col ] : '';
				}
				fputcsv( $fp, $line );
			}
		}
		rewind( $fp );
		$files[ $bare . '.csv' ] = stream_get_contents( $fp );
		fclose( $fp );
	}

	$zip = mtl_build_zip( $files );

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="my-tool-library-export-' . gmdate( 'Y-m-d' ) . '.zip"' );
	header( 'Content-Length: ' . strlen( $zip ) );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this *is* the file being downloaded (a raw .zip binary), not HTML; escaping would corrupt it.
	echo $zip;
	exit;
}

/**
 * Minimal pure-PHP ZIP archive builder (STORE method, no compression).
 * Dependency-free so the export works even where the ZipArchive extension
 * is unavailable.
 *
 * @param array<string,string> $files Filename => raw file contents.
 * @return string Raw .zip bytes.
 */
function mtl_build_zip( $files ) {
	$local_data  = '';
	$central_dir = '';
	$offset      = 0;

	// DOS-format modification date/time stamp (shared by all entries).
	$now      = getdate();
	$dos_time = ( $now['hours'] << 11 ) | ( $now['minutes'] << 5 ) | (int) ( $now['seconds'] / 2 );
	$dos_date = ( ( $now['year'] - 1980 ) << 9 ) | ( $now['mon'] << 5 ) | $now['mday'];

	foreach ( $files as $name => $data ) {
		$crc      = crc32( $data );
		$size     = strlen( $data );
		$name_len = strlen( $name );

		// --- Local file header + file data ---
		$header  = pack( 'V', 0x04034b50 ); // Local file header signature.
		$header .= pack( 'v', 20 );         // Version needed to extract.
		$header .= pack( 'v', 0 );          // General purpose bit flag.
		$header .= pack( 'v', 0 );          // Compression method: 0 = store.
		$header .= pack( 'v', $dos_time );
		$header .= pack( 'v', $dos_date );
		$header .= pack( 'V', $crc );
		$header .= pack( 'V', $size );      // Compressed size (= size, stored).
		$header .= pack( 'V', $size );      // Uncompressed size.
		$header .= pack( 'v', $name_len );
		$header .= pack( 'v', 0 );          // Extra field length.
		$header .= $name;

		$local_data .= $header . $data;

		// --- Central directory record for this file ---
		$record  = pack( 'V', 0x02014b50 ); // Central file header signature.
		$record .= pack( 'v', 20 );         // Version made by.
		$record .= pack( 'v', 20 );         // Version needed to extract.
		$record .= pack( 'v', 0 );          // General purpose bit flag.
		$record .= pack( 'v', 0 );          // Compression method.
		$record .= pack( 'v', $dos_time );
		$record .= pack( 'v', $dos_date );
		$record .= pack( 'V', $crc );
		$record .= pack( 'V', $size );
		$record .= pack( 'V', $size );
		$record .= pack( 'v', $name_len );
		$record .= pack( 'v', 0 );          // Extra field length.
		$record .= pack( 'v', 0 );          // File comment length.
		$record .= pack( 'v', 0 );          // Disk number start.
		$record .= pack( 'v', 0 );          // Internal file attributes.
		$record .= pack( 'V', 0 );          // External file attributes.
		$record .= pack( 'V', $offset );    // Relative offset of local header.
		$record .= $name;

		$central_dir .= $record;
		$offset      += strlen( $header ) + $size;
	}

	// --- End of central directory record ---
	$eocd  = pack( 'V', 0x06054b50 );
	$eocd .= pack( 'v', 0 );                 // Number of this disk.
	$eocd .= pack( 'v', 0 );                 // Disk with central directory.
	$eocd .= pack( 'v', count( $files ) );     // Entries on this disk.
	$eocd .= pack( 'v', count( $files ) );     // Total entries.
	$eocd .= pack( 'V', strlen( $central_dir ) );
	$eocd .= pack( 'V', $offset );           // Offset of central directory.
	$eocd .= pack( 'v', 0 );                 // Comment length.

	return $local_data . $central_dir . $eocd;
}

/**
 * Renders the Setup & Settings admin page.
 */
function mtl_render_setup_page() {
	global $wpdb;

	// Administrators only. WordPress already refuses to route an Editor here
	// (the page is registered against manage_options in
	// mtl_register_admin_menus()), so this is defence in depth rather than the
	// gate itself, and it keeps the guarantee local to the file, where every
	// handler below repeats it.
	if ( ! mtl_can_manage_settings() ) {
		return;
	}

	$tbl_categories = $wpdb->prefix . 'tool_categories';
	$tbl_tags       = $wpdb->prefix . 'tool_tags';
	$tbl_trainings  = $wpdb->prefix . 'member_trainings';

	// The Member Agreements file picker uses the Media Library modal. Enqueued
	// here rather than on admin_enqueue_scripts because this callback runs
	// before the footer, where the media templates and scripts print.
	wp_enqueue_media();

	echo '<div class="wrap mtl-admin-wrapper">';
	echo '<h2>My Tool Library Setup & Settings</h2>';

	// ==========================================
	// 1. HANDLE SETTINGS FORM SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_save_settings'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_settings_nonce'] ) ), 'mtl_save_settings_action' ) ) {

			// General.
			update_option( 'mtl_org_name', isset( $_POST['mtl_org_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_org_name'] ) ) : '' );
			update_option( 'mtl_contact_email', isset( $_POST['mtl_contact_email'] ) ? sanitize_email( wp_unslash( $_POST['mtl_contact_email'] ) ) : '' );
			update_option( 'mtl_currency_symbol', isset( $_POST['mtl_currency_symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_currency_symbol'] ) ) : '' );
			update_option( 'mtl_logo_url', isset( $_POST['mtl_logo_url'] ) ? sanitize_url( wp_unslash( $_POST['mtl_logo_url'] ) ) : '' );
			update_option( 'mtl_verified_badge_image_url', isset( $_POST['mtl_verified_badge_image_url'] ) ? sanitize_url( wp_unslash( $_POST['mtl_verified_badge_image_url'] ) ) : '' );

			// Header Options.
			// Colours resolve to their default rather than to '' when missing or
			// malformed. An empty colour option renders a black swatch in
			// <input type="color">, which the next save then persists; see
			// mtl_color_or_default().
			update_option( 'mtl_header_color', mtl_color_or_default( isset( $_POST['mtl_header_color'] ) ? wp_unslash( $_POST['mtl_header_color'] ) : '', '#ff6600' ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- mtl_sanitize_css_value() unslashes and sanitizes internally.
			update_option( 'mtl_header_font', isset( $_POST['mtl_header_font'] ) ? mtl_sanitize_css_value( $_POST['mtl_header_font'] ) : '' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- mtl_sanitize_css_value() unslashes and sanitizes internally.
			update_option( 'mtl_header_size', isset( $_POST['mtl_header_size'] ) ? mtl_sanitize_css_value( $_POST['mtl_header_size'] ) : '' );

			// <select>-backed values are whitelisted server-side rather than trusted outright.
			$allowed_h_weights = array( '400', '600', '700' );
			$posted_h_weight   = isset( $_POST['mtl_header_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_header_weight'] ) ) : '';
			update_option( 'mtl_header_weight', in_array( $posted_h_weight, $allowed_h_weights, true ) ? $posted_h_weight : '700' );

			$allowed_transforms = array( 'none', 'uppercase', 'capitalize', 'lowercase' );
			$posted_transform   = isset( $_POST['mtl_header_transform'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_header_transform'] ) ) : '';
			update_option( 'mtl_header_transform', in_array( $posted_transform, $allowed_transforms, true ) ? $posted_transform : 'none' );

			// Body Options.
			update_option( 'mtl_body_color', mtl_color_or_default( isset( $_POST['mtl_body_color'] ) ? wp_unslash( $_POST['mtl_body_color'] ) : '', '#096491' ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- mtl_sanitize_css_value() unslashes and sanitizes internally.
			update_option( 'mtl_body_font', isset( $_POST['mtl_body_font'] ) ? mtl_sanitize_css_value( $_POST['mtl_body_font'] ) : '' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- mtl_sanitize_css_value() unslashes and sanitizes internally.
			update_option( 'mtl_body_size', isset( $_POST['mtl_body_size'] ) ? mtl_sanitize_css_value( $_POST['mtl_body_size'] ) : '' );

			$allowed_b_weights = array( '300', '400', '700' );
			$posted_b_weight   = isset( $_POST['mtl_body_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_body_weight'] ) ) : '';
			update_option( 'mtl_body_weight', in_array( $posted_b_weight, $allowed_b_weights, true ) ? $posted_b_weight : '400' );

			// Link Options.
			update_option( 'mtl_link_color', mtl_color_or_default( isset( $_POST['mtl_link_color'] ) ? wp_unslash( $_POST['mtl_link_color'] ) : '', '#00b3ff' ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- mtl_sanitize_css_value() unslashes and sanitizes internally.
			update_option( 'mtl_link_font', isset( $_POST['mtl_link_font'] ) ? mtl_sanitize_css_value( $_POST['mtl_link_font'] ) : '' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- mtl_sanitize_css_value() unslashes and sanitizes internally.
			update_option( 'mtl_link_size', isset( $_POST['mtl_link_size'] ) ? mtl_sanitize_css_value( $_POST['mtl_link_size'] ) : '' );

			$allowed_decorations = array( 'none', 'underline' );
			$posted_decoration   = isset( $_POST['mtl_link_decoration'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_link_decoration'] ) ) : '';
			update_option( 'mtl_link_decoration', in_array( $posted_decoration, $allowed_decorations, true ) ? $posted_decoration : 'none' );

			// Buttons & Page Accents.
			update_option( 'mtl_accent_color', mtl_color_or_default( isset( $_POST['mtl_accent_color'] ) ? wp_unslash( $_POST['mtl_accent_color'] ) : '', '#f7c600' ) );
			update_option( 'mtl_background_color', mtl_color_or_default( isset( $_POST['mtl_background_color'] ) ? wp_unslash( $_POST['mtl_background_color'] ) : '', '#ffffff' ) );

			$allowed_radii = array( '0px', '4px', '10px', '999px' );
			$posted_radius = isset( $_POST['mtl_border_radius'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_border_radius'] ) ) : '';
			update_option( 'mtl_border_radius', in_array( $posted_radius, $allowed_radii, true ) ? $posted_radius : '4px' );

			// Stored as a plain multiplier for calc() when styles are injected; whitelisted since it lands directly in a CSS rule.
			$allowed_btn_scales = array( '1.25', '1', '0.85', '0.7' );
			$posted_btn_scale   = isset( $_POST['mtl_button_scale'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_button_scale'] ) ) : '';
			update_option( 'mtl_button_scale', in_array( $posted_btn_scale, $allowed_btn_scales, true ) ? $posted_btn_scale : '1' );

			// Reservations & Loans.
			// Lands directly in date math (strtotime("+{$n} days")) on the
			// Loans & Reservations and Inventory pages, so it is whitelisted rather than trusted.
			$allowed_loan_days = array( '7', '14', '21', '30' );
			$posted_loan_days  = isset( $_POST['mtl_default_loan_days'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_default_loan_days'] ) ) : '';
			update_option( 'mtl_default_loan_days', in_array( $posted_loan_days, $allowed_loan_days, true ) ? $posted_loan_days : '21' );

			// Reservation hold period. Stored as a plain integer, with 0
			// meaning "never expires". The "Never expires" checkbox wins over
			// whatever number the stepper happens to be showing, since that
			// input is disabled (and so not submitted) while it is ticked.
			// Anything outside 1-365 falls back to the 14-day default rather
			// than being clamped silently to a value nobody chose.
			if ( isset( $_POST['mtl_reservation_hold_never'] ) ) {
				update_option( 'mtl_reservation_hold_days', 0 );
			} else {
				$posted_hold_days = isset( $_POST['mtl_reservation_hold_days'] ) ? (int) $_POST['mtl_reservation_hold_days'] : 14;
				if ( $posted_hold_days < 1 || $posted_hold_days > 365 ) {
					$posted_hold_days = 14;
				}
				update_option( 'mtl_reservation_hold_days', $posted_hold_days );
			}

			// A saved blank value is meaningful, not "unset": get_option()'s
			// default only applies before the option row exists, so an empty
			// save sticks and intentionally hides the directions on the public pages.
			update_option( 'mtl_pickup_directions', isset( $_POST['mtl_pickup_directions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_pickup_directions'] ) ) : '' );
			update_option( 'mtl_verification_directions', isset( $_POST['mtl_verification_directions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_verification_directions'] ) ) : '' );
			update_option( 'mtl_giving_text', isset( $_POST['mtl_giving_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_giving_text'] ) ) : '' );

			// The giving link is stored normalized so the member-facing button
			// can never point somewhere unexpected. mtl_normalize_giving_url()
			// drops anything that is not http/https, so a pasted "javascript:" or
			// "data:" URL saves as blank rather than becoming a button.
			update_option(
				'mtl_giving_url',
				isset( $_POST['mtl_giving_url'] )
					? mtl_normalize_giving_url( sanitize_text_field( wp_unslash( $_POST['mtl_giving_url'] ) ) )
					: ''
			);

			echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Settings have been saved.</p></div>';
		}
	}

	// ==========================================
	// 1B. HANDLE HOME PAGE LINK SUBMISSION
	// ==========================================
	// Own form/nonce/option, separate from the General Details form above,
	// so saving just this field can never blank out the other settings.
	if ( isset( $_POST['mtl_save_home_url'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_home_url_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_home_url_nonce'] ) ), 'mtl_save_home_url_action' ) ) {
			update_option( 'mtl_home_url', isset( $_POST['mtl_home_url'] ) ? sanitize_url( wp_unslash( $_POST['mtl_home_url'] ) ) : '' );
			echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Home page link has been saved.</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 2. HANDLE "ADD CATEGORY" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_add_category'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_add_category_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_add_category_nonce'] ) ), 'mtl_add_category_action' ) ) {
			$new_category_name = isset( $_POST['new_category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_category_name'] ) ) : '';

			if ( '' === $new_category_name ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please enter a category name.</p></div>';
			} elseif ( strlen( $new_category_name ) > 50 ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Category names must be 50 characters or fewer.</p></div>';
			} else {
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
						"SELECT category_id FROM {$tbl_categories} WHERE category_name = %s LIMIT 1",
						$new_category_name
					)
				);

				if ( $existing ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That category already exists.</p></div>';
				} else {
					// category_id is AUTO_INCREMENT, so MySQL assigns the next id.
					$inserted = $wpdb->insert(
						$tbl_categories,
						array( 'category_name' => $new_category_name ),
						array( '%s' )
					);

					if ( $inserted ) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Category &ldquo;' . esc_html( $new_category_name ) . '&rdquo; has been added. It will now show up when adding or editing tools in the Inventory tab.</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Failed to add category. Please try again.</p></div>';
					}
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 3. HANDLE "ADD TAG" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_add_tag'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_add_tag_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_add_tag_nonce'] ) ), 'mtl_add_tag_action' ) ) {
			$new_tag_name = isset( $_POST['new_tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_tag_name'] ) ) : '';

			if ( '' === $new_tag_name ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please enter a tag name.</p></div>';
			} elseif ( strlen( $new_tag_name ) > 50 ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Tag names must be 50 characters or fewer.</p></div>';
			} else {
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
						"SELECT tag_id FROM {$tbl_tags} WHERE tag_name = %s LIMIT 1",
						$new_tag_name
					)
				);

				if ( $existing ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That tag already exists.</p></div>';
				} else {
					// tag_id is AUTO_INCREMENT, so MySQL assigns the next id.
					$inserted = $wpdb->insert(
						$tbl_tags,
						array( 'tag_name' => $new_tag_name ),
						array( '%s' )
					);

					if ( $inserted ) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Tag &ldquo;' . esc_html( $new_tag_name ) . '&rdquo; has been added. It will now show up when adding or editing tools in the Inventory tab.</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Failed to add tag. Please try again.</p></div>';
					}
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 2B. HANDLE "DELETE CATEGORIES" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_delete_categories'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_delete_categories_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_categories_nonce'] ) ), 'mtl_delete_categories_action' ) ) {
			$delete_category_ids = isset( $_POST['delete_category_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['delete_category_ids'] ) ) : array();
			$delete_category_ids = array_filter(
				$delete_category_ids,
				function ( $id ) {
					return $id > 0;
				}
			);

			if ( empty( $delete_category_ids ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> No categories were selected.</p></div>';
			} else {
				// Deleting a category cascades to tool_category_mappings (see
				// schema.sql), so any tool using it simply loses that category
				// It does not fail or delete the tool itself.
				$deleted_count = 0;
				foreach ( $delete_category_ids as $id ) {
					if ( $wpdb->delete( $tbl_categories, array( 'category_id' => $id ), array( '%d' ) ) ) {
						++$deleted_count;
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p><strong>Removed.</strong> ' . intval( $deleted_count ) . ' categor' . ( 1 === $deleted_count ? 'y' : 'ies' ) . ' deleted. Any tools that had it were automatically un-categorized from it.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 3B. HANDLE "DELETE TAGS" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_delete_tags'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_delete_tags_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_tags_nonce'] ) ), 'mtl_delete_tags_action' ) ) {
			$delete_tag_ids = isset( $_POST['delete_tag_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['delete_tag_ids'] ) ) : array();
			$delete_tag_ids = array_filter(
				$delete_tag_ids,
				function ( $id ) {
					return $id > 0;
				}
			);

			if ( empty( $delete_tag_ids ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> No tags were selected.</p></div>';
			} else {
				// Deleting a tag cascades to tool_tag_mappings (see
				// schema.sql), so any tool using it simply loses that tag;
				// it does not fail or delete the tool itself.
				$deleted_count = 0;
				foreach ( $delete_tag_ids as $id ) {
					if ( $wpdb->delete( $tbl_tags, array( 'tag_id' => $id ), array( '%d' ) ) ) {
						++$deleted_count;
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p><strong>Removed.</strong> ' . intval( $deleted_count ) . ' tag' . ( 1 === $deleted_count ? '' : 's' ) . ' deleted. Any tools that had it were automatically untagged.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 3C. HANDLE "ADD TRAINING" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_add_training'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_add_training_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_add_training_nonce'] ) ), 'mtl_add_training_action' ) ) {
			$new_training_name = isset( $_POST['new_training_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_training_name'] ) ) : '';

			if ( '' === $new_training_name ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please enter a training name.</p></div>';
			} elseif ( strlen( $new_training_name ) > 50 ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Training names must be 50 characters or fewer.</p></div>';
			} else {
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
						"SELECT training_id FROM {$tbl_trainings} WHERE training_name = %s LIMIT 1",
						$new_training_name
					)
				);

				if ( $existing ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That training already exists.</p></div>';
				} else {
					// training_id is AUTO_INCREMENT, so MySQL assigns the next id.
					$inserted = $wpdb->insert(
						$tbl_trainings,
						array( 'training_name' => $new_training_name ),
						array( '%s' )
					);

					if ( $inserted ) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Training &ldquo;' . esc_html( $new_training_name ) . '&rdquo; has been added. It will now show up when adding or editing members in the Membership tab.</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Failed to add training. Please try again.</p></div>';
					}
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 3D. HANDLE "DELETE TRAININGS" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_delete_trainings'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_delete_trainings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_trainings_nonce'] ) ), 'mtl_delete_trainings_action' ) ) {
			$delete_training_ids = isset( $_POST['delete_training_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['delete_training_ids'] ) ) : array();
			$delete_training_ids = array_filter(
				$delete_training_ids,
				function ( $id ) {
					return $id > 0;
				}
			);

			if ( empty( $delete_training_ids ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> No trainings were selected.</p></div>';
			} else {
				// Deleting a training cascades to member_training_mappings (see
				// schema.sql), so any member who had completed it simply loses
				// that record; it does not fail or delete the member.
				$deleted_count = 0;
				foreach ( $delete_training_ids as $id ) {
					if ( $wpdb->delete( $tbl_trainings, array( 'training_id' => $id ), array( '%d' ) ) ) {
						++$deleted_count;
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p><strong>Removed.</strong> ' . intval( $deleted_count ) . ' training' . ( 1 === $deleted_count ? '' : 's' ) . ' deleted. Any members who had completed it no longer show it.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 3E. HANDLE "SAVE TRAININGS" SUBMISSION
	// One bulk save covering every training's name, badge image and
	// certification length at once. Unlike Categories/Tags (add-or-delete
	// only), trainings are editable in place: a badge image or a renewal
	// period can reasonably change long after the training was created, and
	// re-creating the training to change one would orphan every member's
	// completion record via the ON DELETE CASCADE.
	// ==========================================
	if ( isset( $_POST['mtl_save_trainings'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_save_trainings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_save_trainings_nonce'] ) ), 'mtl_save_trainings_action' ) ) {
			// Three parallel arrays keyed by training_id, one per table row
			// below. Each is sanitized as it is read, with (string) first in case
			// a malformed request nests an array under one of the ids, since
			// both sanitize_url() and sanitize_text_field() would misbehave on
			// a non-string.
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is sanitized inside the closures immediately below; the sniff cannot see through a closure.
			$posted_names = isset( $_POST['training_name'] ) && is_array( $_POST['training_name'] )
				? array_map(
					function ( $mtl_raw ) {
						return sanitize_text_field( (string) $mtl_raw );
					},
					wp_unslash( $_POST['training_name'] )
				)
				: array();

			$posted_badges = isset( $_POST['training_badge_url'] ) && is_array( $_POST['training_badge_url'] )
				? array_map(
					function ( $mtl_raw_url ) {
						return sanitize_url( (string) $mtl_raw_url );
					},
					wp_unslash( $_POST['training_badge_url'] )
				)
				: array();

			$posted_lengths = isset( $_POST['training_cert_months'] ) && is_array( $_POST['training_cert_months'] )
				? array_map(
					function ( $mtl_raw ) {
						return sanitize_text_field( (string) $mtl_raw );
					},
					wp_unslash( $_POST['training_cert_months'] )
				)
				: array();
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			// Names are UNIQUE in the schema, so a rename that collides has to
			// be caught before any write; otherwise the first few rows save
			// and the clashing one silently doesn't, leaving the admin looking
			// at a half-applied form.
			$seen_names   = array();
			$name_error   = '';
			$rows_to_save = array();
			foreach ( $posted_names as $posted_id => $posted_name ) {
				$posted_id   = (int) $posted_id;
				$posted_name = trim( $posted_name );
				if ( $posted_id <= 0 ) {
					continue;
				}
				if ( '' === $posted_name ) {
					$name_error = 'Training names cannot be blank. Nothing was saved.';
					break;
				}
				if ( strlen( $posted_name ) > 50 ) {
					$name_error = 'Training names must be 50 characters or fewer. Nothing was saved.';
					break;
				}
				$name_key = strtolower( $posted_name );
				if ( isset( $seen_names[ $name_key ] ) ) {
					$name_error = 'Two trainings cannot share the name "' . $posted_name . '". Nothing was saved.';
					break;
				}
				$seen_names[ $name_key ] = true;

				// Blank / 0 / negative all mean "never expires" (NULL).
				$raw_len = isset( $posted_lengths[ $posted_id ] ) ? trim( $posted_lengths[ $posted_id ] ) : '';
				$months  = ( '' === $raw_len ) ? 0 : (int) $raw_len;
				if ( $months > 600 ) {
					$name_error = 'A certification length of ' . $months . ' months is not realistic (max 600). Nothing was saved.';
					break;
				}

				$url = isset( $posted_badges[ $posted_id ] ) ? $posted_badges[ $posted_id ] : '';

				$rows_to_save[ $posted_id ] = array(
					'training_name'               => $posted_name,
					'badge_image_url'             => ( '' !== $url ? $url : null ),
					'certification_length_months' => ( $months > 0 ? $months : null ),
				);
			}

			if ( '' !== $name_error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html( $name_error ) . '</p></div>';
			} else {
				foreach ( $rows_to_save as $save_id => $save_data ) {
					$wpdb->update(
						$tbl_trainings,
						$save_data,
						array( 'training_id' => $save_id ),
						array( '%s', '%s', '%d' ),
						array( '%d' )
					);
				}
				echo '<div class="notice notice-success is-dismissible"><p><strong>Saved.</strong> Trainings have been updated. Changing a certification length immediately re-dates every member who holds that training.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// HANDLE MEMBER AGREEMENTS SUBMISSIONS
	//
	// Each mutation flushes the agreements cache: the mode and the active count
	// are memoised per request and either can go stale the instant one runs.
	//
	// $mtl_agreement_edit_id / $mtl_agreement_conflict carry state down to the
	// render section: which agreement to open the edit form for, and whether
	// the last save lost a race with another admin.
	// ==========================================
	$tbl_agreements  = $wpdb->prefix . 'member_agreements';
	$tbl_acceptances = $wpdb->prefix . 'member_agreement_acceptances';

	$mtl_agreement_edit_id   = isset( $_GET['mtl_edit_agreement'] ) ? absint( $_GET['mtl_edit_agreement'] ) : 0;
	$mtl_agreement_conflict  = null;
	$mtl_agreement_add_open  = false;
	$mtl_agreement_form_text = '';

	// ---- Mode ----------------------------------------------------------
	//
	// Its own form rather than riding along with Save Settings: paper -> full
	// needs a confirmation, and hanging that off the button that also saves
	// branding would fire it on unrelated saves.
	if ( isset( $_POST['mtl_save_agreements_mode'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_agreements_mode_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_agreements_mode_nonce'] ) ), 'mtl_agreements_mode_action' ) ) {
			$posted_mode = isset( $_POST['mtl_agreements_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_agreements_mode'] ) ) : '';

			// Whitelisted server-side. An unrecognised value is not saved at
			// all rather than coerced. mtl_agreements_mode() would read it
			// as `off`, but storing a value the plugin does not understand
			// makes the Setup page disagree with the database.
			if ( in_array( $posted_mode, array( 'off', 'paper', 'full' ), true ) ) {
				update_option( 'mtl_agreements_mode', $posted_mode );

				// Saved in every mode, so the choice survives a trip through
				// paper or off and comes back as it was set. Paper mode reads it
				// but does not obey it; see mtl_agreements_staff_recording().
				$posted_allow_paper = isset( $_POST['mtl_agreements_allow_paper'] ) ? '1' : '';
				update_option( 'mtl_agreements_allow_paper', $posted_allow_paper );

				mtl_agreements_flush_cache();

				if ( 'full' === $posted_mode ) {
					$mtl_desk_sentence = '1' === $posted_allow_paper
						? ' Staff can also record signed paper at the desk.'
						: ' Staff cannot record signed paper. Tick <em>Allow paper tracking</em> if they need to.';
					echo '<div class="notice notice-success is-dismissible"><p><strong>Saved.</strong> Members now agree online. Anyone who is not up to date cannot reserve a tool until they agree. No one has been emailed. Send agreement requests from the Membership page.' . wp_kses_post( $mtl_desk_sentence ) . '</p></div>';
				} elseif ( 'paper' === $posted_mode ) {
					echo '<div class="notice notice-success is-dismissible"><p><strong>Saved.</strong> Staff record signed paper agreements. Members are not asked to agree on the website and are never blocked from reserving.</p></div>';
				} else {
					echo '<div class="notice notice-success is-dismissible"><p><strong>Saved.</strong> Member agreements are off. Nothing is recorded or shown, and no existing record has been deleted.</p></div>';
				}
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That is not a valid mode. Nothing was changed.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ---- Add an agreement ----------------------------------------------
	if ( isset( $_POST['mtl_add_agreement'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_add_agreement_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_add_agreement_nonce'] ) ), 'mtl_add_agreement_action' ) ) {
			// sanitize_textarea_field() rather than sanitize_text_field(): the
			// text is stored and rendered as plain text but line breaks are
			// meaningful and must survive.
			$new_text    = isset( $_POST['agreement_text'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['agreement_text'] ) ) ) : '';
			$new_file_id = isset( $_POST['agreement_attachment_id'] ) ? absint( $_POST['agreement_attachment_id'] ) : 0;
			$new_file_id = ( $new_file_id > 0 && 'attachment' === get_post_type( $new_file_id ) ) ? $new_file_id : 0;

			if ( '' === $new_text ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Enter the text members have to agree to.</p></div>';
				$mtl_agreement_add_open = true;
			} elseif ( mb_strlen( $new_text ) > MTL_AGREEMENT_TEXT_MAXLENGTH ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That text is too long. Keep it under ' . esc_html( number_format_i18n( MTL_AGREEMENT_TEXT_MAXLENGTH ) ) . ' characters so the signup form stays readable.</p></div>';
				$mtl_agreement_add_open  = true;
				$mtl_agreement_form_text = $new_text;
			} else {
				// sort_order is one past the current maximum, so a new
				// agreement appends. Gaps are expected, since retiring never
				// renumbers, and the value is only ever used for relative
				// ordering, never as a position count.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix.
				$next_sort = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$tbl_agreements}" );
				$now_utc   = gmdate( 'Y-m-d H:i:s' );

				// '' from the hash means "no fingerprint could be taken",
				// which is stored as NULL and never blocks the save.
				$new_hash = $new_file_id > 0 ? mtl_agreement_file_hash( $new_file_id ) : '';

				$inserted = $wpdb->insert(
					$tbl_agreements,
					array(
						'agreement_text'       => $new_text,
						'attachment_id'        => $new_file_id > 0 ? $new_file_id : null,
						'file_sha256'          => '' !== $new_hash ? $new_hash : null,
						'version_num'          => 1,
						'version_published_at' => $now_utc,
						'sort_order'           => $next_sort,
					),
					array( '%s', '%d', '%s', '%d', '%s', '%d' )
				);

				if ( $inserted ) {
					mtl_agreements_flush_cache();
					echo '<div class="notice notice-success is-dismissible"><p><strong>Added.</strong> The new agreement is live at version 1. Anyone who has not agreed to it is now outstanding.</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The agreement could not be saved. Please try again.</p></div>';
					$mtl_agreement_add_open  = true;
					$mtl_agreement_form_text = $new_text;
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ---- Edit an agreement ---------------------------------------------
	//
	// Any change to the wording or the file increments version_num, putting
	// every member who had agreed back to outstanding. There is no minor-edit
	// exemption: the plugin cannot tell a typo from a material change.
	if ( isset( $_POST['mtl_edit_agreement'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_edit_agreement_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_edit_agreement_nonce'] ) ), 'mtl_edit_agreement_action' ) ) {
			// A submitted edit closes the form. The edit link carries
			// ?mtl_edit_agreement=<id>, and the form posts back to that same
			// URL, so without this the query string reopens the form over the
			// success notice. The branches below reopen it deliberately where
			// the admin still has something to fix.
			$mtl_agreement_edit_id = 0;

			$edit_id      = isset( $_POST['agreement_id'] ) ? absint( $_POST['agreement_id'] ) : 0;
			$seen_version = isset( $_POST['seen_version'] ) ? absint( $_POST['seen_version'] ) : 0;
			$edit_text    = isset( $_POST['agreement_text'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['agreement_text'] ) ) ) : '';
			$edit_file_id = isset( $_POST['agreement_attachment_id'] ) ? absint( $_POST['agreement_attachment_id'] ) : 0;
			$edit_file_id = ( $edit_file_id > 0 && 'attachment' === get_post_type( $edit_file_id ) ) ? $edit_file_id : 0;
			$existing     = $edit_id > 0 ? mtl_get_agreement( $edit_id ) : null;

			if ( ! $existing ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That agreement no longer exists.</p></div>';
			} elseif ( '' === $edit_text ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Enter the text members have to agree to. Nothing was saved.</p></div>';
				$mtl_agreement_edit_id   = $edit_id;
				$mtl_agreement_form_text = $edit_text;
			} elseif ( mb_strlen( $edit_text ) > MTL_AGREEMENT_TEXT_MAXLENGTH ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That text is too long. Keep it under ' . esc_html( number_format_i18n( MTL_AGREEMENT_TEXT_MAXLENGTH ) ) . ' characters. Nothing was saved.</p></div>';
				$mtl_agreement_edit_id   = $edit_id;
				$mtl_agreement_form_text = $edit_text;
			} else {
				$existing_file_id = (int) $existing->attachment_id;
				$text_unchanged   = ( $edit_text === (string) $existing->agreement_text );
				$file_unchanged   = ( $edit_file_id === $existing_file_id );
				$fresh_hash       = $edit_file_id > 0 ? mtl_agreement_file_hash( $edit_file_id ) : '';

				if ( $text_unchanged && $file_unchanged ) {
					// A save that changes nothing is a no-op, so opening the
					// form to read it and clicking Save does not re-prompt the
					// membership. file_sha256 is still refreshed, so a file
					// replaced on disk out of band clears the drift warning.
					$wpdb->update(
						$tbl_agreements,
						array( 'file_sha256' => '' !== $fresh_hash ? $fresh_hash : null ),
						array( 'agreement_id' => $edit_id ),
						array( '%s' ),
						array( '%d' )
					);
					mtl_agreements_flush_cache();
					echo '<div class="notice notice-info is-dismissible"><p><strong>No changes.</strong> The text and file are the same as before, so the version was not increased and no one has been asked to agree again.</p></div>';
				} else {
					$now_utc = gmdate( 'Y-m-d H:i:s' );

					// Optimistic concurrency: the version the editing admin was
					// shown is submitted back, so two admins saving at once
					// cannot both bump and prompt the membership twice.
					$affected = $wpdb->query(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix.
							"UPDATE {$tbl_agreements}
							    SET agreement_text = %s,
							        attachment_id = %s,
							        file_sha256 = %s,
							        version_num = version_num + 1,
							        version_published_at = %s
							  WHERE agreement_id = %d
							    AND version_num = %d",
							$edit_text,
							$edit_file_id > 0 ? (string) $edit_file_id : null,
							'' !== $fresh_hash ? $fresh_hash : null,
							$now_utc,
							$edit_id,
							$seen_version
						)
					);

					if ( 0 === (int) $affected ) {
						// Somebody else got there first. Neither retry nor
						// merge: the render section reopens the form with
						// their saved text and keeps this admin's wording
						// below it to copy from.
						$mtl_agreement_edit_id  = $edit_id;
						$mtl_agreement_conflict = array(
							'agreement_id' => $edit_id,
							'your_text'    => $edit_text,
						);
					} else {
						mtl_agreements_flush_cache();
						$new_version = (int) $existing->version_num + 1;
						echo '<div class="notice notice-success is-dismissible"><p><strong>Saved as version ' . esc_html( number_format_i18n( $new_version ) ) . '.</strong> Everyone who had agreed to the previous version is now outstanding, and in full mode cannot reserve tools until they agree again. No email has been sent. Send agreement requests from the Membership page.</p></div>';
					}
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ---- Retire / un-retire / delete ------------------------------------
	if ( isset( $_POST['mtl_retire_agreement'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_retire_agreement_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_retire_agreement_nonce'] ) ), 'mtl_retire_agreement_action' ) ) {
			$retire_id = isset( $_POST['agreement_id'] ) ? absint( $_POST['agreement_id'] ) : 0;

			// sort_order is left untouched, so retiring one agreement does not
			// silently renumber the rest.
			//
			// A direct query rather than $wpdb->update(): the WHERE has to test
			// `retired_at IS NULL`, and $wpdb->update() renders a null in its
			// where array as `= NULL`, which matches nothing.
			$updated = $retire_id > 0 ? $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix.
					"UPDATE {$tbl_agreements} SET retired_at = %s WHERE agreement_id = %d AND retired_at IS NULL",
					gmdate( 'Y-m-d H:i:s' ),
					$retire_id
				)
			) : 0;

			if ( $updated ) {
				mtl_agreements_flush_cache();
				echo '<div class="notice notice-success is-dismissible"><p><strong>Retired.</strong> It is no longer shown at signup and no longer required. Members who already agreed to it keep that record, and it still appears on their account page.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That agreement could not be retired. It may already be retired.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	if ( isset( $_POST['mtl_unretire_agreement'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_unretire_agreement_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_unretire_agreement_nonce'] ) ), 'mtl_unretire_agreement_action' ) ) {
			$unretire_id = isset( $_POST['agreement_id'] ) ? absint( $_POST['agreement_id'] ) : 0;

			// Appends to the end rather than restoring the old position, which
			// would drop it into the middle of a list the admin has since
			// rearranged. The version number is untouched, so earlier accepters
			// stay up to date.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix.
			$next_sort = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$tbl_agreements}" );

			$updated = $unretire_id > 0 ? $wpdb->update(
				$tbl_agreements,
				array(
					'retired_at' => null,
					'sort_order' => $next_sort,
				),
				array( 'agreement_id' => $unretire_id ),
				array( '%s', '%d' ),
				array( '%d' )
			) : false;

			if ( false !== $updated ) {
				mtl_agreements_flush_cache();
				echo '<div class="notice notice-success is-dismissible"><p><strong>Back in use.</strong> It has been added to the end of the list at its existing version number. Members who never agreed to it are now outstanding.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That agreement could not be put back into use.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	if ( isset( $_POST['mtl_delete_agreement'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_delete_agreement_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_agreement_nonce'] ) ), 'mtl_delete_agreement_action' ) ) {
			$delete_id = isset( $_POST['agreement_id'] ) ? absint( $_POST['agreement_id'] ) : 0;

			// Delete is offered only for an agreement nobody has ever accepted
			// Checked here again, not just when the button was rendered,
			// because someone could have accepted it in between. The
			// ON DELETE RESTRICT foreign key is the real guarantee; this check
			// exists so the admin gets an explanation instead of a database
			// error.
			if ( $delete_id > 0 && mtl_count_agreement_acceptances( $delete_id ) > 0 ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Not deleted.</strong> Someone has agreed to this, so the record has to be kept. Retire it instead, which stops it being required without destroying anyone&rsquo;s record.</p></div>';
			} elseif ( $delete_id > 0 && $wpdb->delete( $tbl_agreements, array( 'agreement_id' => $delete_id ), array( '%d' ) ) ) {
				mtl_agreements_flush_cache();
				echo '<div class="notice notice-success is-dismissible"><p><strong>Deleted.</strong> No one had agreed to it, so nothing was lost.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That agreement could not be deleted.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ---- Reorder ---------------------------------------------------------
	if ( isset( $_POST['mtl_move_agreement'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_move_agreement_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_move_agreement_nonce'] ) ), 'mtl_move_agreement_action' ) ) {
			$move_id        = isset( $_POST['agreement_id'] ) ? absint( $_POST['agreement_id'] ) : 0;
			$move_direction = isset( $_POST['direction'] ) ? sanitize_text_field( wp_unslash( $_POST['direction'] ) ) : '';
			$active_list    = mtl_get_active_agreements();

			if ( in_array( $move_direction, array( 'up', 'down' ), true ) && $move_id > 0 && $active_list ) {
				// Find the row's position in the rendered order and swap
				// sort_order with its neighbour. Working from the same ordered
				// list the admin is looking at, rather than comparing
				// sort_order values directly, means the swap still does the
				// obvious thing when two rows share a value.
				$position = null;
				foreach ( $active_list as $index => $candidate ) {
					if ( (int) $candidate->agreement_id === $move_id ) {
						$position = $index;
						break;
					}
				}

				$neighbour_index = ( 'up' === $move_direction ) ? $position - 1 : $position + 1;

				if ( null !== $position && isset( $active_list[ $neighbour_index ] ) ) {
					$this_row  = $active_list[ $position ];
					$other_row = $active_list[ $neighbour_index ];

					// Two rows sharing a sort_order would swap to no effect,
					// so give the moving row a value that definitely lands on
					// the correct side of its neighbour.
					$this_sort  = (int) $this_row->sort_order;
					$other_sort = (int) $other_row->sort_order;
					if ( $this_sort === $other_sort ) {
						$this_sort = ( 'up' === $move_direction ) ? $other_sort - 1 : $other_sort + 1;
					} else {
						$swap       = $this_sort;
						$this_sort  = $other_sort;
						$other_sort = $swap;
					}

					$wpdb->update( $tbl_agreements, array( 'sort_order' => $this_sort ), array( 'agreement_id' => (int) $this_row->agreement_id ), array( '%d' ), array( '%d' ) );
					$wpdb->update( $tbl_agreements, array( 'sort_order' => $other_sort ), array( 'agreement_id' => (int) $other_row->agreement_id ), array( '%d' ), array( '%d' ) );
					mtl_agreements_flush_cache();
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ---- Email wording ----------------------------------------------------
	if ( isset( $_POST['mtl_save_agreement_emails'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_agreement_emails_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_agreement_emails_nonce'] ) ), 'mtl_agreement_emails_action' ) ) {
			// The subject is a mail header, so line breaks come out of it.
			// A subject containing CR or LF is classic header injection;
			// everything after the break is read as a new header, which is how
			// a Bcc: gets added to every agreement email the site sends. It is
			// stripped again at send time, since the option could be written
			// by something that never came through this form.
			$posted_subject = isset( $_POST['mtl_agreement_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_agreement_email_subject'] ) ) : '';
			$posted_subject = str_replace( array( "\r", "\n" ), '', $posted_subject );

			update_option( 'mtl_agreement_email_subject', $posted_subject );
			update_option( 'mtl_agreement_email_body', isset( $_POST['mtl_agreement_email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_agreement_email_body'] ) ) : '' );
			update_option( 'mtl_agreement_request_email_body', isset( $_POST['mtl_agreement_request_email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_agreement_request_email_body'] ) ) : '' );

			echo '<div class="notice notice-success is-dismissible"><p><strong>Saved.</strong> Email wording updated. Leaving a field empty restores the wording the plugin ships with.</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 4. HANDLE DATABASE SETUP SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_run_db_setup'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_db_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_db_nonce'] ) ), 'mtl_run_db_action' ) ) {
			// Typed-phrase confirmation. Checked here and not only in the
			// browser prompt: this is the one irreversible action in the
			// plugin, so a submission with JavaScript disabled (or a
			// hand-crafted POST) must not be able to skip past it.
			// Only surrounding whitespace is forgiven; wording and case
			// have to match exactly.
			$mtl_typed_phrase = isset( $_POST['mtl_reset_confirmation'] )
				? trim( sanitize_text_field( wp_unslash( $_POST['mtl_reset_confirmation'] ) ) )
				: '';

			$sql_file_path = MTL_PLUGIN_DIR . 'admin/schema.sql';
			if ( mtl_db_reset_confirmation_phrase() !== $mtl_typed_phrase ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Nothing was deleted.</strong> A database reset only runs when the phrase &ldquo;<code>' . esc_html( mtl_db_reset_confirmation_phrase() ) . '</code>&rdquo; is typed exactly as shown. Your data is unchanged.</p></div>';
			} elseif ( file_exists( $sql_file_path ) ) {
				$sql_contents = file_get_contents( $sql_file_path );

				// Swap the {{prefix}} placeholder for the site's real table
				// prefix (e.g. "wp_", or "wp_2_" on multisite) so the tables
				// follow WordPress naming conventions.
				$sql_contents = str_replace( '{{prefix}}', $wpdb->prefix, $sql_contents );

				// Strip full-line SQL comments before splitting on
				// semicolons. A comment line sitting directly above a
				// statement (no semicolon between them) would otherwise be
				// bundled into the same chunk once the file is exploded on
				// ";", and a naive "starts with --" filter would then skip
				// the whole chunk, including the real SQL. Inline trailing
				// comments (e.g. "-- 'Y' or 'N'") are left alone since MySQL parses those natively.
				$lines        = explode( "\n", $sql_contents );
				$lines        = array_filter(
					$lines,
					function ( $line ) {
						return 0 !== strpos( trim( $line ), '--' );
					}
				);
				$sql_contents = implode( "\n", $lines );

				$queries = array_filter( array_map( 'trim', explode( ';', $sql_contents ) ) );

				$success_count = 0;
				$error_count   = 0;

				foreach ( $queries as $query ) {
					if ( empty( $query ) ) {
						continue;
					}
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- runs the plugin's own bundled admin/schema.sql, not user input.
					$result = $wpdb->query( $query );
					if ( false === $result ) {
						++$error_count;
						echo '<div style="background: #ffebe8; border: 1px solid #cc0000; padding: 10px; margin: 5px 0;">';
						echo '<strong>Failed Query:</strong> ' . esc_html( $query ) . '<br>';
						echo '<strong>DB Error:</strong> ' . esc_html( $wpdb->last_error );
						echo '</div>';
					} else {
						++$success_count;
					}
				}

				if ( 0 === $error_count ) {
					echo '<div class="notice notice-success is-dismissible"><p><strong>Database Setup Complete:</strong> Successfully reset tables and executed ' . intval( $success_count ) . ' queries.</p></div>';
				} else {
					echo '<div class="notice notice-warning is-dismissible"><p><strong>Database Setup Finished with Errors:</strong> ' . intval( $success_count ) . ' queries succeeded, but ' . intval( $error_count ) . ' encountered errors.</p></div>';
				}
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Could not find <code>schema.sql</code>.</p></div>';
			}
		}
	}

	$org_name = get_option( 'mtl_org_name', '' );
	// No admin_email default: this address is printed on the public pages, and
	// pre-filling the site administrator's own mailbox would publish it the
	// first time somebody saved this form without touching the field.
	$contact_email            = get_option( 'mtl_contact_email', '' );
	$currency                 = get_option( 'mtl_currency_symbol', '$' );
	$logo_url                 = get_option( 'mtl_logo_url', '' );
	$verified_badge_image_url = get_option( 'mtl_verified_badge_image_url', '' );

	$h_color     = get_option( 'mtl_header_color', '#ff6600' );
	$h_font      = get_option( 'mtl_header_font', 'inherit' );
	$h_size      = get_option( 'mtl_header_size', '2em' );
	$h_weight    = get_option( 'mtl_header_weight', '700' );
	$h_transform = get_option( 'mtl_header_transform', 'none' );

	$b_color  = get_option( 'mtl_body_color', '#096491' );
	$b_font   = get_option( 'mtl_body_font', 'inherit' );
	$b_size   = get_option( 'mtl_body_size', '14px' );
	$b_weight = get_option( 'mtl_body_weight', '400' );

	$l_color = get_option( 'mtl_link_color', '#00b3ff' );
	$l_font  = get_option( 'mtl_link_font', 'inherit' );
	$l_size  = get_option( 'mtl_link_size', 'inherit' );
	$l_dec   = get_option( 'mtl_link_decoration', 'none' );

	$accent_color = get_option( 'mtl_accent_color', '#f7c600' );
	$bg_color     = get_option( 'mtl_background_color', '#ffffff' );

	// A stored colour can be EMPTY as well as absent, and get_option()'s default
	// only covers absent. That distinction is destructive here: <input
	// type="color"> rejects anything that is not #rrggbb and falls back to
	// #000000, so an empty option renders a black swatch, and the next Save
	// Settings, for any reason at all, writes that black into the option and
	// turns every page black. Colour pickers cannot express "unset", so an empty
	// value must resolve to the documented default before it reaches the field.
	$h_color      = mtl_color_or_default( $h_color, '#ff6600' );
	$b_color      = mtl_color_or_default( $b_color, '#096491' );
	$l_color      = mtl_color_or_default( $l_color, '#00b3ff' );
	$accent_color = mtl_color_or_default( $accent_color, '#f7c600' );
	$bg_color     = mtl_color_or_default( $bg_color, '#ffffff' );
	$radius       = get_option( 'mtl_border_radius', '4px' );
	$btn_scale    = get_option( 'mtl_button_scale', '1' );

	$default_loan_days = get_option( 'mtl_default_loan_days', '21' );
	// 0 means "never expires"; see mtl_reservation_hold_days().
	$reservation_hold_days   = (int) get_option( 'mtl_reservation_hold_days', 14 );
	$pickup_directions       = get_option(
		'mtl_pickup_directions',
		'Placing a reservation holds your spot in line and speeds up the process of checking out tools. If no one is waiting in line to borrow a tool, no reservation is required. Come by our store and speak with a representative to take tools home.'
	);
	$verification_directions = get_option(
		'mtl_verification_directions',
		'A government issued ID and proof of address are required to become a verified member and to check out tools. Stop by our office to verify membership.'
	);

	// Default lives in mtl_default_giving_text() so this box and the
	// member-facing fallback can never show different words.
	$giving_text = get_option( 'mtl_giving_text', mtl_default_giving_text() );

	// Shown re-normalized rather than raw, so the field displays exactly what
	// the member-facing button would use. Comparing the two makes a rejected
	// link visible instead of it silently appearing blank on the next load.
	$giving_url_raw = trim( (string) get_option( 'mtl_giving_url', '' ) );
	$giving_url     = mtl_normalize_giving_url( $giving_url_raw );

	// Shown as chips next to the "add new" mini-forms below.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	$categories = $wpdb->get_results( "SELECT category_id, category_name FROM {$tbl_categories} ORDER BY category_name ASC" );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	$tags = $wpdb->get_results( "SELECT tag_id, tag_name FROM {$tbl_tags} ORDER BY tag_name ASC" );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	$trainings = $wpdb->get_results( "SELECT training_id, training_name, badge_image_url, certification_length_months FROM {$tbl_trainings} ORDER BY training_name ASC" );

	$font_presets = mtl_font_preset_options();

	// ==========================================
	// 5. RENDER THE SETTINGS FORM
	// ==========================================
	?>
	<style>
		.mtl-chip-row {
			display: flex;
			flex-wrap: wrap;
			gap: 6px;
			margin-top: 8px;
		}

		.mtl-font-preset {
			display: block;
			margin-bottom: 4px;
			font-size: 0.85em;
			max-width: 100%;
		}

		/* Fixed layout prevents a long font preset <select> from pushing the
			table wider than the panel; the 100% widths below make inputs fill
			their column instead of overflowing it. */
		.mtl-appearance-table {
			table-layout: fixed;
			width: 100%;
		}

		.mtl-appearance-table td {
			vertical-align: top;
			padding-right: 10px;
			word-wrap: break-word;
		}

		.mtl-appearance-table select,
		.mtl-appearance-table input[type="text"] {
			width: 100%;
			max-width: 100%;
			box-sizing: border-box;
		}

		.mtl-swatch-row {
			display: flex;
			flex-wrap: wrap;
			gap: 10px;
			margin-bottom: 20px;
		}

		.mtl-swatch {
			cursor: pointer;
			border: 2px solid #ccd0d4;
			border-radius: 6px;
			padding: 10px 14px;
			font-size: 0.85em;
			font-weight: 600;
			color: #fff;
			text-shadow: 0 1px 2px rgba(0, 0, 0, .5);
		}

		.mtl-swatch:hover {
			border-color: #666;
		}

		/* The Inherit preset is neutral (restores defaults), so it reads as a
			plain light chip rather than a colored palette swatch. */
		.mtl-swatch-inherit {
			background: #fff;
			color: #333;
			text-shadow: none;
		}

		.mtl-add-lookup-form {
			display: flex;
			gap: 8px;
			margin-top: 10px;
		}

		.mtl-add-lookup-form input[type="text"] {
			flex: 1;
		}

		/* Category/Tag chips become checkboxes so a set of them can be deleted at once. */
		.mtl-chip-checkbox {
			display: inline-flex;
			align-items: center;
			gap: 5px;
			background: #f0f6fa;
			color: #096491;
			border: 1px solid #d3dde4;
			border-radius: 12px;
			padding: 3px 10px 3px 8px;
			font-size: 0.85em;
			white-space: nowrap;
			cursor: pointer;
		}

		.mtl-chip-checkbox input[type="checkbox"] {
			margin: 0;
		}

		.mtl-chip-checkbox:has(input:checked) {
			background: #fdf2f2;
			border-color: #e6b3b3;
			color: #b32d2e;
		}

		/* "Slide to unlock" toggle guarding Run Database Setup: a plain
			checkbox styled as a slider, with the native "required" attribute
			doing the actual blocking so it still works with JS disabled. */
		.mtl-lock-toggle {
			display: flex;
			align-items: center;
			gap: 10px;
			margin-bottom: 12px;
			cursor: pointer;
			user-select: none;
		}

		.mtl-lock-toggle input[type="checkbox"] {
			position: absolute;
			opacity: 0;
			width: 0;
			height: 0;
		}

		.mtl-lock-slider {
			position: relative;
			display: inline-block;
			width: 46px;
			height: 24px;
			background: #ccc;
			border-radius: 999px;
			flex-shrink: 0;
			transition: background 0.2s ease;
		}

		.mtl-lock-slider::before {
			content: "";
			position: absolute;
			left: 3px;
			top: 3px;
			width: 18px;
			height: 18px;
			background: #fff;
			border-radius: 50%;
			box-shadow: 0 1px 2px rgba(0, 0, 0, .3);
			transition: transform 0.2s ease;
		}

		.mtl-lock-toggle input[type="checkbox"]:checked+.mtl-lock-slider {
			background: #d63638;
		}

		.mtl-lock-toggle input[type="checkbox"]:checked+.mtl-lock-slider::before {
			transform: translateX(22px);
		}

		.mtl-lock-toggle input[type="checkbox"]:focus-visible+.mtl-lock-slider {
			outline: 2px solid #096491;
			outline-offset: 2px;
		}

		.mtl-lock-label {
			font-size: 0.9em;
			color: #444;
		}

		/* Must stay red regardless of the Accent Color setting; the extra
			class outranks the shared .button-secondary accent rule from
			my-tool-library.php on specificity (an inline style couldn't cover :hover). */
		.mtl-admin-wrapper .button-secondary.mtl-danger-btn {
			background: transparent !important;
			border-color: #d63638 !important;
			color: #d63638 !important;
		}

		.mtl-admin-wrapper .button-secondary.mtl-danger-btn:hover {
			background: #d63638 !important;
			color: #fff !important;
		}

		/* One compact box holding both links side by side (stacking on
			narrow screens), instead of two full-size boxes stacked vertically. */
		.mtl-public-link-box {
			display: flex;
			flex-wrap: wrap;
			gap: 10px 24px;
			background: #fff;
			border: 1px solid #ccd0d4;
			border-left: 4px solid var(--mtl-header-color, #ff6600);
			border-radius: 4px;
			padding: 12px 16px;
			margin-top: 20px;
		}

		.mtl-public-link-item {
			flex: 1 1 300px;
			min-width: 240px;
		}

		.mtl-public-link-item>label {
			display: block;
			font-size: 0.72em;
			font-weight: 600;
			color: #646970;
			text-transform: uppercase;
			letter-spacing: 0.03em;
			margin-bottom: 3px;
		}

		.mtl-public-link-row {
			display: flex;
			gap: 6px;
		}

		.mtl-public-link-input,
		.mtl-home-link-input {
			flex: 1 1 auto;
			min-width: 0;
			padding: 4px 8px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
			font-size: 0.8em;
		}

		.mtl-public-link-input {
			font-family: Consolas, Menlo, monospace;
			background: #f6f7f7;
			color: #1d2327;
		}

		/* Editable setting, unlike the readonly Public Page Link, so plain
			(non-monospace) text on a white background, so it doesn't read
			as disabled. */
		.mtl-home-link-input {
			background: #fff;
			color: #1d2327;
		}

		.mtl-public-link-row .button {
			height: 26px;
			padding: 0 10px;
			line-height: 24px;
			font-size: 0.8em;
		}

		.mtl-public-link-hint {
			margin: 6px 0 0 0;
			font-size: 0.75em;
			color: #8a6d00;
		}

		/* SETUP PAGE TILES
			Three bands down the page, in the order an admin actually works
			through them: settings first, then the lists they populate, then
			the data operations at the bottom where they are out of the way.

			.mtl-setup-row is the shared card look. A full-width band is just a
			row holding one tile; the middle band holds several and reflows on
			narrow screens. Keeping one card style means a tile can be moved
			between bands without restyling it. */
		.mtl-setup-row {
			display: flex;
			gap: 20px;
			margin-top: 20px;
			flex-wrap: wrap;
		}

		.mtl-setup-tile {
			background: #fff;
			padding: 20px;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
			/* Tiles sharing a row size themselves to their content rather than
				stretching to match the tallest one. */
			height: fit-content;
		}

		/* Sole occupant of its band: fills the width at every screen size.
			flex-basis 100% rather than width so the row's gap and padding are
			accounted for automatically. */
		.mtl-setup-tile-full {
			flex: 1 1 100%;
			min-width: 0;
		}

		/* Shares its band, and drops to one-per-row once there is no longer
			space for two. min-width is what triggers that wrap; it must stay
			small enough that the tile still fits inside the admin content area
			on a narrow window, or the row would overflow horizontally. */
		.mtl-setup-tile-half {
			flex: 1 1 400px;
			min-width: 320px;
		}

		/* Below this the two-up band cannot hold two readable columns, so let
			every tile take the full width rather than squeezing. */
		@media screen and (max-width: 782px) {
			.mtl-setup-tile-half {
				flex-basis: 100%;
				min-width: 0;
			}
		}
	</style>

	<?php
	// Query-string form works with no setup; the pretty /tool-library/ form
	// (registered near the top of my-tool-library.php) takes over automatically
	// once permalinks are anything but Plain.
	$public_page_url = mtl_front_page_url( 'main' );
	// Defaults to the site's home page but is a real option so the admin can
	// point the public page's "Return to Home" button elsewhere.
	$home_url = get_option( 'mtl_home_url', home_url( '/' ) );
	?>
	<div class="mtl-public-link-box">
		<div class="mtl-public-link-item">
			<label>Public Page Link</label>
			<div class="mtl-public-link-row">
				<input type="text" readonly class="mtl-public-link-input" value="<?php echo esc_attr( $public_page_url ); ?>" onclick="this.select();">
				<a href="<?php echo esc_url( $public_page_url ); ?>" target="_blank" class="button">View</a>
			</div>
			<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
				<p class="mtl-public-link-hint">
					Using Plain permalinks, so this link includes <code>?mtl_page=main</code>. Switch to pretty permalinks under <strong>Settings &rarr; Permalinks</strong> for a shorter one.
				</p>
			<?php endif; ?>
		</div>

		<div class="mtl-public-link-item">
			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_save_home_url_action', 'mtl_home_url_nonce' ); ?>
				<label for="mtl_home_url">Home Page Link <span style="font-weight:400; text-transform:none; letter-spacing:normal;">(&ldquo;Return to Home&rdquo; target)</span></label>
				<div class="mtl-public-link-row">
					<input type="url" name="mtl_home_url" id="mtl_home_url" class="mtl-home-link-input" value="<?php echo esc_attr( $home_url ); ?>" placeholder="https://...">
					<button type="submit" name="mtl_save_home_url" class="button button-primary">Save</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Band 1: General Details, full width. It holds the widest content on
		the page (the form-table plus the Appearance Settings panel), so it
		gets a row to itself rather than competing for space with the lists. -->
	<div class="mtl-setup-row">

		<!-- General Customization Settings -->
		<div class="mtl-setup-tile mtl-setup-tile-full">
			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_save_settings_action', 'mtl_settings_nonce' ); ?>

				<h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">General Details</h3>
				<table class="form-table" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="mtl_org_name">Organization Name</label></th>
						<td><input type="text" name="mtl_org_name" id="mtl_org_name" class="regular-text" value="<?php echo esc_attr( $org_name ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_logo_url">Logo URL</label></th>
						<td>
							<input type="url" name="mtl_logo_url" id="mtl_logo_url" class="regular-text" value="<?php echo esc_url( $logo_url ); ?>" placeholder="https://...">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Upload your logo to the WordPress Media Library and paste the File URL here. Leave blank if unknown.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_verified_badge_image_url">Verified Badge Image URL</label></th>
						<td>
							<input type="url" name="mtl_verified_badge_image_url" id="mtl_verified_badge_image_url" class="regular-text" value="<?php echo esc_url( $verified_badge_image_url ); ?>" placeholder="https://...">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Optional. Shown on a member's My Account page in place of the plain green &ldquo;Verified&rdquo; pill once they&rsquo;re verified. Leave blank to keep using the pill.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_contact_email">Public Contact Email</label></th>
						<td>
							<input type="email" name="mtl_contact_email" id="mtl_contact_email" class="regular-text" value="<?php echo esc_attr( $contact_email ); ?>" placeholder="hello@example.org">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Shown to the public in the footer of every member-facing page, and in the confirmation email sent after a password change, so members have a way to reach staff. Use a shared staff address rather than a personal one. Leave blank to show no contact details anywhere.</p>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">This address is for members to write <em>to</em>, and automated email is not sent from it. Outgoing mail uses whatever WordPress or your SMTP plugin is configured to send from.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_currency_symbol">Currency Symbol</label></th>
						<td><input type="text" name="mtl_currency_symbol" id="mtl_currency_symbol" style="width: 50px;" value="<?php echo esc_attr( $currency ); ?>"></td>
					</tr>
				</table>

				<h3 style="border-bottom: 1px solid #eee; padding-bottom: 10px;">Reservations &amp; Loans</h3>
				<table class="form-table" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="mtl_default_loan_days">Default Loan Length</label></th>
						<td>
							<select name="mtl_default_loan_days" id="mtl_default_loan_days">
								<option value="7" <?php selected( $default_loan_days, '7' ); ?>>7 days</option>
								<option value="14" <?php selected( $default_loan_days, '14' ); ?>>14 days</option>
								<option value="21" <?php selected( $default_loan_days, '21' ); ?>>21 days</option>
								<option value="30" <?php selected( $default_loan_days, '30' ); ?>>30 days</option>
							</select>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Pre-fills the due date whenever an admin checks out or renews a loan (still adjustable per loan).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_reservation_hold_days">Reservation Hold Period</label></th>
						<td>
							<input type="number" name="mtl_reservation_hold_days" id="mtl_reservation_hold_days" min="1" max="365" step="1" value="<?php echo esc_attr( $reservation_hold_days > 0 ? $reservation_hold_days : 14 ); ?>" style="width: 90px;" <?php disabled( 0, $reservation_hold_days ); ?>>
							<span style="margin-left: 4px;">days</span>
							<label style="display: inline-block; margin-left: 16px;">
								<input type="checkbox" name="mtl_reservation_hold_never" id="mtl_reservation_hold_never" value="1" <?php checked( 0, $reservation_hold_days ); ?>>
								Never expires
							</label>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">How long a tool reservation is held once the member reaches the front of the queue <em>and</em> the tool is back on the shelf. Reservation auto-cancelled upon expiration.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_pickup_directions">Tool Pickup Directions</label></th>
						<td>
							<textarea name="mtl_pickup_directions" id="mtl_pickup_directions" class="large-text" rows="4"><?php echo esc_textarea( $pickup_directions ); ?></textarea>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Shown to members on the My Reservations page. Leave blank to hide it there.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_verification_directions">Member Verification Directions</label></th>
						<td>
							<textarea name="mtl_verification_directions" id="mtl_verification_directions" class="large-text" rows="4"><?php echo esc_textarea( $verification_directions ); ?></textarea>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Shown to members on their Account page until they&rsquo;re verified. Leave blank to hide it there.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_giving_text">Consider Giving Message</label></th>
						<td>
							<textarea name="mtl_giving_text" id="mtl_giving_text" class="large-text" rows="4"><?php echo esc_textarea( $giving_text ); ?></textarea>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Fundraising ask, shown to signed-in members on their Account page and on My Reservations. <strong>Leave blank to hide the section entirely</strong>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_giving_url">Consider Giving Link</label></th>
						<td>
							<input type="url" name="mtl_giving_url" id="mtl_giving_url" class="large-text" value="<?php echo esc_attr( $giving_url ); ?>" placeholder="https://example.org/donate">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">
								Where the <strong>Give Now</strong> button sends members. Opens in a new tab. Leave blank to show the message without a button.
								<?php if ( '' !== $giving_url_raw && '' === $giving_url ) : ?>
									<br><span style="color: #b32d2e;"><strong>The link you last saved was discarded.</strong> Only ordinary web addresses starting with <code>http://</code> or <code>https://</code> can be used here.</span>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<!-- Collapsible Styling Panel (collapsed by default). -->
				<details style="background: #f9f9f9; padding: 15px 20px; border: 1px solid #ccd0d4; margin-top: 30px; border-radius: 4px;">
					<summary style="font-size: 1.1em; font-weight: 600; cursor: pointer; outline: none;">
						Appearance Settings
					</summary>

					<div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">

						<!-- Quick Theme Presets -->
						<h4 style="margin-bottom: 5px;">Quick Theme Presets</h4>
						<p style="font-size: 0.85em; color: #666; margin: 0 0 10px 0;">Click a preset to fill in the settings below, then fine-tune anything you like before saving. <strong>Inherit</strong> restores every option to the site defaults (fonts follow your WordPress theme).</p>
						<div class="mtl-swatch-row">
							<button type="button" class="mtl-swatch mtl-swatch-inherit" onclick="mtlApplyInherit()">Inherit</button>
							<button type="button" class="mtl-swatch" style="background: linear-gradient(135deg, #ff6600, #096491);" onclick="mtlApplySwatch('#ff6600', '#096491', '#00b3ff', '#f7c600')">Classic</button>
							<button type="button" class="mtl-swatch" style="background: linear-gradient(135deg, #2e7d32, #1b3a2b);" onclick="mtlApplySwatch('#2e7d32', '#1b3a2b', '#4caf50', '#c5e1a5')">Forest</button>
							<button type="button" class="mtl-swatch" style="background: linear-gradient(135deg, #d84315, #4e342e);" onclick="mtlApplySwatch('#d84315', '#4e342e', '#ff7043', '#ffcc80')">Sunset</button>
							<button type="button" class="mtl-swatch" style="background: linear-gradient(135deg, #01579b, #263238);" onclick="mtlApplySwatch('#01579b', '#263238', '#0288d1', '#80deea')">Ocean</button>
							<button type="button" class="mtl-swatch" style="background: linear-gradient(135deg, #616161, #212121);" onclick="mtlApplySwatch('#616161', '#212121', '#9e9e9e', '#e0b0ff')">Slate</button>
						</div>

						<hr style="border: 0; border-top: 1px solid #ddd; margin: 15px 0;">

						<!-- Header Styling -->
						<h4 style="margin-bottom: 5px;">Headers</h4>
						<table class="form-table mtl-appearance-table" style="margin-top: 0;">
							<tr>
								<td><label>Color:</label><br><input type="color" name="mtl_header_color" id="mtl_header_color" value="<?php echo esc_attr( $h_color ); ?>"></td>
								<td>
									<label>Font Family:</label><br>
									<select class="mtl-font-preset" onchange="if(this.value){document.getElementById('mtl_header_font').value=this.value;}">
										<?php foreach ( $font_presets as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="text" name="mtl_header_font" id="mtl_header_font" value="<?php echo esc_attr( $h_font ); ?>" placeholder="e.g. Arial, sans-serif">
								</td>
								<td><label>Font Size:</label><br><input type="text" name="mtl_header_size" value="<?php echo esc_attr( $h_size ); ?>" placeholder="e.g. 2em"></td>
								<td><label>Font Weight:</label><br>
									<select name="mtl_header_weight">
										<option value="400" <?php selected( $h_weight, '400' ); ?>>Normal (400)</option>
										<option value="600" <?php selected( $h_weight, '600' ); ?>>Semi-Bold (600)</option>
										<option value="700" <?php selected( $h_weight, '700' ); ?>>Bold (700)</option>
									</select>
								</td>
								<td><label>Text Style:</label><br>
									<select name="mtl_header_transform">
										<option value="none" <?php selected( $h_transform, 'none' ); ?>>Normal</option>
										<option value="uppercase" <?php selected( $h_transform, 'uppercase' ); ?>>UPPERCASE</option>
										<option value="capitalize" <?php selected( $h_transform, 'capitalize' ); ?>>Capitalize Each Word</option>
										<option value="lowercase" <?php selected( $h_transform, 'lowercase' ); ?>>lowercase</option>
									</select>
								</td>
							</tr>
						</table>

						<hr style="border: 0; border-top: 1px solid #ddd; margin: 15px 0;">

						<!-- Body Styling -->
						<h4 style="margin-bottom: 5px;">Body Text</h4>
						<table class="form-table mtl-appearance-table" style="margin-top: 0;">
							<tr>
								<td><label>Color:</label><br><input type="color" name="mtl_body_color" id="mtl_body_color" value="<?php echo esc_attr( $b_color ); ?>"></td>
								<td>
									<label>Font Family:</label><br>
									<select class="mtl-font-preset" onchange="if(this.value){document.getElementById('mtl_body_font').value=this.value;}">
										<?php foreach ( $font_presets as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="text" name="mtl_body_font" id="mtl_body_font" value="<?php echo esc_attr( $b_font ); ?>" placeholder="e.g. inherit">
								</td>
								<td><label>Font Size:</label><br><input type="text" name="mtl_body_size" value="<?php echo esc_attr( $b_size ); ?>" placeholder="e.g. 14px"></td>
								<td><label>Font Weight:</label><br>
									<select name="mtl_body_weight">
										<option value="300" <?php selected( $b_weight, '300' ); ?>>Light (300)</option>
										<option value="400" <?php selected( $b_weight, '400' ); ?>>Normal (400)</option>
										<option value="700" <?php selected( $b_weight, '700' ); ?>>Bold (700)</option>
									</select>
								</td>
							</tr>
						</table>

						<hr style="border: 0; border-top: 1px solid #ddd; margin: 15px 0;">

						<!-- Link Styling -->
						<h4 style="margin-bottom: 5px;">Links</h4>
						<table class="form-table mtl-appearance-table" style="margin-top: 0;">
							<tr>
								<td><label>Color:</label><br><input type="color" name="mtl_link_color" id="mtl_link_color" value="<?php echo esc_attr( $l_color ); ?>"></td>
								<td>
									<label>Font Family:</label><br>
									<select class="mtl-font-preset" onchange="if(this.value){document.getElementById('mtl_link_font').value=this.value;}">
										<?php foreach ( $font_presets as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="text" name="mtl_link_font" id="mtl_link_font" value="<?php echo esc_attr( $l_font ); ?>" placeholder="e.g. inherit">
								</td>
								<td><label>Font Size:</label><br><input type="text" name="mtl_link_size" value="<?php echo esc_attr( $l_size ); ?>" placeholder="e.g. inherit"></td>
								<td><label>Text Decoration:</label><br>
									<select name="mtl_link_decoration">
										<option value="none" <?php selected( $l_dec, 'none' ); ?>>None</option>
										<option value="underline" <?php selected( $l_dec, 'underline' ); ?>>Underline</option>
									</select>
								</td>
							</tr>
						</table>

						<hr style="border: 0; border-top: 1px solid #ddd; margin: 15px 0;">

						<!-- Buttons & Page Accents -->
						<h4 style="margin-bottom: 5px;">Buttons & Page Accents</h4>
						<table class="form-table mtl-appearance-table" style="margin-top: 0;">
							<tr>
								<td><label>Accent Color:</label><br><input type="color" name="mtl_accent_color" id="mtl_accent_color" value="<?php echo esc_attr( $accent_color ); ?>">
									<p style="font-size: 0.8em; color: #666; margin: 4px 0 0 0;">Used for secondary buttons.</p>
								</td>
								<td><label>Page Background:</label><br><input type="color" name="mtl_background_color" value="<?php echo esc_attr( $bg_color ); ?>"></td>
								<td><label>Corner Roundness:</label><br>
									<select name="mtl_border_radius">
										<option value="0px" <?php selected( $radius, '0px' ); ?>>Sharp</option>
										<option value="4px" <?php selected( $radius, '4px' ); ?>>Soft (Default)</option>
										<option value="10px" <?php selected( $radius, '10px' ); ?>>Rounded</option>
										<option value="999px" <?php selected( $radius, '999px' ); ?>>Pill</option>
									</select>
								</td>
								<td><label>Button Size:</label><br>
									<select name="mtl_button_scale">
										<option value="1.25" <?php selected( $btn_scale, '1.25' ); ?>>Big (125%)</option>
										<option value="1" <?php selected( $btn_scale, '1' ); ?>>Default (100%)</option>
										<option value="0.85" <?php selected( $btn_scale, '0.85' ); ?>>Small (85%)</option>
										<option value="0.7" <?php selected( $btn_scale, '0.7' ); ?>>Tiny (70%)</option>
									</select>
									<p style="font-size: 0.8em; color: #666; margin: 4px 0 0 0;">Scales every button proportionally, so large and small buttons keep their relative sizes.</p>
								</td>
							</tr>
						</table>
					</div>
				</details>

				<p class="submit">
					<input type="submit" name="mtl_save_settings" class="button button-primary" value="Save Settings">
				</p>
			</form>
		</div>
	</div>

	<!-- Band 1b: Member Agreements, full width. Sits directly below General
		Details because the mode selector at its head is a settings-level
		decision, and because the agreement list needs the full width to show
		each agreement's text in full rather than truncated. Editing is
		expensive here, so the page pushes admins to get it right first time. -->
	<div class="mtl-setup-row">
		<div class="mtl-setup-tile mtl-setup-tile-full">
			<h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Member Agreements</h3>
			<p style="font-size: 0.9em; color: #666;">Statements every member has to agree to: a liability waiver, a code of conduct, a fee schedule. Members see all of them, in this order, and what they agreed to is recorded exactly as it was worded at the time.</p>

			<?php
			$mtl_stored_mode      = (string) get_option( 'mtl_agreements_mode', 'off' );
			$mtl_stored_mode      = in_array( $mtl_stored_mode, array( 'off', 'paper', 'full' ), true ) ? $mtl_stored_mode : 'off';
			$mtl_allow_paper      = (string) get_option( 'mtl_agreements_allow_paper', '' );
			$mtl_active_list      = mtl_get_active_agreements();
			$mtl_retired_list     = mtl_get_retired_agreements();
			$mtl_outstanding_now  = ( 'paper' === $mtl_stored_mode ) ? mtl_count_members_not_in_agreement() : 0;
			$mtl_agreement_emails = mtl_agreement_email_defaults();
			?>

			<?php if ( 'off' !== $mtl_stored_mode && ! $mtl_active_list ) : ?>
				<!-- The one configuration that silently does nothing, so it must
					not be silent. -->
				<div class="notice notice-warning inline" style="margin: 0 0 16px 0;">
					<p><strong>Nothing is being tracked.</strong> Member agreements are switched on, but there is no agreement for members to agree to. Add one below, or set this back to Off.</p>
				</div>
			<?php endif; ?>

			<form method="post" action="" id="mtl-agreements-mode-form">
				<?php wp_nonce_field( 'mtl_agreements_mode_action', 'mtl_agreements_mode_nonce' ); ?>
				<fieldset style="margin-bottom: 8px;">
					<legend class="screen-reader-text">How member agreements work</legend>

					<label style="display: block; margin-bottom: 10px;">
						<input type="radio" name="mtl_agreements_mode" value="off" <?php checked( 'off', $mtl_stored_mode ); ?>>
						<strong>Off</strong><br>
						<span style="color: #666; margin-left: 24px; display: block;">No agreements are tracked. Nothing is recorded or shown. Any records you already have are kept.</span>
					</label>

					<label style="display: block; margin-bottom: 10px;">
						<input type="radio" name="mtl_agreements_mode" value="paper" <?php checked( 'paper', $mtl_stored_mode ); ?>>
						<strong>Track signed paper only</strong><br>
						<span style="color: #666; margin-left: 24px; display: block;">Staff record who has signed your paper agreements. Members are not asked to agree on the website and are never blocked from reserving. They can see their own record on their account page.</span>
					</label>

					<label style="display: block; margin-bottom: 10px;">
						<input type="radio" name="mtl_agreements_mode" value="full" <?php checked( 'full', $mtl_stored_mode ); ?>>
						<strong>Full: members agree online</strong><br>
						<span style="color: #666; margin-left: 24px; display: block;">Members must tick every agreement to create an account, and must agree again whenever you revise one. Anyone outstanding cannot reserve a tool until they do.</span>
					</label>

					<?php // Indented under Full because that is the mode it qualifies. Paper mode ignores it, since staff recording is the whole of that mode. ?>
					<label style="display: block; margin: 0 0 10px 24px;">
						<input type="checkbox" name="mtl_agreements_allow_paper" value="1" <?php checked( '1', $mtl_allow_paper ); ?>>
						<strong>Allow paper tracking</strong><br>
						<span style="color: #666; margin-left: 24px; display: block;">Staff can also record a member&rsquo;s signed paper agreement at the desk, from Add New Member or the member&rsquo;s detail panel. Leave this off if everyone agrees online. Paper mode above always allows it.</span>
					</label>
				</fieldset>
				<p class="submit" style="margin: 0 0 4px 0;">
					<button type="submit" name="mtl_save_agreements_mode" class="button button-primary"
						data-mtl-outstanding="<?php echo esc_attr( $mtl_outstanding_now ); ?>"
						data-mtl-current-mode="<?php echo esc_attr( $mtl_stored_mode ); ?>">Save Mode</button>
				</p>
			</form>

			<hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">

			<h4 style="margin-bottom: 4px;">Agreements</h4>
			<?php if ( ! $mtl_active_list ) : ?>
				<p style="color: #999;">None yet. Add the first one below.</p>
			<?php else : ?>
				<ol class="mtl-agreement-list" style="margin: 0 0 16px 0; padding-left: 24px;">
					<?php foreach ( $mtl_active_list as $mtl_index => $mtl_agreement ) : ?>
						<?php
						$mtl_agreement_id = (int) $mtl_agreement->agreement_id;
						$mtl_file_id      = (int) $mtl_agreement->attachment_id;
						$mtl_file_url     = $mtl_file_id > 0 ? wp_get_attachment_url( $mtl_file_id ) : '';
						$mtl_hash_status  = $mtl_file_id > 0 ? mtl_agreement_file_hash_status( $mtl_file_id ) : '';
						$mtl_accept_count = mtl_count_agreement_acceptances( $mtl_agreement_id );
						$mtl_is_editing   = ( $mtl_agreement_edit_id === $mtl_agreement_id );

						// The number the edit warning names: members who ARE up
						// to date, because those are exactly the people a
						// version bump knocks back out of agreement.
						$mtl_up_to_date = mtl_count_members_agreed_to( $mtl_agreement_id, (int) $mtl_agreement->version_num );
						?>
						<li style="margin-bottom: 18px;">
							<div style="white-space: pre-wrap;"><?php echo esc_html( $mtl_agreement->agreement_text ); ?></div>

							<p style="margin: 6px 0 2px 0; font-size: 0.9em; color: #666;">
								<?php if ( $mtl_file_id > 0 && $mtl_file_url ) : ?>
									File: <a href="<?php echo esc_url( $mtl_file_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( wp_parse_url( $mtl_file_url, PHP_URL_PATH ) ) ); ?></a>
								<?php elseif ( $mtl_file_id > 0 ) : ?>
									File: <em>attachment <?php echo esc_html( $mtl_file_id ); ?> is missing</em>
								<?php else : ?>
									No file attached
								<?php endif; ?>
								&nbsp;&middot;&nbsp;
								v<?php echo esc_html( number_format_i18n( (int) $mtl_agreement->version_num ) ); ?>
								&middot; in use since <?php echo wp_kses_post( mtl_format_utc_datetime( $mtl_agreement->version_published_at, 'j M Y' ) ); ?>
								&nbsp;&middot;&nbsp;
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: number of members. */
										_n( '%s member is up to date', '%s members are up to date', $mtl_up_to_date, 'my-tool-library' ),
										number_format_i18n( $mtl_up_to_date )
									)
								);
								?>
							</p>

							<?php if ( $mtl_file_id > 0 ) : ?>
								<p style="margin: 0 0 4px 0; font-size: 0.85em; color: #666;">
									<?php if ( 'ok' === $mtl_hash_status && ! empty( $mtl_agreement->file_sha256 ) ) : ?>
										<?php // Printed in full: this is the value somebody compares against a file they have been sent, and a truncated one cannot be compared. ?>
										Fingerprint <code style="word-break: break-all; user-select: all;"><?php echo esc_html( (string) $mtl_agreement->file_sha256 ); ?></code>
										<?php if ( mtl_agreement_file_hash( $mtl_file_id ) !== (string) $mtl_agreement->file_sha256 ) : ?>
											<span style="color: #b32d2e;"><strong>The file has changed since this was recorded.</strong> Members who agreed earlier saw a different document. Open the agreement and save it to record the new file, which asks everyone to agree again.</span>
										<?php endif; ?>
									<?php elseif ( 'missing_file' === $mtl_hash_status ) : ?>
										<span style="color: #b32d2e;">No fingerprint. The file is missing from the Media Library. Members cannot open it.</span>
									<?php elseif ( 'not_an_attachment' === $mtl_hash_status ) : ?>
										<span style="color: #b32d2e;">No fingerprint. The attachment no longer exists.</span>
									<?php elseif ( 'too_large' === $mtl_hash_status ) : ?>
										No fingerprint. The file is too large to fingerprint. It still works normally.
									<?php else : ?>
										No fingerprint recorded.
									<?php endif; ?>
								</p>
							<?php endif; ?>

							<div class="mtl-agreement-actions" style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
								<form method="post" action="" style="display: inline;">
									<?php wp_nonce_field( 'mtl_move_agreement_action', 'mtl_move_agreement_nonce' ); ?>
									<input type="hidden" name="agreement_id" value="<?php echo esc_attr( $mtl_agreement_id ); ?>">
									<input type="hidden" name="direction" value="up">
									<button type="submit" name="mtl_move_agreement" class="button" <?php disabled( 0, $mtl_index ); ?> aria-label="Move up">&uarr;</button>
								</form>
								<form method="post" action="" style="display: inline;">
									<?php wp_nonce_field( 'mtl_move_agreement_action', 'mtl_move_agreement_nonce' ); ?>
									<input type="hidden" name="agreement_id" value="<?php echo esc_attr( $mtl_agreement_id ); ?>">
									<input type="hidden" name="direction" value="down">
									<button type="submit" name="mtl_move_agreement" class="button" <?php disabled( count( $mtl_active_list ) - 1, $mtl_index ); ?> aria-label="Move down">&darr;</button>
								</form>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'mtl_edit_agreement', $mtl_agreement_id ) ); ?>#mtl-agreement-<?php echo esc_attr( $mtl_agreement_id ); ?>">Edit</a>
								<?php if ( 0 === $mtl_accept_count ) : ?>
									<form method="post" action="" style="display: inline;" onsubmit="return confirm('Delete this agreement? No one has agreed to it, so nothing will be lost. This cannot be undone.');">
										<?php wp_nonce_field( 'mtl_delete_agreement_action', 'mtl_delete_agreement_nonce' ); ?>
										<input type="hidden" name="agreement_id" value="<?php echo esc_attr( $mtl_agreement_id ); ?>">
										<button type="submit" name="mtl_delete_agreement" class="button mtl-btn-danger">Delete</button>
									</form>
								<?php else : ?>
									<form method="post" action="" style="display: inline;" onsubmit="return confirm('Retire this agreement? It stops being shown and stops being required. Everyone who already agreed to it keeps that record.');">
										<?php wp_nonce_field( 'mtl_retire_agreement_action', 'mtl_retire_agreement_nonce' ); ?>
										<input type="hidden" name="agreement_id" value="<?php echo esc_attr( $mtl_agreement_id ); ?>">
										<button type="submit" name="mtl_retire_agreement" class="button">Retire</button>
									</form>
								<?php endif; ?>
							</div>

							<?php if ( $mtl_is_editing ) : ?>
								<div id="mtl-agreement-<?php echo esc_attr( $mtl_agreement_id ); ?>" style="border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; background: #fff; padding: 12px 16px; margin-top: 12px;">
									<h4 style="margin-top: 0;">Edit this agreement</h4>

									<?php if ( $mtl_agreement_conflict && (int) $mtl_agreement_conflict['agreement_id'] === $mtl_agreement_id ) : ?>
										<div class="notice notice-error inline" style="margin: 0 0 12px 0;">
											<p><strong>Nothing was saved.</strong> Someone else saved a change to this agreement while you had it open, so your version was not applied on top of theirs. Their wording is in the box below. Your unsaved wording is kept underneath, so copy anything you still need from it, then edit and save again.</p>
										</div>
									<?php endif; ?>

									<form method="post" action="">
										<?php wp_nonce_field( 'mtl_edit_agreement_action', 'mtl_edit_agreement_nonce' ); ?>
										<input type="hidden" name="agreement_id" value="<?php echo esc_attr( $mtl_agreement_id ); ?>">
										<!-- The version this form was rendered with. Submitted back so a
											save that lost a race with another admin is refused rather
											than applied on top of a change nobody reviewed. -->
										<input type="hidden" name="seen_version" value="<?php echo esc_attr( $mtl_agreement->version_num ); ?>">

										<p style="margin-top: 0;">
											<label for="mtl-agreement-text-<?php echo esc_attr( $mtl_agreement_id ); ?>"><strong>Text members must agree to</strong></label><br>
											<textarea id="mtl-agreement-text-<?php echo esc_attr( $mtl_agreement_id ); ?>" name="agreement_text" rows="6" style="width: 100%;" maxlength="<?php echo esc_attr( MTL_AGREEMENT_TEXT_MAXLENGTH ); ?>" required><?php echo esc_textarea( ( '' !== $mtl_agreement_form_text && ! $mtl_agreement_add_open && ! $mtl_agreement_conflict ) ? $mtl_agreement_form_text : $mtl_agreement->agreement_text ); ?></textarea>
										</p>

										<?php mtl_render_agreement_file_picker( 'mtl-file-edit-' . $mtl_agreement_id, $mtl_file_id ); ?>

										<div class="notice notice-warning inline" style="margin: 12px 0;">
											<p><strong>&#9888; Saving a change here asks every member to agree again.</strong></p>
											<p>
												<?php
												printf(
													/* translators: 1: number of members up to date, 2: the new version number, 3: number of members again. */
													esc_html__( '%1$s members have agreed to version %2$s. Saving makes this version %3$s, and all %1$s will be prompted on the website and blocked from reserving tools until they accept it. There is no way to make a small correction without this happening, and it cannot be undone.', 'my-tool-library' ),
													esc_html( number_format_i18n( $mtl_up_to_date ) ),
													esc_html( number_format_i18n( (int) $mtl_agreement->version_num ) ),
													esc_html( number_format_i18n( (int) $mtl_agreement->version_num + 1 ) )
												);
												?>
											</p>
											<p>No email is sent. To tell members, go to <strong>Membership &rarr; Member Agreements</strong> and send agreement requests.</p>
											<p style="margin-bottom: 0;">If you have not changed anything, saving does nothing and nobody is asked again.</p>
										</div>

										<p class="submit" style="margin: 0;">
											<button type="submit" name="mtl_edit_agreement" class="button button-primary">Save and re-prompt all members</button>
											<a class="button" href="<?php echo esc_url( remove_query_arg( 'mtl_edit_agreement' ) ); ?>">Cancel</a>
										</p>
									</form>

									<?php if ( $mtl_agreement_conflict && (int) $mtl_agreement_conflict['agreement_id'] === $mtl_agreement_id ) : ?>
										<p style="margin-bottom: 4px;"><strong>Your unsaved wording</strong></p>
										<textarea readonly rows="6" style="width: 100%; background: #f6f7f7;" aria-label="Your unsaved wording"><?php echo esc_textarea( $mtl_agreement_conflict['your_text'] ); ?></textarea>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<!-- Add an agreement -->
			<details <?php echo $mtl_agreement_add_open ? 'open' : ''; ?> style="border: 1px solid #ddd; padding: 10px 14px; margin-bottom: 16px;">
				<summary style="cursor: pointer; font-weight: 600;">Add an agreement</summary>
				<form method="post" action="" style="margin-top: 12px;">
					<?php wp_nonce_field( 'mtl_add_agreement_action', 'mtl_add_agreement_nonce' ); ?>
					<p style="margin-top: 0;">
						<label for="mtl-agreement-text-new"><strong>Text members must agree to</strong> (required)</label><br>
						<textarea id="mtl-agreement-text-new" name="agreement_text" rows="5" style="width: 100%;" maxlength="<?php echo esc_attr( MTL_AGREEMENT_TEXT_MAXLENGTH ); ?>" required placeholder="I agree to return tools by the due date, and to report any damage before returning them."><?php echo esc_textarea( $mtl_agreement_add_open ? $mtl_agreement_form_text : '' ); ?></textarea>
						<span style="font-size: 0.85em; color: #666;">Plain text only. Line breaks are kept; links and formatting are not. To give members a document, attach a file below.</span>
					</p>
					<?php mtl_render_agreement_file_picker( 'mtl-file-new', 0 ); ?>
					<p class="submit" style="margin-bottom: 0;">
						<button type="submit" name="mtl_add_agreement" class="button button-primary">Add Agreement</button>
					</p>
				</form>
			</details>

			<?php if ( $mtl_retired_list ) : ?>
				<details style="margin-top: 16px;">
					<summary style="cursor: pointer;">Retired agreements (<?php echo esc_html( number_format_i18n( count( $mtl_retired_list ) ) ); ?>)</summary>
					<ul style="margin-top: 12px;">
						<?php foreach ( $mtl_retired_list as $mtl_retired ) : ?>
							<li style="margin-bottom: 12px;">
								<div style="white-space: pre-wrap; color: #555;"><?php echo esc_html( $mtl_retired->agreement_text ); ?></div>
								<p style="margin: 4px 0; font-size: 0.9em; color: #666;">
									v<?php echo esc_html( number_format_i18n( (int) $mtl_retired->version_num ) ); ?>
									&middot; retired <?php echo wp_kses_post( mtl_format_utc_datetime( $mtl_retired->retired_at, 'j M Y' ) ); ?>
								</p>
								<form method="post" action="" onsubmit="return confirm('Put this agreement back into use? It goes to the end of the list at its existing version number. Members who never agreed to it will be outstanding.');">
									<?php wp_nonce_field( 'mtl_unretire_agreement_action', 'mtl_unretire_agreement_nonce' ); ?>
									<input type="hidden" name="agreement_id" value="<?php echo esc_attr( $mtl_retired->agreement_id ); ?>">
									<button type="submit" name="mtl_unretire_agreement" class="button">Put back into use</button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">

			<h4 style="margin-bottom: 4px;">Emails</h4>
			<p style="font-size: 0.9em; color: #666; margin-top: 0;">Both emails are plain text. The plugin writes the greeting, the numbered list of what was agreed to, and the sign-off; what you write below goes in between. Leave a field empty to use the wording the plugin ships with.</p>
			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_agreement_emails_action', 'mtl_agreement_emails_nonce' ); ?>
				<table class="form-table" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="mtl_agreement_email_subject">Confirmation subject</label></th>
						<td>
							<input type="text" name="mtl_agreement_email_subject" id="mtl_agreement_email_subject" class="regular-text" maxlength="150"
								value="<?php echo esc_attr( (string) get_option( 'mtl_agreement_email_subject', '' ) ); ?>"
								placeholder="<?php echo esc_attr( $mtl_agreement_emails['subject'] ); ?>">
							<p class="description">Your organization name is added in front of this automatically.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_agreement_email_body">Confirmation body</label></th>
						<td>
							<textarea name="mtl_agreement_email_body" id="mtl_agreement_email_body" rows="4" class="large-text" placeholder="<?php echo esc_attr( $mtl_agreement_emails['body'] ); ?>"><?php echo esc_textarea( (string) get_option( 'mtl_agreement_email_body', '' ) ); ?></textarea>
							<p class="description">Sent to a member after they agree, with the agreed wording listed and any attached files included. Do not list the agreements here, because the plugin does that.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_agreement_request_email_body">Request body</label></th>
						<td>
							<textarea name="mtl_agreement_request_email_body" id="mtl_agreement_request_email_body" rows="4" class="large-text" placeholder="<?php echo esc_attr( $mtl_agreement_emails['request_body'] ); ?>"><?php echo esc_textarea( (string) get_option( 'mtl_agreement_request_email_body', '' ) ); ?></textarea>
							<p class="description">Sent when you ask members to agree, from the Membership page. Only used when members agree online.</p>
						</td>
					</tr>
				</table>
				<p class="submit" style="margin: 0;">
					<button type="submit" name="mtl_save_agreement_emails" class="button button-primary">Save Email Wording</button>
				</p>
			</form>
		</div>
	</div>

	<!-- Band 2: the lookup lists. Two up on a wide screen, stacking on a
		narrow one, the responsive behaviour the page already had. -->
	<div class="mtl-setup-row">

		<!-- Categories & Tags Management -->
		<div class="mtl-setup-tile mtl-setup-tile-half">
			<h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Categories & Tags</h3>
			<p style="font-size: 0.9em; color: #666;">Add new lookup values here so they're available to choose from when adding or editing tools in the Inventory tab.</p>

			<h4 style="margin-bottom: 0;">Categories</h4>
			<?php if ( $categories ) : ?>
				<form method="post" action="" onsubmit="return confirm('Delete the selected categories? Any tools using them will simply lose that category. This cannot be undone.');">
					<?php wp_nonce_field( 'mtl_delete_categories_action', 'mtl_delete_categories_nonce' ); ?>
					<div class="mtl-chip-row">
						<?php foreach ( $categories as $cat ) : ?>
							<label class="mtl-chip-checkbox">
								<input type="checkbox" name="delete_category_ids[]" value="<?php echo esc_attr( $cat->category_id ); ?>">
								<?php echo esc_html( $cat->category_name ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="submit" style="margin: 8px 0 0 0;">
						<button type="submit" name="mtl_delete_categories" class="button mtl-btn-danger">Delete Selected</button>
					</p>
				</form>
			<?php else : ?>
				<div class="mtl-chip-row">
					<span style="color: #999; font-size: 0.85em;">None yet.</span>
				</div>
			<?php endif; ?>
			<form method="post" action="" class="mtl-add-lookup-form">
				<?php wp_nonce_field( 'mtl_add_category_action', 'mtl_add_category_nonce' ); ?>
				<input type="text" name="new_category_name" maxlength="50" placeholder="New category name" class="regular-text" required>
				<button type="submit" name="mtl_add_category" class="button button-primary">Add Category</button>
			</form>

			<hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">

			<h4 style="margin-bottom: 0;">Tags</h4>
			<?php if ( $tags ) : ?>
				<form method="post" action="" onsubmit="return confirm('Delete the selected tags? Any tools using them will simply lose that tag. This cannot be undone.');">
					<?php wp_nonce_field( 'mtl_delete_tags_action', 'mtl_delete_tags_nonce' ); ?>
					<div class="mtl-chip-row">
						<?php foreach ( $tags as $tag ) : ?>
							<label class="mtl-chip-checkbox">
								<input type="checkbox" name="delete_tag_ids[]" value="<?php echo esc_attr( $tag->tag_id ); ?>">
								<?php echo esc_html( $tag->tag_name ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="submit" style="margin: 8px 0 0 0;">
						<button type="submit" name="mtl_delete_tags" class="button mtl-btn-danger">Delete Selected</button>
					</p>
				</form>
			<?php else : ?>
				<div class="mtl-chip-row">
					<span style="color: #999; font-size: 0.85em;">None yet.</span>
				</div>
			<?php endif; ?>
			<form method="post" action="" class="mtl-add-lookup-form">
				<?php wp_nonce_field( 'mtl_add_tag_action', 'mtl_add_tag_nonce' ); ?>
				<input type="text" name="new_tag_name" maxlength="50" placeholder="New tag name" class="regular-text" required>
				<button type="submit" name="mtl_add_tag" class="button button-primary">Add Tag</button>
			</form>
		</div>

		<!-- Member Trainings Management -->
		<div class="mtl-setup-tile mtl-setup-tile-half">
			<h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Member Trainings</h3>
			<p style="font-size: 0.9em; color: #666;">Safety and skill trainings a member can complete. Staff record who has completed what (with the date) on the Membership page; members see their own on their account page.</p>

			<?php if ( $trainings ) : ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'mtl_save_trainings_action', 'mtl_save_trainings_nonce' ); ?>
					<table class="widefat striped" style="margin: 0 0 10px 0;">
						<thead>
							<tr>
								<th style="width: 32%;">Name</th>
								<th>Badge Image URL</th>
								<th style="width: 22%;">Valid For</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $trainings as $training ) : ?>
								<tr>
									<td>
										<input type="text" name="training_name[<?php echo esc_attr( $training->training_id ); ?>]" maxlength="50" style="width: 100%;" value="<?php echo esc_attr( $training->training_name ); ?>" required>
									</td>
									<td>
										<input type="url" name="training_badge_url[<?php echo esc_attr( $training->training_id ); ?>]" style="width: 100%;" value="<?php echo esc_url( (string) $training->badge_image_url ); ?>" placeholder="https://...">
									</td>
									<td>
										<input type="number" name="training_cert_months[<?php echo esc_attr( $training->training_id ); ?>]" min="1" max="600" step="1" style="width: 70px;" value="<?php echo esc_attr( $training->certification_length_months > 0 ? $training->certification_length_months : '' ); ?>" placeholder="&mdash;">
										<span style="font-size: 0.85em; color: #666;">months</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="font-size: 0.85em; color: #666; margin: 0 0 10px 0;">
						<strong>Badge Image URL</strong> is optional. Upload the badge to the WordPress Media Library and paste its File URL. It replaces the plain green pill on a member&rsquo;s own account page, and only shows while their certification is still current.<br>
						<strong>Valid For</strong> is how many months a completed training stays current, counted from the date that member completed it. Leave it blank for a training that never expires. Changing it re-dates every member who holds that training straight away.
					</p>
					<p class="submit" style="margin: 0;">
						<button type="submit" name="mtl_save_trainings" class="button button-primary">Save Trainings</button>
					</p>
				</form>

				<hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">

				<form method="post" action="" onsubmit="return confirm('Delete the selected trainings? Any members who completed them will lose that record, including the date. This cannot be undone.');">
					<?php wp_nonce_field( 'mtl_delete_trainings_action', 'mtl_delete_trainings_nonce' ); ?>
					<div class="mtl-chip-row">
						<?php foreach ( $trainings as $training ) : ?>
							<label class="mtl-chip-checkbox">
								<input type="checkbox" name="delete_training_ids[]" value="<?php echo esc_attr( $training->training_id ); ?>">
								<?php echo esc_html( $training->training_name ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="submit" style="margin: 8px 0 0 0;">
						<button type="submit" name="mtl_delete_trainings" class="button mtl-btn-danger">Delete Selected</button>
					</p>
				</form>
			<?php else : ?>
				<div class="mtl-chip-row">
					<span style="color: #999; font-size: 0.85em;">None yet.</span>
				</div>
			<?php endif; ?>

			<form method="post" action="" class="mtl-add-lookup-form">
				<?php wp_nonce_field( 'mtl_add_training_action', 'mtl_add_training_nonce' ); ?>
				<input type="text" name="new_training_name" maxlength="50" placeholder="New training name" class="regular-text" required>
				<button type="submit" name="mtl_add_training" class="button button-primary">Add Training</button>
			</form>
		</div>

	</div>

	<!-- Band 3: Export Data, full width. Directly above Database
		Configuration on purpose, because taking a backup is the step that makes
		a reset recoverable, so an admin heading for the reset button has
		to pass it first. -->
	<div class="mtl-setup-row">

		<!-- Export Data -->
		<div class="mtl-setup-tile mtl-setup-tile-full">
			<h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Export Data</h3>

			<p>Download a complete copy of all My Tool Library data: members, verifications, inventory, categories, tags, loans and reservations.</p>

			<ul style="font-size: 0.85em; color: #666; margin: 0 0 15px 20px;">
				<li><strong>.sql dump</strong>: a single SQL file (DROP + CREATE + INSERT) you can import into any MySQL/MariaDB database. Table names <strong>keep</strong> the <code><?php echo esc_html( $wpdb->prefix ); ?></code> prefix (e.g. <code><?php echo esc_html( $wpdb->prefix ); ?>members</code>), matching how the plugin creates them. <strong>This is the one to keep as a backup:</strong> it preserves every record&rsquo;s ID, so restoring it puts members, loans, reservations and members&rsquo; online sign-ins back exactly as they were.</li>
				<li><strong>.zip of CSVs</strong>: one <code>.csv</code> file per table, named after the table without the prefix (e.g. <code>members.csv</code>), handy for spreadsheets and for reading in Excel.</li>
			</ul>

			<div style="background: #fff8e5; border-left: 4px solid #dba617; padding: 12px; margin-bottom: 20px; font-size: 0.9em;">
				<strong>A CSV export is not a backup.</strong> The Membership and Inventory bulk importers always assign new IDs, so re-importing <code>members.csv</code> after a reset creates fresh member records that no longer match members&rsquo; existing sign-ins, and there is no importer at all for loans or reservations. To restore a library, use the <strong>.sql dump</strong> with phpMyAdmin, the <code>mysql</code> command line, or <code>wp db import</code>.
			</div>

			<div style="background: #fff8e5; border-left: 4px solid #dba617; padding: 12px; margin-bottom: 20px; font-size: 0.9em;">
				<strong>Note:</strong> The export includes members&rsquo; sensitive verification document links. Store the downloaded file securely.
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_export_action', 'mtl_export_nonce' ); ?>
				<p class="submit" style="display: flex; gap: 8px; flex-wrap: wrap;">
					<button type="submit" name="mtl_export_sql" class="button button-primary">Download .sql dump</button>
					<button type="submit" name="mtl_export_zip" class="button button-secondary">Download .zip of CSVs</button>
				</p>
			</form>
		</div>
	</div>

	<!-- Band 4: Database Configuration, full width and last on the page.
		It is the one destructive control here, so it sits furthest from the
		settings an admin edits day to day. -->
	<div class="mtl-setup-row">

		<!-- Database Setup Tool -->
		<div class="mtl-setup-tile mtl-setup-tile-full">
			<h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; color: #d63638;">Database Configuration</h3>

			<p>Builds the plugin's database tables from the bundled <code>schema.sql</code> file. Required once when you first install the plugin; on a library that is already running, it is a full reset, not a repair.</p>

			<div style="background: #fdf2f2; border-left: 4px solid #d63638; padding: 12px; margin-bottom: 20px;">
				<p style="margin: 0 0 8px 0;"><strong>Warning: this permanently deletes all My Tool Library data.</strong></p>
				<p style="margin: 0 0 8px 0;"><code>schema.sql</code> begins by dropping every one of the plugin's tables, so running it <em>always</em> erases what is currently stored &#40;every member, verification document, training record, tool, category, tag, loan and reservation&#41; and then recreates the tables empty. This is not a conditional risk and there is no undo.</p>
				<p style="margin: 0 0 8px 0;">Members&rsquo; <strong>WordPress sign-ins are not touched</strong> but the records those sign-ins point to are gone, so members will be told their account can&rsquo;t be matched until the data is restored. Re-importing members from CSV does <em>not</em> fix this: it assigns brand-new member IDs.</p>
				<p style="margin: 0;">Use <strong>Export Data</strong> first if there is any chance you will need the current contents back, and restore from the <strong>.sql dump</strong>.</p>
			</div>

			<form method="post" action="" id="mtl-db-reset-form">
				<?php wp_nonce_field( 'mtl_run_db_action', 'mtl_db_nonce' ); ?>
				<?php
				// Filled in by the confirmation prompt below; the server rejects the submission unless it matches exactly.
				?>
				<input type="hidden" name="mtl_reset_confirmation" id="mtl-db-reset-confirmation" value="">
				<label class="mtl-lock-toggle">
					<input type="checkbox" required>
					<span class="mtl-lock-slider"></span>
					<span class="mtl-lock-label">Slide to unlock. I understand this will erase existing data</span>
				</label>
				<p class="submit">
					<input type="submit" name="mtl_run_db_setup" class="button button-secondary mtl-danger-btn" value="Run Database Setup">
				</p>
			</form>

			<script>
				/*
				 * Second gate on the database reset: the slide-to-unlock toggle stops
				 * an accidental click, and this prompt stops a deliberate-but-unconsidered
				 * one by making the admin type the phrase out. The same phrase is
				 * re-checked server-side (see the mtl_run_db_setup handler), so this is
				 * a usability layer rather than the security boundary.
				 */
				(function() {
					var form = document.getElementById('mtl-db-reset-form');
					if (!form) {
						return;
					}
					var phrase = <?php echo wp_json_encode( mtl_db_reset_confirmation_phrase() ); ?>;
					var field = document.getElementById('mtl-db-reset-confirmation');

					form.addEventListener('submit', function(event) {
						var typed = window.prompt(
							'This permanently deletes ALL My Tool Library data: members, tools, ' +
							'loans, reservations and everything else. It cannot be undone.\n\n' +
							'To confirm, type this phrase exactly:\n\n' + phrase
						);

						// Cancelled the prompt: leave the page untouched.
						if (null === typed) {
							event.preventDefault();
							return;
						}

						if (typed.trim() !== phrase) {
							event.preventDefault();
							field.value = '';
							window.alert('That phrase did not match, so nothing was deleted.\n\nExpected: ' + phrase);
							return;
						}

						field.value = typed.trim();
					});
				}());
			</script>
		</div>
	</div>

	<script>
		// "Never expires" greys out the day stepper. Disabling it also stops it
		// being submitted, which is what lets the save handler treat the
		// checkbox as authoritative without having to reconcile the two.
		(function() {
			var never = document.getElementById('mtl_reservation_hold_never');
			var days = document.getElementById('mtl_reservation_hold_days');
			if (!never || !days) {
				return;
			}
			never.addEventListener('change', function() {
				days.disabled = never.checked;
			});
		}());

		// Fills in the color pickers from a "Quick Theme Presets" swatch; still requires clicking "Save Settings".
		function mtlApplySwatch(headerColor, bodyColor, linkColor, accentColor) {
			document.getElementById('mtl_header_color').value = headerColor;
			document.getElementById('mtl_body_color').value = bodyColor;
			document.getElementById('mtl_link_color').value = linkColor;
			document.getElementById('mtl_accent_color').value = accentColor;
		}

		// Restores every appearance field to the plugin's defaults (matching
		// get_option()'s fallbacks); nothing is saved until "Save Settings" is clicked.
		function mtlApplyInherit() {
			var defaults = {
				mtl_header_color: '#ff6600',
				mtl_header_font: 'inherit',
				mtl_header_size: '2em',
				mtl_header_weight: '700',
				mtl_header_transform: 'none',
				mtl_body_color: '#096491',
				mtl_body_font: 'inherit',
				mtl_body_size: '14px',
				mtl_body_weight: '400',
				mtl_link_color: '#00b3ff',
				mtl_link_font: 'inherit',
				mtl_link_size: 'inherit',
				mtl_link_decoration: 'none',
				mtl_accent_color: '#f7c600',
				mtl_background_color: '#ffffff',
				mtl_border_radius: '4px',
				mtl_button_scale: '1'
			};
			for (var name in defaults) {
				var el = document.querySelector('[name="' + name + '"]');
				if (el) {
					el.value = defaults[name];
				}
			}
		}
	</script>

	<script>
		// Member Agreements: the Media Library picker, and the one mode change
		// that needs confirming before it happens.
		(function() {
			// The picker. Unfiltered on purpose, since a library may reasonably
			// attach a PDF, a scanned form or an image, so nothing here
			// restricts the type.
			var frames = {};
			document.querySelectorAll('.mtl-agreement-file-select').forEach(function(button) {
				button.addEventListener('click', function() {
					var target = button.getAttribute('data-target');
					if (typeof wp === 'undefined' || !wp.media) {
						return;
					}
					if (!frames[target]) {
						frames[target] = wp.media({
							title: 'Choose a file for this agreement',
							button: { text: 'Use this file' },
							multiple: false
						});
						frames[target].on('select', function() {
							var file = frames[target].state().get('selection').first().toJSON();
							document.getElementById(target + '-id').value = file.id;
							document.getElementById(target + '-name').textContent = file.filename || file.title;
							var remove = document.querySelector('.mtl-agreement-file-remove[data-target="' + target + '"]');
							if (remove) {
								remove.style.display = '';
							}
						});
					}
					frames[target].open();
				});
			});

			document.querySelectorAll('.mtl-agreement-file-remove').forEach(function(button) {
				button.addEventListener('click', function() {
					var target = button.getAttribute('data-target');
					document.getElementById(target + '-id').value = '';
					document.getElementById(target + '-name').textContent = '(none chosen)';
					button.style.display = 'none';
				});
			});

			// Only one transition needs an interstitial: paper to full blocks
			// every outstanding member from reserving the instant it is saved,
			// with no email to soften it. Every other transition is either
			// harmless or a release, so confirming them all would train the
			// admin to click through this one too.
			var modeForm = document.getElementById('mtl-agreements-mode-form');
			if (!modeForm) {
				return;
			}
			modeForm.addEventListener('submit', function(event) {
				var button = modeForm.querySelector('[name="mtl_save_agreements_mode"]');
				var chosen = modeForm.querySelector('[name="mtl_agreements_mode"]:checked');
				if (!button || !chosen) {
					return;
				}
				if (button.getAttribute('data-mtl-current-mode') !== 'paper' || chosen.value !== 'full') {
					return;
				}
				var count = parseInt(button.getAttribute('data-mtl-outstanding'), 10) || 0;
				var message = 'Switching to full mode will immediately require ' + count +
					(count === 1 ? ' member' : ' members') +
					' to agree online, and block them from reserving tools until they do.\n\n' +
					'They will not be emailed automatically. Send agreement requests from the Membership page.\n\n' +
					'Switching back to "Track signed paper only" releases everyone again straight away.';
				if (!window.confirm(message)) {
					event.preventDefault();
				}
			});
		})();
	</script>
	<?php
	echo '</div>';
}
