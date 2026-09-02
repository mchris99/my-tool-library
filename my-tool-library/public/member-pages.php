<?php
/**
 * Public member accounts (server-side rendered, no JavaScript required).
 *
 * Public-facing member signup, sign-in, and account management, all plain GET
 * pages and POST forms, matching the public shop page (public/shop-page.php).
 *
 *   mtl_page=signup        : create a member account
 *   mtl_page=reservations  : "My Loans & Reservations": active loans with a
 *                              due-soon/overdue status, reservation queue,
 *                              place in line, cancel
 *   mtl_page=account       : profile, verification status, past loans, edits
 *
 * Sign-in itself is core WordPress (wp_login_form on the shared login page in
 * my-tool-library.php); this plugin never handles a password directly.
 *
 * The link between a WordPress user and their row in the {prefix}members
 * table is the mtl_member_id stored in that user's meta at signup. That id is
 * a cache, not proof: member_id is AUTO_INCREMENT and restarts at 1 whenever
 * the Setup page rebuilds the tables, while WordPress accounts survive
 * untouched, so a stored id can end up pointing at a completely different
 * person. The account's own email address is what actually identifies the
 * member; mtl_current_member() checks the two agree before trusting the id,
 * and repairs the id when it can. No password or other credential is ever
 * stored in the plugin's own tables.
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
// Low-privilege role so members are recognizable/distinct from administrators
// in the WP Users list. Registered on init (guarded so it's only added once)
// rather than only on activation, so it also appears on installs that were
// already active before this feature shipped.
add_action( 'init', 'mtl_register_member_role' );

/**
 * Registers the low-privilege "mtl_member" role, if not already present.
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
 * The pages below render on GET and handle their own POST, and the reserve
 * handler runs on POST alone, so each opens by asking this. Wrapping the
 * isset/unslash/sanitize dance $_SERVER['REQUEST_METHOD'] needs keeps that to
 * one line per call site.
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
 * The members.email column is UNIQUE, so this matches at most one row, and
 * anonymized_at IS NULL is part of the match rather than an afterthought: an
 * anonymized row is a deleted person whose personal fields are placeholders
 * and whose address has been replaced with a reserved .invalid one, so it is
 * never a record to serve and can never collide with a real address. Both
 * callers (resolving a sign-in to its member row, and telling a would-be
 * signup that the library already holds a membership for them) depend on
 * that same rule, so they share this one query rather than each restating it.
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
 * The stored mtl_member_id is treated as a cache rather than proof. It is an
 * AUTO_INCREMENT value that restarts at 1 every time the Setup page rebuilds
 * the tables, while WordPress accounts survive that reset untouched, so a
 * surviving sign-in can be left pointing at a row that now belongs to someone
 * else entirely. Returning it would hand a stranger another member's name,
 * address, phone number and loan history, with edit and delete over the
 * record. So the row's email must match the signed-in account's before it is
 * trusted; if it doesn't, the account's own email is used to find the right
 * row and the stored id is repaired. If nothing matches, this returns null
 * and the caller shows the "we couldn't match your record" notice,
 * deliberately failing closed, since being locked out is recoverable and
 * disclosure isn't. See mtl_current_member_link_broken().
 *
 * Cached per-request since several places (shop nav, reserve handling) ask
 * for it on a single page load; safe because every write to the member row is
 * followed by a redirect, so the cache can never go stale mid-request, and
 * for the same reason, nothing may switch the current user after this has
 * run without also redirecting.
 *
 * Note for multisite: wp_usermeta is network-global but {prefix}members is
 * per-site, so a member of one site carries their id onto another site's
 * tables. The email check makes that fail closed instead of disclosing; a
 * per-site meta key would be the real fix, and is a migration.
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

	// anonymized_at IS NULL on both lookups: an anonymized row is a deleted
	// person whose personal fields are placeholders, never a record to serve.
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
 * Whether a member is fully verified. Verification is created only by
 * administrators (see the Membership admin page); this file never writes to
 * member_verifications. A row can exist with only one of the two scan URLs
 * on file (a member who has provided one form of ID but not the other yet),
 * so both must be present, not just the row, to count as verified. See
 * also mtl_verification_urls_complete() in my-tool-library.php.
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
 * Text + severity for a one-off status banner, keyed by the mtl_msg the
 * post-action PRG redirects carry. Shared by the shop page and the member
 * pages so wording stays consistent.
 *
 * @param string $key mtl_msg value.
 * @return array{0:string,1:string}|null array('type', 'text'), or null.
 */
function mtl_front_notice( $key ) {
	$map = array(
		'reserved'               => array( 'success', 'You&rsquo;ve joined the waiting queue for this tool. Track your place under My Loans &amp; Reservations.' ),
		'already_reserved'       => array( 'error', 'You already have an active reservation for that tool.' ),
		'on_loan_conflict'       => array( 'error', 'You currently have that tool checked out, so there&rsquo;s no need to reserve it.' ),
		'login_required'         => array( 'error', 'Please sign in to reserve a tool.' ),
		'reserve_failed'         => array( 'error', 'Sorry, that tool could not be reserved. Please try again.' ),
		'reservation_cancelled'  => array( 'success', 'Your reservation has been cancelled.' ),
		'reservations_cancelled' => array( 'success', 'All of your reservations have been cancelled.' ),
		'account_updated'        => array( 'success', 'Your account details have been updated.' ),
		'account_verif_removed'  => array( 'success', 'Your details were updated. Because your address changed, your verified status has been reset, and an administrator will need to re-verify your account.' ),
		'account_deleted'        => array( 'success', 'Your account and personal data have been deleted. You&rsquo;re welcome to browse the catalog, but you&rsquo;ll need to sign up again if you&rsquo;d like to reserve a tool.' ),
		// The reserve gate sends members here. The wording explains what
		// happened and what to do, because the alternative, a reservation
		// that silently did not happen, is the worst version of this.
		'agreements_required'    => array( 'error', 'Before you can reserve a tool, please read and agree to our member agreements below. Your reservation was not created.' ),
		'agreements_recorded'    => array( 'success', 'Thank you. Your agreement has been recorded.' ),
		// Sign-in failures, carried back from wp-login.php by
		// mtl_handle_failed_front_login(). Deliberately does not say WHICH of
		// the two was wrong: that would confirm to anyone guessing whether a
		// given email address has an account here.
		'login_failed'           => array( 'error', 'That email address and password don&rsquo;t match an account. Please check them and try again.' ),
		'login_empty'            => array( 'error', 'Please enter both your email address and your password.' ),
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
 * Renders the current mtl_msg (if any) as a status banner, or '' if none.
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
 * Top-right nav cluster for the shop page, adapting to login state: logged
 * out gets Sign In + Sign Up; signed-in member gets a native <details> account
 * menu (no JS needed); signed-in admin gets Admin Portal + Log Out. Rendered
 * inside the shop markup (public/shop-page.php), which supplies the CSS.
 */
function mtl_member_nav_html() {
	$out = '<div class="mtl-shop-account-nav">';

	if ( ! is_user_logged_in() ) {
		$out .= '<a class="mtl-shop-btn mtl-shop-btn-ghost" href="' . esc_url( mtl_front_page_url( 'login' ) ) . '">Sign In</a>';
		$out .= '<a class="mtl-shop-btn" href="' . esc_url( mtl_front_page_url( 'signup' ) ) . '">Sign Up</a>';
		return $out . '</div>';
	}

	if ( mtl_can_manage_library() ) {
		$out .= '<a class="mtl-shop-btn mtl-shop-btn-ghost" href="' . esc_url( admin_url( 'admin.php?page=mtl-dashboard' ) ) . '">Admin Portal</a>';
		$out .= '<a class="mtl-shop-btn mtl-shop-btn-ghost" href="' . esc_url( wp_logout_url( mtl_front_page_url( 'main' ) ) ) . '">Log Out</a>';
		return $out . '</div>';
	}

	$member = mtl_current_member();
	$label  = $member ? ( 'Hi, ' . $member->first_name ) : 'My Account';

	$out .= '<details class="mtl-shop-account-menu">';
	$out .= '<summary class="mtl-shop-btn mtl-shop-btn-ghost">' . esc_html( $label ) . ' &#9662;</summary>';
	$out .= '<div class="mtl-shop-account-menu-panel">';
	$out .= '<a href="' . esc_url( mtl_front_page_url( 'reservations' ) ) . '">My Loans &amp; Reservations</a>';
	$out .= '<a href="' . esc_url( mtl_front_page_url( 'account' ) ) . '">Account</a>';
	$out .= '<a href="' . esc_url( wp_logout_url( mtl_front_page_url( 'main' ) ) ) . '">Log Out</a>';
	$out .= '</div></details>';

	return $out . '</div>';
}

/**
 * Shared <style> for the standalone member pages (signup / reservations /
 * account). Overrides .mtl-front-content to top-align (the front-end shell
 * normally centers a single card) and adds table / form / badge styling.
 */
function mtl_member_page_styles() {
	$accent = get_option( 'mtl_header_color', '#ff6600' );
	ob_start();
	?>
	<style>
		.mtl-front-content {
			display: block;
			padding: 24px 16px 56px 16px;
		}

		.mtl-member-wrap {
			max-width: 760px;
			margin: 0 auto;
		}

		.mtl-member-card {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 8px;
			padding: 22px 24px;
			margin-bottom: 20px;
			text-align: left;
		}

		.mtl-member-card h2 {
			margin: 0 0 14px 0;
		}

		/* Admin-editable informational copy (Setup page), visually distinct
			from the main content cards so it reads as a note, not a form. */
		.mtl-member-directions {
			background: #f6f7f7;
			border-style: dashed;
		}

		.mtl-member-directions strong {
			font-size: 0.9em;
			text-transform: uppercase;
			letter-spacing: 0.03em;
			color: #646970;
		}

		.mtl-member-directions p {
			color: #3c434a;
		}

		.mtl-member-card h3 {
			margin: 22px 0 10px 0;
			font-size: 1.05em;
		}

		/* <details>-based cards (Trainings, Your details, Your loan history):
			collapsed by default, no JavaScript required to open them. */
		details.mtl-member-card {
			padding: 0;
		}

		.mtl-member-summary {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			cursor: pointer;
			padding: 22px 24px;
			font-weight: 600;
			font-size: 1.05em;
			outline: none;
			/* Suppress the browser's own disclosure triangle so the chevron
				below is the only marker; each engine needs its own opt-out. */
			list-style: none;
		}

		.mtl-member-summary::-webkit-details-marker {
			display: none;
		}

		/* Chevron telling members the row opens. Drawn from borders rather than
			a glyph or an image so it renders identically everywhere, needs no
			font support, and inherits the surrounding text color. Points down
			when closed, up when open. */
		.mtl-member-summary::after {
			content: "";
			flex: 0 0 auto;
			width: 9px;
			height: 9px;
			margin-right: 2px;
			border-right: 2px solid currentColor;
			border-bottom: 2px solid currentColor;
			transform: translateY(-2px) rotate(45deg);
			transition: transform 0.2s ease;
			opacity: 0.55;
		}

		details.mtl-member-card[open] .mtl-member-summary::after {
			transform: translateY(2px) rotate(-135deg);
		}

		.mtl-member-summary:hover::after {
			opacity: 1;
		}

		/* outline:none above removes the default focus ring, which would leave
			keyboard users with no idea where they are. Put a visible one back,
			only for keyboard focus so a mouse click does not leave a ring. */
		.mtl-member-summary:focus-visible {
			outline: 2px solid currentColor;
			outline-offset: -4px;
			border-radius: 4px;
		}

		/* Respect a reduced-motion preference: the chevron still flips, it just
			does not animate. */
		@media (prefers-reduced-motion: reduce) {
			.mtl-member-summary::after {
				transition: none;
			}
		}

		details.mtl-member-card[open] .mtl-member-summary {
			padding-bottom: 10px;
		}

		.mtl-member-collapsible-body {
			padding: 0 24px 22px 24px;
		}

		/* Consider Giving: the optional fundraising ask, shown on the Account
			page and My Reservations. Styled as an ordinary card rather than a
			banner, since it is a standing invitation rather than an alert, and members
			see it on every visit. */
		.mtl-member-giving-text {
			margin: 6px 0 0 0;
		}

		.mtl-member-giving-action {
			margin: 14px 0 0 0;
		}

		.mtl-member-btn-giving {
			display: inline-block;
		}

		/* Admin-uploaded badge images (training/verified), small and inline,
			same spot the plain green pill would otherwise occupy. The training
			name/label lives in both alt (accessibility, and shown if the image
			fails to load) and title (mouse hover tooltip). */
		.mtl-badge-img {
			display: inline-block;
			vertical-align: middle;
			border-radius: 4px;
		}

		.mtl-verified-badge-img {
			height: 28px;
			width: auto;
		}

		.mtl-training-badge-img {
			height: 36px;
			width: 36px;
			object-fit: cover;
			border: 1px solid #ccd0d4;
			margin-left: 6px;
		}

		.mtl-member-back {
			display: inline-block;
			margin-bottom: 16px;
			font-size: 0.9em;
		}

		.mtl-front-notice {
			max-width: 760px;
			margin: 0 auto 18px auto;
			padding: 12px 16px;
			border-radius: 6px;
			font-size: 0.95em;
		}

		.mtl-front-notice-success {
			background: #edf7ed;
			border: 1px solid #b6dcb6;
			color: #1e5b25;
		}

		.mtl-front-notice-error {
			background: #fcf0f1;
			border: 1px solid #f0c0c4;
			color: #8a1f28;
		}

		/* Forms */
		.mtl-member-field {
			margin-bottom: 14px;
		}

		.mtl-member-field label {
			display: block;
			font-weight: 600;
			margin-bottom: 4px;
		}

		.mtl-member-field input {
			width: 100%;
			box-sizing: border-box;
			padding: 9px 11px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
			font-size: 1em;
		}

		.mtl-member-field input[readonly] {
			background: #f6f7f7;
			color: #646970;
		}

		.mtl-member-hint {
			font-size: 0.82em;
			color: #646970;
			margin: 4px 0 0 0;
		}

		.mtl-member-row {
			display: flex;
			gap: 14px;
			flex-wrap: wrap;
		}

		.mtl-member-row>.mtl-member-field {
			flex: 1 1 220px;
		}

		.mtl-member-btn {
			display: inline-block;
			padding: 10px 20px;
			border: 1px solid <?php echo esc_html( $accent ); ?>;
			border-radius: 4px;
			background: <?php echo esc_html( $accent ); ?>;
			color: #fff;
			font-size: 1em;
			font-weight: 600;
			cursor: pointer;
			text-decoration: none;
		}

		.mtl-member-btn-ghost {
			background: #fff;
			color: #3c434a;
			border-color: #ccd0d4;
			font-weight: 400;
		}

		.mtl-member-btn-danger {
			background: #fff;
			color: #b32d2e;
			border-color: #e2a3a4;
			font-weight: 400;
		}

		.mtl-member-confirm-actions {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
			align-items: center;
		}

		/* Tables (reservations queue, past loans) */
		.mtl-member-table {
			width: 100%;
			border-collapse: collapse;
			font-size: 0.94em;
		}

		.mtl-member-table th,
		.mtl-member-table td {
			text-align: left;
			padding: 9px 10px;
			border-bottom: 1px solid #eef0f2;
			vertical-align: middle;
		}

		.mtl-member-table th {
			font-size: 0.8em;
			text-transform: uppercase;
			letter-spacing: 0.03em;
			color: #646970;
		}

		.mtl-member-empty {
			color: #646970;
			margin: 8px 0 0 0;
		}

		/* Status pills */
		.mtl-pill {
			display: inline-block;
			padding: 2px 9px;
			border-radius: 12px;
			font-size: 0.8em;
			font-weight: 600;
		}

		.mtl-pill-green {
			background: #e6f4ea;
			color: #1e7b34;
		}

		.mtl-pill-amber {
			background: #fcf3e3;
			color: #8a6d00;
		}

		.mtl-pill-red {
			background: #fcecec;
			color: #b32d2e;
		}

		.mtl-pill-orange {
			background: #fbe6d2;
			color: #9a4d00;
		}

		.mtl-pill-grey {
			background: #f0f1f2;
			color: #50575e;
		}

		.mtl-member-queue-place {
			font-weight: 700;
			font-size: 1.05em;
		}

		/* ---- My Reservations: two-column list + :target detail box ---- */
		.mtl-member-wrap-wide {
			max-width: 1080px;
		}

		.mtl-res-layout {
			display: flex;
			gap: 20px;
			align-items: flex-start;
		}

		.mtl-res-main {
			flex: 1 1 auto;
			min-width: 0;
		}

		.mtl-res-detail-col {
			flex: 0 0 320px;
			position: sticky;
			top: 16px;
		}

		@media (max-width: 820px) {
			.mtl-res-layout {
				flex-direction: column;
			}

			.mtl-res-detail-col {
				position: static;
				width: 100%;
			}
		}

		/* Clicking a tool name reveals its panel; every panel is pre-rendered
			hidden and CSS :target shows the one whose id matches the fragment. */
		.mtl-res-name-link {
			font-weight: 600;
			text-decoration: none;
		}

		.mtl-res-name-link:hover {
			text-decoration: underline;
		}

		.mtl-res-detail {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 8px;
			overflow: hidden;
		}

		.mtl-res-detail-panel {
			display: none;
		}

		.mtl-res-detail-panel:target {
			display: block;
		}

		.mtl-res-detail:has(.mtl-res-detail-panel:target) .mtl-res-detail-empty {
			display: none;
		}

		.mtl-res-detail-empty {
			padding: 28px 20px;
			text-align: center;
			color: #8c8f94;
		}

		.mtl-res-detail-photo {
			width: 100%;
			max-height: 220px;
			object-fit: contain;
			background: #f6f7f7;
		}

		.mtl-res-detail-body {
			padding: 16px 18px 20px 18px;
		}

		.mtl-res-detail-name {
			font-size: 1.2em;
			font-weight: 700;
			margin: 0;
		}

		.mtl-res-detail-brand {
			color: #787c82;
			margin: 2px 0 0 0;
		}

		.mtl-res-detail-body h4 {
			margin: 16px 0 6px 0;
			font-size: 0.95em;
		}

		.mtl-res-info {
			background: #f6f7f7;
			border-radius: 6px;
			padding: 12px 14px;
			margin: 14px 0;
		}

		/* Member agreements. The clause itself is the label and therefore the
			click target, so the row is laid out to keep the checkbox aligned
			with the first line of what can be several paragraphs of text. */
		.mtl-agreements {
			border: 1px solid #dcdcde;
			border-radius: 6px;
			padding: 14px 16px 4px 16px;
			margin: 18px 0;
		}

		.mtl-agreements legend {
			font-weight: 600;
			padding: 0 6px;
		}

		.mtl-agreements-intro {
			margin: 0 0 12px 0;
			color: #50575e;
		}

		.mtl-agreement-item {
			margin-bottom: 14px;
		}

		.mtl-agreement-item-invalid {
			border-left: 3px solid #b32d2e;
			padding-left: 10px;
			margin-left: -13px;
		}

		.mtl-agreement-label {
			display: flex;
			gap: 10px;
			align-items: flex-start;
			cursor: pointer;
		}

		.mtl-agreement-label input[type="checkbox"] {
			flex: 0 0 auto;
			margin-top: 3px;
			width: 18px;
			height: 18px;
		}

		/* Paired with the red rule above, never carrying the meaning alone. */
		.mtl-agreement-error {
			margin: 4px 0 0 28px;
			color: #b32d2e;
			font-weight: 600;
		}

		.mtl-agreement-file,
		.mtl-agreement-superseded {
			margin: 4px 0 0 28px;
			font-size: 0.9em;
		}

		.mtl-agreement-superseded {
			color: #50575e;
		}

		.mtl-agreements-assent {
			margin: 14px 0;
			color: #50575e;
		}

		.mtl-agreements-summary ul {
			margin: 6px 0 0 0;
			padding-left: 20px;
		}

		/* The receipt: what they have already agreed to. */
		.mtl-agreement-receipt {
			list-style: none;
			margin: 0;
			padding: 0;
		}

		.mtl-agreement-receipt li {
			display: flex;
			gap: 10px;
			align-items: flex-start;
			margin-bottom: 14px;
		}

		.mtl-agreement-tick {
			flex: 0 0 auto;
			color: #007017;
			font-weight: 700;
		}

		.mtl-agreement-meta {
			margin: 2px 0 0 0;
			font-size: 0.9em;
			color: #50575e;
		}

		.mtl-agreement-retired-note {
			font-style: italic;
		}

		/* Availability badges + category/tag pills. These pages call the shop's
			own mtl_shop_status_badges() / mtl_shop_pills() helpers, so they take
			the shop's rules verbatim from public/shop-page.php. */
		<?php echo mtl_shop_badge_pill_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS from a developer-defined string, never user input. ?>
	</style>
	<?php
	return ob_get_clean();
}

/**
 * Standard "back to the shop" footer link for the member pages.
 */
function mtl_member_page_footer() {
	return '<a href="' . esc_url( mtl_front_page_url( 'main' ) ) . '">&larr; Back to the tool catalog</a>';
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
 * Wording matches the actual state. "Our agreements have been updated" is
 * simply untrue for somebody who never agreed to anything, and a member told
 * that will reasonably reply that they never saw the first set either.
 *
 * Not a live region: it is present on page load rather than injected, so
 * announcing it would be noise on every page view.
 *
 * Gated on mtl_agreements_online(), since paper mode gives the member nowhere
 * to act on it.
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
 * The first few words of an agreement, for error messages and link names.
 *
 * @param string $text  Agreement text.
 * @param int    $words How many words to keep.
 * @return string Plain text, ellipsis appended when truncated.
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
 * join the library, so the accessible structure is load-bearing:
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
							aria-label="<?php echo esc_attr( 'View the attached document for &ldquo;' . wp_strip_all_tags( mtl_agreement_excerpt( $agreement->agreement_text ) ) . '&rdquo; (opens in a new tab)' ); ?>"><?php esc_html_e( 'View the attached document', 'my-tool-library' ); ?> \&#8599;</a>
					</p>
				<?php endif; ?>

				<?php if ( isset( $args['superseded'][ $aid ] ) ) : ?>
					<p class="mtl-agreement-superseded"><?php echo esc_html( $args['superseded'][ $aid ] ); ?></p>
				<?php endif; ?>

				<!-- The version this member is being shown. Submitted back so an
					acceptance can never be recorded against wording that was
					revised while the form sat open. -->
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
 * role="alert" announces it on a re-render; tabindex lets focus move to it, so
 * a screen-reader user submitting an incomplete form hears why.
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
	<div class="mtl-front-notice mtl-front-notice-error mtl-agreements-summary" role="alert" tabindex="-1" id="<?php echo esc_attr( $id_prefix ); ?>-summary">
		<div><strong><?php esc_html_e( 'You must agree to:', 'my-tool-library' ); ?></strong></div>
		<ul>
			<?php foreach ( $missing as $agreement ) : ?>
				<li>
					<a href="#<?php echo esc_attr( $id_prefix . '-' . (int) $agreement->agreement_id ); ?>">
						<?php echo wp_kses( mtl_agreement_excerpt( $agreement->agreement_text, 12 ), array() ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<script>
		// Focus the summary so a keyboard or screen-reader user lands on the
		// explanation rather than at the top of an apparently unchanged page.
		// Progressive enhancement only: role="alert" already announces it, and
		// the summary's links work without any of this.
		(function() {
			var s = document.getElementById(<?php echo wp_json_encode( $id_prefix . '-summary' ); ?>);
			if (s) { s.focus(); }
		})();
	</script>
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

		// Never shown to this member, having been added while they were filling the form
		// in. Erroring about a checkbox they never saw would be accurate and
		// useless, so the caller re-renders with it shown and unticked.
		if ( ! isset( $versions[ $aid ] ) ) {
			$added[] = $aid;
			continue;
		}

		// The dangerous one. Recording this would assert, permanently and with
		// a checksum, that the member agreed to wording they never read.
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
 * Called from mtl_render_front_main_page() before any output, so it can
 * finish with a Post/Redirect/Get back to the catalog (no double-submit on
 * refresh). Does nothing unless this request is actually a reserve POST.
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

	// Valid nonce required (blocks cross-site / accidental submissions).
	if ( ! isset( $_POST['mtl_reserve_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_reserve_nonce'] ) ), 'mtl_reserve_action' ) ) {
		$redirect( 'reserve_failed' );
	}

	$member = mtl_current_member();
	if ( ! $member ) {
		$redirect( 'login_required' );
	}

	// The agreements gate. online() only: in paper mode a member cannot agree on
	// the website, so blocking them would leave them with no way to unblock
	// themselves. The Reserve button stays visible and the gate catches the
	// click, so no reservation is created and there is nothing to resume; the
	// member agrees, then reserves whenever they like.
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

	$redirect( $inserted ? 'reserved' : 'reserve_failed' );
}

// --------------------------------------------------------------------------
// Sign-up page  (mtl_page=signup)
// --------------------------------------------------------------------------

/**
 * Renders the member sign-up page and handles its POST submission.
 */
function mtl_render_signup_page() {
	// Already signed in, so nothing to sign up for.
	if ( is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'main' ) );
		exit;
	}

	global $wpdb;
	$errors = array();
	// Set when the submitted address already has a membership but no website
	// sign-in. Rendered as its own notice below rather than as an $errors entry,
	// because it needs a real link and that loop escapes its messages.
	$needs_password_setup = false;

	// Typed twice because the address IS the username, and a WordPress username
	// cannot be changed afterwards. Kept out of $vals: it is a form field only,
	// never a value that reaches the members table. Repopulated on a validation
	// failure, unlike the passwords, so a member can see the two side by side
	// and correct whichever one is wrong.
	$email_confirm = '';

	// Agreements state, carried from the handler to the render below.
	// $agreement_ticked keeps the boxes ticked across a validation failure,
	// making somebody re-tick six boxes because they fat-fingered their ZIP
	// code is the kind of thing that loses a signup.
	$agreement_ticked  = array();
	$agreement_invalid = array();
	$agreement_missing = array();
	$agreement_changed = false;
	$agreement_seen    = array();

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
			// Passwords are unslashed but NOT sanitized, since altering the
			// characters would silently change the member's chosen password.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$password = (string) wp_unslash( $_POST['password'] ?? '' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$password2 = (string) wp_unslash( $_POST['password2'] ?? '' );

			// The single source of truth for what gets stored; see
			// mtl_format_phone_number()'s docblock. Computed once here so
			// both the validation check below and the INSERT further down
			// use the exact same result.
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
				// Case-insensitively: somebody who types Jo@x.com then jo@x.com
				// has confirmed the address they meant, and WordPress signs them
				// in either way. Rejecting that would be a typo check inventing
				// a typo.
				$errors[] = 'The two email addresses you entered do not match.';
			} elseif ( email_exists( $vals['email'] ) ) {
				$errors[] = 'An account with that email already exists. Try signing in instead.';
			} elseif ( mtl_find_member_by_email( $vals['email'] ) ) {
				// The library already holds a membership for this address but
				// there is no WordPress account behind it: staff added or
				// imported them and no password has ever been set.
				//
				// This used to say "try signing in instead", which was a dead
				// end: there was nothing to sign in to, and the lost-password
				// page could not help either, having no account to make a key
				// for. Both work now, so point them at the one that does the
				// job rather than the one that looks obvious.
				$needs_password_setup = true;
				$errors[]             = 'There is already a membership on file for that email address.';
			}
			if ( strlen( $password ) < MTL_MIN_PASSWORD_LENGTH ) {
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
					// Something changed under them. Re-render with the new
					// wording; untick only what changed, and leave every other
					// field and tick alone, since re-typing an address because a
					// fee policy was edited is how a signup gets abandoned.
					$agreement_changed = true;
					$agreement_ticked  = array_values( array_diff( $agreement_ticked, $check['stale'], $check['added'] ) );
					$errors[]          = 'One or more of our agreements changed while you were reading them. The updated wording is shown below; please review and agree again.';
				} elseif ( $check['missing'] ) {
					$agreement_missing = $check['missing'];
					foreach ( $check['missing'] as $mtl_missed ) {
						$agreement_invalid[] = (int) $mtl_missed->agreement_id;
					}
					// The summary block lists these as links; this line is what
					// appears in the ordinary error list beside the field
					// errors, naming them rather than counting them.
					$mtl_names = array();
					foreach ( $check['missing'] as $mtl_missed ) {
						$mtl_names[] = wp_strip_all_tags( mtl_agreement_excerpt( $mtl_missed->agreement_text ) );
					}
					$errors[] = __( 'You must agree to:', 'my-tool-library' ) . ' ' . implode( '; ', $mtl_names );
				}
			}

			if ( empty( $errors ) ) {
				// Create the member row first so a failed wp_insert_user()
				// never leaves an orphaned WP user; if it fails afterward,
				// the row is deleted so a retry can succeed cleanly.
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
					$user_id   = wp_insert_user(
						array(
							'user_login'   => $vals['email'],
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
							$expected      = count( mtl_member_outstanding_agreements( $member_id ) );
							$recorded      = mtl_record_all_outstanding_agreements( $member_id, 'signup', $agreement_seen );
							$agreements_ok = ( $recorded === $expected );
						}

						if ( ! $agreements_ok ) {
							// Unwind everything, in the reverse of the order it
							// was created. The acceptance rows go with the
							// member row through the foreign key's ON DELETE
							// CASCADE, so there is nothing separate to clean up.
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
							do_action( 'wp_login', $vals['email'], get_userdata( $user_id ) );

							wp_safe_redirect( mtl_front_page_url( 'main' ) );
							exit;
						}
					}
				}
			}
		}
	}

	$action_url = mtl_front_page_url( 'signup' );

	ob_start();
	echo mtl_member_page_styles();
	?>
	<div class="mtl-member-wrap">
		<a class="mtl-member-back" href="<?php echo esc_url( mtl_front_page_url( 'main' ) ); ?>">&larr; Back to the tool catalog</a>

		<div class="mtl-member-card">
			<h2>Create your member account</h2>
			<p style="margin-top:0; color:#50575e;">Membership is free and lets you reserve tools and track your place in line. You&rsquo;ll be signed in and ready to browse as soon as you finish.</p>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="mtl-front-notice mtl-front-notice-error" style="max-width:none; margin: 0 0 18px 0;">
					<?php foreach ( $errors as $e ) : ?>
						<div><?php echo esc_html( $e ); ?></div>
					<?php endforeach; ?>
					<?php if ( $needs_password_setup ) : ?>
						<div style="margin-top: 8px;">
							Library staff have already set you up, so there is no need to sign up again.
							You just need a password.
							<a href="<?php echo esc_url( mtl_front_page_url( 'lostpassword' ) ); ?>"><strong>Set your password here</strong></a>
							and we will email you a link.
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( 'mtl_signup_action', 'mtl_signup_nonce' ); ?>

				<div class="mtl-member-row">
					<div class="mtl-member-field">
						<label for="mtl-su-first">First name</label>
						<input type="text" id="mtl-su-first" name="first_name" value="<?php echo esc_attr( $vals['first_name'] ); ?>" required>
					</div>
					<div class="mtl-member-field">
						<label for="mtl-su-last">Last name</label>
						<input type="text" id="mtl-su-last" name="last_name" value="<?php echo esc_attr( $vals['last_name'] ); ?>" required>
					</div>
				</div>

				<div class="mtl-member-field">
					<label for="mtl-su-address1">Address</label>
					<input type="text" id="mtl-su-address1" name="address_line1" value="<?php echo esc_attr( $vals['address_line1'] ); ?>" required>
					<p class="mtl-member-hint">Used to verify membership when you borrow tools.</p>
				</div>

				<div class="mtl-member-field">
					<label for="mtl-su-address2">Address line 2 <span style="font-weight:normal;">(optional)</span></label>
					<input type="text" id="mtl-su-address2" name="address_line2" value="<?php echo esc_attr( $vals['address_line2'] ); ?>">
				</div>

				<div class="mtl-member-row">
					<div class="mtl-member-field">
						<label for="mtl-su-city">City</label>
						<input type="text" id="mtl-su-city" name="city" value="<?php echo esc_attr( $vals['city'] ); ?>" required>
					</div>
					<div class="mtl-member-field">
						<label for="mtl-su-state">State / Province</label>
						<select id="mtl-su-state" name="state" required>
							<option value="">&mdash; Select &mdash;</option>
							<?php foreach ( mtl_get_state_options() as $mtl_state_code => $mtl_state_label ) : ?>
								<option value="<?php echo esc_attr( $mtl_state_code ); ?>" <?php selected( $vals['state'], $mtl_state_code ); ?>><?php echo esc_html( $mtl_state_label ); ?> (<?php echo esc_html( $mtl_state_code ); ?>)</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="mtl-member-row">
					<div class="mtl-member-field">
						<label for="mtl-su-zip">ZIP code</label>
						<input type="text" id="mtl-su-zip" name="zip_code" value="<?php echo esc_attr( $vals['zip_code'] ); ?>" required>
					</div>
					<div class="mtl-member-field">
						<label for="mtl-su-country">Country</label>
						<select id="mtl-su-country" name="country" required>
							<?php foreach ( mtl_get_country_options() as $mtl_country_name ) : ?>
								<option value="<?php echo esc_attr( $mtl_country_name ); ?>" <?php selected( $vals['country'], $mtl_country_name ); ?>><?php echo esc_html( $mtl_country_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="mtl-member-row">
					<div class="mtl-member-field">
						<label for="mtl-su-phone_national">Phone number</label>
						<?php mtl_render_phone_input( $vals['phone_country'], $vals['phone_national'], 'mtl-su-' ); ?>
					</div>
				</div>

				<div class="mtl-member-row">
					<div class="mtl-member-field">
						<label for="mtl-su-email">Email address</label>
						<input type="email" id="mtl-su-email" name="email" value="<?php echo esc_attr( $vals['email'] ); ?>" required>
						<p class="mtl-member-hint">This is also your username for signing in.</p>
					</div>
					<div class="mtl-member-field">
						<label for="mtl-su-email2">Confirm email address</label>
						<input type="email" id="mtl-su-email2" name="email2" value="<?php echo esc_attr( $email_confirm ); ?>" required>
						<p class="mtl-member-hint">Confirm email address.</p>
					</div>
				</div>

				<div class="mtl-member-row">
					<div class="mtl-member-field">
						<label for="mtl-su-pass">Password</label>
						<input type="password" id="mtl-su-pass" name="password" autocomplete="new-password" required>
						<p class="mtl-member-hint">At least <?php echo (int) MTL_MIN_PASSWORD_LENGTH; ?> characters.</p>
					</div>
					<div class="mtl-member-field">
						<label for="mtl-su-pass2">Confirm password</label>
						<input type="password" id="mtl-su-pass2" name="password2" autocomplete="new-password" required>
					</div>
				</div>

				<?php
				// online() only, since paper mode collects signatures at the desk.
				// Read live rather than reusing the handler's copy, so the form
				// shows the agreements as they stand now.
				if ( mtl_agreements_online() ) {
					$signup_agreements = mtl_get_active_agreements();
					if ( $signup_agreements ) {
						// Both helpers escape every value they interpolate; the
						// markup around those values is the plugin's own.
						echo mtl_render_agreements_error_summary( $agreement_missing, 'mtl-su-agreement' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the renderer.
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
				}
				?>

				<p style="margin: 18px 0 0 0;">
					<button type="submit" name="mtl_signup" value="1" class="mtl-member-btn">Create Account</button>
				</p>
			</form>
		</div>

		<p style="text-align:center; margin:0;">
			Already have an account? <a href="<?php echo esc_url( mtl_front_page_url( 'login' ) ); ?>">Sign in</a>.
		</p>
	</div>
	<?php
	mtl_phone_formatter_script();
	$body = ob_get_clean();

	mtl_render_front_shell( 'Create Account', $body );
}

/**
 * Render one reserved tool's detail-panel body for the My Reservations page:
 * the tool's customer-facing details plus this member's reservation info
 * (place in line, ready/on-loan status, dates) and a cancel link. Reuses the
 * shop's badge/pill helpers so it matches the catalog's detail box, and
 * follows the same rule on shelf location: shown only when the library has
 * turned it on for members.
 *
 * @param object $r        Enriched reservation row from the query below.
 * @param string $self_url Current page URL, used to build the cancel link.
 * @return string HTML.
 */
function mtl_render_reservation_detail_panel( $r, $self_url ) {
	$on_loan   = ( (int) $r->active_loans > 0 );
	$is_first  = ( 1 === (int) $r->queue_place );
	$available = ! $on_loan;

	ob_start();
	?>
	<?php if ( ! empty( $r->photo_url ) ) : ?>
		<img class="mtl-res-detail-photo" src="<?php echo esc_url( $r->photo_url ); ?>" alt="<?php echo esc_attr( stripslashes( $r->tool_name ) ); ?>" loading="lazy">
	<?php endif; ?>
	<div class="mtl-res-detail-body">
		<p class="mtl-res-detail-name"><?php echo esc_html( stripslashes( $r->tool_name ) ); ?></p>
		<?php if ( ! empty( $r->brand ) ) : ?>
			<p class="mtl-res-detail-brand"><?php echo esc_html( stripslashes( $r->brand ) ); ?></p>
		<?php endif; ?>

		<div class="mtl-shop-badges"><?php echo mtl_shop_status_badges( $on_loan, (int) $r->queue_size ); ?></div>

		<div class="mtl-res-info">
			<p style="margin:0 0 6px 0; font-weight:600;">Your reservation</p>
			<p style="margin:0;">
				Place in line: <strong><?php echo (int) $r->queue_place; ?></strong> of <?php echo (int) $r->queue_size; ?>
				<?php if ( $is_first && $available ) : ?>
					<span class="mtl-pill mtl-pill-green" style="margin-left:6px;">Ready for pickup</span>
				<?php elseif ( $is_first ) : ?>
					<span class="mtl-pill mtl-pill-amber" style="margin-left:6px;">Out on loan</span>
				<?php endif; ?>
			</p>
			<p style="margin:6px 0 0 0; color:#50575e; font-size:0.92em;">
				Reserved on <?php echo mtl_format_date( $r->reservation_date ); ?>
			</p>
			<?php
			// Only a reservation that is actually collectable has a deadline;
			// anyone still queued behind a loan has no countdown running.
			// ready_since is checked separately from the deadline because a
			// library can set the hold period to 0 (never expires), in which case the tool
			// is still being held from that date, there is just no cut-off.
			$mtl_ready_since = trim( (string) $r->ready_since );
			$mtl_collect_by  = mtl_reservation_collect_by( $mtl_ready_since );
			if ( '' !== $mtl_ready_since ) :
				?>
				<p style="margin:6px 0 0 0; color:#50575e; font-size:0.92em;">
					Ready for pickup since <strong><?php echo mtl_format_date( $mtl_ready_since ); ?></strong>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $mtl_collect_by ) : ?>
				<p style="margin:6px 0 0 0; color:#50575e; font-size:0.92em;">
					Please collect by <strong><?php echo mtl_format_date( $mtl_collect_by ); ?></strong>, or the reservation is cancelled and the tool passes to the next person in line.
				</p>
			<?php endif; ?>
		</div>

		<?php echo mtl_shop_location_block( $r->location ); ?>

		<?php if ( ! empty( $r->categories ) ) : ?>
			<h4>Categories</h4>
			<div><?php echo mtl_shop_pills( $r->categories ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $r->subcategories ) ) : ?>
			<h4>Sub-categories</h4>
			<div><?php echo mtl_shop_pills( $r->subcategories ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $r->tags ) ) : ?>
			<h4>Tags</h4>
			<div><?php echo mtl_shop_pills( $r->tags ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $r->description ) ) : ?>
			<h4>Description</h4>
			<p><?php echo nl2br( esc_html( stripslashes( $r->description ) ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $r->components ) ) : ?>
			<h4>What&rsquo;s included</h4>
			<p><?php echo nl2br( esc_html( stripslashes( $r->components ) ) ); ?></p>
		<?php endif; ?>

		<?php
		$mtl_cancel_url = add_query_arg(
			array(
				'mtl_confirm' => 'one',
				'rid'         => (int) $r->reservation_id,
			),
			$self_url
		);
		?>
		<a class="mtl-member-btn mtl-member-btn-danger" style="margin-top:16px;" href="<?php echo esc_url( $mtl_cancel_url ); ?>">Cancel this reservation</a>
	</div>
	<?php
	return ob_get_clean();
}

// --------------------------------------------------------------------------
// My Reservations page  (mtl_page=reservations)
// --------------------------------------------------------------------------

/**
 * Renders the member's "My Loans & Reservations" page and handles its
 * cancel-reservation POST actions.
 */
function mtl_render_member_reservations_page() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'login' ) );
		exit;
	}

	$member = mtl_current_member();
	if ( ! $member ) {
		mtl_render_member_only_notice( 'My Loans & Reservations', mtl_current_member_link_broken() );
		return; // (mtl_render_member_only_notice exits, but be explicit.)
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

	// --- Active loans (currently checked out), soonest due date first. Each
	// is flagged 'overdue', 'due_today', 'due_soon' (within 1-3 days), or
	// 'normal' so the table can call out urgency. ---
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$active_loans = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.loan_id, l.tool_id, l.due_date, t.tool_name
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
		if ( $days_left < 0 ) {
			$loan->loan_status = 'overdue';
		} elseif ( 0 === $days_left ) {
			$loan->loan_status = 'due_today';
		} elseif ( $days_left <= 3 ) {
			$loan->loan_status = 'due_soon';
		} else {
			$loan->loan_status = 'normal';
		}
	}

	// Admin-editable via the Setup page; blank hides it entirely (see the
	// update_option() comment in setup-page.php for why blank stays blank).
	// The fallback text here matches setup-page.php's default exactly, so a
	// fresh install shows sensible copy before any admin has saved Setup.
	$pickup_directions = trim(
		(string) get_option(
			'mtl_pickup_directions',
			'Placing a reservation holds your spot in line and speeds up the process of checking out tools. If no one is waiting in line to borrow a tool, no reservation is required. Come by our store and speak with a representative to take tools home.'
		)
	);

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
					"UPDATE {$tbl_res} SET expiry_date = %s
                 WHERE reservation_id = %d AND member_id = %d AND expiry_date IS NULL",
					$today,
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
					"UPDATE {$tbl_res} SET expiry_date = %s
                 WHERE member_id = %d AND expiry_date IS NULL",
					$today,
					(int) $member->member_id
				)
			);
			foreach ( $freed_tool_ids as $freed_tool_id ) {
				mtl_sync_reservation_readiness( (int) $freed_tool_id );
			}
			wp_safe_redirect( add_query_arg( 'mtl_msg', 'reservations_cancelled', $self ) );
			exit;
		}
	}

	// --- Active reservations, enriched with everything the detail panel needs:
	// tool fields, category/tag lists (correlated subqueries, so no
	// GROUP BY is needed), place in line + queue size, and on-loan status.
	// queue_place counts same-tool reservations ahead in line (earlier
	// reservation_date, ties broken by reservation_id), the same
	// derivation the admin Loans & Reservations page uses. ---
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT r.reservation_id, r.tool_id, r.reservation_date, r.ready_since,
                t.tool_name, t.brand, t.description, t.components, t.photo_url, t.location,
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
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

	ob_start();
	echo mtl_member_page_styles();
	?>
	<?php
	// In the normal listing view the page is a two-column layout (list +
	// detail), so it gets a wider wrap; the confirm and empty states stay
	// in the standard narrow, single-column wrap.
	?>
	<?php $listing = ( '' === $confirm && ! empty( $rows ) ); ?>
	<div class="mtl-member-wrap<?php echo $listing ? ' mtl-member-wrap-wide' : ''; ?>">
		<a class="mtl-member-back" href="<?php echo esc_url( mtl_front_page_url( 'main' ) ); ?>">&larr; Back to the tool catalog</a>

		<?php echo mtl_front_notice_html(); ?>
		<?php echo mtl_agreements_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>

		<?php
		// Always shown (with an empty-state message when there's nothing
		// checked out), same as the Reservations section below, so the
		// page structure is predictable regardless of state.
		?>
		<div class="mtl-member-card">
			<h2>Active Loans</h2>
			<?php if ( empty( $active_loans ) ) : ?>
				<p class="mtl-member-empty">You don&rsquo;t have any tools checked out right now.</p>
			<?php else : ?>
				<table class="mtl-member-table">
					<thead>
						<tr>
							<th>Tool</th>
							<th>Due</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $active_loans as $loan ) : ?>
							<tr>
								<td><?php echo esc_html( stripslashes( $loan->tool_name ) ); ?></td>
								<td>
									<?php echo mtl_format_date( $loan->due_date ); ?>
									<?php if ( 'overdue' === $loan->loan_status ) : ?>
										<span class="mtl-pill mtl-pill-red" style="margin-left:6px;">Overdue</span>
									<?php elseif ( 'due_today' === $loan->loan_status ) : ?>
										<span class="mtl-pill mtl-pill-orange" style="margin-left:6px;">Due today</span>
									<?php elseif ( 'due_soon' === $loan->loan_status ) : ?>
										<span class="mtl-pill mtl-pill-amber" style="margin-left:6px;">Due soon</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php if ( '' !== $pickup_directions ) : ?>
			<div class="mtl-member-card mtl-member-directions">
				<strong>Picking up a tool</strong>
				<p style="margin:6px 0 0 0;"><?php echo nl2br( esc_html( $pickup_directions ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'one' === $confirm && $confirm_row ) : ?>
			<div class="mtl-member-card">
				<h2>My Reservations</h2>
				<?php
				// "Are you sure?" for cancelling ONE reservation. Reached
				// by the plain Cancel link; the delete only happens when
				// the member submits this POST form.
				?>
				<p style="margin-top:0;">Cancel your reservation for <strong><?php echo esc_html( stripslashes( $confirm_row->tool_name ) ); ?></strong>? You&rsquo;ll lose your current place in line, and this can&rsquo;t be undone.</p>
				<div class="mtl-member-confirm-actions">
					<form method="post" action="<?php echo esc_url( $self ); ?>" style="margin:0;">
						<?php wp_nonce_field( 'mtl_res_action', 'mtl_res_nonce' ); ?>
						<input type="hidden" name="mtl_action" value="cancel_reservation">
						<input type="hidden" name="reservation_id" value="<?php echo (int) $confirm_row->reservation_id; ?>">
						<button type="submit" class="mtl-member-btn mtl-member-btn-danger">Yes, cancel this reservation</button>
					</form>
					<a class="mtl-member-btn mtl-member-btn-ghost" href="<?php echo esc_url( $self ); ?>">No, keep it</a>
				</div>
			</div>

		<?php elseif ( 'all' === $confirm && ! empty( $rows ) ) : ?>
			<div class="mtl-member-card">
				<h2>My Reservations</h2>
				<?php
				// "Are you sure?" for cancelling ALL reservations.
				?>
				<p style="margin-top:0;">Cancel <strong>all <?php echo count( $rows ); ?></strong> of your reservations? You&rsquo;ll lose every place in line, and this can&rsquo;t be undone.</p>
				<div class="mtl-member-confirm-actions">
					<form method="post" action="<?php echo esc_url( $self ); ?>" style="margin:0;">
						<?php wp_nonce_field( 'mtl_res_action', 'mtl_res_nonce' ); ?>
						<input type="hidden" name="mtl_action" value="cancel_all">
						<button type="submit" class="mtl-member-btn mtl-member-btn-danger">Yes, cancel all reservations</button>
					</form>
					<a class="mtl-member-btn mtl-member-btn-ghost" href="<?php echo esc_url( $self ); ?>">No, keep them</a>
				</div>
			</div>

		<?php elseif ( empty( $rows ) ) : ?>
			<div class="mtl-member-card">
				<h2>My Reservations</h2>
				<p class="mtl-member-empty">You don&rsquo;t have any active reservations. Browse the <a href="<?php echo esc_url( mtl_front_page_url( 'main' ) ); ?>">tool catalog</a> and reserve a tool to join its waiting queue.</p>
			</div>

		<?php else : ?>
			<?php
			// Two columns: the reservation list (left) and a detail box
			// (right) revealed via the CSS :target pseudo-class: clicking
			// a tool name opens its panel in place, no reload, no JS, the
			// same technique the shop catalog uses.
			?>
			<div class="mtl-res-layout">
				<div class="mtl-res-main">
					<div class="mtl-member-card">
						<h2>My Reservations</h2>
						<p style="margin-top:0; color:#50575e;">Select a tool name to see its full details on the right. Once you reach the front of the queue, you&rsquo;ll see whether the tool is ready to pick up or still out on loan.</p>
						<table class="mtl-member-table">
							<thead>
								<tr>
									<th>Tool</th>
									<th>Place in line</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $rows as $r ) :
									$is_first  = ( 1 === (int) $r->queue_place );
									$available = ( 0 === (int) $r->active_loans );
									?>
									<tr>
										<td><a class="mtl-res-name-link" href="#<?php echo esc_attr( 'res-tool-' . (int) $r->reservation_id ); ?>"><?php echo esc_html( stripslashes( $r->tool_name ) ); ?></a></td>
										<td>
											<span class="mtl-member-queue-place"><?php echo (int) $r->queue_place; ?></span>
											<span style="color:#8c8f94;"> of <?php echo (int) $r->queue_size; ?></span>
											<?php if ( $is_first && $available ) : ?>
												<span class="mtl-pill mtl-pill-green" style="margin-left:6px;">Ready for pickup</span>
												<?php
												// Show when the hold started, not just when it ends: a member
												// coming back to this page wants to know how long the tool has
												// been waiting for them. ready_since drives the deadline, so
												// the two always agree.
												$mtl_ready_since = trim( (string) $r->ready_since );
												$mtl_collect_by  = mtl_reservation_collect_by( $mtl_ready_since );
												if ( '' !== $mtl_ready_since ) :
													?>
													<span style="color:#50575e; font-size:0.85em; margin-left:6px;">
														ready since <?php echo mtl_format_date( $mtl_ready_since ); ?>
														<?php if ( '' !== $mtl_collect_by ) : ?>
															&middot; collect by <?php echo mtl_format_date( $mtl_collect_by ); ?>
														<?php endif; ?>
													</span>
												<?php endif; ?>
											<?php elseif ( $is_first ) : ?>
												<span class="mtl-pill mtl-pill-amber" style="margin-left:6px;">Out on loan</span>
											<?php endif; ?>
										</td>
										<td>
											<?php
											// Plain link -> confirmation prompt (no delete yet).
											$mtl_cancel_url = add_query_arg(
												array(
													'mtl_confirm' => 'one',
													'rid' => (int) $r->reservation_id,
												),
												$self
											);
											?>
											<a class="mtl-member-btn mtl-member-btn-danger" style="padding:5px 12px; font-size:0.85em;" href="<?php echo esc_url( $mtl_cancel_url ); ?>">Cancel</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<p style="margin: 18px 0 0 0;">
							<a class="mtl-member-btn mtl-member-btn-danger" href="<?php echo esc_url( add_query_arg( 'mtl_confirm', 'all', $self ) ); ?>">Cancel all reservations</a>
						</p>
					</div>
				</div>

				<div class="mtl-res-detail-col">
					<div class="mtl-res-detail" id="mtl-res-detail">
						<div class="mtl-res-detail-empty">
							<p style="margin:0;">Select a tool name to see its full details and your reservation here.</p>
						</div>
						<?php foreach ( $rows as $r ) : ?>
							<div class="mtl-res-detail-panel" id="<?php echo esc_attr( 'res-tool-' . (int) $r->reservation_id ); ?>" tabindex="-1">
								<?php echo mtl_render_reservation_detail_panel( $r, $self ); ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php
		// Fundraising ask, last on the page so it sits under My Reservations
		// in every state: the list, the empty state, and both cancel
		// confirmations. Deliberately below a confirmation prompt rather than
		// above it, so it never pushes a destructive choice down the page.
		// Escaped inside mtl_giving_section_html(); returns '' when the admin
		// has left the message blank on the Setup page.
		echo mtl_giving_section_html();
		?>
	</div>
	<?php
	$body = ob_get_clean();

	mtl_render_front_shell( 'My Loans & Reservations', $body, mtl_member_page_footer() );
}

// --------------------------------------------------------------------------
// Account page  (mtl_page=account)
// --------------------------------------------------------------------------

/**
 * Renders the member's Account page and handles its profile-update POST.
 */
function mtl_render_account_page() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'login' ) );
		exit;
	}

	$member = mtl_current_member();
	if ( ! $member ) {
		mtl_render_member_only_notice( 'Account', mtl_current_member_link_broken() );
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

	// Agreements state, carried down to the render below.
	$agreement_ticked   = array();
	$agreement_invalid  = array();
	$agreement_missing  = array();
	$agreement_changed  = false;
	$agreement_errors   = array();
	$agreement_recorded = false;

	// --- Handle the agreements submission (POST + its own nonce). ---
	//
	// Fires only on its own submit button and nonce, so a member fixing their
	// phone number is never told they failed to tick an agreement. No PRG
	// redirect, since the page re-rendered here is already the one showing the
	// result.
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
				$mtl_names = array();
				foreach ( $check['missing'] as $mtl_missed ) {
					$mtl_names[] = wp_strip_all_tags( mtl_agreement_excerpt( $mtl_missed->agreement_text ) );
				}
				$agreement_errors[] = __( 'You must agree to:', 'my-tool-library' ) . ' ' . implode( '; ', $mtl_names );
			} else {
				// One row per ticked agreement; earlier rows are untouched.
				//
				// No rollback here, unlike signup: whatever failed stays
				// outstanding and the member is shown what remains.
				//
				// Ids are kept as written rather than re-derived afterwards;
				// accepted_at has one-second resolution, so a member agreeing in
				// the same second staff record something would be emailed both.
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
					// Confirm on screen. The form vanishing and its items
					// reappearing further down reads as a failed reload, and
					// the emailed copy may arrive late or not at all.
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
				// Verification is tied to the address on file, so any change
				// to any of the 6 address fields invalidates a prior verification.
				$address_changed = (
					trim( $address1 ) !== trim( (string) $member->address_line1 )
					|| trim( $address2 ) !== trim( (string) $member->address_line2 )
					|| trim( $city ) !== trim( (string) $member->city )
					|| trim( $state ) !== trim( (string) $member->state )
					|| trim( $zip_code ) !== trim( (string) $member->zip_code )
					|| trim( $country ) !== trim( (string) $member->country )
				);
				$was_verified    = mtl_member_is_verified( $member->member_id );

				$wpdb->update(
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

				// Keep the WordPress profile name in step with the member row.
				wp_update_user(
					array(
						'ID'           => get_current_user_id(),
						'first_name'   => $first,
						'last_name'    => $last,
						'display_name' => trim( $first . ' ' . $last ),
					)
				);

				$removed_verif = false;
				if ( $address_changed && $was_verified ) {
					$wpdb->delete( $tbl_verif, array( 'member_id' => (int) $member->member_id ), array( '%d' ) );
					$removed_verif = true;
				}

				wp_safe_redirect(
					add_query_arg(
						'mtl_msg',
						$removed_verif ? 'account_verif_removed' : 'account_updated',
						mtl_front_page_url( 'account' )
					)
				);
				exit;
			}
		}
	}

	// --- Handle "Delete Account and Remove Personal Data" (POST + nonce). ---
	// The confirmation step is the GET link to ?mtl_confirm_delete=1 below
	// (this page has no JavaScript, so there's no confirm() dialog), this
	// handler only runs on the follow-up POST from that confirmation form.
	if ( mtl_is_post_request() && isset( $_POST['mtl_delete_account'] ) ) {
		if ( ! isset( $_POST['mtl_delete_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_account_nonce'] ) ), 'mtl_delete_account_action' ) ) {
			$errors[] = 'Your session expired. Please try again.';
		} else {
			// 'member': the emails this sends say the member did this
			// themselves, not that staff did it for them.
			mtl_delete_or_anonymize_member( (int) $member->member_id, 'member' );
			// wp_delete_user() alone doesn't end the current request's own
			// session, so log out explicitly before redirecting somewhere
			// that doesn't require being signed in.
			wp_logout();
			wp_safe_redirect( add_query_arg( 'mtl_msg', 'account_deleted', mtl_front_page_url( 'main' ) ) );
			exit;
		}
	}

	$is_verified    = mtl_member_is_verified( $member->member_id );
	$user           = wp_get_current_user();
	$confirm_delete = isset( $_GET['mtl_confirm_delete'] ) && '1' === $_GET['mtl_confirm_delete'];
	// Splits the stored "+<code> <national number>" value back into the two
	// pieces the phone widget needs to prefill. Matches this form's existing
	// pattern of always rendering from the DB row rather than sticky POST
	// values (see first_name/last_name/etc. below), so a failed save reverts
	// the phone field too, same as every other field on this form.
	$phone_parsed = mtl_parse_stored_phone_number( $member->phone_number );
	// Admin-editable via the Setup page; blank hides it entirely (see the
	// update_option() comment in setup-page.php for why blank stays blank).
	// The fallback text here matches setup-page.php's default exactly, so a
	// fresh install shows sensible copy before any admin has saved Setup.
	$verification_directions = trim(
		(string) get_option(
			'mtl_verification_directions',
			'A government issued ID and proof of address are required to become a verified member and to check out tools. Stop by our office to verify membership.'
		)
	);
	// Optional admin-uploaded image (Setup page) shown instead of the plain
	// green "Verified" pill below, once this member is verified.
	$verified_badge_image_url = trim( (string) get_option( 'mtl_verified_badge_image_url', '' ) );

	// Past + current loans for this member.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$loans = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.loan_id, l.loan_date, l.due_date, l.return_date, t.tool_name
         FROM {$tbl_loans} l
         JOIN {$tbl_inv} t ON t.tool_id = l.tool_id
         WHERE l.member_id = %d
         ORDER BY l.loan_date DESC",
			(int) $member->member_id
		)
	);
	// Trainings this member has completed. Read-only here, since only staff can
	// record a training (see the admin Membership page); this is purely so the
	// member can see which tools they're already qualified to use.
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

	// Split once, used twice: the badges near the top of the page show only
	// what this member is CURRENTLY certified in, while the collapsible table
	// further down lists everything they have ever completed, expired
	// included. badge_image_url is admin-set on the Setup page; a current
	// training with none set falls back to the plain green pill.
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

	// What deleting this account would cost the member, for the confirmation
	// view: whether they have any history at all (which makes the delete an
	// anonymize rather than a full removal), and whether an active reservation
	// would be cancelled, called out separately there, being a more
	// immediate, concrete consequence than the general history note. Only the
	// confirmation view asks, so the count stays off the ordinary page load.
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

	ob_start();
	echo mtl_member_page_styles();
	?>
	<div class="mtl-member-wrap">
		<a class="mtl-member-back" href="<?php echo esc_url( mtl_front_page_url( 'main' ) ); ?>">&larr; Back to the tool catalog</a>

		<?php echo mtl_front_notice_html(); ?>
		<?php echo mtl_agreements_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>

		<?php if ( ! empty( $errors ) ) : ?>
			<div class="mtl-front-notice mtl-front-notice-error">
				<?php foreach ( $errors as $e ) : ?>
					<div><?php echo esc_html( $e ); ?></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $confirm_delete ) : ?>
			<div class="mtl-member-card">
				<h2>Delete Account and Remove Personal Data</h2>
				<?php
				// "Are you sure?" for account deletion, reached by the
				// plain Danger Zone link below; the delete only happens
				// when the member submits this POST form. This replaces
				// the rest of the page rather than sitting alongside it,
				// same as the "cancel reservation(s)" confirm views.
				?>
				<p style="margin-top:0;">This permanently deletes your account and cannot be undone.</p>
				<p>Your name, address, contact details and verification documents will all be removed, and your sign-in will be deleted entirely, so you will not be able to log in again.</p>
				<?php if ( $has_history ) : ?>
					<p>Your borrowing record is kept, but with nothing identifying you attached to it: past loans and reservations stay on file against a &ldquo;former member&rdquo; so the library&rsquo;s tool histories and totals remain accurate.</p>
				<?php endif; ?>
				<?php if ( $has_active_reservation ) : ?>
					<p>You currently have an active reservation. It will be cancelled as part of deleting your account.</p>
				<?php endif; ?>
				<div class="mtl-member-confirm-actions">
					<form method="post" action="<?php echo esc_url( mtl_front_page_url( 'account' ) ); ?>" style="margin:0;">
						<?php wp_nonce_field( 'mtl_delete_account_action', 'mtl_delete_account_nonce' ); ?>
						<button type="submit" name="mtl_delete_account" value="1" class="mtl-member-btn mtl-member-btn-danger">Yes, Permanently Delete My Account</button>
					</form>
					<a class="mtl-member-btn mtl-member-btn-ghost" href="<?php echo esc_url( mtl_front_page_url( 'account' ) ); ?>">No, keep my account</a>
				</div>
			</div>
		<?php else : ?>

			<?php
			// --- Member agreements ---
			//
			// Computed once for the outstanding block here and the receipt at
			// the foot of the page, then partitioned between them. A superseded
			// acceptance is still an acceptance row, so listing it unfiltered
			// would show the same agreement twice: once to agree to, once as
			// already agreed.
			$mtl_ag_outstanding     = mtl_agreements_online() ? mtl_member_outstanding_agreements( (int) $member->member_id ) : array();
			$mtl_ag_acceptances     = mtl_agreements_tracking() ? mtl_get_member_acceptances( (int) $member->member_id ) : array();
			$mtl_ag_outstanding_ids = array_map( 'intval', wp_list_pluck( $mtl_ag_outstanding, 'agreement_id' ) );

			// An outstanding agreement never also appears as a plain tick. Its
			// earlier acceptance becomes the "you agreed to an earlier version"
			// line on that row, next to the thing being asked for.
			$mtl_ag_superseded  = array();
			$mtl_ag_receipt     = array();
			$mtl_ag_retired_rec = array();
			foreach ( $mtl_ag_acceptances as $mtl_acceptance ) {
				$mtl_aid = (int) $mtl_acceptance->agreement_id;
				if ( in_array( $mtl_aid, $mtl_ag_outstanding_ids, true ) ) {
					$mtl_ag_superseded[ $mtl_aid ] = sprintf(
						/* translators: %s: date the member agreed to the earlier version. */
						__( 'You agreed to an earlier version of this on %s.', 'my-tool-library' ),
						wp_strip_all_tags( mtl_format_utc_datetime( $mtl_acceptance->accepted_at, 'j F Y' ) )
					);
					continue;
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
				<!-- Above the account detail rows: members arrive here from the
					reserve gate or an email to do this one thing, and a notice
					pointing at a form further down the page is easy to miss. -->
				<div class="mtl-member-card" id="mtl-agreements">
					<h2>Member agreements</h2>

					<?php if ( ! empty( $agreement_errors ) ) : ?>
						<div class="mtl-front-notice mtl-front-notice-error" style="max-width:none;">
							<?php foreach ( $agreement_errors as $mtl_ag_err ) : ?>
								<div><?php echo wp_kses_post( $mtl_ag_err ); ?></div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php echo mtl_render_agreements_error_summary( $agreement_missing, 'mtl-ac-agreement' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the renderer. ?>

					<form method="post" action="<?php echo esc_url( mtl_front_page_url( 'account' ) ); ?>#mtl-agreements">
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
						<p style="margin: 0;">
							<button type="submit" name="mtl_agree" value="1" class="mtl-member-btn">Agree</button>
						</p>
					</form>
				</div>
			<?php elseif ( $agreement_recorded ) : ?>
				<!-- role="status" so the change is announced: the form vanishing
					and its items reappearing further down the page is otherwise
					a purely visual cue, and reads as a failed reload. -->
				<div class="mtl-front-notice mtl-front-notice-success" role="status">
					Thank you. Your agreement has been recorded.
				</div>
			<?php endif; ?>

			<div class="mtl-member-card">
				<h2>My Account</h2>
				<p style="margin-top:0;">
					Membership status:
					<?php if ( $is_verified && '' !== $verified_badge_image_url ) : ?>
						<img class="mtl-badge-img mtl-verified-badge-img" src="<?php echo esc_url( $verified_badge_image_url ); ?>" alt="Verified" title="Verified">
					<?php elseif ( $is_verified ) : ?>
						<span class="mtl-pill mtl-pill-green">Verified</span>
					<?php else : ?>
						<span class="mtl-pill mtl-pill-grey">Not yet verified</span>
					<?php endif; ?>
				</p>
				<?php if ( ! $is_verified && '' !== $verification_directions ) : ?>
					<p class="mtl-member-hint" style="font-size:0.9em;"><?php echo nl2br( esc_html( $verification_directions ) ); ?></p>
				<?php endif; ?>

				<p style="margin-top:0;">
					Trainings completed:
					<?php if ( ! empty( $my_current_trainings ) ) : ?>
						<?php foreach ( $my_current_trainings as $mtl_training ) : ?>
							<?php if ( '' !== $mtl_training['badge'] ) : ?>
								<img class="mtl-badge-img mtl-training-badge-img" src="<?php echo esc_url( $mtl_training['badge'] ); ?>" alt="<?php echo esc_attr( $mtl_training['name'] ); ?>" title="<?php echo esc_attr( $mtl_training['name'] ); ?>">
							<?php else : ?>
								<span class="mtl-pill mtl-pill-green" style="margin-left:6px;"><?php echo esc_html( $mtl_training['name'] ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php else : ?>
						<span class="mtl-pill mtl-pill-grey" style="margin-left:6px;">None yet</span>
					<?php endif; ?>
				</p>
				<p class="mtl-member-hint" style="font-size:0.9em;">
					Trainings are recorded by library staff and show which tools you&rsquo;re qualified to use. Ask a staff member if you&rsquo;d like to take one.
					<?php if ( count( $my_trainings ) > count( $my_current_trainings ) ) : ?>
						Only trainings that are still current are shown here. See <strong>Trainings</strong> below for your full record.
					<?php endif; ?>
				</p>
			</div>

			<?php if ( ! empty( $my_trainings ) ) : ?>
				<details class="mtl-member-card">
					<summary class="mtl-member-summary">Trainings</summary>
					<div class="mtl-member-collapsible-body">
						<p class="mtl-member-hint" style="margin-top:0;">Every training you&rsquo;ve completed, including any that have since expired. Ask library staff if you&rsquo;d like to retake one.</p>
						<table class="mtl-member-table">
							<thead>
								<tr>
									<th>Training</th>
									<th>Completed</th>
									<th>Valid For</th>
									<th>Expires</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $my_trainings as $mtl_training ) : ?>
									<tr>
										<td><?php echo esc_html( $mtl_training['name'] ); ?></td>
										<td><?php echo mtl_format_date( $mtl_training['start_date'] ); ?></td>
										<td>
											<?php
											echo $mtl_training['months'] > 0
												? esc_html( $mtl_training['months'] . ' month' . ( 1 === $mtl_training['months'] ? '' : 's' ) )
												: '<span style="color:#8c8f94;">&mdash;</span>';
											?>
										</td>
										<td><?php echo '' !== $mtl_training['expiry_date'] ? mtl_format_date( $mtl_training['expiry_date'] ) : '<span style="color:#8c8f94;">Never</span>'; ?></td>
										<td>
											<?php if ( $mtl_training['is_current'] ) : ?>
												<span class="mtl-pill mtl-pill-green">Current</span>
											<?php else : ?>
												<span class="mtl-pill mtl-pill-grey">Expired</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</details>
			<?php endif; ?>

			<?php
			// Fundraising ask, above Your details. Escaped inside
			// mtl_giving_section_html(); returns '' when the admin has left
			// both the message and the link blank on the Setup page.
			echo mtl_giving_section_html();
			?>

			<?php
			// Collapsed by default, but forced open when a submitted edit
			// just failed validation; otherwise the error banner above
			// would point at a form the member can no longer see.
			?>
			<details class="mtl-member-card" <?php echo ! empty( $errors ) ? 'open' : ''; ?>>
				<summary class="mtl-member-summary">Your details</summary>
				<div class="mtl-member-collapsible-body">
				<form method="post" action="<?php echo esc_url( mtl_front_page_url( 'account' ) ); ?>">
					<?php wp_nonce_field( 'mtl_account_action', 'mtl_account_nonce' ); ?>

					<div class="mtl-member-row">
						<div class="mtl-member-field">
							<label for="mtl-ac-first">First name</label>
							<input type="text" id="mtl-ac-first" name="first_name" value="<?php echo esc_attr( stripslashes( $member->first_name ) ); ?>" required>
						</div>
						<div class="mtl-member-field">
							<label for="mtl-ac-last">Last name</label>
							<input type="text" id="mtl-ac-last" name="last_name" value="<?php echo esc_attr( stripslashes( $member->last_name ) ); ?>" required>
						</div>
					</div>

					<div class="mtl-member-field">
						<label for="mtl-ac-email">Email (username)</label>
						<input type="email" id="mtl-ac-email" value="<?php echo esc_attr( $user->user_email ); ?>" readonly>
						<p class="mtl-member-hint">Your email is your sign-in username. To change it, contact library staff.</p>
					</div>

					<div class="mtl-member-field">
						<label for="mtl-ac-phone_national">Phone number</label>
						<?php mtl_render_phone_input( $phone_parsed['iso'], $phone_parsed['national'], 'mtl-ac-' ); ?>
					</div>

					<div class="mtl-member-field">
						<label for="mtl-ac-address1">Address</label>
						<input type="text" id="mtl-ac-address1" name="address_line1" value="<?php echo esc_attr( stripslashes( $member->address_line1 ) ); ?>" required>
					</div>

					<div class="mtl-member-field">
						<label for="mtl-ac-address2">Address line 2 <span style="font-weight:normal;">(optional)</span></label>
						<input type="text" id="mtl-ac-address2" name="address_line2" value="<?php echo esc_attr( stripslashes( (string) $member->address_line2 ) ); ?>">
					</div>

					<div class="mtl-member-row">
						<div class="mtl-member-field">
							<label for="mtl-ac-city">City</label>
							<input type="text" id="mtl-ac-city" name="city" value="<?php echo esc_attr( stripslashes( $member->city ) ); ?>" required>
						</div>
						<div class="mtl-member-field">
							<label for="mtl-ac-state">State / Province</label>
							<select id="mtl-ac-state" name="state" required>
								<?php foreach ( mtl_get_state_options() as $mtl_state_code => $mtl_state_label ) : ?>
									<option value="<?php echo esc_attr( $mtl_state_code ); ?>" <?php selected( $member->state, $mtl_state_code ); ?>><?php echo esc_html( $mtl_state_label ); ?> (<?php echo esc_html( $mtl_state_code ); ?>)</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

					<div class="mtl-member-row">
						<div class="mtl-member-field">
							<label for="mtl-ac-zip">ZIP code</label>
							<input type="text" id="mtl-ac-zip" name="zip_code" value="<?php echo esc_attr( $member->zip_code ); ?>" required>
						</div>
						<div class="mtl-member-field">
							<label for="mtl-ac-country">Country</label>
							<select id="mtl-ac-country" name="country" required>
								<?php foreach ( mtl_get_country_options() as $mtl_country_name ) : ?>
									<option value="<?php echo esc_attr( $mtl_country_name ); ?>" <?php selected( $member->country, $mtl_country_name ); ?>><?php echo esc_html( $mtl_country_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<p class="mtl-member-hint"><strong>Note:</strong> changing your address will reset your verified status, and staff will need to re-verify your account.</p>

					<p style="margin: 18px 0 0 0;">
						<button type="submit" name="mtl_update_account" value="1" class="mtl-member-btn">Save Changes</button>
					</p>
				</form>
				</div>
			</details>

			<details class="mtl-member-card">
				<summary class="mtl-member-summary">Your loan history</summary>
				<div class="mtl-member-collapsible-body">
				<?php if ( empty( $loans ) ) : ?>
					<p class="mtl-member-empty">You don&rsquo;t have any loans on record yet.</p>
				<?php else : ?>
					<table class="mtl-member-table">
						<thead>
							<tr>
								<th>Tool</th>
								<th>Borrowed</th>
								<th>Due</th>
								<th>Returned</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$today = current_time( 'Y-m-d' );
							foreach ( $loans as $l ) :
								$is_returned = ! empty( $l->return_date );
								if ( ! $is_returned ) {
									$overdue      = ( $l->due_date < $today );
									$status_class = $overdue ? 'mtl-pill-red' : 'mtl-pill-amber';
									$status_label = $overdue ? 'Overdue' : 'On loan';
								} elseif ( gmdate( 'Y-m-d', strtotime( $l->return_date ) ) > $l->due_date ) {
									// Compare on the DATE portion only: return_date is a
									// full timestamp, due_date stays a plain date, so a
									// raw > comparison would wrongly call anything
									// returned after 00:00 on the due date "late".
									$status_class = 'mtl-pill-grey';
									$status_label = 'Returned late';
								} else {
									$status_class = 'mtl-pill-green';
									$status_label = 'Returned';
								}
								?>
								<tr>
									<td><?php echo esc_html( stripslashes( $l->tool_name ) ); ?></td>
									<td><?php echo mtl_format_date( $l->loan_date ); ?></td>
									<td><?php echo mtl_format_date( $l->due_date ); ?></td>
									<td><?php echo $is_returned ? mtl_format_date( $l->return_date ) : '<span style="color:#8c8f94;">&mdash;</span>'; ?></td>
									<td><span class="mtl-pill <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				</div>
			</details>

			<?php if ( $mtl_ag_receipt ) : ?>
				<div class="mtl-member-card">
					<!-- Not tied to account creation: a re-accepted agreement
						carries a date later than the member's signup date, so a
						heading naming signup would be wrong on those rows. -->
					<h3 style="margin-top:0;">You have agreed to the following:</h3>
					<ul class="mtl-agreement-receipt">
						<?php foreach ( $mtl_ag_receipt as $mtl_acceptance ) : ?>
							<?php
							// Every value shown here comes from the acceptance
							// row. Reading the live agreement instead would show
							// the member wording they never saw, which is the
							// one thing this table's design exists to prevent.
							$mtl_is_retired = in_array( (int) $mtl_acceptance->agreement_id, $mtl_ag_retired_ids, true );

							// Drop the link where the attachment has since been
							// deleted, so the member gets the text rather than
							// a 404. One lookup per attached document, on one
							// member's own page.
							$mtl_file_live = ! empty( $mtl_acceptance->file_url )
								&& attachment_url_to_postid( $mtl_acceptance->file_url ) > 0;
							?>
							<li>
								<span class="mtl-agreement-tick" aria-hidden="true">&#10003;</span>
								<div>
									<div><?php echo nl2br( esc_html( $mtl_acceptance->agreement_text ) ); ?></div>
									<p class="mtl-agreement-meta">
										Agreed <?php echo wp_kses_post( mtl_format_utc_datetime( $mtl_acceptance->accepted_at, 'j F Y' ) ); ?>
										<?php if ( $mtl_file_live ) : ?>
											&middot;
											<a href="<?php echo esc_url( $mtl_acceptance->file_url ); ?>" target="_blank" rel="noopener noreferrer"
												aria-label="<?php echo esc_attr( 'View the document you agreed to for &ldquo;' . wp_strip_all_tags( mtl_agreement_excerpt( $mtl_acceptance->agreement_text ) ) . '&rdquo; (opens in a new tab)' ); ?>"><?php esc_html_e( 'View the attached document', 'my-tool-library' ); ?> \&#8599;</a>
										<?php endif; ?>
										<?php if ( $mtl_is_retired ) : ?>
											&middot; <span class="mtl-agreement-retired-note"><?php esc_html_e( 'no longer required', 'my-tool-library' ); ?></span>
										<?php endif; ?>
									</p>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="mtl-member-card">
				<h3 style="margin-top:0;">Danger Zone</h3>
				<p class="mtl-member-hint">Deleting your account removes your personal information from our system. This cannot be undone.</p>
				<a class="mtl-member-btn mtl-member-btn-danger" href="<?php echo esc_url( add_query_arg( 'mtl_confirm_delete', '1', mtl_front_page_url( 'account' ) ) ); ?>">Delete Account and Remove Personal Data</a>
			</div>

		<?php endif; ?>
	</div>
	<?php
	mtl_phone_formatter_script();
	$body = ob_get_clean();

	mtl_render_front_shell( 'My Account', $body, mtl_member_page_footer() );
}

/**
 * Shown when someone logged in but not a member (e.g. an admin) opens a
 * member-only page.
 *
 * A member whose record could not be matched to their sign-in gets different
 * wording: telling them their account "isn't a member account" would be
 * plainly wrong, and sends them to support describing it as a bug rather than
 * as something staff can fix in a minute (see mtl_current_member()).
 *
 * @param string $page_title  Page title to display.
 * @param bool   $link_broken True when the visitor is a member whose record
 *                            could not be matched, rather than a non-member.
 * @return void Outputs the page directly (via mtl_render_front_shell()) and exits.
 */
function mtl_render_member_only_notice( $page_title, $link_broken = false ) {
	ob_start();
	echo mtl_member_page_styles();
	?>
	<div class="mtl-member-wrap">
		<div class="mtl-member-card">
			<h2><?php echo esc_html( $page_title ); ?></h2>
			<?php if ( $link_broken ) : ?>
				<p>You&rsquo;re signed in, but we couldn&rsquo;t match your sign-in to a membership record, so there&rsquo;s nothing to show here yet.</p>
				<p>This usually means the library&rsquo;s records were rebuilt recently. Please contact library staff, who can reconnect your account, and nothing about your membership has been lost.</p>
			<?php else : ?>
				<p>This area is for tool-library member accounts. You&rsquo;re signed in, but your account isn&rsquo;t a member account, so there&rsquo;s nothing to show here.</p>
			<?php endif; ?>
			<p style="margin-bottom:0;"><a href="<?php echo esc_url( mtl_front_page_url( 'main' ) ); ?>">&larr; Back to the tool catalog</a></p>
		</div>
	</div>
	<?php
	$body = ob_get_clean();

	mtl_render_front_shell( $page_title, $body );
}
