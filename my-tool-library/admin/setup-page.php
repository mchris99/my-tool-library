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
		'tool_subcategories',
		'tool_subcategory_mappings',
		'tool_tags',
		'tool_tag_mappings',
		'tool_training_mappings',
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
 * The exact phrase an admin must type to confirm a restore from backup.
 * Different from the reset phrase, so neither can be typed from habit while
 * meaning the other.
 *
 * @return string
 */
function mtl_db_restore_confirmation_phrase() {
	return 'Replace ALL my data';
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

/**
 * The Setup page section the current request came from, so that section
 * renders open with the result of what was just done in view, rather than
 * folding back to its default.
 *
 * Read from the submit button's name (for the settings forms, its value),
 * the agreement Edit link, and ?mtl_open= on links that lead to a section.
 *
 * @return string Section key, or '' when the request names none.
 */
function mtl_setup_requested_section() {
	$buttons = array(
		'catalog'    => array( 'mtl_add_category', 'mtl_delete_categories', 'mtl_add_subcategory', 'mtl_delete_subcategories', 'mtl_add_tag', 'mtl_delete_tags' ),
		'trainings'  => array( 'mtl_add_training', 'mtl_delete_trainings', 'mtl_save_trainings' ),
		'agreements' => array( 'mtl_save_agreements_mode', 'mtl_add_agreement', 'mtl_edit_agreement', 'mtl_retire_agreement', 'mtl_unretire_agreement', 'mtl_delete_agreement', 'mtl_move_agreement', 'mtl_save_agreement_emails' ),
		'backups'    => array( 'mtl_save_backup_settings', 'mtl_backup_now' ),
		'restore'    => array( 'mtl_restore_sql' ),
		'database'   => array( 'mtl_run_db_setup' ),
	);

	// Only chooses what is expanded. Every handler checks its own nonce
	// before acting on anything.
	// phpcs:disable WordPress.Security.NonceVerification
	foreach ( $buttons as $section => $names ) {
		foreach ( $names as $name ) {
			if ( isset( $_POST[ $name ] ) ) {
				return $section;
			}
		}
	}
	if ( isset( $_POST['mtl_save_settings'] ) ) {
		return sanitize_key( wp_unslash( $_POST['mtl_save_settings'] ) );
	}
	// A restore upload larger than post_max_size arrives with nothing in
	// $_POST at all; see the restore handler.
	if ( isset( $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_LENGTH'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && empty( $_POST ) && (int) $_SERVER['CONTENT_LENGTH'] > 0 ) {
		return 'restore';
	}
	if ( isset( $_GET['mtl_edit_agreement'] ) ) {
		return 'agreements';
	}
	if ( isset( $_GET['mtl_open'] ) ) {
		return sanitize_key( wp_unslash( $_GET['mtl_open'] ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification

	return '';
}

/**
 * Opens one collapsible section of the Setup page. Close it with
 * mtl_setup_section_end().
 *
 * A native <details>, so it opens and closes without JavaScript. The summary
 * says in one line what the section holds, so a closed one can be found
 * without opening it, and can carry a badge for state worth seeing at a
 * glance, such as whether automatic backups are on.
 *
 * @param string $key  Section key. The element id is "mtl-section-{$key}".
 * @param array  $args {
 *     What to show.
 *
 *     @type string $title  Heading.
 *     @type string $desc   What the section holds.
 *     @type bool   $open   Whether it starts open.
 *     @type bool   $danger Whether it replaces or erases data, which marks it in red.
 *     @type string $badge  Short status text, or '' for none.
 *     @type string $tone   Badge colour: '' for neutral, 'warn' or 'error'.
 * }
 */
function mtl_setup_section_start( $key, $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'title'  => '',
			'desc'   => '',
			'open'   => false,
			'danger' => false,
			'badge'  => '',
			'tone'   => '',
		)
	);

	$class = 'mtl-setup-section' . ( $args['danger'] ? ' mtl-setup-section-danger' : '' );
	$badge = 'mtl-setup-badge' . ( '' !== $args['tone'] ? ' mtl-setup-badge-' . $args['tone'] : '' );
	?>
	<details class="<?php echo esc_attr( $class ); ?>" id="mtl-section-<?php echo esc_attr( $key ); ?>" <?php echo $args['open'] ? 'open' : ''; ?>>
		<summary>
			<h3><?php echo esc_html( $args['title'] ); ?></h3>
			<span class="mtl-setup-section-desc"><?php echo esc_html( $args['desc'] ); ?></span>
			<?php if ( '' !== $args['badge'] ) : ?>
				<span class="<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $args['badge'] ); ?></span>
			<?php endif; ?>
		</summary>
		<div class="mtl-setup-section-body">
	<?php
}

/**
 * Closes a section opened with mtl_setup_section_start().
 */
function mtl_setup_section_end() {
	echo '</div></details>';
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
 * Download a MySQL-style .sql dump of every table; see mtl_write_sql_export().
 *
 * @param string[] $bare_tables Bare table names, see mtl_export_table_names().
 */
function mtl_export_as_sql( $bare_tables ) {
	// Discard any buffered output so nothing corrupts the file body.
	while ( ob_get_level() ) {
		ob_end_clean();
	}

	nocache_headers();
	header( 'Content-Type: application/sql; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="my-tool-library-export-' . gmdate( 'Y-m-d' ) . '.sql"' );

	// Written straight to the response as it is built. This is the file being
	// downloaded (a raw .sql dump), not HTML; escaping would corrupt it.
	$out = fopen( 'php://output', 'wb' );
	mtl_write_sql_export(
		$bare_tables,
		function ( $text ) use ( $out ) {
			fwrite( $out, $text );
		}
	);
	fclose( $out );
	exit;
}

/**
 * Build a MySQL-style .sql dump (DROP + CREATE + INSERTs) of every table,
 * handing it to $write a piece at a time: to the browser for Export Data, or
 * to the encryptor for an automatic backup (mtl_backup_create()). Table names
 * keep the WordPress prefix (e.g. wp_members) so re-running the dump restores
 * the tables in place, matching schema.sql. (The CSV/zip export uses bare
 * names instead.)
 *
 * This is also the file Restore from Backup reads (mtl_restore_from_sql()).
 * The reader recognises an export by the first line and the prefix line of
 * the header, and treats a file as complete only when its last statement is
 * the closing SET FOREIGN_KEY_CHECKS=1. Change any of those here and the
 * reader has to change with them.
 *
 * @param string[] $bare_tables Bare table names, see mtl_export_table_names().
 * @param callable $write       Receives each piece of the dump as a string.
 * @return string '' on success, or what went wrong. A failed dump stops
 *                without its closing statement, so it can never be restored.
 */
function mtl_write_sql_export( $bare_tables, $write ) {
	global $wpdb;
	$prefix = $wpdb->prefix;

	// Every table is read from one snapshot, so a loan written while the
	// file is being built can't appear without the member it belongs to.
	$wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );

	// Handed over as it is built rather than collected into one string, and
	// read a page at a time below, so a large library's history never has to
	// fit in memory at once.
	$write( "-- My Tool Library data export\n" );
	$write( '-- Generated ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
	$write( '-- Site: ' . home_url() . "\n" );
	$write( '-- Database version: ' . (int) MTL_DB_VERSION . "\n" );
	$write( '-- Table names keep the WordPress "' . $prefix . "\" prefix, matching how the\n" );
	$write( "-- plugin creates them in schema.sql.\n" );
	$write( "-- Restore it from My Tool Library > Setup > Restore from Backup.\n\n" );
	$write( "SET FOREIGN_KEY_CHECKS=0;\n\n" );

	// Set when a read fails part-way. The file then ends without its
	// closing statement, which is what tells Restore from Backup (and
	// anyone reading it) that it is incomplete.
	$failure = '';

	// $full below is always a trusted prefix + hardcoded bare name from
	// mtl_export_table_names() (no user input), so it's safe to interpolate
	// into these backtick-quoted identifiers; phpcs can't verify that.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $bare_tables as $bare ) {
		$full = $prefix . $bare;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
		if ( ! $exists ) {
			$write( "-- (table `$full` not found in the database; skipped)\n\n" );
			continue;
		}

		$write( "-- ------------------------------------------------------------\n" );
		$write( "-- Table: $full\n" );
		$write( "-- ------------------------------------------------------------\n" );
		$write( "DROP TABLE IF EXISTS `$full`;\n" );

		// Used verbatim: SHOW CREATE TABLE already emits the prefixed name
		// and any prefixed FK references, keeping the dump aligned with the real tables.
		$create_row = $wpdb->get_row( "SHOW CREATE TABLE `$full`", ARRAY_N );
		if ( empty( $create_row[1] ) ) {
			$failure = "could not read the structure of `$full`: " . $wpdb->last_error;
			break;
		}
		$write( $create_row[1] . ";\n\n" );

		$cols     = $wpdb->get_col( "SHOW COLUMNS FROM `$full`" );
		$col_list = '`' . implode( '`, `', $cols ) . '`';

		// Paged by primary key, which every table has. Without one, OFFSET
		// paging has no stable order to follow, so the table is read whole.
		$keys     = $wpdb->get_col( "SHOW KEYS FROM `$full` WHERE Key_name = 'PRIMARY'", 4 );
		$order_by = $keys ? ' ORDER BY `' . implode( '`, `', $keys ) . '`' : '';
		$per_page = $keys ? 1000 : 0;

		for ( $offset = 0; ; $offset += $per_page ) {
			$rows = $per_page
				? $wpdb->get_results( "SELECT * FROM `$full`{$order_by} LIMIT {$per_page} OFFSET {$offset}", ARRAY_A )
				: $wpdb->get_results( "SELECT * FROM `$full`", ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				$failure = "could not read the rows of `$full`: " . $wpdb->last_error;
				break 2;
			}

			foreach ( $rows as $row ) {
				$vals = array();
				foreach ( $cols as $col ) {
					$v = array_key_exists( $col, $row ) ? $row[ $col ] : null;
					// esc_sql() turns every % into wpdb's placeholder token,
					// which only $wpdb->query() turns back. This text never
					// passes through a query, so the % has to be put back
					// here or it reaches the file as "{64 hex digits}".
					$vals[] = ( null === $v ) ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( esc_sql( $v ) ) . "'";
				}
				$write( "INSERT INTO `$full` ($col_list) VALUES (" . implode( ', ', $vals ) . ");\n" );
			}

			if ( ! $per_page || count( $rows ) < $per_page ) {
				break;
			}
		}
		$write( "\n" );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$wpdb->query( 'COMMIT' );

	if ( '' !== $failure ) {
		$write( "\n-- EXPORT FAILED: $failure\n" );
		$write( "-- This file is incomplete and cannot be restored.\n" );
		return $failure;
	}

	$write( "SET FOREIGN_KEY_CHECKS=1;\n" );
	return '';
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

		// Trusted prefix + hardcoded bare name, as in mtl_export_as_sql().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
 * Runs the bundled admin/schema.sql: drops every plugin table, recreates it
 * empty and adds the starter categories, tags and trainings. Used by Run
 * Database Setup, and by Restore from Backup on a site with no tables yet.
 *
 * @return array|WP_Error array( 'ok' => queries that succeeded, 'failed' =>
 *                        list of array( query, database error ) ), or an
 *                        error when schema.sql is missing.
 */
function mtl_run_schema_sql() {
	global $wpdb;

	$sql_file_path = MTL_PLUGIN_DIR . 'admin/schema.sql';
	if ( ! file_exists( $sql_file_path ) ) {
		return new WP_Error( 'mtl_schema_missing', 'Could not find schema.sql.' );
	}
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

	$ok     = 0;
	$failed = array();
	foreach ( $queries as $query ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- runs the plugin's own bundled admin/schema.sql, not user input.
		if ( false === $wpdb->query( $query ) ) {
			$failed[] = array( $query, $wpdb->last_error );
		} else {
			++$ok;
		}
	}

	return array(
		'ok'     => $ok,
		'failed' => $failed,
	);
}

// ==========================================================================
// RESTORE FROM BACKUP
//
// Reads a .sql dump made by mtl_export_as_sql() back into the plugin's tables.
// Nothing in the uploaded file is ever run as SQL. The reader below splits it
// into tokens the way MySQL would, accepts only the four statements an export
// contains, and pulls the literal values out of each INSERT; the restore then
// inserts those values itself. Whatever a file says, it can only put rows of
// plain values into the plugin's own tables.
// ==========================================================================

/**
 * A restore error naming the line of the uploaded file it is about.
 *
 * @param string $sql     Whole file.
 * @param int    $at      Byte offset of the problem.
 * @param string $message What is wrong, starting lower case.
 * @return WP_Error
 */
function mtl_restore_error_at( $sql, $at, $message ) {
	$line = substr_count( substr( $sql, 0, max( 0, (int) $at ) ), "\n" ) + 1;
	return new WP_Error( 'mtl_restore_file', 'Line ' . $line . ' of the file: ' . $message );
}

/**
 * Reads the next token of an uploaded .sql file, skipping whitespace and
 * comments.
 *
 * Quoted strings come back decoded, using MySQL's backslash escapes (the ones
 * esc_sql() writes) and doubled quotes. A character that starts no other
 * token comes back alone as 'punct', and it is up to the caller to accept or
 * refuse it.
 *
 * @param string $sql Whole file.
 * @param int    $pos Byte offset, moved past the token.
 * @return array|null|WP_Error array( 'type' => 'word'|'name'|'string'|'number'|'punct',
 *                             'value' => string, 'at' => int ), or null at the end.
 */
function mtl_restore_next_token( $sql, &$pos ) {
	$len        = strlen( $sql );
	$whitespace = " \t\r\n\f\v";

	while ( $pos < $len ) {
		$pos += strspn( $sql, $whitespace, $pos );
		if ( $pos >= $len ) {
			return null;
		}
		$pair = substr( $sql, $pos, 2 );
		// MySQL reads "--" as a comment only when whitespace follows it.
		if ( '#' === $sql[ $pos ] || ( '--' === $pair && ( $pos + 2 >= $len || false !== strpos( $whitespace, $sql[ $pos + 2 ] ) ) ) ) {
			$eol = strpos( $sql, "\n", $pos );
			$pos = false === $eol ? $len : $eol + 1;
		} elseif ( '/*' === $pair ) {
			$end = strpos( $sql, '*/', $pos + 2 );
			if ( false === $end ) {
				return mtl_restore_error_at( $sql, $pos, 'a comment is never closed.' );
			}
			$pos = $end + 2;
		} else {
			break;
		}
	}
	if ( $pos >= $len ) {
		return null;
	}

	$at = $pos;
	$ch = $sql[ $pos ];

	if ( "'" === $ch || '"' === $ch ) {
		// \% and \_ keep their backslash, as they do in MySQL outside LIKE.
		$escapes = array(
			'0' => "\0",
			'b' => "\x08",
			'n' => "\n",
			'r' => "\r",
			't' => "\t",
			'Z' => "\x1A",
			'%' => '\\%',
			'_' => '\\_',
		);
		$value   = '';
		++$pos;
		while ( true ) {
			$run    = strcspn( $sql, $ch . '\\', $pos );
			$value .= substr( $sql, $pos, $run );
			$pos   += $run;
			if ( $pos >= $len || ( '\\' === $sql[ $pos ] && $pos + 1 >= $len ) ) {
				return mtl_restore_error_at( $sql, $at, 'a quoted value is never closed, so the file may have been cut short.' );
			}
			if ( '\\' === $sql[ $pos ] ) {
				$next   = $sql[ $pos + 1 ];
				$value .= isset( $escapes[ $next ] ) ? $escapes[ $next ] : $next;
				$pos   += 2;
			} elseif ( $pos + 1 < $len && $ch === $sql[ $pos + 1 ] ) {
				$value .= $ch;
				$pos   += 2;
			} else {
				++$pos;
				return array(
					'type'  => 'string',
					'value' => $value,
					'at'    => $at,
				);
			}
		}
	}

	if ( '`' === $ch ) {
		$value = '';
		++$pos;
		while ( true ) {
			$end = strpos( $sql, '`', $pos );
			if ( false === $end ) {
				return mtl_restore_error_at( $sql, $at, 'a `name` is never closed.' );
			}
			$value .= substr( $sql, $pos, $end - $pos );
			$pos    = $end + 1;
			// A doubled backtick is a literal one inside the name.
			if ( $pos < $len && '`' === $sql[ $pos ] ) {
				$value .= '`';
				++$pos;
				continue;
			}
			return array(
				'type'  => 'name',
				'value' => $value,
				'at'    => $at,
			);
		}
	}

	$digits = '0123456789';
	$signed = ( '-' === $ch || '+' === $ch ) && $pos + 1 < $len && false !== strpos( $digits, $sql[ $pos + 1 ] );
	if ( $signed || false !== strpos( $digits, $ch ) ) {
		preg_match( '/\G[-+]?[0-9]+(?:\.[0-9]+)?(?:[eE][-+]?[0-9]+)?/', $sql, $match, 0, $pos );
		$pos += strlen( $match[0] );
		return array(
			'type'  => 'number',
			'value' => $match[0],
			'at'    => $at,
		);
	}

	$word = strspn( $sql, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$', $pos );
	if ( $word > 0 ) {
		$pos += $word;
		return array(
			'type'  => 'word',
			'value' => substr( $sql, $at, $word ),
			'at'    => $at,
		);
	}

	++$pos;
	return array(
		'type'  => 'punct',
		'value' => $ch,
		'at'    => $at,
	);
}

/**
 * Reads the next token and checks it is the one the statement needs there.
 *
 * @param string      $sql   Whole file.
 * @param int         $pos   Byte offset, moved past the token.
 * @param string      $type  Token type wanted.
 * @param string|null $value Value wanted, ignoring case, or null for any.
 * @return array|WP_Error The token.
 */
function mtl_restore_expect( $sql, &$pos, $type, $value = null ) {
	$at  = $pos;
	$tok = mtl_restore_next_token( $sql, $pos );
	if ( is_wp_error( $tok ) ) {
		return $tok;
	}
	if ( null === $tok ) {
		return mtl_restore_error_at( $sql, $at, 'the file ends part-way through a statement, so it may have been cut short.' );
	}
	if ( $type !== $tok['type'] || ( null !== $value && 0 !== strcasecmp( $tok['value'], $value ) ) ) {
		return mtl_restore_error_at( $sql, $tok['at'], 'this is not something Export Data writes, so the file can\'t be restored.' );
	}
	return $tok;
}

/**
 * Steps past the next token if it is $word, for the optional parts of a
 * statement such as IF EXISTS.
 *
 * @param string $sql  Whole file.
 * @param int    $pos  Byte offset, moved past the word only when it matches.
 * @param string $word Word to look for, ignoring case.
 * @return bool Whether it was there.
 */
function mtl_restore_accept_word( $sql, &$pos, $word ) {
	$try = $pos;
	$tok = mtl_restore_next_token( $sql, $try );
	if ( is_array( $tok ) && 'word' === $tok['type'] && 0 === strcasecmp( $tok['value'], $word ) ) {
		$pos = $try;
		return true;
	}
	return false;
}

/**
 * Reads the separator after an item in a list: true for a comma (another
 * item follows), false for $close (the list is over).
 *
 * @param string $sql   Whole file.
 * @param int    $pos   Byte offset, moved past the separator.
 * @param string $close The character that ends the list.
 * @return bool|WP_Error
 */
function mtl_restore_list_continues( $sql, &$pos, $close ) {
	$at  = $pos;
	$tok = mtl_restore_next_token( $sql, $pos );
	if ( is_wp_error( $tok ) ) {
		return $tok;
	}
	if ( is_array( $tok ) && 'punct' === $tok['type'] && ( ',' === $tok['value'] || $close === $tok['value'] ) ) {
		return ',' === $tok['value'];
	}
	return mtl_restore_error_at( $sql, is_array( $tok ) ? $tok['at'] : $at, 'this is not something Export Data writes, so the file can\'t be restored.' );
}

/**
 * Reads a table name and maps it to one of the plugin's bare table names.
 *
 * @param string $sql        Whole file.
 * @param int    $pos        Byte offset, moved past the name.
 * @param string $src_prefix Table prefix the export was made with.
 * @return string|WP_Error Bare table name, see mtl_export_table_names().
 */
function mtl_restore_table_name( $sql, &$pos, $src_prefix ) {
	$at  = $pos;
	$tok = mtl_restore_next_token( $sql, $pos );
	if ( is_wp_error( $tok ) ) {
		return $tok;
	}
	if ( ! is_array( $tok ) || ! in_array( $tok['type'], array( 'name', 'word' ), true ) ) {
		return mtl_restore_error_at( $sql, is_array( $tok ) ? $tok['at'] : $at, 'a table name should be here.' );
	}

	// Compared without case, because MySQL on Windows and macOS stores table
	// names in lower case, and a backup may have come from one.
	$name   = strtolower( $tok['value'] );
	$prefix = strtolower( $src_prefix );
	$bare   = substr( $name, 0, strlen( $prefix ) ) === $prefix ? (string) substr( $name, strlen( $prefix ) ) : '';
	if ( ! in_array( $bare, mtl_export_table_names(), true ) ) {
		return mtl_restore_error_at( $sql, $tok['at'], '"' . $tok['value'] . '" is not one of My Tool Library\'s tables. Only a .sql dump from Export Data can be restored here.' );
	}
	return $bare;
}

/**
 * Steps over a DROP TABLE or CREATE TABLE statement after its first word.
 *
 * The restore keeps this site's own table structure (see
 * mtl_restore_from_sql()), so these are read only to confirm each names one
 * of the plugin's tables and to find the ; that ends it.
 *
 * @param string $sql        Whole file.
 * @param int    $pos        Byte offset, moved past the statement.
 * @param string $src_prefix Table prefix the export was made with.
 * @return true|WP_Error
 */
function mtl_restore_skip_table_statement( $sql, &$pos, $src_prefix ) {
	$tok = mtl_restore_expect( $sql, $pos, 'word', 'TABLE' );
	if ( is_wp_error( $tok ) ) {
		return $tok;
	}
	if ( mtl_restore_accept_word( $sql, $pos, 'IF' ) ) {
		mtl_restore_accept_word( $sql, $pos, 'NOT' );
		$tok = mtl_restore_expect( $sql, $pos, 'word', 'EXISTS' );
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
	}
	$bare = mtl_restore_table_name( $sql, $pos, $src_prefix );
	if ( is_wp_error( $bare ) ) {
		return $bare;
	}

	// A CREATE TABLE body is a parenthesised list, so the ; that ends the
	// statement is the first one outside every bracket. Quoted text inside it,
	// such as a column's DEFAULT, is already one token.
	$depth = 0;
	while ( true ) {
		$at  = $pos;
		$tok = mtl_restore_next_token( $sql, $pos );
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		if ( null === $tok ) {
			return mtl_restore_error_at( $sql, $at, 'the file ends part-way through a statement, so it may have been cut short.' );
		}
		if ( 'punct' !== $tok['type'] ) {
			continue;
		}
		if ( '(' === $tok['value'] ) {
			++$depth;
		} elseif ( ')' === $tok['value'] ) {
			--$depth;
		} elseif ( ';' === $tok['value'] && $depth <= 0 ) {
			return true;
		}
	}
}

/**
 * Reads an INSERT INTO ... (columns) VALUES (...), (...); statement after its
 * first word. Every value must be quoted text, a number or NULL: never an
 * expression, a function call or a subquery.
 *
 * @param string $sql        Whole file.
 * @param int    $pos        Byte offset, moved past the statement.
 * @param string $src_prefix Table prefix the export was made with.
 * @return array|WP_Error array( 'table' => bare name, 'columns' => string[],
 *                        'rows' => list of value lists, each value a string or null ).
 */
function mtl_restore_read_insert( $sql, &$pos, $src_prefix ) {
	$tok = mtl_restore_expect( $sql, $pos, 'word', 'INTO' );
	if ( is_wp_error( $tok ) ) {
		return $tok;
	}
	$bare = mtl_restore_table_name( $sql, $pos, $src_prefix );
	if ( is_wp_error( $bare ) ) {
		return $bare;
	}

	// The column list is required. It is what lets a backup taken before a
	// column was added load into a table that now has it.
	$tok = mtl_restore_expect( $sql, $pos, 'punct', '(' );
	if ( is_wp_error( $tok ) ) {
		return $tok;
	}
	$columns = array();
	while ( true ) {
		$at  = $pos;
		$tok = mtl_restore_next_token( $sql, $pos );
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		if ( ! is_array( $tok ) || ! in_array( $tok['type'], array( 'name', 'word' ), true ) ) {
			return mtl_restore_error_at( $sql, is_array( $tok ) ? $tok['at'] : $at, 'a column name should be here.' );
		}
		$columns[] = $tok['value'];
		$more      = mtl_restore_list_continues( $sql, $pos, ')' );
		if ( is_wp_error( $more ) ) {
			return $more;
		}
		if ( ! $more ) {
			break;
		}
	}

	$tok = mtl_restore_expect( $sql, $pos, 'word', 'VALUES' );
	if ( is_wp_error( $tok ) ) {
		return $tok;
	}
	$rows = array();
	while ( true ) {
		$opened = mtl_restore_expect( $sql, $pos, 'punct', '(' );
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}
		$row = array();
		while ( true ) {
			$at  = $pos;
			$tok = mtl_restore_next_token( $sql, $pos );
			if ( is_wp_error( $tok ) ) {
				return $tok;
			}
			if ( is_array( $tok ) && ( 'string' === $tok['type'] || 'number' === $tok['type'] ) ) {
				$row[] = $tok['value'];
			} elseif ( is_array( $tok ) && 'word' === $tok['type'] && 0 === strcasecmp( $tok['value'], 'NULL' ) ) {
				$row[] = null;
			} else {
				return mtl_restore_error_at( $sql, is_array( $tok ) ? $tok['at'] : $at, 'every value must be quoted text, a number or NULL.' );
			}
			$more = mtl_restore_list_continues( $sql, $pos, ')' );
			if ( is_wp_error( $more ) ) {
				return $more;
			}
			if ( ! $more ) {
				break;
			}
		}
		if ( count( $row ) !== count( $columns ) ) {
			return mtl_restore_error_at( $sql, $opened['at'], 'a row has ' . count( $row ) . ' values for ' . count( $columns ) . ' columns.' );
		}
		$rows[] = $row;

		$more = mtl_restore_list_continues( $sql, $pos, ';' );
		if ( is_wp_error( $more ) ) {
			return $more;
		}
		if ( ! $more ) {
			break;
		}
	}

	return array(
		'table'   => $bare,
		'columns' => $columns,
		'rows'    => $rows,
	);
}

/**
 * Walks an uploaded export statement by statement, handing each INSERT to
 * $on_insert.
 *
 * Accepts only what mtl_export_as_sql() writes: SET FOREIGN_KEY_CHECKS, DROP
 * TABLE, CREATE TABLE and INSERT, each naming one of the plugin's tables.
 * Anything else, such as an UPDATE or another plugin's table, stops the walk.
 * So does a file whose last statement is not the closing
 * SET FOREIGN_KEY_CHECKS=1, since an export always ends with one and a
 * download that was cut short does not.
 *
 * @param string   $sql        Whole file.
 * @param string   $src_prefix Table prefix the export was made with.
 * @param callable $on_insert  Called as ( $bare_table, $columns, $rows, $at )
 *                             for each INSERT. Returning a WP_Error stops the walk.
 * @return true|WP_Error
 */
function mtl_restore_walk( $sql, $src_prefix, $on_insert ) {
	$pos  = 0;
	$last = '';

	while ( true ) {
		$tok = mtl_restore_next_token( $sql, $pos );
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		if ( null === $tok ) {
			break;
		}
		if ( 'punct' === $tok['type'] && ';' === $tok['value'] ) {
			continue;
		}

		$verb = 'word' === $tok['type'] ? strtoupper( $tok['value'] ) : '';
		if ( 'SET' === $verb ) {
			$step = mtl_restore_expect( $sql, $pos, 'word', 'FOREIGN_KEY_CHECKS' );
			if ( ! is_wp_error( $step ) ) {
				$step = mtl_restore_expect( $sql, $pos, 'punct', '=' );
			}
			if ( ! is_wp_error( $step ) ) {
				$value = mtl_restore_expect( $sql, $pos, 'number' );
				$step  = is_wp_error( $value ) ? $value : mtl_restore_expect( $sql, $pos, 'punct', ';' );
			}
			if ( is_wp_error( $step ) ) {
				return $step;
			}
			$last = 'SET FOREIGN_KEY_CHECKS=' . (int) $value['value'];
		} elseif ( 'DROP' === $verb || 'CREATE' === $verb ) {
			$step = mtl_restore_skip_table_statement( $sql, $pos, $src_prefix );
			if ( is_wp_error( $step ) ) {
				return $step;
			}
			$last = $verb;
		} elseif ( 'INSERT' === $verb ) {
			$insert = mtl_restore_read_insert( $sql, $pos, $src_prefix );
			if ( is_wp_error( $insert ) ) {
				return $insert;
			}
			$step = call_user_func( $on_insert, $insert['table'], $insert['columns'], $insert['rows'], $tok['at'] );
			if ( is_wp_error( $step ) ) {
				return $step;
			}
			$last = $verb;
		} else {
			return mtl_restore_error_at( $sql, $tok['at'], 'this is not something Export Data writes, so the file can\'t be restored.' );
		}
	}

	if ( 'SET FOREIGN_KEY_CHECKS=1' !== $last ) {
		return new WP_Error( 'mtl_restore_incomplete', 'The file stops before the end of the export, so some of the data may be missing from it. Use a complete .sql dump.' );
	}
	return true;
}

/**
 * Sends the rows collected for one table as a single multi-row INSERT.
 *
 * @param array $batch array( 'head' => 'INSERT INTO ... VALUES ', 'table' =>
 *                     bare name, 'rows' => escaped "(...)" tuples, 'bytes' =>
 *                     their total length ). The rows are cleared once sent.
 * @return true|WP_Error
 */
function mtl_restore_flush( &$batch ) {
	global $wpdb;

	if ( ! $batch['rows'] ) {
		return true;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each value was escaped with esc_sql() as the batch was built, and the table and column names were checked against this site's own tables.
	if ( false === $wpdb->query( $batch['head'] . implode( ",\n", $batch['rows'] ) ) ) {
		return new WP_Error( 'mtl_restore_db', 'The database refused a row for the ' . $batch['table'] . ' table (' . $wpdb->last_error . '), so every change was undone.' );
	}
	$batch['rows']  = array();
	$batch['bytes'] = 0;
	return true;
}

/**
 * Replaces the rows of every My Tool Library table with those in a .sql
 * dump from Export Data.
 *
 * The rows go into this site's tables as they stand, and the CREATE TABLE
 * statements in the file are skipped. A backup taken before a plugin update
 * would otherwise bring back an older table shape, and
 * mtl_maybe_upgrade_schema() would never revisit it, because mtl_db_version
 * already says the tables are current. Columns added since the backup take
 * their defaults, just as they did when the upgrade added them.
 *
 * It runs in two passes. The first reads the whole file and changes
 * nothing, so a wrong, damaged or incomplete file is turned away with the
 * library untouched. The second empties the tables and loads the rows in one
 * transaction, so a row the database refuses rolls the whole restore back.
 * (The tables are InnoDB, which their foreign keys already depend on.)
 *
 * Emptying is DELETE, not TRUNCATE or DROP. Those would reset the
 * AUTO_INCREMENT counters, and the next new member could then be given the ID
 * of someone who joined after the backup and whose WordPress sign-in still
 * points at that number.
 *
 * @param string $sql Contents of the uploaded file.
 * @return array|WP_Error array( 'rows' => rows loaded per bare table name,
 *                        'created' => whether the tables had to be created first ).
 */
function mtl_restore_from_sql( $sql ) {
	global $wpdb;
	$prefix = $wpdb->prefix;
	$tables = mtl_export_table_names();

	// A byte-order mark, left by a text editor that re-saved the file.
	if ( "\xEF\xBB\xBF" === substr( $sql, 0, 3 ) ) {
		$sql = substr( $sql, 3 );
	}

	if ( 0 !== strpos( $sql, '-- My Tool Library data export' ) ) {
		return new WP_Error( 'mtl_restore_not_export', 'That file is not a .sql dump from Export Data. Only those can be restored here.' );
	}
	$header = substr( $sql, 0, 2048 );
	if ( ! preg_match( '/^-- Table names keep the WordPress "([A-Za-z0-9_]*)" prefix/m', $header, $match ) ) {
		return new WP_Error( 'mtl_restore_not_export', 'That file is missing part of the header Export Data writes, so it can\'t be restored.' );
	}
	$src_prefix = $match[1];

	// Exports made before version 9 have no version line, and are older
	// than this site by definition.
	if ( preg_match( '/^-- Database version: ([0-9]+)/m', $header, $match ) && (int) $match[1] > MTL_DB_VERSION ) {
		return new WP_Error( 'mtl_restore_newer', 'That backup was made by a newer version of My Tool Library. Update the plugin on this site, then restore.' );
	}

	// Exports made before this was fixed wrote every % in the data as wpdb's
	// placeholder escape: "{", 64 hex digits, "}", the same token throughout
	// one file (see mtl_export_as_sql()). Turn it back into %. Nothing a
	// library stores contains that token by chance.
	if ( preg_match( '/\{[0-9a-f]{64}\}/', $sql, $match ) ) {
		$sql = str_replace( $match[0], '%', $sql );
	}

	// A new site with no tables yet gets them built, since there is nothing
	// there to lose. Only some missing means something has gone wrong that a
	// person should look at, and Database Setup is the deliberate way to
	// rebuild them.
	$missing = array();
	foreach ( $tables as $bare ) {
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . $bare ) ) ) ) {
			$missing[] = $bare;
		}
	}
	$created = false;
	if ( count( $missing ) === count( $tables ) ) {
		$schema = mtl_run_schema_sql();
		if ( is_wp_error( $schema ) || $schema['failed'] ) {
			return new WP_Error( 'mtl_restore_tables', 'This site has no My Tool Library tables yet, and creating them failed. Run Database Setup below to see why.' );
		}
		$created = true;
	} elseif ( $missing ) {
		return new WP_Error( 'mtl_restore_tables', 'Some of the plugin\'s tables are missing (' . implode( ', ', $missing ) . '). Run Database Setup below to rebuild them, then restore.' );
	}

	// $prefix . $bare is a trusted prefix plus a hardcoded name from
	// mtl_export_table_names(), as in the export.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$columns = array();
	foreach ( $tables as $bare ) {
		$columns[ $bare ] = array_flip( $wpdb->get_col( "SHOW COLUMNS FROM `{$prefix}{$bare}`" ) );
	}

	// Pass 1: read everything, change nothing.
	$counts  = array_fill_keys( $tables, 0 );
	$checked = mtl_restore_walk(
		$sql,
		$src_prefix,
		function ( $bare, $names, $rows, $at ) use ( $sql, $columns, &$counts ) {
			foreach ( $names as $name ) {
				if ( ! isset( $columns[ $bare ][ $name ] ) ) {
					return mtl_restore_error_at( $sql, $at, 'the ' . $bare . ' table in the backup has a "' . $name . '" column that this site\'s doesn\'t. Update the plugin on this site, then restore.' );
				}
			}
			if ( count( array_unique( $names ) ) !== count( $names ) ) {
				return mtl_restore_error_at( $sql, $at, 'a column is listed twice.' );
			}
			$counts[ $bare ] += count( $rows );
			return true;
		}
	);
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}

	// Pass 2: empty the tables and load the rows, all or nothing. A large
	// library can take longer than the default time limit, and a request cut
	// off part-way would leave the transaction to roll back.
	if ( function_exists( 'set_time_limit' ) ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit -- see above.
		set_time_limit( 0 );
	}
	// A refused row comes back in the restore's own notice. Left to wpdb, with
	// WP_DEBUG on it would also print the failed query, up to half a megabyte
	// of members' details, into the page and the error log.
	$suppressed = $wpdb->suppress_errors( true );
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
	$wpdb->query( 'START TRANSACTION' );

	$result = true;
	foreach ( array_reverse( $tables ) as $bare ) {
		if ( false === $wpdb->query( "DELETE FROM `{$prefix}{$bare}`" ) ) {
			$result = new WP_Error( 'mtl_restore_db', 'Emptying the ' . $bare . ' table failed (' . $wpdb->last_error . '), so every change was undone.' );
			break;
		}
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( true === $result ) {
		$batch  = array(
			'head'  => '',
			'table' => '',
			'rows'  => array(),
			'bytes' => 0,
		);
		$result = mtl_restore_walk(
			$sql,
			$src_prefix,
			function ( $bare, $names, $rows ) use ( $prefix, $columns, &$batch ) {
				// Checked again rather than trusted from pass 1, because these
				// names go into the query.
				foreach ( $names as $name ) {
					if ( ! isset( $columns[ $bare ][ $name ] ) ) {
						return new WP_Error( 'mtl_restore_column', 'The ' . $bare . ' table in the backup has a column this site\'s doesn\'t.' );
					}
				}
				$head = "INSERT INTO `{$prefix}{$bare}` (`" . implode( '`, `', $names ) . '`) VALUES ';
				if ( $head !== $batch['head'] ) {
					$sent = mtl_restore_flush( $batch );
					if ( is_wp_error( $sent ) ) {
						return $sent;
					}
					$batch['head']  = $head;
					$batch['table'] = $bare;
				}
				foreach ( $rows as $row ) {
					$values = array();
					foreach ( $row as $value ) {
						$values[] = null === $value ? 'NULL' : "'" . esc_sql( $value ) . "'";
					}
					$tuple           = '(' . implode( ', ', $values ) . ')';
					$batch['rows'][] = $tuple;
					$batch['bytes'] += strlen( $tuple );
					// Comfortably under the 1 MB max_allowed_packet of the
					// oldest MySQL and MariaDB servers still in use.
					if ( $batch['bytes'] >= 512 * 1024 ) {
						$sent = mtl_restore_flush( $batch );
						if ( is_wp_error( $sent ) ) {
							return $sent;
						}
					}
				}
				return true;
			}
		);
		if ( true === $result ) {
			$result = mtl_restore_flush( $batch );
		}
	}

	if ( true === $result && false === $wpdb->query( 'COMMIT' ) ) {
		$result = new WP_Error( 'mtl_restore_db', 'The database could not save the restore (' . $wpdb->last_error . '), so every change was undone.' );
	}
	if ( true !== $result ) {
		$wpdb->query( 'ROLLBACK' );
	}
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
	$wpdb->suppress_errors( $suppressed );

	if ( true !== $result ) {
		return $result;
	}

	mtl_agreements_flush_cache();
	return array(
		'rows'    => $counts,
		'created' => $created,
	);
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
	//
	// mtl_is_administrator(), not mtl_can_manage_settings(): the switch that
	// turns the view back on is at the top of this page.
	if ( ! mtl_is_administrator() ) {
		return;
	}

	// Applied before the page renders, so everything below can simply ask
	// mtl_can_manage_settings() and get an answer that already reflects it.
	$mtl_admin_view_notice = '';
	if ( isset( $_POST['mtl_save_admin_view'] ) ) {
		if ( isset( $_POST['mtl_admin_view_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_admin_view_nonce'] ) ), 'mtl_admin_view_action' ) ) {
			if ( isset( $_POST['mtl_admin_view_on'] ) ) {
				delete_user_meta( get_current_user_id(), 'mtl_admin_view_off' );
			} else {
				update_user_meta( get_current_user_id(), 'mtl_admin_view_off', '0' );
			}
		} else {
			$mtl_admin_view_notice = '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	$tbl_categories    = $wpdb->prefix . 'tool_categories';
	$tbl_subcategories = $wpdb->prefix . 'tool_subcategories';
	$tbl_tags          = $wpdb->prefix . 'tool_tags';
	$tbl_trainings     = $wpdb->prefix . 'member_trainings';

	// The Member Agreements file picker uses the Media Library modal. Enqueued
	// here rather than on admin_enqueue_scripts because this callback runs
	// before the footer, where the media templates and scripts print.
	wp_enqueue_media();

	echo '<div class="wrap mtl-admin-wrapper">';

	$mtl_admin_view = mtl_admin_view_enabled();
	?>
	<style>
		/* Its own block: it has to survive the early return below, which never
			reaches the page's main stylesheet. */
		.mtl-view-toggle {
			display: flex;
			align-items: center;
			gap: 10px;
			cursor: pointer;
			user-select: none;
			margin: 0;
		}

		.mtl-view-toggle input[type="checkbox"] {
			position: absolute;
			opacity: 0;
			width: 0;
			height: 0;
		}

		.mtl-view-slider {
			position: relative;
			display: inline-block;
			width: 46px;
			height: 24px;
			background: #ccc;
			border-radius: 999px;
			flex-shrink: 0;
			transition: background 0.2s ease;
		}

		.mtl-view-slider::before {
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

		.mtl-view-toggle input[type="checkbox"]:checked+.mtl-view-slider { background: #2271b1; }
		.mtl-view-toggle input[type="checkbox"]:checked+.mtl-view-slider::before { transform: translateX(22px); }
		.mtl-view-toggle input[type="checkbox"]:focus-visible+.mtl-view-slider {
			outline: 2px solid #096491;
			outline-offset: 2px;
		}

		/* Shares the heading's line rather than taking one of its own. */
		.mtl-view-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 16px;
			flex-wrap: wrap;
		}

		.mtl-view-header h2 { margin: 0; }
		.mtl-view-header form { margin: 0; }
	</style>

	<div class="mtl-view-header">
		<h2>My Tool Library Setup &amp; Settings</h2>
		<form method="post" action="">
			<?php wp_nonce_field( 'mtl_admin_view_action', 'mtl_admin_view_nonce' ); ?>
			<label class="mtl-view-toggle">
				<input type="checkbox" name="mtl_admin_view_on" value="1" onchange="this.form.submit();" <?php checked( $mtl_admin_view ); ?>>
				<span class="mtl-view-slider"></span>
				<span><strong>Admin view</strong> <?php echo $mtl_admin_view ? 'on' : 'off'; ?></span>
			</label>
			<noscript><p style="margin: 8px 0 0 0;"><button type="submit" name="mtl_save_admin_view" value="1" class="button">Save</button></p></noscript>
			<input type="hidden" name="mtl_save_admin_view" value="1">
		</form>
	</div>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built, escaped HTML from the admin-view handler above.
	echo $mtl_admin_view_notice;

	// Everything past here is the admin view. The switch above is the whole
	// page while it is off, since it is the only way back.
	if ( ! $mtl_admin_view ) {
		echo '</div>';
		return;
	}

	// ==========================================
	// 1. HANDLE SETTINGS FORM SUBMISSIONS
	// ==========================================
	// Organization, Appearance, Member Page Messages and Reservations & Loans
	// each have their own form, and the Save button's value names the one
	// sent. Each branch saves only its own section's options. Every field
	// falls back to blank when it is missing from the post, so a branch that
	// also saved another section's options would wipe them.
	if ( isset( $_POST['mtl_save_settings'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_settings_nonce'] ) ), 'mtl_save_settings_action' ) ) {
			$mtl_settings_section = sanitize_key( wp_unslash( $_POST['mtl_save_settings'] ) );
			$mtl_settings_saved   = '';

			if ( 'org' === $mtl_settings_section ) {
				update_option( 'mtl_org_name', isset( $_POST['mtl_org_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_org_name'] ) ) : '' );
				update_option( 'mtl_contact_email', isset( $_POST['mtl_contact_email'] ) ? sanitize_email( wp_unslash( $_POST['mtl_contact_email'] ) ) : '' );
				update_option( 'mtl_currency_symbol', isset( $_POST['mtl_currency_symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_currency_symbol'] ) ) : '' );
				update_option( 'mtl_home_url', isset( $_POST['mtl_home_url'] ) ? sanitize_url( wp_unslash( $_POST['mtl_home_url'] ) ) : '' );
				$mtl_settings_saved = 'Organization details';
			} elseif ( 'appearance' === $mtl_settings_section ) {
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

				$mtl_settings_saved = 'Appearance settings';
			} elseif ( 'messages' === $mtl_settings_section ) {
				// A saved blank value is meaningful, not "unset": get_option()'s
				// default only applies before the option row exists, so an empty
				// save sticks and intentionally hides the directions on the public pages.
				update_option( 'mtl_pickup_directions', isset( $_POST['mtl_pickup_directions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_pickup_directions'] ) ) : '' );
				update_option( 'mtl_verification_directions', isset( $_POST['mtl_verification_directions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_verification_directions'] ) ) : '' );
				update_option( 'mtl_giving_text', isset( $_POST['mtl_giving_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_giving_text'] ) ) : '' );

				// The giving link is stored normalized so the member-facing button
				// can never point somewhere unexpected. mtl_normalize_web_url()
				// drops anything that is not http/https, so a pasted "javascript:" or
				// "data:" URL saves as blank rather than becoming a button.
				update_option(
					'mtl_giving_url',
					isset( $_POST['mtl_giving_url'] )
						? mtl_normalize_web_url( sanitize_text_field( wp_unslash( $_POST['mtl_giving_url'] ) ) )
						: ''
				);
				update_option(
					'mtl_giving_wishlist_url',
					isset( $_POST['mtl_giving_wishlist_url'] )
						? mtl_normalize_web_url( sanitize_text_field( wp_unslash( $_POST['mtl_giving_wishlist_url'] ) ) )
						: ''
				);

				update_option( 'mtl_tool_request_text', isset( $_POST['mtl_tool_request_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_tool_request_text'] ) ) : '' );
				update_option(
					'mtl_tool_request_url',
					isset( $_POST['mtl_tool_request_url'] )
						? mtl_normalize_web_url( sanitize_text_field( wp_unslash( $_POST['mtl_tool_request_url'] ) ) )
						: ''
				);

				$mtl_settings_saved = 'Member page messages';
			} elseif ( 'loans' === $mtl_settings_section ) {
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

				// An unticked checkbox posts nothing at all, so absence is the "off"
				// value here rather than a missing field. Stored as '1'/'' to match
				// how mtl_tool_location_visible_to_members() reads it.
				update_option( 'mtl_show_tool_location', isset( $_POST['mtl_show_tool_location'] ) ? '1' : '' );

				$mtl_settings_saved = 'Reservation and loan settings';
			}

			if ( '' !== $mtl_settings_saved ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> ' . esc_html( $mtl_settings_saved ) . ' have been saved.</p></div>';
			}
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
	// 3A2. HANDLE "ADD SUB-CATEGORY" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_add_subcategory'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_add_subcategory_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_add_subcategory_nonce'] ) ), 'mtl_add_subcategory_action' ) ) {
			$new_subcategory_name   = isset( $_POST['new_subcategory_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_subcategory_name'] ) ) : '';
			$new_subcategory_parent = isset( $_POST['new_subcategory_category'] ) ? (int) $_POST['new_subcategory_category'] : 0;

			if ( '' === $new_subcategory_name ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please enter a sub-category name.</p></div>';
			} elseif ( strlen( $new_subcategory_name ) > 50 ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Sub-category names must be 50 characters or fewer.</p></div>';
			} elseif ( $new_subcategory_parent <= 0 ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please choose the category this sub-category belongs to.</p></div>';
			} else {
				// Names are unique per category, not globally, so this looks for
				// the pair. Two categories may each have their own "Drills".
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
						"SELECT subcategory_id FROM {$tbl_subcategories} WHERE category_id = %d AND subcategory_name = %s LIMIT 1",
						$new_subcategory_parent,
						$new_subcategory_name
					)
				);

				if ( $existing ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That category already has that sub-category.</p></div>';
				} else {
					$inserted = $wpdb->insert(
						$tbl_subcategories,
						array(
							'category_id'      => $new_subcategory_parent,
							'subcategory_name' => $new_subcategory_name,
						),
						array( '%d', '%s' )
					);

					if ( $inserted ) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Sub-category &ldquo;' . esc_html( $new_subcategory_name ) . '&rdquo; has been added.</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Failed to add sub-category. The category may no longer exist.</p></div>';
					}
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 3B2. HANDLE "DELETE SUB-CATEGORIES" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_delete_subcategories'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_delete_subcategories_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_subcategories_nonce'] ) ), 'mtl_delete_subcategories_action' ) ) {
			$delete_subcategory_ids = isset( $_POST['delete_subcategory_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['delete_subcategory_ids'] ) ) : array();
			$delete_subcategory_ids = array_filter(
				$delete_subcategory_ids,
				function ( $id ) {
					return $id > 0;
				}
			);

			if ( empty( $delete_subcategory_ids ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> No sub-categories were selected.</p></div>';
			} else {
				// Cascades to tool_subcategory_mappings (see schema.sql), so any
				// tool using it loses that sub-category and keeps its category.
				$deleted_count = 0;
				foreach ( $delete_subcategory_ids as $id ) {
					if ( $wpdb->delete( $tbl_subcategories, array( 'subcategory_id' => $id ), array( '%d' ) ) ) {
						++$deleted_count;
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p><strong>Removed.</strong> ' . intval( $deleted_count ) . ' sub-categor' . ( 1 === $deleted_count ? 'y' : 'ies' ) . ' deleted.</p></div>';
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
	// Its own form rather than riding along with the agreement emails or any
	// other Save: paper -> full needs a confirmation, and hanging that off a
	// button that also saves something else would fire it on unrelated saves.
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

			if ( mtl_db_reset_confirmation_phrase() !== $mtl_typed_phrase ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Nothing was deleted.</strong> A database reset only runs when the phrase &ldquo;<code>' . esc_html( mtl_db_reset_confirmation_phrase() ) . '</code>&rdquo; is typed exactly as shown. Your data is unchanged.</p></div>';
			} else {
				$schema = mtl_run_schema_sql();
				if ( is_wp_error( $schema ) ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Could not find <code>schema.sql</code>.</p></div>';
				} else {
					foreach ( $schema['failed'] as $failure ) {
						echo '<div style="background: #ffebe8; border: 1px solid #cc0000; padding: 10px; margin: 5px 0;">';
						echo '<strong>Failed Query:</strong> ' . esc_html( $failure[0] ) . '<br>';
						echo '<strong>DB Error:</strong> ' . esc_html( $failure[1] );
						echo '</div>';
					}

					if ( ! $schema['failed'] ) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>Database Setup Complete:</strong> Successfully reset tables and executed ' . intval( $schema['ok'] ) . ' queries.</p></div>';
					} else {
						echo '<div class="notice notice-warning is-dismissible"><p><strong>Database Setup Finished with Errors:</strong> ' . intval( $schema['ok'] ) . ' queries succeeded, but ' . count( $schema['failed'] ) . ' encountered errors.</p></div>';
					}
				}
			}
		}
	}

	// ==========================================
	// 4B. HANDLE RESTORE FROM BACKUP
	// ==========================================
	// A file bigger than post_max_size never arrives: PHP discards the whole
	// request body, so $_POST is empty and no handler on this page sees a
	// submission. The restore upload is the only form here that can send that
	// much, so without this check its button would seem to do nothing.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only checks whether anything arrived, to explain a discarded upload; nothing is acted on.
	if ( isset( $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_LENGTH'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && empty( $_POST ) && empty( $_FILES ) && (int) $_SERVER['CONTENT_LENGTH'] > 0 ) {
		echo '<div class="notice notice-error is-dismissible"><p><strong>Nothing was restored.</strong> The file is larger than this server accepts (' . esc_html( size_format( wp_max_upload_size() ) ) . '). Your data is unchanged.</p></div>';
	}

	if ( isset( $_POST['mtl_restore_sql'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_restore_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_restore_nonce'] ) ), 'mtl_restore_action' ) ) {
			// Typed-phrase confirmation, checked here as well as in the
			// dialog for the same reason as the database reset's above.
			$mtl_typed_phrase = isset( $_POST['mtl_restore_confirmation'] )
				? trim( sanitize_text_field( wp_unslash( $_POST['mtl_restore_confirmation'] ) ) )
				: '';

			// $_FILES as in the CSV importers: 'error' is an integer PHP sets
			// itself, 'name' is sanitized, and 'tmp_name' is taken RAW, since
			// wp_unslash() strips the separators from a Windows temp path.
			// is_uploaded_file() below is what proves it.
			$restore_error = isset( $_FILES['mtl_restore_file']['error'] ) ? (int) $_FILES['mtl_restore_file']['error'] : UPLOAD_ERR_NO_FILE;
			$restore_name  = isset( $_FILES['mtl_restore_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['mtl_restore_file']['name'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a filesystem path, validated by is_uploaded_file() below; sanitizing corrupts it on Windows.
			$restore_tmp = isset( $_FILES['mtl_restore_file']['tmp_name'] ) ? $_FILES['mtl_restore_file']['tmp_name'] : '';

			// An automatic backup picked from the list instead of an upload.
			$restore_backup = isset( $_POST['mtl_restore_backup'] ) ? absint( $_POST['mtl_restore_backup'] ) : 0;
			// Only needed for an encrypted backup this site can't open with its
			// own key. mtl_backup_decode() picks the hex digits out of it.
			$restore_key = isset( $_POST['mtl_restore_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_restore_key'] ) ) : '';

			$restore_refused = '';
			$restore_data    = '';
			if ( mtl_db_restore_confirmation_phrase() !== $mtl_typed_phrase ) {
				$restore_refused = 'A restore only runs when the phrase "' . mtl_db_restore_confirmation_phrase() . '" is typed exactly as shown.';
			} elseif ( $restore_backup > 0 ) {
				// Already on the server, so no upload and no upload limit.
				$backup_path = mtl_is_backup( $restore_backup ) ? (string) get_attached_file( $restore_backup ) : '';
				if ( '' === $backup_path || ! is_readable( $backup_path ) ) {
					$restore_refused = 'That backup is no longer in the Media Library.';
				} else {
					$restore_name = basename( $backup_path );
					$restore_data = (string) file_get_contents( $backup_path );
				}
			} elseif ( UPLOAD_ERR_NO_FILE === $restore_error ) {
				$restore_refused = 'Choose a file to upload, or one of the automatic backups.';
			} elseif ( UPLOAD_ERR_INI_SIZE === $restore_error || UPLOAD_ERR_FORM_SIZE === $restore_error ) {
				$restore_refused = 'The file is larger than this server accepts (' . size_format( wp_max_upload_size() ) . ').';
			} elseif ( UPLOAD_ERR_OK !== $restore_error || ! is_uploaded_file( $restore_tmp ) ) {
				$restore_refused = 'The file failed to upload. Please try again.';
			} elseif ( ! in_array( strtolower( pathinfo( $restore_name, PATHINFO_EXTENSION ) ), array( 'sql', 'enc' ), true ) ) {
				$restore_refused = 'Choose a .sql dump from Export Data, or an automatic backup (.sql.enc). The .zip of CSVs can\'t be restored.';
			} else {
				$restore_data = (string) file_get_contents( $restore_tmp );
			}

			if ( '' === $restore_refused ) {
				// Decrypts an automatic backup; a plain .sql dump passes through.
				$restore_sql = mtl_backup_decode( $restore_data, $restore_key );
				unset( $restore_data );
				$restored = is_wp_error( $restore_sql ) ? $restore_sql : mtl_restore_from_sql( $restore_sql );
				if ( is_wp_error( $restored ) ) {
					$restore_refused = $restored->get_error_message();
				}
			}

			if ( '' !== $restore_refused ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Nothing was restored.</strong> ' . esc_html( $restore_refused ) . ' Your data is unchanged.</p></div>';
			} else {
				$rows   = $restored['rows'];
				$plural = function ( $count, $one, $many ) {
					return number_format_i18n( $count ) . ' ' . ( 1 === $count ? $one : $many );
				};
				echo '<div class="notice notice-success is-dismissible"><p><strong>Restore complete.</strong> Loaded '
					. esc_html( $plural( array_sum( $rows ), 'record', 'records' ) ) . ' from <code>' . esc_html( $restore_name ) . '</code>, including '
					. esc_html( $plural( $rows['members'], 'member', 'members' ) ) . ', '
					. esc_html( $plural( $rows['tool_inventory'], 'tool', 'tools' ) ) . ', '
					. esc_html( $plural( $rows['loans'], 'loan', 'loans' ) ) . ' and '
					. esc_html( $plural( $rows['tool_reservations'], 'reservation', 'reservations' ) ) . '.'
					. ( $restored['created'] ? ' This site had no My Tool Library tables yet, so they were created first.' : '' )
					. ' Anything added after the backup was made is no longer on file.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 4C. HANDLE AUTOMATIC BACKUP SETTINGS
	// ==========================================
	// Own form and nonce, so saving these can never touch the other settings
	// on this page or the other way round.
	if ( isset( $_POST['mtl_save_backup_settings'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_backup_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_backup_settings_nonce'] ) ), 'mtl_backup_settings_action' ) ) {
			// Out-of-range numbers fall back to the defaults rather than being
			// clamped to a value nobody chose, as the hold period does.
			$posted_days = isset( $_POST['mtl_backup_interval_days'] ) ? (int) $_POST['mtl_backup_interval_days'] : 7;
			$posted_keep = isset( $_POST['mtl_backup_keep'] ) ? (int) $_POST['mtl_backup_keep'] : 10;
			update_option( 'mtl_backup_enabled', isset( $_POST['mtl_backup_enabled'] ) ? '1' : '' );
			update_option( 'mtl_backup_interval_days', ( $posted_days >= 1 && $posted_days <= 90 ) ? $posted_days : 7 );
			update_option( 'mtl_backup_keep', ( $posted_keep >= 1 && $posted_keep <= 100 ) ? $posted_keep : 10 );

			$backup_settings = mtl_backup_settings();
			if ( $backup_settings['enabled'] ) {
				// Made now rather than at the first backup, so the key is on
				// screen to be saved before anything depends on it.
				mtl_backup_key();
			}
			mtl_backup_reschedule();

			$next_backup = wp_next_scheduled( 'mtl_auto_backup' );
			if ( $backup_settings['enabled'] && $next_backup ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>Automatic backups are on.</strong> Every ' . intval( $backup_settings['days'] ) . ' day' . ( 1 === $backup_settings['days'] ? '' : 's' ) . ', keeping the latest ' . intval( $backup_settings['keep'] ) . '. The next one is due around ' . esc_html( wp_date( 'M j, Y g:i a', $next_backup ) ) . '. Save the backup key below if you haven&rsquo;t already.</p></div>';
			} elseif ( $backup_settings['enabled'] ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The settings were saved, but WordPress would not schedule the backup. Try saving again.</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p><strong>Automatic backups are off.</strong> Backups already in the Media Library stay there.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	if ( isset( $_POST['mtl_backup_now'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_backup_now_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_backup_now_nonce'] ) ), 'mtl_backup_now_action' ) ) {
			$made = mtl_backup_run( 'manual' );
			if ( is_wp_error( $made ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>No backup was made.</strong> ' . esc_html( $made->get_error_message() ) . '</p></div>';
			} else {
				$made_path = (string) get_attached_file( $made );
				echo '<div class="notice notice-success is-dismissible"><p><strong>Backup saved</strong> to the Media Library as <code>' . esc_html( basename( $made_path ) ) . '</code> (' . esc_html( size_format( (int) filesize( $made_path ) ) ) . ').</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
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
	$show_tool_location      = mtl_tool_location_visible_to_members();
	$pickup_directions       = get_option(
		'mtl_pickup_directions',
		'Placing a reservation holds your spot in line and speeds up the process of checking out tools. If no one is waiting in line to borrow a tool, no reservation is required. Come by our store and speak with a representative to take tools home.'
	);
	$verification_directions = get_option(
		'mtl_verification_directions',
		'A government-issued ID and proof of address are required to become a verified member and to check out tools. Stop by our office to verify membership.'
	);

	// Default lives in mtl_default_giving_text() so this box and the
	// member-facing fallback can never show different words.
	$giving_text = get_option( 'mtl_giving_text', mtl_default_giving_text() );

	// Shown re-normalized rather than raw, so the field displays exactly what
	// the member-facing button would use. Comparing the two makes a rejected
	// link visible instead of it silently appearing blank on the next load.
	$giving_url_raw = trim( (string) get_option( 'mtl_giving_url', '' ) );
	$giving_url     = mtl_normalize_web_url( $giving_url_raw );

	$wishlist_url_raw = trim( (string) get_option( 'mtl_giving_wishlist_url', '' ) );
	$wishlist_url     = mtl_normalize_web_url( $wishlist_url_raw );

	// Same treatment as the giving message and link above.
	$tool_request_text    = get_option( 'mtl_tool_request_text', mtl_default_tool_request_text() );
	$tool_request_url_raw = trim( (string) get_option( 'mtl_tool_request_url', '' ) );
	$tool_request_url     = mtl_normalize_web_url( $tool_request_url_raw );

	// Shown as chips next to the "add new" mini-forms below.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	$categories = $wpdb->get_results( "SELECT category_id, category_name FROM {$tbl_categories} ORDER BY category_name ASC" );
	// Joined to the parent so the panel can label each one "Category > Sub-category";
	// the names are only unique inside their category.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, no request-derived data.
	$subcategories = $wpdb->get_results(
		"SELECT s.subcategory_id, s.subcategory_name, s.category_id, c.category_name
		 FROM {$tbl_subcategories} s
		 INNER JOIN {$tbl_categories} c ON c.category_id = s.category_id
		 ORDER BY c.category_name ASC, s.subcategory_name ASC"
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		/* One compact box for the Public Page Link, above the sections. */
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

		.mtl-public-link-input {
			flex: 1 1 auto;
			min-width: 0;
			padding: 4px 8px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
			font-size: 0.8em;
			font-family: Consolas, Menlo, monospace;
			background: #f6f7f7;
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

		/* SETUP PAGE SECTIONS
			Three groups down the page: the lists, which grow with the library
			and so start open; the settings, usually set once and so closed;
			and the data operations, last and out of the way, with the two
			that replace or erase everything marked in red.

			Each section is a <details> (see mtl_setup_section_start()), so it
			opens and closes without JavaScript. Which ones start open is
			decided in PHP, so the section a form was just sent from comes
			back open with its result in view. */
		.mtl-setup-group {
			margin-top: 30px;
		}

		/* A small label over its sections rather than another big heading,
			so the section titles stay the thing to scan for. Outranks the
			shared h2 rule in my-tool-library.php on specificity. */
		.mtl-admin-wrapper .mtl-setup-group-title {
			margin: 0 0 8px 0;
			font-size: 0.85em;
			letter-spacing: 0.05em;
			text-transform: uppercase;
		}

		.mtl-setup-section {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
			margin-bottom: 10px;
		}

		/* Outranks the shared ".mtl-admin-wrapper summary" rule, which styles
			every summary as a heading. Here the <h3> inside is the heading and
			the rest of the row is ordinary text. */
		.mtl-admin-wrapper .mtl-setup-section > summary {
			display: grid;
			grid-template-columns: auto 1fr auto;
			align-items: center;
			column-gap: 12px;
			padding: 14px 20px;
			cursor: pointer;
			list-style: none;
			font-family: inherit;
			font-size: inherit;
			font-weight: inherit;
			text-transform: none;
		}

		.mtl-setup-section > summary::-webkit-details-marker {
			display: none;
		}

		/* A plain disclosure triangle in the heading colour, pointing right
			when closed and down when open. Not a chevron, which at this size
			reads as a tick. */
		.mtl-setup-section > summary::before {
			content: "";
			grid-column: 1;
			grid-row: 1;
			width: 0;
			height: 0;
			border-top: 6px solid transparent;
			border-bottom: 6px solid transparent;
			border-left: 8px solid currentColor;
			transition: transform 0.15s ease;
		}

		.mtl-setup-section[open] > summary::before {
			transform: rotate(90deg);
		}

		/* Body-text spacing, not the heading line-height the shared summary
			rule gives the row it sits in. */
		.mtl-setup-section-desc {
			line-height: 1.4;
		}

		.mtl-setup-section[open] > summary {
			border-bottom: 1px solid #eee;
		}

		.mtl-setup-section > summary:focus-visible {
			outline: 2px solid #2271b1;
			outline-offset: -2px;
		}

		.mtl-setup-section > summary h3 {
			grid-column: 2;
			grid-row: 1;
			margin: 0;
		}

		.mtl-setup-section-desc {
			grid-column: 2;
			grid-row: 2;
			margin-top: 2px;
			font-size: 0.9em;
			color: #646970;
		}

		.mtl-setup-badge {
			grid-column: 3;
			grid-row: 1 / span 2;
			padding: 2px 10px;
			border-radius: 999px;
			background: #f0f0f1;
			color: #50575e;
			font-size: 0.85em;
			white-space: nowrap;
		}

		.mtl-setup-badge-warn {
			background: #fcf9e8;
			color: #8a6d00;
		}

		.mtl-setup-badge-error {
			background: #fcf0f1;
			color: #b32d2e;
		}

		.mtl-setup-section-body {
			padding: 20px;
		}

		.mtl-setup-section-body > :first-child {
			margin-top: 0;
		}

		/* A section's closing Save button sits on its bottom edge rather than
			1.5em above it. */
		.mtl-setup-section-body > .submit:last-child,
		.mtl-setup-section-body > form:last-child > .submit:last-child {
			margin-bottom: 0;
			padding-bottom: 0;
		}

		/* Replaces or erases data: red down the edge and in the title,
			whatever the Header Color setting is. */
		.mtl-setup-section-danger {
			border-left: 4px solid #d63638;
		}

		.mtl-admin-wrapper .mtl-setup-section-danger > summary,
		.mtl-admin-wrapper .mtl-setup-section-danger > summary h3 {
			color: #d63638 !important;
		}

		/* Too narrow for the badge beside the title: it drops below the
			description instead of squeezing it. */
		@media screen and (max-width: 600px) {
			.mtl-admin-wrapper .mtl-setup-section > summary {
				grid-template-columns: auto 1fr;
			}

			.mtl-setup-badge {
				grid-column: 2;
				grid-row: 3;
				justify-self: start;
				margin-top: 6px;
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

	// A section opens when the request came from it, and some also open when
	// they need attention. The lists and Export Data are always open, because
	// they are used routinely; everything else is set once or replaces data.
	$mtl_requested_section = mtl_setup_requested_section();

	// No members table means Database Setup has never been run, and nothing
	// else on this page or any other works until it has.
	$mtl_tables_ready = mtl_members_table_exists();
	?>
	<!-- Always in view, since copying it is something an admin comes back
		for. The editable Home Page Link lives under Organization. -->
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
	</div>

	<?php
	// First, because until it is on, customers can't use anything the rest of
	// this page sets up. See admin/go-live.php.
	mtl_render_go_live_section( $mtl_requested_section, $mtl_tables_ready );
	?>

	<div class="mtl-setup-group">
		<h2 class="mtl-setup-group-title">Lists</h2>

		<?php
		mtl_setup_section_start(
			'catalog',
			array(
				'title' => 'Categories & Tags',
				'desc'  => 'Categories, sub-categories and tags to choose from when adding or editing a tool.',
				'open'  => true,
			)
		);
		?>
			<h4 style="margin: 0;">Categories</h4>
			<?php
			if ( $categories ) :
				$mtl_delete_categories_confirm = array(
					'title'   => 'Delete Categories',
					'message' => 'Delete the selected categories?',
					'details' => array(
						'Any tools using them will simply lose that category.',
						'This cannot be undone.',
					),
					'confirm' => 'Delete Categories',
					'danger'  => true,
				);
				?>
				<form method="post" action=""<?php echo mtl_confirm_attr( $mtl_delete_categories_confirm ); ?>>
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

			<h4 style="margin-bottom: 0;">Sub-categories</h4>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 8px 0;">Each belongs to one category, and deleting a category deletes its sub-categories. Two categories can each have their own &ldquo;Drills&rdquo;.</p>
			<?php
			if ( $subcategories ) :
				$mtl_delete_subcategories_confirm = array(
					'title'   => 'Delete Sub-categories',
					'message' => 'Delete the selected sub-categories?',
					'details' => array(
						'Any tools using them will lose that sub-category and keep their category.',
						'This cannot be undone.',
					),
					'confirm' => 'Delete Sub-categories',
					'danger'  => true,
				);
				?>
				<form method="post" action=""<?php echo mtl_confirm_attr( $mtl_delete_subcategories_confirm ); ?>>
					<?php wp_nonce_field( 'mtl_delete_subcategories_action', 'mtl_delete_subcategories_nonce' ); ?>
					<div class="mtl-chip-row">
						<?php foreach ( $subcategories as $sub ) : ?>
							<label class="mtl-chip-checkbox">
								<input type="checkbox" name="delete_subcategory_ids[]" value="<?php echo esc_attr( $sub->subcategory_id ); ?>">
								<?php echo esc_html( $sub->category_name . ' > ' . $sub->subcategory_name ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="submit" style="margin: 8px 0 0 0;">
						<button type="submit" name="mtl_delete_subcategories" class="button mtl-btn-danger">Delete Selected</button>
					</p>
				</form>
			<?php else : ?>
				<div class="mtl-chip-row">
					<span style="color: #999; font-size: 0.85em;">None yet.</span>
				</div>
			<?php endif; ?>
			<?php if ( $categories ) : ?>
				<form method="post" action="" class="mtl-add-lookup-form">
					<?php wp_nonce_field( 'mtl_add_subcategory_action', 'mtl_add_subcategory_nonce' ); ?>
					<select name="new_subcategory_category" required>
						<option value="">Category&hellip;</option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat->category_id ); ?>"><?php echo esc_html( $cat->category_name ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="new_subcategory_name" maxlength="50" placeholder="New sub-category name" class="regular-text" required>
					<button type="submit" name="mtl_add_subcategory" class="button button-primary">Add Sub-category</button>
				</form>
			<?php else : ?>
				<p style="font-size: 0.85em; color: #666;">Add a category first. Every sub-category needs one.</p>
			<?php endif; ?>

			<hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">

			<h4 style="margin-bottom: 0;">Tags</h4>
			<?php
			if ( $tags ) :
				$mtl_delete_tags_confirm = array(
					'title'   => 'Delete Tags',
					'message' => 'Delete the selected tags?',
					'details' => array(
						'Any tools using them will simply lose that tag.',
						'This cannot be undone.',
					),
					'confirm' => 'Delete Tags',
					'danger'  => true,
				);
				?>
				<form method="post" action=""<?php echo mtl_confirm_attr( $mtl_delete_tags_confirm ); ?>>
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
		<?php mtl_setup_section_end(); ?>

		<?php
		mtl_setup_section_start(
			'trainings',
			array(
				'title' => 'Member Trainings',
				'desc'  => 'Safety and skill trainings members can complete, and how long each stays current.',
				'open'  => true,
			)
		);
		?>
			<p style="font-size: 0.9em; color: #666; margin-top: 0;">Staff record who has completed what (with the date) on the Membership page; members see their own on their account page.</p>

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

				<?php
				$mtl_delete_trainings_confirm = array(
					'title'   => 'Delete Trainings',
					'message' => 'Delete the selected trainings?',
					'details' => array(
						'Any members who completed them will lose that record, including the date.',
						'This cannot be undone.',
					),
					'confirm' => 'Delete Trainings',
					'danger'  => true,
				);
				?>
				<form method="post" action=""<?php echo mtl_confirm_attr( $mtl_delete_trainings_confirm ); ?>>
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
		<?php mtl_setup_section_end(); ?>
	</div>

	<div class="mtl-setup-group">
		<h2 class="mtl-setup-group-title">Library Settings</h2>

		<?php
		mtl_setup_section_start(
			'org',
			array(
				'title' => 'Organization',
				'desc'  => 'Your library&rsquo;s name, public contact email, currency symbol and Home Page Link.',
				'open'  => 'org' === $mtl_requested_section,
			)
		);
		?>
			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_save_settings_action', 'mtl_settings_nonce' ); ?>
				<table class="form-table" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="mtl_org_name">Organization Name</label></th>
						<td><input type="text" name="mtl_org_name" id="mtl_org_name" class="regular-text" value="<?php echo esc_attr( $org_name ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_contact_email">Public Contact Email</label></th>
						<td>
							<input type="email" name="mtl_contact_email" id="mtl_contact_email" class="regular-text" value="<?php echo esc_attr( $contact_email ); ?>" placeholder="hello@example.org">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Shown in the footer of every member-facing page. Use a shared staff address, not a personal one. Blank shows no contact details at all.</p>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Members write <em>to</em> it; nothing is sent <em>from</em> it.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_currency_symbol">Currency Symbol</label></th>
						<td><input type="text" name="mtl_currency_symbol" id="mtl_currency_symbol" style="width: 50px;" value="<?php echo esc_attr( $currency ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_home_url">Home Page Link</label></th>
						<td>
							<input type="url" name="mtl_home_url" id="mtl_home_url" class="regular-text" value="<?php echo esc_attr( $home_url ); ?>" placeholder="https://...">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Where the <strong>Home</strong> button on the public pages goes. Starts as this site&rsquo;s home page.</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="mtl_save_settings" value="org" class="button button-primary">Save Organization</button>
				</p>
			</form>
		<?php mtl_setup_section_end(); ?>

		<?php
		mtl_setup_section_start(
			'appearance',
			array(
				'title' => 'Appearance',
				'desc'  => 'Logo, badge image, colors, fonts and buttons, for the staff pages and the public pages.',
				'open'  => 'appearance' === $mtl_requested_section,
			)
		);
		?>
			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_save_settings_action', 'mtl_settings_nonce' ); ?>
				<table class="form-table" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="mtl_logo_url">Logo URL</label></th>
						<td>
							<input type="url" name="mtl_logo_url" id="mtl_logo_url" class="regular-text" value="<?php echo esc_url( $logo_url ); ?>" placeholder="https://...">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Upload to the Media Library and paste the File URL here.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_verified_badge_image_url">Verified Badge Image URL</label></th>
						<td>
							<input type="url" name="mtl_verified_badge_image_url" id="mtl_verified_badge_image_url" class="regular-text" value="<?php echo esc_url( $verified_badge_image_url ); ?>" placeholder="https://...">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Replaces the green &ldquo;Verified&rdquo; pill on a verified member&rsquo;s account page. Blank keeps the pill.</p>
						</td>
					</tr>
				</table>

				<hr style="border: 0; border-top: 1px solid #ddd; margin: 15px 0;">

				<!-- Quick Theme Presets -->
				<h4 style="margin-bottom: 5px;">Quick Theme Presets</h4>
				<p style="font-size: 0.85em; color: #666; margin: 0 0 10px 0;">A preset fills in the settings below; adjust anything before saving. <strong>Inherit</strong> restores the site defaults.</p>
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

				<p class="submit">
					<button type="submit" name="mtl_save_settings" value="appearance" class="button button-primary">Save Appearance</button>
				</p>
			</form>
		<?php mtl_setup_section_end(); ?>

		<?php
		mtl_setup_section_start(
			'messages',
			array(
				'title' => 'Member Page Messages',
				'desc'  => 'Pickup and verification directions, and the Consider Giving and Request a Tool boxes.',
				'open'  => 'messages' === $mtl_requested_section,
			)
		);
		?>
			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_save_settings_action', 'mtl_settings_nonce' ); ?>
				<h4 style="margin: 0 0 5px 0;">Directions</h4>
				<table class="form-table" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="mtl_pickup_directions">Tool Pickup Directions</label></th>
						<td>
							<textarea name="mtl_pickup_directions" id="mtl_pickup_directions" class="large-text" rows="4"><?php echo esc_textarea( $pickup_directions ); ?></textarea>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Shown to members on the My Reservations page. Blank hides it.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_verification_directions">Member Verification Directions</label></th>
						<td>
							<textarea name="mtl_verification_directions" id="mtl_verification_directions" class="large-text" rows="4"><?php echo esc_textarea( $verification_directions ); ?></textarea>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Shown on a member&rsquo;s Account page until they&rsquo;re verified. Blank hides it.</p>
						</td>
					</tr>
				</table>

				<hr style="border: 0; border-top: 1px solid #ddd; margin: 15px 0;">

				<h4 style="margin-bottom: 5px;">Consider Giving</h4>
				<table class="form-table" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="mtl_giving_text">Consider Giving Message</label></th>
						<td>
							<textarea name="mtl_giving_text" id="mtl_giving_text" class="large-text" rows="4"><?php echo esc_textarea( $giving_text ); ?></textarea>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Shown to signed-in members on their Account page and My Reservations. <strong>Blank hides the section</strong>.</p>
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
					<tr>
						<th scope="row"><label for="mtl_giving_wishlist_url">Consider Giving Wishlist Link</label></th>
						<td>
							<input type="url" name="mtl_giving_wishlist_url" id="mtl_giving_wishlist_url" class="large-text" value="<?php echo esc_attr( $wishlist_url ); ?>" placeholder="https://example.org/wishlist">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">
								Where the <strong>Wishlist</strong> button sends members, such as a list of items you want to buy. Opens in a new tab. Blank hides the button.
								<?php if ( '' !== $wishlist_url_raw && '' === $wishlist_url ) : ?>
									<br><span style="color: #b32d2e;"><strong>The link you last saved was discarded.</strong> Only ordinary web addresses starting with <code>http://</code> or <code>https://</code> can be used here.</span>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<hr style="border: 0; border-top: 1px solid #ddd; margin: 15px 0;">

				<h4 style="margin-bottom: 5px;">Request a Tool</h4>
				<table class="form-table" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="mtl_tool_request_text">Tool Request Message</label></th>
						<td>
							<textarea name="mtl_tool_request_text" id="mtl_tool_request_text" class="large-text" rows="3"><?php echo esc_textarea( $tool_request_text ); ?></textarea>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Shown on the public catalog when a search finds no tools, and on members&rsquo; Account page. Blank hides it.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_tool_request_url">Tool Request Link</label></th>
						<td>
							<input type="url" name="mtl_tool_request_url" id="mtl_tool_request_url" class="large-text" value="<?php echo esc_attr( $tool_request_url ); ?>" placeholder="https://example.org/request-a-tool">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">
								Where the <strong>Request a Tool</strong> button sends people, such as a request form. Opens in a new tab. Blank hides the button.
								<?php if ( '' !== $tool_request_url_raw && '' === $tool_request_url ) : ?>
									<br><span style="color: #b32d2e;"><strong>The link you last saved was discarded.</strong> Only ordinary web addresses starting with <code>http://</code> or <code>https://</code> can be used here.</span>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="mtl_save_settings" value="messages" class="button button-primary">Save Messages</button>
				</p>
			</form>
		<?php mtl_setup_section_end(); ?>

		<?php
		mtl_setup_section_start(
			'loans',
			array(
				'title' => 'Reservations & Loans',
				'desc'  => 'The default loan length, how long a ready reservation is held, and whether members see shelf locations.',
				'open'  => 'loans' === $mtl_requested_section,
			)
		);
		?>
			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_save_settings_action', 'mtl_settings_nonce' ); ?>
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
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Pre-fills the due date on checkout and renewal. Still adjustable per loan.</p>
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
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Starts once the member is at the front of the queue <em>and</em> the tool is back. The reservation is cancelled when it runs out.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Shelf Location</th>
						<td>
							<label>
								<input type="checkbox" name="mtl_show_tool_location" id="mtl_show_tool_location" value="1" <?php checked( true, $show_tool_location ); ?>>
								Show each tool&rsquo;s shelf location to members
							</label>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="mtl_save_settings" value="loans" class="button button-primary">Save Reservations &amp; Loans</button>
				</p>
			</form>
		<?php mtl_setup_section_end(); ?>

		<?php
		$mtl_stored_mode      = (string) get_option( 'mtl_agreements_mode', 'off' );
		$mtl_stored_mode      = in_array( $mtl_stored_mode, array( 'off', 'paper', 'full' ), true ) ? $mtl_stored_mode : 'off';
		$mtl_allow_paper      = (string) get_option( 'mtl_agreements_allow_paper', '' );
		$mtl_active_list      = mtl_get_active_agreements();
		$mtl_retired_list     = mtl_get_retired_agreements();
		$mtl_outstanding_now  = ( 'paper' === $mtl_stored_mode ) ? mtl_count_members_not_in_agreement() : 0;
		$mtl_agreement_emails = mtl_agreement_email_defaults();

		// Switched on with nothing to agree to does nothing at all, silently,
		// so the section opens on it rather than leaving it to be found.
		$mtl_agreements_untracked = 'off' !== $mtl_stored_mode && ! $mtl_active_list;
		$mtl_agreement_modes      = array(
			'off'   => 'Off',
			'paper' => 'Paper only',
			'full'  => 'Full',
		);

		mtl_setup_section_start(
			'agreements',
			array(
				'title' => 'Member Agreements',
				'desc'  => 'Waivers and other statements members must agree to, and the emails about them.',
				'open'  => 'agreements' === $mtl_requested_section || $mtl_agreements_untracked,
				'badge' => $mtl_agreements_untracked ? 'Nothing to agree to' : $mtl_agreement_modes[ $mtl_stored_mode ],
				'tone'  => $mtl_agreements_untracked ? 'warn' : '',
			)
		);
		?>
			<p style="font-size: 0.9em; color: #666;">Statements every member has to agree to: a liability waiver, a code of conduct, a fee schedule. Members see all of them, in this order, and what they agreed to is recorded exactly as it was worded at the time.</p>

			<?php if ( $mtl_agreements_untracked ) : ?>
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
								<?php
								if ( 0 === $mtl_accept_count ) :
									$mtl_delete_agreement_confirm = array(
										'title'   => 'Delete Agreement',
										'message' => 'Delete this agreement?',
										'details' => array(
											'No one has agreed to it, so nothing will be lost.',
											'This cannot be undone.',
										),
										'confirm' => 'Delete Agreement',
										'danger'  => true,
									);
									?>
									<form method="post" action="" style="display: inline;"<?php echo mtl_confirm_attr( $mtl_delete_agreement_confirm ); ?>>
										<?php wp_nonce_field( 'mtl_delete_agreement_action', 'mtl_delete_agreement_nonce' ); ?>
										<input type="hidden" name="agreement_id" value="<?php echo esc_attr( $mtl_agreement_id ); ?>">
										<button type="submit" name="mtl_delete_agreement" class="button mtl-btn-danger">Delete</button>
									</form>
									<?php
								else :
									$mtl_retire_agreement_confirm = array(
										'title'   => 'Retire Agreement',
										'message' => 'Retire this agreement?',
										'details' => array(
											'It stops being shown and stops being required.',
											'Everyone who already agreed to it keeps that record.',
										),
										'confirm' => 'Retire Agreement',
										'danger'  => true,
									);
									?>
									<form method="post" action="" style="display: inline;"<?php echo mtl_confirm_attr( $mtl_retire_agreement_confirm ); ?>>
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
											<a class="button" href="<?php echo esc_url( add_query_arg( 'mtl_open', 'agreements', remove_query_arg( 'mtl_edit_agreement' ) ) . '#mtl-section-agreements' ); ?>">Cancel</a>
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
						<span style="font-size: 0.85em; color: #666;">Plain text; line breaks are kept, formatting is not. Attach a file below to give members a document.</span>
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
								<?php
								$mtl_unretire_agreement_confirm = array(
									'title'   => 'Restore Agreement',
									'message' => 'Put this agreement back into use?',
									'details' => array(
										'It goes to the end of the list at its existing version number.',
										'Members who never agreed to it will be outstanding.',
									),
									'confirm' => 'Put Back into Use',
								);
								?>
								<form method="post" action=""<?php echo mtl_confirm_attr( $mtl_unretire_agreement_confirm ); ?>>
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
							<p class="description">Sent after a member agrees. The wording and any files are added automatically, so don&rsquo;t list them here.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_agreement_request_email_body">Request body</label></th>
						<td>
							<textarea name="mtl_agreement_request_email_body" id="mtl_agreement_request_email_body" rows="4" class="large-text" placeholder="<?php echo esc_attr( $mtl_agreement_emails['request_body'] ); ?>"><?php echo esc_textarea( (string) get_option( 'mtl_agreement_request_email_body', '' ) ); ?></textarea>
							<p class="description">Sent when you ask members to agree from the Membership page. Online agreements only.</p>
						</td>
					</tr>
				</table>
				<p class="submit" style="margin: 0;">
					<button type="submit" name="mtl_save_agreement_emails" class="button button-primary">Save Email Wording</button>
				</p>
			</form>
		<?php mtl_setup_section_end(); ?>
	</div>

	<div class="mtl-setup-group">
		<h2 class="mtl-setup-group-title">Data &amp; Backups</h2>

		<?php
		mtl_setup_section_start(
			'export',
			array(
				'title' => 'Export Data',
				'desc'  => 'Download every record as a .sql dump or a .zip of CSVs.',
				'open'  => true,
			)
		);
		?>
			<p>Download a complete copy of all My Tool Library data: members, verifications, trainings, agreement records, inventory, categories, tags, loans and reservations.</p>

			<ul style="font-size: 0.85em; color: #666; margin: 0 0 15px 20px;">
				<li><strong>.sql dump</strong>: a single SQL file (DROP + CREATE + INSERT) you can import into any MySQL/MariaDB database. Table names <strong>keep</strong> the <code><?php echo esc_html( $wpdb->prefix ); ?></code> prefix (e.g. <code><?php echo esc_html( $wpdb->prefix ); ?>members</code>), matching how the plugin creates them. <strong>This is the one to keep as a backup:</strong> it preserves every record&rsquo;s ID, so restoring it under <strong>Restore from Backup</strong> below puts members, loans, reservations and members&rsquo; online sign-ins back exactly as they were.</li>
				<li><strong>.zip of CSVs</strong>: one <code>.csv</code> file per table, named after the table without the prefix (e.g. <code>members.csv</code>), handy for spreadsheets and for reading in Excel.</li>
			</ul>

			<div style="background: #fff8e5; border-left: 4px solid #dba617; padding: 12px; margin-bottom: 20px; font-size: 0.9em;">
				<strong>A CSV export is not a backup.</strong> The Membership and Inventory bulk importers always assign new IDs, so re-importing <code>members.csv</code> after a reset creates fresh member records that no longer match members&rsquo; existing sign-ins, and there is no importer at all for loans or reservations. To restore a library, upload the <strong>.sql dump</strong> under <strong>Restore from Backup</strong> below.
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
		<?php mtl_setup_section_end(); ?>

		<?php
		$backup_settings = mtl_backup_settings();
		$backup_last     = get_option( 'mtl_backup_last', array() );
		$backup_success  = (int) get_option( 'mtl_backup_last_success', 0 );
		$backup_next     = wp_next_scheduled( 'mtl_auto_backup' );
		$backup_key      = mtl_backup_key( false );
		$stored_backups  = mtl_backup_list();
		$backup_failure  = is_array( $backup_last ) && ! empty( $backup_last['error'] ) ? $backup_last : array();
		// A day's grace past the schedule before calling it late, because WP-Cron
		// only runs when somebody visits the site.
		$backup_overdue = $backup_settings['enabled'] && $backup_success > 0 && $backup_success < time() - ( $backup_settings['days'] + 1 ) * DAY_IN_SECONDS;
		// Only worth a loopback request once there is something to protect.
		$backup_folder_public = ( $backup_settings['enabled'] || $stored_backups ) && mtl_backup_folder_is_public();
		$backup_folder_path   = (string) wp_parse_url( wp_upload_dir( null, false )['baseurl'], PHP_URL_PATH ) . '/mtl-backups/';

		// The badge shows the state most worth knowing. Only a failed or
		// overdue backup opens the section: the other warnings inside can
		// stand for good on some hosts, and opening every visit would nag.
		if ( $backup_failure ) {
			$backup_badge = array( 'Last backup failed', 'error' );
		} elseif ( $backup_overdue ) {
			$backup_badge = array( 'Overdue', 'warn' );
		} elseif ( $backup_folder_public ) {
			$backup_badge = array( 'Folder not blocked', 'warn' );
		} elseif ( $backup_settings['enabled'] ) {
			$backup_badge = array( 1 === $backup_settings['days'] ? 'Every day' : 'Every ' . $backup_settings['days'] . ' days', '' );
		} else {
			$backup_badge = array( 'Off', '' );
		}

		mtl_setup_section_start(
			'backups',
			array(
				'title' => 'Automatic Backups',
				'desc'  => 'Encrypted copies saved to the Media Library on a schedule, and the key that opens them.',
				'open'  => 'backups' === $mtl_requested_section || $backup_failure || $backup_overdue,
				'badge' => $backup_badge[0],
				'tone'  => $backup_badge[1],
			)
		);
		?>
			<p>Saves an encrypted copy of the .sql dump to the Media Library on a schedule, so there is always a recent backup even if nobody remembers to download one. Only administrators can see or download them, and <strong>Restore from Backup</strong> below can restore them directly.</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_backup_settings_action', 'mtl_backup_settings_nonce' ); ?>
				<table class="form-table" style="margin-top: 0;">
					<tr>
						<th scope="row">Automatic Backups</th>
						<td>
							<label>
								<input type="checkbox" name="mtl_backup_enabled" id="mtl_backup_enabled" value="1" <?php checked( $backup_settings['enabled'] ); ?>>
								Back up automatically
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_backup_interval_days">Back Up Every</label></th>
						<td>
							<input type="number" name="mtl_backup_interval_days" id="mtl_backup_interval_days" min="1" max="90" step="1" value="<?php echo esc_attr( $backup_settings['days'] ); ?>" style="width: 90px;">
							<span style="margin-left: 4px;">days</span>
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Runs around 3 a.m. site time, as soon as someone visits the site after that.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mtl_backup_keep">Backups to Keep</label></th>
						<td>
							<input type="number" name="mtl_backup_keep" id="mtl_backup_keep" min="1" max="100" step="1" value="<?php echo esc_attr( $backup_settings['keep'] ); ?>" style="width: 90px;">
							<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">The oldest is deleted each time a new one is made.</p>
						</td>
					</tr>
				</table>
				<p class="submit" style="margin: 0 0 20px 0; padding: 0;">
					<button type="submit" name="mtl_save_backup_settings" class="button button-primary">Save Backup Settings</button>
				</p>
			</form>

			<div style="background: #f6f7f7; border-left: 4px solid #8c8f94; padding: 12px; margin-bottom: 20px; font-size: 0.9em;">
				<p style="margin: 0 0 4px 0;"><strong>Last backup:</strong> <?php echo $backup_success ? esc_html( wp_date( 'M j, Y g:i a', $backup_success ) ) : 'none yet'; ?></p>
				<?php if ( $backup_settings['enabled'] && $backup_next ) : ?>
					<p style="margin: 0 0 4px 0;"><strong>Next backup:</strong> around <?php echo esc_html( wp_date( 'M j, Y g:i a', $backup_next ) ); ?></p>
				<?php endif; ?>
				<p style="margin: 0;"><strong>Stored in the Media Library:</strong> <?php echo intval( count( $stored_backups ) ); ?></p>
			</div>

			<?php if ( $backup_failure ) : ?>
				<div style="background: #fdf2f2; border-left: 4px solid #d63638; padding: 12px; margin-bottom: 20px; font-size: 0.9em;">
					<strong>The last backup attempt failed</strong> (<?php echo esc_html( wp_date( 'M j, Y g:i a', (int) $backup_last['time'] ) ); ?>): <?php echo esc_html( $backup_last['error'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $backup_overdue ) : ?>
				<div style="background: #fff8e5; border-left: 4px solid #dba617; padding: 12px; margin-bottom: 20px; font-size: 0.9em;">
					<strong>The last automatic backup is overdue.</strong> WordPress only runs scheduled tasks when someone visits the site, so a quiet site can fall behind. Use <strong>Back up now</strong>, and ask your host to run <code>wp-cron.php</code> on a schedule if this keeps happening.
				</div>
			<?php endif; ?>

			<?php if ( $backup_folder_public ) : ?>
				<div style="background: #fff8e5; border-left: 4px solid #dba617; padding: 12px; margin-bottom: 20px; font-size: 0.9em;">
					<p style="margin: 0 0 8px 0;"><strong>This server will hand out a backup to anyone who has its exact address.</strong> The backups are encrypted and their names can&rsquo;t be guessed, so they stay unreadable, but for the strongest protection ask your host to block the folder. On nginx, the rule is:</p>
					<p style="margin: 0;"><code>location ^~ <?php echo esc_html( $backup_folder_path ); ?> { deny all; }</code></p>
				</div>
			<?php endif; ?>

			<?php if ( $backup_settings['enabled'] && defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div style="background: #fff8e5; border-left: 4px solid #dba617; padding: 12px; margin-bottom: 20px; font-size: 0.9em;">
					<strong>WordPress&rsquo;s scheduler is turned off on this site</strong> (<code>DISABLE_WP_CRON</code>), so automatic backups only run if your host calls <code>wp-cron.php</code> on a schedule. Check with them.
				</div>
			<?php endif; ?>

			<?php if ( '' !== $backup_key ) : ?>
				<div style="background: #fff8e5; border-left: 4px solid #dba617; padding: 12px; margin-bottom: 20px; font-size: 0.9em;">
					<p style="margin: 0 0 8px 0;"><strong>Save your backup key somewhere safe, such as a password manager.</strong> Automatic backups are encrypted with it. This site opens them on its own, but if its database is ever lost, the backups can&rsquo;t be opened without this key, and nobody can recover it for you.</p>
					<details>
						<summary style="cursor: pointer;">Show backup key</summary>
						<p style="margin: 8px 0 0 0; display: flex; gap: 8px; flex-wrap: wrap;">
							<input type="text" readonly id="mtl-backup-key" class="code" style="width: 100%; max-width: 640px;" value="<?php echo esc_attr( mtl_backup_key_display( $backup_key ) ); ?>" onfocus="this.select();">
							<button type="button" class="button" id="mtl-backup-key-copy">Copy</button>
						</p>
					</details>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'mtl_backup_now_action', 'mtl_backup_now_nonce' ); ?>
				<p class="submit" style="margin: 0; padding: 0;">
					<button type="submit" name="mtl_backup_now" class="button button-secondary">Back up now</button>
				</p>
			</form>
		<?php mtl_setup_section_end(); ?>

		<?php
		mtl_setup_section_start(
			'restore',
			array(
				'title'  => 'Restore from Backup',
				'desc'   => 'Replace all library data with an exported .sql dump or an automatic backup.',
				'open'   => 'restore' === $mtl_requested_section,
				'danger' => true,
			)
		);
		?>
			<p>Replaces all My Tool Library data with a <strong>.sql dump</strong> from Export Data or one of the automatic backups. Use it to recover from a mistake, a bad import or a lost database.</p>

			<div style="background: #fdf2f2; border-left: 4px solid #d63638; padding: 12px; margin-bottom: 20px;">
				<p style="margin: 0 0 8px 0;"><strong>Warning: everything stored now is replaced by what is in the file.</strong> Members, tools, loans and reservations added after that dump was made will be gone. Download a fresh .sql dump first if you might want today&rsquo;s data back.</p>
				<p style="margin: 0;">The whole file is checked before anything changes, and if any of it fails to load, nothing changes at all. The settings on this page aren&rsquo;t part of the backup and stay as they are.</p>
			</div>

			<form method="post" action="" enctype="multipart/form-data" id="mtl-db-restore-form">
				<?php wp_nonce_field( 'mtl_restore_action', 'mtl_restore_nonce' ); ?>
				<?php
				// Filled in by the confirmation dialog below; the server rejects the submission unless it matches exactly.
				?>
				<input type="hidden" name="mtl_restore_confirmation" id="mtl-db-restore-confirmation" value="">
				<?php if ( $stored_backups ) : ?>
					<p style="margin-bottom: 4px;"><label for="mtl-db-restore-backup"><strong>Restore from</strong></label></p>
					<p style="margin-top: 0;">
						<select name="mtl_restore_backup" id="mtl-db-restore-backup">
							<option value="0">A file I upload</option>
							<?php foreach ( $stored_backups as $stored_backup ) : ?>
								<option value="<?php echo esc_attr( $stored_backup->ID ); ?>">Backup from<?php echo esc_html( wp_date( 'M j, Y g:i a', (int) get_post_time( 'U', true, $stored_backup ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>
				<div id="mtl-db-restore-upload">
					<p style="margin-bottom: 4px;">
						<input type="file" name="mtl_restore_file" id="mtl-db-restore-file" accept=".sql,.enc" required>
					</p>
					<p style="margin: 0 0 15px 0; font-size: 0.85em; color: #666;">A .sql dump from Export Data, or an automatic backup (.sql.enc) downloaded from the Media Library. Largest file this server accepts: <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>.</p>
					<p style="margin: 0 0 4px 0;"><label for="mtl-db-restore-key"><strong>Backup key</strong></label> <span style="font-size: 0.85em; color: #666;">(only for an automatic backup this site can&rsquo;t open itself, such as one from another site or from before its database was lost)</span></p>
					<p style="margin-top: 0;">
						<input type="text" name="mtl_restore_key" id="mtl-db-restore-key" class="code" style="width: 100%; max-width: 640px;" autocomplete="off" spellcheck="false">
					</p>
				</div>
				<label class="mtl-lock-toggle">
					<input type="checkbox" required>
					<span class="mtl-lock-slider"></span>
					<span class="mtl-lock-label">Slide to unlock. I understand this will replace existing data</span>
				</label>
				<p class="submit">
					<input type="submit" name="mtl_restore_sql" class="button button-secondary mtl-danger-btn" value="Restore from Backup">
				</p>
			</form>
		<?php mtl_setup_section_end(); ?>

		<?php
		mtl_setup_section_start(
			'database',
			array(
				'title'  => 'Database Configuration',
				'desc'   => 'Creates the plugin&rsquo;s tables on a new site. On a running library it erases everything.',
				'open'   => 'database' === $mtl_requested_section || ! $mtl_tables_ready,
				'danger' => true,
				'badge'  => $mtl_tables_ready ? '' : 'Not set up yet',
				'tone'   => 'error',
			)
		);
		?>
			<p>Builds the plugin's database tables from the bundled <code>schema.sql</code> file. Required once when you first install the plugin; on a library that is already running, it is a full reset, not a repair.</p>

			<div style="background: #fdf2f2; border-left: 4px solid #d63638; padding: 12px; margin-bottom: 20px;">
				<p style="margin: 0 0 8px 0;"><strong>Warning: this permanently deletes all My Tool Library data.</strong></p>
				<p style="margin: 0 0 8px 0;"><code>schema.sql</code> begins by dropping every one of the plugin's tables, so running it <em>always</em> erases what is currently stored &#40;every member, verification document, training record, tool, category, tag, loan and reservation&#41; and then recreates the tables empty. This is not a conditional risk and there is no undo.</p>
				<p style="margin: 0 0 8px 0;">Members&rsquo; <strong>WordPress sign-ins are not touched</strong> but the records those sign-ins point to are gone, so members will be told their account can&rsquo;t be matched until the data is restored. Re-importing members from CSV does <em>not</em> fix this: it assigns brand-new member IDs.</p>
				<p style="margin: 0;">Use <strong>Export Data</strong> first if there is any chance you will need the current contents back, then put them back with <strong>Restore from Backup</strong>.</p>
			</div>

			<form method="post" action="" id="mtl-db-reset-form">
				<?php wp_nonce_field( 'mtl_run_db_action', 'mtl_db_nonce' ); ?>
				<?php
				// Filled in by the confirmation dialog below; the server rejects the submission unless it matches exactly.
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
		<?php mtl_setup_section_end(); ?>
	</div>

	<script>
		/*
		 * Second gate on the database reset and the restore: the
		 * slide-to-unlock toggle stops an accidental click, and this dialog
		 * stops a deliberate-but-unconsidered one by keeping its button
		 * disabled until the admin types the phrase out. Each phrase is
		 * re-checked server-side (see the mtl_run_db_setup and
		 * mtl_restore_sql handlers), so this is a usability layer rather
		 * than the security boundary.
		 */
		(function() {
			[
				{
					form: 'mtl-db-reset-form',
					field: 'mtl-db-reset-confirmation',
					button: 'mtl_run_db_setup',
					phrase: <?php echo wp_json_encode( mtl_db_reset_confirmation_phrase() ); ?>,
					title: 'Run Database Setup',
					message: 'This permanently deletes ALL My Tool Library data: members, tools, loans, reservations and everything else.',
					details: ['It cannot be undone.']
				},
				{
					form: 'mtl-db-restore-form',
					field: 'mtl-db-restore-confirmation',
					button: 'mtl_restore_sql',
					// The backup picked from the list, or else the file chosen.
					subject: function() {
						var pick = document.getElementById('mtl-db-restore-backup');
						if (pick && pick.value !== '0') {
							return pick.options[pick.selectedIndex].text;
						}
						var file = document.getElementById('mtl-db-restore-file');
						return file && file.files.length ? file.files[0].name : 'the chosen file';
					},
					phrase: <?php echo wp_json_encode( mtl_db_restore_confirmation_phrase() ); ?>,
					title: 'Restore from Backup',
					message: 'This replaces ALL My Tool Library data with the contents of %s.',
					details: ['Anything added after that backup was made will be gone.', 'It cannot be undone, except by restoring another backup.']
				}
			].forEach(function(cfg) {
				var form = document.getElementById(cfg.form);
				if (!form) {
					return;
				}
				var field = document.getElementById(cfg.field);

				form.addEventListener('submit', function(event) {
					event.preventDefault();
					var submitter = event.submitter || form.querySelector('[name="' + cfg.button + '"]');
					window.mtlDialog.confirm({
						title: cfg.title,
						message: cfg.message,
						subject: cfg.subject ? cfg.subject() : undefined,
						details: cfg.details,
						match: cfg.phrase,
						confirm: cfg.title,
						danger: true
					}).then(function(ok) {
						if (!ok) {
							return;
						}
						field.value = cfg.phrase;
						window.mtlDialog.submit(form, submitter);
					});
				});
			});
		}());

		// Restore from: picking an automatic backup hides the upload
		// fields, and stops the file being required.
		(function() {
			var pick = document.getElementById('mtl-db-restore-backup');
			var upload = document.getElementById('mtl-db-restore-upload');
			var file = document.getElementById('mtl-db-restore-file');
			if (!pick || !upload || !file) {
				return;
			}
			var sync = function() {
				var stored = pick.value !== '0';
				upload.style.display = stored ? 'none' : '';
				file.required = !stored;
			};
			pick.addEventListener('change', sync);
			sync();
		}());

		// Copy button beside the backup key.
		(function() {
			var button = document.getElementById('mtl-backup-key-copy');
			var input = document.getElementById('mtl-backup-key');
			if (!button || !input) {
				return;
			}
			button.addEventListener('click', function() {
				var done = function() {
					button.textContent = 'Copied';
				};
				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText(input.value).then(done);
				} else {
					input.select();
					if (document.execCommand('copy')) {
						done();
					}
				}
			});
		}());
	</script>

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

		// Fills in the color pickers from a "Quick Theme Presets" swatch; still requires clicking "Save Appearance".
		function mtlApplySwatch(headerColor, bodyColor, linkColor, accentColor) {
			document.getElementById('mtl_header_color').value = headerColor;
			document.getElementById('mtl_body_color').value = bodyColor;
			document.getElementById('mtl_link_color').value = linkColor;
			document.getElementById('mtl_accent_color').value = accentColor;
		}

		// Restores every appearance field to the plugin's defaults (matching
		// get_option()'s fallbacks); nothing is saved until "Save Appearance" is clicked.
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
				var submitter = event.submitter || button;
				event.preventDefault();
				window.mtlDialog.confirm({
					title: 'Switch to Full Mode',
					message: 'Switching to full mode will immediately require ' + count +
						(count === 1 ? ' member' : ' members') +
						' to agree online, and block them from reserving tools until they do.',
					details: [
						'They will not be emailed automatically. Send agreement requests from the Membership page.',
						'Switching back to "Track signed paper only" releases everyone again straight away.'
					],
					confirm: 'Switch to Full Mode',
					danger: true
				}).then(function(ok) {
					if (ok) {
						window.mtlDialog.submit(modeForm, submitter);
					}
				});
			});
		})();
	</script>
	<?php
	echo '</div>';
}
