<?php
// Prevent direct file access
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
 * Plain web-safe stacks only -- never a webfont from Google Fonts or any
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
 * absent -- schema.sql drops that table but never creates it.
 *
 * @return string[] Bare table names.
 */
function mtl_export_table_names() {
	return array(
		'members',
		'member_verifications',
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
 * Serve the Export Data downloads (.sql dump or .zip of CSVs). Must run on
 * admin_init -- before any admin HTML is sent -- so it can emit
 * file-download headers and a raw body.
 */
add_action( 'admin_init', 'mtl_maybe_export_data' );
function mtl_maybe_export_data() {
	$want_sql = isset( $_POST['mtl_export_sql'] );
	$want_zip = isset( $_POST['mtl_export_zip'] );
	if ( ! $want_sql && ! $want_zip ) {
		return;
	}

	// Exporting exposes ALL member data (including sensitive verification
	// document URLs), so gate it on the admin capability AND a valid nonce.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_POST['mtl_export_nonce'] ) || ! wp_verify_nonce( $_POST['mtl_export_nonce'], 'mtl_export_action' ) ) {
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

	foreach ( $bare_tables as $bare ) {
		// $full is a trusted prefix + hardcoded bare name (no user input),
		// so it is safe to interpolate into the backtick-quoted identifiers below.
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
					$vals[] = ( $v === null ) ? 'NULL' : "'" . esc_sql( $v ) . "'";
				}
				$out .= "INSERT INTO `$full` ($col_list) VALUES (" . implode( ', ', $vals ) . ");\n";
			}
			$out .= "\n";
		}
	}

	$out .= "SET FOREIGN_KEY_CHECKS=1;\n";

	echo $out;
	exit;
}

/**
 * Stream a .zip containing one CSV per table (bare table name + ".csv").
 * Uses a small self-contained ZIP writer so it depends on nothing beyond
 * core PHP -- ZipArchive is not required.
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
		$header  = pack( 'V', 0x04034b50 ); // local file header signature
		$header .= pack( 'v', 20 );         // version needed to extract
		$header .= pack( 'v', 0 );          // general purpose bit flag
		$header .= pack( 'v', 0 );          // compression method: 0 = store
		$header .= pack( 'v', $dos_time );
		$header .= pack( 'v', $dos_date );
		$header .= pack( 'V', $crc );
		$header .= pack( 'V', $size );      // compressed size (= size, stored)
		$header .= pack( 'V', $size );      // uncompressed size
		$header .= pack( 'v', $name_len );
		$header .= pack( 'v', 0 );          // extra field length
		$header .= $name;

		$local_data .= $header . $data;

		// --- Central directory record for this file ---
		$record  = pack( 'V', 0x02014b50 ); // central file header signature
		$record .= pack( 'v', 20 );         // version made by
		$record .= pack( 'v', 20 );         // version needed to extract
		$record .= pack( 'v', 0 );          // general purpose bit flag
		$record .= pack( 'v', 0 );          // compression method
		$record .= pack( 'v', $dos_time );
		$record .= pack( 'v', $dos_date );
		$record .= pack( 'V', $crc );
		$record .= pack( 'V', $size );
		$record .= pack( 'V', $size );
		$record .= pack( 'v', $name_len );
		$record .= pack( 'v', 0 );          // extra field length
		$record .= pack( 'v', 0 );          // file comment length
		$record .= pack( 'v', 0 );          // disk number start
		$record .= pack( 'v', 0 );          // internal file attributes
		$record .= pack( 'V', 0 );          // external file attributes
		$record .= pack( 'V', $offset );    // relative offset of local header
		$record .= $name;

		$central_dir .= $record;
		$offset      += strlen( $header ) + $size;
	}

	// --- End of central directory record ---
	$eocd  = pack( 'V', 0x06054b50 );
	$eocd .= pack( 'v', 0 );                 // number of this disk
	$eocd .= pack( 'v', 0 );                 // disk with central directory
	$eocd .= pack( 'v', count( $files ) );     // entries on this disk
	$eocd .= pack( 'v', count( $files ) );     // total entries
	$eocd .= pack( 'V', strlen( $central_dir ) );
	$eocd .= pack( 'V', $offset );           // offset of central directory
	$eocd .= pack( 'v', 0 );                 // comment length

	return $local_data . $central_dir . $eocd;
}

/**
 * Renders the Setup & Settings admin page.
 */
function mtl_render_setup_page() {
	global $wpdb;

	$tbl_categories = $wpdb->prefix . 'tool_categories';
	$tbl_tags       = $wpdb->prefix . 'tool_tags';

	echo '<div class="wrap mtl-admin-wrapper">';
	echo '<h2>My Tool Library Setup & Settings</h2>';

	// ==========================================
	// 1. HANDLE SETTINGS FORM SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_save_settings'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_settings_nonce'] ) && wp_verify_nonce( $_POST['mtl_settings_nonce'], 'mtl_save_settings_action' ) ) {

			// General
			update_option( 'mtl_org_name', sanitize_text_field( wp_unslash( $_POST['mtl_org_name'] ) ) );
			update_option( 'mtl_contact_email', sanitize_email( wp_unslash( $_POST['mtl_contact_email'] ) ) );
			update_option( 'mtl_currency_symbol', sanitize_text_field( wp_unslash( $_POST['mtl_currency_symbol'] ) ) );
			update_option( 'mtl_logo_url', sanitize_url( wp_unslash( $_POST['mtl_logo_url'] ) ) );

			// Header Options
			update_option( 'mtl_header_color', sanitize_hex_color( wp_unslash( $_POST['mtl_header_color'] ) ) );
			update_option( 'mtl_header_font', mtl_sanitize_css_value( $_POST['mtl_header_font'] ) );
			update_option( 'mtl_header_size', mtl_sanitize_css_value( $_POST['mtl_header_size'] ) );

			// <select>-backed values are whitelisted server-side rather than trusted outright.
			$allowed_h_weights = array( '400', '600', '700' );
			$posted_h_weight   = sanitize_text_field( wp_unslash( $_POST['mtl_header_weight'] ) );
			update_option( 'mtl_header_weight', in_array( $posted_h_weight, $allowed_h_weights, true ) ? $posted_h_weight : '700' );

			$allowed_transforms = array( 'none', 'uppercase', 'capitalize', 'lowercase' );
			$posted_transform   = sanitize_text_field( wp_unslash( $_POST['mtl_header_transform'] ) );
			update_option( 'mtl_header_transform', in_array( $posted_transform, $allowed_transforms, true ) ? $posted_transform : 'none' );

			// Body Options
			update_option( 'mtl_body_color', sanitize_hex_color( wp_unslash( $_POST['mtl_body_color'] ) ) );
			update_option( 'mtl_body_font', mtl_sanitize_css_value( $_POST['mtl_body_font'] ) );
			update_option( 'mtl_body_size', mtl_sanitize_css_value( $_POST['mtl_body_size'] ) );

			$allowed_b_weights = array( '300', '400', '700' );
			$posted_b_weight   = sanitize_text_field( wp_unslash( $_POST['mtl_body_weight'] ) );
			update_option( 'mtl_body_weight', in_array( $posted_b_weight, $allowed_b_weights, true ) ? $posted_b_weight : '400' );

			// Link Options
			update_option( 'mtl_link_color', sanitize_hex_color( wp_unslash( $_POST['mtl_link_color'] ) ) );
			update_option( 'mtl_link_font', mtl_sanitize_css_value( $_POST['mtl_link_font'] ) );
			update_option( 'mtl_link_size', mtl_sanitize_css_value( $_POST['mtl_link_size'] ) );

			$allowed_decorations = array( 'none', 'underline' );
			$posted_decoration   = sanitize_text_field( wp_unslash( $_POST['mtl_link_decoration'] ) );
			update_option( 'mtl_link_decoration', in_array( $posted_decoration, $allowed_decorations, true ) ? $posted_decoration : 'none' );

			// Buttons & Page Accents
			update_option( 'mtl_accent_color', sanitize_hex_color( wp_unslash( $_POST['mtl_accent_color'] ) ) );
			update_option( 'mtl_background_color', sanitize_hex_color( wp_unslash( $_POST['mtl_background_color'] ) ) );

			$allowed_radii = array( '0px', '4px', '10px', '999px' );
			$posted_radius = sanitize_text_field( wp_unslash( $_POST['mtl_border_radius'] ) );
			update_option( 'mtl_border_radius', in_array( $posted_radius, $allowed_radii, true ) ? $posted_radius : '4px' );

			// Stored as a plain multiplier for calc() when styles are injected; whitelisted since it lands directly in a CSS rule.
			$allowed_btn_scales = array( '1.25', '1', '0.85', '0.7' );
			$posted_btn_scale   = sanitize_text_field( wp_unslash( $_POST['mtl_button_scale'] ) );
			update_option( 'mtl_button_scale', in_array( $posted_btn_scale, $allowed_btn_scales, true ) ? $posted_btn_scale : '1' );

			// Reservations & Loans
			// Lands directly in date math (strtotime("+{$n} days")) on the
			// Loans & Reservations and Inventory pages, so it is whitelisted rather than trusted.
			$allowed_loan_days = array( '7', '14', '21', '30' );
			$posted_loan_days  = sanitize_text_field( wp_unslash( $_POST['mtl_default_loan_days'] ) );
			update_option( 'mtl_default_loan_days', in_array( $posted_loan_days, $allowed_loan_days, true ) ? $posted_loan_days : '21' );

			// A saved blank value is meaningful, not "unset": get_option()'s
			// default only applies before the option row exists, so an empty
			// save sticks and intentionally hides the directions on the public pages.
			update_option( 'mtl_pickup_directions', sanitize_textarea_field( wp_unslash( $_POST['mtl_pickup_directions'] ) ) );
			update_option( 'mtl_verification_directions', sanitize_textarea_field( wp_unslash( $_POST['mtl_verification_directions'] ) ) );

			echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Settings have been saved.</p></div>';
		}
	}

	// ==========================================
	// 1B. HANDLE HOME PAGE LINK SUBMISSION
	// ==========================================
	// Own form/nonce/option, separate from the General Details form above,
	// so saving just this field can never blank out the other settings.
	if ( isset( $_POST['mtl_save_home_url'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_home_url_nonce'] ) && wp_verify_nonce( $_POST['mtl_home_url_nonce'], 'mtl_save_home_url_action' ) ) {
			update_option( 'mtl_home_url', sanitize_url( wp_unslash( $_POST['mtl_home_url'] ) ) );
			echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Home page link has been saved.</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 2. HANDLE "ADD CATEGORY" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_add_category'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_add_category_nonce'] ) && wp_verify_nonce( $_POST['mtl_add_category_nonce'], 'mtl_add_category_action' ) ) {
			$new_category_name = sanitize_text_field( wp_unslash( $_POST['new_category_name'] ) );

			if ( $new_category_name === '' ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please enter a category name.</p></div>';
			} elseif ( strlen( $new_category_name ) > 50 ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Category names must be 50 characters or fewer.</p></div>';
			} else {
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT category_id FROM {$tbl_categories} WHERE category_name = %s LIMIT 1",
						$new_category_name
					)
				);

				if ( $existing ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That category already exists.</p></div>';
				} else {
					// category_id is AUTO_INCREMENT -- MySQL assigns the next id.
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
	if ( isset( $_POST['mtl_add_tag'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_add_tag_nonce'] ) && wp_verify_nonce( $_POST['mtl_add_tag_nonce'], 'mtl_add_tag_action' ) ) {
			$new_tag_name = sanitize_text_field( wp_unslash( $_POST['new_tag_name'] ) );

			if ( $new_tag_name === '' ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please enter a tag name.</p></div>';
			} elseif ( strlen( $new_tag_name ) > 50 ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Tag names must be 50 characters or fewer.</p></div>';
			} else {
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT tag_id FROM {$tbl_tags} WHERE tag_name = %s LIMIT 1",
						$new_tag_name
					)
				);

				if ( $existing ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That tag already exists.</p></div>';
				} else {
					// tag_id is AUTO_INCREMENT -- MySQL assigns the next id.
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
	if ( isset( $_POST['mtl_delete_categories'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_delete_categories_nonce'] ) && wp_verify_nonce( $_POST['mtl_delete_categories_nonce'], 'mtl_delete_categories_action' ) ) {
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
				// -- it does not fail or delete the tool itself.
				$deleted_count = 0;
				foreach ( $delete_category_ids as $id ) {
					if ( $wpdb->delete( $tbl_categories, array( 'category_id' => $id ), array( '%d' ) ) ) {
						++$deleted_count;
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p><strong>Removed.</strong> ' . intval( $deleted_count ) . ' categor' . ( $deleted_count === 1 ? 'y' : 'ies' ) . ' deleted. Any tools that had it were automatically un-categorized from it.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 3B. HANDLE "DELETE TAGS" SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_delete_tags'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_delete_tags_nonce'] ) && wp_verify_nonce( $_POST['mtl_delete_tags_nonce'], 'mtl_delete_tags_action' ) ) {
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
				// schema.sql), so any tool using it simply loses that tag --
				// it does not fail or delete the tool itself.
				$deleted_count = 0;
				foreach ( $delete_tag_ids as $id ) {
					if ( $wpdb->delete( $tbl_tags, array( 'tag_id' => $id ), array( '%d' ) ) ) {
						++$deleted_count;
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p><strong>Removed.</strong> ' . intval( $deleted_count ) . ' tag' . ( $deleted_count === 1 ? '' : 's' ) . ' deleted. Any tools that had it were automatically untagged.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 4. HANDLE DATABASE SETUP SUBMISSION
	// ==========================================
	if ( isset( $_POST['mtl_run_db_setup'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_db_nonce'] ) && wp_verify_nonce( $_POST['mtl_db_nonce'], 'mtl_run_db_action' ) ) {
			$sql_file_path = MTL_PLUGIN_DIR . 'admin/schema.sql';
			if ( file_exists( $sql_file_path ) ) {
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
				// the whole chunk -- including the real SQL. Inline trailing
				// comments (e.g. "-- 'Y' or 'N'") are left alone since MySQL parses those natively.
				$lines        = explode( "\n", $sql_contents );
				$lines        = array_filter(
					$lines,
					function ( $line ) {
						return strpos( trim( $line ), '--' ) !== 0;
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
					$result = $wpdb->query( $query );
					if ( $result === false ) {
						++$error_count;
						echo '<div style="background: #ffebe8; border: 1px solid #cc0000; padding: 10px; margin: 5px 0;">';
						echo '<strong>Failed Query:</strong> ' . esc_html( $query ) . '<br>';
						echo '<strong>DB Error:</strong> ' . esc_html( $wpdb->last_error );
						echo '</div>';
					} else {
						++$success_count;
					}
				}

				if ( $error_count === 0 ) {
					echo '<div class="notice notice-success is-dismissible"><p><strong>Database Setup Complete:</strong> Successfully reset tables and executed ' . intval( $success_count ) . ' queries.</p></div>';
				} else {
					echo '<div class="notice notice-warning is-dismissible"><p><strong>Database Setup Finished with Errors:</strong> ' . intval( $success_count ) . ' queries succeeded, but ' . intval( $error_count ) . ' encountered errors.</p></div>';
				}
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Could not find <code>schema.sql</code>.</p></div>';
			}
		}
	}

	$org_name      = get_option( 'mtl_org_name', '' );
	$contact_email = get_option( 'mtl_contact_email', get_option( 'admin_email' ) );
	$currency      = get_option( 'mtl_currency_symbol', '$' );
	$logo_url      = get_option( 'mtl_logo_url', '' );

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
	$radius       = get_option( 'mtl_border_radius', '4px' );
	$btn_scale    = get_option( 'mtl_button_scale', '1' );

	$default_loan_days       = get_option( 'mtl_default_loan_days', '21' );
	$pickup_directions       = get_option(
		'mtl_pickup_directions',
		'Placing a reservation holds your spot in line and speeds up the process of checking out tools. If no one is waiting in line to borrow a tool, no reservation is required. Come by our store and speak with a representative to take tools home.'
	);
	$verification_directions = get_option(
		'mtl_verification_directions',
		'A government issued ID and proof of address are required to become a verified member and to check out tools. Stop by our office to verify membership.'
	);

	// Shown as chips next to the "add new" mini-forms below.
	$categories = $wpdb->get_results( "SELECT category_id, category_name FROM {$tbl_categories} ORDER BY category_name ASC" );
	$tags       = $wpdb->get_results( "SELECT tag_id, tag_name FROM {$tbl_tags} ORDER BY tag_name ASC" );

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

		.mtl-public-link-item > label {
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

		/* Editable setting, unlike the readonly Public Page Link -- plain
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

	<div style="display: flex; gap: 20px; margin-top: 20px; flex-wrap: wrap;">

		<!-- General Customization Settings -->
		<div style="flex: 1; min-width: 450px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
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
						<th scope="row"><label for="mtl_contact_email">Public Contact Email</label></th>
						<td><input type="email" name="mtl_contact_email" id="mtl_contact_email" class="regular-text" value="<?php echo esc_attr( $contact_email ); ?>"></td>
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

		<!-- Categories & Tags Management -->
		<div style="flex: 1; min-width: 400px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); height: fit-content;">
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

		<!-- Database Setup Tool -->
		<div style="flex: 1; min-width: 400px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); height: fit-content;">
			<h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; color: #d63638;">Database Configuration</h3>

			<p>Use this tool to initialize the required tables in your WordPress database. This process will read from the <code>schema.sql</code> file located in the plugin's <code>admin/</code> folder.</p>

			<div style="background: #fdf2f2; border-left: 4px solid #d63638; padding: 12px; margin-bottom: 20px;">
				<strong>Warning:</strong> Running this will execute all queries in the SQL file. If your SQL file contains <code>DROP TABLE</code> commands, it will completely wipe existing inventory data.
			</div>

			<form method="post" action="" onsubmit="return confirm('Are you sure you want to execute the database schema? This action cannot be undone.');">
				<?php wp_nonce_field( 'mtl_run_db_action', 'mtl_db_nonce' ); ?>
				<label class="mtl-lock-toggle">
					<input type="checkbox" required>
					<span class="mtl-lock-slider"></span>
					<span class="mtl-lock-label">Slide to unlock &mdash; I understand this will erase existing data</span>
				</label>
				<p class="submit">
					<input type="submit" name="mtl_run_db_setup" class="button button-secondary mtl-danger-btn" value="Run Database Setup">
				</p>
			</form>
		</div>

		<!-- Export Data -->
		<div style="flex: 1; min-width: 400px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); height: fit-content;">
			<h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Export Data</h3>

			<p>Download a complete copy of all My Tool Library data &mdash; members, verifications, inventory, categories, tags, loans and reservations.</p>

			<ul style="font-size: 0.85em; color: #666; margin: 0 0 15px 20px;">
				<li><strong>.sql dump</strong> &mdash; a single SQL file (DROP + CREATE + INSERT) you can import into any MySQL/MariaDB database. Table names <strong>keep</strong> the <code><?php echo esc_html( $wpdb->prefix ); ?></code> prefix (e.g. <code><?php echo esc_html( $wpdb->prefix ); ?>members</code>), matching how the plugin creates them.</li>
				<li><strong>.zip of CSVs</strong> &mdash; one <code>.csv</code> file per table, named after the table without the prefix (e.g. <code>members.csv</code>), handy for spreadsheets.</li>
			</ul>

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

	<script>
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
	<?php
	echo '</div>';
}
