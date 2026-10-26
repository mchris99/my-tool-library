<?php
/**
 * Public member accounts (server-side rendered, no JavaScript required).
 *
 * Public-facing member signup, sign-in, and account management, all plain GET
 * pages and POST forms, matching the public shop page (public/shop-page.php).
 *
 *   mtl_page=signup        : create a member account
 *   mtl_page=reservations  : "My loans & reservations": active loans with a
 *                              due-soon/overdue status, reservation queue,
 *                              place in line, cancel
 *   mtl_page=account       : profile, verification status, past loans, edits
 *
 * Sign-in itself is core WordPress (wp_login_form on the shared login page in
 * my-tool-library.php). Passwords go to WordPress core and are never stored
 * in the plugin's tables.
 *
 * A user's mtl_member_id meta links them to their {prefix}members row; see
 * mtl_current_member() for why that link is checked before it is trusted.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Minimum member password length; WordPress handles the actual hashing/storage.
if ( ! defined( 'MTL_MIN_PASSWORD_LENGTH' ) ) {
	define( 'MTL_MIN_PASSWORD_LENGTH', 8 );
}

// --------------------------------------------------------------------------
// Member role
// --------------------------------------------------------------------------
// Low-privilege role that sets members apart from administrators in the WP
// Users list. Registered on init, not on activation, because activation hooks
// don't run when the plugin is updated.
add_action( 'init', 'mtl_register_member_role' );

/**
 * Registers the low-privilege "mtl_member" role, if not already present.
 *
 * @return void
 */
function mtl_register_member_role() {
	if ( ! get_role( 'mtl_member' ) ) {
		add_role( 'mtl_member', 'Tool Library Member', array( 'read' => true ) );
	}
}

// --------------------------------------------------------------------------
// Shared helpers
// --------------------------------------------------------------------------

/**
 * Whether this request is a form submission.
 *
 * @return bool
 */
function mtl_is_post_request() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	return 'POST' === $method;
}

/**
 * Find a live membership record by email address.
 *
 * The email column is UNIQUE, so this matches at most one row. Anonymized
 * rows are deleted people and never match.
 *
 * @param string $email Email address to look up.
 * @return object|null Member row, or null when no live record has that address.
 */
function mtl_find_member_by_email( $email ) {
	global $wpdb;
	$tbl = $wpdb->prefix . 'members';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE email = %s AND anonymized_at IS NULL LIMIT 1", $email ) );
}

/**
 * Get the {prefix}members row for the logged-in user, but only when that row
 * can be shown to belong to them.
 *
 * The stored mtl_member_id is a cache, not proof. member_id restarts at 1 when
 * the Setup page rebuilds the tables but WordPress accounts survive, so an old
 * id can point at another person's row. The row is trusted only when its
 * email matches the account's; otherwise the account's email finds the right
 * row and the id is repaired. With no match this returns null (see
 * mtl_current_member_link_broken()), failing closed because a lockout is
 * recoverable and a disclosure isn't.
 *
 * Cached per request. That is safe because every write to the member row ends
 * in a redirect, so nothing may switch the current user after this runs
 * without also redirecting.
 *
 * Multisite: usermeta is network-wide but {prefix}members is per site, so the
 * email check is what fails closed there too. A per-site meta key would be the
 * real fix, and needs a migration.
 *
 * @return object|null Member row, or null if the current user isn't a member
 *                     or their record could not be matched.
 */
function mtl_current_member() {
	static $resolved = false;
	static $member   = null;

	if ( $resolved ) {
		return $member;
	}
	$resolved = true;

	if ( ! is_user_logged_in() ) {
		return $member;
	}

	$user_id   = get_current_user_id();
	$member_id = (int) get_user_meta( $user_id, 'mtl_member_id', true );
	if ( $member_id <= 0 ) {
		// Never was a member account (e.g. an administrator). Deliberately not
		// recovered by email: staff accounts should not be auto-linked to a
		// member row that happens to share an address.
		return $member;
	}

	// The account's own email is the identity proof; with none there is
	// nothing to check the stored link against.
	$user       = wp_get_current_user();
	$user_email = $user ? trim( (string) $user->user_email ) : '';
	if ( '' === $user_email ) {
		return $member;
	}

	global $wpdb;
	$tbl = $wpdb->prefix . 'members';

	// Anonymized rows are deleted people, never a record to serve.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$candidate = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$tbl} WHERE member_id = %d AND anonymized_at IS NULL", $member_id )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $candidate && 0 === strcasecmp( trim( (string) $candidate->email ), $user_email ) ) {
		// Normal case, and the only path on a healthy install: no extra query,
		// no write. The email came from the already-loaded WP_User.
		$member = $candidate;
		return $member;
	}

	// Stale or mismatched link. Re-resolve on the account's own address, which
	// matches at most one live record; see mtl_find_member_by_email().
	$recovered = mtl_find_member_by_email( $user_email );

	if ( $recovered ) {
		// Assigned BEFORE the meta write: update_user_meta() fires actions, and
		// a hook that called back in here would otherwise find $resolved
		// already true with $member still null.
		$member = $recovered;
		update_user_meta( $user_id, 'mtl_member_id', (int) $recovered->member_id );
		return $member;
	}

	// Nothing proved this row belongs to this account, so fail closed.
	return $member;
}

/**
 * Whether the signed-in user looks like a member whose record could not be
 * matched, rather than simply not a member at all.
 *
 * Lets the member-only notice tell a locked-out member ("we couldn't match
 * your record, contact staff") apart from an administrator who was never a
 * member ("this area is for member accounts"), which are the same null from
 * mtl_current_member() but very different messages to read.
 *
 * @return bool
 */
function mtl_current_member_link_broken() {
	if ( ! is_user_logged_in() || mtl_current_member() ) {
		return false;
	}
	return (int) get_user_meta( get_current_user_id(), 'mtl_member_id', true ) > 0;
}

/**
 * Whether a member is fully verified.
 *
 * Only administrators record verification (Membership admin page); this file
 * only ever removes it. A row can hold just one of the two scan URLs, so both
 * must be present. See also mtl_verification_urls_complete() in
 * my-tool-library.php.
 *
 * @param int $member_id Member ID.
 * @return bool
 */
function mtl_member_is_verified( $member_id ) {
	global $wpdb;
	$tbl = $wpdb->prefix . 'member_verifications';
	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT member_id FROM {$tbl} WHERE member_id = %d AND photo_id_scan_url IS NOT NULL AND address_proof_scan_url IS NOT NULL",
			(int) $member_id
		)
	);
}

/**
 * The verification scan links on file for a member, either or both.
 *
 * @param int $member_id Member row ID.
 * @return array<string,string> Label => URL for each scan on file; empty if none.
 */
function mtl_member_verification_doc_urls( $member_id ) {
	global $wpdb;
	$tbl = $wpdb->prefix . 'member_verifications';
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT photo_id_scan_url, address_proof_scan_url FROM {$tbl} WHERE member_id = %d",
			(int) $member_id
		)
	);
	$urls = array();
	if ( $row && '' !== trim( (string) $row->photo_id_scan_url ) ) {
		$urls['Photo ID scan'] = trim( (string) $row->photo_id_scan_url );
	}
	if ( $row && '' !== trim( (string) $row->address_proof_scan_url ) ) {
		$urls['Proof of address scan'] = trim( (string) $row->address_proof_scan_url );
	}
	return $urls;
}

/**
 * Emails the site administrator the scan links that a member's details change
 * just removed, so the files themselves can be deleted.
 *
 * Goes to the site admin rather than the public contact address, for the same
 * reason as mtl_send_verification_cleanup_email().
 *
 * @param int    $member_id Member row ID.
 * @param string $name      The member's name as just saved.
 * @param string $email     The member's email address.
 * @param array  $doc_urls  Label => URL for each scan that was on file.
 * @return bool True if the mail was handed off.
 */
function mtl_send_verification_reset_email( $member_id, $name, $email, $doc_urls ) {
	$admin_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
	$sent        = false;
	if ( is_email( $admin_email ) && $doc_urls ) {
		$org_name = mtl_email_org_name();
		$lines    = array(
			sprintf( '%s (member #%d, %s) changed their name, phone number or address on their Account page on %s.', $name, (int) $member_id, $email, wp_date( 'F j, Y \a\t g:i a' ) ),
			'',
			'Their verification documents no longer match their details, so the links below were removed from the library\'s records. The member will need to provide new documents to be verified.',
			'',
			'The files themselves are stored outside the database and could not be deleted automatically. Please delete them from wherever the library keeps them:',
			'',
		);
		foreach ( $doc_urls as $label => $url ) {
			$lines[] = sprintf( '  %s: %s', $label, $url );
		}
		$lines[] = '';
		$lines[] = 'Once the files are gone, please delete this email too, because it is the last copy of those links.';
		$lines[] = '';
		$lines[] = sprintf( '-- %s', $org_name );

		$sent = (bool) wp_mail( $admin_email, sprintf( '[%s] Member changed their details: please delete their old verification files', $org_name ), implode( "\r\n", $lines ) );
	}
	if ( ! $sent ) {
		error_log( sprintf( 'My Tool Library: member #%d changed their details and their verification links were removed, but the email to the site administrator could not be sent.', (int) $member_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational record of a failed admin email, not debug output.
	}
	return $sent;
}

/**
 * Whether a POST came from a page on this site, judged by its Origin header,
 * or its Referer when there's no Origin. A request carrying neither passes,
 * since some browsers and privacy tools strip both.
 *
 * @return bool
 */
function mtl_request_from_this_site() {
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) ) : '';

	// Browsers send "Origin: null" from sandboxed frames, but also from this
	// site's own forms when it uses Referrer-Policy: no-referrer. The fetch
	// metadata header tells the two apart where the browser sends it.
	if ( 'null' === $origin ) {
		$fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		if ( '' !== $fetch_site ) {
			return 'same-origin' === $fetch_site;
		}
		$origin = '';
	}

	$source = '';
	if ( '' !== $origin ) {
		$source = esc_url_raw( $origin );
	} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$source = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
	}
	if ( '' === $source ) {
		return true;
	}

	$host = wp_parse_url( $source, PHP_URL_HOST );
	return is_string( $host ) && 0 === strcasecmp( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

/**
 * Emails the next step to someone who tried to sign up with an address that's
 * already registered, so the signup form never has to say that it is.
 *
 * A member staff added, with no website sign-in yet, gets the same set-password
 * email the lost-password page sends. Anyone with a sign-in gets a note to sign
 * in or reset their password. At most one email per address per hour, so the
 * form can't be used to flood someone's inbox.
 *
 * @param string $email Address submitted on the signup form.
 * @return void
 */
function mtl_send_signup_existing_email( $email ) {
	// Its links lead to pages that are closed before launch.
	if ( ! mtl_library_is_live() ) {
		return;
	}
	$throttle = 'mtl_signup_taken_' . md5( strtolower( $email ) );
	if ( get_transient( $throttle ) ) {
		return;
	}
	set_transient( $throttle, 1, HOUR_IN_SECONDS );

	if ( ! email_exists( $email ) ) {
		mtl_setup_login_for_unclaimed_member( $email );
		return;
	}

	$org_name = mtl_email_org_name();
	$lines    = array(
		sprintf( 'Someone tried to create a new account at %s with this email address, but you already have one.', $org_name ),
		'',
		'Sign in here:',
		mtl_front_page_url( 'login' ),
		'',
		'Forgot your password? Choose a new one here:',
		mtl_front_page_url( 'lostpassword' ),
		'',
		'If this wasn\'t you, you can ignore this email. Nothing about your account has changed.',
		'',
		sprintf( '-- %s', $org_name ),
	);
	wp_mail( $email, sprintf( '[%s] You already have an account', $org_name ), implode( "\r\n", $lines ) );
}

/**
 * Text + severity for a one-off status banner, keyed by the mtl_msg the
 * post-action PRG redirects carry. Shared by the shop page and the member
 * pages so wording stays consistent.
 *
 * @param string $key mtl_msg value.
 * @return array{0:string,1:string}|null array('type', 'text'), or null.
 */
function mtl_front_notice( $key ) {
	$map = array(
		'reserved'               => array( 'success', 'Your reservation is in. See My loans &amp; reservations for your place in line and pickup details.' ),
		'already_reserved'       => array( 'error', 'You already have an active reservation for that tool.' ),
		'on_loan_conflict'       => array( 'error', 'You currently have that tool checked out, so there&rsquo;s no need to reserve it.' ),
		'login_required'         => array( 'error', 'Please sign in to reserve a tool.' ),
		'reserve_failed'         => array( 'error', 'Sorry, that tool could not be reserved. Please try again.' ),
		'reservation_cancelled'  => array( 'success', 'Your reservation has been cancelled.' ),
		'reservations_cancelled' => array( 'success', 'All of your reservations have been cancelled.' ),
		'session_expired'        => array( 'error', 'That page had been open too long, so nothing was changed. Please try again.' ),
		'account_updated'        => array( 'success', 'Your account details have been updated.' ),
		'account_docs_removed'   => array( 'success', 'Your details were updated. Since your personal information changed, we removed the verification document on file. Library staff will need new documents to verify your account.' ),
		'account_verif_removed'  => array( 'success', 'Your details were updated. Since your personal information changed, your account is no longer verified. Library staff will need to verify it again.' ),
		'account_deleted'        => array( 'success', 'Your account and personal data have been deleted. You&rsquo;re welcome to browse the catalog, but you&rsquo;ll need to sign up again if you&rsquo;d like to reserve a tool.' ),
		// The reserve gate sends members here, so say plainly that no
		// reservation was made.
		'agreements_required'    => array( 'error', 'Before you can reserve a tool, please read and agree to our member agreements below. Your reservation was not created.' ),
		// From a Reserve button on a page opened before staff locked the
		// account; the catalog stops offering one once it is locked.
		'account_locked'         => array( 'error', 'Your account is locked, so that tool was not reserved. Please speak with library staff.' ),
		// From a Reserve button on a page opened before the library was closed
		// to the public again; the catalog offers none before launch.
		'not_live'               => array( 'error', 'Reservations aren&rsquo;t open yet, so that tool was not reserved. Please check back once we&rsquo;ve launched.' ),
		// Sign-in failures, carried back from wp-login.php by
		// mtl_handle_failed_front_login(). Deliberately does not say WHICH of
		// the two was wrong: that would confirm to anyone guessing whether a
		// given email address has an account here.
		'login_failed'           => array( 'error', 'That email address and password don&rsquo;t match an account. Please check them and try again.' ),
		'login_empty'            => array( 'error', 'Please enter both your email address and your password.' ),
		// After a signup with an address that's already registered. Worded so
		// it doesn't confirm that, the same way reset_sent is.
		'signup_check_email'     => array( 'success', 'Thanks. We&rsquo;ve emailed that address with the next step. It can take a few minutes to arrive, so do check your spam folder.' ),
		// Password reset. reset_sent is deliberately non-committal about
		// whether the address matched an account; see
		// mtl_render_lost_password_page().
		'reset_sent'             => array( 'success', 'If an account exists for that email address, a link to choose a new password is on its way. It can take a few minutes to arrive, so do check your spam folder.' ),
		'reset_empty'            => array( 'error', 'Please enter the email address you signed up with.' ),
		'reset_done'             => array( 'success', 'Your password has been changed. You can sign in with it now.' ),
		'reset_expired'          => array( 'error', 'That reset link has expired. Links are only good for a day, so please request a new one.' ),
		'reset_invalid'          => array( 'error', 'That reset link is no longer valid. It may already have been used. Please request a new one.' ),
		'reset_expired_form'     => array( 'error', 'That page had been open too long to submit safely. Please try again.' ),
	);
	return isset( $map[ $key ] ) ? $map[ $key ] : null;
}

/**
 * The current mtl_msg as a status banner.
 *
 * @return string Banner HTML, or '' when there is no recognised mtl_msg.
 */
function mtl_front_notice_html() {
	if ( ! isset( $_GET['mtl_msg'] ) ) {
		return '';
	}
	$notice = mtl_front_notice( sanitize_key( wp_unslash( $_GET['mtl_msg'] ) ) );
	if ( ! $notice ) {
		return '';
	}
	return '<div class="mtl-front-notice mtl-front-notice-' . esc_attr( $notice[0] ) . '">'
		. $notice[1] // From the fixed map above: safe, pre-escaped copy.
		. '</div>';
}

/**
 * Shared <style> for the member pages (sign up, My loans & reservations,
 * Account and the member-only notice).
 *
 * Builds on the shared classes in public/public-look.php (buttons, fields,
 * notices, status line) and only adds to or adjusts them for these pages.
 * Every color is one of the Public pages look's custom properties, or a
 * color-mix() of two of them.
 *
 * @return string The <style> element.
 */
function mtl_member_page_styles() {
	ob_start();
	?>
	<style>
		.mtl-member {
			max-width: 960px;
			margin: 0 auto;
			padding: 34px 24px 70px;
		}

		.mtl-member-lede {
			margin: 8px 0 0;
			max-width: 62ch;
			color: var(--mtl-ink-2);
		}

		.mtl-member-notices {
			margin-top: 22px;
		}

		.mtl-member-notices > :last-child {
			margin-bottom: 0;
		}

		.mtl-member-empty {
			margin: 0;
			padding: 18px 20px;
			color: var(--mtl-ink-2);
		}

		.mtl-front-notice ul {
			margin: 6px 0 0;
			padding-left: 20px;
		}

		.mtl-front-notice p {
			margin: 0;
		}

		/* A red-outline button, for the one step that really destroys
			something; the link that leads to it is a red text link. */
		.mtl-btn.mtl-member-danger-btn {
			border: 2px solid var(--mtl-late);
			color: var(--mtl-late);
		}

		/* ---------- Ready for pickup ---------- */

		.mtl-ready {
			margin-top: 24px;
			display: grid;
			grid-template-columns: 92px minmax(0, 1fr) auto;
			gap: 18px;
			align-items: center;
			padding: 16px 20px;
			background: var(--mtl-highlight);
			border: 2px solid var(--mtl-btn);
			border-radius: var(--mtl-radius);
		}

		.mtl-ready-no-photo {
			grid-template-columns: minmax(0, 1fr) auto;
		}

		.mtl-ready + .mtl-ready {
			margin-top: 12px;
		}

		.mtl-ready-photo {
			display: block;
			width: 92px;
			aspect-ratio: 4 / 3;
			object-fit: contain;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: 4px;
		}

		.mtl-ready p {
			margin: 2px 0 0;
		}

		.mtl-ready .mtl-ready-title {
			margin: 0;
			color: var(--mtl-ink);
			font-family: var(--mtl-font-text);
			font-stretch: 100%;
			font-size: 18px;
			font-weight: 700;
			line-height: 1.35;
		}

		.mtl-ready-by {
			font-weight: 700;
		}

		.mtl-ready .mtl-ready-note {
			margin-top: 4px;
			font-size: 14.5px;
		}

		.mtl-pickup-note {
			margin: 26px 0 0;
			padding: 2px 0 2px 14px;
			border-left: 3px solid var(--mtl-rule);
			color: var(--mtl-ink-2);
			font-size: 15px;
		}

		.mtl-pickup-note strong {
			color: var(--mtl-ink);
		}

		/* ---------- Checked out: date-due slips ---------- */

		.mtl-slips {
			display: grid;
			gap: 10px;
			margin: 0;
			padding: 0;
			list-style: none;
		}

		.mtl-slip {
			display: grid;
			grid-template-columns: 96px minmax(0, 1fr) auto;
			gap: 20px;
			align-items: center;
			padding: 14px 20px 14px 14px;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
		}

		.mtl-slip-due {
			padding-right: 14px;
			border-right: 1px dashed var(--mtl-rule);
			line-height: 1;
			text-align: center;
		}

		.mtl-slip-m,
		.mtl-slip-w {
			display: block;
			font-size: 14px;
			color: var(--mtl-ink-2);
		}

		.mtl-slip-w {
			font-size: 13px;
		}

		.mtl-slip-d {
			display: block;
			margin: 2px 0;
			color: var(--mtl-heading);
			font-family: var(--mtl-font-display);
			font-stretch: 75%;
			font-weight: 800;
			font-size: 46px;
			letter-spacing: -0.02em;
		}

		.mtl-slip-late .mtl-slip-m,
		.mtl-slip-late .mtl-slip-d {
			color: var(--mtl-late);
		}

		.mtl-slip h3 {
			font-size: 21px;
			font-weight: 700;
		}

		.mtl-slip-meta {
			margin: 3px 0 0;
			color: var(--mtl-ink-2);
			font-size: 15px;
		}

		.mtl-slip-state {
			margin: 0;
			font-size: 15px;
			font-weight: 700;
			text-align: right;
		}

		.mtl-slip-state-late {
			color: var(--mtl-late);
		}

		.mtl-slip-state-soon {
			color: var(--mtl-out);
		}

		.mtl-slip-state-fine {
			color: var(--mtl-ink-2);
			font-weight: 400;
		}

		/* ---------- Reservations ---------- */

		.mtl-res-list {
			margin: 0;
			padding: 0;
			list-style: none;
		}

		.mtl-res-row {
			display: grid;
			grid-template-columns: minmax(0, 1fr) 200px 180px auto;
			gap: 16px;
			align-items: center;
			padding: 12px 20px;
			border-top: 1px solid var(--mtl-rule);
		}

		.mtl-res-row:first-child {
			border-top: 0;
		}

		.mtl-res-row h3 {
			font-size: 19px;
			font-weight: 700;
		}

		.mtl-res-row h3 a {
			color: inherit;
		}

		.mtl-res-meta {
			margin: 2px 0 0;
			color: var(--mtl-ink-2);
			font-size: 14.5px;
		}

		.mtl-res-line {
			font-size: 15px;
		}

		.mtl-res-line strong {
			font-size: 17px;
		}

		/* Squares for the queue. The sentence above them says the same thing,
			so they are hidden from screen readers. */
		.mtl-line-dots {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 4px;
			margin-top: 5px;
		}

		.mtl-line-dots i {
			width: 14px;
			height: 14px;
			border: 1.5px solid var(--mtl-ink-2);
			border-radius: 3px;
		}

		.mtl-line-dots .mtl-line-ahead {
			background: var(--mtl-ink-2);
		}

		.mtl-line-dots .mtl-line-you {
			background: var(--mtl-btn);
			border-color: var(--mtl-ink);
		}

		.mtl-line-more {
			margin-left: 2px;
			color: var(--mtl-ink-2);
			font-size: 13px;
			line-height: 1;
		}

		.mtl-res-state {
			font-size: 14.5px;
		}

		.mtl-res-state .mtl-status {
			display: flex;
		}

		.mtl-res-cancel,
		.mtl-res-foot a,
		.mtl-acct-danger a {
			display: inline-flex;
			align-items: center;
			min-height: 44px;
		}

		.mtl-res-cancel {
			justify-self: end;
		}

		.mtl-res-foot {
			display: flex;
			justify-content: flex-end;
			margin-top: 4px;
			font-size: 15px;
		}

		/* ---------- Reservation detail panel ---------- */

		/* Every panel is printed hidden and :target shows the one whose id is
			in the URL. With none open the column is not drawn at all, so the
			page keeps its single column. */
		.mtl-res-detail,
		.mtl-res-detail-panel {
			display: none;
		}

		.mtl-res-page:has(.mtl-res-detail-panel:target) .mtl-res-detail,
		.mtl-res-detail-panel:target {
			display: block;
		}

		.mtl-res-detail {
			margin-top: 32px;
		}

		.mtl-res-detail-panel {
			overflow: hidden;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
		}

		/* In the text, not over the photo: nothing is ever drawn on a photo. */
		.mtl-res-close {
			position: absolute;
			top: 10px;
			right: 16px;
			display: inline-flex;
			align-items: center;
			min-height: 44px;
			font-size: 15px;
		}

		.mtl-res-detail-photo {
			display: block;
			width: 100%;
			aspect-ratio: 16 / 10;
			max-height: 340px;
			object-fit: contain;
			background: var(--mtl-panel);
			border-bottom: 1px solid var(--mtl-rule);
		}

		.mtl-res-detail-body {
			position: relative;
			padding: 18px 22px 22px;
		}

		.mtl-res-detail-body h2 {
			padding-right: 64px;
			font-size: 30px;
			letter-spacing: -0.01em;
		}

		.mtl-res-detail-body p {
			margin: 0;
		}

		.mtl-res-detail-body .mtl-res-detail-sub {
			margin-top: 4px;
			color: var(--mtl-ink-2);
			font-size: 15px;
		}

		.mtl-res-info {
			margin: 18px 0 0;
			padding: 12px 14px;
			border-left: 4px solid var(--mtl-wait);
			border-radius: 0 var(--mtl-radius) var(--mtl-radius) 0;
			background: color-mix(in srgb, var(--mtl-wait) 8%, var(--mtl-panel));
		}

		.mtl-res-info-ok {
			border-left-color: var(--mtl-ok);
			background: color-mix(in srgb, var(--mtl-ok) 9%, var(--mtl-panel));
		}

		.mtl-res-info-out {
			border-left-color: var(--mtl-out);
			background: color-mix(in srgb, var(--mtl-out) 8%, var(--mtl-panel));
		}

		.mtl-res-info-maint {
			border-left-color: var(--mtl-maint);
			background: color-mix(in srgb, var(--mtl-maint) 9%, var(--mtl-panel));
		}

		.mtl-res-info strong {
			display: block;
			font-size: 17px;
		}

		.mtl-res-info-ok strong {
			color: var(--mtl-ok);
		}

		.mtl-res-info-out strong {
			color: var(--mtl-out);
		}

		.mtl-res-info-maint strong {
			color: var(--mtl-maint);
		}

		.mtl-res-info-wait strong {
			color: var(--mtl-wait);
		}

		.mtl-res-detail-body .mtl-res-info p {
			margin-top: 4px;
			font-size: 15px;
		}

		.mtl-res-detail-body :is(h3, h4),
		.mtl-res-detail-body .mtl-links > summary {
			margin: 22px 0 4px;
			color: var(--mtl-ink);
			font-family: var(--mtl-font-text);
			font-stretch: 100%;
			font-size: 15px;
			font-weight: 700;
		}

		.mtl-res-facts {
			display: grid;
			grid-template-columns: max-content minmax(0, 1fr);
			gap: 6px 14px;
			margin: 22px 0 0;
			padding-top: 16px;
			border-top: 1px solid var(--mtl-rule);
			font-size: 15px;
		}

		.mtl-res-facts dt {
			color: var(--mtl-ink-2);
		}

		.mtl-res-facts dd {
			margin: 0;
		}

		.mtl-res-detail-body .mtl-links > summary {
			cursor: pointer;
			color: var(--mtl-link);
		}

		.mtl-res-detail-body .mtl-links-count {
			margin-left: 4px;
			color: var(--mtl-ink-2);
			font-weight: 400;
		}

		.mtl-res-detail-body .mtl-links-count::before {
			content: "(";
		}

		.mtl-res-detail-body .mtl-links-count::after {
			content: ")";
		}

		.mtl-res-detail-body .mtl-links-list {
			margin: 0;
			padding: 0;
			list-style: none;
			font-size: 15px;
		}

		.mtl-res-detail-body .mtl-links-list li {
			padding: 6px 0;
			border-top: 1px solid var(--mtl-rule-soft);
		}

		.mtl-res-detail-body .mtl-res-detail-cancel {
			margin-top: 22px;
			padding-top: 6px;
			border-top: 1px solid var(--mtl-rule);
		}

		.mtl-res-detail-cancel a {
			display: inline-flex;
			align-items: center;
			min-height: 44px;
		}

		/* Wide enough for both: the panel sits beside everything else and stays
			in view while the list scrolls. It scrolls itself when it is taller
			than the window, or its foot could never be reached. */
		@media (min-width: 1000px) {
			.mtl-res-page:has(.mtl-res-detail-panel:target) {
				max-width: 1400px;
				display: grid;
				grid-template-columns: minmax(0, 1fr) 390px;
				grid-template-areas: "main detail" "after detail";
				column-gap: 36px;
				align-items: start;
			}

			.mtl-res-page:has(.mtl-res-detail-panel:target) .mtl-res-main {
				grid-area: main;
			}

			.mtl-res-page:has(.mtl-res-detail-panel:target) .mtl-res-after {
				grid-area: after;
			}

			.mtl-res-page:has(.mtl-res-detail-panel:target) .mtl-res-detail {
				grid-area: detail;
				position: sticky;
				top: 20px;
				max-height: calc(100vh - 40px);
				margin-top: 0;
				overflow-y: auto;
				border-radius: var(--mtl-radius);
			}
		}

		/* ---------- Cancel confirmations ---------- */

		.mtl-confirm {
			margin-top: 26px;
			padding: 24px;
		}

		.mtl-confirm h2 {
			margin-bottom: 10px;
			font-size: 26px;
		}

		.mtl-confirm p {
			margin: 0 0 12px;
			max-width: 62ch;
		}

		.mtl-confirm-actions {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 12px;
			margin-top: 18px;
		}

		.mtl-confirm-actions form {
			margin: 0;
		}

		/* ---------- Giving and tool-request boxes ---------- */

		.mtl-callout {
			display: flex;
			align-items: center;
			gap: 20px;
			margin-top: 44px;
			padding: 20px 22px;
			border: 1px dashed color-mix(in srgb, var(--mtl-ink) 38%, var(--mtl-page));
			border-radius: var(--mtl-radius);
		}

		.mtl-callout + .mtl-callout {
			margin-top: 16px;
		}

		.mtl-callout-body {
			flex: 1 1 auto;
			min-width: 0;
		}

		.mtl-callout .mtl-callout-title {
			color: var(--mtl-ink);
			font-family: var(--mtl-font-text);
			font-stretch: 100%;
			font-size: 17px;
			font-weight: 700;
			line-height: 1.4;
		}

		.mtl-callout-text {
			margin: 4px 0 0;
			color: var(--mtl-ink-2);
		}

		.mtl-callout-actions {
			display: flex;
			flex: none;
			flex-wrap: wrap;
			gap: 10px;
			margin-left: auto;
		}

		/* ---------- Account ---------- */

		.mtl-acct-who {
			display: grid;
			grid-template-columns: 1fr 1fr;
			margin-top: 24px;
		}

		.mtl-acct-who > section {
			padding: 20px 22px;
		}

		.mtl-acct-who > section + section {
			border-left: 1px solid var(--mtl-rule);
		}

		.mtl-acct-who h2 {
			margin-bottom: 8px;
			color: var(--mtl-ink-2);
			font-family: var(--mtl-font-text);
			font-stretch: 100%;
			font-size: 15px;
			font-weight: 700;
		}

		.mtl-verified {
			display: flex;
			align-items: flex-start;
			gap: 12px;
		}

		.mtl-verified-check {
			display: grid;
			flex: none;
			place-items: center;
			width: 30px;
			height: 30px;
			border-radius: 50%;
			background: var(--mtl-ok);
			color: var(--mtl-panel);
			font-weight: 800;
		}

		.mtl-verified-badge-img {
			display: block;
			flex: none;
			height: 36px;
			width: auto;
		}

		.mtl-verified strong {
			display: block;
			font-size: 18px;
		}

		.mtl-verified p,
		.mtl-acct-fine {
			margin: 2px 0 0;
			color: var(--mtl-ink-2);
			font-size: 15px;
		}

		.mtl-acct-fine {
			margin-top: 10px;
			font-size: 14px;
		}

		.mtl-acct-chips {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			margin: 0;
			padding: 0;
			list-style: none;
		}

		.mtl-acct-chips .mtl-chip {
			padding-left: 6px;
		}

		.mtl-chip-check {
			display: grid;
			place-items: center;
			width: 22px;
			height: 22px;
			border-radius: 50%;
			background: color-mix(in srgb, var(--mtl-ok) 14%, var(--mtl-panel));
			color: var(--mtl-ok);
			font-size: 13px;
			font-weight: 800;
		}

		.mtl-chip-badge {
			display: block;
			width: 22px;
			height: 22px;
			border-radius: 50%;
			object-fit: cover;
		}

		.mtl-acct-none {
			margin: 0;
			font-weight: 700;
		}

		.mtl-acct-agree {
			margin-top: 24px;
			padding: 22px;
			border: 2px solid var(--mtl-btn);
		}

		.mtl-acct-agree h2 {
			margin-bottom: 12px;
			font-size: 26px;
		}

		.mtl-acct-agree .mtl-agreements legend {
			font-family: var(--mtl-font-text);
			font-stretch: 100%;
			font-size: 17px;
		}

		/* One list of folding rows. Each summary carries a short note on the
			right so a closed row still says what is inside. */
		.mtl-acct-panels {
			margin-top: 26px;
		}

		.mtl-acct-panels > details + details {
			border-top: 1px solid var(--mtl-rule);
		}

		.mtl-acct-panels summary {
			display: grid;
			grid-template-columns: minmax(0, 1fr) auto 20px;
			align-items: center;
			gap: 16px;
			min-height: 44px;
			padding: 18px 22px;
			cursor: pointer;
			list-style: none;
		}

		.mtl-acct-panels summary::-webkit-details-marker {
			display: none;
		}

		.mtl-acct-panels summary::after {
			content: "";
			justify-self: end;
			width: 9px;
			height: 9px;
			border-right: 2px solid var(--mtl-ink-2);
			border-bottom: 2px solid var(--mtl-ink-2);
			transform: rotate(45deg) translateY(-3px);
		}

		.mtl-acct-panels details[open] > summary::after {
			transform: rotate(-135deg) translateY(-2px);
		}

		.mtl-acct-panels summary:hover {
			background: var(--mtl-hover);
		}

		.mtl-acct-sum-title {
			font-size: 18px;
			font-weight: 700;
		}

		.mtl-acct-sum-note {
			color: var(--mtl-ink-2);
			font-size: 15px;
			text-align: right;
		}

		.mtl-acct-body {
			padding: 2px 22px 24px;
		}

		.mtl-acct-body > p:first-child {
			margin-top: 0;
		}

		.mtl-form-note {
			margin: 0;
			color: var(--mtl-ink-2);
			font-size: 14px;
		}

		.mtl-form-actions {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 12px 18px;
			margin-top: 22px;
		}

		.mtl-member-verif-confirm {
			margin: 18px 0 0;
		}

		.mtl-member-verif-confirm p {
			margin: 6px 0 0;
		}

		.mtl-member-verif-confirm .mtl-form-actions {
			margin-top: 14px;
		}

		/* Loan history and training record. */
		.mtl-history {
			margin: 0;
			padding: 0;
			list-style: none;
		}

		.mtl-history li {
			display: grid;
			grid-template-columns: minmax(0, 1fr) auto;
			gap: 2px 16px;
			align-items: center;
			padding: 12px 0;
			border-top: 1px solid var(--mtl-rule-soft);
		}

		.mtl-history li:first-child {
			border-top: 0;
		}

		.mtl-history-name {
			font-weight: 700;
		}

		.mtl-history-meta {
			grid-column: 1;
			margin: 0;
			color: var(--mtl-ink-2);
			font-size: 15px;
		}

		.mtl-history .mtl-history-status {
			grid-column: 2;
			grid-row: 1 / span 2;
		}

		.mtl-history-status.mtl-status-note {
			font-weight: 700;
		}

		.mtl-acct-danger {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 0 18px;
			margin-top: 44px;
			padding-top: 14px;
			border-top: 1px solid var(--mtl-rule);
		}

		.mtl-acct-danger p {
			margin: 0;
			color: var(--mtl-ink-2);
			font-size: 15px;
		}

		/* ---------- Member agreements (sign up and Account) ---------- */

		.mtl-agreements {
			min-width: 0;
			margin: 0;
			padding: 0;
			border: 0;
		}

		.mtl-agreements-intro {
			margin: 0;
			color: var(--mtl-ink-2);
		}

		.mtl-agreement-item {
			margin-top: 10px;
			padding: 12px 14px;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
		}

		/* Paired with the error text, never carrying the meaning alone. */
		.mtl-agreement-item-invalid {
			padding-left: 10px;
			border-left: 5px solid var(--mtl-late);
		}

		.mtl-agreement-label {
			display: flex;
			align-items: flex-start;
			gap: 12px;
			cursor: pointer;
		}

		/* 3px down lines the box up with the first line of the clause. */
		.mtl-agreement-label input[type="checkbox"] {
			flex: none;
			width: 20px;
			height: 20px;
			margin: 3px 0 0;
		}

		.mtl-agreement-error,
		.mtl-agreement-file,
		.mtl-agreement-superseded {
			margin: 6px 0 0 32px;
			font-size: 14.5px;
		}

		.mtl-agreement-error {
			color: var(--mtl-late);
			font-weight: 700;
		}

		.mtl-agreement-superseded {
			color: var(--mtl-ink-2);
		}

		.mtl-agreements-assent {
			margin: 14px 0 0;
			color: var(--mtl-ink-2);
			font-size: 15px;
		}

		/* The receipt: what the member has already agreed to. */
		.mtl-agreement-receipt {
			margin: 0;
			padding: 0;
			list-style: none;
		}

		.mtl-agreement-receipt li {
			display: flex;
			align-items: flex-start;
			gap: 12px;
			padding: 12px 0;
			border-top: 1px solid var(--mtl-rule-soft);
		}

		.mtl-agreement-receipt li:first-child {
			border-top: 0;
		}

		.mtl-agreement-receipt .mtl-chip-check {
			flex: none;
			margin-top: 2px;
		}

		.mtl-agreement-meta {
			margin: 4px 0 0;
			color: var(--mtl-ink-2);
			font-size: 14.5px;
		}

		/* ---------- Sign up ---------- */

		.mtl-signup {
			display: grid;
			grid-template-columns: 300px minmax(0, 1fr);
			gap: 48px;
			align-items: start;
			max-width: 1040px;
			margin: 0 auto;
			padding: 34px 24px 70px;
		}

		.mtl-signup-intro {
			position: sticky;
			top: 24px;
		}

		.mtl-signup-intro p {
			margin: 12px 0 0;
			color: var(--mtl-ink-2);
		}

		.mtl-signup-intro ul {
			margin: 18px 0 0;
			padding: 0;
			list-style: none;
		}

		.mtl-signup-intro li {
			padding: 10px 0;
			border-top: 1px solid var(--mtl-rule);
		}

		.mtl-signup-intro li:last-child {
			border-bottom: 1px solid var(--mtl-rule);
		}

		.mtl-signup-form {
			padding: 8px 28px 28px;
		}

		.mtl-signup-form > .mtl-front-notice {
			margin: 20px 0 0;
		}

		/* Less at the foot, where the last field's own margin makes it up. */
		.mtl-signup-form fieldset {
			min-width: 0;
			margin: 0;
			padding: 22px 0 6px;
			border: 0;
		}

		.mtl-signup-form .mtl-agreements {
			padding-bottom: 22px;
		}

		.mtl-signup-form fieldset + fieldset {
			border-top: 1px solid var(--mtl-rule);
		}

		/* Floated, so the legend sits inside the fieldset like a heading
			rather than cutting into its top edge. */
		.mtl-signup-form legend,
		.mtl-agreements legend {
			float: left;
			width: 100%;
			margin: 0 0 14px;
			padding: 0;
			color: var(--mtl-heading);
			font-family: var(--mtl-font-display);
			font-stretch: 82%;
			font-size: 21px;
			font-weight: 700;
			line-height: 1.2;
		}

		.mtl-signup-form legend + *,
		.mtl-agreements legend + * {
			clear: both;
		}

		.mtl-signup-form .mtl-form-actions {
			margin-top: 6px;
		}

		/* mtl_render_phone_input() markup, shared with the admin pages, so it
			is styled here rather than given new classes. */
		.mtl-phone-widget {
			display: grid;
			grid-template-columns: 220px minmax(0, 1fr);
			gap: 8px;
		}

		/* ---------- Member-only notice ---------- */

		.mtl-front-card .mtl-confirm-actions {
			margin-top: 20px;
		}

		/* The intro column would leave the form too narrow on a tablet. */
		@media (max-width: 900px) {
			.mtl-signup {
				grid-template-columns: minmax(0, 1fr);
				gap: 22px;
				max-width: 680px;
			}

			.mtl-signup-intro {
				position: static;
			}
		}

		@media (max-width: 760px) {
			.mtl-member {
				padding: 22px 16px 50px;
			}

			.mtl-section-head .mtl-section-aside {
				flex-basis: 100%;
				margin-left: 0;
				color: var(--mtl-ink-2);
			}

			.mtl-ready,
			.mtl-ready-no-photo {
				grid-template-columns: 1fr;
				gap: 12px;
				padding: 14px 16px;
			}

			.mtl-ready-photo {
				display: none;
			}

			.mtl-slip {
				grid-template-columns: 74px minmax(0, 1fr);
				gap: 4px 14px;
				padding: 12px 14px 12px 10px;
			}

			.mtl-slip-due {
				grid-row: 1 / span 2;
			}

			.mtl-slip-d {
				font-size: 38px;
			}

			.mtl-slip-state {
				grid-column: 2;
				text-align: left;
			}

			.mtl-res-row {
				grid-template-columns: 1fr;
				gap: 8px;
				padding: 14px 16px;
			}

			.mtl-res-cancel {
				justify-self: start;
			}

			.mtl-res-detail-body {
				padding: 16px 16px 20px;
			}

			.mtl-res-detail-body h2 {
				font-size: 26px;
			}

			.mtl-callout {
				flex-direction: column;
				align-items: flex-start;
				gap: 14px;
				padding: 18px 16px;
			}

			.mtl-callout-actions {
				margin-left: 0;
			}

			.mtl-acct-who {
				grid-template-columns: 1fr;
			}

			.mtl-acct-who > section + section {
				border-top: 1px solid var(--mtl-rule);
				border-left: 0;
			}

			.mtl-acct-who > section,
			.mtl-acct-agree {
				padding: 18px 16px;
			}

			.mtl-acct-panels summary {
				grid-template-columns: minmax(0, 1fr) 20px;
				gap: 2px 12px;
				padding: 16px;
			}

			.mtl-acct-sum-note {
				grid-column: 1;
				grid-row: 2;
				text-align: left;
			}

			.mtl-acct-panels summary::after {
				grid-column: 2;
				grid-row: 1 / span 2;
			}

			.mtl-acct-body {
				padding: 2px 16px 20px;
			}

			.mtl-signup {
				padding: 22px 16px 50px;
			}

			.mtl-signup-form {
				padding: 4px 16px 22px;
			}

			/* Stacked on phones, so the country menu can show its dial code. */
			.mtl-phone-widget {
				grid-template-columns: minmax(0, 1fr);
			}

			.mtl-btn-primary.mtl-member-submit {
				width: 100%;
			}
		}
	</style>
	<?php
	return ob_get_clean();
}

// --------------------------------------------------------------------------
// Member agreements: the shared renderer
//
// The signup form and the account page's outstanding block both draw their
// checkboxes from mtl_render_agreements_fieldset(), so the two cannot drift.
// --------------------------------------------------------------------------

/**
 * The standing banner telling a member they owe an agreement, or ''.
 *
 * Wording depends on the state: "agreements have changed" would be wrong for
 * someone who never agreed to any.
 *
 * Not a live region: it is present on page load rather than injected, so
 * announcing it would be noise on every page view.
 *
 * Only shown when mtl_agreements_online(), since paper mode gives the member
 * nowhere to act on it.
 *
 * @return string HTML, or '' when there is nothing to say.
 */
function mtl_agreements_banner_html() {
	if ( ! mtl_agreements_online() ) {
		return '';
	}

	$member = mtl_current_member();
	if ( ! $member ) {
		return '';
	}

	$status = mtl_member_agreements_status( (int) $member->member_id );
	if ( 'outdated' === $status ) {
		$text = __( 'Some of our member agreements have changed. Please review and agree on your account page before reserving a tool.', 'my-tool-library' );
	} elseif ( 'none' === $status ) {
		$text = __( 'Please review and agree to our member agreements on your account page before reserving a tool.', 'my-tool-library' );
	} else {
		return '';
	}

	return '<div class="mtl-front-notice mtl-front-notice-error">'
		. esc_html( $text ) . ' '
		. '<a href="' . esc_url( mtl_front_page_url( 'account' ) . '#mtl-agreements' ) . '">' . esc_html__( 'Review your agreements', 'my-tool-library' ) . '</a>'
		. '</div>';
}

/**
 * The standing red banner telling a signed-in member their account is
 * locked, or '' when it isn't. Shown at the top of the catalog, the Account
 * page and My Loans & Reservations. See mtl_lock_member().
 *
 * Not a live region, for the same reason as mtl_agreements_banner_html().
 *
 * @return string HTML, or '' when there is nothing to say.
 */
function mtl_account_locked_banner_html() {
	$member = mtl_current_member();
	if ( ! $member || empty( $member->locked_at ) ) {
		return '';
	}

	$contact = mtl_contact_email();
	$reach   = '' !== $contact
		? ' You can reach us at <a href="' . esc_url( 'mailto:' . $contact ) . '">' . esc_html( $contact ) . '</a>.'
		: '';

	return '<div class="mtl-locked-banner">'
		. '<strong>' . esc_html__( 'Your account has been locked.', 'my-tool-library' ) . '</strong> '
		. esc_html__( 'You can still sign in and see your account, but you can\'t reserve or borrow tools. Please speak with library staff.', 'my-tool-library' )
		. $reach
		. '</div>';
}

/**
 * The first few words of an agreement, for error messages and link names.
 *
 * @param string $text  Agreement text.
 * @param int    $words How many words to keep.
 * @return string The excerpt, with &hellip; appended when truncated.
 */
function mtl_agreement_excerpt( $text, $words = 8 ) {
	$text  = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	$parts = explode( ' ', $text );
	if ( count( $parts ) <= $words ) {
		return $text;
	}
	return implode( ' ', array_slice( $parts, 0, $words ) ) . '&hellip;';
}

/**
 * Renders a group of agreements as a fieldset of checkboxes.
 *
 * These forms are the legal gate. A member who cannot operate them cannot
 * join the library, so they must work with assistive technology:
 *
 * - the whole clause is the label, so it is both the click target and the
 *   accessible name;
 * - the group is a fieldset with a legend, announced as a group;
 * - the assent sentence is bound by aria-describedby from the fieldset, so it
 *   is heard before submitting rather than met while tabbing;
 * - each file link's accessible name carries the agreement's opening words and
 *   says it opens in a new tab, so several attached documents are
 *   distinguishable in a link list;
 * - nothing is disabled. The submit button always works and always produces an
 *   explanation.
 *
 * Recognised $args keys, all optional:
 *
 *   context     'signup' or 'agree_page'. Selects the assent wording.
 *   id_prefix   Prefix for element ids, unique per form on a page.
 *   legend      Group heading.
 *   intro       Sentence above the list, or '' for none.
 *   checked     Agreement ids to render ticked.
 *   invalid     Agreement ids that failed validation.
 *   superseded  agreement_id => "you agreed to an earlier version" line.
 *
 * @param object[] $agreements Rows from member_agreements, in display order.
 * @param array    $args       Rendering options, as above.
 * @return string HTML.
 */
function mtl_render_agreements_fieldset( $agreements, $args = array() ) {
	$args = array_merge(
		array(
			'context'    => 'signup',
			'id_prefix'  => 'mtl-agreement',
			'legend'     => 'Member agreements',
			'intro'      => '',
			'checked'    => array(),
			'invalid'    => array(),
			'superseded' => array(),
		),
		$args
	);

	if ( empty( $agreements ) ) {
		return '';
	}

	$assent_id = $args['id_prefix'] . '-assent';
	$checked   = array_map( 'intval', (array) $args['checked'] );
	$invalid   = array_map( 'intval', (array) $args['invalid'] );

	ob_start();
	?>
	<fieldset class="mtl-agreements" aria-describedby="<?php echo esc_attr( $assent_id ); ?>">
		<legend><?php echo esc_html( $args['legend'] ); ?></legend>
		<?php if ( '' !== $args['intro'] ) : ?>
			<p class="mtl-agreements-intro"><?php echo esc_html( $args['intro'] ); ?></p>
		<?php endif; ?>

		<?php foreach ( $agreements as $agreement ) : ?>
			<?php
			$aid       = (int) $agreement->agreement_id;
			$box_id    = $args['id_prefix'] . '-' . $aid;
			$err_id    = $box_id . '-error';
			$is_bad    = in_array( $aid, $invalid, true );
			$file_url  = (int) $agreement->attachment_id > 0 ? wp_get_attachment_url( (int) $agreement->attachment_id ) : '';
			$describes = array();
			if ( $is_bad ) {
				$describes[] = $err_id;
			}
			?>
			<div class="mtl-agreement-item<?php echo $is_bad ? ' mtl-agreement-item-invalid' : ''; ?>">
				<label class="mtl-agreement-label" for="<?php echo esc_attr( $box_id ); ?>">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $box_id ); ?>"
						name="agreements[<?php echo esc_attr( $aid ); ?>]"
						value="1"
						<?php checked( true, in_array( $aid, $checked, true ) ); ?>
						<?php echo $is_bad ? ' aria-invalid="true"' : ''; ?>
						<?php echo $describes ? ' aria-describedby="' . esc_attr( implode( ' ', $describes ) ) . '"' : ''; ?>
					>
					<span><?php echo nl2br( esc_html( $agreement->agreement_text ) ); ?></span>
				</label>

				<?php if ( $is_bad ) : ?>
					<p class="mtl-agreement-error" id="<?php echo esc_attr( $err_id ); ?>"><?php esc_html_e( 'You need to tick this box to continue.', 'my-tool-library' ); ?></p>
				<?php endif; ?>

				<?php if ( $file_url ) : ?>
					<p class="mtl-agreement-file">
						<a href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener noreferrer"
							aria-label="<?php echo esc_attr( 'View the attached document for &ldquo;' . wp_strip_all_tags( mtl_agreement_excerpt( $agreement->agreement_text ) ) . '&rdquo; (opens in a new tab)' ); ?>"><?php esc_html_e( 'View the attached document', 'my-tool-library' ); ?></a>
					</p>
				<?php endif; ?>

				<?php if ( isset( $args['superseded'][ $aid ] ) ) : ?>
					<p class="mtl-agreement-superseded"><?php echo esc_html( $args['superseded'][ $aid ] ); ?></p>
				<?php endif; ?>

				<?php
				// The version shown, sent back so an acceptance is never recorded
				// against wording revised while the form was open.
				?>
				<input type="hidden" name="agreement_versions[<?php echo esc_attr( $aid ); ?>]" value="<?php echo esc_attr( (int) $agreement->version_num ); ?>">
			</div>
		<?php endforeach; ?>

		<p class="mtl-agreements-assent" id="<?php echo esc_attr( $assent_id ); ?>">
			<?php echo esc_html( mtl_assent_language( $args['context'] ) ); ?>
		</p>
	</fieldset>
	<?php
	return ob_get_clean();
}

/**
 * The error summary shown when a submit is missing agreements.
 *
 * A real element at the top of the form, linking to the checkboxes it is about.
 * role="alert" announces it on a re-render, and autofocus (made focusable by
 * tabindex) lands a keyboard or screen-reader user on the explanation rather
 * than at the top of an apparently unchanged page. No script involved.
 *
 * @param object[] $missing   Agreement rows that were not ticked.
 * @param string   $id_prefix Same prefix the fieldset was rendered with.
 * @return string HTML, or '' when nothing is missing.
 */
function mtl_render_agreements_error_summary( $missing, $id_prefix ) {
	if ( empty( $missing ) ) {
		return '';
	}

	ob_start();
	?>
	<div class="mtl-front-notice mtl-front-notice-error mtl-agreements-summary" role="alert" tabindex="-1" autofocus id="<?php echo esc_attr( $id_prefix ); ?>-summary">
		<div><strong><?php esc_html_e( 'You must agree to:', 'my-tool-library' ); ?></strong></div>
		<ul>
			<?php foreach ( $missing as $agreement ) : ?>
				<li>
					<a href="#<?php echo esc_attr( $id_prefix . '-' . (int) $agreement->agreement_id ); ?>">
						<?php echo esc_html( mtl_agreement_excerpt( $agreement->agreement_text, 12 ) ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Reads the agreements POST back into ids and displayed versions.
 *
 * @return array {
 *     @type int[] ticked   Agreement ids whose box was ticked.
 *     @type array versions agreement_id => version_num as displayed.
 * }
 */
function mtl_read_agreements_post() {
	$ticked   = array();
	$versions = array();

	// No submitted string is trusted: only array keys are read from agreements[],
	// and both keys and values from agreement_versions[] are cast to integers.
	// Every caller then checks those ids against the live list.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies its own nonce before calling this.
	if ( isset( $_POST['agreements'] ) && is_array( $_POST['agreements'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() is the sanitizer, applied to every key.
		$ticked = array_map( 'absint', array_keys( wp_unslash( $_POST['agreements'] ) ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	if ( isset( $_POST['agreement_versions'] ) && is_array( $_POST['agreement_versions'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() is the sanitizer, applied to both key and value.
		$raw_versions = (array) wp_unslash( $_POST['agreement_versions'] );
		foreach ( $raw_versions as $key => $value ) {
			$versions[ absint( $key ) ] = absint( $value );
		}
	}

	return array(
		'ticked'   => array_values( array_unique( array_filter( $ticked ) ) ),
		'versions' => $versions,
	);
}

/**
 * Checks a submitted set of agreements against what is live right now.
 *
 * Covers the gap between a form being drawn and coming back, during which an
 * admin may have added, retired or revised an agreement, or changed the mode.
 *
 * The version check is all-or-nothing: refusing up front leaves nothing to
 * undo, where refusing row by row mid-loop would leave a partial write.
 *
 * @param object[] $live     Currently active agreements.
 * @param int[]    $ticked   Agreement ids the member ticked.
 * @param array    $versions agreement_id => version_num the form displayed.
 * @return array {
 *     @type object[] missing        Live agreements with no tick.
 *     @type int[]    stale          Ids whose displayed version is no longer current.
 *     @type int[]    added          Ids that appeared after the form was drawn.
 *     @type bool     ok             True when nothing blocks the write.
 * }
 */
function mtl_check_agreements_submission( $live, $ticked, $versions ) {
	$missing = array();
	$stale   = array();
	$added   = array();

	foreach ( $live as $agreement ) {
		$aid = (int) $agreement->agreement_id;

		// Added after the form was drawn, so the member never saw it. The
		// caller re-renders with it shown and unticked rather than erroring
		// about a box they never saw.
		if ( ! isset( $versions[ $aid ] ) ) {
			$added[] = $aid;
			continue;
		}

		// Recording this would assert, permanently and with a checksum, that
		// the member agreed to wording they never read.
		if ( (int) $versions[ $aid ] !== (int) $agreement->version_num ) {
			$stale[] = $aid;
			continue;
		}

		if ( ! in_array( $aid, $ticked, true ) ) {
			$missing[] = $agreement;
		}
	}

	return array(
		'missing' => $missing,
		'stale'   => $stale,
		'added'   => $added,
		'ok'      => ( ! $missing && ! $stale && ! $added ),
	);
}

// --------------------------------------------------------------------------
// Reserve a tool  (POST from the shop page's detail panel)
// --------------------------------------------------------------------------

/**
 * Handles a Reserve POST from the catalog's detail panel.
 *
 * Called from mtl_render_front_main_page() before any output, so it can
 * finish with a Post/Redirect/Get back to the catalog (no double-submit on
 * refresh). Does nothing unless this request is actually a reserve POST.
 *
 * @return void Returns only when this isn't a reserve POST; otherwise redirects and exits.
 */
function mtl_handle_reserve_action() {
	if ( ! mtl_is_post_request() || ! isset( $_POST['mtl_action'] ) ) {
		return;
	}
	if ( 'reserve' !== sanitize_key( wp_unslash( $_POST['mtl_action'] ) ) ) {
		return;
	}

	$tool_id = isset( $_POST['mtl_tool'] ) ? (int) $_POST['mtl_tool'] : 0;
	// Return the member to the exact catalog view they reserved from (filters,
	// sort, page preserved) via the referer; fall back to the clean catalog URL.
	$referer  = wp_get_referer();
	$back     = $referer ? remove_query_arg( 'mtl_msg', $referer ) : mtl_front_page_url( 'main' );
	$redirect = function ( $msg ) use ( $back, $tool_id ) {
		$url = add_query_arg( 'mtl_msg', $msg, $back );
		if ( $tool_id > 0 ) {
			$url .= '#' . mtl_shop_panel_id( $tool_id );
		}
		wp_safe_redirect( $url );
		exit;
	};

	if ( ! isset( $_POST['mtl_reserve_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_reserve_nonce'] ) ), 'mtl_reserve_action' ) ) {
		$redirect( 'session_expired' );
	}

	// Nobody reserves before launch. The catalog preview shows no Reserve
	// button, so this is a page opened while the library was still live.
	if ( ! mtl_library_is_live() ) {
		$redirect( 'not_live' );
	}

	$member = mtl_current_member();
	if ( ! $member ) {
		$redirect( 'login_required' );
	}

	// Ahead of the agreements gate: sending a locked member off to agree to
	// something would only lead to a second refusal.
	if ( ! empty( $member->locked_at ) ) {
		$redirect( 'account_locked' );
	}

	// The agreements gate, online mode only: in paper mode a member can't agree
	// on the website, so blocking them would leave no way to unblock themselves.
	// No reservation is created, so after agreeing they simply reserve again.
	if ( mtl_agreements_online() ) {
		$agreement_status = mtl_member_agreements_status( (int) $member->member_id );
		if ( 'outdated' === $agreement_status || 'none' === $agreement_status ) {
			wp_safe_redirect( add_query_arg( 'mtl_msg', 'agreements_required', mtl_front_page_url( 'account' ) ) . '#mtl-agreements' );
			exit;
		}
	}

	global $wpdb;
	$tbl_res   = $wpdb->prefix . 'tool_reservations';
	$tbl_loans = $wpdb->prefix . 'loans';
	$tbl_inv   = $wpdb->prefix . 'tool_inventory';

	// Tool must exist and not be retired (closes off a stale/bookmarked
	// reserve link against a since-retired tool).
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$exists = $wpdb->get_var( $wpdb->prepare( "SELECT tool_id FROM {$tbl_inv} WHERE tool_id = %d AND retired_at IS NULL", $tool_id ) );
	if ( ! $exists ) {
		$redirect( 'reserve_failed' );
	}

	// Can't reserve a tool the member already has checked out.
	$on_loan = $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT loan_id FROM {$tbl_loans} WHERE member_id = %d AND tool_id = %d AND return_date IS NULL LIMIT 1",
			(int) $member->member_id,
			$tool_id
		)
	);
	if ( $on_loan ) {
		$redirect( 'on_loan_conflict' );
	}

	// A double-click sends two POSTs at once, and both would pass the duplicate
	// check before either inserts. A named lock per member and tool makes the
	// second wait, then find the first one's reservation.
	$lock = 'mtl_reserve_' . md5( $wpdb->prefix . '|' . (int) $member->member_id . '|' . $tool_id );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
		$redirect( 'reserve_failed' );
	}

	// Only one active reservation per member per tool.
	$already = $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT reservation_id FROM {$tbl_res} WHERE member_id = %d AND tool_id = %d AND expiry_date IS NULL LIMIT 1",
			(int) $member->member_id,
			$tool_id
		)
	);
	if ( $already ) {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		$redirect( 'already_reserved' );
	}

	// expiry_date is intentionally left NULL: a new reservation is active until
	// it is cancelled or fulfilled by a loan, at which point that date is set.
	$inserted = $wpdb->insert(
		$tbl_res,
		array(
			'tool_id'          => $tool_id,
			'member_id'        => (int) $member->member_id,
			'reservation_date' => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%s' )
	);

	if ( $inserted ) {
		// If the tool is on the shelf and nobody is ahead of them, this
		// reservation is collectable immediately and its hold period starts now.
		mtl_sync_reservation_readiness( $tool_id );
	}
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );

	$redirect( $inserted ? 'reserved' : 'reserve_failed' );
}

// --------------------------------------------------------------------------
// Sign-up page  (mtl_page=signup)
// --------------------------------------------------------------------------

/**
 * Renders the member sign-up page and handles its POST submission.
 *
 * @return void Outputs the page via mtl_render_front_shell(), which exits, or redirects and exits.
 */
function mtl_render_signup_page() {
	// Already signed in, so nothing to sign up for.
	if ( is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'main' ) );
		exit;
	}

	global $wpdb;
	$errors = array();
	// Set when the address already has an account or a membership. The form
	// doesn't say so; the next step is emailed to that address instead.
	$email_taken = false;

	// Typed twice because the address is what the member signs in with, and
	// only staff can change it afterwards. Kept out of $vals: it is a form field only,
	// never a value that reaches the members table. Repopulated on a validation
	// failure, unlike the passwords, so a member can see the two side by side
	// and correct whichever one is wrong.
	$email_confirm = '';

	// Agreements state, carried from the handler to the render below.
	// $agreement_ticked keeps the boxes ticked across a validation failure,
	// because making someone re-tick every box over a ZIP code typo loses
	// signups.
	$agreement_ticked  = array();
	$agreement_invalid = array();
	$agreement_missing = array();
	$agreement_changed = false;
	$agreement_seen    = array();
	// Where the "You must agree to" line sits in $errors, so the error list
	// can show it as links to the boxes rather than as plain text.
	$agreement_error_at = -1;

	// Sticky values so a validation error doesn't wipe the form (password
	// fields are intentionally never repopulated).
	$vals = array(
		'first_name'     => '',
		'last_name'      => '',
		'address_line1'  => '',
		'address_line2'  => '',
		'city'           => '',
		'state'          => '',
		'zip_code'       => '',
		'country'        => 'United States',
		'phone_country'  => 'US',
		'phone_national' => '',
		'email'          => '',
	);

	if ( mtl_is_post_request() && isset( $_POST['mtl_signup'] ) ) {
		if ( ! isset( $_POST['mtl_signup_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_signup_nonce'] ) ), 'mtl_signup_action' ) ) {
			$errors[] = 'Your session expired. Please try submitting the form again.';
		} elseif ( ! mtl_request_from_this_site() ) {
			// Logged-out nonces are the same for every visitor, so they can't
			// stop another site submitting this form and signing the visitor
			// into an account it chose. Checking where the request came from can.
			$errors[] = 'Please sign up using the form on this page.';
		} else {
			$vals['first_name']    = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$vals['last_name']     = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
			$vals['address_line1'] = sanitize_text_field( wp_unslash( $_POST['address_line1'] ?? '' ) );
			$vals['address_line2'] = sanitize_text_field( wp_unslash( $_POST['address_line2'] ?? '' ) );
			$vals['city']          = sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) );
			// mtl_valid_*() coerces anything outside the whitelist (a tampered
			// request) to '', so it's caught by the normal required-field /
			// blank-defaults-to-US handling below.
			$vals['state']    = mtl_valid_state( sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ) );
			$vals['zip_code'] = sanitize_text_field( wp_unslash( $_POST['zip_code'] ?? '' ) );
			$vals['country']  = mtl_valid_country( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
			if ( '' === $vals['country'] ) {
				$vals['country'] = 'United States';
			}
			// phone_country falls back to 'US' for anything outside the
			// dropdown's own options (mtl_valid_phone_country()), same
			// whitelist-or-default pattern as state/country above.
			$vals['phone_country']  = mtl_valid_phone_country( sanitize_text_field( wp_unslash( $_POST['phone_country'] ?? '' ) ) );
			$vals['phone_national'] = sanitize_text_field( wp_unslash( $_POST['phone_national'] ?? '' ) );
			$vals['email']          = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$email_confirm          = sanitize_email( wp_unslash( $_POST['email2'] ?? '' ) );
			// Stored exactly as WordPress core's sign-in checks it: still
			// slashed, and trimmed (see wp_signon() and wp_authenticate()).
			// Unslashing it would make a password containing a quote or a
			// backslash fail at every sign-in after this one.
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- kept the way core reads it; sanitizing or unslashing would change the password.
			$password  = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? trim( $_POST['password'] ) : '';
			$password2 = isset( $_POST['password2'] ) && is_string( $_POST['password2'] ) ? trim( $_POST['password2'] ) : '';
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			$phone_result = mtl_format_phone_number( $vals['phone_country'], $vals['phone_national'] );

			if ( '' === $vals['first_name'] || '' === $vals['last_name'] ) {
				$errors[] = 'Please enter your first and last name.';
			}
			if ( '' === $vals['address_line1'] || '' === $vals['city'] || '' === $vals['state'] || '' === $vals['zip_code'] ) {
				$errors[] = 'Please enter a complete address (street, city, state, and ZIP code).';
			}
			if ( '' !== $phone_result['error'] ) {
				$errors[] = $phone_result['error'];
			}
			if ( '' === $vals['email'] || ! is_email( $vals['email'] ) ) {
				$errors[] = 'Please enter a valid email address.';
			} elseif ( 0 !== strcasecmp( $vals['email'], $email_confirm ) ) {
				// Case-insensitive: Jo@x.com and jo@x.com are the same address,
				// and WordPress signs them in with either.
				$errors[] = 'The two email addresses you entered do not match.';
			} elseif ( email_exists( $vals['email'] ) || username_exists( $vals['email'] ) || mtl_find_member_by_email( $vals['email'] ) ) {
				// Not shown as an error, so the form never states that the
				// address is registered. Below, once the rest of the form checks
				// out, the next step is emailed to it instead. The outcome still
				// differs from a new signup, which is signed straight in.
				$email_taken = true;
			}
			if ( strlen( wp_unslash( $password ) ) < MTL_MIN_PASSWORD_LENGTH ) {
				$errors[] = 'Your password must be at least ' . (int) MTL_MIN_PASSWORD_LENGTH . ' characters long.';
			} elseif ( $password !== $password2 ) {
				$errors[] = 'The two passwords you entered do not match.';
			}

			// --- Agreements ---
			//
			// The mode and the live list are re-read here rather than trusted
			// from render time, so a form drawn under `full` still goes through
			// after a switch to `paper` or `off`. Errors accumulate with the
			// field errors above, so everything wrong reports at once.
			$posted           = mtl_read_agreements_post();
			$agreement_ticked = $posted['ticked'];
			$agreement_seen   = $posted['versions'];

			if ( mtl_agreements_online() ) {
				$live_agreements = mtl_get_active_agreements();
				$check           = mtl_check_agreements_submission( $live_agreements, $agreement_ticked, $agreement_seen );

				if ( $check['stale'] || $check['added'] ) {
					// An agreement changed while the form was open. Re-render
					// with the new wording and untick only what changed, keeping
					// every other field and tick.
					$agreement_changed = true;
					$agreement_ticked  = array_values( array_diff( $agreement_ticked, $check['stale'], $check['added'] ) );
					$errors[]          = 'One or more of our agreements changed while you were reading them. The updated wording is shown below; please review and agree again.';
				} elseif ( $check['missing'] ) {
					$agreement_missing = $check['missing'];
					// The error list shows this entry as links to the unticked
					// boxes (see $agreement_error_at), so these excerpts are
					// only its plain-text stand-in.
					$mtl_names = array();
					foreach ( $check['missing'] as $mtl_missed ) {
						$agreement_invalid[] = (int) $mtl_missed->agreement_id;
						$mtl_names[]         = wp_strip_all_tags( mtl_agreement_excerpt( $mtl_missed->agreement_text ) );
					}
					$agreement_error_at = count( $errors );
					$errors[]           = __( 'You must agree to:', 'my-tool-library' ) . ' ' . implode( '; ', $mtl_names );
				}
			}

			if ( empty( $errors ) && $email_taken ) {
				mtl_send_signup_existing_email( $vals['email'] );
				wp_safe_redirect( add_query_arg( 'mtl_msg', 'signup_check_email', mtl_front_page_url( 'login' ) ) );
				exit;
			}

			if ( empty( $errors ) ) {
				// The member row goes in first: the fallback login below needs
				// its id, and a failure here leaves no WordPress user to clean
				// up. If wp_insert_user() then fails, the row is deleted so a
				// retry can succeed.
				$tbl_members = $wpdb->prefix . 'members';
				$inserted    = $wpdb->insert(
					$tbl_members,
					array(
						'first_name'    => $vals['first_name'],
						'last_name'     => $vals['last_name'],
						'address_line1' => $vals['address_line1'],
						'address_line2' => '' !== $vals['address_line2'] ? $vals['address_line2'] : null,
						'city'          => $vals['city'],
						'state'         => $vals['state'],
						'zip_code'      => $vals['zip_code'],
						'country'       => $vals['country'],
						'phone_number'  => $phone_result['value'],
						'email'         => $vals['email'],
						'signup_date'   => current_time( 'Y-m-d' ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);

				if ( ! $inserted ) {
					$errors[] = 'Sorry, something went wrong creating your account. Please try again.';
				} else {
					$member_id = (int) $wpdb->insert_id;
					// The login is only a label; identity is matched on
					// user_email (see mtl_current_member()). wp_insert_user()
					// refuses a login over 60 characters and silently strips
					// characters such as "+", which can collide with an
					// existing login, so either case falls back to the same
					// generated label Add New Member uses.
					$login = $vals['email'];
					if ( mb_strlen( $login ) > 60 || sanitize_user( $login, true ) !== $login ) {
						$login = 'mtl_member_' . $member_id;
					}
					$user_id = wp_insert_user(
						array(
							'user_login'   => $login,
							'user_email'   => $vals['email'],
							'user_pass'    => $password,
							'first_name'   => $vals['first_name'],
							'last_name'    => $vals['last_name'],
							'display_name' => trim( $vals['first_name'] . ' ' . $vals['last_name'] ),
							'role'         => 'mtl_member',
						)
					);

					if ( is_wp_error( $user_id ) ) {
						// Roll back the member row so the email is free again.
						$wpdb->delete( $tbl_members, array( 'member_id' => $member_id ), array( '%d' ) );
						// Not escaped here; $errors members are escaped once,
						// at render time (see the esc_html($e) loop below).
						$errors[] = 'Sorry, that account could not be created: ' . $user_id->get_error_message();
					} else {
						update_user_meta( $user_id, 'mtl_member_id', $member_id );

						// Recorded before signing anybody in, so a shortfall can
						// still be rolled back. Compared against the number
						// expected, not against zero, because a half-agreed account
						// looks like a member who has not got round to it, so
						// nothing would ever flag it as broken.
						$agreements_ok = true;
						if ( mtl_agreements_online() ) {
							$outstanding = mtl_member_outstanding_agreements( $member_id );
							// An agreement published after the form was checked
							// has no version in $agreement_seen, and recording
							// it would say they accepted wording they never saw.
							$unseen = false;
							foreach ( $outstanding as $mtl_outstanding ) {
								if ( ! isset( $agreement_seen[ (int) $mtl_outstanding->agreement_id ] ) ) {
									$unseen = true;
								}
							}
							$agreements_ok = ! $unseen && count( $outstanding ) === mtl_record_all_outstanding_agreements( $member_id, 'signup', $agreement_seen );
						}

						if ( ! $agreements_ok ) {
							// Unwind everything, in the reverse of the order it
							// was created. The acceptance rows go with the
							// member row through the foreign key's ON DELETE
							// CASCADE, so there is nothing separate to clean up.
							require_once ABSPATH . 'wp-admin/includes/user.php';
							wp_delete_user( $user_id );
							$wpdb->delete( $tbl_members, array( 'member_id' => $member_id ), array( '%d' ) );
							$errors[] = 'Sorry, something went wrong recording your agreements. Nothing was saved. Please try again.';
						} else {
							// Sent outside the rollback path above: a mail
							// failure must not undo a completed signup, and the
							// member sees the normal success page either way.
							//
							// Re-deriving the event from the timestamp is safe
							// here because the member was created moments ago,
							// so every row they have is this one event. See
							// mtl_latest_acceptance_event_ids().
							if ( mtl_agreements_online() ) {
								mtl_send_agreement_confirmation_email( $member_id, mtl_latest_acceptance_event_ids( $member_id ) );
							}

							// Sign the new member in immediately; this runs on
							// template_redirect, before any output, so the auth
							// cookie can still be set.
							wp_set_current_user( $user_id, $vals['email'] );
							wp_set_auth_cookie( $user_id, true );
							$new_user = get_userdata( $user_id );
							do_action( 'wp_login', $new_user->user_login, $new_user );

							wp_safe_redirect( mtl_front_page_url( 'main' ) );
							exit;
						}
					}
				}
			}
		}
	}

	$action_url = mtl_front_page_url( 'signup' );

	// Online mode only, since paper mode collects signatures at the desk.
	// Read live rather than reusing the handler's copy, so the form shows the
	// agreements as they stand now.
	$signup_agreements = mtl_agreements_online() ? mtl_get_active_agreements() : array();

	ob_start();
	echo mtl_member_page_styles();
	?>
	<div class="mtl-signup">
		<div class="mtl-signup-intro">
			<h1 class="mtl-page-title">Join the library</h1>
			<p>Membership is free. You&rsquo;ll be signed in as soon as you finish.</p>
			<ul>
				<li>Reserve tools and see your place in line</li>
				<li>Check what&rsquo;s due and when</li>
				<li>Bring a photo ID and proof of address the first time you borrow</li>
			</ul>
			<p>Already a member? <a href="<?php echo esc_url( mtl_front_page_url( 'login' ) ); ?>">Sign in</a></p>
		</div>

		<form class="mtl-panel mtl-signup-form" method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( 'mtl_signup_action', 'mtl_signup_nonce' ); ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<?php
				// One list of everything to fix. role="alert" announces it on the
				// re-render, and autofocus (made focusable by tabindex) takes a
				// keyboard or screen-reader user straight to it.
				?>
				<div class="mtl-front-notice mtl-front-notice-error" role="alert" tabindex="-1" autofocus id="mtl-su-errors">
					<p><strong><?php echo 1 === count( $errors ) ? 'Please fix this and try again:' : 'Please fix these and try again:'; ?></strong></p>
					<ul>
						<?php foreach ( $errors as $mtl_error_at => $e ) : ?>
							<?php if ( $mtl_error_at === $agreement_error_at && $agreement_missing ) : ?>
								<li>
									<?php esc_html_e( 'You must agree to:', 'my-tool-library' ); ?>
									<ul>
										<?php foreach ( $agreement_missing as $agreement ) : ?>
											<li><a href="#<?php echo esc_attr( 'mtl-su-agreement-' . (int) $agreement->agreement_id ); ?>"><?php echo esc_html( mtl_agreement_excerpt( $agreement->agreement_text, 12 ) ); ?></a></li>
										<?php endforeach; ?>
									</ul>
								</li>
							<?php else : ?>
								<li><?php echo esc_html( $e ); ?></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<fieldset>
				<legend>Your name</legend>
				<div class="mtl-field-row">
					<div class="mtl-field">
						<label for="mtl-su-first">First name</label>
						<input type="text" id="mtl-su-first" name="first_name" value="<?php echo esc_attr( $vals['first_name'] ); ?>" autocomplete="given-name" required>
					</div>
					<div class="mtl-field">
						<label for="mtl-su-last">Last name</label>
						<input type="text" id="mtl-su-last" name="last_name" value="<?php echo esc_attr( $vals['last_name'] ); ?>" autocomplete="family-name" required>
					</div>
				</div>
			</fieldset>

			<fieldset>
				<legend>Address</legend>
				<div class="mtl-field">
					<label for="mtl-su-address1">Street address</label>
					<input type="text" id="mtl-su-address1" name="address_line1" value="<?php echo esc_attr( $vals['address_line1'] ); ?>" autocomplete="address-line1" aria-describedby="mtl-su-address1-hint" required>
					<p class="mtl-hint" id="mtl-su-address1-hint">Staff check it against your proof of address.</p>
				</div>
				<div class="mtl-field">
					<label for="mtl-su-address2">Address line 2 <small>(optional)</small></label>
					<input type="text" id="mtl-su-address2" name="address_line2" value="<?php echo esc_attr( $vals['address_line2'] ); ?>" autocomplete="address-line2">
				</div>
				<div class="mtl-field-row">
					<div class="mtl-field">
						<label for="mtl-su-city">City</label>
						<input type="text" id="mtl-su-city" name="city" value="<?php echo esc_attr( $vals['city'] ); ?>" autocomplete="address-level2" required>
					</div>
					<div class="mtl-field">
						<label for="mtl-su-state">State or province</label>
						<select id="mtl-su-state" name="state" required>
							<option value="">Choose one</option>
							<?php foreach ( mtl_get_state_options() as $mtl_state_code => $mtl_state_label ) : ?>
								<option value="<?php echo esc_attr( $mtl_state_code ); ?>" <?php selected( $vals['state'], $mtl_state_code ); ?>><?php echo esc_html( $mtl_state_label ); ?> (<?php echo esc_html( $mtl_state_code ); ?>)</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="mtl-field-row">
					<div class="mtl-field">
						<label for="mtl-su-zip">ZIP or postal code</label>
						<input type="text" id="mtl-su-zip" name="zip_code" value="<?php echo esc_attr( $vals['zip_code'] ); ?>" autocomplete="postal-code" required>
					</div>
					<div class="mtl-field">
						<label for="mtl-su-country">Country</label>
						<select id="mtl-su-country" name="country" required>
							<?php foreach ( mtl_get_country_options() as $mtl_country_name ) : ?>
								<option value="<?php echo esc_attr( $mtl_country_name ); ?>" <?php selected( $vals['country'], $mtl_country_name ); ?>><?php echo esc_html( $mtl_country_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</fieldset>

			<fieldset>
				<legend>Contact and sign-in</legend>
				<div class="mtl-field">
					<label for="mtl-su-phone_national">Phone number</label>
					<?php mtl_render_phone_input( $vals['phone_country'], $vals['phone_national'], 'mtl-su-' ); ?>
				</div>
				<div class="mtl-field-row">
					<div class="mtl-field">
						<label for="mtl-su-email">Email address</label>
						<input type="email" id="mtl-su-email" name="email" value="<?php echo esc_attr( $vals['email'] ); ?>" autocomplete="email" aria-describedby="mtl-su-email-hint" required>
						<p class="mtl-hint" id="mtl-su-email-hint">You&rsquo;ll sign in with this.</p>
					</div>
					<div class="mtl-field">
						<label for="mtl-su-email2">Email address again</label>
						<input type="email" id="mtl-su-email2" name="email2" value="<?php echo esc_attr( $email_confirm ); ?>" autocomplete="email" required>
					</div>
				</div>
				<div class="mtl-field-row">
					<div class="mtl-field">
						<label for="mtl-su-pass">Password</label>
						<input type="password" id="mtl-su-pass" name="password" autocomplete="new-password" aria-describedby="mtl-su-pass-hint" required>
						<p class="mtl-hint" id="mtl-su-pass-hint">At least <?php echo (int) MTL_MIN_PASSWORD_LENGTH; ?> characters.</p>
					</div>
					<div class="mtl-field">
						<label for="mtl-su-pass2">Password again</label>
						<input type="password" id="mtl-su-pass2" name="password2" autocomplete="new-password" required>
					</div>
				</div>
			</fieldset>

			<?php
			if ( $signup_agreements ) {
				// Escapes every value it interpolates; the markup around those
				// values is the plugin's own. Its legend makes it the form's
				// fourth group.
				echo mtl_render_agreements_fieldset( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the renderer.
					$signup_agreements,
					array(
						'context'   => 'signup',
						'id_prefix' => 'mtl-su-agreement',
						'legend'    => 'Member agreements',
						'intro'     => $agreement_changed
							? 'These have changed since you opened this page. Please read them again.'
							: 'Please read these and tick each box.',
						'checked'   => $agreement_ticked,
						'invalid'   => $agreement_invalid,
					)
				);
			}
			?>

			<div class="mtl-form-actions">
				<button type="submit" name="mtl_signup" value="1" class="mtl-btn mtl-btn-primary mtl-member-submit">Create my account</button>
			</div>
		</form>
	</div>
	<?php
	$body = ob_get_clean();

	mtl_render_front_shell( 'Join the library', $body );
}

/**
 * The queue drawn as small squares: filled for the people ahead, the button
 * color for the member, empty for the people behind. Ten at most, then "+N".
 *
 * Decoration only, hidden from screen readers: the sentence beside it ("2nd
 * of 4 in line") says the same thing.
 *
 * @param int $place The member's place in line, from 1.
 * @param int $size  How many are in line, the member included.
 * @return string HTML, built from integers only.
 */
function mtl_member_line_dots_html( $place, $size ) {
	$place = max( 1, (int) $place );
	$size  = max( $place, (int) $size );
	$shown = min( $size, 10 );
	// Someone further back than tenth is drawn on the last square.
	$you = min( $place, $shown );

	$out = '<span class="mtl-line-dots" aria-hidden="true">';
	for ( $i = 1; $i <= $shown; $i++ ) {
		if ( $i === $you ) {
			$out .= '<i class="mtl-line-you"></i>';
		} elseif ( $i < $you ) {
			$out .= '<i class="mtl-line-ahead"></i>';
		} else {
			$out .= '<i></i>';
		}
	}
	if ( $size > $shown ) {
		$out .= '<span class="mtl-line-more">+' . (int) ( $size - $shown ) . '</span>';
	}
	return $out . '</span>';
}

/**
 * Where a reservation stands, in the words the member pages use.
 *
 * Only the member at the front of the line hears about the tool itself; for
 * anyone behind them, whether it is out or in for repair changes nothing.
 *
 * @param object $r Reservation row with queue_place, active_loans,
 *                  maintenance_at and ready_since.
 * @return string[] Status suffix (ok, out, maint or wait, as in
 *                  .mtl-status-*), its label, and a short note or ''.
 */
function mtl_member_reservation_state( $r ) {
	if ( 1 !== (int) $r->queue_place ) {
		return array( 'wait', 'Waiting in line', '' );
	}
	if ( (int) $r->active_loans > 0 ) {
		return array( 'out', 'On loan', 'Waiting for its return' );
	}
	if ( ! empty( $r->maintenance_at ) ) {
		return array( 'maint', 'Under maintenance', 'Being repaired or serviced' );
	}
	$collect_by = mtl_reservation_collect_by( $r->ready_since );
	return array( 'ok', 'Ready for pickup', '' !== $collect_by ? 'until ' . gmdate( 'M j', strtotime( $collect_by ) ) : '' );
}

/**
 * The line under a tool's name in the reservation list and the loan history:
 * "No. 63, Makita", or less when the library hides tool numbers or there is
 * no brand.
 *
 * @param int    $tool_id Tool row ID.
 * @param string $brand   Brand, possibly slashed or empty.
 * @return string Plain text, not escaped.
 */
function mtl_member_tool_meta( $tool_id, $brand ) {
	$look  = mtl_public_look();
	$parts = array();
	if ( $look['show_tool_numbers'] ) {
		$parts[] = 'No. ' . (int) $tool_id;
	}
	$brand = trim( stripslashes( (string) $brand ) );
	if ( '' !== $brand ) {
		$parts[] = $brand;
	}
	return implode( ', ', $parts );
}

/**
 * Render one reserved tool's detail panel for My loans & reservations: the
 * tool's customer-facing details plus this member's reservation (place in
 * line, where the tool is, dates) and a cancel link. Laid out like the
 * catalog's tool panel, and follows the same rule on shelf location: shown
 * only when the library has turned it on for members.
 *
 * @param object $r        Enriched reservation row from the query below.
 * @param string $self_url Current page URL, used to build the cancel link.
 * @return string HTML.
 */
function mtl_render_reservation_detail_panel( $r, $self_url ) {
	$name        = stripslashes( $r->tool_name );
	$title_id    = 'res-tool-' . (int) $r->reservation_id . '-title';
	$state       = mtl_member_reservation_state( $r );
	$ready_since = trim( (string) $r->ready_since );
	$collect_by  = mtl_reservation_collect_by( $ready_since );
	$look        = mtl_public_look();

	$sub = array();
	if ( '' !== trim( (string) $r->brand ) ) {
		$sub[] = stripslashes( $r->brand );
	}
	if ( $look['show_tool_numbers'] ) {
		$sub[] = ( $sub ? 'tool no. ' : 'Tool no. ' ) . (int) $r->tool_id;
	}

	$facts = array(
		'Category'       => $r->categories,
		'Sub-categories' => $r->subcategories,
		'Tags'           => $r->tags,
	);
	$facts = array_filter( array_map( 'trim', array_map( 'strval', $facts ) ) );

	// Only opens the confirm prompt; the cancel itself is a POST.
	$cancel_url = add_query_arg(
		array(
			'mtl_confirm' => 'one',
			'rid'         => (int) $r->reservation_id,
		),
		$self_url
	);

	ob_start();
	?>
	<?php if ( ! empty( $r->photo_url ) ) : ?>
		<img class="mtl-res-detail-photo" src="<?php echo esc_url( $r->photo_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy">
	<?php endif; ?>
	<div class="mtl-res-detail-body">
		<a class="mtl-btn-text mtl-res-close" href="#mtl-reservations">Close<span class="mtl-sr-only"> the details for <?php echo esc_html( $name ); ?></span></a>
		<h2 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $name ); ?></h2>
		<?php if ( $sub ) : ?>
			<p class="mtl-res-detail-sub"><?php echo esc_html( implode( ', ', $sub ) ); ?></p>
		<?php endif; ?>

		<div class="mtl-res-info mtl-res-info-<?php echo esc_attr( $state[0] ); ?>">
			<strong><?php echo esc_html( $state[1] ); ?></strong>
			<p>
				You&rsquo;re <?php echo esc_html( mtl_shop_ordinal( $r->queue_place ) ); ?> of <?php echo (int) $r->queue_size; ?> in line.
				Reserved on <?php echo mtl_format_date( $r->reservation_date, 'M j, Y' ); ?>.
			</p>
			<?php if ( 'out' === $state[0] ) : ?>
				<p>It&rsquo;s out on loan. We&rsquo;ll hold it for you when it comes back.</p>
			<?php elseif ( 'maint' === $state[0] ) : ?>
				<p>This tool is being repaired or serviced.</p>
			<?php endif; ?>
			<?php
			// Only a reservation that is actually collectable has a deadline;
			// anyone still queued behind a loan has no countdown running.
			// ready_since is checked separately because a hold period of 0
			// (never expires) still holds the tool from that date, just with
			// no cut-off.
			if ( '' !== $ready_since ) :
				?>
				<p>
					On the hold shelf since <?php echo mtl_format_date( $ready_since, 'M j' ); ?>.
					<?php if ( '' !== $collect_by ) : ?>
						<strong>Pick it up by <?php echo mtl_format_date( $collect_by, 'l, M j' ); ?></strong>, or the reservation is cancelled and the tool passes to the next person in line.
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $r->description ) ) : ?>
			<h3>About this tool</h3>
			<p><?php echo nl2br( esc_html( stripslashes( $r->description ) ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $r->components ) ) : ?>
			<h3>What&rsquo;s included</h3>
			<p><?php echo nl2br( esc_html( stripslashes( $r->components ) ) ); ?></p>
		<?php endif; ?>

		<?php echo mtl_shop_location_block( $r->location ); ?>

		<?php if ( $facts ) : ?>
			<dl class="mtl-res-facts">
				<?php foreach ( $facts as $mtl_label => $mtl_value ) : ?>
					<dt><?php echo esc_html( $mtl_label ); ?></dt>
					<dd><?php echo esc_html( stripslashes( $mtl_value ) ); ?></dd>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>

		<?php
		// Last, after everything describing the tool, the same place the
		// catalog puts them. Same helper too, so a member waiting on a tool
		// sees exactly the links everyone else does.
		echo mtl_tool_all_links_html( $r );
		?>

		<p class="mtl-res-detail-cancel">
			<a class="mtl-btn-danger" href="<?php echo esc_url( $cancel_url ); ?>">Cancel this reservation</a>
		</p>
	</div>
	<?php
	return ob_get_clean();
}

// --------------------------------------------------------------------------
// My Reservations page  (mtl_page=reservations)
// --------------------------------------------------------------------------

/**
 * Renders the member's "My loans & reservations" page and handles its
 * cancel-reservation POST actions.
 *
 * @return void Outputs the page via mtl_render_front_shell(), which exits, or redirects and exits.
 */
function mtl_render_member_reservations_page() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'login' ) );
		exit;
	}

	$member = mtl_current_member();
	if ( ! $member ) {
		mtl_render_member_only_notice( 'My loans & reservations', mtl_current_member_link_broken() );
		return;
	}

	global $wpdb;
	$tbl_res        = $wpdb->prefix . 'tool_reservations';
	$tbl_inv        = $wpdb->prefix . 'tool_inventory';
	$tbl_loans      = $wpdb->prefix . 'loans';
	$tbl_cats       = $wpdb->prefix . 'tool_categories';
	$tbl_cat_map    = $wpdb->prefix . 'tool_category_mappings';
	$tbl_subcats    = $wpdb->prefix . 'tool_subcategories';
	$tbl_subcat_map = $wpdb->prefix . 'tool_subcategory_mappings';
	$tbl_tags       = $wpdb->prefix . 'tool_tags';
	$tbl_tag_map    = $wpdb->prefix . 'tool_tag_mappings';
	$self           = mtl_front_page_url( 'reservations' );

	// --- Handle cancel actions (POST + nonce), then PRG-redirect. ---
	if ( mtl_is_post_request() && isset( $_POST['mtl_action'] ) ) {
		$action = sanitize_key( wp_unslash( $_POST['mtl_action'] ) );
		$valid  = isset( $_POST['mtl_res_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_res_nonce'] ) ), 'mtl_res_action' );

		// Cancelling does not delete the row; it closes the reservation by
		// stamping expiry_date, so it becomes history. member_id is enforced
		// in every WHERE so a member can only cancel their own reservations.
		$today = current_time( 'mysql' );

		if ( $valid && 'cancel_reservation' === $action ) {
			$rid = isset( $_POST['reservation_id'] ) ? (int) $_POST['reservation_id'] : 0;
			// Read before the cancel, while the row still matches.
			$cancel_tool_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
					"SELECT tool_id FROM {$tbl_res} WHERE reservation_id = %d AND member_id = %d AND expiry_date IS NULL",
					$rid,
					(int) $member->member_id
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
					"UPDATE {$tbl_res} SET expiry_date = %s, closed_reason = %s
                 WHERE reservation_id = %d AND member_id = %d AND expiry_date IS NULL",
					$today,
					'cancelled_member',
					$rid,
					(int) $member->member_id
				)
			);
			// Giving up their place promotes whoever was behind them.
			mtl_sync_reservation_readiness( $cancel_tool_id );
			wp_safe_redirect( add_query_arg( 'mtl_msg', 'reservation_cancelled', $self ) );
			exit;
		}

		if ( $valid && 'cancel_all' === $action ) {
			$freed_tool_ids = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
					"SELECT DISTINCT tool_id FROM {$tbl_res} WHERE member_id = %d AND expiry_date IS NULL",
					(int) $member->member_id
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
					"UPDATE {$tbl_res} SET expiry_date = %s, closed_reason = %s
                 WHERE member_id = %d AND expiry_date IS NULL",
					$today,
					'cancelled_member',
					(int) $member->member_id
				)
			);
			foreach ( $freed_tool_ids as $freed_tool_id ) {
				mtl_sync_reservation_readiness( (int) $freed_tool_id );
			}
			wp_safe_redirect( add_query_arg( 'mtl_msg', 'reservations_cancelled', $self ) );
			exit;
		}

		// A confirm page left open past the nonce's life: say nothing changed,
		// rather than returning to the list as if the cancel had worked.
		if ( ! $valid && in_array( $action, array( 'cancel_reservation', 'cancel_all' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'mtl_msg', 'session_expired', $self ) );
			exit;
		}
	}

	// --- Active loans, soonest due first. ---
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$active_loans = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.loan_id, l.tool_id, l.loan_date, l.due_date, t.tool_name
         FROM {$tbl_loans} l
         JOIN {$tbl_inv} t ON t.tool_id = l.tool_id
         WHERE l.member_id = %d AND l.return_date IS NULL
         ORDER BY l.due_date ASC",
			(int) $member->member_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$today_ts = strtotime( current_time( 'Y-m-d' ) );
	foreach ( $active_loans as $loan ) {
		$days_left = (int) round( ( strtotime( $loan->due_date ) - $today_ts ) / DAY_IN_SECONDS );
		// Three days or fewer is "due soon", and gets the warning color.
		if ( $days_left < 0 ) {
			$loan->due_class = 'late';
			$loan->due_label = -1 === $days_left ? 'Overdue by 1 day' : 'Overdue by ' . abs( $days_left ) . ' days';
		} elseif ( 0 === $days_left ) {
			$loan->due_class = 'soon';
			$loan->due_label = 'Due today';
		} elseif ( 1 === $days_left ) {
			$loan->due_class = 'soon';
			$loan->due_label = 'Due tomorrow';
		} else {
			$loan->due_class = $days_left <= 3 ? 'soon' : 'fine';
			$loan->due_label = 'Due in ' . $days_left . ' days';
		}
	}

	// Set on the Setup page. Blank hides it (see the update_option() comment in
	// setup-page.php), and the fallback matches Setup's default for new sites.
	$pickup_directions = trim(
		(string) get_option(
			'mtl_pickup_directions',
			'Placing a reservation holds your spot in line and speeds up the process of checking out tools. If no one is waiting in line to borrow a tool, no reservation is required. Come by our store and speak with a representative to take tools home.'
		)
	);

	// --- Active reservations, with everything the detail panel needs.
	// queue_place counts this reservation and those ahead of it (earlier
	// reservation_date, ties broken by reservation_id), the same way the
	// admin Loans & Reservations page does. ---
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table names only, built from $wpdb->prefix, not user input; the concatenated fragments are mtl_tool_primary_photo_sql(), a fixed subquery, and mtl_tool_link_columns_sql(), column names from the registry.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT r.reservation_id, r.tool_id, r.reservation_date, r.ready_since,
                t.tool_name, t.brand, t.description, t.components, ' . mtl_tool_primary_photo_sql() . ', t.location, t.maintenance_at'
			. mtl_tool_link_columns_sql() . ",
                (SELECT COUNT(*) FROM {$tbl_res} r2
                    WHERE r2.tool_id = r.tool_id AND r2.expiry_date IS NULL
                      AND (r2.reservation_date < r.reservation_date
                           OR (r2.reservation_date = r.reservation_date AND r2.reservation_id <= r.reservation_id))
                ) AS queue_place,
                (SELECT COUNT(*) FROM {$tbl_res} r3
                    WHERE r3.tool_id = r.tool_id AND r3.expiry_date IS NULL
                ) AS queue_size,
                (SELECT COUNT(*) FROM {$tbl_loans} l
                    WHERE l.tool_id = r.tool_id AND l.return_date IS NULL
                ) AS active_loans,
                (SELECT GROUP_CONCAT(c.category_name ORDER BY c.category_name SEPARATOR ', ')
                    FROM {$tbl_cat_map} cm JOIN {$tbl_cats} c ON c.category_id = cm.category_id
                    WHERE cm.tool_id = r.tool_id
                ) AS categories,
                (SELECT GROUP_CONCAT(sc.subcategory_name ORDER BY sc.subcategory_name SEPARATOR ', ')
                    FROM {$tbl_subcat_map} sm JOIN {$tbl_subcats} sc ON sc.subcategory_id = sm.subcategory_id
                    WHERE sm.tool_id = r.tool_id
                ) AS subcategories,
                (SELECT GROUP_CONCAT(tg.tag_name ORDER BY tg.tag_name SEPARATOR ', ')
                    FROM {$tbl_tag_map} tm JOIN {$tbl_tags} tg ON tg.tag_id = tm.tag_id
                    WHERE tm.tool_id = r.tool_id
                ) AS tags
         FROM {$tbl_res} r
         JOIN {$tbl_inv} t ON t.tool_id = r.tool_id
         WHERE r.member_id = %d AND r.expiry_date IS NULL
         ORDER BY r.reservation_date ASC",
			(int) $member->member_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

	// --- Confirmation step (zero-JS): Cancel links just render an "Are you
	// sure?" prompt via ?mtl_confirm=one|all; nothing is cancelled until
	// the member submits the POST form in that prompt (handled above).
	// $confirm_row is matched within the member-scoped $rows so a
	// tampered id can never target someone else's reservation. ---
	$confirm     = isset( $_GET['mtl_confirm'] ) ? sanitize_key( wp_unslash( $_GET['mtl_confirm'] ) ) : '';
	$confirm_rid = isset( $_GET['rid'] ) ? (int) $_GET['rid'] : 0;
	$confirm_row = null;
	if ( 'one' === $confirm && $confirm_rid > 0 ) {
		foreach ( $rows as $candidate ) {
			if ( (int) $candidate->reservation_id === $confirm_rid ) {
				$confirm_row = $candidate;
				break;
			}
		}
	}

	$confirm_one = 'one' === $confirm && $confirm_row;
	$confirm_all = 'all' === $confirm && ! empty( $rows );

	// First in line with the hold started: the tool is on the shelf for them.
	$ready_rows = array();
	foreach ( $rows as $r ) {
		if ( 1 === (int) $r->queue_place && '' !== trim( (string) $r->ready_since ) ) {
			$ready_rows[] = $r;
		}
	}

	$look    = mtl_public_look();
	$notices = mtl_account_locked_banner_html() . mtl_front_notice_html() . mtl_agreements_banner_html();
	$giving  = mtl_giving_section_html();

	ob_start();
	echo mtl_member_page_styles();
	?>
	<div class="mtl-member mtl-res-page">
		<div class="mtl-res-main">
			<h1 class="mtl-page-title">Your loans and reservations</h1>

			<?php if ( '' !== $notices ) : ?>
				<div class="mtl-member-notices">
					<?php echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each helper escapes its own output. ?>
				</div>
			<?php endif; ?>

			<?php if ( $confirm_one ) : ?>
				<section class="mtl-panel mtl-confirm" aria-labelledby="mtl-confirm-title">
					<h2 id="mtl-confirm-title">Cancel this reservation?</h2>
					<p>You&rsquo;ll lose your place in line for <strong><?php echo esc_html( stripslashes( $confirm_row->tool_name ) ); ?></strong>, and this can&rsquo;t be undone.</p>
					<div class="mtl-confirm-actions">
						<form method="post" action="<?php echo esc_url( $self ); ?>">
							<?php wp_nonce_field( 'mtl_res_action', 'mtl_res_nonce' ); ?>
							<input type="hidden" name="mtl_action" value="cancel_reservation">
							<input type="hidden" name="reservation_id" value="<?php echo (int) $confirm_row->reservation_id; ?>">
							<button type="submit" class="mtl-btn mtl-member-danger-btn">Yes, cancel this reservation</button>
						</form>
						<a class="mtl-btn" href="<?php echo esc_url( $self ); ?>">No, keep it</a>
					</div>
				</section>

			<?php elseif ( $confirm_all ) : ?>
				<?php $mtl_one = ( 1 === count( $rows ) ); ?>
				<section class="mtl-panel mtl-confirm" aria-labelledby="mtl-confirm-title">
					<h2 id="mtl-confirm-title"><?php echo $mtl_one ? 'Cancel your reservation?' : 'Cancel all your reservations?'; ?></h2>
					<?php if ( $mtl_one ) : ?>
						<p>You&rsquo;ll lose your place in line, and this can&rsquo;t be undone.</p>
					<?php else : ?>
						<p>All <?php echo count( $rows ); ?> of your reservations will be cancelled. You&rsquo;ll lose every place in line, and this can&rsquo;t be undone.</p>
					<?php endif; ?>
					<div class="mtl-confirm-actions">
						<form method="post" action="<?php echo esc_url( $self ); ?>">
							<?php wp_nonce_field( 'mtl_res_action', 'mtl_res_nonce' ); ?>
							<input type="hidden" name="mtl_action" value="cancel_all">
							<button type="submit" class="mtl-btn mtl-member-danger-btn"><?php echo $mtl_one ? 'Yes, cancel it' : 'Yes, cancel all reservations'; ?></button>
						</form>
						<a class="mtl-btn" href="<?php echo esc_url( $self ); ?>"><?php echo $mtl_one ? 'No, keep it' : 'No, keep them'; ?></a>
					</div>
				</section>

			<?php else : ?>
				<?php
				foreach ( $ready_rows as $r ) :
					$mtl_name       = stripslashes( $r->tool_name );
					$mtl_collect_by = mtl_reservation_collect_by( $r->ready_since );
					?>
					<section class="mtl-ready<?php echo empty( $r->photo_url ) ? ' mtl-ready-no-photo' : ''; ?>" aria-labelledby="<?php echo esc_attr( 'mtl-ready-' . (int) $r->reservation_id ); ?>">
						<?php if ( ! empty( $r->photo_url ) ) : ?>
							<img class="mtl-ready-photo" src="<?php echo esc_url( $r->photo_url ); ?>" alt="" loading="lazy">
						<?php endif; ?>
						<div>
							<h2 class="mtl-ready-title" id="<?php echo esc_attr( 'mtl-ready-' . (int) $r->reservation_id ); ?>"><?php echo esc_html( $mtl_name ); ?> is ready for you</h2>
							<p>
								On the hold shelf since <?php echo mtl_format_date( $r->ready_since, 'l, M j' ); ?>.
								<?php if ( '' !== $mtl_collect_by ) : ?>
									<span class="mtl-ready-by">Pick it up by <?php echo mtl_format_date( $mtl_collect_by, 'l, M j' ); ?>.</span>
								<?php endif; ?>
							</p>
							<?php if ( '' !== $pickup_directions ) : ?>
								<p class="mtl-ready-note"><?php echo nl2br( esc_html( $pickup_directions ) ); ?></p>
							<?php endif; ?>
						</div>
						<a class="mtl-btn" href="<?php echo esc_attr( '#res-tool-' . (int) $r->reservation_id ); ?>">Tool details<span class="mtl-sr-only"> for <?php echo esc_html( $mtl_name ); ?></span></a>
					</section>
				<?php endforeach; ?>

				<section aria-labelledby="mtl-loans-title">
					<div class="mtl-section-head">
						<h2 id="mtl-loans-title">Checked out</h2>
						<?php if ( $active_loans ) : ?>
							<span><?php echo esc_html( count( $active_loans ) . ( 1 === count( $active_loans ) ? ' tool' : ' tools' ) ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( empty( $active_loans ) ) : ?>
						<div class="mtl-panel">
							<p class="mtl-member-empty">You don&rsquo;t have any tools checked out.</p>
						</div>
					<?php else : ?>
						<ul class="mtl-slips">
							<?php
							foreach ( $active_loans as $loan ) :
								$mtl_meta = $look['show_tool_numbers'] ? 'No. ' . (int) $loan->tool_id . ', borrowed ' : 'Borrowed ';
								?>
								<li class="mtl-slip<?php echo 'late' === $loan->due_class ? ' mtl-slip-late' : ''; ?>">
									<div class="mtl-slip-due">
										<span class="mtl-sr-only">Due</span>
										<span class="mtl-slip-m"><?php echo mtl_format_date( $loan->due_date, 'M' ); ?></span>
										<span class="mtl-slip-d"><?php echo mtl_format_date( $loan->due_date, 'j' ); ?></span>
										<span class="mtl-slip-w"><?php echo mtl_format_date( $loan->due_date, 'D' ); ?></span>
									</div>
									<div>
										<h3><?php echo esc_html( stripslashes( $loan->tool_name ) ); ?></h3>
										<p class="mtl-slip-meta"><?php echo esc_html( $mtl_meta ); ?><?php echo mtl_format_date( $loan->loan_date, 'M j' ); ?></p>
									</div>
									<p class="mtl-slip-state mtl-slip-state-<?php echo esc_attr( $loan->due_class ); ?>"><?php echo esc_html( $loan->due_label ); ?></p>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>

				<?php if ( ! $ready_rows && '' !== $pickup_directions ) : ?>
					<p class="mtl-pickup-note"><strong>Picking up a tool.</strong> <?php echo nl2br( esc_html( $pickup_directions ) ); ?></p>
				<?php endif; ?>

				<section id="mtl-reservations" aria-labelledby="mtl-res-title">
					<div class="mtl-section-head">
						<h2 id="mtl-res-title">Reservations</h2>
						<?php if ( $rows ) : ?>
							<span><?php echo esc_html( count( $rows ) . ( 1 === count( $rows ) ? ' tool' : ' tools' ) ); ?></span>
							<span class="mtl-section-aside">Select a tool to see its details.</span>
						<?php endif; ?>
					</div>
					<?php if ( empty( $rows ) ) : ?>
						<div class="mtl-panel">
							<p class="mtl-member-empty">You don&rsquo;t have any reservations. Browse the <a href="<?php echo esc_url( mtl_front_page_url( 'main' ) ); ?>">tool catalog</a> and reserve a tool to join its queue.</p>
						</div>
					<?php else : ?>
						<ul class="mtl-panel mtl-res-list">
							<?php
							foreach ( $rows as $r ) :
								$mtl_name  = stripslashes( $r->tool_name );
								$mtl_meta  = mtl_member_tool_meta( $r->tool_id, $r->brand );
								$mtl_state = mtl_member_reservation_state( $r );
								// Only opens the confirm prompt; the cancel itself is a POST.
								$mtl_cancel_url = add_query_arg(
									array(
										'mtl_confirm' => 'one',
										'rid'         => (int) $r->reservation_id,
									),
									$self
								);
								?>
								<li class="mtl-res-row">
									<div>
										<h3><a href="<?php echo esc_attr( '#res-tool-' . (int) $r->reservation_id ); ?>"><?php echo esc_html( $mtl_name ); ?></a></h3>
										<?php if ( '' !== $mtl_meta ) : ?>
											<p class="mtl-res-meta"><?php echo esc_html( $mtl_meta ); ?></p>
										<?php endif; ?>
									</div>
									<div class="mtl-res-line">
										<strong><?php echo esc_html( mtl_shop_ordinal( $r->queue_place ) ); ?></strong> of <?php echo (int) $r->queue_size; ?> in line
										<?php echo mtl_member_line_dots_html( $r->queue_place, $r->queue_size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from integers only. ?>
									</div>
									<div class="mtl-res-state">
										<span class="mtl-status mtl-status-<?php echo esc_attr( $mtl_state[0] ); ?>"><?php echo esc_html( $mtl_state[1] ); ?></span>
										<?php echo esc_html( $mtl_state[2] ); ?>
									</div>
									<a class="mtl-btn-danger mtl-res-cancel" href="<?php echo esc_url( $mtl_cancel_url ); ?>">Cancel<span class="mtl-sr-only"> your reservation for <?php echo esc_html( $mtl_name ); ?></span></a>
								</li>
							<?php endforeach; ?>
						</ul>
						<?php if ( count( $rows ) > 1 ) : ?>
							<div class="mtl-res-foot">
								<a class="mtl-btn-danger" href="<?php echo esc_url( add_query_arg( 'mtl_confirm', 'all', $self ) ); ?>">Cancel all reservations</a>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</section>
			<?php endif; ?>
		</div>

		<?php if ( $rows && ! $confirm_one && ! $confirm_all ) : ?>
			<?php // Every panel is printed and CSS :target opens one, as in the catalog. ?>
			<aside class="mtl-res-detail" aria-label="Reservation details">
				<?php foreach ( $rows as $r ) : ?>
					<div class="mtl-res-detail-panel" id="<?php echo esc_attr( 'res-tool-' . (int) $r->reservation_id ); ?>" tabindex="-1" role="region" aria-labelledby="<?php echo esc_attr( 'res-tool-' . (int) $r->reservation_id . '-title' ); ?>">
						<?php echo mtl_render_reservation_detail_panel( $r, $self ); ?>
					</div>
				<?php endforeach; ?>
			</aside>
		<?php endif; ?>

		<?php if ( '' !== $giving ) : ?>
			<?php // Last on the page, so it never pushes a cancel confirmation down. ?>
			<div class="mtl-res-after">
				<?php echo $giving; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mtl_giving_section_html() escapes its own output. ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	$body = ob_get_clean();

	mtl_render_front_shell( 'My loans & reservations', $body );
}

// --------------------------------------------------------------------------
// Account page  (mtl_page=account)
// --------------------------------------------------------------------------

/**
 * Renders the member's Account page and handles its agreement, profile-update
 * and account-deletion POSTs.
 *
 * @return void Outputs the page via mtl_render_front_shell(), which exits, or redirects and exits.
 */
function mtl_render_account_page() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'login' ) );
		exit;
	}

	$member = mtl_current_member();
	if ( ! $member ) {
		mtl_render_member_only_notice( 'My account', mtl_current_member_link_broken() );
		return;
	}

	global $wpdb;
	$tbl_members      = $wpdb->prefix . 'members';
	$tbl_verif        = $wpdb->prefix . 'member_verifications';
	$tbl_loans        = $wpdb->prefix . 'loans';
	$tbl_inv          = $wpdb->prefix . 'tool_inventory';
	$tbl_trainings    = $wpdb->prefix . 'member_trainings';
	$tbl_training_map = $wpdb->prefix . 'member_training_mappings';

	$errors = array();

	// A verified member's unsaved edits, held while they confirm a change that
	// would reset their verification. Null when nothing is waiting.
	$pending_details = null;

	// Agreements state, carried down to the render below.
	$agreement_ticked   = array();
	$agreement_invalid  = array();
	$agreement_missing  = array();
	$agreement_changed  = false;
	$agreement_errors   = array();
	$agreement_recorded = false;

	// --- Handle the agreements submission (POST + its own nonce). ---
	// Kept apart from the profile form, so fixing a phone number never reports
	// an unticked agreement. No PRG redirect: this render shows the result.
	if ( mtl_is_post_request() && isset( $_POST['mtl_agree'] ) && mtl_agreements_online() ) {
		if ( ! isset( $_POST['mtl_agreements_agree_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_agreements_agree_nonce'] ) ), 'mtl_agreements_agree_action' ) ) {
			$agreement_errors[] = 'Your session expired. Please try agreeing again.';
		} else {
			$posted           = mtl_read_agreements_post();
			$agreement_ticked = $posted['ticked'];
			$outstanding      = mtl_member_outstanding_agreements( (int) $member->member_id );

			// Every submitted id is checked against this member's own
			// outstanding list first, so a forged POST naming a retired
			// agreement, one they are already current on, or one that does not
			// exist is rejected here rather than deeper in the writer.
			$outstanding_ids  = array_map( 'intval', wp_list_pluck( $outstanding, 'agreement_id' ) );
			$agreement_ticked = array_values( array_intersect( $agreement_ticked, $outstanding_ids ) );

			$check = mtl_check_agreements_submission( $outstanding, $agreement_ticked, $posted['versions'] );

			if ( $check['stale'] || $check['added'] ) {
				$agreement_changed  = true;
				$agreement_ticked   = array_values( array_diff( $agreement_ticked, $check['stale'], $check['added'] ) );
				$agreement_errors[] = 'One or more of our agreements changed while you were reading them. The updated wording is shown below; please review and agree again.';
			} elseif ( $check['missing'] ) {
				$agreement_missing = $check['missing'];
				foreach ( $check['missing'] as $mtl_missed ) {
					$agreement_invalid[] = (int) $mtl_missed->agreement_id;
				}
			} else {
				// No rollback, unlike signup: a failed row just stays outstanding.
				// Ids are kept as written; accepted_at is too coarse to re-derive.
				$written     = 0;
				$written_ids = array();
				foreach ( $outstanding as $mtl_agreement ) {
					$aid = (int) $mtl_agreement->agreement_id;
					if ( ! in_array( $aid, $agreement_ticked, true ) ) {
						continue;
					}
					$seen          = isset( $posted['versions'][ $aid ] ) ? (int) $posted['versions'][ $aid ] : null;
					$acceptance_id = mtl_record_agreement_acceptance( (int) $member->member_id, $aid, 'agree_page', $seen );
					if ( $acceptance_id > 0 ) {
						++$written;
						$written_ids[] = $acceptance_id;
					}
				}

				// Covers only the agreements just accepted, not the whole set
				// again. Sent after the rows are committed, and its result
				// changes nothing here.
				if ( $written_ids ) {
					mtl_send_agreement_confirmation_email( (int) $member->member_id, $written_ids );
				}

				if ( $written > 0 && count( $agreement_ticked ) === $written ) {
					// Confirm on screen: the form vanishing reads as a failed
					// reload, and the email may arrive late or not at all.
					$agreement_recorded = true;
				} elseif ( $written > 0 ) {
					$agreement_recorded = true;
					$agreement_errors[] = 'Some of your agreements could not be recorded. Anything still outstanding is shown below. Please try again.';
				} else {
					$agreement_errors[] = 'Sorry, your agreement could not be recorded. Please try again.';
				}
				$agreement_ticked = array();
			}
		}
	}

	// --- Handle profile update (POST + nonce), then PRG-redirect. ---
	if ( mtl_is_post_request() && isset( $_POST['mtl_update_account'] ) ) {
		if ( ! isset( $_POST['mtl_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_account_nonce'] ) ), 'mtl_account_action' ) ) {
			$errors[] = 'Your session expired. Please try again.';
		} else {
			$first          = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$last           = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
			$phone_country  = mtl_valid_phone_country( sanitize_text_field( wp_unslash( $_POST['phone_country'] ?? '' ) ) );
			$phone_national = sanitize_text_field( wp_unslash( $_POST['phone_national'] ?? '' ) );
			$phone_result   = mtl_format_phone_number( $phone_country, $phone_national );
			$address1       = sanitize_text_field( wp_unslash( $_POST['address_line1'] ?? '' ) );
			$address2       = sanitize_text_field( wp_unslash( $_POST['address_line2'] ?? '' ) );
			$city           = sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) );
			$state          = mtl_valid_state( sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ) );
			$zip_code       = sanitize_text_field( wp_unslash( $_POST['zip_code'] ?? '' ) );
			$country        = mtl_valid_country( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
			if ( '' === $country ) {
				$country = 'United States';
			}

			if ( '' === $first || '' === $last ) {
				$errors[] = 'Please keep your first and last name filled in.';
			}
			if ( '' !== $phone_result['error'] ) {
				$errors[] = $phone_result['error'];
			}
			if ( '' === $address1 || '' === $city || '' === $state || '' === $zip_code ) {
				$errors[] = 'Please keep a complete address on file (street, city, state, and ZIP code).';
			}

			if ( empty( $errors ) ) {
				// Verification is tied to the personal information on file, so
				// a change to the name, phone number or any of the 6 address
				// fields invalidates a prior verification. Stored values are
				// compared as the form displays them (stripslashes), so saving
				// the form untouched never counts as a change.
				$stored_text = static function ( $value ) {
					return trim( stripslashes( (string) $value ) );
				};

				// The stored phone is re-run through the formatter before
				// comparing, so a legacy value saved before the "+code" format
				// existed is not mistaken for a change when the member saves
				// the same number again.
				$stored_phone      = mtl_parse_stored_phone_number( $member->phone_number );
				$stored_phone_norm = mtl_format_phone_number( $stored_phone['iso'], $stored_phone['national'] )['value'];
				if ( '' === $stored_phone_norm ) {
					$stored_phone_norm = trim( (string) $member->phone_number );
				}

				$details_changed = (
					trim( $first ) !== $stored_text( $member->first_name )
					|| trim( $last ) !== $stored_text( $member->last_name )
					|| $phone_result['value'] !== $stored_phone_norm
					|| trim( $address1 ) !== $stored_text( $member->address_line1 )
					|| trim( $address2 ) !== $stored_text( $member->address_line2 )
					|| trim( $city ) !== $stored_text( $member->city )
					|| trim( $state ) !== $stored_text( $member->state )
					|| trim( $zip_code ) !== $stored_text( $member->zip_code )
					|| trim( $country ) !== $stored_text( $member->country )
				);
				$was_verified    = mtl_member_is_verified( $member->member_id );

				// A verified member confirms before a change that resets their
				// verification. Asked by the server, not a browser dialog, so the
				// page needs no script: the first Save re-renders the form with
				// their edits and a warning, and only "Yes, save my changes"
				// (value "confirm") goes on to write.
				$confirmed = 'confirm' === sanitize_key( wp_unslash( $_POST['mtl_update_account'] ) );
				if ( $details_changed && $was_verified && ! $confirmed ) {
					$pending_details = array(
						'first_name'     => $first,
						'last_name'      => $last,
						'phone_iso'      => $phone_country,
						'phone_national' => $phone_national,
						'address_line1'  => $address1,
						'address_line2'  => $address2,
						'city'           => $city,
						'state'          => $state,
						'zip_code'       => $zip_code,
						'country'        => $country,
					);
				}
			}

			if ( empty( $errors ) && null === $pending_details ) {
				$updated = $wpdb->update(
					$tbl_members,
					array(
						'first_name'    => $first,
						'last_name'     => $last,
						'phone_number'  => $phone_result['value'],
						'address_line1' => $address1,
						'address_line2' => '' !== $address2 ? $address2 : null,
						'city'          => $city,
						'state'         => $state,
						'zip_code'      => $zip_code,
						'country'       => $country,
					),
					array( 'member_id' => (int) $member->member_id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);

				// Verification is only reset after the new details are saved.
				if ( false === $updated ) {
					$errors[] = 'Sorry, your details could not be saved. Please try again.';
				} else {
					// Keep the WordPress profile name in step with the member row.
					wp_update_user(
						array(
							'ID'           => get_current_user_id(),
							'first_name'   => $first,
							'last_name'    => $last,
							'display_name' => trim( $first . ' ' . $last ),
						)
					);

					// New details void any scan on file, even a lone one, since it
					// shows the old details. The files live outside the database,
					// so the admin is emailed the links to delete them.
					$msg = 'account_updated';
					if ( $details_changed ) {
						$doc_urls = mtl_member_verification_doc_urls( (int) $member->member_id );
						if ( $doc_urls ) {
							$wpdb->delete( $tbl_verif, array( 'member_id' => (int) $member->member_id ), array( '%d' ) );
							mtl_send_verification_reset_email( (int) $member->member_id, trim( $first . ' ' . $last ), (string) $member->email, $doc_urls );
							$msg = $was_verified ? 'account_verif_removed' : 'account_docs_removed';
						}
					}

					wp_safe_redirect( add_query_arg( 'mtl_msg', $msg, mtl_front_page_url( 'account' ) ) );
					exit;
				}
			}
		}
	}

	// --- Handle account deletion (POST + nonce). ---
	// The GET link to ?mtl_confirm_delete=1 only shows the confirmation; this
	// runs on the POST from that confirmation form.
	if ( mtl_is_post_request() && isset( $_POST['mtl_delete_account'] ) ) {
		if ( ! isset( $_POST['mtl_delete_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_account_nonce'] ) ), 'mtl_delete_account_action' ) ) {
			$errors[] = 'Your session expired. Please try again.';
		} else {
			// 'member' tells the admin cleanup email the member deleted their
			// own account.
			$deleted = mtl_delete_or_anonymize_member( (int) $member->member_id, 'member' );
			if ( 'anonymized' === $deleted['outcome'] && ! $deleted['cleanup_email_sent'] ) {
				// The admin's copy of the record and the scan links never went
				// out, so leave a trace the site owner can follow up on.
				error_log( sprintf( 'My Tool Library: member #%d deleted their account, but the cleanup email to the site administrator could not be sent.', (int) $member->member_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational record of a failed admin email, not debug output.
			}
			// wp_delete_user() doesn't end this request's session, so log out
			// before redirecting.
			wp_logout();
			wp_safe_redirect( add_query_arg( 'mtl_msg', 'account_deleted', mtl_front_page_url( 'main' ) ) );
			exit;
		}
	}

	$is_verified    = mtl_member_is_verified( $member->member_id );
	$user           = wp_get_current_user();
	$confirm_delete = isset( $_GET['mtl_confirm_delete'] ) && '1' === $_GET['mtl_confirm_delete'];
	// Your details is prefilled from the stored row, not the POST, so a failed
	// save reverts it. The exception is a change waiting on the verification
	// warning: the form keeps those edits, so "Yes, save my changes" sends them.
	$phone_parsed = mtl_parse_stored_phone_number( $member->phone_number );
	$details_form = $pending_details ? $pending_details : array(
		'first_name'     => stripslashes( $member->first_name ),
		'last_name'      => stripslashes( $member->last_name ),
		'phone_iso'      => $phone_parsed['iso'],
		'phone_national' => $phone_parsed['national'],
		'address_line1'  => stripslashes( $member->address_line1 ),
		'address_line2'  => stripslashes( (string) $member->address_line2 ),
		'city'           => stripslashes( $member->city ),
		'state'          => $member->state,
		'zip_code'       => $member->zip_code,
		'country'        => $member->country,
	);
	// Same blank and fallback rules as $pickup_directions on My Reservations.
	$verification_directions = trim(
		(string) get_option(
			'mtl_verification_directions',
			'A government-issued ID and proof of address are required to become a verified member and to check out tools. Stop by our office to verify membership.'
		)
	);
	// Optional Setup-page image that replaces the green Verified check.
	$verified_badge_image_url = trim( (string) get_option( 'mtl_verified_badge_image_url', '' ) );

	// Past + current loans for this member.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$loans = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.loan_id, l.tool_id, l.loan_date, l.due_date, l.return_date, t.tool_name
         FROM {$tbl_loans} l
         JOIN {$tbl_inv} t ON t.tool_id = l.tool_id
         WHERE l.member_id = %d
         ORDER BY l.loan_date DESC",
			(int) $member->member_id
		)
	);
	// Read-only: only staff record trainings (admin Membership page).
	$my_training_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.training_name, t.badge_image_url, t.certification_length_months, mtm.start_date
         FROM {$tbl_training_map} mtm
         JOIN {$tbl_trainings} t ON t.training_id = mtm.training_id
         WHERE mtm.member_id = %d
         ORDER BY t.training_name ASC",
			(int) $member->member_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Badges show current trainings only; the Trainings table lists them all.
	$my_trainings         = array();
	$my_current_trainings = array();
	foreach ( $my_training_rows as $mtl_tr ) {
		$mtl_entry      = array(
			'name'        => $mtl_tr->training_name,
			'badge'       => (string) $mtl_tr->badge_image_url,
			'start_date'  => (string) $mtl_tr->start_date,
			'months'      => (int) $mtl_tr->certification_length_months,
			'expiry_date' => mtl_training_expiry_date( $mtl_tr->start_date, $mtl_tr->certification_length_months ),
			'is_current'  => mtl_training_is_current( $mtl_tr->start_date, $mtl_tr->certification_length_months ),
		);
		$my_trainings[] = $mtl_entry;
		if ( $mtl_entry['is_current'] ) {
			$my_current_trainings[] = $mtl_entry;
		}
	}

	// For the delete confirmation only: whether to mention the kept borrowing
	// record, and whether active reservations will be cancelled.
	$has_history            = false;
	$has_active_reservation = false;
	if ( $confirm_delete ) {
		$tbl_res    = $wpdb->prefix . 'tool_reservations';
		$res_counts = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"SELECT COUNT(*) AS total, SUM(expiry_date IS NULL) AS active FROM {$tbl_res} WHERE member_id = %d",
				(int) $member->member_id
			)
		);

		// SUM() over no rows is NULL, and get_row() itself returns null if the
		// query fails, so neither is dereferenced without checking first.
		$has_history            = ! empty( $loans ) || ( $res_counts && (int) $res_counts->total > 0 );
		$has_active_reservation = ( $res_counts && (int) $res_counts->active > 0 );
	}

	// When staff verified them, for the Membership panel. Empty on rows saved
	// before the column was filled in, and the sentence then leaves it out.
	$verified_at = '';
	if ( $is_verified ) {
		$verified_at = (string) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"SELECT verified_at FROM {$tbl_verif} WHERE member_id = %d",
				(int) $member->member_id
			)
		);
	}

	// Tools out now are on My loans & reservations, so the history lists only
	// what has come back.
	$past_loans = array();
	foreach ( $loans as $mtl_loan ) {
		if ( ! empty( $mtl_loan->return_date ) ) {
			$past_loans[] = $mtl_loan;
		}
	}

	$full_name = trim( stripslashes( (string) $member->first_name ) . ' ' . stripslashes( (string) $member->last_name ) );
	$notices   = mtl_account_locked_banner_html() . mtl_front_notice_html() . mtl_agreements_banner_html();

	ob_start();
	echo mtl_member_page_styles();
	?>
	<div class="mtl-member mtl-acct-page">
		<h1 class="mtl-page-title"><?php echo esc_html( $full_name ); ?></h1>
		<?php if ( ! $confirm_delete ) : ?>
			<p class="mtl-member-lede">
				<?php if ( ! empty( $member->signup_date ) && 0 !== strpos( (string) $member->signup_date, '0000' ) ) : ?>
					Member since <?php echo mtl_format_date( $member->signup_date, 'F Y' ); ?>.
				<?php endif; ?>
				You sign in with <?php echo esc_html( $user->user_email ); ?>.
			</p>
		<?php endif; ?>

		<?php if ( '' !== $notices || ! empty( $errors ) ) : ?>
			<div class="mtl-member-notices">
				<?php echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each helper escapes its own output. ?>
				<?php if ( ! empty( $errors ) ) : ?>
					<div class="mtl-front-notice mtl-front-notice-error" role="alert">
						<?php foreach ( $errors as $e ) : ?>
							<p><?php echo esc_html( $e ); ?></p>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $confirm_delete ) : ?>
			<section class="mtl-panel mtl-confirm" aria-labelledby="mtl-confirm-title">
				<h2 id="mtl-confirm-title">Delete your account?</h2>
				<p>This permanently deletes your account and cannot be undone.</p>
				<p>Your name, address, contact details and verification documents will be removed, and your sign-in will be deleted, so you won&rsquo;t be able to sign in again.</p>
				<?php if ( $has_history ) : ?>
					<p>Your past loans and reservations stay on file under &ldquo;former member&rdquo;, with nothing that identifies you, so the library&rsquo;s tool records stay accurate.</p>
				<?php endif; ?>
				<?php if ( $has_active_reservation ) : ?>
					<p><?php echo 1 === (int) $res_counts->active ? 'You currently have an active reservation. It will be cancelled' : 'You currently have active reservations. They will be cancelled'; ?> as part of deleting your account.</p>
				<?php endif; ?>
				<div class="mtl-confirm-actions">
					<form method="post" action="<?php echo esc_url( mtl_front_page_url( 'account' ) ); ?>">
						<?php wp_nonce_field( 'mtl_delete_account_action', 'mtl_delete_account_nonce' ); ?>
						<button type="submit" name="mtl_delete_account" value="1" class="mtl-btn mtl-member-danger-btn">Yes, delete my account</button>
					</form>
					<a class="mtl-btn" href="<?php echo esc_url( mtl_front_page_url( 'account' ) ); ?>">No, keep it</a>
				</div>
			</section>
		<?php else : ?>

			<?php
			// --- Member agreements ---
			// Split between the outstanding form here and the receipt in the
			// folding list below.
			$mtl_ag_outstanding     = mtl_agreements_online() ? mtl_member_outstanding_agreements( (int) $member->member_id ) : array();
			$mtl_ag_acceptances     = mtl_agreements_tracking() ? mtl_get_member_acceptances( (int) $member->member_id ) : array();
			$mtl_ag_outstanding_ids = array_map( 'intval', wp_list_pluck( $mtl_ag_outstanding, 'agreement_id' ) );

			// An outstanding agreement's earlier acceptance becomes its "you
			// agreed to an earlier version" line, not a receipt tick, so the
			// same agreement never shows twice.
			$mtl_ag_superseded  = array();
			$mtl_ag_receipt     = array();
			$mtl_ag_retired_rec = array();
			$mtl_ag_latest      = '';
			foreach ( $mtl_ag_acceptances as $mtl_acceptance ) {
				$mtl_aid = (int) $mtl_acceptance->agreement_id;
				if ( in_array( $mtl_aid, $mtl_ag_outstanding_ids, true ) ) {
					$mtl_ag_superseded[ $mtl_aid ] = sprintf(
						/* translators: %s: date the member agreed to the earlier version. */
						__( 'You agreed to an earlier version of this on %s.', 'my-tool-library' ),
						wp_strip_all_tags( mtl_format_utc_datetime( $mtl_acceptance->accepted_at, 'M j, Y' ) )
					);
					continue;
				}

				// The receipt row's summary shows the most recent date.
				if ( (string) $mtl_acceptance->accepted_at > $mtl_ag_latest ) {
					$mtl_ag_latest = (string) $mtl_acceptance->accepted_at;
				}

				// Retired ones stay in the receipt, sorted after the active ones
				// and labelled, so their absence elsewhere on the site is
				// explained.
				$mtl_live = mtl_get_agreement( $mtl_aid );
				if ( $mtl_live && null !== $mtl_live->retired_at ) {
					$mtl_ag_retired_rec[] = $mtl_acceptance;
				} else {
					$mtl_ag_receipt[] = $mtl_acceptance;
				}
			}
			$mtl_ag_retired_ids = array_map( 'intval', wp_list_pluck( $mtl_ag_retired_rec, 'agreement_id' ) );
			$mtl_ag_receipt     = array_merge( $mtl_ag_receipt, $mtl_ag_retired_rec );
			?>

			<?php if ( $mtl_ag_outstanding ) : ?>
				<?php
				// Above everything else: members arrive from the reserve gate or
				// an email to do just this.
				?>
				<section class="mtl-panel mtl-acct-agree" id="mtl-agreements" aria-labelledby="mtl-agreements-title">
					<h2 id="mtl-agreements-title">Member agreements</h2>

					<?php if ( ! empty( $agreement_errors ) ) : ?>
						<div class="mtl-front-notice mtl-front-notice-error">
							<?php foreach ( $agreement_errors as $mtl_ag_err ) : ?>
								<p><?php echo wp_kses_post( $mtl_ag_err ); ?></p>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php echo mtl_render_agreements_error_summary( $agreement_missing, 'mtl-ac-agreement' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the renderer. ?>

					<?php
					// No #mtl-agreements on the action: browsers ignore autofocus
					// when the URL names a fragment, and the error summary above
					// relies on it. The panel is near the top of the page anyway.
					?>
					<form method="post" action="<?php echo esc_url( mtl_front_page_url( 'account' ) ); ?>">
						<?php wp_nonce_field( 'mtl_agreements_agree_action', 'mtl_agreements_agree_nonce' ); ?>
						<?php
						echo mtl_render_agreements_fieldset( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the renderer.
							$mtl_ag_outstanding,
							array(
								'context'    => 'agree_page',
								'id_prefix'  => 'mtl-ac-agreement',
								'legend'     => 'Please agree to the following',
								'intro'      => $agreement_changed
									? 'These have changed since you opened this page. Please read them again.'
									: 'Before you can reserve a tool, please read these and tick each box.',
								'checked'    => $agreement_ticked,
								'invalid'    => $agreement_invalid,
								'superseded' => $mtl_ag_superseded,
							)
						);
						?>
						<div class="mtl-form-actions">
							<button type="submit" name="mtl_agree" value="1" class="mtl-btn mtl-btn-primary">Agree</button>
						</div>
					</form>
				</section>
			<?php elseif ( $agreement_recorded ) : ?>
				<?php // role="status" so screen readers announce the change. ?>
				<div class="mtl-member-notices">
					<div class="mtl-front-notice mtl-front-notice-success" role="status">
						Thank you. Your agreement has been recorded.
					</div>
				</div>
			<?php endif; ?>

			<div class="mtl-panel mtl-acct-who">
				<section aria-labelledby="mtl-acct-membership">
					<h2 id="mtl-acct-membership">Membership</h2>
					<?php if ( $is_verified ) : ?>
						<div class="mtl-verified">
							<?php if ( '' !== $verified_badge_image_url ) : ?>
								<img class="mtl-verified-badge-img" src="<?php echo esc_url( $verified_badge_image_url ); ?>" alt="">
							<?php else : ?>
								<span class="mtl-verified-check" aria-hidden="true">&#10003;</span>
							<?php endif; ?>
							<div>
								<strong>Verified</strong>
								<?php if ( '' !== $verified_at ) : ?>
									<p>Staff checked your photo ID and proof of address on <?php echo mtl_format_date( $verified_at, 'M j, Y' ); ?>.</p>
								<?php else : ?>
									<p>Staff have checked your photo ID and proof of address.</p>
								<?php endif; ?>
							</div>
						</div>
					<?php else : ?>
						<div class="mtl-verified">
							<div>
								<strong>Not yet verified</strong>
								<?php if ( '' !== $verification_directions ) : ?>
									<p><?php echo nl2br( esc_html( $verification_directions ) ); ?></p>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>
				</section>
				<section aria-labelledby="mtl-acct-trainings">
					<h2 id="mtl-acct-trainings">Trainings</h2>
					<?php if ( ! empty( $my_current_trainings ) ) : ?>
						<ul class="mtl-acct-chips" role="list">
							<?php foreach ( $my_current_trainings as $mtl_training ) : ?>
								<li class="mtl-chip">
									<?php if ( '' !== $mtl_training['badge'] ) : ?>
										<img class="mtl-chip-badge" src="<?php echo esc_url( $mtl_training['badge'] ); ?>" alt="">
									<?php else : ?>
										<span class="mtl-chip-check" aria-hidden="true">&#10003;</span>
									<?php endif; ?>
									<?php echo esc_html( $mtl_training['name'] ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="mtl-acct-none">None yet</p>
					<?php endif; ?>
					<p class="mtl-acct-fine">Staff record trainings at the desk. Some tools need one before you borrow them.</p>
				</section>
			</div>

			<div class="mtl-panel mtl-acct-panels">
				<?php
				// Closed by default, but forced open whenever the page has an error
				// to show or an edit is waiting on the verification warning, so the
				// form the message is about is in view.
				?>
				<details <?php echo ( ! empty( $errors ) || $pending_details ) ? 'open' : ''; ?>>
					<summary><span class="mtl-acct-sum-title">Your details</span><span class="mtl-acct-sum-note">Name, address, phone</span></summary>
					<div class="mtl-acct-body">
						<form method="post" action="<?php echo esc_url( mtl_front_page_url( 'account' ) ); ?>" id="mtl-account-form">
							<?php wp_nonce_field( 'mtl_account_action', 'mtl_account_nonce' ); ?>

							<div class="mtl-field-row">
								<div class="mtl-field">
									<label for="mtl-ac-first">First name</label>
									<input type="text" id="mtl-ac-first" name="first_name" value="<?php echo esc_attr( $details_form['first_name'] ); ?>" autocomplete="given-name" required>
								</div>
								<div class="mtl-field">
									<label for="mtl-ac-last">Last name</label>
									<input type="text" id="mtl-ac-last" name="last_name" value="<?php echo esc_attr( $details_form['last_name'] ); ?>" autocomplete="family-name" required>
								</div>
							</div>

							<div class="mtl-field">
								<label for="mtl-ac-address1">Street address</label>
								<input type="text" id="mtl-ac-address1" name="address_line1" value="<?php echo esc_attr( $details_form['address_line1'] ); ?>" autocomplete="address-line1" required>
							</div>

							<div class="mtl-field">
								<label for="mtl-ac-address2">Address line 2 <small>(optional)</small></label>
								<input type="text" id="mtl-ac-address2" name="address_line2" value="<?php echo esc_attr( $details_form['address_line2'] ); ?>" autocomplete="address-line2">
							</div>

							<div class="mtl-field-row">
								<div class="mtl-field">
									<label for="mtl-ac-city">City</label>
									<input type="text" id="mtl-ac-city" name="city" value="<?php echo esc_attr( $details_form['city'] ); ?>" autocomplete="address-level2" required>
								</div>
								<div class="mtl-field">
									<label for="mtl-ac-state">State or province</label>
									<select id="mtl-ac-state" name="state" required>
										<?php foreach ( mtl_get_state_options() as $mtl_state_code => $mtl_state_label ) : ?>
											<option value="<?php echo esc_attr( $mtl_state_code ); ?>" <?php selected( $details_form['state'], $mtl_state_code ); ?>><?php echo esc_html( $mtl_state_label ); ?> (<?php echo esc_html( $mtl_state_code ); ?>)</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="mtl-field-row">
								<div class="mtl-field">
									<label for="mtl-ac-zip">ZIP or postal code</label>
									<input type="text" id="mtl-ac-zip" name="zip_code" value="<?php echo esc_attr( $details_form['zip_code'] ); ?>" autocomplete="postal-code" required>
								</div>
								<div class="mtl-field">
									<label for="mtl-ac-country">Country</label>
									<select id="mtl-ac-country" name="country" required>
										<?php foreach ( mtl_get_country_options() as $mtl_country_name ) : ?>
											<option value="<?php echo esc_attr( $mtl_country_name ); ?>" <?php selected( $details_form['country'], $mtl_country_name ); ?>><?php echo esc_html( $mtl_country_name ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="mtl-field">
								<label for="mtl-ac-phone_national">Phone number</label>
								<?php mtl_render_phone_input( $details_form['phone_iso'], $details_form['phone_national'], 'mtl-ac-' ); ?>
							</div>

							<p class="mtl-form-note">To change the email address you sign in with, ask library staff.</p>

							<?php if ( $pending_details ) : ?>
								<?php // autofocus scrolls the member straight to it; the form posts to a URL with no fragment, which autofocus needs. ?>
								<div class="mtl-front-notice mtl-front-notice-error mtl-member-verif-confirm" role="alert" tabindex="-1" autofocus>
									<p><strong>You&rsquo;ll lose your verified status</strong></p>
									<p>Saving these changes to your personal information will reset your verified status. Staff will need to verify your account again before you can check out tools.</p>
									<div class="mtl-form-actions">
										<button type="submit" name="mtl_update_account" value="confirm" class="mtl-btn mtl-btn-primary">Yes, save my changes</button>
										<a class="mtl-btn" href="<?php echo esc_url( mtl_front_page_url( 'account' ) ); ?>">No, keep my current details</a>
									</div>
								</div>
							<?php else : ?>
								<?php if ( $is_verified ) : ?>
									<p class="mtl-form-note">Changing your name, address or phone number means staff will need to verify you again.</p>
								<?php endif; ?>
								<div class="mtl-form-actions">
									<button type="submit" name="mtl_update_account" value="1" class="mtl-btn mtl-btn-primary">Save changes</button>
								</div>
							<?php endif; ?>
						</form>
					</div>
				</details>

				<details>
					<summary>
						<span class="mtl-acct-sum-title">Loan history</span>
						<span class="mtl-acct-sum-note"><?php echo $past_loans ? esc_html( count( $past_loans ) . ( 1 === count( $past_loans ) ? ' past loan' : ' past loans' ) ) : 'No past loans'; ?></span>
					</summary>
					<div class="mtl-acct-body">
						<?php if ( empty( $past_loans ) ) : ?>
							<p class="mtl-form-note">Tools you return will be listed here.</p>
						<?php else : ?>
							<ul class="mtl-history">
								<?php
								foreach ( $past_loans as $l ) :
									// Compare on the DATE portion only: return_date is a
									// full timestamp, due_date stays a plain date, so a
									// raw > comparison would wrongly call anything
									// returned after 00:00 on the due date "late".
									$mtl_late = gmdate( 'Y-m-d', strtotime( $l->return_date ) ) > $l->due_date;
									$mtl_meta = mtl_member_tool_meta( $l->tool_id, '' );
									?>
									<li>
										<span class="mtl-history-name"><?php echo esc_html( stripslashes( $l->tool_name ) ); ?></span>
										<p class="mtl-history-meta">
											<?php echo '' !== $mtl_meta ? esc_html( $mtl_meta . '. ' ) : ''; ?>
											Borrowed <?php echo mtl_format_date( $l->loan_date, 'M j, Y' ); ?>, returned <?php echo mtl_format_date( $l->return_date, 'M j, Y' ); ?>.
										</p>
										<span class="mtl-history-status mtl-status-note"><?php echo $mtl_late ? 'Returned late' : 'On time'; ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</details>

				<?php if ( ! empty( $my_trainings ) ) : ?>
					<?php
					$mtl_expired_count = count( $my_trainings ) - count( $my_current_trainings );
					$mtl_training_note = array();
					if ( $my_current_trainings ) {
						$mtl_training_note[] = count( $my_current_trainings ) . ' current';
					}
					if ( $mtl_expired_count > 0 ) {
						$mtl_training_note[] = $mtl_expired_count . ' expired';
					}
					?>
					<details>
						<summary>
							<span class="mtl-acct-sum-title">Training record</span>
							<span class="mtl-acct-sum-note"><?php echo esc_html( implode( ', ', $mtl_training_note ) ); ?></span>
						</summary>
						<div class="mtl-acct-body">
							<p class="mtl-form-note">Every training you&rsquo;ve completed, including any that have since expired. Ask library staff if you&rsquo;d like to retake one.</p>
							<ul class="mtl-history">
								<?php foreach ( $my_trainings as $mtl_training ) : ?>
									<li>
										<span class="mtl-history-name"><?php echo esc_html( $mtl_training['name'] ); ?></span>
										<p class="mtl-history-meta">
											Completed <?php echo mtl_format_date( $mtl_training['start_date'], 'M j, Y' ); ?>.
											<?php if ( '' === $mtl_training['expiry_date'] ) : ?>
												Never expires.
											<?php elseif ( $mtl_training['is_current'] ) : ?>
												Good until <?php echo mtl_format_date( $mtl_training['expiry_date'], 'M j, Y' ); ?>.
											<?php else : ?>
												Expired <?php echo mtl_format_date( $mtl_training['expiry_date'], 'M j, Y' ); ?>.
											<?php endif; ?>
										</p>
										<?php if ( $mtl_training['is_current'] ) : ?>
											<span class="mtl-history-status mtl-status mtl-status-ok">Current</span>
										<?php else : ?>
											<span class="mtl-history-status mtl-status mtl-status-retired">Expired</span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</details>
				<?php endif; ?>

				<?php if ( $mtl_ag_receipt ) : ?>
					<details>
						<summary>
							<span class="mtl-acct-sum-title">Member agreements</span>
							<span class="mtl-acct-sum-note">Agreed <?php echo wp_kses_post( mtl_format_utc_datetime( $mtl_ag_latest, 'M j, Y' ) ); ?></span>
						</summary>
						<div class="mtl-acct-body">
							<p class="mtl-form-note">You have agreed to the following.</p>
							<ul class="mtl-agreement-receipt">
								<?php foreach ( $mtl_ag_receipt as $mtl_acceptance ) : ?>
									<?php
									// Every value shown here comes from the acceptance
									// row. The live agreement may have been revised
									// since, and would show wording the member never saw.
									$mtl_is_retired = in_array( (int) $mtl_acceptance->agreement_id, $mtl_ag_retired_ids, true );

									// Drop the link where the attachment has since been
									// deleted, so the member gets the text rather than
									// a 404. One lookup per attached document, on one
									// member's own page.
									$mtl_file_live = ! empty( $mtl_acceptance->file_url )
										&& attachment_url_to_postid( $mtl_acceptance->file_url ) > 0;
									?>
									<li>
										<span class="mtl-chip-check" aria-hidden="true">&#10003;</span>
										<div>
											<div><?php echo nl2br( esc_html( $mtl_acceptance->agreement_text ) ); ?></div>
											<p class="mtl-agreement-meta">
												Agreed <?php echo wp_kses_post( mtl_format_utc_datetime( $mtl_acceptance->accepted_at, 'M j, Y' ) ); ?>.
												<?php if ( $mtl_is_retired ) : ?>
													<?php esc_html_e( 'No longer required.', 'my-tool-library' ); ?>
												<?php endif; ?>
												<?php if ( $mtl_file_live ) : ?>
													<a href="<?php echo esc_url( $mtl_acceptance->file_url ); ?>" target="_blank" rel="noopener noreferrer"
														aria-label="<?php echo esc_attr( 'View the document you agreed to for &ldquo;' . wp_strip_all_tags( mtl_agreement_excerpt( $mtl_acceptance->agreement_text ) ) . '&rdquo; (opens in a new tab)' ); ?>"><?php esc_html_e( 'View the attached document', 'my-tool-library' ); ?></a>
												<?php endif; ?>
											</p>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</details>
				<?php endif; ?>
			</div>

			<?php
			echo mtl_giving_section_html();

			echo mtl_tool_request_section_html();
			?>

			<div class="mtl-acct-danger">
				<a class="mtl-btn-danger" href="<?php echo esc_url( add_query_arg( 'mtl_confirm_delete', '1', mtl_front_page_url( 'account' ) ) ); ?>">Delete my account</a>
				<p>Removes your personal details. Past loans stay in the library&rsquo;s records without your name.</p>
			</div>

		<?php endif; ?>
	</div>
	<?php
	$body = ob_get_clean();

	mtl_render_front_shell( 'My account', $body );
}

/**
 * Shown when someone logged in but not a member (e.g. an admin) opens a
 * member-only page.
 *
 * A member whose record could not be matched gets different wording (see
 * mtl_current_member()).
 *
 * @param string $page_title  Page title to display.
 * @param bool   $link_broken True when the visitor is a member whose record
 *                            could not be matched, rather than a non-member.
 * @return void Outputs the page directly (via mtl_render_front_shell()) and exits.
 */
function mtl_render_member_only_notice( $page_title, $link_broken = false ) {
	$contact = mtl_contact_email();

	ob_start();
	echo mtl_member_page_styles();
	?>
	<div class="mtl-front-card">
		<h1><?php echo esc_html( $page_title ); ?></h1>
		<?php if ( $link_broken ) : ?>
			<p>You&rsquo;re signed in, but we couldn&rsquo;t match your sign-in to a membership record, so there&rsquo;s nothing to show here yet.</p>
			<p>This usually means the library&rsquo;s records were rebuilt recently. Please contact library staff, who can reconnect your account. Nothing about your membership has been lost.</p>
		<?php else : ?>
			<p>This area is for tool-library member accounts. You&rsquo;re signed in, but your account isn&rsquo;t a member account, so there&rsquo;s nothing to show here.</p>
		<?php endif; ?>
		<div class="mtl-confirm-actions">
			<a class="mtl-btn mtl-btn-primary" href="<?php echo esc_url( mtl_front_page_url( 'main' ) ); ?>">Browse tools</a>
			<?php if ( $link_broken && '' !== $contact ) : ?>
				<a class="mtl-btn" href="<?php echo esc_url( 'mailto:' . $contact ); ?>">Email library staff</a>
			<?php endif; ?>
			<a class="mtl-btn" href="<?php echo esc_url( wp_logout_url( mtl_front_page_url( 'main' ) ) ); ?>">Log out</a>
		</div>
	</div>
	<?php
	$body = ob_get_clean();

	mtl_render_front_shell( $page_title, $body );
}
