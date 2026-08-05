<?php
/**
 * Public member accounts (server-side rendered, no JavaScript required).
 *
 * Public-facing member signup, sign-in, and account management -- plain GET
 * pages and POST forms, matching the public shop page (public/shop-page.php).
 *
 *   mtl_page=signup        -- create a member account
 *   mtl_page=reservations  -- "My Loans & Reservations": active loans with a
 *                              due-soon/overdue status, reservation queue,
 *                              place in line, cancel
 *   mtl_page=account       -- profile, verification status, past loans, edits
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
 * Get the {prefix}members row for the logged-in user, but only when that row
 * can be shown to belong to them.
 *
 * The stored mtl_member_id is treated as a cache rather than proof. It is an
 * AUTO_INCREMENT value that restarts at 1 every time the Setup page rebuilds
 * the tables, while WordPress accounts survive that reset untouched -- so a
 * surviving sign-in can be left pointing at a row that now belongs to someone
 * else entirely. Returning it would hand a stranger another member's name,
 * address, phone number and loan history, with edit and delete over the
 * record. So the row's email must match the signed-in account's before it is
 * trusted; if it doesn't, the account's own email is used to find the right
 * row and the stored id is repaired. If nothing matches, this returns null
 * and the caller shows the "we couldn't match your record" notice --
 * deliberately failing closed, since being locked out is recoverable and
 * disclosure isn't. See mtl_current_member_link_broken().
 *
 * Cached per-request since several places (shop nav, reserve handling) ask
 * for it on a single page load; safe because every write to the member row is
 * followed by a redirect, so the cache can never go stale mid-request -- and
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

	// Stale or mismatched link. Re-resolve on the account's own address --
	// members.email is UNIQUE, so this matches at most one row. Anonymized
	// rows carry a reserved .invalid address and can never match a real one.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$recovered = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$tbl} WHERE email = %s AND anonymized_at IS NULL LIMIT 1", $user_email )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $recovered ) {
		// Assigned BEFORE the meta write: update_user_meta() fires actions, and
		// a hook that called back in here would otherwise find $resolved
		// already true with $member still null.
		$member = $recovered;
		update_user_meta( $user_id, 'mtl_member_id', (int) $recovered->member_id );
		return $member;
	}

	// Nothing proved this row belongs to this account -- fail closed.
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
 * so both must be present -- not just the row -- to count as verified. See
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
		'account_verif_removed'  => array( 'success', 'Your details were updated. Because your address changed, your verified status has been reset &mdash; an administrator will need to re-verify your account.' ),
		'account_deleted'        => array( 'success', 'Your account and personal data have been deleted. You&rsquo;re welcome to browse the catalog, but you&rsquo;ll need to sign up again if you&rsquo;d like to reserve a tool.' ),
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
		. $notice[1] // From the fixed map above -- safe, pre-escaped copy.
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

		/* Admin-editable informational copy (Setup page) -- visually distinct
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

		/* <details>-based cards (Your details, Your loan history): collapsed by
			default, no JavaScript required to open them. */
		details.mtl-member-card {
			padding: 0;
		}

		.mtl-member-summary {
			display: block;
			cursor: pointer;
			padding: 22px 24px;
			font-weight: 600;
			font-size: 1.05em;
			outline: none;
		}

		details.mtl-member-card[open] .mtl-member-summary {
			padding-bottom: 10px;
		}

		.mtl-member-collapsible-body {
			padding: 0 24px 22px 24px;
		}

		/* Admin-uploaded badge images (training/verified) -- small and inline,
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

		/* Availability badges + category/tag pills, mirrored from the shop's
			detail box (mtl_shop_status_badges() / mtl_shop_pills(), reused here)
			so this detail view matches the catalog. */
		.mtl-shop-badges {
			display: flex;
			flex-wrap: wrap;
			gap: 5px;
			margin-top: 2px;
		}

		.mtl-shop-badge {
			display: inline-block;
			border-radius: 999px;
			padding: 1px 9px;
			font-size: 0.74em;
			font-weight: 600;
			white-space: nowrap;
		}

		.mtl-shop-badge-avail {
			background: #edf7ed;
			color: #1e7e34;
			border: 1px solid #bfe3c0;
		}

		.mtl-shop-badge-out {
			background: #fff4e5;
			color: #b45309;
			border: 1px solid #f2cfa0;
		}

		.mtl-shop-badge-res {
			background: #eaf3fb;
			color: #135e96;
			border: 1px solid #b9d7ef;
		}

		.mtl-shop-pill {
			display: inline-block;
			background: #f0f1f2;
			color: #50575e;
			border-radius: 12px;
			padding: 1px 9px;
			margin: 2px 3px 0 0;
			font-size: 0.76em;
		}
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
// Reserve a tool  (POST from the shop page's detail panel)
// --------------------------------------------------------------------------

/**
 * Called from mtl_render_front_main_page() before any output, so it can
 * finish with a Post/Redirect/Get back to the catalog (no double-submit on
 * refresh). Does nothing unless this request is actually a reserve POST.
 */
function mtl_handle_reserve_action() {
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'POST' !== $request_method || ! isset( $_POST['mtl_action'] ) ) {
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
	// Already signed in -- nothing to sign up for.
	if ( is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'main' ) );
		exit;
	}

	global $wpdb;
	$errors = array();
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

	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'POST' === $request_method && isset( $_POST['mtl_signup'] ) ) {
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
			// Passwords are unslashed but NOT sanitized -- altering the
			// characters would silently change the member's chosen password.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$password = (string) wp_unslash( $_POST['password'] ?? '' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$password2 = (string) wp_unslash( $_POST['password2'] ?? '' );

			// The single source of truth for what gets stored -- see
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
			} elseif ( email_exists( $vals['email'] ) ) {
				$errors[] = 'An account with that email already exists. Try signing in instead.';
			} else {
				$tbl_members = $wpdb->prefix . 'members';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				$dupe = $wpdb->get_var( $wpdb->prepare( "SELECT member_id FROM {$tbl_members} WHERE email = %s", $vals['email'] ) );
				if ( $dupe ) {
					$errors[] = 'An account with that email already exists. Try signing in instead.';
				}
			}
			if ( strlen( $password ) < MTL_MIN_PASSWORD_LENGTH ) {
				$errors[] = 'Your password must be at least ' . (int) MTL_MIN_PASSWORD_LENGTH . ' characters long.';
			} elseif ( $password !== $password2 ) {
				$errors[] = 'The two passwords you entered do not match.';
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
						// Not escaped here -- $errors members are escaped once,
						// at render time (see the esc_html($e) loop below).
						$errors[] = 'Sorry, that account could not be created: ' . $user_id->get_error_message();
					} else {
						update_user_meta( $user_id, 'mtl_member_id', $member_id );

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
					<div class="mtl-member-field">
						<label for="mtl-su-email">Email address</label>
						<input type="email" id="mtl-su-email" name="email" value="<?php echo esc_attr( $vals['email'] ); ?>" required>
						<p class="mtl-member-hint">This is also your username for signing in.</p>
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
 * shop's badge/pill helpers so it matches the catalog's detail box.
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
			// library can set the hold period to 0 (never expires) -- the tool
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

		<?php if ( ! empty( $r->categories ) ) : ?>
			<h4>Categories</h4>
			<div><?php echo mtl_shop_pills( $r->categories ); ?></div>
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

		<a class="mtl-member-btn mtl-member-btn-danger" style="margin-top:16px;" href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'mtl_confirm' => 'one',
					'rid'         => (int) $r->reservation_id,
				),
				$self_url
			)
		);
		?>
																						">Cancel this reservation</a>
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
	$tbl_res     = $wpdb->prefix . 'tool_reservations';
	$tbl_inv     = $wpdb->prefix . 'tool_inventory';
	$tbl_loans   = $wpdb->prefix . 'loans';
	$tbl_cats    = $wpdb->prefix . 'tool_categories';
	$tbl_cat_map = $wpdb->prefix . 'tool_category_mappings';
	$tbl_tags    = $wpdb->prefix . 'tool_tags';
	$tbl_tag_map = $wpdb->prefix . 'tool_tag_mappings';
	$self        = mtl_front_page_url( 'reservations' );

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
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'POST' === $request_method && isset( $_POST['mtl_action'] ) ) {
		$action = sanitize_key( wp_unslash( $_POST['mtl_action'] ) );
		$valid  = isset( $_POST['mtl_res_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_res_nonce'] ) ), 'mtl_res_action' );

		// Cancelling doesn't delete the row -- it closes the reservation by
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
	// reservation_date, ties broken by reservation_id) -- the same
	// derivation the admin Loans & Reservations page uses. ---
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT r.reservation_id, r.tool_id, r.reservation_date, r.ready_since,
                t.tool_name, t.brand, t.description, t.components, t.photo_url,
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

		<?php
		// Always shown (with an empty-state message when there's nothing
		// checked out), same as the Reservations section below -- so the
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
			// (right) revealed via the CSS :target pseudo-class -- clicking
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
											?>
											<a class="mtl-member-btn mtl-member-btn-danger" style="padding:5px 12px; font-size:0.85em;" href="
											<?php
											echo esc_url(
												add_query_arg(
													array(
														'mtl_confirm' => 'one',
														'rid' => (int) $r->reservation_id,
													),
													$self
												)
											);
											?>
																																				">Cancel</a>
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

	// --- Handle profile update (POST + nonce), then PRG-redirect. ---
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'POST' === $request_method && isset( $_POST['mtl_update_account'] ) ) {
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
	// (this page has no JavaScript, so there's no confirm() dialog) -- this
	// handler only runs on the follow-up POST from that confirmation form.
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'POST' === $request_method && isset( $_POST['mtl_delete_account'] ) ) {
		if ( ! isset( $_POST['mtl_delete_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_account_nonce'] ) ), 'mtl_delete_account_action' ) ) {
			$errors[] = 'Your session expired. Please try again.';
		} else {
			mtl_delete_or_anonymize_member( (int) $member->member_id );
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
	// values (see first_name/last_name/etc. below) -- a failed save reverts
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
	// Trainings this member has completed. Read-only here -- only staff can
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

	// Whether deleting this account will anonymize (history on record) or
	// fully remove it -- shown on the delete-confirmation view below.
	$tbl_res     = $wpdb->prefix . 'tool_reservations';
	$has_history = ! empty( $loans ) || (bool) $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT 1 FROM {$tbl_res} WHERE member_id = %d LIMIT 1",
			(int) $member->member_id
		)
	);
	// Whether deletion will cancel a currently-active reservation -- called
	// out separately on the confirm view since it's a more immediate,
	// concrete consequence than the general history note above.
	$has_active_reservation = (bool) $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT 1 FROM {$tbl_res} WHERE member_id = %d AND expiry_date IS NULL LIMIT 1",
			(int) $member->member_id
		)
	);

	ob_start();
	echo mtl_member_page_styles();
	?>
	<div class="mtl-member-wrap">
		<a class="mtl-member-back" href="<?php echo esc_url( mtl_front_page_url( 'main' ) ); ?>">&larr; Back to the tool catalog</a>

		<?php echo mtl_front_notice_html(); ?>

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
				// "Are you sure?" for account deletion -- reached by the
				// plain Danger Zone link below; the delete only happens
				// when the member submits this POST form. This replaces
				// the rest of the page rather than sitting alongside it,
				// same as the "cancel reservation(s)" confirm views.
				?>
				<p style="margin-top:0;">This permanently deletes your account and cannot be undone.</p>
				<p>Your name, address, contact details and verification documents will all be removed, and your sign-in will be deleted entirely &mdash; you will not be able to log in again.</p>
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
						Only trainings that are still current are shown here &mdash; see <strong>Trainings</strong> below for your full record.
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
			// Collapsed by default, but forced open when a submitted edit
			// just failed validation -- otherwise the error banner above
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
							foreach ( $loans as $l ) :
								$today       = current_time( 'Y-m-d' );
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
				<p>This usually means the library&rsquo;s records were rebuilt recently. Please contact library staff &mdash; they can reconnect your account, and nothing about your membership has been lost.</p>
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
