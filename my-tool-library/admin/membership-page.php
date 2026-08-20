<?php
/**
 * Membership admin page.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', 'mtl_maybe_serve_member_csv_template' );

/**
 * Records agreements in bulk from the uploaded CSV.
 *
 * REFUSES THE WHOLE FILE ON ANY PROBLEM. These rows are permanent and there is
 * no un-agree, so importing the readable part of a file somebody edited by hand
 * would leave staff unable to say what was recorded and unable to undo it.
 *
 * A row is skipped, not refused, when the member is already current on that
 * agreement. That also makes re-uploading the same file safe, which is what
 * recovers a run that hit the time budget.
 *
 * @return string Admin-notice HTML.
 */
function mtl_handle_bulk_agree_upload() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verifies mtl_bulk_agree_nonce before calling this.
	$phrase = isset( $_POST['mtl_bulk_agree_phrase'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['mtl_bulk_agree_phrase'] ) ) ) : '';
	if ( 0 !== strcasecmp( $phrase, 'Record these agreements' ) ) {
		return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was recorded.</strong> Type <code>Record these agreements</code> to confirm.</p></div>';
	}

	// Taken RAW, like the two CSV importers on this page and on Inventory.
	// WordPress never slashes $_FILES, and wp_unslash() on a Windows temp path
	// strips its separators: C:\Windows\Temp\php1234.tmp becomes
	// C:WindowsTempphp1234.tmp, which then fails to open. is_uploaded_file() is
	// what proves the path, and it is the only check that can.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce as above; a filesystem path, validated by is_uploaded_file() on the next line.
	$tmp_name = isset( $_FILES['mtl_bulk_agree_file']['tmp_name'] ) ? $_FILES['mtl_bulk_agree_file']['tmp_name'] : '';
	if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
		return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was recorded.</strong> Choose a CSV file to upload.</p></div>';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	return mtl_bulk_agree_process( $tmp_name, isset( $_POST['mtl_bulk_agree_email'] ) );
}

/**
 * Parses a bulk-agree file and writes what it asks for.
 *
 * Separate from the request handling above so the rules can be exercised
 * against a file on disk, which is_uploaded_file() makes impossible otherwise.
 *
 * @param string $path       Readable CSV path.
 * @param bool   $send_email Email each member a copy of what was recorded.
 * @return string Admin-notice HTML.
 */
function mtl_bulk_agree_process( $path, $send_email = false ) {
	global $wpdb;

	$handle = fopen( $path, 'r' );
	if ( ! $handle ) {
		return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was recorded.</strong> That file could not be read.</p></div>';
	}

	$headers = fgetcsv( $handle );
	if ( ! $headers ) {
		fclose( $handle );
		return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was recorded.</strong> That file is empty.</p></div>';
	}
	// A byte-order mark on the first heading is Excel's parting gift.
	$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );

	$live = array();
	foreach ( mtl_get_active_agreements() as $agreement ) {
		$live[ (int) $agreement->agreement_id ] = $agreement;
	}

	// Which column holds which agreement, and proof the file still matches.
	$columns  = array();
	$id_index = array_search( 'member_id', array_map( 'trim', $headers ), true );
	foreach ( $headers as $index => $heading ) {
		$parsed = mtl_parse_bulk_agree_heading( $heading );
		if ( null === $parsed ) {
			continue;
		}
		if ( ! isset( $live[ $parsed['id'] ] ) ) {
			fclose( $handle );
			return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was recorded.</strong> This file has a column for an agreement that is no longer active. Download a fresh copy and start again.</p></div>';
		}
		if ( (int) $live[ $parsed['id'] ]->version_num !== $parsed['version'] ) {
			fclose( $handle );
			return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was recorded.</strong> An agreement was revised after this file was downloaded, so its wording no longer matches. Download a fresh copy and start again.</p></div>';
		}
		$columns[ $index ] = $live[ $parsed['id'] ];
	}

	if ( false === $id_index || ! $columns ) {
		fclose( $handle );
		return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was recorded.</strong> This does not look like a member agreements file. It needs the <code>member_id</code> column and at least one agreement column, with their headings unchanged.</p></div>';
	}

	$tbl_members = $wpdb->prefix . 'members';
	$today       = current_time( 'Y-m-d' );
	$planned     = array();
	$errors      = array();
	$row_no      = 1;

	while ( true ) {
		$row = fgetcsv( $handle );
		if ( false === $row ) {
			break;
		}
		++$row_no;
		if ( 1 === count( $row ) && ( null === $row[0] || '' === trim( (string) $row[0] ) ) ) {
			continue;
		}

		$member_id = isset( $row[ $id_index ] ) ? (int) trim( (string) $row[ $id_index ] ) : 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
		$member = $member_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT member_id, anonymized_at FROM {$tbl_members} WHERE member_id = %d", $member_id ) ) : null;
		if ( ! $member ) {
			$errors[] = sprintf( 'Row %1$d: no member with id %2$s.', $row_no, esc_html( (string) $member_id ) );
			continue;
		}
		if ( ! empty( $member->anonymized_at ) ) {
			$errors[] = sprintf( 'Row %d: that member has been deleted.', $row_no );
			continue;
		}

		foreach ( $columns as $index => $agreement ) {
			$cell = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
			if ( '' === $cell ) {
				continue;
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $cell ) || ! wp_checkdate( (int) substr( $cell, 5, 2 ), (int) substr( $cell, 8, 2 ), (int) substr( $cell, 0, 4 ), $cell ) ) {
				$errors[] = sprintf( 'Row %1$d: &ldquo;%2$s&rdquo; is not a date. Use YYYY-MM-DD, or leave the cell empty.', $row_no, esc_html( $cell ) );
				continue;
			}
			if ( $cell > $today ) {
				$errors[] = sprintf( 'Row %1$d: %2$s is in the future.', $row_no, esc_html( $cell ) );
				continue;
			}
			// Nobody can have agreed to wording that did not exist yet.
			//
			// Compared in LOCAL dates on both sides. version_published_at is
			// UTC, so slicing its date off directly would read midnight UTC as
			// the 15th while the library was still on the 14th, and refuse a
			// form signed the day the agreement actually went live.
			$published = get_date_from_gmt( (string) $agreement->version_published_at, 'Y-m-d' );
			if ( $cell < $published ) {
				$errors[] = sprintf(
					'Row %1$d: %2$s is before this version was published (%3$s).',
					$row_no,
					esc_html( $cell ),
					esc_html( $published )
				);
				continue;
			}

			$planned[] = array(
				'member'    => $member_id,
				'agreement' => (int) $agreement->agreement_id,
				'version'   => (int) $agreement->version_num,
				// Start of that day where the library is, stored as UTC, so it
				// reads back as the date on the paper rather than the one before.
				'at'        => get_gmt_from_date( $cell . ' 00:00:00' ),
			);
		}
	}
	fclose( $handle );

	if ( $errors ) {
		return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was recorded.</strong> Fix the file and upload it again.</p><ul style="list-style: disc; margin-left: 20px;"><li>'
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled above from literals and esc_html()'d values.
			. implode( '</li><li>', array_slice( $errors, 0, 25 ) ) . '</li></ul>'
			. ( count( $errors ) > 25 ? '<p>' . esc_html( sprintf( 'and %d more.', count( $errors ) - 25 ) ) . '</p>' : '' )
			. '</div>';
	}

	if ( ! $planned ) {
		return '<div class="notice notice-warning is-dismissible"><p><strong>Nothing was recorded.</strong> No dates were filled in.</p></div>';
	}

	$written   = 0;
	$skipped   = 0;
	$by_member = array();
	$started   = microtime( true );
	$ran_out   = false;

	$outstanding_for = array();
	foreach ( $planned as $item ) {
		if ( microtime( true ) - $started > 20 ) {
			$ran_out = true;
			break;
		}

		// Re-read per member the first time they come up, so a file listing the
		// same member twice cannot record them twice.
		if ( ! isset( $outstanding_for[ $item['member'] ] ) ) {
			$outstanding_for[ $item['member'] ] = array_map(
				'intval',
				wp_list_pluck( mtl_member_outstanding_agreements( $item['member'] ), 'agreement_id' )
			);
		}
		// Not outstanding means they are already current on it.
		if ( ! in_array( $item['agreement'], $outstanding_for[ $item['member'] ], true ) ) {
			++$skipped;
			continue;
		}
		$acceptance_id = mtl_record_agreement_acceptance( $item['member'], $item['agreement'], 'staff_edit', $item['version'], $item['at'] );
		if ( $acceptance_id > 0 ) {
			++$written;
			$by_member[ $item['member'] ][]     = $acceptance_id;
			$outstanding_for[ $item['member'] ] = array_diff( $outstanding_for[ $item['member'] ], array( $item['agreement'] ) );
		} else {
			++$skipped;
		}
	}

	// Mail shares the same budget as the writing. A send is an SMTP round trip,
	// so a few hundred of them will outrun a request on their own, and anyone
	// left unmailed is reported rather than silently dropped.
	$emailed   = 0;
	$unemailed = 0;
	if ( $send_email ) {
		foreach ( $by_member as $member_id => $ids ) {
			if ( microtime( true ) - $started > 20 ) {
				$unemailed = count( $by_member ) - $emailed;
				break;
			}
			if ( mtl_send_agreement_confirmation_email( $member_id, $ids ) ) {
				++$emailed;
			}
		}
	}

	$notice = '<div class="notice notice-success is-dismissible"><p><strong>'
		. esc_html( sprintf( '%1$d recorded for %2$d member(s).', $written, count( $by_member ) ) ) . '</strong>';
	if ( $skipped > 0 ) {
		$notice .= ' ' . esc_html( sprintf( '%d already on record, so skipped.', $skipped ) );
	}
	if ( $emailed > 0 ) {
		$notice .= ' ' . esc_html( sprintf( '%d member(s) emailed their copy.', $emailed ) );
	}
	if ( $unemailed > 0 ) {
		$notice .= ' ' . esc_html( sprintf( '%d could not be emailed in time; their agreements are recorded either way.', $unemailed ) );
	}
	if ( $ran_out ) {
		$notice .= ' <strong>The file was too long to finish in one go.</strong> Upload the same file again to continue; anything already recorded will be skipped.';
	}

	return $notice . '</p></div>';
}

/**
 * Column heading for one agreement in the bulk-agree CSV: "12: Waiver... (v3)".
 *
 * Carries the agreement_id and the version so the upload can prove the file
 * still describes what it described when it was downloaded. The text between
 * them is for the person filling it in and is never read back.
 *
 * @param object $agreement Row from member_agreements.
 * @return string
 */
function mtl_bulk_agree_heading( $agreement ) {
	// Entities decoded: mtl_agreement_excerpt() ends a truncation with &hellip;,
	// which a spreadsheet shows literally rather than as an ellipsis.
	$excerpt = html_entity_decode( wp_strip_all_tags( mtl_agreement_excerpt( $agreement->agreement_text ) ), ENT_QUOTES, 'UTF-8' );
	$excerpt = str_replace( array( "\r", "\n" ), ' ', $excerpt );

	return (int) $agreement->agreement_id . ': ' . $excerpt . ' (v' . (int) $agreement->version_num . ')';
}

/**
 * Reads a bulk-agree column heading back into the ids it encodes.
 *
 * The version is matched greedily so wording containing its own "(v2)" cannot
 * shadow the real marker at the end.
 *
 * @param string $heading Column heading from the uploaded file.
 * @return array{id:int, version:int}|null Null when the heading is not one of ours.
 */
function mtl_parse_bulk_agree_heading( $heading ) {
	if ( ! preg_match( '/^(\d+):.*\(v(\d+)\)\s*$/', trim( (string) $heading ), $m ) ) {
		return null;
	}

	return array(
		'id'      => (int) $m[1],
		'version' => (int) $m[2],
	);
}

/**
 * Streams the bulk-agree CSV: one row per member, one column per active
 * agreement, for staff to date from the signed paper.
 *
 * Administrators only, and only where staff may attest at all. Anonymized
 * members are left out: recording a fresh attestation against a placeholder
 * name would be recording nothing about nobody.
 *
 * @return void
 */
function mtl_maybe_serve_bulk_agree_csv() {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if (
		! isset( $_GET['mtl_download_bulk_agree'] ) || 'mtl-membership' !== $page ||
		! mtl_can_manage_settings() || ! mtl_agreements_staff_recording()
	) {
		return;
	}
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mtl_bulk_agree_action' ) ) {
		return;
	}

	global $wpdb;
	$agreements = mtl_get_active_agreements();
	if ( ! $agreements ) {
		return;
	}

	$everyone = isset( $_GET['audience'] ) && 'all' === sanitize_key( wp_unslash( $_GET['audience'] ) );

	$tbl_members = $wpdb->prefix . 'members';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$members = $wpdb->get_results( "SELECT member_id, first_name, last_name, email FROM {$tbl_members} WHERE anonymized_at IS NULL ORDER BY last_name ASC, first_name ASC" );

	// One batched query rather than one per member: a library with a couple of
	// thousand members would otherwise spend the whole request on lookups.
	$owes = array();
	if ( ! $everyone ) {
		foreach ( mtl_member_agreements_status_map( wp_list_pluck( $members, 'member_id' ) ) as $id => $status ) {
			$owes[ (int) $id ] = in_array( $status, array( 'none', 'outdated' ), true );
		}
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="member-agreements-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$out     = fopen( 'php://output', 'w' );
	$headers = array( 'member_id', 'first_name', 'last_name', 'email' );
	foreach ( $agreements as $agreement ) {
		$headers[] = mtl_bulk_agree_heading( $agreement );
	}
	fputcsv( $out, $headers );

	foreach ( $members as $member ) {
		$member_id = (int) $member->member_id;
		if ( ! $everyone && empty( $owes[ $member_id ] ) ) {
			continue;
		}

		$row = array(
			$member_id,
			stripslashes( (string) $member->first_name ),
			stripslashes( (string) $member->last_name ),
			(string) $member->email,
		);
		// Blank cells throughout. A pre-ticked file is a file somebody uploads
		// without reading, and every row here is a permanent attestation.
		foreach ( $agreements as $unused ) {
			$row[] = '';
		}
		fputcsv( $out, $row );
	}

	fclose( $out );
	exit;
}
add_action( 'admin_init', 'mtl_maybe_serve_bulk_agree_csv' );

/**
 * Serves a downloadable CSV template for the Membership Bulk Import feature.
 * Runs on admin_init (before any HTML) so it can send download headers, the
 * same way the inventory template download works.
 */
function mtl_maybe_serve_member_csv_template() {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if (
		! isset( $_GET['mtl_download_member_template'] ) || '' === $page ||
		'mtl-membership' !== $page ||
		! mtl_can_bulk_import()
	) {
		return;
	}

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mtl_download_member_template_action' ) ) {
		return;
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="member-import-template.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv(
		$out,
		array(
			'first_name',
			'last_name',
			'email',
			'phone_number',
			'address_line1',
			'address_line2',
			'city',
			'state',
			'zip_code',
			'country',
			'signup_date',
			'recurring_donation_amount',
			'has_donated_tools',
			'photo_id_scan_url',
			'address_proof_scan_url',
			'private_notes',
			'trainings',
		)
	);
	// One clearly-fake example row so admins can see the expected format at a
	// glance, so delete/overwrite it before uploading real member data.
	fputcsv(
		$out,
		array(
			'Example',
			'Member - Delete This Row',
			'example.member@example.com',
			'(555) 555-0100',
			'123 Example St',
			'',
			'Milwaukee',
			'WI',
			'53202',
			'United States',
			// The importer accepts any date string PHP's strtotime() understands
			// (see the normalization a few lines below), but the template models
			// the site-wide MM/DD/YYYY convention.
			gmdate( 'm/d/Y' ),
			'10.00',
			'N',
			'https://example.com/scans/photo-id.jpg',
			'https://example.com/scans/proof-of-address.pdf',
			'',
			// "Name: completion date" pairs, semicolon-separated. Names are
			// matched against the trainings set up on the Setup page (see the
			// importer's lookup below); the date is when that member completed
			// it, which is what their certification length runs from.
			'Ladder Safety: ' . gmdate( 'n/j/Y' ) . '; Welding Basics: ' . gmdate( 'n/j/Y' ),
		)
	);
	fclose( $out );
	exit;
}

add_action( 'admin_init', 'mtl_maybe_serve_agreement_record' );

/**
 * Serves one member's complete agreement history as a printable page.
 *
 * This is the one screen the feature exists to produce. Everything else is
 * about CAPTURING agreement; without this, the answer to "show me what this
 * member agreed to, in a form I can hand to a lawyer" is "ask an administrator
 * to export the database and open it in a spreadsheet".
 *
 * Administrators only, because assembling one person's complete history into a
 * portable file is a different act from reading a status on screen, and belongs
 * with whoever already controls the data exports. Editors see the agreement
 * block and the badges but not this.
 *
 * Runs on admin_init, before any HTML, so it can own the whole response.
 *
 * IT WRITES NOTHING. No acceptance row, no user meta, no status change.
 * Producing evidence must never alter it.
 */
function mtl_maybe_serve_agreement_record() {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( ! isset( $_GET['mtl_agreement_record'] ) || 'mtl-membership' !== $page || ! mtl_can_manage_settings() ) {
		return;
	}
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mtl_agreement_record_action' ) ) {
		return;
	}

	$member_id = absint( $_GET['mtl_agreement_record'] );
	if ( $member_id <= 0 ) {
		return;
	}

	global $wpdb;
	$members = $wpdb->prefix . 'members';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix.
	$member  = $wpdb->get_row( $wpdb->prepare( "SELECT member_id, first_name, last_name, email, anonymized_at FROM {$members} WHERE member_id = %d", $member_id ) );
	$history = mtl_get_member_acceptance_history( $member_id );

	$org_name = get_option( 'mtl_org_name', '' );
	if ( '' === trim( (string) $org_name ) ) {
		$org_name = 'My Tool Library';
	}

	// The identity shown is the SNAPSHOT from the acceptance rows, not the live
	// member row, so it is correct for somebody who has since changed their
	// name, and it still works for a deleted member. That is the reason the
	// identity is retained through deletion at all.
	$snapshot_name  = '';
	$snapshot_email = '';
	if ( $history ) {
		$latest         = end( $history );
		$snapshot_name  = (string) $latest->member_name;
		$snapshot_email = (string) $latest->member_email;
	}
	if ( '' === $snapshot_name && $member ) {
		$snapshot_name = trim( $member->first_name . ' ' . $member->last_name );
	}
	if ( '' === $snapshot_email && $member ) {
		$snapshot_email = (string) $member->email;
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Member agreement record: <?php echo esc_html( $snapshot_name ); ?></title>
	<style>
		/* Deliberately self-contained and free of the admin theme: this page is
			printed to PDF or saved, and must stay readable either way.

			color-scheme and an explicit background are both required. Without
			them a browser in dark mode paints its own dark ground behind the
			text colour set below, and the record comes out near-unreadable,
			on screen and in the PDF. */
		html { color-scheme: light; background: #fff; }
		body { background: #fff; font-family: Georgia, 'Times New Roman', serif; color: #111; max-width: 46em; margin: 2em auto; padding: 0 1.5em; line-height: 1.5; }
		h1 { font-size: 1.4em; margin: 0 0 0.2em 0; letter-spacing: 0.04em; text-transform: uppercase; }
		.mtl-rec-sub { color: #444; margin: 0 0 1.6em 0; font-size: 0.9em; }
		.mtl-rec-head { border-top: 2px solid #111; border-bottom: 2px solid #111; padding: 0.8em 0; margin-bottom: 1.6em; }
		.mtl-rec-block { border-top: 1px solid #999; padding-top: 0.9em; margin-top: 1.6em; page-break-inside: avoid; }
		.mtl-rec-block h2 { font-size: 1em; margin: 0 0 0.7em 0; letter-spacing: 0.03em; text-transform: uppercase; }
		dl { display: grid; grid-template-columns: 11em 1fr; gap: 0.35em 1em; margin: 0; }
		dt { font-weight: 700; }
		dd { margin: 0; }
		.mtl-rec-hash { font-family: 'Courier New', monospace; font-size: 0.85em; word-break: break-all; }
		.mtl-rec-url { font-size: 0.85em; word-break: break-all; }
		.mtl-rec-none { color: #444; font-style: italic; }
		.mtl-rec-print { margin-bottom: 1.5em; }
		@media print { .mtl-rec-print { display: none; } body { margin: 0; max-width: none; } }
	</style>
</head>
<body>
	<p class="mtl-rec-print"><button type="button" onclick="window.print();">Print or save as PDF</button></p>

	<h1>Member agreement record</h1>
	<p class="mtl-rec-sub">
		<?php echo esc_html( $org_name ); ?> &middot;
		generated <?php echo esc_html( gmdate( 'j F Y, H:i' ) ); ?> (UTC)
	</p>

	<div class="mtl-rec-head">
		<dl>
			<dt>Member</dt>
			<dd><?php echo '' !== $snapshot_name ? esc_html( $snapshot_name ) : '<span class="mtl-rec-none">not recorded</span>'; ?></dd>
			<dt>Email</dt>
			<dd><?php echo '' !== $snapshot_email ? esc_html( $snapshot_email ) : '<span class="mtl-rec-none">not recorded</span>'; ?></dd>
			<dt>Member ID</dt>
			<dd><?php echo esc_html( $member_id ); ?></dd>
			<?php if ( ! $member ) : ?>
				<dt>Status</dt>
				<dd>Member record deleted</dd>
			<?php elseif ( ! empty( $member->anonymized_at ) ) : ?>
				<dt>Status</dt>
				<dd>Personal data removed <?php echo wp_kses_post( mtl_format_date( $member->anonymized_at, 'j F Y' ) ); ?></dd>
			<?php endif; ?>
		</dl>
	</div>

	<?php if ( ! $history ) : ?>
		<p class="mtl-rec-none">No agreements are on record for this member.</p>
	<?php else : ?>
		<?php foreach ( $history as $row ) : ?>
			<?php
			// accepted_context written out in full prose rather than as the
			// stored token, because this page is read by people who have never
			// seen the schema.
			$how       = array(
				'signup'     => 'The member ticked the box themselves, while creating their account',
				'agree_page' => 'The member ticked the box themselves, on their account page',
				'staff_add'  => 'Recorded by library staff against a signed paper copy, while adding the member',
				'staff_edit' => 'Recorded by library staff against a signed paper copy, after the member already existed',
			);
			$how_text  = isset( $how[ $row->accepted_context ] ) ? $how[ $row->accepted_context ] : $row->accepted_context;
			$file_name = ! empty( $row->file_url ) ? basename( wp_parse_url( $row->file_url, PHP_URL_PATH ) ) : '';

			// Resolved at render, never stored: staff turnover outlasts these
			// rows by design, so a deleted account degrades to its number
			// rather than to a blank.
			$actor = '';
			if ( ! empty( $row->acted_by ) ) {
				$actor_user = get_userdata( (int) $row->acted_by );
				$actor      = $actor_user
					? sprintf( '%s (WordPress user %d)', $actor_user->display_name, (int) $row->acted_by )
					: sprintf( 'a former staff account (#%d)', (int) $row->acted_by );
			}
			?>
			<div class="mtl-rec-block">
				<h2>Agreement <?php echo esc_html( (int) $row->agreement_id ); ?> &middot; version <?php echo esc_html( (int) $row->agreement_version_num ); ?></h2>
				<dl>
					<dt>Agreed</dt>
					<dd>
						<?php echo esc_html( $row->accepted_at ); ?> UTC
						(<?php echo wp_kses_post( mtl_format_utc_datetime( $row->accepted_at, 'j F Y, g:i a' ) ); ?> local)
					</dd>

					<dt>How</dt>
					<dd><?php echo esc_html( $how_text ); ?></dd>

					<?php if ( '' !== $actor ) : ?>
						<?php // Never printed empty: its presence always means somebody acted on the member's behalf. ?>
						<dt>Recorded by</dt>
						<dd><?php echo esc_html( $actor ); ?></dd>
					<?php endif; ?>

					<dt>They were shown</dt>
					<dd>&ldquo;<?php echo nl2br( esc_html( $row->agreement_text ) ); ?>&rdquo;</dd>

					<dt>They were told</dt>
					<dd>&ldquo;<?php echo esc_html( $row->assent_text ); ?>&rdquo;</dd>

					<dt>Version</dt>
					<dd>
						<?php echo esc_html( (int) $row->agreement_version_num ); ?>,
						in use since <?php echo wp_kses_post( mtl_format_utc_datetime( $row->agreement_version_published_at, 'j F Y' ) ); ?>
					</dd>

					<dt>Attached file</dt>
					<dd>
						<?php if ( '' === $file_name ) : ?>
							None
						<?php else : ?>
							<?php echo esc_html( $file_name ); ?><br>
							<span class="mtl-rec-url"><?php echo esc_html( $row->file_url ); ?></span>
							<?php if ( ! empty( $row->file_sha256 ) ) : ?>
								<?php // Printed in full, not truncated as on the detail panel: this is the one context where somebody will actually compare it. ?>
								<br><span class="mtl-rec-hash">SHA-256 <?php echo esc_html( $row->file_sha256 ); ?></span>
							<?php else : ?>
								<br><span class="mtl-rec-none">No fingerprint was recorded for this file.</span>
							<?php endif; ?>
						<?php endif; ?>
					</dd>
				</dl>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</body>
</html>
	<?php
	exit;
}

/**
 * The Record Agreement form: one markup, rendered in two containers.
 *
 * With scripting it goes in a native <dialog>; without, it renders inline on
 * the detail panel from the `?mtl_record_agreement=<id>` link. Not two
 * implementations. A staff member who cannot open the dialog would otherwise
 * have NO way at all to record a signature, and this is the screen where the
 * plugin's progressive-enhancement stance is load-bearing.
 *
 * There is no per-checkbox error state, because there is no way to fail one:
 * ticking nothing closes without writing, and a revision under the form
 * rejects the whole submission via $changed rather than any single agreement.
 *
 * @param object   $member      Member row.
 * @param object[] $acceptances Latest acceptance per agreement for this member.
 * @param bool     $changed     Whether an agreement was revised mid-form.
 * @return string HTML.
 */
function mtl_render_record_agreement_form( $member, $acceptances, $changed = false ) {
	$member_id   = (int) $member->member_id;
	$outstanding = mtl_member_outstanding_agreements( $member_id );
	$by_id       = array();
	foreach ( $acceptances as $acceptance ) {
		$by_id[ (int) $acceptance->agreement_id ] = $acceptance;
	}
	$outstanding_ids = array_map( 'intval', wp_list_pluck( $outstanding, 'agreement_id' ) );
	$full_name       = trim( $member->first_name . ' ' . $member->last_name );

	ob_start();
	?>
	<form method="post" class="mtl-record-agreement-form">
		<?php wp_nonce_field( 'mtl_record_agreement_action', 'mtl_record_agreement_nonce' ); ?>
		<input type="hidden" name="agreement_member_id" value="<?php echo esc_attr( $member_id ); ?>">

		<h3 id="mtl-record-title-<?php echo esc_attr( $member_id ); ?>" style="margin-top:0;">
			Record agreement &middot; <?php echo esc_html( $full_name ); ?>
		</h3>

		<?php // First and unmissable, before the checkboxes in DOM order, and not dismissible. Staff are about to assert something on another person's behalf into a record that can never be deleted. ?>
		<div class="notice notice-warning inline" style="margin: 0 0 14px 0;">
			<p style="margin: 6px 0;">
				<strong>&#9888; Only tick these once you have the member&rsquo;s signed form in hand.</strong>
				You are recording that this person agreed, and that record is permanent and cannot be removed or corrected afterwards.
			</p>
		</div>

		<?php if ( $changed ) : ?>
			<div class="notice notice-error inline" style="margin: 0 0 14px 0;" role="alert" tabindex="-1">
				<p>Nothing was recorded. One or more of these agreements was revised while this was open, so the wording below has changed. Please read the new wording before recording anything.</p>
			</div>
		<?php endif; ?>

		<?php
		$already = array();
		foreach ( $acceptances as $acceptance ) {
			if ( ! in_array( (int) $acceptance->agreement_id, $outstanding_ids, true ) ) {
				$already[] = $acceptance;
			}
		}
		?>

		<?php if ( $already ) : ?>
			<fieldset class="mtl-record-group">
				<legend>Already on record</legend>
				<?php foreach ( $already as $acceptance ) : ?>
					<?php // Read-only, no checkbox: a member already current on an agreement cannot re-record it, because a duplicate row would say nothing new. ?>
					<div class="mtl-record-item">
						<p style="margin: 0;">&#10003; <?php echo nl2br( esc_html( $acceptance->agreement_text ) ); ?></p>
						<p class="mtl-record-meta">
							v<?php echo esc_html( (int) $acceptance->agreement_version_num ); ?>
							&middot; agreed <?php echo wp_kses_post( mtl_format_utc_datetime( $acceptance->accepted_at, 'j M Y' ) ); ?>
							&middot; <?php echo mtl_acceptance_is_staff( $acceptance->accepted_context ) ? 'by staff' : 'by the member'; ?>
						</p>
					</div>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>

		<?php if ( ! $outstanding ) : ?>
			<p style="color:#666;">This member is up to date on every agreement. There is nothing to record.</p>
		<?php else : ?>
			<fieldset class="mtl-record-group">
				<legend>Not yet recorded</legend>
				<?php foreach ( $outstanding as $agreement ) : ?>
					<?php
					$aid      = (int) $agreement->agreement_id;
					$box_id   = 'mtl-rec-' . $member_id . '-' . $aid;
					$file_url = (int) $agreement->attachment_id > 0 ? wp_get_attachment_url( (int) $agreement->attachment_id ) : '';
					$prior    = isset( $by_id[ $aid ] ) ? $by_id[ $aid ] : null;
					?>
					<div class="mtl-record-item">
						<label for="<?php echo esc_attr( $box_id ); ?>" class="mtl-record-label">
							<input type="checkbox" id="<?php echo esc_attr( $box_id ); ?>" name="agreements[<?php echo esc_attr( $aid ); ?>]" value="1" class="mtl-record-box" data-member="<?php echo esc_attr( $member_id ); ?>">
							<span><?php echo nl2br( esc_html( $agreement->agreement_text ) ); ?></span>
						</label>
						<p class="mtl-record-meta">
							v<?php echo esc_html( (int) $agreement->version_num ); ?>
							<?php if ( $prior ) : ?>
								<?php // So staff can see this is a re-signing rather than a first. ?>
								&middot; previously agreed to v<?php echo esc_html( (int) $prior->agreement_version_num ); ?>
								on <?php echo wp_kses_post( mtl_format_utc_datetime( $prior->accepted_at, 'j M Y' ) ); ?>
							<?php endif; ?>
							<?php if ( $file_url ) : ?>
								&middot; <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener noreferrer"
									aria-label="<?php echo esc_attr( 'View the attached document for &ldquo;' . wp_strip_all_tags( mtl_agreement_excerpt( $agreement->agreement_text ) ) . '&rdquo; (opens in a new tab)' ); ?>">View the attached document &#8599;</a>
							<?php endif; ?>
						</p>
						<input type="hidden" name="agreement_versions[<?php echo esc_attr( $aid ); ?>]" value="<?php echo esc_attr( (int) $agreement->version_num ); ?>">
					</div>
				<?php endforeach; ?>
			</fieldset>

			<?php // The sentence staff are attesting to, shown before they attest to it. Same string the writer stores. ?>
			<p class="mtl-record-assent"><?php echo esc_html( mtl_assent_language( 'staff_edit' ) ); ?></p>

			<p>
				<label>
					<input type="checkbox" name="mtl_send_agreement_copy" value="1" checked>
					<?php esc_html_e( 'Email the member their copy of what was recorded', 'my-tool-library' ); ?>
				</label>
			</p>
		<?php endif; ?>

		<p style="margin-bottom:0;">
			<a class="button" href="<?php echo esc_url( remove_query_arg( 'mtl_record_agreement' ) ); ?>#mtl-detail-<?php echo esc_attr( $member_id ); ?>">Cancel</a>
			<?php if ( $outstanding ) : ?>
				<button type="submit" name="mtl_record_agreement" value="1" class="button button-primary">Record</button>
			<?php endif; ?>
		</p>
	</form>
	<?php
	return ob_get_clean();
}
/**
 * Reads the trainings picker's posted fields into a training_id => start_date
 * map. Shared by the Add and Edit handlers so the two can't drift.
 *
 * The picker posts training_id[] (ticked ids) alongside training_start[<id>]
 * (one date per row, including rows that were never ticked). Only a ticked
 * id's date is read, so a date left over from un-ticking a box is ignored
 * rather than silently recording a training the member doesn't hold.
 *
 * A ticked training with a missing or unparseable date falls back to today
 * rather than failing the whole save: start_date is NOT NULL, and a
 * best-guess date the admin can correct beats rejecting an otherwise good
 * member record over one blank field.
 *
 * @return array<int,string> training_id => start_date (Y-m-d).
 */
function mtl_read_posted_training_starts() {
	// Both sniffs in ONE directive, comma-separated. Two phpcs:disable lines
	// stacked on consecutive lines silently breaks phpcs's ignore tracking for
	// the remainder of the file -- unrelated phpcs:ignore comments hundreds of
	// lines below stop being honored.
	// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- callers verify their own form nonce before calling this; every date is sanitized in the closure below and then re-validated against a strict Y-m-d pattern, which the sniff cannot see through.
	$ids = isset( $_POST['training_id'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['training_id'] ) ) : array();
	$raw = isset( $_POST['training_start'] ) && is_array( $_POST['training_start'] )
		? array_map(
			function ( $mtl_raw_date ) {
				// (string) first: a malformed request could nest an array
				// under one of the training ids, which sanitize_text_field()
				// is not guaranteed to survive.
				return sanitize_text_field( (string) $mtl_raw_date );
			},
			wp_unslash( $_POST['training_start'] )
		)
		: array();
	// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	$today = current_time( 'Y-m-d' );
	$out   = array();
	foreach ( $ids as $tid ) {
		$tid = (int) $tid;
		if ( $tid <= 0 ) {
			continue;
		}
		$date = isset( $raw[ $tid ] ) ? trim( $raw[ $tid ] ) : '';
		if ( '' === $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! strtotime( $date ) ) {
			$date = $today;
		}
		$out[ $tid ] = $date;
	}
	return $out;
}

/**
 * Writes a member's training mappings, replacing whatever was there.
 *
 * Clear-and-reinsert rather than diffing old against new: it is the same
 * approach the tool category/tag mappings use, and it correctly handles a
 * date changing on a training the member already held (which a pure
 * add/remove diff would miss entirely).
 *
 * @param int               $member_id       Member row ID.
 * @param array<int,string> $training_starts training_id => start_date (Y-m-d).
 */
function mtl_sync_member_trainings( $member_id, $training_starts ) {
	global $wpdb;
	$member_id = (int) $member_id;
	if ( $member_id <= 0 ) {
		return;
	}
	$tbl_training_map = $wpdb->prefix . 'member_training_mappings';

	$wpdb->delete( $tbl_training_map, array( 'member_id' => $member_id ), array( '%d' ) );
	foreach ( $training_starts as $tid => $start_date ) {
		$tid = (int) $tid;
		if ( $tid <= 0 ) {
			continue;
		}
		$wpdb->insert(
			$tbl_training_map,
			array(
				'member_id'   => $member_id,
				'training_id' => $tid,
				'start_date'  => $start_date,
			),
			array( '%d', '%d', '%s' )
		);
	}
}

/**
 * Renders the shared set of member fields used by both the "Add a New
 * Member" form and the "Edit Member" form, so the two stay in sync.
 * Mirrors mtl_render_tool_form_fields() on the Inventory page.
 *
 * @param array  $values      Field values keyed by field name.
 * @param array  $trainings   Available trainings (from the Setup page).
 * @param string $id_prefix   Prefix for element IDs (e.g. "edit_") so
 *                             <label for="..."> stays unique when both forms
 *                             are on the page at once.
 * @param bool   $offer_setup Show the "email them a setup link" tickbox. Only
 *                             the Add form passes true: Edit never creates an
 *                             account, so offering it there would promise
 *                             something that handler does not do.
 */
function mtl_render_member_form_fields( $values, $trainings, $id_prefix = '', $offer_setup = false ) {
	$field_id = function ( $name ) use ( $id_prefix ) {
		return esc_attr( $id_prefix . $name );
	};
	// $field_id() always returns esc_attr()-escaped output; phpcs can't see
	// through a closure assigned to a variable to verify that.
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'first_name' ); ?>">First Name *</label></th>
		<td>
			<input type="text" name="first_name" id="<?php echo $field_id( 'first_name' ); ?>" class="regular-text" maxlength="50" value="<?php echo esc_attr( $values['first_name'] ); ?>" required>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'last_name' ); ?>">Last Name *</label></th>
		<td>
			<input type="text" name="last_name" id="<?php echo $field_id( 'last_name' ); ?>" class="regular-text" maxlength="50" value="<?php echo esc_attr( $values['last_name'] ); ?>" required>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'email' ); ?>">Email *</label></th>
		<td>
			<input type="email" name="email" id="<?php echo $field_id( 'email' ); ?>" class="regular-text" maxlength="100" value="<?php echo esc_attr( $values['email'] ); ?>" required>
			<?php if ( $offer_setup ) : ?>
				<p style="font-size: 0.85em; color: #8a6d00; margin: 6px 0 0 0;"><strong>&#9888; Check the address before you save.</strong> It becomes their WordPress username and can never be changed.</p>
			<?php endif; ?>
		</td>
	</tr>
	<?php if ( $offer_setup ) : ?>
	<tr>
		<th scope="row">Online Account</th>
		<td>
			<label for="<?php echo $field_id( 'mtl_send_setup_email' ); ?>">
				<input type="checkbox" name="mtl_send_setup_email" id="<?php echo $field_id( 'mtl_send_setup_email' ); ?>" value="1" checked>
				Email them a link to choose their password
			</label>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">A website sign-in is created either way. Members can always change their password by clicking <em>"Lost your password?"</em> from the main page.</p>
		</td>
	</tr>
	<?php endif; ?>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'phone_national' ); ?>">Phone Number *</label></th>
		<td>
			<?php mtl_render_phone_input( $values['phone_country'], $values['phone_national'], $id_prefix ); ?>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Required. Pick the country, then type the number, which is formatted automatically.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'address_line1' ); ?>">Address Line 1 *</label></th>
		<td>
			<input type="text" name="address_line1" id="<?php echo $field_id( 'address_line1' ); ?>" style="width: 100%; max-width: 400px;" maxlength="255" value="<?php echo esc_attr( $values['address_line1'] ); ?>" required>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Required. Street address.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'address_line2' ); ?>">Address Line 2</label></th>
		<td>
			<input type="text" name="address_line2" id="<?php echo $field_id( 'address_line2' ); ?>" style="width: 100%; max-width: 400px;" maxlength="255" value="<?php echo esc_attr( $values['address_line2'] ); ?>">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Optional. Apartment, suite, unit, etc.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'city' ); ?>">City *</label></th>
		<td>
			<input type="text" name="city" id="<?php echo $field_id( 'city' ); ?>" class="regular-text" maxlength="100" value="<?php echo esc_attr( $values['city'] ); ?>" required>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'state' ); ?>">State / Province *</label></th>
		<td>
			<select name="state" id="<?php echo $field_id( 'state' ); ?>" required>
				<option value="">&mdash; Select &mdash;</option>
				<?php foreach ( mtl_get_state_options() as $mtl_state_code => $mtl_state_label ) : ?>
					<option value="<?php echo esc_attr( $mtl_state_code ); ?>" <?php selected( $values['state'], $mtl_state_code ); ?>><?php echo esc_html( $mtl_state_label ); ?> (<?php echo esc_html( $mtl_state_code ); ?>)</option>
				<?php endforeach; ?>
			</select>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Covers U.S. states/territories and Canadian provinces. Choose &ldquo;N/A&rdquo; for anywhere else.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'zip_code' ); ?>">ZIP Code *</label></th>
		<td>
			<input type="text" name="zip_code" id="<?php echo $field_id( 'zip_code' ); ?>" class="regular-text" maxlength="20" value="<?php echo esc_attr( $values['zip_code'] ); ?>" required>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'country' ); ?>">Country *</label></th>
		<td>
			<select name="country" id="<?php echo $field_id( 'country' ); ?>" required>
				<?php foreach ( mtl_get_country_options() as $mtl_country_name ) : ?>
					<option value="<?php echo esc_attr( $mtl_country_name ); ?>" <?php selected( $values['country'], $mtl_country_name ); ?>><?php echo esc_html( $mtl_country_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'signup_date' ); ?>">Signup Date</label></th>
		<td>
			<input type="date" name="signup_date" id="<?php echo $field_id( 'signup_date' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['signup_date'] ); ?>">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Defaults to today. Change it if the member signed up on a different date.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'recurring_donation_amount' ); ?>">Recurring Donation ($)</label></th>
		<td>
			<input type="number" step="0.01" min="0" name="recurring_donation_amount" id="<?php echo $field_id( 'recurring_donation_amount' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['recurring_donation_amount'] ); ?>" placeholder="0.00">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Monthly recurring donation amount, if the member has set one up. Leave blank or enter 0.00 if none.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'has_donated_tools' ); ?>">Has Donated Tools?</label></th>
		<td>
			<select name="has_donated_tools" id="<?php echo $field_id( 'has_donated_tools' ); ?>">
				<option value="N" <?php selected( $values['has_donated_tools'], 'N' ); ?>>No</option>
				<option value="Y" <?php selected( $values['has_donated_tools'], 'Y' ); ?>>Yes</option>
			</select>
		</td>
	</tr>
	<tr>
		<th scope="row">Trainings Completed</th>
		<td>
			<?php mtl_render_trainings_picker( $trainings, $values['training_starts'], $id_prefix ); ?>
			<?php if ( $trainings ) : ?>
				<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Tick every training this member has completed and set the date they completed it. That date is what the certification length runs from. It shows staff which tools they&rsquo;re qualified to use, and the member sees their own on their account page.</p>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'photo_id_scan_url' ); ?>">Photo ID Scan URL</label></th>
		<td>
			<input type="url" name="photo_id_scan_url" id="<?php echo $field_id( 'photo_id_scan_url' ); ?>" class="regular-text" maxlength="255" value="<?php echo esc_url( $values['photo_id_scan_url'] ); ?>" placeholder="https://...">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;"><strong>Sensitive.</strong> Link to the scan of the member&rsquo;s photo ID. Store scans in a private location, never a publicly listed folder. It&rsquo;s fine to save just this one if that&rsquo;s all the member has provided so far; provide BOTH this and the proof-of-address scan below to mark them verified.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'address_proof_scan_url' ); ?>">Proof of Address Scan URL</label></th>
		<td>
			<input type="url" name="address_proof_scan_url" id="<?php echo $field_id( 'address_proof_scan_url' ); ?>" class="regular-text" maxlength="255" value="<?php echo esc_url( $values['address_proof_scan_url'] ); ?>" placeholder="https://...">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;"><strong>Sensitive.</strong> Link to the scan of the member&rsquo;s proof of address (utility bill, lease, etc.). It&rsquo;s fine to save just this one for now; required together with the photo ID scan above to mark the member as verified.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'private_notes' ); ?>">Private Notes</label></th>
		<td>
			<textarea name="private_notes" id="<?php echo $field_id( 'private_notes' ); ?>" rows="4" style="width: 100%; max-width: 400px;"><?php echo esc_textarea( $values['private_notes'] ); ?></textarea>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Staff-only. Never shown on the public catalog, the member&rsquo;s account page, or anywhere else a member can see it.</p>
		</td>
	</tr>
	<?php
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Renders the Membership admin page.
 */
function mtl_render_membership_page() {
	global $wpdb;

	// Table names go through $wpdb->prefix so this plugin follows WordPress
	// table naming conventions and matches the {{prefix}} tables created by
	// schema.sql (see setup-page.php).
	$tbl_members       = $wpdb->prefix . 'members';
	$tbl_verifications = $wpdb->prefix . 'member_verifications';
	$tbl_loans         = $wpdb->prefix . 'loans';
	$tbl_inventory     = $wpdb->prefix . 'tool_inventory';
	$tbl_reservations  = $wpdb->prefix . 'tool_reservations';
	$tbl_trainings     = $wpdb->prefix . 'member_trainings';
	$tbl_training_map  = $wpdb->prefix . 'member_training_mappings';

	// Available trainings, shown as the tick-and-date picker on the Add/Edit
	// forms, as the Trainings filter's options, and matched by name during CSV
	// bulk import. certification_length_months comes along so the picker can
	// show each training's renewal period inline. Managed on the Setup page.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$trainings = $wpdb->get_results( "SELECT training_id, training_name, certification_length_months FROM {$tbl_trainings} ORDER BY training_name ASC" );

	// Every form on this page posts back to this exact (query-string-free)
	// URL rather than action="". That keeps an in-progress "?mtl_action=edit"
	// link from leaking into an unrelated Add/Delete submission.
	$base_url         = menu_page_url( 'mtl-membership', false );
	$csv_template_url = wp_nonce_url( add_query_arg( 'mtl_download_member_template', '1', $base_url ), 'mtl_download_member_template_action' );

	// Default due date for the reservation modal's "Start Loan" section,
	// matching the Setup page's configured default loan length (same
	// convention as Quick Loan on the Inventory page).
	$mtl_default_loan_days = (int) get_option( 'mtl_default_loan_days', 21 );
	$mtl_default_due_date  = gmdate( 'Y-m-d', strtotime( '+' . $mtl_default_loan_days . ' days' ) );

	// Set by the loan/reservation action handlers below when they succeed, so
	// the affected member's detail row can be reopened automatically after
	// the page reloads (this page has no PRG redirect; see the Add/Edit/
	// Delete handlers above/below, which follow the same plain-POST pattern).
	$reopen_member_id = 0;

	echo '<div class="wrap mtl-admin-wrapper">';
	echo '<h2>Membership Management</h2>';

	// Default values used to (re)populate the "Add a New Member" form. On a
	// fresh page load these stay empty; if a submission fails they are refilled
	// from the submitted data so the admin does not lose their work.
	// $keep_form_open forces the collapsible panel open after an error so the
	// preserved data is visible.
	$form_values    = array(
		'first_name'                => '',
		'last_name'                 => '',
		'email'                     => '',
		'phone_country'             => 'US',
		'phone_national'            => '',
		'address_line1'             => '',
		'address_line2'             => '',
		'city'                      => '',
		'state'                     => '',
		'zip_code'                  => '',
		'country'                   => 'United States',
		'signup_date'               => gmdate( 'Y-m-d' ),
		'recurring_donation_amount' => '',
		'has_donated_tools'         => 'N',
		'photo_id_scan_url'         => '',
		'address_proof_scan_url'    => '',
		'private_notes'             => '',
		// training_id => start_date (Y-m-d) for every training this member
		// holds; empty on a fresh Add form.
		'training_starts'           => array(),
	);
	$keep_form_open = false;

	// State for the "Edit Member" panel. It only renders when $editing is true
	// Either because a GET link ("Edit" in the table) asked to edit a
	// specific member, or because a submitted edit failed validation and needs
	// to be redisplayed with the admin's (invalid) input intact.
	$editing        = false;
	$edit_member_id = 0;
	$edit_values    = null;

	// State for the Record Agreement dialog and the per-member request action.
	// $record_agreement_id names the member whose dialog is open; the rest carry
	// a failed submit back into the re-render.
	$record_agreement_id      = isset( $_GET['mtl_record_agreement'] ) ? absint( $_GET['mtl_record_agreement'] ) : 0;
	$record_agreement_changed = false;

	// 0a. HANDLE "RECORD AGREEMENT" SUBMISSION
	//
	// Administrators and Editors, since this is desk work. Behaves identically in
	// paper and full mode: a library that collects signatures at the desk and
	// one that also lets members agree online record a desk signature in exactly
	// the same way, and switching modes never changes how staff do this job.
	if ( isset( $_POST['mtl_record_agreement'] ) && mtl_agreements_staff_recording() && mtl_can_manage_library() ) {
		if ( ! isset( $_POST['mtl_record_agreement_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_record_agreement_nonce'] ) ), 'mtl_record_agreement_action' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		} else {
			$target_id           = isset( $_POST['agreement_member_id'] ) ? absint( $_POST['agreement_member_id'] ) : 0;
			$posted              = mtl_read_agreements_post();
			$record_agreement_id = $target_id;
			$outstanding         = mtl_member_outstanding_agreements( $target_id );

			// Only ids this member actually owes are considered, so a forged
			// POST naming a retired agreement or one they are already current
			// on is rejected here rather than left to the writer's own checks
			// to happen to cover.
			$outstanding_ids = array_map( 'intval', wp_list_pluck( $outstanding, 'agreement_id' ) );
			$ticked          = array_values( array_intersect( $posted['ticked'], $outstanding_ids ) );

			$check = mtl_check_agreements_submission( $outstanding, $ticked, $posted['versions'] );

			if ( $check['stale'] || $check['added'] ) {
				// Staff must never attest to text that changed under them
				// mid-conversation. Nothing is written and the form re-renders
				// with the new wording, unticked.
				$record_agreement_changed = true;
			} elseif ( ! $ticked ) {
				// Ticking nothing and pressing Record is not an error. Staff
				// open this dialog to look as often as to act.
				$record_agreement_id = 0;
			} else {
				// Ids kept as written, not re-derived from the timestamp;
				// accepted_at has one-second resolution and cannot separate two
				// events that land in the same second.
				$recorded     = 0;
				$recorded_ids = array();
				foreach ( $ticked as $aid ) {
					$seen          = isset( $posted['versions'][ $aid ] ) ? (int) $posted['versions'][ $aid ] : null;
					$acceptance_id = mtl_record_agreement_acceptance( $target_id, $aid, 'staff_edit', $seen );
					if ( $acceptance_id > 0 ) {
						++$recorded;
						$recorded_ids[] = $acceptance_id;
					}
				}

				if ( $recorded > 0 ) {
					$record_agreement_id = 0;

					// Their copy of what was recorded, sent because it is
					// theirs, not because anything is being asked of them.
					// Ticked by default, following the existing setup-email
					// checkbox on the Add form; an unticked box posts nothing,
					// so absence is a deliberate "don't email them".
					$mtl_rec_emailed = false;
					if ( isset( $_POST['mtl_send_agreement_copy'] ) ) {
						$mtl_rec_emailed = mtl_send_agreement_confirmation_email( $target_id, $recorded_ids );
					}

					echo '<div class="notice notice-success is-dismissible"><p><strong>Recorded.</strong> ' . esc_html( number_format_i18n( $recorded ) ) . ' agreement' . ( 1 === $recorded ? '' : 's' ) . ' recorded against this member&rsquo;s signed form.';
					if ( isset( $_POST['mtl_send_agreement_copy'] ) && ! $mtl_rec_emailed ) {
						// Reported rather than swallowed: the record stands
						// either way, but silence would mean nobody finds out
						// the member never got their copy until a dispute.
						echo ' <strong>Their emailed copy could not be sent</strong>. Use <em>Resend agreement email</em> on their detail panel to try again.';
					}
					echo '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Nothing could be recorded. Please try again.</p></div>';
				}
			}
		}
	}

	// 0bb. HANDLE "RESEND AGREEMENT EMAIL"
	//
	// A confirmation that failed to send has no other remedy: the acceptance is
	// committed and correct, but the member has no copy of it. This re-sends the
	// LATEST acceptance event, regenerating the list from those rows, never an
	// older event, and it never writes a new acceptance row.
	if ( isset( $_POST['mtl_resend_agreement_email'] ) && mtl_agreements_tracking() && mtl_can_manage_library() ) {
		if ( ! isset( $_POST['mtl_resend_agreement_email_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_resend_agreement_email_nonce'] ) ), 'mtl_resend_agreement_email_action' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		} else {
			$target_id = isset( $_POST['agreement_member_id'] ) ? absint( $_POST['agreement_member_id'] ) : 0;
			$event_ids = $target_id > 0 ? mtl_latest_acceptance_event_ids( $target_id ) : array();

			if ( ! $event_ids ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Nothing to send.</strong> This member has no agreements on record.</p></div>';
			} elseif ( mtl_send_agreement_confirmation_email( $target_id, $event_ids ) ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>Sent.</strong> The member has been emailed their copy of the most recent agreements recorded for them. Nothing about their record has changed.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Not sent.</strong> The email could not be delivered. Check the site&rsquo;s mail settings and try again.</p></div>';
			}
		}
	}

	// 0c. HANDLE PER-MEMBER "SEND AGREEMENT REQUEST"
	//
	// mtl_agreements_online() only: a request email tells the member to agree on
	// the website, and there is nowhere to do that in paper mode. This is part
	// of what gives paper mode its defining property: the plugin never asks a
	// member for anything in it.
	if ( isset( $_POST['mtl_send_agreement_request'] ) && mtl_agreements_online() && mtl_can_manage_library() ) {
		if ( ! isset( $_POST['mtl_send_agreement_request_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_send_agreement_request_nonce'] ) ), 'mtl_send_agreement_request_action' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		} else {
			$target_id = isset( $_POST['agreement_member_id'] ) ? absint( $_POST['agreement_member_id'] ) : 0;

			// mtl_find_wp_user_id_by_member_id() needs the email as well as the
			// id, and refuses to guess a link from the id alone.
			$mtl_req_email = '';
			if ( $target_id > 0 ) {
				$mtl_req_members = $wpdb->prefix . 'members';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix.
				$mtl_req_email = (string) $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$mtl_req_members} WHERE member_id = %d", $target_id ) );
			}
			$user_id = '' !== $mtl_req_email ? mtl_find_wp_user_id_by_member_id( $target_id, $mtl_req_email ) : 0;

			// Same once-a-day guard the bulk sender uses, so pressing this
			// repeatedly does not mail somebody three times in a minute.
			$last_sent = $user_id > 0 ? (int) get_user_meta( $user_id, 'mtl_agreement_requested_at', true ) : 0;

			if ( $user_id <= 0 ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Not sent.</strong> This member has no website sign-in, so there is nowhere for them to agree. Record their signed form instead, or send them a password setup email first.</p></div>';
			} elseif ( $last_sent > 0 && ( time() - $last_sent ) < DAY_IN_SECONDS ) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>Not sent.</strong> This member was already asked within the last day.</p></div>';
			} elseif ( mtl_send_agreement_request_email( $target_id ) ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>Sent.</strong> The member has been asked to review and agree. Nothing about their record has changed yet.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Not sent.</strong> The email could not be delivered. Check the site&rsquo;s mail settings and try again.</p></div>';
			}
		}
	}

	// 1. HANDLE "ADD" FORM SUBMISSION (Insert Data)
	if ( isset( $_POST['mtl_add_member'] ) && mtl_can_manage_library() ) {
		if ( isset( $_POST['mtl_add_member_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_add_member_nonce'] ) ), 'mtl_add_member_action' ) ) {

			// --- Gather + sanitize incoming data ---
			// wp_unslash() removes WordPress magic quotes before sanitizing.
			$first_name     = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$last_name      = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
			$email          = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$phone_country  = mtl_valid_phone_country( sanitize_text_field( wp_unslash( $_POST['phone_country'] ?? '' ) ) );
			$phone_national = sanitize_text_field( wp_unslash( $_POST['phone_national'] ?? '' ) );
			$phone_result   = mtl_format_phone_number( $phone_country, $phone_national );
			$address_line1  = sanitize_text_field( wp_unslash( $_POST['address_line1'] ?? '' ) );
			$address_line2  = sanitize_text_field( wp_unslash( $_POST['address_line2'] ?? '' ) );
			$city           = sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) );
			// Both are <select> dropdowns; mtl_valid_*() coerces anything
			// outside their whitelist (a tampered request) to '' so the
			// existing required-field / blank-defaults-to-US logic below
			// handles an invalid value exactly the same as a missing one.
			$state           = mtl_valid_state( sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ) );
			$zip_code        = sanitize_text_field( wp_unslash( $_POST['zip_code'] ?? '' ) );
			$country         = mtl_valid_country( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
			$signup_date     = sanitize_text_field( wp_unslash( $_POST['signup_date'] ?? '' ) );
			$photo_id_url    = sanitize_url( wp_unslash( $_POST['photo_id_scan_url'] ?? '' ) );
			$addr_proof_url  = sanitize_url( wp_unslash( $_POST['address_proof_scan_url'] ?? '' ) );
			$private_notes   = sanitize_textarea_field( wp_unslash( $_POST['private_notes'] ?? '' ) );
			$training_starts = mtl_read_posted_training_starts();

			// Numeric field: keep the raw typed string for redisplay (so a blank
			// field stays blank instead of turning into "0"), but store a float.
			$donation_display = isset( $_POST['recurring_donation_amount'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['recurring_donation_amount'] ) ) ) : '';
			$donation         = floatval( $donation_display );

			// CHAR(1) 'Y'/'N' column, so whitelist rather than trust the posted value.
			$has_donated = ( isset( $_POST['has_donated_tools'] ) && 'Y' === $_POST['has_donated_tools'] ) ? 'Y' : 'N';

			// Ticked by default in the form. An unchecked box posts nothing at
			// all, so absence is a deliberate "don't email them", not a default.
			$send_setup_email = isset( $_POST['mtl_send_setup_email'] );

			// signup_date is NOT NULL in the schema; fall back to today.
			if ( '' === $signup_date || ! strtotime( $signup_date ) ) {
				$signup_date = gmdate( 'Y-m-d' );
			}

			// country is NOT NULL with a DB default, but that default only
			// applies when the column is omitted from the INSERT entirely;
			// this form always supplies the field, so an emptied-out input
			// needs its own fallback to the same default.
			if ( '' === $country ) {
				$country = 'United States';
			}

			// --- Validate ---
			$error                = false;
			$error_message        = '';
			$clear_email_on_error = false;

			if ( '' === $first_name || '' === $last_name || '' === $address_line1 || '' === $city || '' === $state || '' === $zip_code ) {
				// These columns are all NOT NULL in the schema. The HTML
				// "required" attributes normally stop this client-side; this is
				// a re-check in case they are bypassed.
				$error         = true;
				$error_message = 'First name, last name, phone number, and a complete address (street, city, state, ZIP) are all required. The member was not added.';
			} elseif ( '' !== $phone_result['error'] ) {
				$error         = true;
				$error_message = $phone_result['error'] . ' The member was not added.';
			} elseif ( '' === $email || ! is_email( $email ) ) {
				$error         = true;
				$error_message = 'A valid email address is required. The member was not added.';
			} else {
				// Email must be unique. Checked up front to show a clear
				// message. The UNIQUE column constraint in the DB is the final
				// backstop if two admins submit the same email simultaneously.
				$email_in_use = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
						"SELECT member_id FROM {$tbl_members} WHERE email = %s LIMIT 1",
						$email
					)
				);
				if ( $email_in_use ) {
					$error                = true;
					$clear_email_on_error = true;
					$error_message        = 'That email address already belongs to another member. The member was not added. Please enter a different email address.';
				} elseif ( mtl_email_taken_by_non_member( $email ) ) {
					// A member's email doubles as their WordPress sign-in, so it
					// has to be free on that side too. Checked here rather than
					// after the INSERT so a clash cannot leave a member record
					// behind that can never be given a login, the same
					// pre-flight the Edit handler does further down.
					//
					// Note this asks specifically about NON-member accounts. A
					// plain email_exists() would also reject a member's own
					// sign-in that outlived a database rebuild, and re-adding
					// them with the same address is precisely how the staff guide
					// says to reconnect those.
					$error                = true;
					$clear_email_on_error = true;
					$error_message        = 'That email address is already used by another WordPress account, so it cannot also be this member&rsquo;s sign-in. The member was not added. Please enter a different email address.';
				}
			}

			// --- Insert (only if validation passed) ---
			if ( ! $error ) {
				$inserted = $wpdb->insert(
					$tbl_members,
					array(
						'first_name'                => $first_name,
						'last_name'                 => $last_name,
						'address_line1'             => $address_line1,
						'address_line2'             => '' !== $address_line2 ? $address_line2 : null,
						'city'                      => $city,
						'state'                     => $state,
						'zip_code'                  => $zip_code,
						'country'                   => $country,
						'phone_number'              => $phone_result['value'],
						'email'                     => $email,
						'signup_date'               => $signup_date,
						'recurring_donation_amount' => $donation,
						'has_donated_tools'         => $has_donated,
						'private_notes'             => '' !== $private_notes ? $private_notes : null,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
				);

				if ( $inserted ) {
					// member_id is AUTO_INCREMENT, so read back the ID MySQL assigned.
					$new_member_id = $wpdb->insert_id;

					// MANY-TO-MANY: one row per completed training, each carrying
					// the date that member completed it.
					mtl_sync_member_trainings( $new_member_id, $training_starts );

					// Agreements ticked on the form, recorded against the paper
					// the member signed at the desk. A shortfall warns but keeps
					// the member, the same handling as a failed login below, since
					// staff can finish from the detail panel.
					if ( mtl_agreements_staff_recording() ) {
						$posted_agreements  = mtl_read_agreements_post();
						$wanted             = count( $posted_agreements['ticked'] );
						$agreements_written = 0;
						$mtl_written_ids    = array();
						foreach ( $posted_agreements['ticked'] as $mtl_aid ) {
							$mtl_seen      = isset( $posted_agreements['versions'][ $mtl_aid ] ) ? (int) $posted_agreements['versions'][ $mtl_aid ] : null;
							$mtl_accept_id = mtl_record_agreement_acceptance( $new_member_id, $mtl_aid, 'staff_add', $mtl_seen );
							if ( $mtl_accept_id > 0 ) {
								++$agreements_written;
								$mtl_written_ids[] = $mtl_accept_id;
							}
						}
						if ( $agreements_written < $wanted ) {
							$mtl_shortfall = $wanted - $agreements_written;
							echo '<div class="notice notice-warning is-dismissible"><p><strong>The member was saved, but ' . esc_html( number_format_i18n( $mtl_shortfall ) ) . ' agreement' . ( 1 === $mtl_shortfall ? '' : 's' ) . ' could not be recorded.</strong> Use <em>Record agreement</em> on their detail panel to finish. This usually means an agreement was revised while the form was open.</p></div>';
						}

						// Their copy, same checkbox treatment as the dialog.
						if ( $mtl_written_ids && isset( $_POST['mtl_send_agreement_copy'] ) ) {
							mtl_send_agreement_confirmation_email( $new_member_id, $mtl_written_ids );
						}
					}

					// Verification documents live in their own table, separated
					// for security compliance. Insert a row as soon as at least
					// one scan is provided, since staff can save whatever the member
					// currently has on hand, one form of ID at a time. The member
					// only counts as verified once both are present.
					if ( '' !== $photo_id_url || '' !== $addr_proof_url ) {
						$wpdb->insert(
							$tbl_verifications,
							array(
								'member_id'              => $new_member_id,
								'photo_id_scan_url'      => '' !== $photo_id_url ? $photo_id_url : null,
								'address_proof_scan_url' => '' !== $addr_proof_url ? $addr_proof_url : null,
							),
							array( '%d', '%s', '%s' )
						);
						if ( '' !== $photo_id_url && '' !== $addr_proof_url ) {
							$success_message = esc_html( stripslashes( $first_name . ' ' . $last_name ) ) . ' has been added as a verified member.';
						} else {
							$success_message = esc_html( stripslashes( $first_name . ' ' . $last_name ) ) . ' has been added with one verification document on file. Add the other via Edit to mark them verified.';
						}
					} else {
						$success_message = esc_html( stripslashes( $first_name . ' ' . $last_name ) ) . ' has been added as an unverified member. Add their verification documents later via Edit.';
					}

					// Give them a way to actually get online. Without this the
					// member exists in the library's records but has no WordPress
					// account, which used to leave them unable to sign in, sign up
					// or reset a password; see mtl_create_member_login().
					//
					// A failure here does NOT undo the member row. That is a
					// deliberate difference from the public signup flow, which
					// rolls back: there, a record with no account is a dead end,
					// but here it is a recoverable state with "Create logins"
					// standing by, and throwing away hand-typed member details to
					// tidy up a login problem is the worse outcome by far.
					$new_login = mtl_create_member_login( $new_member_id );

					if ( is_wp_error( $new_login ) ) {
						$success_message .= ' <strong>Their online sign-in could not be created:</strong> '
							. esc_html( $new_login->get_error_message() )
							. ' Their record is saved. Use <em>Create logins</em> under Member Logins below to try again once that is resolved.';
					} elseif ( ! mtl_is_setup_pending( $new_login ) ) {
						// Not a new account: mtl_create_member_login() found this
						// member's own sign-in already on the address and pointed
						// it at the new record. They kept their password, so there
						// is nothing to invite them to.
						$success_message .= ' They already had a website sign-in, which has been reconnected to this record, so their existing password still works, and no email was sent.';
					} elseif ( $send_setup_email ) {
						if ( mtl_send_member_setup_email( $new_login ) ) {
							$success_message .= ' They have been emailed a link to choose their password.';
						} else {
							$success_message .= ' Their online sign-in was created, but the setup email could not be sent. Use <em>Send setup emails</em> under Member Logins below to retry.';
						}
					} else {
						$success_message .= ' Their online sign-in was created. No setup email was sent, so they will need one before they can sign in.';
					}

					echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> ' . wp_kses_post( $success_message ) . '</p></div>';
					// On success the form is left blank (defaults) for the next entry.
				} else {
					$error         = true;
					$error_message = 'Failed to add member. Please verify the database connection and try again.';
				}
			}

			// --- On any error: show the message and refill the form ---
			if ( $error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . wp_kses_post( $error_message ) . '</p></div>';

				$keep_form_open = true;

				$form_values['first_name']                = $first_name;
				$form_values['last_name']                 = $last_name;
				$form_values['email']                     = $clear_email_on_error ? '' : $email;
				$form_values['phone_country']             = $phone_country;
				$form_values['phone_national']            = $phone_national;
				$form_values['address_line1']             = $address_line1;
				$form_values['address_line2']             = $address_line2;
				$form_values['city']                      = $city;
				$form_values['state']                     = $state;
				$form_values['zip_code']                  = $zip_code;
				$form_values['country']                   = $country;
				$form_values['signup_date']               = $signup_date;
				$form_values['recurring_donation_amount'] = $donation_display;
				$form_values['has_donated_tools']         = $has_donated;
				$form_values['photo_id_scan_url']         = $photo_id_url;
				$form_values['address_proof_scan_url']    = $addr_proof_url;
				$form_values['private_notes']             = $private_notes;
				$form_values['training_starts']           = $training_starts;
				// On a duplicate-email error the email is intentionally
				// cleared, since it is the field that must change.
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 1B. HANDLE BULK CSV IMPORT SUBMISSION
	// Each row is validated and inserted independently, so one bad row (missing
	// required field, duplicate/invalid email, etc.) is skipped and reported;
	// it does not abort the rest of the file. member_id is never read from the
	// CSV; every row goes through the same auto-increment insert as the single
	// Add form, and writes to BOTH the members and member_verifications tables.
	$bulk_import_ran      = false;
	$bulk_success_count   = 0;
	$bulk_failed_rows     = array();
	$bulk_warnings        = array();
	$keep_bulk_panel_open = false;

	if ( isset( $_POST['mtl_bulk_import_members'] ) && mtl_can_bulk_import() ) {
		if ( isset( $_POST['mtl_bulk_import_members_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_bulk_import_members_nonce'] ) ), 'mtl_bulk_import_members_action' ) ) {
			$keep_bulk_panel_open = true;

			// $_FILES values below: 'error' and 'size' are always plain
			// integers set by PHP itself (never sanitized per WPCS
			// convention); 'name' is sanitized here and used via these locals
			// for the rest of this block.
			//
			// 'tmp_name' is taken RAW. WordPress never slashes $_FILES, and
			// wp_unslash() on a Windows temp path strips its separators:
			// C:\Windows\Temp\php1234.tmp becomes C:WindowsTempphp1234.tmp,
			// which then fails to open. is_uploaded_file() below is what
			// proves the path, and it is the only check that can.
			$csv_error = isset( $_FILES['csv_file']['error'] ) ? (int) $_FILES['csv_file']['error'] : UPLOAD_ERR_NO_FILE;
			$csv_name  = isset( $_FILES['csv_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['csv_file']['name'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a filesystem path, validated by is_uploaded_file() below; sanitizing corrupts it on Windows.
			$csv_tmp_name = isset( $_FILES['csv_file']['tmp_name'] ) ? $_FILES['csv_file']['tmp_name'] : '';

			if ( ! isset( $_FILES['csv_file'] ) || UPLOAD_ERR_NO_FILE === $csv_error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please choose a CSV file to upload.</p></div>';
			} elseif ( UPLOAD_ERR_OK !== $csv_error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The file failed to upload. Please try again.</p></div>';
			} elseif ( 'csv' !== strtolower( pathinfo( $csv_name, PATHINFO_EXTENSION ) ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please upload a .csv file.</p></div>';
			} elseif ( ! is_uploaded_file( $csv_tmp_name ) ) {
				// Confirms tmp_name genuinely came from this request's upload.
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The upload could not be verified. Please try again.</p></div>';
			} elseif (
				// Content-based check, layered on top of the extension check
				// above. An explicit $mimes override is required: WordPress
				// did not add "csv" to its default allowed-uploads list until
				// 5.9, and this plugin supports 5.8+, so relying on core's
				// default list would reject every CSV on a 5.8 install.
				'csv' !== wp_check_filetype_and_ext(
					$csv_tmp_name,
					$csv_name,
					array( 'csv' => 'text/csv' )
				)['ext']
			) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The uploaded file does not appear to be a genuine CSV file.</p></div>';
			} else {
				$handle = fopen( $csv_tmp_name, 'r' );

				if ( ! $handle ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Could not read the uploaded file.</p></div>';
				} else {
					// Strip a UTF-8 byte-order-mark some spreadsheet apps
					// prepend, which would otherwise corrupt the first header.
					if ( "\xEF\xBB\xBF" !== fread( $handle, 3 ) ) {
						rewind( $handle );
					}

					$header_row = fgetcsv( $handle );

					if ( false === $header_row ) {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The CSV file appears to be empty.</p></div>';
					} else {
						// Map column name -> position so columns can appear in
						// any order in the uploaded file.
						$columns = array();
						foreach ( $header_row as $i => $col_name ) {
							$columns[ strtolower( trim( $col_name ) ) ] = $i;
						}

						$required_cols = array( 'first_name', 'last_name', 'email', 'phone_number', 'address_line1', 'city', 'state', 'zip_code' );
						$missing_cols  = array();
						foreach ( $required_cols as $rc ) {
							if ( ! isset( $columns[ $rc ] ) ) {
								$missing_cols[] = $rc;
							}
						}

						if ( ! empty( $missing_cols ) ) {
							// Escape each column name individually, THEN glue with the
							// <code> markup, and escaping the already-imploded string would
							// encode the glue's own <code>/</code> tags too, showing them
							// as literal text instead of styling the column names.
							echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The CSV is missing required column(s): <code>' . implode( '</code>, <code>', array_map( 'esc_html', $missing_cols ) ) . '</code>. Please use the downloadable template.</p></div>';
						} else {
							// Case-insensitive name -> id lookup so trainings in the
							// CSV can be matched by name, same as categories/tags
							// in the Inventory importer.
							$training_lookup = array();
							foreach ( $trainings as $mtl_training ) {
								$training_lookup[ strtolower( $mtl_training->training_name ) ] = (int) $mtl_training->training_id;
							}

							// Emails already claimed earlier in THIS file, the
							// DB uniqueness check can't catch two rows in the same
							// upload sharing an email, since neither exists yet.
							$seen_emails = array();

							$get_col = function ( $row, $name ) use ( $columns ) {
								return isset( $columns[ $name ], $row[ $columns[ $name ] ] ) ? trim( (string) $row[ $columns[ $name ] ] ) : '';
							};

							$row_number      = 1; // First data row is row 2 in a spreadsheet.
							$bulk_import_ran = true;
							$max_bulk_rows   = 5000; // Sanity cap so a huge file can't tie up the request indefinitely.

							while ( true ) {
								$row = fgetcsv( $handle );
								if ( false === $row ) {
									break;
								}
								++$row_number;

								if ( $row_number - 1 > $max_bulk_rows ) {
									$bulk_warnings[] = 'Import stopped after ' . $max_bulk_rows . ' rows; the remaining rows in this file were not processed. Split large imports into multiple files.';
									break;
								}

								// Skip a genuinely blank line.
								if ( 1 === count( $row ) && ( ! isset( $row[0] ) || '' === trim( (string) $row[0] ) ) ) {
									continue;
								}

								// --- Sanitize every field (security cleaning) ---
								$row_first = sanitize_text_field( $get_col( $row, 'first_name' ) );
								$row_last  = sanitize_text_field( $get_col( $row, 'last_name' ) );
								$row_email = sanitize_email( $get_col( $row, 'email' ) );
								$row_phone = sanitize_text_field( $get_col( $row, 'phone_number' ) );
								// mtl_parse_stored_phone_number() reads a bare 10-digit
								// number as U.S./+1 (no leading "+" required), and a
								// "+<code> ..." value as whichever country that code
								// belongs to, the same handling as re-editing an
								// already-stored value, since a CSV cell is just another
								// external representation of the same kind of text.
								$row_phone_parsed = mtl_parse_stored_phone_number( $row_phone );
								$row_phone_result = mtl_format_phone_number( $row_phone_parsed['iso'], $row_phone_parsed['national'] );
								$row_address1     = sanitize_text_field( $get_col( $row, 'address_line1' ) );
								$row_address2     = sanitize_text_field( $get_col( $row, 'address_line2' ) );
								$row_city         = sanitize_text_field( $get_col( $row, 'city' ) );
								$row_state        = sanitize_text_field( $get_col( $row, 'state' ) );
								$row_zip          = sanitize_text_field( $get_col( $row, 'zip_code' ) );
								$row_country      = sanitize_text_field( $get_col( $row, 'country' ) );
								if ( '' === $row_country ) {
									$row_country = 'United States';
								}
								$row_photo = sanitize_url( $get_col( $row, 'photo_id_scan_url' ) );
								$row_proof = sanitize_url( $get_col( $row, 'address_proof_scan_url' ) );
								$row_notes = sanitize_textarea_field( $get_col( $row, 'private_notes' ) );

								$row_signup_raw = $get_col( $row, 'signup_date' );
								$row_signup     = ( '' !== $row_signup_raw && strtotime( $row_signup_raw ) ) ? gmdate( 'Y-m-d', strtotime( $row_signup_raw ) ) : gmdate( 'Y-m-d' );

								$row_donation = floatval( $get_col( $row, 'recurring_donation_amount' ) );

								// has_donated_tools must be Y/N (case-insensitive);
								// blank defaults to N, anything else fails the row.
								$row_donated_raw = strtoupper( $get_col( $row, 'has_donated_tools' ) );
								if ( '' === $row_donated_raw ) {
									$row_donated = 'N';
								} elseif ( 'Y' === $row_donated_raw || 'N' === $row_donated_raw ) {
									$row_donated = $row_donated_raw;
								} else {
									$row_donated = null; // Signals a formatting error below.
								}

								// --- Per-row validation ---
								if ( '' === $row_first || '' === $row_last || '' === $row_phone || '' === $row_address1 || '' === $row_city || '' === $row_state || '' === $row_zip ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Missing a required field (first_name, last_name, phone_number, address_line1, city, state and zip_code are all required).',
									);
									continue;
								}
								if ( '' !== $row_phone_result['error'] ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Invalid phone_number "' . $row_phone . '": ' . $row_phone_result['error'],
									);
									continue;
								}
								if ( ! array_key_exists( $row_state, mtl_get_state_options() ) ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Invalid state "' . $row_state . '". Must be a valid 2-letter U.S. state/territory or Canadian province code, or "N/A".',
									);
									continue;
								}
								if ( ! in_array( $row_country, mtl_get_country_options(), true ) ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Invalid country "' . $row_country . '". Must exactly match one of the supported country names.',
									);
									continue;
								}
								if ( '' === $row_email || ! is_email( $row_email ) ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Missing or invalid email address.',
									);
									continue;
								}
								if ( null === $row_donated ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'has_donated_tools must be "Y" or "N" (or left blank).',
									);
									continue;
								}
								if ( isset( $seen_emails[ strtolower( $row_email ) ] ) ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Duplicate email "' . $row_email . '" also appears earlier in this file.',
									);
									continue;
								}

								$email_in_use = $wpdb->get_var(
									$wpdb->prepare(
										// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
										"SELECT member_id FROM {$tbl_members} WHERE email = %s LIMIT 1",
										$row_email
									)
								);
								if ( $email_in_use ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Email "' . $row_email . '" already belongs to an existing member.',
									);
									continue;
								}

								// The members-table check above cannot see a
								// WordPress account that has no member row, an
								// administrator, or a leftover from an earlier
								// delete. Importing over one would create a member
								// who can never be given a sign-in, because their
								// address is already spoken for.
								//
								// A member's OWN account surviving a database
								// rebuild is fine and deliberately allowed
								// through: re-importing those addresses is how the
								// staff guide says to reconnect them, and
								// mtl_create_member_login() relinks rather than
								// duplicating.
								if ( mtl_email_taken_by_non_member( $row_email ) ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Email "' . $row_email . '" is already used by a WordPress account, so it cannot also be a member sign-in.',
									);
									continue;
								}

								// "Name: date" pairs, semicolon-separated, e.g.
								// "Ladder Safety: 8/4/2026; Welding Basics: 8/3/2026".
								// Unknown training names and unreadable dates don't fail
								// the row; they are skipped and reported as notes, since
								// trainings are optional. Parsed here, after the validation
								// continues above, so a row that gets skipped entirely never
								// emits a training warning. sanitize_text_field() runs here
								// (not just at output) as defense in depth alongside the
								// esc_html() applied when warnings render.
								$row_training_starts = array();
								foreach ( array_filter( array_map( 'trim', explode( ';', $get_col( $row, 'trainings' ) ) ) ) as $pair ) {
									// Split on the FIRST colon only: a training name can't
									// contain one, but a date conceivably could.
									$colon_at = strpos( $pair, ':' );
									if ( false === $colon_at ) {
										$bulk_warnings[] = 'Row ' . $row_number . ': training "' . sanitize_text_field( $pair ) . '" has no completion date (expected "Name: date") and was skipped.';
										continue;
									}
									$pair_name = sanitize_text_field( trim( substr( $pair, 0, $colon_at ) ) );
									$pair_date = sanitize_text_field( trim( substr( $pair, $colon_at + 1 ) ) );

									if ( ! isset( $training_lookup[ strtolower( $pair_name ) ] ) ) {
										$bulk_warnings[] = 'Row ' . $row_number . ': unknown training "' . $pair_name . '" was skipped.';
										continue;
									}
									$pair_ts = ( '' !== $pair_date ) ? strtotime( $pair_date ) : false;
									if ( ! $pair_ts ) {
										$bulk_warnings[] = 'Row ' . $row_number . ': training "' . $pair_name . '" has an unreadable date "' . $pair_date . '" and was skipped.';
										continue;
									}
									$row_training_starts[ $training_lookup[ strtolower( $pair_name ) ] ] = gmdate( 'Y-m-d', $pair_ts );
								}

								// --- Insert into members ---
								$inserted = $wpdb->insert(
									$tbl_members,
									array(
										'first_name'    => $row_first,
										'last_name'     => $row_last,
										'address_line1' => $row_address1,
										'address_line2' => '' !== $row_address2 ? $row_address2 : null,
										'city'          => $row_city,
										'state'         => $row_state,
										'zip_code'      => $row_zip,
										'country'       => $row_country,
										'phone_number'  => $row_phone_result['value'],
										'email'         => $row_email,
										'signup_date'   => $row_signup,
										'recurring_donation_amount' => $row_donation,
										'has_donated_tools' => $row_donated,
										'private_notes' => '' !== $row_notes ? $row_notes : null,
									),
									array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
								);

								if ( ! $inserted ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Database error while adding this member.',
									);
									continue;
								}

								// member_id is AUTO_INCREMENT, never from the CSV.
								$new_member_id = $wpdb->insert_id;

								// --- Insert into member_training_mappings ---
								mtl_sync_member_trainings( $new_member_id, $row_training_starts );

								// --- Insert into member_verifications (either or both URLs) ---
								if ( '' !== $row_photo || '' !== $row_proof ) {
									$v_inserted = $wpdb->insert(
										$tbl_verifications,
										array(
											'member_id' => $new_member_id,
											'photo_id_scan_url' => '' !== $row_photo ? $row_photo : null,
											'address_proof_scan_url' => '' !== $row_proof ? $row_proof : null,
										),
										array( '%d', '%s', '%s' )
									);
									if ( ! $v_inserted ) {
										// The member row succeeded, so this is a
										// non-fatal note rather than a row failure.
										$bulk_warnings[] = 'Row ' . $row_number . ': member added, but their verification documents could not be saved.';
									}
								}

								$seen_emails[ strtolower( $row_email ) ] = true;
								++$bulk_success_count;
							}
						}
					}
				}

				fclose( $handle );
			}

			// --- Report results ---
			if ( $bulk_import_ran ) {
				$bulk_fail_count = count( $bulk_failed_rows );

				// Importing deliberately creates no sign-ins and sends no mail.
				// wp_insert_user() hashes with bcrypt (~50-100ms a go), so doing
				// it inline would add minutes to a large import and blow the
				// request; and a legacy roster should not email hundreds of
				// people the instant the file is uploaded. Both jobs are batched
				// under Member Logins instead, which this points staff at.
				$bulk_next_step = '';
				if ( $bulk_success_count > 0 ) {
					$bulk_next_step = ' They cannot sign in yet. Use <strong>Member Logins</strong> below to create their sign-ins, then send everyone a link to set a password.';
				}

				if ( $bulk_success_count > 0 && 0 === $bulk_fail_count ) {
					echo '<div class="notice notice-success is-dismissible"><p><strong>Bulk Import Complete!</strong> ' . intval( $bulk_success_count ) . ' member(s) were added.' . wp_kses_post( $bulk_next_step ) . '</p></div>';
				} elseif ( $bulk_success_count > 0 && $bulk_fail_count > 0 ) {
					echo '<div class="notice notice-warning is-dismissible"><p><strong>Bulk Import Finished with Errors:</strong> ' . intval( $bulk_success_count ) . ' member(s) added, but ' . intval( $bulk_fail_count ) . ' row(s) failed. See details below.' . wp_kses_post( $bulk_next_step ) . '</p></div>';
				} elseif ( 0 === $bulk_success_count && $bulk_fail_count > 0 ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Bulk Import Failed:</strong> None of the ' . intval( $bulk_fail_count ) . ' row(s) could be added. See details below.</p></div>';
				} else {
					echo '<div class="notice notice-warning is-dismissible"><p><strong>Nothing to import.</strong> The CSV file had no data rows.</p></div>';
				}

				if ( $bulk_fail_count > 0 ) {
					echo '<details open style="background: #fdf2f2; border: 1px solid #e6b3b3; border-radius: 4px; padding: 10px 15px; margin-bottom: 15px; max-width: 800px;">';
					echo '<summary style="cursor: pointer; font-weight: 600; color: #b32d2e;">' . intval( $bulk_fail_count ) . ' row(s) failed. Click to view details</summary>';
					echo '<ul style="margin: 10px 0 0 20px;">';
					foreach ( $bulk_failed_rows as $f ) {
						echo '<li>Row ' . intval( $f['row'] ) . ': ' . esc_html( $f['reason'] ) . '</li>';
					}
					echo '</ul></details>';
				}

				if ( ! empty( $bulk_warnings ) ) {
					echo '<details style="background: #fff8e5; border: 1px solid #f0dca0; border-radius: 4px; padding: 10px 15px; margin-bottom: 15px; max-width: 800px;">';
					echo '<summary style="cursor: pointer; font-weight: 600; color: #8a6d00;">' . count( $bulk_warnings ) . ' note(s). Click to view details</summary>';
					echo '<ul style="margin: 10px 0 0 20px;">';
					foreach ( $bulk_warnings as $w ) {
						echo '<li>' . esc_html( $w ) . '</li>';
					}
					echo '</ul></details>';
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 1C. HANDLE THE MEMBER LOGIN BATCH ACTIONS
	//
	// Administrators only, not Editors. Adding one member and minting that
	// member's low-privilege sign-in is ordinary staff work, but creating
	// accounts en masse and emailing the entire roster is the kind of thing this
	// plugin already keeps to administrators (see mtl_can_manage_settings() and
	// every data export). The capability is checked here, in the handler, not
	// just where the buttons are drawn.
	//
	// Note this page has no Post/Redirect/Get, so a browser refresh re-submits
	// whatever ran last. Both actions are safe to repeat: creating logins skips
	// anyone who already has one, and sending skips anyone contacted in the past
	// day. Anything added here later must hold to that or add a redirect.
	$login_batch_notice = '';

	if ( isset( $_POST['mtl_create_member_logins'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_member_logins_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_member_logins_nonce'] ) ), 'mtl_member_logins_action' ) ) {
			$batch = mtl_run_create_logins_batch();

			$login_batch_notice = '<div class="notice notice-success is-dismissible"><p><strong>Member Logins:</strong> '
				. intval( $batch['created'] ) . ' sign-in(s) created or reconnected.';

			if ( $batch['remaining'] > 0 ) {
				$login_batch_notice .= ' ' . intval( $batch['remaining'] ) . ' member(s) still need one. Press the button again to continue.';
			} else {
				$login_batch_notice .= ' Every member now has one.';
			}
			$login_batch_notice .= '</p>';

			if ( ! empty( $batch['failed'] ) ) {
				$login_batch_notice .= '<p>' . count( $batch['failed'] ) . ' could not be created:</p><ul style="margin-left: 20px; list-style: disc;">';
				foreach ( $batch['failed'] as $f ) {
					$login_batch_notice .= '<li>Member #' . intval( $f['member_id'] ) . ': ' . esc_html( $f['reason'] ) . '</li>';
				}
				$login_batch_notice .= '</ul>';
			}
			$login_batch_notice .= '</div>';
		} else {
			$login_batch_notice = '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	if ( isset( $_POST['mtl_send_setup_emails'] ) && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_member_logins_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_member_logins_nonce'] ) ), 'mtl_member_logins_action' ) ) {
			$resend_all = isset( $_POST['mtl_resend_all'] );
			$batch      = mtl_run_setup_email_batch( $resend_all );

			$login_batch_notice = '<div class="notice notice-success is-dismissible"><p><strong>Member Logins:</strong> '
				. intval( $batch['sent'] ) . ' setup email(s) sent.';

			if ( $batch['failed'] > 0 ) {
				$login_batch_notice .= ' ' . intval( $batch['failed'] ) . ' could not be sent. They stay on the list, so try again once mail delivery is working.';
			}
			if ( $batch['remaining'] > 0 ) {
				$login_batch_notice .= ' ' . intval( $batch['remaining'] ) . ' still to go. Press the button again to continue.';
			}
			$login_batch_notice .= ' ' . intval( $batch['pending'] ) . ' member(s) have still not chosen a password.</p></div>';
		} else {
			$login_batch_notice = '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// Bulk agreement requests. Administrators only, since one click here mails the
	// whole membership. Editors keep the per-member action above.
	$agreement_batch_notice = '';
	if ( isset( $_POST['mtl_bulk_agree_upload'] ) && mtl_agreements_staff_recording() && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_bulk_agree_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_bulk_agree_nonce'] ) ), 'mtl_bulk_agree_upload_action' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built, escaped HTML from the handler.
			echo mtl_handle_bulk_agree_upload();
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	if ( isset( $_POST['mtl_send_agreement_requests'] ) && mtl_agreements_online() && mtl_can_manage_settings() ) {
		if ( isset( $_POST['mtl_agreement_requests_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_agreement_requests_nonce'] ) ), 'mtl_agreement_requests_action' ) ) {
			// The one place the audience string is trusted, and it is
			// whitelisted before it reaches anything that builds a query. An
			// unrecognised value falls back to the NARROWER set, never "all".
			$agreement_audience = mtl_valid_agreement_audience(
				isset( $_POST['mtl_agreement_audience'] ) ? sanitize_text_field( wp_unslash( $_POST['mtl_agreement_audience'] ) ) : ''
			);
			$agreement_batch    = mtl_run_agreement_request_batch( $agreement_audience, isset( $_POST['mtl_agreement_resend_all'] ) );

			$agreement_batch_notice = '<div class="notice notice-success is-dismissible"><p><strong>Member Agreements:</strong> '
				. intval( $agreement_batch['sent'] ) . ' request(s) sent. Nobody&rsquo;s agreement status has changed, because this only asks.';

			if ( $agreement_batch['failed'] > 0 ) {
				$agreement_batch_notice .= ' ' . intval( $agreement_batch['failed'] ) . ' could not be sent. They stay on the list, so try again once mail delivery is working.';
			}
			if ( $agreement_batch['remaining'] > 0 ) {
				$agreement_batch_notice .= ' ' . intval( $agreement_batch['remaining'] ) . ' still to go. Press the button again to continue.';
			}
			if ( $agreement_batch['excluded'] > 0 ) {
				$agreement_batch_notice .= ' ' . intval( $agreement_batch['excluded'] ) . ' were left out because they cannot sign in yet.';
			}
			$agreement_batch_notice .= '</p></div>';
		} else {
			$agreement_batch_notice = '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// Per-member "send them a link" from the members table. Editors may do this
	// one at a time: it is the same act as ticking the box on Add Member.
	if ( isset( $_POST['mtl_send_one_setup_email'] ) && mtl_can_manage_library() ) {
		if ( isset( $_POST['mtl_send_one_setup_email_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_send_one_setup_email_nonce'] ) ), 'mtl_send_one_setup_email_action' ) ) {
			$one_member_id = isset( $_POST['member_id'] ) ? (int) $_POST['member_id'] : 0;
			$one_row       = $one_member_id > 0 ? $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
					"SELECT member_id, email, anonymized_at FROM {$tbl_members} WHERE member_id = %d",
					$one_member_id
				)
			) : null;

			if ( ! $one_row || null !== $one_row->anonymized_at ) {
				$login_batch_notice = '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That member record could not be found.</p></div>';
			} else {
				$one_user_id = mtl_find_wp_user_id_by_member_id( $one_row->member_id, (string) $one_row->email );

				// No proven sign-in yet: a member added before this existed, one
				// whose creation failed, or one whose own account outlived a
				// database rebuild. Sort that out first, so the button does what
				// it says rather than reporting a state staff cannot act on.
				$was_reconnected = false;
				if ( 0 === $one_user_id ) {
					$made = mtl_create_member_login( $one_row->member_id );
					if ( is_wp_error( $made ) ) {
						$login_batch_notice = '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html( $made->get_error_message() ) . '</p></div>';
					} else {
						$one_user_id     = (int) $made;
						$was_reconnected = ! mtl_is_setup_pending( $one_user_id );
					}
				}

				if ( $one_user_id > 0 && $was_reconnected ) {
					// Their own account was found and relinked. They already have
					// a working password, so emailing them a setup link would
					// invite them to replace one they are happily using.
					$login_batch_notice = '<div class="notice notice-success is-dismissible"><p><strong>Reconnected.</strong> ' . esc_html( $one_row->email ) . ' already had a website sign-in, now linked to this record. Their existing password still works, so no email was sent. If they have forgotten it, they can use &ldquo;Lost your password?&rdquo; on the sign-in page.</p></div>';
				} elseif ( $one_user_id > 0 ) {
					if ( mtl_send_member_setup_email( $one_user_id ) ) {
						$login_batch_notice = '<div class="notice notice-success is-dismissible"><p><strong>Sent.</strong> ' . esc_html( $one_row->email ) . ' has been emailed a link to choose a password. Any link sent to them earlier no longer works.</p></div>';
					} else {
						$login_batch_notice = '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That email could not be sent. Check the site&rsquo;s mail delivery and try again.</p></div>';
					}
				}
			}
		} else {
			$login_batch_notice = '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	if ( '' !== $agreement_batch_notice ) {
		echo wp_kses_post( $agreement_batch_notice );
	}

	// A mode is set but no agreement is active, so nothing can be tracked and
	// every agreement surface on this page is gone. The setting itself is
	// unchanged; without this notice staff just find the badges and the Record
	// agreement button missing and reasonably conclude the feature reset itself.
	if ( 'off' !== mtl_agreements_mode() && ! mtl_agreements_tracking() ) {
		echo '<div class="notice notice-warning is-dismissible"><p><strong>Member agreements are switched on, but no agreement is active.</strong> Nothing is tracked and nothing is shown on this page until at least one is in use';
		if ( mtl_can_manage_settings() ) {
			echo '. Add or un-retire one under <a href="' . esc_url( admin_url( 'admin.php?page=mtl-setup' ) ) . '">Setup &rarr; Member Agreements</a>.';
		} else {
			echo '. An administrator can add one under Setup.';
		}
		echo '</p></div>';
	}

	if ( '' !== $login_batch_notice ) {
		echo wp_kses_post( $login_batch_notice );
	}

	// 2. HANDLE "EDIT" FORM SUBMISSION (Update Data)
	if ( isset( $_POST['mtl_update_member'] ) && mtl_can_manage_library() ) {
		if ( isset( $_POST['mtl_edit_member_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_edit_member_nonce'] ) ), 'mtl_edit_member_action' ) ) {

			$edit_member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;

			$first_name     = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$last_name      = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
			$email          = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$phone_country  = mtl_valid_phone_country( sanitize_text_field( wp_unslash( $_POST['phone_country'] ?? '' ) ) );
			$phone_national = sanitize_text_field( wp_unslash( $_POST['phone_national'] ?? '' ) );
			$phone_result   = mtl_format_phone_number( $phone_country, $phone_national );
			$address_line1  = sanitize_text_field( wp_unslash( $_POST['address_line1'] ?? '' ) );
			$address_line2  = sanitize_text_field( wp_unslash( $_POST['address_line2'] ?? '' ) );
			$city           = sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) );
			// Both are <select> dropdowns; mtl_valid_*() coerces anything
			// outside their whitelist (a tampered request) to '' so the
			// existing required-field / blank-defaults-to-US logic below
			// handles an invalid value exactly the same as a missing one.
			$state           = mtl_valid_state( sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ) );
			$zip_code        = sanitize_text_field( wp_unslash( $_POST['zip_code'] ?? '' ) );
			$country         = mtl_valid_country( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
			$signup_date     = sanitize_text_field( wp_unslash( $_POST['signup_date'] ?? '' ) );
			$photo_id_url    = sanitize_url( wp_unslash( $_POST['photo_id_scan_url'] ?? '' ) );
			$addr_proof_url  = sanitize_url( wp_unslash( $_POST['address_proof_scan_url'] ?? '' ) );
			$private_notes   = sanitize_textarea_field( wp_unslash( $_POST['private_notes'] ?? '' ) );
			$training_starts = mtl_read_posted_training_starts();

			$donation_display = isset( $_POST['recurring_donation_amount'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['recurring_donation_amount'] ) ) ) : '';
			$donation         = floatval( $donation_display );

			$has_donated = ( isset( $_POST['has_donated_tools'] ) && 'Y' === $_POST['has_donated_tools'] ) ? 'Y' : 'N';

			if ( '' === $signup_date || ! strtotime( $signup_date ) ) {
				$signup_date = gmdate( 'Y-m-d' );
			}

			if ( '' === $country ) {
				$country = 'United States';
			}

			$error         = false;
			$error_message = '';

			// Set in the validation block below and reused after a successful
			// save to keep the linked WordPress sign-in's email in step.
			$old_email      = '';
			$linked_user_id = 0;

			if ( $edit_member_id <= 0 ) {
				$error         = true;
				$error_message = 'Could not determine which member to update. Please try again.';
			} elseif ( '' === $first_name || '' === $last_name || '' === $address_line1 || '' === $city || '' === $state || '' === $zip_code ) {
				$error         = true;
				$error_message = 'First name, last name, phone number, and a complete address (street, city, state, ZIP) are all required. The member was not updated.';
			} elseif ( '' !== $phone_result['error'] ) {
				$error         = true;
				$error_message = $phone_result['error'] . ' The member was not updated.';
			} elseif ( '' === $email || ! is_email( $email ) ) {
				$error         = true;
				$error_message = 'A valid email address is required. The member was not updated.';
			} else {
				// Email must stay unique, but must not collide with ITSELF.
				$email_in_use = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
						"SELECT member_id FROM {$tbl_members} WHERE email = %s AND member_id != %d LIMIT 1",
						$email,
						$edit_member_id
					)
				);
				if ( $email_in_use ) {
					$error         = true;
					$error_message = 'That email address already belongs to another member. Please enter a different email address.';
				} else {
					$old_email = (string) $wpdb->get_var(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
							"SELECT email FROM {$tbl_members} WHERE member_id = %d",
							$edit_member_id
						)
					);
					$linked_user_id = mtl_find_wp_user_id_by_member_id( $edit_member_id, $old_email );

					// A member's email is also their WordPress sign-in, so it
					// has to be free on that side too. The members-table check
					// above cannot see accounts that have no member row, an
					// administrator, or a leftover account from an earlier
					// delete. Checked BEFORE anything is written, so a clash
					// can never leave the record and the sign-in out of step.
					if ( $linked_user_id > 0 && 0 !== strcasecmp( $old_email, $email ) ) {
						$wp_email_owner = email_exists( $email );
						if ( $wp_email_owner && (int) $wp_email_owner !== $linked_user_id ) {
							$error         = true;
							$error_message = 'That email address is already used by another WordPress account, so it cannot also be this member&rsquo;s sign-in. The member was not updated.';
						}
					}
				}
			}

			if ( ! $error ) {
				// member_id is intentionally excluded from this array, since it is
				// the primary key and is never editable.
				$updated = $wpdb->update(
					$tbl_members,
					array(
						'first_name'                => $first_name,
						'last_name'                 => $last_name,
						'address_line1'             => $address_line1,
						'address_line2'             => '' !== $address_line2 ? $address_line2 : null,
						'city'                      => $city,
						'state'                     => $state,
						'zip_code'                  => $zip_code,
						'country'                   => $country,
						'phone_number'              => $phone_result['value'],
						'email'                     => $email,
						'signup_date'               => $signup_date,
						'recurring_donation_amount' => $donation,
						'has_donated_tools'         => $has_donated,
						'private_notes'             => '' !== $private_notes ? $private_notes : null,
					),
					array( 'member_id' => $edit_member_id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s' ),
					array( '%d' )
				);

				// $wpdb->update() returns the number of rows changed, which is
				// legitimately 0 when nothing actually differed; only `false`
				// means a real failure.
				if ( false === $updated ) {
					$error         = true;
					$error_message = 'Failed to update member. Please verify the database connection and try again.';
				} else {
					// A member's email doubles as their WordPress sign-in, and
					// mtl_current_member() proves a record belongs to an account by
					// comparing the two. Letting them drift apart here would lock
					// the member out of their own account page, so the sign-in
					// moves with the record or neither does.
					$email_sync_note = '';
					if ( 0 !== strcasecmp( $old_email, $email ) ) {
						if ( $linked_user_id > 0 ) {
							$synced = wp_update_user(
								array(
									'ID'         => $linked_user_id,
									'user_email' => $email,
								)
							);
							if ( is_wp_error( $synced ) ) {
								// Put the members row back rather than leave the
								// record and the sign-in pointing at different
								// addresses. The pre-flight check above catches
								// the common cause; this covers the race where
								// the address was claimed a moment ago.
								$wpdb->update(
									$tbl_members,
									array( 'email' => $old_email ),
									array( 'member_id' => $edit_member_id ),
									array( '%s' ),
									array( '%d' )
								);
								$error         = true;
								$error_message = 'Every other change was saved, but the email address was left as it was, because their WordPress sign-in could not be updated to match: ' . esc_html( $synced->get_error_message() );
							} else {
								$email_sync_note = ' Their WordPress sign-in email was updated to match. Their original username still works for signing in, and WordPress has emailed them about the change.';
							}
						} elseif ( ! empty( mtl_find_wp_user_ids_claiming_member_id( $edit_member_id ) ) ) {
							$email_sync_note = ' Note: this member has an online account, but its email no longer matches this record, so it was left alone. Set this member&rsquo;s email to the address shown on their WordPress user (under Users) to reconnect them.';
						}
					}

					// Re-sync the training mappings, including any changed
					// completion date on a training the member already held.
					mtl_sync_member_trainings( $edit_member_id, $training_starts );

					// Sync the verification row with the submitted scan URLs.
					// Either field can be blank, since a member may have only one
					// form of ID on file so far; they're only "verified" once
					// both are present (mtl_verification_urls_complete()).
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
					$was_verified     = mtl_verification_urls_complete(
						$wpdb->get_var( $wpdb->prepare( "SELECT photo_id_scan_url FROM {$tbl_verifications} WHERE member_id = %d", $edit_member_id ) ),
						$wpdb->get_var( $wpdb->prepare( "SELECT address_proof_scan_url FROM {$tbl_verifications} WHERE member_id = %d", $edit_member_id ) )
					);
					$has_verification = (bool) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT member_id FROM {$tbl_verifications} WHERE member_id = %d LIMIT 1",
							$edit_member_id
						)
					);
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$is_now_verified = mtl_verification_urls_complete( $photo_id_url, $addr_proof_url );

					$verification_note = '';
					if ( '' !== $photo_id_url || '' !== $addr_proof_url ) {
						$v_data = array(
							'photo_id_scan_url'      => '' !== $photo_id_url ? $photo_id_url : null,
							'address_proof_scan_url' => '' !== $addr_proof_url ? $addr_proof_url : null,
						);
						if ( $has_verification ) {
							$wpdb->update(
								$tbl_verifications,
								$v_data,
								array( 'member_id' => $edit_member_id ),
								array( '%s', '%s' ),
								array( '%d' )
							);
						} else {
							$wpdb->insert(
								$tbl_verifications,
								array_merge( array( 'member_id' => $edit_member_id ), $v_data ),
								array( '%d', '%s', '%s' )
							);
						}
						if ( $is_now_verified && ! $was_verified ) {
							$verification_note = ' The member is now marked as verified.';
						} elseif ( ! $is_now_verified ) {
							$verification_note = ' Only one verification document is on file, so the member is not (or no longer) marked as verified.';
						}
					} elseif ( $has_verification ) {
						// Both scan fields were cleared -> the admin removed the
						// member's verification documents.
						$wpdb->delete( $tbl_verifications, array( 'member_id' => $edit_member_id ), array( '%d' ) );
						$verification_note = ' The member&rsquo;s verification documents were removed, so they are now marked as unverified.';
					}

					// $error can be set inside this branch by the email-sync
					// rollback above, in which case the error notice below is
					// the whole story and a "Success!" line would contradict it.
					if ( ! $error ) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> ' . esc_html( stripslashes( $first_name . ' ' . $last_name ) ) . ' has been updated.' . wp_kses_post( $verification_note ) . wp_kses_post( $email_sync_note ) . '</p></div>';
					}
				}
			}

			if ( $error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . wp_kses_post( $error_message ) . '</p></div>';

				// Re-open the Edit panel with what the admin typed (not the
				// DB's stale copy) so a validation error never discards their edits.
				$editing     = true;
				$edit_values = array(
					'first_name'                => $first_name,
					'last_name'                 => $last_name,
					'email'                     => $email,
					'phone_country'             => $phone_country,
					'phone_national'            => $phone_national,
					'address_line1'             => $address_line1,
					'address_line2'             => $address_line2,
					'city'                      => $city,
					'state'                     => $state,
					'zip_code'                  => $zip_code,
					'country'                   => $country,
					'signup_date'               => $signup_date,
					'recurring_donation_amount' => $donation_display,
					'has_donated_tools'         => $has_donated,
					'photo_id_scan_url'         => $photo_id_url,
					'address_proof_scan_url'    => $addr_proof_url,
					'private_notes'             => $private_notes,
					'training_starts'           => $training_starts,
				);
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3. HANDLE "DELETE" FORM SUBMISSION. mtl_delete_or_anonymize_member()
	// strips the member's identifying details, deletes their verification
	// documents and their WordPress sign-in outright, and keeps everything
	// that records what they did with the library (loans, reservations and
	// completed trainings) attached to a "Former Member" row.
	if ( isset( $_POST['mtl_delete_member'] ) && mtl_can_delete_members() ) {
		if ( isset( $_POST['mtl_delete_member_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_member_nonce'] ) ), 'mtl_delete_member_action' ) ) {

			$delete_member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;

			if ( $delete_member_id > 0 ) {
				$result       = mtl_delete_or_anonymize_member( $delete_member_id, 'staff' );
				$display_name = esc_html( stripslashes( (string) $result['name'] ) );
				$res_note     = $result['cancelled_reservations'] > 0
					? ( ' ' . (int) $result['cancelled_reservations'] . ' active reservation(s) of theirs were also cancelled.' )
					: '';
				// An account whose stored member id no longer matches this
				// record can't be confirmed as theirs, so it is left in place
				// rather than deleted on a guess, because staff need to know it is
				// still there.
				$orphan_note = ! empty( $result['wp_user_orphaned'] )
					? ' Their online sign-in could not be matched to this record, so it was left in place. Remove it under Users if it is no longer needed.'
					: '';

				// The administrator's copy is the library's only record of the
				// deleted details, and the only thing still pointing at the
				// verification files, so a failure has to be loud, not silent.
				$cleanup_note = ! empty( $result['cleanup_email_sent'] )
					? ' Their record, and a request to delete their stored verification files, has been emailed to the site administrator.'
					: ' <strong>Their record could not be emailed to the site administrator</strong>. Nothing now points at their verification files, so delete those manually from wherever the library stores them.';

				if ( 'anonymized' === $result['outcome'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $display_name is esc_html()'d above.
					echo '<div class="notice notice-success is-dismissible"><p><strong>Personal data removed.</strong> ' . $display_name . ' is now shown as a former member. Their personal details, verification documents and online sign-in have been permanently removed, while their loans, reservations and completed trainings have been kept so the library&rsquo;s records stay accurate.' . esc_html( $res_note ) . wp_kses_post( $orphan_note ) . wp_kses_post( $cleanup_note ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That member could not be found or was already deleted.</p></div>';
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3B. HANDLE "MARK RETURNED" FROM THE MEMBER DETAIL PANEL'S MANAGE-LOAN
	// MODAL. Same effect as the Loans & Reservations / Inventory "mark
	// returned" actions, reachable here too so staff never have to leave a
	// member's record to close out their loan.
	// Every {$tbl_*} fragment interpolated in the queries through the end of
	// this member-detail-panel quick-action section is a table name only,
	// built from $wpdb->prefix, never request data.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( isset( $_POST['mtl_member_mark_returned'] ) && mtl_can_manage_library() ) {
		if ( isset( $_POST['mtl_member_loan_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_member_loan_nonce'] ) ), 'mtl_member_loan_action' ) ) {
			$mr_loan_id = isset( $_POST['loan_id'] ) ? intval( $_POST['loan_id'] ) : 0;
			$mr_tool_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT tool_id FROM {$tbl_loans} WHERE loan_id = %d", $mr_loan_id )
			);
			// Today unless the modal backdated it, validated (and, if
			// backdated, dated) by the shared helper.
			$mr_return = mtl_resolve_return_timestamp(
				$mr_loan_id,
				isset( $_POST['return_date'] ) ? sanitize_text_field( wp_unslash( $_POST['return_date'] ) ) : ''
			);
			if ( '' !== $mr_return['error'] ) {
				// Reopen the member's row so the return can be re-entered with
				// a workable date, without hunting for them again.
				$reopen_member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . wp_kses_post( $mr_return['error'] ) . '</p></div>';
			} else {
				$mr_done = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$tbl_loans} SET return_date = %s WHERE loan_id = %d AND return_date IS NULL",
						$mr_return['timestamp'],
						$mr_loan_id
					)
				);
				if ( $mr_done ) {
					// Back on the shelf: start the front of the queue's hold
					// period. It starts now even on a backdated return, since
					// nobody could collect the tool while it sat unprocessed.
					mtl_sync_reservation_readiness( $mr_tool_id );
					$reopen_member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
					$mr_note          = $mr_return['backdated']
						? ' Recorded as returned on ' . mtl_format_date( $mr_return['timestamp'] ) . '.'
						: '';
					echo '<div class="notice notice-success is-dismissible"><p><strong>Marked returned.</strong> The tool is back in inventory.' . wp_kses_post( $mr_note ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That loan could not be found, or was already marked returned.</p></div>';
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3C. HANDLE "EXTEND LOAN" FROM THE MEMBER DETAIL PANEL'S MANAGE-LOAN
	// MODAL, same effect as the Loans & Reservations "renew loan" action.
	if ( isset( $_POST['mtl_member_extend_loan'] ) && mtl_can_manage_library() ) {
		if ( isset( $_POST['mtl_member_loan_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_member_loan_nonce'] ) ), 'mtl_member_loan_action' ) ) {
			$ext_loan_id = isset( $_POST['loan_id'] ) ? intval( $_POST['loan_id'] ) : 0;
			$ext_due     = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ext_due ) || ! strtotime( $ext_due ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please provide a valid due date.</p></div>';
			} elseif ( $ext_due < gmdate( 'Y-m-d' ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The due date can&rsquo;t be in the past. Please pick today or a later date.</p></div>';
			} else {
				// Confirm the loan is still active before reporting success;
				// $wpdb->query()'s affected-rows return is 0 both when nothing
				// matched AND when the posted date equals the existing one, so
				// it can't distinguish "no such active loan" from "no-op save".
				$ext_active = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT loan_id FROM {$tbl_loans} WHERE loan_id = %d AND return_date IS NULL",
						$ext_loan_id
					)
				);
				if ( ! $ext_active ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That loan could not be found, or has already been returned.</p></div>';
				} else {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$tbl_loans} SET due_date = %s WHERE loan_id = %d AND return_date IS NULL",
							$ext_due,
							$ext_loan_id
						)
					);
					$reopen_member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
					echo '<div class="notice notice-success is-dismissible"><p><strong>Loan extended.</strong> New due date: ' . mtl_format_date( $ext_due ) . '.</p></div>';
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3D. HANDLE "CANCEL RESERVATION" FROM THE MEMBER DETAIL PANEL, same
	// effect as the Loans & Reservations "cancel reservation" action.
	if ( isset( $_POST['mtl_member_cancel_reservation'] ) && mtl_can_manage_library() ) {
		if ( isset( $_POST['mtl_member_cancel_reservation_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_member_cancel_reservation_nonce'] ) ), 'mtl_member_cancel_reservation_action' ) ) {
			$cr_reservation_id = isset( $_POST['reservation_id'] ) ? intval( $_POST['reservation_id'] ) : 0;
			$cr_tool_id        = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT tool_id FROM {$tbl_reservations} WHERE reservation_id = %d AND expiry_date IS NULL", $cr_reservation_id )
			);
			$cr_done           = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tbl_reservations} SET expiry_date = %s WHERE reservation_id = %d AND expiry_date IS NULL",
					current_time( 'mysql' ),
					$cr_reservation_id
				)
			);
			if ( $cr_done ) {
				// Whoever was behind them may now be at the front.
				mtl_sync_reservation_readiness( $cr_tool_id );
				$reopen_member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
				echo '<div class="notice notice-success is-dismissible"><p><strong>Reservation cancelled.</strong></p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That reservation could not be found, or was already cancelled.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3E. HANDLE "START LOAN" FROM A MEMBER'S RESERVATION MODAL, only
	// offered in the UI when the member is first in that tool's queue, but
	// re-verified authoritatively here (queue order, and tool availability,
	// can both change between page load and submit), same pattern as the
	// Loans & Reservations checkout action.
	if ( isset( $_POST['mtl_member_start_loan'] ) && mtl_can_manage_library() ) {
		if ( isset( $_POST['mtl_member_start_loan_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_member_start_loan_nonce'] ) ), 'mtl_member_start_loan_action' ) ) {
			$sl_reservation_id = isset( $_POST['reservation_id'] ) ? intval( $_POST['reservation_id'] ) : 0;
			$sl_due            = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
			$sl_due_error      = false;
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sl_due ) || ! strtotime( $sl_due ) ) {
				$sl_due = gmdate( 'Y-m-d', strtotime( '+' . (int) get_option( 'mtl_default_loan_days', 21 ) . ' days' ) );
			} elseif ( $sl_due < gmdate( 'Y-m-d' ) ) {
				$sl_due_error = true;
			}

			// Derive tool + member from the reservation itself, never trust
			// posted tool/member ids.
			$sl_res = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT tool_id, member_id, reservation_date FROM {$tbl_reservations} WHERE reservation_id = %d AND expiry_date IS NULL",
					$sl_reservation_id
				)
			);

			if ( $sl_due_error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The due date can&rsquo;t be in the past. Please pick today or a later date.</p></div>';
			} elseif ( ! $sl_res ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That reservation is no longer active, so it could not be checked out.</p></div>';
			} else {
				// Anyone else with an earlier active reservation for the same
				// tool means this member is no longer first in line.
				$sl_ahead_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$tbl_reservations}
                     WHERE tool_id = %d AND expiry_date IS NULL AND reservation_id != %d
                       AND (reservation_date < %s OR (reservation_date = %s AND reservation_id < %d))",
						(int) $sl_res->tool_id,
						$sl_reservation_id,
						$sl_res->reservation_date,
						$sl_res->reservation_date,
						$sl_reservation_id
					)
				);

				if ( $sl_ahead_count > 0 ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> This member is no longer first in line for that tool.</p></div>';
				} elseif ( $wpdb->get_var( $wpdb->prepare( "SELECT retired_at FROM {$tbl_inventory} WHERE tool_id = %d", (int) $sl_res->tool_id ) ) ) {
					// Retiring a tool auto-cancels its active reservations, so
					// this should be unreachable in normal use, kept as
					// defense-in-depth, same as the checkout action's check.
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That tool is retired and can&rsquo;t be checked out.</p></div>';
				} else {
					// A tool is a single physical item, so it cannot be checked
					// out while it is already out on another loan.
					$sl_on_loan = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT loan_id FROM {$tbl_loans} WHERE tool_id = %d AND return_date IS NULL LIMIT 1",
							(int) $sl_res->tool_id
						)
					);
					if ( $sl_on_loan ) {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Cannot start this loan.</strong> That tool is already checked out. End the current loan first.</p></div>';
					} else {
						$sl_inserted = $wpdb->insert(
							$tbl_loans,
							array(
								'tool_id'   => (int) $sl_res->tool_id,
								'member_id' => (int) $sl_res->member_id,
								'loan_date' => current_time( 'mysql' ),
								'due_date'  => $sl_due,
							),
							array( '%d', '%d', '%s', '%s' )
						);
						if ( ! $sl_inserted ) {
							echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The loan could not be recorded. Please try again.</p></div>';
						} else {
							// Close out this reservation, same as the Loans &
							// Reservations checkout action.
							$wpdb->query(
								$wpdb->prepare(
									"UPDATE {$tbl_reservations} SET expiry_date = %s WHERE reservation_id = %d AND expiry_date IS NULL",
									current_time( 'mysql' ),
									$sl_reservation_id
								)
							);
							// Tool is out, so nobody left in the queue is collectable.
							mtl_sync_reservation_readiness( (int) $sl_res->tool_id );
							$reopen_member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : (int) $sl_res->member_id;
							echo '<div class="notice notice-success is-dismissible"><p><strong>Checked out.</strong> The tool is on loan, due ' . mtl_format_date( $sl_due ) . ', and the reservation has been closed.</p></div>';
						}
					}
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// 4. HANDLE "EDIT" LINK (GET): load the requested member into the Edit
	// panel. Skipped if a submitted edit above already failed validation, since
	// that block already populated $editing/$edit_values with the admin's input.
	$get_mtl_action = isset( $_GET['mtl_action'] ) ? sanitize_key( wp_unslash( $_GET['mtl_action'] ) ) : '';
	if ( ! $editing && mtl_can_manage_library() && isset( $_GET['member_id'] ) && 'edit' === $get_mtl_action ) {
		$edit_member_id = intval( $_GET['member_id'] );

		if ( $edit_member_id > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
			$member_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT m.*, v.photo_id_scan_url, v.address_proof_scan_url
                 FROM {$tbl_members} m
                 LEFT JOIN {$tbl_verifications} v ON m.member_id = v.member_id
                 WHERE m.member_id = %d",
					$edit_member_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $member_row ) {
				// training_id => start_date, exactly the shape the picker wants.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				$existing_training_rows   = $wpdb->get_results( $wpdb->prepare( "SELECT training_id, start_date FROM {$tbl_training_map} WHERE member_id = %d", $edit_member_id ) );
				$existing_training_starts = array();
				foreach ( $existing_training_rows as $mtl_row ) {
					$existing_training_starts[ (int) $mtl_row->training_id ] = (string) $mtl_row->start_date;
				}
				// Splits the stored "+<code> <national number>" value back
				// into the two pieces the phone widget needs to prefill.
				$edit_phone_parsed = mtl_parse_stored_phone_number( $member_row->phone_number );

				$editing     = true;
				$edit_values = array(
					'first_name'                => stripslashes( $member_row->first_name ),
					'last_name'                 => stripslashes( $member_row->last_name ),
					'email'                     => $member_row->email,
					'phone_country'             => $edit_phone_parsed['iso'],
					'phone_national'            => $edit_phone_parsed['national'],
					'address_line1'             => stripslashes( $member_row->address_line1 ),
					'address_line2'             => stripslashes( (string) $member_row->address_line2 ),
					'city'                      => stripslashes( $member_row->city ),
					'state'                     => stripslashes( $member_row->state ),
					'zip_code'                  => $member_row->zip_code,
					'country'                   => $member_row->country,
					'signup_date'               => $member_row->signup_date,
					'recurring_donation_amount' => $member_row->recurring_donation_amount,
					'has_donated_tools'         => $member_row->has_donated_tools,
					'photo_id_scan_url'         => (string) $member_row->photo_id_scan_url,
					'address_proof_scan_url'    => (string) $member_row->address_proof_scan_url,
					'private_notes'             => stripslashes( (string) $member_row->private_notes ),
					'training_starts'           => $existing_training_starts,
				);
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Not found.</strong> That member no longer exists.</p></div>';
			}
		}
	}
	?>

	<style>
		/* Multi-selects: let the admin drag-resize them instead of being stuck at size="6". */
		.mtl-resizable-select {
			min-width: 250px;
			min-height: 120px;
			padding: 4px;
			resize: vertical;
			overflow: auto;
		}

		/* Training chips in the detail panel, matching the Inventory page's
			category/tag badges. */
		.mtl-badge {
			display: inline-block;
			background: #eef3f7;
			color: #096491;
			border: 1px solid #d3dde4;
			border-radius: 12px;
			padding: 2px 9px;
			margin: 2px 4px 2px 0;
			font-size: 0.8em;
			white-space: nowrap;
		}

		/* Members table appearance, mirroring the Inventory page styling. */
		.mtl-table-wrap {
			overflow-x: auto;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			background: #fff;
		}

		#mtl-members-table {
			border: none;
		}

		#mtl-members-table th {
			background: #f6f7f7;
			text-transform: uppercase;
			font-size: 0.75em;
			letter-spacing: 0.03em;
			padding: 10px 8px;
		}

		#mtl-members-table td {
			padding: 10px 8px;
			vertical-align: top;
		}

		#mtl-members-table tbody tr:hover {
			background-color: #f0f7fb;
		}

		#mtl-members-table .mtl-truncate {
			max-width: 220px;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		#mtl-members-table .mtl-actions {
			white-space: nowrap;
		}

		.mtl-btn-danger.button {
			color: #b32d2e;
			border-color: #b32d2e;
			background: #fff;
		}

		.mtl-btn-danger.button:hover {
			background: #b32d2e;
			color: #fff;
		}

		.mtl-verified-badge {
			display: inline-block;
			background: #edf7ed;
			color: #1e7e34;
			border: 1px solid #bfe3c0;
			border-radius: 12px;
			padding: 2px 9px;
			font-size: 0.8em;
			white-space: nowrap;
		}

		.mtl-unverified-badge {
			display: inline-block;
			background: #fdf2f2;
			color: #b32d2e;
			border: 1px solid #e6b3b3;
			border-radius: 12px;
			padding: 2px 9px;
			font-size: 0.8em;
			white-space: nowrap;
		}

		/* Advanced filters: related fields are boxed into side-by-side groups
			so the whole panel stays short instead of running down the page. */
		.mtl-adv-groups {
			display: flex;
			gap: 16px;
			flex-wrap: wrap;
			align-items: flex-start;
		}

		.mtl-adv-group {
			flex: 1 1 280px;
			min-width: 250px;
			margin: 0;
			border: 1px solid #e2e5e8;
			border-radius: 4px;
			padding: 4px 12px 10px 12px;
		}

		.mtl-adv-group legend {
			padding: 0 5px;
			font-size: 0.72em;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			color: #787c82;
		}

		.mtl-adv-fields {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(115px, 1fr));
			gap: 8px 10px;
		}

		.mtl-adv-fields label {
			display: block;
			font-size: 0.78em;
			font-weight: 600;
			margin-bottom: 2px;
		}

		.mtl-adv-fields input,
		.mtl-adv-fields select {
			width: 100%;
			min-height: 28px;
			font-size: 0.85em;
		}

		/* Trainings filter: a checkbox list in a popover, rather than a native
			<select multiple>. Ctrl/Cmd-clicking to build a multi-selection is
			the kind of thing that is obvious only if you already know it, and
			this filter is meant to be reached for casually. */
		.mtl-ms {
			position: relative;
		}

		.mtl-ms-toggle {
			width: 100%;
			min-height: 28px;
			font-size: 0.85em;
			text-align: left;
			background: #fff;
			border: 1px solid #8c8f94;
			border-radius: 4px;
			padding: 3px 22px 3px 8px;
			cursor: pointer;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		/* Caret, drawn in CSS so no image or icon font is needed. */
		.mtl-ms-toggle::after {
			content: '';
			position: absolute;
			right: 8px;
			top: 50%;
			margin-top: -2px;
			border-left: 4px solid transparent;
			border-right: 4px solid transparent;
			border-top: 5px solid #50575e;
		}

		.mtl-ms-panel {
			position: absolute;
			z-index: 20;
			top: calc(100% + 2px);
			left: 0;
			min-width: 100%;
			width: max-content;
			max-width: 280px;
			max-height: 230px;
			overflow-y: auto;
			background: #fff;
			border: 1px solid #8c8f94;
			border-radius: 4px;
			box-shadow: 0 2px 8px rgba(0, 0, 0, .12);
			padding: 4px 0;
		}

		.mtl-ms-panel label {
			display: flex;
			align-items: center;
			gap: 7px;
			padding: 4px 10px;
			font-size: 0.85em;
			font-weight: 400;
			cursor: pointer;
			margin: 0;
		}

		.mtl-ms-panel label:hover {
			background: #f0f6fc;
		}

		.mtl-ms-panel input[type="checkbox"] {
			width: auto;
			min-height: 0;
			margin: 0;
		}

		/* "Select all" sits above a divider so it reads as an action on the
			list rather than as another training in it. */
		.mtl-ms-all {
			border-bottom: 1px solid #dcdcde;
			font-weight: 600 !important;
		}

		/* Per-member training table in the detail panel. */
		.mtl-training-table {
			border-collapse: collapse;
			margin: 4px 0 14px 0;
			font-size: 0.85em;
		}

		.mtl-training-table th,
		.mtl-training-table td {
			text-align: left;
			padding: 4px 14px 4px 0;
			border-bottom: 1px solid #f0f0f1;
			white-space: nowrap;
		}

		.mtl-training-table th {
			font-size: 0.9em;
			text-transform: uppercase;
			letter-spacing: 0.03em;
			color: #787c82;
		}

		/* Expandable rows */
		.mtl-member-row {
			cursor: pointer;
		}

		.mtl-member-row.mtl-row-expanded {
			background-color: #eaf3fa;
		}

		.mtl-detail-row td {
			background: #fafbfc;
			padding: 16px 24px;
		}

		.mtl-detail-panel {
			cursor: default;
			display: flex;
			gap: 40px;
			flex-wrap: wrap;
		}

		.mtl-detail-col {
			flex: 1 1 320px;
			min-width: 280px;
		}

		.mtl-detail-panel p {
			margin: 4px 0 14px 0;
		}

		/* Per-member borrowing activity */
		.mtl-member-stats {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			margin: 8px 0 16px 0;
		}

		.mtl-member-stat {
			display: flex;
			flex-direction: column;
			background: #fff;
			border: 1px solid #e2e5e8;
			border-radius: 4px;
			padding: 6px 12px;
			font-size: 0.78em;
			color: #787c82;
			min-width: 74px;
		}

		.mtl-member-stat b {
			font-size: 1.6em;
			line-height: 1.1;
			color: var(--mtl-header-color, #ff6600);
		}

		/* A non-zero "kept past due" count is the one number worth noticing. */
		.mtl-member-stat-warn {
			border-color: #e6b3b3;
			background: #fdf6f6;
		}

		.mtl-member-stat-warn b {
			color: #b32d2e;
		}

		.mtl-member-list {
			list-style: none;
			margin: 6px 0 16px 0;
			padding: 0;
		}

		.mtl-member-list li {
			padding: 6px 0;
			border-top: 1px solid #eef0f2;
			font-size: 0.9em;
		}

		.mtl-member-list-meta {
			display: block;
			color: #999;
			font-size: 0.85em;
		}

		.mtl-overdue-flag {
			display: inline-block;
			background: #fdecea;
			color: #b32d2e;
			border: 1px solid #f0b7b2;
			border-radius: 999px;
			padding: 0 8px;
			font-size: 0.8em;
			font-weight: 600;
		}

		/* Loans and reservations are both clickable, opening the manage-loan /
			manage-reservation modals respectively. */
		.mtl-member-list li.mtl-loan-clickable {
			cursor: pointer;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 8px;
		}

		.mtl-member-list li.mtl-loan-clickable:hover,
		.mtl-member-list li.mtl-res-clickable:hover {
			background: #f0f7fb;
		}

		.mtl-member-list li.mtl-res-clickable {
			cursor: pointer;
		}

		/* ---- Manage Loan modal ---- */
		.mtl-lm-overlay {
			position: fixed;
			inset: 0;
			z-index: 100000;
			background: rgba(0, 0, 0, .5);
			display: flex;
			align-items: flex-start;
			justify-content: center;
			padding: 8vh 16px 16px 16px;
			overflow-y: auto;
		}

		.mtl-lm-modal {
			position: relative;
			background: #fff;
			border-radius: 6px;
			box-shadow: 0 8px 30px rgba(0, 0, 0, .3);
			padding: 22px 24px 24px 24px;
			width: 100%;
			max-width: 420px;
		}

		.mtl-lm-close {
			position: absolute;
			top: 8px;
			right: 10px;
			border: none;
			background: none;
			font-size: 1.6em;
			line-height: 1;
			color: #787c82;
			cursor: pointer;
		}

		.mtl-lm-close:hover {
			color: #1d2327;
		}

		.mtl-lm-tool-line {
			margin: 0 0 16px 0;
			color: #50575e;
		}

		.mtl-lm-section {
			border-top: 1px solid #eef0f2;
			padding-top: 14px;
			margin-top: 14px;
		}

		.mtl-lm-section:first-of-type {
			border-top: none;
			padding-top: 0;
			margin-top: 0;
		}

		.mtl-lm-label {
			display: block;
			font-weight: 600;
			font-size: 0.9em;
			margin-bottom: 4px;
		}

		.mtl-lm-due-quick {
			display: flex;
			gap: 6px;
			flex-wrap: wrap;
			margin-bottom: 8px;
		}

		.mtl-lm-due-active {
			background: var(--mtl-header-color, #ff6600) !important;
			border-color: var(--mtl-header-color, #ff6600) !important;
			color: #fff !important;
		}

		.mtl-lm-due-input {
			padding: 6px 8px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
		}

		.mtl-lm-actions {
			margin-top: 10px;
		}

		.mtl-lm-note {
			font-size: 0.85em;
			color: #666;
			margin: 0;
		}

		.mtl-sensitive-note {
			background: #fff8e5;
			border-left: 4px solid #dba617;
			padding: 8px 12px;
			font-size: 0.85em;
		}

		/* Pagination bars (above + below the table). */
		.mtl-pagination-bar {
			display: flex;
			align-items: center;
			gap: 12px;
			flex-wrap: wrap;
			margin: 10px 0;
		}

		.mtl-pagination-top {
			justify-content: space-between;
		}

		.mtl-pagination-bottom {
			justify-content: center;
		}

		.mtl-results-info {
			font-size: 0.9em;
		}

		.mtl-page-size-label {
			font-size: 0.85em;
			font-weight: 600;
		}

		#mtl-page-indicator {
			font-size: 0.9em;
			min-width: 120px;
			text-align: center;
		}

		.mtl-pagination-bar .button[disabled] {
			opacity: 0.5;
			cursor: not-allowed;
		}

		/* Resizable columns: border-box makes a th's set width match its
			on-screen width exactly, and the grip is a thin drag zone on the
			right edge of each header cell. */
		#mtl-members-table th {
			box-sizing: border-box;
			position: relative;
		}

		.mtl-col-resizer {
			position: absolute;
			top: 0;
			right: 0;
			width: 7px;
			height: 100%;
			cursor: col-resize;
			user-select: none;
		}

		.mtl-col-resizer:hover {
			background: var(--mtl-header-color, #ff6600);
			opacity: 0.4;
		}

		.mtl-agreement-badge {
			display: inline-block;
			background: #fcf0d4;
			color: #7a5b00;
			border: 1px solid #dba617;
			border-radius: 3px;
			padding: 1px 6px;
			font-size: 0.8em;
			white-space: nowrap;
			margin-top: 3px;
		}

		/* The collapsed line is a count, not a heading, so kept at the same
			scale as the training table so the two read as peers. */
		.mtl-agreement-details > summary {
			cursor: pointer;
			font-size: 0.85em;
			color: #646970;
			padding: 2px 0;
		}

		.mtl-agreement-details > summary:hover {
			color: #2271b1;
		}

		/* Only the exception is coloured, so a roll of "3 of 3" stays quiet. */
		.mtl-agreement-count-flag {
			color: #7a5b00;
			font-weight: 600;
		}

		.mtl-agreement-panel {
			margin: 6px 0 14px 3px;
			padding-left: 11px;
			border-left: 2px solid #f0f0f1;
		}

		.mtl-agreement-line {
			display: flex;
			gap: 7px;
			align-items: flex-start;
			padding: 7px 0;
			border-bottom: 1px solid #f0f0f1;
		}

		.mtl-agreement-line:last-of-type {
			border-bottom: 0;
		}

		.mtl-agreement-yes,
		.mtl-agreement-no {
			flex: 0 0 auto;
			font-size: 0.9em;
			line-height: 1.6;
		}

		.mtl-agreement-yes { color: #007017; font-weight: 700; }
		.mtl-agreement-no { color: #b32d2e; font-weight: 700; }

		.mtl-record-meta {
			margin: 2px 0 0 0;
			font-size: 0.85em;
			color: #666;
		}

		.mtl-agreement-actions {
			display: flex;
			gap: 6px;
			flex-wrap: wrap;
			align-items: center;
			margin: 12px 0 0 0;
		}

		.mtl-record-group {
			border: 1px solid #dcdcde;
			border-radius: 4px;
			padding: 10px 14px 4px 14px;
			margin: 0 0 14px 0;
		}

		.mtl-record-group legend {
			font-weight: 600;
			padding: 0 6px;
		}

		.mtl-record-item { margin-bottom: 12px; }

		.mtl-record-label {
			display: flex;
			gap: 8px;
			align-items: flex-start;
			cursor: pointer;
		}

		.mtl-record-label input[type="checkbox"] { margin-top: 3px; flex: 0 0 auto; }

		.mtl-record-assent {
			color: #50575e;
			font-style: italic;
			margin: 10px 0;
		}

		.mtl-record-inline {
			border: 1px solid #c3c4c7;
			border-left: 4px solid #2271b1;
			background: #fff;
			padding: 12px 16px;
			margin-top: 12px;
		}

		dialog.mtl-record-dialog {
			max-width: 640px;
			width: 92%;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 18px 22px;
		}

		dialog.mtl-record-dialog::backdrop { background: rgba(0, 0, 0, 0.5); }
	</style>

	<details style="background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; max-width: 800px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);" <?php echo $keep_form_open ? ' open' : ''; ?>>
		<summary style="font-size: 1.1em; font-weight: 600; cursor: pointer; outline: none; color: var(--mtl-header-color);">
			Add a New Member
		</summary>

		<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
			<form method="post" action="<?php echo esc_url( $base_url ); ?>">
				<?php wp_nonce_field( 'mtl_add_member_action', 'mtl_add_member_nonce' ); ?>

				<table class="form-table" style="margin-top: 0;">
					<?php mtl_render_member_form_fields( $form_values, $trainings, '', true ); ?>
				</table>

				<?php
				// Inline rather than behind the Record Agreement dialog, since
				// there is no detail panel to launch one from yet. Optional too,
				// unlike signup, because staff add walk-ins whose paperwork is not
				// signed, and saving them flagged beats refusing to save them.
				if ( mtl_agreements_staff_recording() ) {
					$mtl_add_agreements = mtl_get_active_agreements();
					if ( $mtl_add_agreements ) {
						?>
						<fieldset class="mtl-record-group" style="max-width: 640px;">
							<legend>Member agreements</legend>
							<div class="notice notice-warning inline" style="margin: 0 0 12px 0;">
								<p style="margin: 6px 0;"><strong>&#9888; Only tick these once you have the member&rsquo;s signed form in hand.</strong> You are recording that this person agreed, and that record is permanent. Leave them blank if the paperwork is not signed yet, since you can record it later from their detail panel.</p>
							</div>
							<?php foreach ( $mtl_add_agreements as $mtl_add_ag ) : ?>
								<?php
								$mtl_add_aid  = (int) $mtl_add_ag->agreement_id;
								$mtl_add_box  = 'mtl-add-agreement-' . $mtl_add_aid;
								$mtl_add_file = (int) $mtl_add_ag->attachment_id > 0 ? wp_get_attachment_url( (int) $mtl_add_ag->attachment_id ) : '';
								?>
								<div class="mtl-record-item">
									<label for="<?php echo esc_attr( $mtl_add_box ); ?>" class="mtl-record-label">
										<input type="checkbox" id="<?php echo esc_attr( $mtl_add_box ); ?>" name="agreements[<?php echo esc_attr( $mtl_add_aid ); ?>]" value="1">
										<span><?php echo nl2br( esc_html( $mtl_add_ag->agreement_text ) ); ?></span>
									</label>
									<p class="mtl-record-meta">
										v<?php echo esc_html( (int) $mtl_add_ag->version_num ); ?>
										<?php if ( $mtl_add_file ) : ?>
											&middot; <a href="<?php echo esc_url( $mtl_add_file ); ?>" target="_blank" rel="noopener noreferrer"
												aria-label="<?php echo esc_attr( 'View the attached document for &ldquo;' . wp_strip_all_tags( mtl_agreement_excerpt( $mtl_add_ag->agreement_text ) ) . '&rdquo; (opens in a new tab)' ); ?>">View the attached document &#8599;</a>
										<?php endif; ?>
									</p>
									<input type="hidden" name="agreement_versions[<?php echo esc_attr( $mtl_add_aid ); ?>]" value="<?php echo esc_attr( (int) $mtl_add_ag->version_num ); ?>">
								</div>
							<?php endforeach; ?>
							<p class="mtl-record-assent"><?php echo esc_html( mtl_assent_language( 'staff_add' ) ); ?></p>
							<p>
								<label>
									<input type="checkbox" name="mtl_send_agreement_copy" value="1" checked>
									<?php esc_html_e( 'Email the member their copy of what was recorded', 'my-tool-library' ); ?>
								</label>
							</p>
						</fieldset>
						<?php
					}
				}
				?>

				<p class="submit">
					<input type="submit" name="mtl_add_member" id="mtl_add_member" class="button button-primary" value="Save to Database">
				</p>
			</form>
		</div>
	</details>

	<?php
	// Administrators only, and withdrawn while the admin view is off -- see
	// mtl_can_bulk_import(). The upload handler and the template download check
	// the same thing for themselves, so hiding the panel here is presentation,
	// not the access control.
	if ( mtl_can_bulk_import() ) :
		?>
	<details style="background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; max-width: 800px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);" <?php echo $keep_bulk_panel_open ? ' open' : ''; ?>>
		<summary style="font-size: 1.1em; font-weight: 600; cursor: pointer; outline: none; color: var(--mtl-header-color);">
			Bulk Import Members from CSV
		</summary>

		<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
			<p>
				Add many members at once by uploading a CSV file.
				<a href="<?php echo esc_url( $csv_template_url ); ?>">Download the CSV template</a>
				(it includes a sample row) to get started, fill in one row per member, then upload it below.
			</p>
			<ul style="font-size: 0.85em; color: #666; margin: 0 0 15px 20px;">
				<li><strong>Required for every row:</strong> <code>first_name</code>, <code>last_name</code>, <code>email</code>, <code>phone_number</code>, <code>address_line1</code>, <code>city</code>, <code>state</code>, <code>zip_code</code>. Each email must be unique.</li>
				<li><code>phone_number</code> with no <code>+</code> is read as a 10-digit U.S./Canada number (e.g. <code>(414) 555-0123</code> or just <code>4145550123</code>). For any other country, lead with <code>+</code> and the calling code (e.g. <code>+44 20 7946 0958</code>). Every number is reformatted automatically on import to match what Add/Edit Member produces, and a row with a phone number that can&rsquo;t be read as a real number fails with a specific reason.</li>
				<li><code>state</code> must be a valid 2-letter U.S. state/territory or Canadian province code (e.g. <code>WI</code>, <code>ON</code>), or <code>N/A</code> for anywhere else.</li>
				<li><code>country</code> is optional (defaults to <code>United States</code> if blank), but if provided must exactly match a supported country name (the same list the Add/Edit form's Country dropdown uses).</li>
				<li><strong>Optional:</strong> <code>address_line2</code> (apartment/suite/unit), <code>signup_date</code> (defaults to today if blank; use <code>MM/DD/YYYY</code>), <code>recurring_donation_amount</code> (defaults to 0.00).</li>
				<li><code>has_donated_tools</code> must be exactly <code>Y</code> or <code>N</code> (blank counts as <code>N</code>).</li>
				<li>To mark a member <strong>verified</strong>, provide <em>both</em> <code>photo_id_scan_url</code> and <code>address_proof_scan_url</code>. Either can be left blank if the member only has one form of ID on file so far. The row still imports, just unverified until the other is added later via Edit.</li>
				<li>Do not include a <code>member_id</code> column, as it is assigned automatically.</li>
				<li><strong>Optional:</strong> <code>private_notes</code> is staff-only and never shown publicly, same as typing it into the Add/Edit form, but remember that unlike the form, the CSV file itself isn&rsquo;t private once it leaves this page, so avoid emailing or sharing an import file that has sensitive notes filled in.</li>
				<li><strong>Optional:</strong> <code>trainings</code> takes <code>Name: completion date</code> pairs separated by semicolons, for example &ldquo;Ladder Safety: 8/4/2026; Welding Basics: 8/3/2026&rdquo;. Names must match existing trainings exactly (add new ones under <strong>Setup &rarr; Member Trainings</strong> first), and the date is when that member completed it, which is what their certification length counts from. A pair with an unknown name, a missing date, or an unreadable date is skipped and reported, and it does not fail the row.</li>
				<li>If a row fails, the rest of the file is still processed, and failed rows are listed after upload.</li>
			</ul>
			<form method="post" action="<?php echo esc_url( $base_url ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'mtl_bulk_import_members_action', 'mtl_bulk_import_members_nonce' ); ?>
				<input type="file" name="csv_file" accept=".csv,text/csv" required>
				<p class="submit">
					<input type="submit" name="mtl_bulk_import_members" class="button button-primary" value="Upload & Import">
				</p>
			</form>
		</div>
	</details>
	<?php endif; ?>

	<?php
	// --- Member Logins -------------------------------------------------------
	// Administrators only. The counts come from mtl_count_*(), which all join
	// through to a live member row rather than reading usermeta directly;
	// otherwise a Setup > Set Up Database rebuild would leave this panel
	// reporting, and offering to email, members who no longer exist.
	if ( mtl_can_manage_settings() ) :
		$logins_missing  = mtl_count_members_without_login();
		$logins_pending  = mtl_count_members_setup_pending();
		$logins_blocked  = mtl_count_members_with_blocked_login();
		$logins_to_send  = mtl_count_members_awaiting_setup_email();
		$logins_all_done = ( 0 === $logins_missing && 0 === $logins_pending && 0 === $logins_blocked );
		?>
		<?php // Starts collapsed. The count in the summary is the part staff scan for; the panel itself is only needed when acting on it. ?>
		<details style="background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; max-width: 800px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
			<summary style="font-size: 1.1em; font-weight: 600; cursor: pointer; outline: none; color: var(--mtl-header-color);">
				Member Logins
				<?php if ( $logins_missing > 0 || $logins_pending > 0 ) : ?>
					<span style="font-weight: 400; color: #8a6d00;">(<?php echo intval( $logins_missing + $logins_pending ); ?> need attention)</span>
				<?php endif; ?>
			</summary>

			<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
				<p style="margin-top: 0;">Members added by staff or imported from a CSV get a library record, but signing in to the website needs a WordPress account as well. This is where those are created and where members are invited to choose a password.</p>

				<?php if ( $logins_all_done ) : ?>
					<p style="color: #1e5b25;"><strong>Nothing outstanding.</strong> Every member has a sign-in and has chosen a password.</p>
				<?php endif; ?>

				<ul style="margin: 0 0 15px 20px; list-style: disc;">
					<li><strong><?php echo intval( $logins_missing ); ?></strong> member(s) need a sign-in created or reconnected.</li>
					<li><strong><?php echo intval( $logins_pending ); ?></strong> have a sign-in but have never chosen a password<?php echo $logins_to_send > 0 ? ' (' . intval( $logins_to_send ) . ' due an email now)' : ''; ?>.</li>
					<?php if ( $logins_blocked > 0 ) : ?>
						<li style="color: #b32d2e;"><strong><?php echo intval( $logins_blocked ); ?></strong> member(s) have an email address that belongs to a WordPress account which is not a member sign-in, usually a staff login, or one left behind by a member deleted earlier. These cannot be fixed automatically: free the address or give the member a different one under <em>Users</em>, then run <em>Create logins</em> again.</li>
					<?php endif; ?>
				</ul>

				<form method="post" action="<?php echo esc_url( $base_url ); ?>">
					<?php wp_nonce_field( 'mtl_member_logins_action', 'mtl_member_logins_nonce' ); ?>

					<p>
						<input type="submit" name="mtl_create_member_logins" class="button" value="Create logins"<?php echo 0 === $logins_missing ? ' disabled' : ''; ?>>
						<span style="color: #666; font-size: 0.9em; margin-left: 8px;">Creates the missing sign-ins. Sends no email. Works through the list a batch at a time, so press it again if any remain.</span>
					</p>

					<p style="margin-bottom: 4px;">
						<input type="submit" name="mtl_send_setup_emails" class="button button-primary" value="Send setup emails"<?php echo 0 === $logins_pending ? ' disabled' : ''; ?>>
						<span style="color: #666; font-size: 0.9em; margin-left: 8px;">Emails everyone who has not chosen a password a link to set one.</span>
					</p>
					<p style="margin-top: 0;">
						<label style="color: #666; font-size: 0.9em;">
							<input type="checkbox" name="mtl_resend_all" value="1">
							Include members emailed in the last 24 hours
						</label>
					</p>

					<p style="color: #666; font-size: 0.85em; margin-bottom: 0;">Each member is emailed at most once a day unless you tick the box above. Sending a fresh link always cancels the previous one, so a member part-way through setting a password will need to use the newest email.</p>
				</form>
			</div>
		</details>
	<?php endif; ?>
	<?php
	// Administrators only. The box appears for either of its two halves: asking
	// members to agree online, or recording paper in bulk. Each half is gated
	// again below, since a paper-mode library has nowhere to send members and a
	// fully online one may not let staff attest at all.
	$ag_can_send   = mtl_agreements_online();
	$ag_can_record = mtl_agreements_staff_recording();
	if ( ( $ag_can_send || $ag_can_record ) && mtl_can_manage_settings() ) :
		$ag_outstanding  = $ag_can_send ? mtl_count_members_awaiting_agreement_request( 'outstanding', true ) : mtl_count_members_not_in_agreement();
		$ag_due_now      = $ag_can_send ? mtl_count_members_awaiting_agreement_request( 'outstanding', false ) : 0;
		$ag_recent       = $ag_outstanding - $ag_due_now;
		$ag_excluded     = $ag_can_send ? mtl_count_agreement_request_excluded( 'outstanding' ) : 0;
		$ag_never_agreed = $ag_can_send ? mtl_count_agreement_request_never_agreed( true ) : 0;
		?>
		<?php // Starts collapsed, matching Member Logins above. ?>
		<details style="background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; max-width: 800px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
			<summary style="font-size: 1.1em; font-weight: 600; cursor: pointer; outline: none; color: var(--mtl-header-color);">
				Member Agreements
				<?php if ( $ag_outstanding > 0 ) : ?>
					<span style="font-weight: 400; color: #8a6d00;">(<?php echo intval( $ag_outstanding ); ?> have not agreed)</span>
				<?php endif; ?>
			</summary>

			<?php if ( $ag_can_send ) : ?>
			<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
				<p style="margin-top: 0;">Ask members to review and agree to the current agreements. They receive an email with a link and agree on the website themselves.</p>

				<ul style="margin: 0 0 15px 20px; list-style: disc;">
					<li><strong><?php echo intval( $ag_outstanding ); ?></strong> member(s) have not agreed to all of the current agreements.</li>
					<li><strong><?php echo intval( $ag_never_agreed ); ?></strong> of those have never agreed to anything.</li>
					<?php if ( $ag_recent > 0 ) : ?>
						<li><strong><?php echo intval( $ag_recent ); ?></strong> were emailed in the last 24 hours and will be skipped.</li>
					<?php endif; ?>
				</ul>

				<?php if ( $ag_excluded > 0 ) : ?>
					<?php // Reported rather than silently dropped: a silent drop looks like a successful send. ?>
					<div class="notice notice-warning inline" style="margin: 0 0 15px 0;">
						<p style="margin: 6px 0;">
							<strong>&#9888; <?php echo intval( $ag_excluded ); ?> of them cannot sign in yet, so they have no way to agree. Create their logins first. </strong>
							See <em>Member Logins</em> above, then send setup emails. They will be left out of this send.
						</p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( $base_url ); ?>">
					<?php wp_nonce_field( 'mtl_agreement_requests_action', 'mtl_agreement_requests_nonce' ); ?>

					<fieldset style="margin-bottom: 12px;">
						<legend style="font-weight: 600;">Send to</legend>
						<label style="display: block; margin-bottom: 4px;">
							<input type="radio" name="mtl_agreement_audience" value="outstanding" checked>
							Members who have not agreed to all current agreements
						</label>
						<label style="display: block;">
							<input type="radio" name="mtl_agreement_audience" value="all">
							All active members
							<span style="color: #666; font-size: 0.9em;">(anyone already up to date is told there is nothing to do.)</span>
						</label>
					</fieldset>

					<p style="margin-bottom: 4px;">
						<input type="submit" name="mtl_send_agreement_requests" class="button button-primary" value="Send agreement requests"<?php echo 0 === $ag_outstanding ? ' disabled' : ''; ?>>
					</p>
					<p style="margin-top: 0;">
						<label style="color: #666; font-size: 0.9em;">
							<input type="checkbox" name="mtl_agreement_resend_all" value="1">
							Include members emailed in the last 24 hours
						</label>
					</p>

					<p style="color: #666; font-size: 0.85em; margin-bottom: 0;">Works through the list a batch at a time. <strong>Sending does not change anyone&rsquo;s status.</strong> Revising an agreement does not automatically send emails.</p>
				</form>
			</div>
			<?php endif; ?>

			<?php if ( $ag_can_record ) : ?>
				<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
					<h4 style="margin-top: 0;">Record signed paper in bulk</h4>
					<p style="margin-top: 0;">Download the list, put the date each member signed against each agreement, and upload it. Cells you leave empty record nothing.</p>

					<p>
						<?php
						$ag_dl_base = wp_nonce_url(
							add_query_arg(
								array(
									'page' => 'mtl-membership',
									'mtl_download_bulk_agree' => '1',
								),
								admin_url( 'admin.php' )
							),
							'mtl_bulk_agree_action'
						);
						?>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'audience', 'outstanding', $ag_dl_base ) ); ?>">Download members who have not agreed</a>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'audience', 'all', $ag_dl_base ) ); ?>">Download every member</a>
					</p>

					<div class="notice notice-warning inline" style="margin: 12px 0;">
						<p style="margin: 6px 0;"><strong>&#9888; This cannot be undone.</strong> Every date you enter becomes a permanent record that the member signed that agreement, attributed to your account. There is no way to remove one afterwards, so only upload a file you have checked against the signed forms.</p>
					</div>

					<form method="post" action="" enctype="multipart/form-data">
						<?php wp_nonce_field( 'mtl_bulk_agree_upload_action', 'mtl_bulk_agree_nonce' ); ?>
						<p>
							<label for="mtl-bulk-agree-file"><strong>Completed file</strong></label><br>
							<input type="file" name="mtl_bulk_agree_file" id="mtl-bulk-agree-file" accept=".csv,text/csv" required>
							<span style="color: #666; font-size: 0.85em;">Dates must be written YYYY-MM-DD, for example 2026-03-04.</span>
						</p>
						<p>
							<label>
								<input type="checkbox" name="mtl_bulk_agree_email" value="1">
								Email each member a copy of what was recorded
							</label>
						</p>
						<p>
							<label for="mtl-bulk-agree-phrase">Type <code>Record these agreements</code> to confirm</label><br>
							<input type="text" name="mtl_bulk_agree_phrase" id="mtl-bulk-agree-phrase" class="regular-text" autocomplete="off" required>
						</p>
						<p style="margin-bottom: 0;">
							<button type="submit" name="mtl_bulk_agree_upload" value="1" class="button button-primary">Record agreements</button>
						</p>
					</form>
				</div>
			<?php endif; ?>
		</details>
	<?php endif; ?>


	<?php if ( $editing && $edit_values ) : ?>
		<details style="background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; max-width: 800px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);" open>
			<summary style="font-size: 1.1em; font-weight: 600; cursor: pointer; outline: none; color: var(--mtl-header-color);">
				Edit Member: <?php echo esc_html( trim( $edit_values['first_name'] . ' ' . $edit_values['last_name'] ) !== '' ? trim( $edit_values['first_name'] . ' ' . $edit_values['last_name'] ) : ( '#' . $edit_member_id ) ); ?>
			</summary>

			<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
				<p style="margin-top: 0;"><strong>Member ID:</strong> #<?php echo esc_html( $edit_member_id ); ?> <span style="color:#666; font-size:0.85em;">(cannot be changed)</span></p>
				<form method="post" action="<?php echo esc_url( $base_url ); ?>" onsubmit="return confirm('Save changes to this member?');">
					<?php wp_nonce_field( 'mtl_edit_member_action', 'mtl_edit_member_nonce' ); ?>
					<input type="hidden" name="member_id" value="<?php echo esc_attr( $edit_member_id ); ?>">

					<table class="form-table" style="margin-top: 0;">
						<?php mtl_render_member_form_fields( $edit_values, $trainings, 'edit_' ); ?>
					</table>
					<p class="submit">
						<input type="submit" name="mtl_update_member" class="button button-primary" value="Update Member">
						<a href="<?php echo esc_url( $base_url ); ?>" class="button">Cancel</a>
					</p>
				</form>
			</div>
		</details>
	<?php endif; ?>

	<?php
	// 5. FETCH ALL MEMBER DATA
	// LEFT JOIN so unverified members (no member_verifications row) still
	// appear; their scan URL columns simply come back NULL, which the table
	// renders as a "Not Verified" badge.
	// Every {$tbl_*} fragment interpolated through the end of this
	// data-gathering section is a table name only, built from $wpdb->prefix,
	// never request data.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$members = $wpdb->get_results(
		"
        SELECT
            m.member_id,
            m.first_name,
            m.last_name,
            m.address_line1,
            m.address_line2,
            m.city,
            m.state,
            m.zip_code,
            m.country,
            m.phone_number,
            m.email,
            m.signup_date,
            m.recurring_donation_amount,
            m.has_donated_tools,
            m.anonymized_at,
            m.private_notes,
            v.photo_id_scan_url,
            v.address_proof_scan_url,
            v.verified_at
        FROM {$tbl_members} m
        LEFT JOIN {$tbl_verifications} v ON m.member_id = v.member_id
        ORDER BY m.member_id DESC
    "
	);

	// Completed trainings per member, as one whole-table query grouped into a
	// member_id-keyed map, same reasoning as the borrowing-activity maps
	// below, and the reason this isn't a GROUP_CONCAT joined onto the main
	// member query above (which would need a GROUP BY across every selected
	// column just to attach one list).
	//
	// Expiry is computed here in PHP rather than in SQL so there is exactly
	// one implementation of "is this certification still current"
	// (mtl_training_is_current()), shared with the member-facing pages.
	$member_trainings     = array();
	$member_training_rows = $wpdb->get_results(
		"
        SELECT mtm.member_id, mtm.training_id, mtm.start_date,
               t.training_name, t.certification_length_months
        FROM {$tbl_training_map} mtm
        JOIN {$tbl_trainings} t ON t.training_id = mtm.training_id
        ORDER BY t.training_name ASC
    "
	);
	foreach ( $member_training_rows as $mt_row ) {
		$member_trainings[ (int) $mt_row->member_id ][] = array(
			'training_id' => (int) $mt_row->training_id,
			'name'        => $mt_row->training_name,
			'start_date'  => (string) $mt_row->start_date,
			'expiry_date' => mtl_training_expiry_date( $mt_row->start_date, $mt_row->certification_length_months ),
			'is_current'  => mtl_training_is_current( $mt_row->start_date, $mt_row->certification_length_months ),
		);
	}

	// Agreements per member, batched for the same reason: the detail panels all
	// render inline, so the single-member helpers would be several queries per
	// row. Three queries total, whatever the roster size. Live in paper mode as
	// well as full, since the whole staff side is gated on tracking(), not online().
	$mtl_all_member_ids      = array_map( 'intval', wp_list_pluck( $members, 'member_id' ) );
	$member_agreement_status = array();
	$member_acceptances      = array();
	$member_agreement_vers   = array();
	$mtl_active_agreements   = array();
	$mtl_all_agreements      = array();
	if ( mtl_agreements_tracking() && $mtl_all_member_ids ) {
		$member_agreement_status = mtl_member_agreements_status_map( $mtl_all_member_ids );
		$member_acceptances      = mtl_get_member_acceptances_map( $mtl_all_member_ids );
		$member_agreement_vers   = mtl_get_member_acceptance_versions_map( $mtl_all_member_ids );
		$mtl_active_agreements   = mtl_get_active_agreements();
		// Advanced Search lists retired agreements too: a withdrawn agreement is
		// the most likely subject of a "who accepted that version?" search.
		$mtl_all_agreements = array_merge( $mtl_active_agreements, mtl_get_retired_agreements() );
	}

	// agreement_id => versions anybody has accepted, for the Version dropdown.
	$mtl_agreement_version_options = array();
	foreach ( $mtl_all_agreements as $mtl_vopt_ag ) {
		$mtl_agreement_version_options[ (string) (int) $mtl_vopt_ag->agreement_id ] = mtl_agreement_accepted_versions( (int) $mtl_vopt_ag->agreement_id );
	}

	// 5B. PER-MEMBER BORROWING ACTIVITY
	// Fetched as three whole-table queries and grouped into member_id-keyed
	// maps, rather than querying inside the render loop; with hundreds of
	// members that would otherwise be hundreds of round trips per page load.

	// Website sign-ins, keyed by lowercased email. One query for the whole
	// table: this page has no LIMIT on $members, so calling
	// mtl_find_wp_user_id_by_member_id() per row would be two more queries per
	// member on every single page load.
	$login_map = mtl_member_login_map();

	// Lifetime loan counts. "past due" deliberately counts BOTH loans returned
	// after their due date AND loans still out that are overdue right now, so
	// it reads as "how many times has this member kept a tool too long".
	$loan_stats     = array();
	$loan_stat_rows = $wpdb->get_results(
		"
        SELECT member_id,
               COUNT(*) AS total_loans,
               SUM(CASE WHEN return_date IS NULL THEN 1 ELSE 0 END) AS active_loans,
               SUM(CASE WHEN return_date IS NOT NULL THEN 1 ELSE 0 END) AS prior_loans,
               SUM(CASE WHEN return_date IS NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_now,
               -- DATE(return_date), not a raw comparison: return_date is a full
               -- timestamp and due_date stays a plain date, so a bare > would
               -- wrongly count a same-day return (any time after midnight) as late.
               SUM(CASE
                     WHEN return_date IS NOT NULL AND DATE(return_date) > due_date THEN 1
                     WHEN return_date IS NULL AND due_date < CURDATE() THEN 1
                     ELSE 0
                   END) AS past_due_loans
        FROM {$tbl_loans}
        GROUP BY member_id
    "
	);
	foreach ( $loan_stat_rows as $row ) {
		$loan_stats[ (int) $row->member_id ] = $row;
	}

	// The tools each member currently has out.
	$active_loans_by_member = array();
	$active_loan_rows       = $wpdb->get_results(
		"
        SELECT l.loan_id, l.member_id, l.loan_date, l.due_date,
               DATEDIFF(CURDATE(), l.due_date) AS days_past_due,
               t.tool_name, t.barcode
        FROM {$tbl_loans} l
        JOIN {$tbl_inventory} t ON t.tool_id = l.tool_id
        WHERE l.return_date IS NULL
        ORDER BY l.due_date ASC
    "
	);
	foreach ( $active_loan_rows as $row ) {
		$active_loans_by_member[ (int) $row->member_id ][] = $row;
	}

	// The tools each member is queued for, with their place in each queue
	// derived the same way as on the Loans & Reservations page.
	$active_res_by_member = array();
	$active_res_rows      = $wpdb->get_results(
		"
        SELECT r.reservation_id, r.member_id, r.reservation_date,
               t.tool_name, t.barcode,
               (SELECT COUNT(*) FROM {$tbl_reservations} r2
                  WHERE r2.tool_id = r.tool_id
                    AND r2.expiry_date IS NULL
                    AND (r2.reservation_date < r.reservation_date
                         OR (r2.reservation_date = r.reservation_date AND r2.reservation_id <= r.reservation_id))
               ) AS queue_place,
               (SELECT COUNT(*) FROM {$tbl_reservations} r3
                  WHERE r3.tool_id = r.tool_id AND r3.expiry_date IS NULL
               ) AS queue_size
        FROM {$tbl_reservations} r
        JOIN {$tbl_inventory} t ON t.tool_id = r.tool_id
        WHERE r.expiry_date IS NULL
        ORDER BY r.reservation_date ASC
    "
	);
	foreach ( $active_res_rows as $row ) {
		$active_res_by_member[ (int) $row->member_id ][] = $row;
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// 6. RENDER THE FILTERABLE/SORTABLE MEMBERS TABLE
	?>
	<div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 40px; margin-bottom: 10px;">
		<h3 style="margin: 0;">Current Members</h3>
		<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
			<input type="text" id="mtl-member-search" placeholder="Quick filter..." style="padding: 5px 10px; width: 220px; border: 1px solid #8c8f94; border-radius: 4px;">
			<button type="button" id="mtl-toggle-advanced" class="button">Advanced Search</button>
			<button type="button" id="mtl-clear-filters" class="button">Clear Filters</button>
		</div>
	</div>

	<div id="mtl-advanced-search" style="display: none; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px 20px; margin-bottom: 15px;">
		<div class="mtl-adv-groups">

			<fieldset class="mtl-adv-group">
				<legend>Contact</legend>
				<div class="mtl-adv-fields">
					<div>
						<label for="adv-m-name">Name</label>
						<input type="text" id="adv-m-name">
					</div>
					<div>
						<label for="adv-m-email">Email</label>
						<input type="text" id="adv-m-email">
					</div>
					<div>
						<label for="adv-m-phone">Phone</label>
						<input type="text" id="adv-m-phone">
					</div>
					<div>
						<label for="adv-m-address">Address</label>
						<input type="text" id="adv-m-address">
					</div>
				</div>
			</fieldset>

			<fieldset class="mtl-adv-group">
				<legend>Membership</legend>
				<div class="mtl-adv-fields">
					<div>
						<label for="adv-m-signup-from">Joined From</label>
						<input type="date" id="adv-m-signup-from">
					</div>
					<div>
						<label for="adv-m-signup-to">Joined To</label>
						<input type="date" id="adv-m-signup-to">
					</div>
					<div>
						<label for="adv-m-donation-min">Min Donation ($)</label>
						<input type="number" step="0.01" id="adv-m-donation-min">
					</div>
					<div>
						<label for="adv-m-donation-max">Max Donation ($)</label>
						<input type="number" step="0.01" id="adv-m-donation-max">
					</div>
					<div>
						<label for="adv-m-donated">Donated Tools?</label>
						<select id="adv-m-donated">
							<option value="">Any</option>
							<option value="y">Yes</option>
							<option value="n">No</option>
						</select>
					</div>
					<div>
						<label for="adv-m-verified">Verified?</label>
						<select id="adv-m-verified">
							<option value="">Any</option>
							<option value="yes">Yes</option>
							<option value="no">No</option>
						</select>
					</div>
					<?php if ( mtl_agreements_tracking() ) : ?>
						<div>
							<?php // A badge finds one member; this is what lets staff work through a backlog after revising an agreement. ?>
							<label for="adv-m-agreements">Agreements</label>
							<select id="adv-m-agreements">
								<option value="">Any status</option>
								<option value="ok">Up to date</option>
								<option value="outdated">Outstanding</option>
								<option value="none">Never agreed</option>
							</select>
						</div>
						<div>
							<?php
							// The question that arrives on a bad day: which
							// members accepted THAT version, the one with the
							// error in it, the one the insurer is asking about.
							// Without this the only route is a database query.
							?>
							<label for="adv-m-agreement">Accepted agreement</label>
							<select id="adv-m-agreement">
								<option value="">Any agreement</option>
								<?php foreach ( $mtl_all_agreements as $mtl_adv_ag ) : ?>
									<option value="<?php echo esc_attr( (int) $mtl_adv_ag->agreement_id ); ?>">
										<?php
										echo esc_html( mb_substr( trim( preg_replace( '/\s+/', ' ', $mtl_adv_ag->agreement_text ) ), 0, 60 ) );
										echo null !== $mtl_adv_ag->retired_at ? esc_html__( ' (retired)', 'my-tool-library' ) : '';
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<label for="adv-m-agreement-version">Version</label>
							<select id="adv-m-agreement-version" disabled>
								<option value="">Any version</option>
							</select>
						</div>
					<?php endif; ?>
					<div>
						<label for="adv-m-hasnotes">Has Private Notes?</label>
						<select id="adv-m-hasnotes">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
				</div>
			</fieldset>

			<fieldset class="mtl-adv-group">
				<legend>Borrowing Activity</legend>
				<div class="mtl-adv-fields">
					<div>
						<label for="adv-m-has-active">Has Active Loans?</label>
						<select id="adv-m-has-active">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-m-has-prior">Has Prior Loans?</label>
						<select id="adv-m-has-prior">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-m-has-res">Has Reservations?</label>
						<select id="adv-m-has-res">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-m-has-pastdue">Ever Kept Past Due?</label>
						<select id="adv-m-has-pastdue">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-m-overdue-now">Overdue Right Now?</label>
						<select id="adv-m-overdue-now">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
				</div>
			</fieldset>

			<?php if ( $trainings ) : ?>
				<fieldset class="mtl-adv-group">
					<legend>Trainings</legend>
					<div class="mtl-adv-fields">
						<div>
							<label for="adv-m-trainings-toggle">Has Completed</label>
							<?php
							// Matches on CURRENT certifications only: the question
							// staff ask here is "who is qualified to use this today",
							// so an expired training deliberately doesn't count. The
							// expired ones are still visible in each member's detail
							// panel.
							?>
							<div class="mtl-ms" id="adv-m-trainings">
								<button type="button" class="mtl-ms-toggle" id="adv-m-trainings-toggle" aria-expanded="false">Any</button>
								<div class="mtl-ms-panel" id="adv-m-trainings-panel" style="display: none;">
									<label class="mtl-ms-all">
										<input type="checkbox" id="adv-m-trainings-all">
										<span>Select all</span>
									</label>
									<?php foreach ( $trainings as $mtl_filter_training ) : ?>
										<label>
											<input type="checkbox" class="mtl-ms-opt" value="<?php echo esc_attr( $mtl_filter_training->training_id ); ?>">
											<span><?php echo esc_html( $mtl_filter_training->training_name ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
							<p style="font-size: 0.75em; color: #787c82; margin: 3px 0 0 0;">Shows members holding <em>every</em> training ticked, and still current.</p>
						</div>
					</div>
				</fieldset>
			<?php endif; ?>

		</div>
	</div>

	<?php if ( $members ) : ?>
		<div class="mtl-pagination-bar mtl-pagination-top">
			<span class="mtl-results-info" id="mtl-results-info"></span>
			<label class="mtl-page-size-label">Rows per page:
				<select id="mtl-page-size">
					<option value="20">20</option>
					<option value="50">50</option>
					<option value="100">100</option>
				</select>
			</label>
		</div>
	<?php endif; ?>

	<div class="mtl-table-wrap">
		<table class="wp-list-table widefat fixed striped table-view-list" id="mtl-members-table">
			<thead>
				<tr>
					<th class="sortable" style="cursor: pointer; width: 50px;" title="Click to sort">ID ↕</th>
					<th class="sortable" style="cursor: pointer;" title="Click to sort">Name ↕</th>
					<th class="sortable" style="cursor: pointer;" title="Click to sort">Email ↕</th>
					<th style="width: 120px;">Phone</th>
					<th>Address</th>
					<?php
					// data-date-col marks this as a date column for the sort handler below,
							// which must compare the row's ISO data-signup attribute rather than the
							// visible MM/DD/YYYY cell text (MM/DD/YYYY strings don't sort lexicographically
							// in date order the way YYYY-MM-DD strings do).
					?>
					<th class="sortable" data-date-col="signup" style="cursor: pointer; width: 100px;" title="Click to sort">Signed Up ↕</th>
					<th class="sortable" style="cursor: pointer; width: 90px;" title="Click to sort">Donation ↕</th>
					<th style="width: 90px;">Donated Tools?</th>
					<th style="width: 100px;">Verified</th>
					<th style="width: 110px;">Sign-in</th>
					<th style="width: 140px;">Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $members ) : ?>
					<?php
					foreach ( $members as $member ) :
						$full_name      = trim( stripslashes( $member->first_name ) . ' ' . stripslashes( $member->last_name ) );
						$is_verified    = mtl_verification_urls_complete( $member->photo_id_scan_url, $member->address_proof_scan_url );
						$is_anonymized  = ! empty( $member->anonymized_at );
						$edit_url       = add_query_arg(
							array(
								'mtl_action' => 'edit',
								'member_id'  => $member->member_id,
							),
							$base_url
						);
						$delete_confirm = sprintf(
							'Permanently delete member "%s" (%s)? This cannot be undone. If they have loan or reservation history, that history will be kept on record and their personal data will be anonymized instead of removed outright. Any active reservations of theirs will be cancelled, freeing up their spot in the queue.',
							$full_name,
							$member->email
						);

						// Borrowing activity for this member. Computed here (not
						// in the detail row below) because the main row needs it
						// too, for the boolean advanced-filter attributes.
						$mid          = (int) $member->member_id;
						$stats        = isset( $loan_stats[ $mid ] ) ? $loan_stats[ $mid ] : null;
						$total_loans  = $stats ? (int) $stats->total_loans : 0;
						$active_count = $stats ? (int) $stats->active_loans : 0;
						$prior_count  = $stats ? (int) $stats->prior_loans : 0;
						$overdue_now  = $stats ? (int) $stats->overdue_now : 0;
						$past_due     = $stats ? (int) $stats->past_due_loans : 0;
						$my_loans     = isset( $active_loans_by_member[ $mid ] ) ? $active_loans_by_member[ $mid ] : array();
						$my_res       = isset( $active_res_by_member[ $mid ] ) ? $active_res_by_member[ $mid ] : array();
						$res_count    = count( $my_res );
						?>
						<tr
							class="mtl-member-row"
							data-member-id="<?php echo esc_attr( $member->member_id ); ?>"
							data-name="<?php echo esc_attr( strtolower( $full_name ) ); ?>"
							data-email="<?php echo esc_attr( strtolower( $member->email ) ); ?>"
							data-phone="<?php echo esc_attr( strtolower( stripslashes( $member->phone_number ) ) ); ?>"
							data-address="<?php echo esc_attr( strtolower( mtl_member_address_single_line( $member ) ) ); ?>"
							data-signup="<?php echo esc_attr( $member->signup_date ); ?>"
							data-donation="<?php echo esc_attr( $member->recurring_donation_amount ); ?>"
							data-donated="<?php echo esc_attr( strtolower( $member->has_donated_tools ) ); ?>"
							data-verified="<?php echo $is_verified ? 'yes' : 'no'; ?>"
							<?php // Only whether notes exist; the notes themselves stay in the detail panel. ?>
							data-hasnotes="<?php echo trim( (string) $member->private_notes ) !== '' ? '1' : '0'; ?>"
							<?php
							// Agreement status, and every agreement/version pair
							// this member has EVER accepted, comma-wrapped
							// (",3:1,3:2,") so testing ",3:1," can never match
							// agreement 13 or version 12. Deliberately not just
							// the latest: somebody who accepted v2 and has since
							// accepted v3 must still be found when searching v2.
							$mtl_ag_status = isset( $member_agreement_status[ $mid ] ) ? $member_agreement_status[ $mid ] : 'disabled';
							$mtl_ag_vers   = isset( $member_agreement_vers[ $mid ] ) && $member_agreement_vers[ $mid ]
								? ',' . implode( ',', $member_agreement_vers[ $mid ] ) . ','
								: '';

							// Omitted entirely when the feature is off rather
							// than emitted as "disabled": off means the page
							// carries no trace of this feature at all, not that
							// it carries a trace saying so.
							if ( mtl_agreements_tracking() ) :
								?>
							data-agreements="<?php echo esc_attr( $mtl_ag_status ); ?>"
							data-agreement-vers="<?php echo esc_attr( $mtl_ag_vers ); ?>"
								<?php
							endif;
							?>
							<?php // Boolean flags backing the activity filters ("1" / "0"). ?>
							data-has-active="<?php echo $active_count > 0 ? '1' : '0'; ?>"
							data-has-prior="<?php echo $prior_count > 0 ? '1' : '0'; ?>"
							data-has-res="<?php echo $res_count > 0 ? '1' : '0'; ?>"
							data-has-pastdue="<?php echo $past_due > 0 ? '1' : '0'; ?>"
							data-overdue-now="<?php echo $overdue_now > 0 ? '1' : '0'; ?>"
							<?php
							// Comma-wrapped list of the training ids this member holds
							// with a CURRENT certification, e.g. ",2,7,". The wrapping
							// commas let the filter test ",7," and never match id 7
							// inside id 17. Expired certifications are deliberately
							// left out; see the Trainings filter's comment above.
							$mtl_current_ids = array();
							if ( ! empty( $member_trainings[ $mid ] ) ) {
								foreach ( $member_trainings[ $mid ] as $mtl_t ) {
									if ( $mtl_t['is_current'] ) {
										$mtl_current_ids[] = (int) $mtl_t['training_id'];
									}
								}
							}
							?>
							data-trainings="<?php echo esc_attr( $mtl_current_ids ? ',' . implode( ',', $mtl_current_ids ) . ',' : '' ); ?>">
							<td><?php echo esc_html( $member->member_id ); ?></td>
							<td><strong><?php echo esc_html( $full_name ); ?></strong></td>
							<td class="mtl-truncate" title="<?php echo esc_attr( $member->email ); ?>"><?php echo esc_html( $member->email ); ?></td>
							<td><?php echo esc_html( stripslashes( $member->phone_number ) ); ?></td>
							<td class="mtl-truncate" title="<?php echo esc_attr( mtl_member_address_single_line( $member ) ); ?>"><?php echo esc_html( mtl_member_address_single_line( $member ) ); ?></td>
							<td><?php echo mtl_format_date( $member->signup_date ); ?></td>
							<td>$<?php echo esc_html( number_format( $member->recurring_donation_amount, 2 ) ); ?></td>
							<td><?php echo 'Y' === $member->has_donated_tools ? 'Yes' : 'No'; ?></td>
							<td>
								<?php if ( $is_anonymized ) : ?>
									<span class="mtl-unverified-badge" title="Personal data removed at member request">Removed</span>
								<?php elseif ( $is_verified ) : ?>
									<span class="mtl-verified-badge">Verified</span>
								<?php else : ?>
									<span class="mtl-unverified-badge">Not Verified</span>
								<?php endif; ?>
								<?php
								// Two labels, because they mean different things
								// to staff: caught by a revision, versus never
								// agreed at all. Nothing for `ok`, so the list
								// stays quiet once everyone is current.
								if ( ! $is_anonymized && 'none' === $mtl_ag_status ) :
									?>
									<span class="mtl-agreement-badge" title="<?php esc_attr_e( 'This member has no agreements on record', 'my-tool-library' ); ?>"><?php esc_html_e( 'No agreements', 'my-tool-library' ); ?></span>
								<?php elseif ( ! $is_anonymized && 'outdated' === $mtl_ag_status ) : ?>
									<span class="mtl-agreement-badge" title="<?php esc_attr_e( 'This member has not agreed to the current version of every agreement', 'my-tool-library' ); ?>"><?php esc_html_e( 'Agreements outstanding', 'my-tool-library' ); ?></span>
								<?php endif; ?>
							</td>
							<?php
							// Sign-in state, read from the map built once above.
							// A row only counts as linked when the account's
							// mtl_member_id points back at it, the same rule
							// mtl_find_wp_user_id_by_member_id() applies, and
							// skipping it is how a stale link would show one
							// member another's account.
							$member_login = null;
							if ( ! $is_anonymized ) {
								$login_key = strtolower( trim( (string) $member->email ) );
								if ( isset( $login_map[ $login_key ] ) && $login_map[ $login_key ]['member_id'] === $mid ) {
									$member_login = $login_map[ $login_key ];
								}
							}
							?>
							<td>
								<?php if ( $is_anonymized ) : ?>
									&mdash;
								<?php elseif ( null === $member_login ) : ?>
									<span style="color: #b32d2e;">None</span>
								<?php elseif ( $member_login['pending'] ) : ?>
									<span style="color: #8a6d00;">No password</span>
								<?php else : ?>
									<span style="color: #1e5b25;">Active</span>
								<?php endif; ?>
							</td>
							<td class="mtl-actions">
								<?php if ( $is_anonymized ) : ?>
									&mdash;
								<?php else : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Edit</a>
									<?php
									// Offered whenever the member has not yet
									// chosen a password, including when they
									// have no account at all, in which case the
									// handler creates one first, so the button
									// does what it says rather than reporting a
									// state staff cannot act on from here.
									if ( null === $member_login || $member_login['pending'] ) :
										?>
										<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display: inline;" onsubmit="return confirm('Email <?php echo esc_js( $member->email ); ?> a link to set their password? Any link sent to them earlier will stop working.');">
											<?php wp_nonce_field( 'mtl_send_one_setup_email_action', 'mtl_send_one_setup_email_nonce' ); ?>
											<input type="hidden" name="member_id" value="<?php echo esc_attr( $member->member_id ); ?>">
											<button type="submit" name="mtl_send_one_setup_email" class="button button-small">Send&nbsp;setup&nbsp;link</button>
										</form>
									<?php endif; ?>
									<?php
									// Deleting a member is administrators-only, so Editors
									// get no Delete button. The handler checks the same
									// thing, so hiding it here is presentation, not the
									// enforcement. Members can always delete their own
									// account themselves from the public Account page.
									if ( mtl_can_delete_members() ) :
										?>
										<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( $delete_confirm ); ?>');">
											<?php wp_nonce_field( 'mtl_delete_member_action', 'mtl_delete_member_nonce' ); ?>
											<input type="hidden" name="member_id" value="<?php echo esc_attr( $member->member_id ); ?>">
											<button type="submit" name="mtl_delete_member" class="button button-small mtl-btn-danger">Delete</button>
										</form>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
						<!--
							Hidden detail row: full contact info plus links to the
							member's sensitive verification documents. Toggled
							open/closed by clicking anywhere on the row above.
						-->
						<tr class="mtl-detail-row" id="mtl-detail-<?php echo esc_attr( $member->member_id ); ?>" style="display: none;">
							<td colspan="11">
								<div class="mtl-detail-panel">
									<div class="mtl-detail-col">
										<strong>Full Address</strong>
										<?php $mtl_addr_lines = mtl_member_address_lines( $member ); ?>
										<p><?php echo esc_html( $mtl_addr_lines[0] ); ?><br><?php echo esc_html( $mtl_addr_lines[1] ); ?></p>

										<strong>Verification Documents</strong>
										<?php if ( ! empty( $member->photo_id_scan_url ) || ! empty( $member->address_proof_scan_url ) ) : ?>
											<p>
												<?php if ( ! empty( $member->photo_id_scan_url ) ) : ?>
													<a href="<?php echo esc_url( $member->photo_id_scan_url ); ?>" target="_blank" rel="noopener noreferrer">Photo ID Scan</a>
												<?php else : ?>
													<span style="color: #999;">Photo ID Scan not on file</span>
												<?php endif; ?>
												&nbsp;|&nbsp;
												<?php if ( ! empty( $member->address_proof_scan_url ) ) : ?>
													<a href="<?php echo esc_url( $member->address_proof_scan_url ); ?>" target="_blank" rel="noopener noreferrer">Proof of Address Scan</a>
												<?php else : ?>
													<span style="color: #999;">Proof of Address Scan not on file</span>
												<?php endif; ?>
												<?php if ( $is_verified && ! empty( $member->verified_at ) ) : ?>
													<span style="color: #666; font-size: 0.85em;">(verified on <?php echo mtl_format_date( $member->verified_at ); ?>)</span>
												<?php endif; ?>
											</p>
											<?php if ( ! $is_verified ) : ?>
												<p style="color: #b32d2e; font-size: 0.85em;">Only one document is on file, so this member is not verified yet. Use Edit to add the other.</p>
											<?php endif; ?>
											<p class="mtl-sensitive-note">These documents contain sensitive personal information. Only open them when necessary, and never share the links.</p>
										<?php else : ?>
											<p style="color: #999;">No verification documents on file. Use Edit to add the member&rsquo;s photo ID and/or proof-of-address scan.</p>
										<?php endif; ?>

										<?php
										// --- Agreements -----------------------------
										//
										// Summary view: version, date, and
										// member-or-staff. The checksum, the staff
										// name, the assent wording and superseded
										// acceptances are all in the record download
										// instead.
										if ( mtl_agreements_tracking() ) :
											$mtl_p_acceptances = isset( $member_acceptances[ $mid ] ) ? $member_acceptances[ $mid ] : array();
											$mtl_p_outstanding = mtl_member_outstanding_agreements( $mid );
											$mtl_p_out_ids     = array_map( 'intval', wp_list_pluck( $mtl_p_outstanding, 'agreement_id' ) );
											$mtl_p_active      = count( $mtl_active_agreements );
											$mtl_p_accepted    = $mtl_p_active - count( $mtl_p_outstanding );
											$mtl_p_open        = ( $record_agreement_id === $mid );
											?>
											<strong>Agreements</strong>
											<?php // Collapsed to the one-line summary: staff scanning a member record need the exception, not the roll call. ?>
											<details class="mtl-agreement-details" <?php echo $mtl_p_open ? 'open' : ''; ?>>
												<summary>
													<span class="<?php echo $mtl_p_outstanding ? 'mtl-agreement-count-flag' : ''; ?>">
														<?php
														printf(
															/* translators: 1: agreements accepted, 2: agreements active. */
															esc_html__( '%1$s of %2$s accepted', 'my-tool-library' ),
															esc_html( number_format_i18n( max( 0, $mtl_p_accepted ) ) ),
															esc_html( number_format_i18n( $mtl_p_active ) )
														);
														?>
													</span>
												</summary>

												<div class="mtl-agreement-panel">
													<?php if ( ! $mtl_p_acceptances && ! $mtl_p_outstanding ) : ?>
														<p style="color:#999;">No agreements have been written yet, so there is nothing to record.</p>
													<?php endif; ?>

													<?php foreach ( $mtl_p_acceptances as $mtl_p_acc ) : ?>
														<?php
														// An agreement that is currently
														// outstanding is shown below with
														// its cross, not here with a tick.
														if ( in_array( (int) $mtl_p_acc->agreement_id, $mtl_p_out_ids, true ) ) {
															continue;
														}
														?>
														<div class="mtl-agreement-line">
															<span class="mtl-agreement-yes">&#10003;</span>
															<div>
																<?php echo nl2br( esc_html( $mtl_p_acc->agreement_text ) ); ?>
																<p class="mtl-record-meta">
																	v<?php echo esc_html( (int) $mtl_p_acc->agreement_version_num ); ?>
																	&middot; agreed <?php echo wp_kses_post( mtl_format_utc_datetime( $mtl_p_acc->accepted_at, 'j M Y' ) ); ?>
																	&middot; <?php echo mtl_acceptance_is_staff( $mtl_p_acc->accepted_context ) ? 'by staff' : 'by the member'; ?>
																</p>
															</div>
														</div>
													<?php endforeach; ?>

													<?php foreach ( $mtl_p_outstanding as $mtl_p_out ) : ?>
														<?php $mtl_p_file = (int) $mtl_p_out->attachment_id > 0 ? wp_get_attachment_url( (int) $mtl_p_out->attachment_id ) : ''; ?>
														<div class="mtl-agreement-line">
															<span class="mtl-agreement-no">&#10007;</span>
															<div>
																<?php echo nl2br( esc_html( $mtl_p_out->agreement_text ) ); ?>
																<p class="mtl-record-meta">
																	Not on record
																	<?php if ( $mtl_p_file ) : ?>
																		<?php // Linked so staff can show it at the desk. ?>
																		&middot; <a href="<?php echo esc_url( $mtl_p_file ); ?>" target="_blank" rel="noopener noreferrer">View the attached document &#8599;</a>
																	<?php endif; ?>
																</p>
															</div>
														</div>
													<?php endforeach; ?>

													<?php
													// Whether the desk can record for THIS member at all;
													// the button additionally needs something outstanding,
													// while the form below stands in for the dialog and
													// says so itself when there is nothing left to record.
													$mtl_p_can_record = mtl_agreements_staff_recording() && ! $is_anonymized;
													?>

													<?php if ( $mtl_p_can_record && $mtl_p_outstanding ) : ?>
														<p class="mtl-record-meta">Once this member has reviewed the agreements and signed, record it with <em>Record agreement</em> below.</p>
													<?php endif; ?>

													<p class="mtl-agreement-actions">
														<?php if ( $mtl_p_can_record && $mtl_p_outstanding ) : ?>
															<?php
															// A real link, so the dialog works with scripting off:
															// following it renders the identical form inline below.
															// A staff member who cannot open the dialog would
															// otherwise have NO way to record a signature.
															$mtl_p_launch = add_query_arg( 'mtl_record_agreement', $mid, $base_url ) . '#mtl-detail-' . $mid;
															?>
															<a class="button mtl-record-launch" data-member="<?php echo esc_attr( $mid ); ?>" href="<?php echo esc_url( $mtl_p_launch ); ?>">Record agreement</a>
														<?php endif; ?>

														<?php if ( mtl_agreements_online() && ! $is_anonymized && $mtl_p_outstanding ) : ?>
															<?php // Writes no status and changes nothing; it only asks. Absent in paper mode, where there is nowhere for the member to act. ?>
															<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display:inline;">
																<?php wp_nonce_field( 'mtl_send_agreement_request_action', 'mtl_send_agreement_request_nonce' ); ?>
																<input type="hidden" name="agreement_member_id" value="<?php echo esc_attr( $mid ); ?>">
																<button type="submit" name="mtl_send_agreement_request" value="1" class="button">Send agreement request</button>
															</form>
														<?php endif; ?>

														<?php if ( $mtl_p_acceptances && ! $is_anonymized ) : ?>
															<?php
															// The remedy when a confirmation failed to send. Offered
															// whenever there is something to resend rather than only
															// after a recorded failure: the plugin stores no
															// mail-failure flag, and a member who deleted the email
															// wants it back for the same reason.
															?>
															<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display:inline;">
																<?php wp_nonce_field( 'mtl_resend_agreement_email_action', 'mtl_resend_agreement_email_nonce' ); ?>
																<input type="hidden" name="agreement_member_id" value="<?php echo esc_attr( $mid ); ?>">
																<button type="submit" name="mtl_resend_agreement_email" value="1" class="button">Resend agreement email</button>
															</form>
														<?php endif; ?>

														<?php if ( mtl_can_manage_settings() ) : ?>
															<?php
															// Administrators only, and offered on Former Member
															// rows too, which is exactly when it is needed, and
															// the reason the identity is retained through
															// deletion at all.
															$mtl_p_record_url = wp_nonce_url(
																add_query_arg( 'mtl_agreement_record', $mid, $base_url ),
																'mtl_agreement_record_action'
															);
															?>
															<a class="button" href="<?php echo esc_url( $mtl_p_record_url ); ?>" target="_blank" rel="noopener">Download agreement record</a>
														<?php endif; ?>
													</p>

													<?php
													// The no-JavaScript container. Identical markup to the
													// dialog below: one form rendered in two places, not
													// two implementations.
													if ( $mtl_p_open && $mtl_p_can_record ) {
														echo '<div class="mtl-record-inline">';
														echo mtl_render_record_agreement_form( $member, $mtl_p_acceptances, $record_agreement_changed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the renderer.
														echo '</div>';
													}
													?>
												</div>
											</details>
										<?php endif; ?>

										<strong>Trainings Completed</strong>
										<?php if ( ! empty( $member_trainings[ $mid ] ) ) : ?>
											<table class="mtl-training-table">
												<thead>
													<tr>
														<th>Training</th>
														<th>Completed</th>
														<th>Expires</th>
														<th>Status</th>
													</tr>
												</thead>
												<tbody>
													<?php foreach ( $member_trainings[ $mid ] as $mtl_t ) : ?>
														<tr>
															<td><?php echo esc_html( $mtl_t['name'] ); ?></td>
															<td><?php echo mtl_format_date( $mtl_t['start_date'] ); ?></td>
															<td><?php echo '' !== $mtl_t['expiry_date'] ? mtl_format_date( $mtl_t['expiry_date'] ) : '<span style="color:#8c8f94;">Never</span>'; ?></td>
															<td>
																<?php if ( $mtl_t['is_current'] ) : ?>
																	<span class="mtl-verified-badge">Current</span>
																<?php else : ?>
																	<span class="mtl-unverified-badge">Expired</span>
																<?php endif; ?>
															</td>
														</tr>
													<?php endforeach; ?>
												</tbody>
											</table>
										<?php else : ?>
											<p style="color: #999;">None on record. Use Edit to record a training this member has completed.</p>
										<?php endif; ?>

										<?php if ( ! empty( $member->private_notes ) ) : ?>
											<strong>Private Notes</strong>
											<div class="mtl-sensitive-note">
												<?php echo nl2br( esc_html( stripslashes( $member->private_notes ) ) ); ?>
											</div>
										<?php endif; ?>
									</div>

									<div class="mtl-detail-col">
										<strong>Borrowing Activity</strong>
										<div class="mtl-member-stats">
											<span class="mtl-member-stat"><b><?php echo esc_html( $total_loans ); ?></b>Total loans</span>
											<span class="mtl-member-stat"><b><?php echo esc_html( $active_count ); ?></b>Active loans</span>
											<?php // Subset of the active loans that are past their due date today. ?>
											<span class="mtl-member-stat <?php echo $overdue_now > 0 ? 'mtl-member-stat-warn' : ''; ?>" title="Active loans that are past their due date right now"><b><?php echo esc_html( $overdue_now ); ?></b>Overdue now</span>
											<span class="mtl-member-stat"><b><?php echo esc_html( $res_count ); ?></b>Active reservations</span>
											<?php // Lifetime count: returned late, plus anything overdue right now. ?>
											<span class="mtl-member-stat <?php echo $past_due > 0 ? 'mtl-member-stat-warn' : ''; ?>" title="Times this member returned a tool late, or is holding one past due now"><b><?php echo esc_html( $past_due ); ?></b>Kept past due</span>
										</div>

										<strong>Currently On Loan</strong>
										<?php if ( $my_loans ) : ?>
											<ul class="mtl-member-list">
												<?php foreach ( $my_loans as $loan ) : ?>
													<li class="mtl-loan-clickable"
														data-loan-id="<?php echo (int) $loan->loan_id; ?>"
														data-member-id="<?php echo (int) $mid; ?>"
														data-tool-name="<?php echo esc_attr( stripslashes( $loan->tool_name ) ); ?>"
														data-due-date="<?php echo esc_attr( $loan->due_date ); ?>"
														<?php // ISO date portion of the checkout timestamp (never mtl_format_date(), which is display-only): the modal's Return date can't go back past this day. ?>
														data-loan-date="<?php echo esc_attr( substr( (string) $loan->loan_date, 0, 10 ) ); ?>"
														title="Click to manage this loan">
														<span>
															<?php echo esc_html( stripslashes( $loan->tool_name ) ); ?>
															<span class="mtl-member-list-meta"><?php echo esc_html( stripslashes( $loan->barcode ) ); ?> &bull; due <?php echo mtl_format_date( $loan->due_date ); ?></span>
														</span>
														<?php if ( (int) $loan->days_past_due > 0 ) : ?>
															<span class="mtl-overdue-flag"><?php echo esc_html( (int) $loan->days_past_due ); ?>d overdue</span>
														<?php endif; ?>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p style="color: #999;">Nothing checked out.</p>
										<?php endif; ?>

										<strong>Active Reservations</strong>
										<?php if ( $my_res ) : ?>
											<ul class="mtl-member-list">
												<?php foreach ( $my_res as $res ) : ?>
													<li class="mtl-res-clickable"
														data-reservation-id="<?php echo (int) $res->reservation_id; ?>"
														data-member-id="<?php echo (int) $mid; ?>"
														data-tool-name="<?php echo esc_attr( stripslashes( $res->tool_name ) ); ?>"
														data-queue-place="<?php echo (int) $res->queue_place; ?>"
														data-queue-size="<?php echo (int) $res->queue_size; ?>"
														data-first-in-queue="<?php echo ( 1 === (int) $res->queue_place ) ? '1' : '0'; ?>"
														title="Click to manage this reservation">
														<?php echo esc_html( stripslashes( $res->tool_name ) ); ?>
														<span class="mtl-member-list-meta"><?php echo esc_html( stripslashes( $res->barcode ) ); ?> &bull; queue #<?php echo esc_html( $res->queue_place ); ?> of <?php echo esc_html( $res->queue_size ); ?></span>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p style="color: #999;">No active reservations.</p>
										<?php endif; ?>
									</div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="11" style="text-align: center; padding: 20px;">
							No members found in the database. Open the panel above to add one!
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<?php
	// ---- Shared Manage Loan modal (one per page; the loan is set by JS
			// when a member's loan list item is clicked). ----
	?>
	<div id="mtl-lm-overlay" class="mtl-lm-overlay" style="display: none;">
		<div class="mtl-lm-modal" role="dialog" aria-modal="true" aria-labelledby="mtl-lm-title">
			<button type="button" class="mtl-lm-close" id="mtl-lm-close" aria-label="Close">&times;</button>
			<h3 id="mtl-lm-title" style="margin-top: 0;">Manage Loan</h3>
			<p class="mtl-lm-tool-line">Tool: <strong id="mtl-lm-tool-name"></strong></p>

			<div class="mtl-lm-section">
				<form method="post" action="<?php echo esc_url( $base_url ); ?>" id="mtl-lm-extend-form">
					<?php wp_nonce_field( 'mtl_member_loan_action', 'mtl_member_loan_nonce' ); ?>
					<input type="hidden" name="loan_id" id="mtl-lm-extend-loan-id" value="">
					<input type="hidden" name="member_id" id="mtl-lm-extend-member-id" value="">

					<label class="mtl-lm-label" for="mtl-lm-due">Extend loan: new due date</label>
					<div class="mtl-lm-due-quick">
						<?php foreach ( array( 7, 14, 21, 30 ) as $lm_days ) : ?>
							<button type="button" class="button button-small mtl-lm-due-btn" data-days="<?php echo (int) $lm_days; ?>"><?php echo (int) $lm_days; ?> days</button>
						<?php endforeach; ?>
					</div>
					<input type="date" name="due_date" id="mtl-lm-due" class="mtl-lm-due-input" value="" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>

					<div class="mtl-lm-actions">
						<button type="submit" name="mtl_member_extend_loan" class="button button-primary">Save New Due Date</button>
					</div>
				</form>
			</div>

			<div class="mtl-lm-section">
				<form method="post" action="<?php echo esc_url( $base_url ); ?>" id="mtl-lm-return-form" onsubmit="return confirm('Mark this tool as returned? This ends the loan.');">
					<?php wp_nonce_field( 'mtl_member_loan_action', 'mtl_member_loan_nonce' ); ?>
					<input type="hidden" name="loan_id" id="mtl-lm-return-loan-id" value="">
					<input type="hidden" name="member_id" id="mtl-lm-return-member-id" value="">
					<?php
					// One modal serves every loan on the page, so the field's
					// `min` (the checkout date) is set by JS in openModal()
					// rather than rendered here.
					echo mtl_return_date_field_html( '', 'mtl-lm-return-date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped markup from the helper.
					?>
					<div class="mtl-lm-actions">
						<button type="submit" name="mtl_member_mark_returned" class="button button-primary">Mark as Returned</button>
						<button type="button" class="button" id="mtl-lm-cancel">Close</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<?php
	// ---- Shared Manage Reservation modal (one per page; the
			// reservation is set by JS when a member's reservation list
			// item is clicked). ----
	?>
	<div id="mtl-rm-overlay" class="mtl-lm-overlay" style="display: none;">
		<div class="mtl-lm-modal" role="dialog" aria-modal="true" aria-labelledby="mtl-rm-title">
			<button type="button" class="mtl-lm-close" id="mtl-rm-close" aria-label="Close">&times;</button>
			<h3 id="mtl-rm-title" style="margin-top: 0;">Manage Reservation</h3>
			<p class="mtl-lm-tool-line">Tool: <strong id="mtl-rm-tool-name"></strong><br>
				<span id="mtl-rm-queue-line"></span>
			</p>

			<div class="mtl-lm-section" id="mtl-rm-start-loan-section" style="display: none;">
				<form method="post" action="<?php echo esc_url( $base_url ); ?>" id="mtl-rm-start-loan-form">
					<?php wp_nonce_field( 'mtl_member_start_loan_action', 'mtl_member_start_loan_nonce' ); ?>
					<input type="hidden" name="reservation_id" id="mtl-rm-start-reservation-id" value="">
					<input type="hidden" name="member_id" id="mtl-rm-start-member-id" value="">

					<label class="mtl-lm-label" for="mtl-rm-due">Start loan: due date</label>
					<div class="mtl-lm-due-quick">
						<?php foreach ( array( 7, 14, 21, 30 ) as $rm_days ) : ?>
							<button type="button" class="button button-small mtl-rm-due-btn<?php echo $rm_days === $mtl_default_loan_days ? ' mtl-lm-due-active' : ''; ?>" data-days="<?php echo (int) $rm_days; ?>"><?php echo (int) $rm_days; ?> days</button>
						<?php endforeach; ?>
					</div>
					<input type="date" name="due_date" id="mtl-rm-due" class="mtl-lm-due-input" value="<?php echo esc_attr( $mtl_default_due_date ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>

					<div class="mtl-lm-actions">
						<button type="submit" name="mtl_member_start_loan" class="button button-primary">Start Loan for This Member</button>
					</div>
				</form>
			</div>

			<p class="mtl-lm-note" id="mtl-rm-not-first-note" style="display: none;">This member is not first in line for this tool, so the loan can't be started from here yet. Check it out from <strong>Loans &amp; Reservations</strong> instead if you need to override the queue.</p>

			<div class="mtl-lm-section">
				<form method="post" action="<?php echo esc_url( $base_url ); ?>" id="mtl-rm-cancel-form" onsubmit="return confirm('Cancel this reservation? This ends it and removes it from the member list.');">
					<?php wp_nonce_field( 'mtl_member_cancel_reservation_action', 'mtl_member_cancel_reservation_nonce' ); ?>
					<input type="hidden" name="reservation_id" id="mtl-rm-cancel-reservation-id" value="">
					<input type="hidden" name="member_id" id="mtl-rm-cancel-member-id" value="">
					<div class="mtl-lm-actions">
						<button type="submit" name="mtl_member_cancel_reservation" class="button mtl-btn-danger">Cancel Reservation</button>
						<button type="button" class="button" id="mtl-rm-close-2">Close</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<?php if ( $members ) : ?>
		<div class="mtl-pagination-bar mtl-pagination-bottom">
			<button type="button" class="button" id="mtl-prev-page">&larr; Previous</button>
			<span id="mtl-page-indicator"></span>
			<button type="button" class="button" id="mtl-next-page">Next &rarr;</button>
		</div>
	<?php endif; ?>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			const searchInput = document.getElementById('mtl-member-search');
			const table = document.getElementById('mtl-members-table');
			const tbody = table.querySelector('tbody');

			// --- Pagination state + control references ---
			let pageSize = 20;
			let currentPage = 1;
			const pageSizeSelect = document.getElementById('mtl-page-size');
			const resultsInfo = document.getElementById('mtl-results-info');
			const pageIndicator = document.getElementById('mtl-page-indicator');
			const prevBtn = document.getElementById('mtl-prev-page');
			const nextBtn = document.getElementById('mtl-next-page');

			// --- Expandable rows (accordion: only one open at a time) ---
			let expandedMemberId = null;

			function collapseRow(memberId) {
				const row = tbody.querySelector('tr.mtl-member-row[data-member-id="' + memberId + '"]');
				const detail = document.getElementById('mtl-detail-' + memberId);
				if (row) row.classList.remove('mtl-row-expanded');
				if (detail) detail.style.display = 'none';
				if (expandedMemberId === memberId) expandedMemberId = null;
			}

			function expandRow(memberId) {
				const row = tbody.querySelector('tr.mtl-member-row[data-member-id="' + memberId + '"]');
				const detail = document.getElementById('mtl-detail-' + memberId);
				if (!row || !detail) return;
				if (expandedMemberId !== null) collapseRow(expandedMemberId);
				row.classList.add('mtl-row-expanded');
				detail.style.display = 'table-row';
				expandedMemberId = memberId;
			}

			tbody.addEventListener('click', function(e) {
				// Ignore clicks on interactive controls (Edit link, Delete
				// button/form), so only plain cell clicks toggle the row.
				if (e.target.closest('a, button, form, input, select, textarea')) {
					return;
				}

				const row = e.target.closest('tr.mtl-member-row');
				if (!row) {
					return;
				}

				const memberId = row.dataset.memberId;
				if (expandedMemberId === memberId) {
					collapseRow(memberId);
				} else {
					expandRow(memberId);
				}
			});

			// --- Advanced search panel toggle ---
			const advToggle = document.getElementById('mtl-toggle-advanced');
			const advPanel = document.getElementById('mtl-advanced-search');
			const clearBtn = document.getElementById('mtl-clear-filters');

			const advFields = {
				name: document.getElementById('adv-m-name'),
				email: document.getElementById('adv-m-email'),
				phone: document.getElementById('adv-m-phone'),
				address: document.getElementById('adv-m-address'),
				signupFrom: document.getElementById('adv-m-signup-from'),
				signupTo: document.getElementById('adv-m-signup-to'),
				donationMin: document.getElementById('adv-m-donation-min'),
				donationMax: document.getElementById('adv-m-donation-max'),
				donated: document.getElementById('adv-m-donated'),
				verified: document.getElementById('adv-m-verified'),
				agreements: document.getElementById('adv-m-agreements'),
				agreement: document.getElementById('adv-m-agreement'),
				agreementVersion: document.getElementById('adv-m-agreement-version'),
				hasNotes: document.getElementById('adv-m-hasnotes'),
				hasActive: document.getElementById('adv-m-has-active'),
				hasPrior: document.getElementById('adv-m-has-prior'),
				hasRes: document.getElementById('adv-m-has-res'),
				hasPastDue: document.getElementById('adv-m-has-pastdue'),
				overdueNow: document.getElementById('adv-m-overdue-now'),
			};

			// The three agreement selects are only rendered when agreement
			// tracking is on, so advFields holds nulls in their place when it
			// is off. Everything that treats the fields as a group (wiring
			// up listeners, clearing them) works from this list instead, so
			// a missing select can't take the whole panel down with it.
			const advFieldEls = Object.values(advFields).filter(Boolean);

			// --- Trainings multi-select ---
			// Deliberately NOT part of advFields: everything in there is a
			// plain input the "Clear Filters" button blanks with .value = '',
			// and this is a checkbox list behind a popover instead. It gets
			// reset explicitly further down.
			const msRoot   = document.getElementById('adv-m-trainings');
			const msToggle = document.getElementById('adv-m-trainings-toggle');
			const msPanel  = document.getElementById('adv-m-trainings-panel');
			const msAll    = document.getElementById('adv-m-trainings-all');
			const msOpts   = msRoot ? Array.prototype.slice.call(msRoot.querySelectorAll('.mtl-ms-opt')) : [];

			// Ids of every ticked training. The filter requires a member to
			// hold ALL of them, so "Select all" naturally means "has completed
			// every training".
			function selectedTrainingIds() {
				return msOpts.filter(function(cb) { return cb.checked; })
					.map(function(cb) { return cb.value; });
			}

			// The button label doubles as the collapsed summary of the selection.
			function refreshMsLabel() {
				if (!msToggle) { return; }
				const picked = msOpts.filter(function(cb) { return cb.checked; });
				if (picked.length === 0) {
					msToggle.textContent = 'Any';
				} else if (picked.length === msOpts.length) {
					msToggle.textContent = 'All trainings';
				} else if (picked.length === 1) {
					msToggle.textContent = picked[0].parentNode.querySelector('span').textContent;
				} else {
					msToggle.textContent = picked.length + ' selected';
				}
			}

			function closeMsPanel() {
				if (!msPanel) { return; }
				msPanel.style.display = 'none';
				msToggle.setAttribute('aria-expanded', 'false');
			}

			if (msRoot) {
				msToggle.addEventListener('click', function(e) {
					e.stopPropagation();
					const isOpen = msPanel.style.display !== 'none';
					msPanel.style.display = isOpen ? 'none' : 'block';
					msToggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
				});

				// Clicks inside the panel must not reach the close-on-outside
				// handler below, or ticking a box would shut the panel.
				msPanel.addEventListener('click', function(e) {
					e.stopPropagation();
				});

				msAll.addEventListener('change', function() {
					msOpts.forEach(function(cb) { cb.checked = msAll.checked; });
					refreshMsLabel();
					applyFilters();
				});

				msOpts.forEach(function(cb) {
					cb.addEventListener('change', function() {
						// Keep "Select all" honest: ticked only while genuinely
						// everything below it is.
						msAll.checked = msOpts.every(function(o) { return o.checked; });
						refreshMsLabel();
						applyFilters();
					});
				});

				document.addEventListener('click', closeMsPanel);
				document.addEventListener('keydown', function(e) {
					if (e.key === 'Escape') { closeMsPanel(); }
				});
			}

			// --- Agreement version dropdown ---
			// Populated from the versions members have ACTUALLY accepted, not
			// counted up to the current version_num: a version nobody ever
			// accepted must not be offered as a filter that returns nothing.
			const agreementVersions = <?php echo wp_json_encode( $mtl_agreement_version_options ); ?>;

			if (advFields.agreement && advFields.agreementVersion) {
				advFields.agreement.addEventListener('change', function() {
					const sel = advFields.agreementVersion;
					const chosen = advFields.agreement.value;
					sel.innerHTML = '';
					const any = document.createElement('option');
					any.value = '';
					any.textContent = 'Any version';
					sel.appendChild(any);

					const versions = chosen && agreementVersions[chosen] ? agreementVersions[chosen] : [];
					versions.forEach(function(v) {
						const opt = document.createElement('option');
						opt.value = String(v);
						opt.textContent = 'Version ' + v;
						sel.appendChild(opt);
					});

					// Nothing to choose between with no agreement picked, and
					// nothing to choose between when only one version was ever
					// accepted, but left enabled in the second case so the
					// admin can still narrow to it deliberately.
					sel.disabled = !chosen || versions.length === 0;
					applyFilters();
				});
			}

			advToggle.addEventListener('click', function() {
				const isOpen = advPanel.style.display !== 'none';
				advPanel.style.display = isOpen ? 'none' : 'block';
				advToggle.textContent = isOpen ? 'Advanced Search' : 'Hide Advanced Search';
			});

			// --- Combined quick filter + advanced search ---
			// A row must pass the quick filter (substring match anywhere in the
			// row) AND every non-empty advanced field to stay visible.
			function applyFilters() {
				const quick = searchInput.value.trim().toLowerCase();

				const f = {
					name: advFields.name.value.trim().toLowerCase(),
					email: advFields.email.value.trim().toLowerCase(),
					phone: advFields.phone.value.trim().toLowerCase(),
					address: advFields.address.value.trim().toLowerCase(),
					signupFrom: advFields.signupFrom.value,
					signupTo: advFields.signupTo.value,
					donationMin: advFields.donationMin.value !== '' ? parseFloat(advFields.donationMin.value) : null,
					donationMax: advFields.donationMax.value !== '' ? parseFloat(advFields.donationMax.value) : null,
					donated: advFields.donated.value,
					verified: advFields.verified.value,
					agreements: advFields.agreements ? advFields.agreements.value : '',
					agreement: advFields.agreement ? advFields.agreement.value : '',
					agreementVersion: advFields.agreementVersion ? advFields.agreementVersion.value : '',
					hasNotes: advFields.hasNotes.value,
					hasActive: advFields.hasActive.value,
					hasPrior: advFields.hasPrior.value,
					hasRes: advFields.hasRes.value,
					hasPastDue: advFields.hasPastDue.value,
					overdueNow: advFields.overdueNow.value,
					trainings: selectedTrainingIds(),
				};

				// Only real member rows are filtered; detail rows follow
				// their parent row's visibility instead of being matched
				// directly, and the "No members found" placeholder has no
				// dataset to match on.
				tbody.querySelectorAll('tr.mtl-member-row').forEach(function(row) {
					const d = row.dataset;
					let visible = true;

					if (quick && !row.textContent.toLowerCase().includes(quick)) visible = false;
					if (visible && f.name && !d.name.includes(f.name)) visible = false;
					if (visible && f.email && !d.email.includes(f.email)) visible = false;
					if (visible && f.phone && !d.phone.includes(f.phone)) visible = false;
					if (visible && f.address && !d.address.includes(f.address)) visible = false;
					if (visible && f.signupFrom && d.signup < f.signupFrom) visible = false;
					if (visible && f.signupTo && d.signup > f.signupTo) visible = false;
					if (visible && f.donated && d.donated !== f.donated) visible = false;
					if (visible && f.verified && d.verified !== f.verified) visible = false;
					if (visible && f.hasNotes && d.hasnotes !== f.hasNotes) visible = false;
					if (visible && f.agreements && d.agreements !== f.agreements) visible = false;

					// "Accepted agreement / version" reads EVERY acceptance, not
					// just the newest, since a member who accepted v2 and has since
					// accepted v3 must still be returned when searching for v2.
					// data-agreement-vers is comma-wrapped (",3:1,3:2,") so
					// testing ",3:1," cannot match agreement 13 or version 12.
					if (visible && f.agreement) {
						const accepted = d.agreementVers || '';
						if (f.agreementVersion) {
							if (accepted.indexOf(',' + f.agreement + ':' + f.agreementVersion + ',') === -1) visible = false;
						} else if (accepted.indexOf(',' + f.agreement + ':') === -1) {
							visible = false;
						}
					}

					// Borrowing-activity booleans. The selects use "1"/"0" and
					// the rows carry matching flags, so an empty value ("Any")
					// simply skips the check, which lets the admin filter for
					// either side of each question.
					if (visible && f.hasActive && d.hasActive !== f.hasActive) visible = false;
					if (visible && f.hasPrior && d.hasPrior !== f.hasPrior) visible = false;
					if (visible && f.hasRes && d.hasRes !== f.hasRes) visible = false;
					if (visible && f.hasPastDue && d.hasPastdue !== f.hasPastDue) visible = false;
					if (visible && f.overdueNow && d.overdueNow !== f.overdueNow) visible = false;

					// Trainings: the member must hold EVERY ticked one, and
					// hold it currently. data-trainings is comma-wrapped
					// (",2,7,") so testing ",7," can't match id 7 inside 17.
					if (visible && f.trainings.length) {
						const held = d.trainings || '';
						for (let i = 0; i < f.trainings.length; i++) {
							if (held.indexOf(',' + f.trainings[i] + ',') === -1) {
								visible = false;
								break;
							}
						}
					}

					if (visible && (f.donationMin !== null || f.donationMax !== null)) {
						const donation = parseFloat(d.donation);
						if (f.donationMin !== null && donation < f.donationMin) visible = false;
						if (f.donationMax !== null && donation > f.donationMax) visible = false;
					}

					// Mark the row's match state; renderPage() below turns that
					// into actual visibility for the current page window.
					row.dataset.matched = visible ? '1' : '0';
				});

				// A filter change always returns to the first page.
				currentPage = 1;
				renderPage();
			}

			// --- Pagination: show only the matched rows for the current page ---
			function renderPage() {
				const allRows = Array.from(tbody.querySelectorAll('tr.mtl-member-row'));
				const matched = allRows.filter(function(r) { return r.dataset.matched !== '0'; });
				const total = matched.length;
				const totalPages = Math.max(1, Math.ceil(total / pageSize));
				if (currentPage > totalPages) currentPage = totalPages;
				if (currentPage < 1) currentPage = 1;
				const start = (currentPage - 1) * pageSize;
				const end = start + pageSize;

				// Collapse any open detail row, then hide every row + detail.
				if (expandedMemberId !== null) collapseRow(expandedMemberId);
				allRows.forEach(function(row) {
					row.style.display = 'none';
					const detail = document.getElementById('mtl-detail-' + row.dataset.memberId);
					if (detail) detail.style.display = 'none';
				});

				// Reveal just this page's slice of the matched rows.
				matched.forEach(function(row, i) {
					if (i >= start && i < end) row.style.display = '';
				});

				const shownStart = total === 0 ? 0 : start + 1;
				const shownEnd = Math.min(end, total);
				if (resultsInfo) {
					resultsInfo.innerHTML = total === 0
						? 'No matching members'
						: 'Showing <strong>' + shownStart + '–' + shownEnd + '</strong> of <strong>' + total + '</strong> members';
				}
				if (pageIndicator) pageIndicator.textContent = 'Page ' + currentPage + ' of ' + totalPages;
				if (prevBtn) prevBtn.disabled = currentPage <= 1;
				if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
			}

			if (pageSizeSelect) {
				pageSizeSelect.addEventListener('change', function() {
					pageSize = parseInt(this.value, 10) || 20;
					currentPage = 1;
					renderPage();
				});
			}
			if (prevBtn) {
				prevBtn.addEventListener('click', function() {
					if (currentPage > 1) { currentPage--; renderPage(); }
				});
			}
			if (nextBtn) {
				nextBtn.addEventListener('click', function() {
					currentPage++;
					renderPage();
				});
			}

			searchInput.addEventListener('keyup', applyFilters);
			advFieldEls.forEach(function(el) {
				el.addEventListener('input', applyFilters);
				el.addEventListener('change', applyFilters);
			});

			clearBtn.addEventListener('click', function() {
				searchInput.value = '';
				advFieldEls.forEach(function(el) {
					el.value = '';
				});
				// The trainings picker is checkboxes, not a value-bearing
				// input, so it needs clearing on its own terms.
				if (msRoot) {
					msOpts.forEach(function(cb) { cb.checked = false; });
					msAll.checked = false;
					refreshMsLabel();
					closeMsPanel();
				}
				applyFilters();
			});

			// --- Column sorting ---
			const headers = document.querySelectorAll('#mtl-members-table th.sortable');
			headers.forEach(header => {
				header.addEventListener('click', () => {
					const table = header.closest('table');
					const tbody = table.querySelector('tbody');
					const index = Array.from(header.parentElement.children).indexOf(header);
					const isAscending = header.classList.contains('asc');

					headers.forEach(h => h.classList.remove('asc', 'desc'));
					header.classList.add(isAscending ? 'desc' : 'asc');

					// Detail rows only have a single (colspan) cell, so they
					// must be excluded from the comparison, since indexing into
					// their children would break. Each is re-attached right
					// after its own member row below so expand/collapse still
					// works post-sort.
					const rows = Array.from(tbody.querySelectorAll('tr.mtl-member-row'));

					// Date columns (currently just "Signed Up") are marked with
					// data-date-col on the <th> and compare the row's ISO
					// data-* attribute instead of the visible cell text: the
					// cell now reads MM/DD/YYYY, which does not sort into date
					// order as a plain string the way YYYY-MM-DD does.
					const dateCol = header.dataset.dateCol;

					rows.sort((a, b) => {
						if (dateCol) {
							const aIso = a.dataset[dateCol] || '';
							const bIso = b.dataset[dateCol] || '';
							return isAscending ? bIso.localeCompare(aIso) : aIso.localeCompare(bIso);
						}

						const aText = a.children[index].textContent.trim();
						const bText = b.children[index].textContent.trim();

						const aNum = parseFloat(aText.replace(/[^0-9.-]+/g, ""));
						const bNum = parseFloat(bText.replace(/[^0-9.-]+/g, ""));

						if (!isNaN(aNum) && !isNaN(bNum) && (aText.includes('$') || index === 0)) {
							return isAscending ? bNum - aNum : aNum - bNum;
						}

						return isAscending ? bText.localeCompare(aText) : aText.localeCompare(bText);
					});

					rows.forEach(row => {
						tbody.appendChild(row);
						const detail = document.getElementById('mtl-detail-' + row.dataset.memberId);
						if (detail) tbody.appendChild(detail);
					});

					// Re-page after re-ordering, back to the first page.
					currentPage = 1;
					renderPage();
				});
			});

			// --- Resizable columns ---
			// A thin grip on each header cell's right edge drags its width.
			// The table uses fixed layout (the "fixed" class), so a th's width
			// dictates its whole column.
			table.querySelectorAll('thead th').forEach(function(th) {
				const grip = document.createElement('span');
				grip.className = 'mtl-col-resizer';
				th.appendChild(grip);

				// A click on the grip must not also trigger the column's sort.
				grip.addEventListener('click', function(e) { e.stopPropagation(); });

				grip.addEventListener('mousedown', function(e) {
					e.preventDefault();
					e.stopPropagation();
					const startX = e.pageX;
					const startWidth = th.getBoundingClientRect().width;

					function onMove(ev) {
						const w = Math.max(40, startWidth + (ev.pageX - startX));
						th.style.width = w + 'px';
					}
					function onUp() {
						document.removeEventListener('mousemove', onMove);
						document.removeEventListener('mouseup', onUp);
						document.body.style.userSelect = '';
					}
					document.addEventListener('mousemove', onMove);
					document.addEventListener('mouseup', onUp);
					document.body.style.userSelect = 'none';
				});
			});

			// Establish the initial paginated view (all rows matched, page 1).
			applyFilters();
		});
	</script>

	<script>
		// ---- Manage Loan modal ----
		// Registered after the script above (which owns tbody's row-expand
		// click delegation), so reopenRow.click() below has a listener to hit.
		document.addEventListener('DOMContentLoaded', function() {
			const overlay = document.getElementById('mtl-lm-overlay');
			if (!overlay) return;
			const toolNameEl      = document.getElementById('mtl-lm-tool-name');
			const dueInput        = document.getElementById('mtl-lm-due');
			const dueButtons      = overlay.querySelectorAll('.mtl-lm-due-btn');
			const extendLoanId    = document.getElementById('mtl-lm-extend-loan-id');
			const extendMemberId  = document.getElementById('mtl-lm-extend-member-id');
			const returnLoanId    = document.getElementById('mtl-lm-return-loan-id');
			const returnMemberId  = document.getElementById('mtl-lm-return-member-id');
			const returnDate      = document.getElementById('mtl-lm-return-date');

			function dateFromToday(days) {
				const d = new Date();
				d.setDate(d.getDate() + days);
				const mm = String(d.getMonth() + 1).padStart(2, '0');
				const dd = String(d.getDate()).padStart(2, '0');
				return d.getFullYear() + '-' + mm + '-' + dd;
			}

			function clearActiveDueButton() {
				dueButtons.forEach(function(b) {
					b.classList.remove('mtl-lm-due-active');
				});
			}

			function openModal(li) {
				const loanId   = li.dataset.loanId;
				const memberId = li.dataset.memberId;
				toolNameEl.textContent = li.dataset.toolName;
				extendLoanId.value   = loanId;
				extendMemberId.value = memberId;
				returnLoanId.value   = loanId;
				returnMemberId.value = memberId;
				dueInput.value = li.dataset.dueDate;
				// Reset the return date to today for each loan opened (the
				// modal is reused), and floor it at this loan's checkout day.
				if (returnDate) {
					returnDate.value = returnDate.max;
					returnDate.min   = li.dataset.loanDate || '';
				}
				clearActiveDueButton();
				overlay.style.display = 'flex';
			}

			function closeModal() {
				overlay.style.display = 'none';
			}

			// Open from any loan's list item in a member's detail panel.
			document.querySelectorAll('.mtl-loan-clickable').forEach(function(li) {
				li.addEventListener('click', function() {
					openModal(li);
				});
			});

			document.getElementById('mtl-lm-close').addEventListener('click', closeModal);
			document.getElementById('mtl-lm-cancel').addEventListener('click', closeModal);
			// Click on the dark backdrop (but not the modal itself) closes it.
			overlay.addEventListener('mousedown', function(e) {
				if (e.target === overlay) closeModal();
			});
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' && overlay.style.display !== 'none') closeModal();
			});

			// --- Quick due-date buttons (always relative to today, matching
			// the same pattern used by Quick Loan and Loans & Reservations). ---
			dueButtons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					dueInput.value = dateFromToday(parseInt(btn.dataset.days, 10));
					clearActiveDueButton();
					btn.classList.add('mtl-lm-due-active');
				});
			});

			<?php
			// If a loan/reservation action above just succeeded, reopen that
					// member's detail row so staff can see the result without having
					// to re-find and re-click the member.
			?>
			<?php if ( $reopen_member_id > 0 ) : ?>
				const reopenRow = document.querySelector('.mtl-member-row[data-member-id="<?php echo (int) $reopen_member_id; ?>"]');
				if (reopenRow) {
					reopenRow.click();
					reopenRow.scrollIntoView({ block: 'center' });
				}
			<?php endif; ?>
		});
	</script>

	<script>
		// ---- Manage Reservation modal ----
		document.addEventListener('DOMContentLoaded', function() {
			const overlay = document.getElementById('mtl-rm-overlay');
			if (!overlay) return;
			const toolNameEl        = document.getElementById('mtl-rm-tool-name');
			const queueLineEl       = document.getElementById('mtl-rm-queue-line');
			const startSection      = document.getElementById('mtl-rm-start-loan-section');
			const notFirstNote      = document.getElementById('mtl-rm-not-first-note');
			const dueInput          = document.getElementById('mtl-rm-due');
			const dueButtons        = overlay.querySelectorAll('.mtl-rm-due-btn');
			const startReservationId = document.getElementById('mtl-rm-start-reservation-id');
			const startMemberId     = document.getElementById('mtl-rm-start-member-id');
			const cancelReservationId = document.getElementById('mtl-rm-cancel-reservation-id');
			const cancelMemberId    = document.getElementById('mtl-rm-cancel-member-id');
			const defaultDueDate    = dueInput.value;

			function dateFromToday(days) {
				const d = new Date();
				d.setDate(d.getDate() + days);
				const mm = String(d.getMonth() + 1).padStart(2, '0');
				const dd = String(d.getDate()).padStart(2, '0');
				return d.getFullYear() + '-' + mm + '-' + dd;
			}

			function clearActiveDueButton() {
				dueButtons.forEach(function(b) {
					b.classList.remove('mtl-lm-due-active');
				});
			}

			function openModal(li) {
				const reservationId = li.dataset.reservationId;
				const memberId      = li.dataset.memberId;
				const firstInQueue  = li.dataset.firstInQueue === '1';

				toolNameEl.textContent = li.dataset.toolName;
				queueLineEl.textContent = 'Queue position: #' + li.dataset.queuePlace + ' of ' + li.dataset.queueSize;

				startReservationId.value = reservationId;
				startMemberId.value      = memberId;
				cancelReservationId.value = reservationId;
				cancelMemberId.value      = memberId;

				<?php
				// Visibility follows the "if and only if first in queue"
						// rule; the server re-verifies this authoritatively before
						// ever creating the loan, since queue order can change
						// between page load and submit.
				?>
				startSection.style.display = firstInQueue ? 'block' : 'none';
				notFirstNote.style.display = firstInQueue ? 'none' : 'block';

				dueInput.value = defaultDueDate;
				clearActiveDueButton();
				overlay.querySelectorAll('.mtl-rm-due-btn').forEach(function(b) {
					if (parseInt(b.dataset.days, 10) === <?php echo (int) $mtl_default_loan_days; ?>) {
						b.classList.add('mtl-lm-due-active');
					}
				});

				overlay.style.display = 'flex';
			}

			function closeModal() {
				overlay.style.display = 'none';
			}

			// Open from any reservation's list item in a member's detail panel.
			document.querySelectorAll('.mtl-res-clickable').forEach(function(li) {
				li.addEventListener('click', function() {
					openModal(li);
				});
			});

			document.getElementById('mtl-rm-close').addEventListener('click', closeModal);
			document.getElementById('mtl-rm-close-2').addEventListener('click', closeModal);
			overlay.addEventListener('mousedown', function(e) {
				if (e.target === overlay) closeModal();
			});
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' && overlay.style.display !== 'none') closeModal();
			});

			dueButtons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					dueInput.value = dateFromToday(parseInt(btn.dataset.days, 10));
					clearActiveDueButton();
					btn.classList.add('mtl-lm-due-active');
				});
			});
		});
	</script>

	<script>
		// Record Agreement: moves the form the detail panel already rendered
		// inline into a native <dialog>. The launcher is a real link, so with
		// scripting off it just navigates and the inline form is what you get.
		document.addEventListener('DOMContentLoaded', function() {
			const launchers = document.querySelectorAll('.mtl-record-launch');
			if (!launchers.length || typeof HTMLDialogElement === 'undefined') {
				return;
			}

			launchers.forEach(function(link) {
				link.addEventListener('click', function(e) {
					const memberId = link.dataset.member;
					const inline = document.querySelector('#mtl-detail-' + memberId + ' .mtl-record-inline');

					// The form is only in the DOM once the link has been
					// followed. On a first click there is nothing to move, so
					// let the navigation happen and the dialog opens on the
					// way back.
					if (!inline) {
						return;
					}

					e.preventDefault();

					let dialog = document.getElementById('mtl-record-dialog-' + memberId);
					if (!dialog) {
						dialog = document.createElement('dialog');
						dialog.id = 'mtl-record-dialog-' + memberId;
						dialog.className = 'mtl-record-dialog';
						// Announces as "Record agreement, <name>" rather than
						// just "dialog".
						dialog.setAttribute('aria-labelledby', 'mtl-record-title-' + memberId);
						dialog.appendChild(inline.firstElementChild ? inline : inline);
						document.body.appendChild(dialog);
					}

					// showModal() gives focus containment and Escape for free,
					// and returns focus to the launcher on close.
					dialog.showModal();
					const first = dialog.querySelector('input[type="checkbox"], button, [href]');
					if (first) { first.focus(); }
				});
			});

			// A dialog rendered open on page load: the link was followed
			// without scripting intercepting it, so upgrade it now. The inline
			// container stays in the DOM as the fallback.
			const openInline = document.querySelector('.mtl-record-inline');
			if (openInline) {
				const form = openInline.querySelector('.mtl-record-agreement-form');
				const memberInput = form ? form.querySelector('[name="agreement_member_id"]') : null;
				if (memberInput) {
					const launcher = document.querySelector('.mtl-record-launch[data-member="' + memberInput.value + '"]');
					if (launcher) { launcher.click(); }
				}
			}
		});
	</script>
	<?php
	// Covers both the Add and Edit forms' phone widgets in one call;
	// mtl_phone_formatter_script() queries every .mtl-phone-widget on the page.
	mtl_phone_formatter_script();
	// Same one-call-covers-every-instance deal: the Add and Edit forms each
	// render their own trainings picker.
	mtl_trainings_picker_script();
	echo '</div>';
}
