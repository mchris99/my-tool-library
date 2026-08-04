<?php
/**
 * Plugin Name: My Tool Library
 * Plugin URI: https://mkelibrary.org
 * Description: An open-source WordPress plugin for Tool Librarians to manage their membership and inventory in one place.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Milwaukee Tool Library (Evan Maruszewski & Chris McHenry)
 * Author URI: https://mkelibrary.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: my-tool-library
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Define the plugin directory path if not already defined.
if ( ! defined( 'MTL_PLUGIN_DIR' ) ) {
	define( 'MTL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Formats a MySQL date/datetime string for display (default MM/DD/YYYY).
 * Display-only: `<input type="date">` values and JS-sortable `data-*`
 * attributes must stay in ISO YYYY-MM-DD and never go through this.
 *
 * @param string $value  Any date/datetime string MySQL would return.
 * @param string $format PHP date() format.
 * @return string Formatted date, or an em dash if $value is empty/unparseable.
 */
function mtl_format_date( $value, $format = 'm/d/Y' ) {
	if ( empty( $value ) ) {
		return '&mdash;';
	}
	$ts = strtotime( $value );
	return $ts ? esc_html( gmdate( $format, $ts ) ) : '&mdash;';
}

/**
 * Builds a member's postal address as two lines: street (plus unit, if any)
 * on line 1, "City, State ZIP, Country" on line 2 (see readme.txt's
 * "Assumptions and intended use"). Returns raw, unescaped, stripslashes()'d
 * strings -- callers decide how to escape/join them for their context.
 *
 * @param object $member A $wpdb member row (or anything with the same address_* properties).
 * @return string[] [line1, line2]
 */
function mtl_member_address_lines( $member ) {
	$line1 = stripslashes( (string) $member->address_line1 );
	$line2 = stripslashes( (string) $member->address_line2 );
	if ( '' !== $line2 ) {
		$line1 .= ' ' . $line2;
	}

	$city  = stripslashes( (string) $member->city );
	$state = stripslashes( (string) $member->state );
	$zip   = trim( (string) $member->zip_code );
	$csz   = trim( trim( $city . ', ' . $state ) . ' ' . $zip );

	$country    = trim( (string) $member->country );
	$line2_full = '' !== $country ? trim( $csz . ', ' . $country, ', ' ) : $csz;

	return array( $line1, $line2_full );
}

/**
 * Same address as mtl_member_address_lines(), as a single comma-separated
 * line, for contexts that need one string (list-table cells/tooltips, row
 * search/filter text, CSV export).
 *
 * @param object $member A $wpdb member row (or anything with the same address_* properties).
 * @return string
 */
function mtl_member_address_single_line( $member ) {
	$lines = mtl_member_address_lines( $member );
	return trim( $lines[0] . ', ' . $lines[1], ', ' );
}

/**
 * The "state" dropdown's valid values (code => label). Deliberately scoped
 * to the U.S. and Canada, since both use short, standardized 2-letter
 * subdivision codes (ISO 3166-2:US / ISO 3166-2:CA); members elsewhere use
 * the trailing 'N/A' entry and put their actual region in the address lines
 * instead. Used both to render the <select> and to validate every write
 * server-side (Add/Edit Member, signup, account edit, CSV import).
 *
 * @return array<string,string>
 */
function mtl_get_state_options() {
	return array(
		'AL'  => 'Alabama',
		'AK'  => 'Alaska',
		'AZ'  => 'Arizona',
		'AR'  => 'Arkansas',
		'CA'  => 'California',
		'CO'  => 'Colorado',
		'CT'  => 'Connecticut',
		'DE'  => 'Delaware',
		'FL'  => 'Florida',
		'GA'  => 'Georgia',
		'HI'  => 'Hawaii',
		'ID'  => 'Idaho',
		'IL'  => 'Illinois',
		'IN'  => 'Indiana',
		'IA'  => 'Iowa',
		'KS'  => 'Kansas',
		'KY'  => 'Kentucky',
		'LA'  => 'Louisiana',
		'ME'  => 'Maine',
		'MD'  => 'Maryland',
		'MA'  => 'Massachusetts',
		'MI'  => 'Michigan',
		'MN'  => 'Minnesota',
		'MS'  => 'Mississippi',
		'MO'  => 'Missouri',
		'MT'  => 'Montana',
		'NE'  => 'Nebraska',
		'NV'  => 'Nevada',
		'NH'  => 'New Hampshire',
		'NJ'  => 'New Jersey',
		'NM'  => 'New Mexico',
		'NY'  => 'New York',
		'NC'  => 'North Carolina',
		'ND'  => 'North Dakota',
		'OH'  => 'Ohio',
		'OK'  => 'Oklahoma',
		'OR'  => 'Oregon',
		'PA'  => 'Pennsylvania',
		'RI'  => 'Rhode Island',
		'SC'  => 'South Carolina',
		'SD'  => 'South Dakota',
		'TN'  => 'Tennessee',
		'TX'  => 'Texas',
		'UT'  => 'Utah',
		'VT'  => 'Vermont',
		'VA'  => 'Virginia',
		'WA'  => 'Washington',
		'WV'  => 'West Virginia',
		'WI'  => 'Wisconsin',
		'WY'  => 'Wyoming',
		'DC'  => 'District of Columbia',
		'AS'  => 'American Samoa',
		'GU'  => 'Guam',
		'MP'  => 'Northern Mariana Islands',
		'PR'  => 'Puerto Rico',
		'VI'  => 'U.S. Virgin Islands',
		'AB'  => 'Alberta (Canada)',
		'BC'  => 'British Columbia (Canada)',
		'MB'  => 'Manitoba (Canada)',
		'NB'  => 'New Brunswick (Canada)',
		'NL'  => 'Newfoundland and Labrador (Canada)',
		'NS'  => 'Nova Scotia (Canada)',
		'NT'  => 'Northwest Territories (Canada)',
		'NU'  => 'Nunavut (Canada)',
		'ON'  => 'Ontario (Canada)',
		'PE'  => 'Prince Edward Island (Canada)',
		'QC'  => 'Quebec (Canada)',
		'SK'  => 'Saskatchewan (Canada)',
		'YT'  => 'Yukon (Canada)',
		'N/A' => 'N/A (outside the U.S. and Canada)',
	);
}

/**
 * The "country" dropdown's valid values: ISO 3166-1 official English short
 * names, so the stored value is a standardized name rather than an ad hoc
 * spelling ("USA" / "United States" / "U.S.A." collapsing into one). Stored
 * as the full name, not the alpha-2 code, so it displays with no separate
 * lookup needed elsewhere. 'United States' is pinned first as the default.
 *
 * @return string[]
 */
function mtl_get_country_options() {
	return array(
		'United States',
		'Afghanistan',
		'Albania',
		'Algeria',
		'Andorra',
		'Angola',
		'Antigua and Barbuda',
		'Argentina',
		'Armenia',
		'Australia',
		'Austria',
		'Azerbaijan',
		'Bahamas',
		'Bahrain',
		'Bangladesh',
		'Barbados',
		'Belarus',
		'Belgium',
		'Belize',
		'Benin',
		'Bhutan',
		'Bolivia',
		'Bosnia and Herzegovina',
		'Botswana',
		'Brazil',
		'Brunei',
		'Bulgaria',
		'Burkina Faso',
		'Burundi',
		'Cabo Verde',
		'Cambodia',
		'Cameroon',
		'Canada',
		'Central African Republic',
		'Chad',
		'Chile',
		'China',
		'Colombia',
		'Comoros',
		'Congo (Congo-Brazzaville)',
		'Costa Rica',
		'Croatia',
		'Cuba',
		'Cyprus',
		'Czechia',
		'Democratic Republic of the Congo',
		'Denmark',
		'Djibouti',
		'Dominica',
		'Dominican Republic',
		'Ecuador',
		'Egypt',
		'El Salvador',
		'Equatorial Guinea',
		'Eritrea',
		'Estonia',
		'Eswatini',
		'Ethiopia',
		'Fiji',
		'Finland',
		'France',
		'Gabon',
		'Gambia',
		'Georgia',
		'Germany',
		'Ghana',
		'Greece',
		'Grenada',
		'Guatemala',
		'Guinea',
		'Guinea-Bissau',
		'Guyana',
		'Haiti',
		'Honduras',
		'Hungary',
		'Iceland',
		'India',
		'Indonesia',
		'Iran',
		'Iraq',
		'Ireland',
		'Israel',
		'Italy',
		'Ivory Coast',
		'Jamaica',
		'Japan',
		'Jordan',
		'Kazakhstan',
		'Kenya',
		'Kiribati',
		'Kosovo',
		'Kuwait',
		'Kyrgyzstan',
		'Laos',
		'Latvia',
		'Lebanon',
		'Lesotho',
		'Liberia',
		'Libya',
		'Liechtenstein',
		'Lithuania',
		'Luxembourg',
		'Madagascar',
		'Malawi',
		'Malaysia',
		'Maldives',
		'Mali',
		'Malta',
		'Marshall Islands',
		'Mauritania',
		'Mauritius',
		'Mexico',
		'Micronesia',
		'Moldova',
		'Monaco',
		'Mongolia',
		'Montenegro',
		'Morocco',
		'Mozambique',
		'Myanmar',
		'Namibia',
		'Nauru',
		'Nepal',
		'Netherlands',
		'New Zealand',
		'Nicaragua',
		'Niger',
		'Nigeria',
		'North Korea',
		'North Macedonia',
		'Norway',
		'Oman',
		'Pakistan',
		'Palau',
		'Palestine',
		'Panama',
		'Papua New Guinea',
		'Paraguay',
		'Peru',
		'Philippines',
		'Poland',
		'Portugal',
		'Qatar',
		'Romania',
		'Russia',
		'Rwanda',
		'Saint Kitts and Nevis',
		'Saint Lucia',
		'Saint Vincent and the Grenadines',
		'Samoa',
		'San Marino',
		'Sao Tome and Principe',
		'Saudi Arabia',
		'Senegal',
		'Serbia',
		'Seychelles',
		'Sierra Leone',
		'Singapore',
		'Slovakia',
		'Slovenia',
		'Solomon Islands',
		'Somalia',
		'South Africa',
		'South Korea',
		'South Sudan',
		'Spain',
		'Sri Lanka',
		'Sudan',
		'Suriname',
		'Sweden',
		'Switzerland',
		'Syria',
		'Taiwan',
		'Tajikistan',
		'Tanzania',
		'Thailand',
		'Timor-Leste',
		'Togo',
		'Tonga',
		'Trinidad and Tobago',
		'Tunisia',
		'Turkey',
		'Turkmenistan',
		'Tuvalu',
		'Uganda',
		'Ukraine',
		'United Arab Emirates',
		'United Kingdom',
		'Uruguay',
		'Uzbekistan',
		'Vanuatu',
		'Vatican City',
		'Venezuela',
		'Vietnam',
		'Yemen',
		'Zambia',
		'Zimbabwe',
	);
}

/**
 * Whitelists a state code against mtl_get_state_options(). Shared by every
 * place a state gets written (Add/Edit Member, signup, account edit, CSV
 * import) so a tampered request or a malformed CSV cell can never store a
 * code outside the dropdown's real options.
 *
 * @param string $value Posted state code.
 * @return string $value unchanged if valid, else ''.
 */
function mtl_valid_state( $value ) {
	return array_key_exists( $value, mtl_get_state_options() ) ? $value : '';
}

/**
 * Whitelists a country name against mtl_get_country_options(). Same purpose
 * as mtl_valid_state(), for the country field.
 *
 * @param string $value Posted country name.
 * @return string $value unchanged if valid, else ''.
 */
function mtl_valid_country( $value ) {
	return in_array( $value, mtl_get_country_options(), true ) ? $value : '';
}

/**
 * A member is verified only once BOTH scan URLs are on file. Either one alone
 * (a member with only one form of ID so far) is not enough. Used where the
 * candidate URLs are already in hand (e.g. a just-submitted admin form); to
 * check an existing member record by ID, see member-pages.php's
 * mtl_member_is_verified().
 *
 * @param string|null $photo_id_scan_url Candidate photo ID scan URL.
 * @param string|null $address_proof_scan_url Candidate proof-of-address scan URL.
 * @return bool
 */
function mtl_verification_urls_complete( $photo_id_scan_url, $address_proof_scan_url ) {
	return ! empty( $photo_id_scan_url ) && ! empty( $address_proof_scan_url );
}

/**
 * Finds the WordPress account linked to a member row -- but only when that
 * account still proves the link: its email must match the member row AND its
 * mtl_member_id must still point back at that row. A member added by staff
 * with no online account has none, and returns 0.
 *
 * Resolving by email FIRST (rather than by the mtl_member_id meta value) is
 * deliberate. member_id is AUTO_INCREMENT and restarts at 1 whenever the
 * Setup page rebuilds the tables, so after a reset several surviving accounts
 * can carry the same stale mtl_member_id -- one already repaired by
 * mtl_current_member(), one not. A meta-first lookup would return an
 * arbitrary one of them. Email is unique in wp_users and in the members
 * table, so it identifies the person unambiguously.
 *
 * @param int    $member_id    Member row ID.
 * @param string $member_email The member row's email address.
 * @return int WP user ID, or 0 if no account proves the link.
 */
function mtl_find_wp_user_id_by_member_id( $member_id, $member_email = '' ) {
	$member_id    = (int) $member_id;
	$member_email = trim( (string) $member_email );
	if ( $member_id <= 0 || '' === $member_email ) {
		return 0;
	}

	$user = get_user_by( 'email', $member_email );
	if ( ! $user ) {
		return 0;
	}
	if ( (int) get_user_meta( $user->ID, 'mtl_member_id', true ) !== $member_id ) {
		return 0;
	}
	return (int) $user->ID;
}

/**
 * Every WordPress account still claiming a member id through usermeta,
 * whether or not its email matches the member row.
 *
 * Diagnostics only -- this is how the plugin notices a link it can no longer
 * vouch for (see the delete handler's "wp_user_orphaned" result). Never use
 * it to pick an account to delete or sign in as; use
 * mtl_find_wp_user_id_by_member_id() for that.
 *
 * @param int $member_id Member row ID.
 * @return int[] WP user IDs.
 */
function mtl_find_wp_user_ids_claiming_member_id( $member_id ) {
	return array_map(
		'intval',
		get_users(
			array(
				'meta_key'   => 'mtl_member_id',
				'meta_value' => (int) $member_id,
				'fields'     => 'ID',
			)
		)
	);
}

add_action( 'profile_update', 'mtl_sync_member_email_from_wp_user', 10, 2 );

/**
 * Keeps {prefix}members.email in step when a linked WordPress account's email
 * changes anywhere outside the Membership page -- the member's own
 * /wp-admin/profile.php screen (the mtl_member role has the "read"
 * capability, so members can reach it), Users > Edit User, or WP-CLI.
 *
 * This matters because mtl_current_member() proves a member row belongs to
 * the signed-in account by comparing the two email addresses. Without this
 * hook the two would drift apart on any of those paths and the member would
 * be locked out of their own account page.
 *
 * The row is only rewritten when the OLD address still matched it -- i.e.
 * when the link was provably good before the change. A row we cannot vouch
 * for is left alone rather than stamped with someone else's address.
 *
 * @param int     $user_id       Updated user ID.
 * @param WP_User $old_user_data User object as it was before the update.
 */
function mtl_sync_member_email_from_wp_user( $user_id, $old_user_data ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}

	$new_email = trim( (string) $user->user_email );
	$old_email = trim( (string) $old_user_data->user_email );
	if ( '' === $new_email || 0 === strcasecmp( $new_email, $old_email ) ) {
		// Email didn't change; nothing to sync.
		return;
	}

	$member_id = (int) get_user_meta( $user_id, 'mtl_member_id', true );
	if ( $member_id <= 0 ) {
		return;
	}

	global $wpdb;
	$tbl = $wpdb->prefix . 'members';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$row_email = (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT email FROM {$tbl} WHERE member_id = %d", $member_id )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( '' === $row_email || 0 === strcasecmp( $row_email, $new_email ) ) {
		// No such row, or the Membership page already moved both sides --
		// this is the guard that stops that handler's own wp_update_user()
		// call from bouncing straight back through here.
		return;
	}
	if ( 0 !== strcasecmp( $row_email, $old_email ) ) {
		// The link didn't prove identity before this change either, so there
		// is nothing here we can safely rewrite.
		return;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$taken = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT member_id FROM {$tbl} WHERE email = %s AND member_id != %d LIMIT 1",
			$new_email,
			$member_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $taken ) {
		// email is UNIQUE in the members table; another member already owns
		// this address, so staff have to resolve the clash by hand.
		return;
	}

	$wpdb->update(
		$tbl,
		array( 'email' => $new_email ),
		array( 'member_id' => $member_id ),
		array( '%s' ),
		array( '%d' )
	);
}

/**
 * Honors a member delete request -- self-service (Account page) or
 * admin-initiated (Membership page).
 *
 * The member's row is always ANONYMIZED, never dropped: their identifying
 * fields are overwritten with placeholders, anonymized_at is stamped, and
 * they read as "Former Member" everywhere afterwards. Everything that records
 * what they did with the library is deliberately kept -- loans, reservations,
 * and the trainings they completed -- so tool-level statistics, borrowing
 * counts and training records all stay accurate. Keeping the row is what
 * makes that possible: loans and tool_reservations reference member_id, and
 * member_training_mappings would be swept away by ON DELETE CASCADE if the
 * row were dropped (see schema.sql).
 *
 * What IS destroyed is the personal, identifying material: the row's own
 * name/address/contact fields, the member_verifications row holding their ID
 * and proof-of-address scans, and -- fully, not anonymized -- their WordPress
 * account, which wp_delete_user() removes from both wp_users and wp_usermeta.
 *
 * Any still-active reservation is cancelled first, otherwise a departed
 * member would keep occupying a spot in a tool's queue indefinitely; this
 * mirrors how retiring a tool auto-cancels its own reservations (see the
 * Retire handler in admin/inventory-page.php). A currently open loan is
 * deliberately left alone, same as a retired tool's loan -- the member still
 * physically has the item, so it can still be ended normally when returned.
 *
 * The WordPress account is only deleted when it still proves the link (see
 * mtl_find_wp_user_id_by_member_id()). An account whose mtl_member_id is
 * stale is left in place and reported via wp_user_orphaned: deleting a
 * sign-in cannot be undone, and a stale id is not evidence of whose it is.
 *
 * @param int $member_id Member row ID.
 * @return array{outcome:string,name:string,cancelled_reservations:int,wp_user_orphaned:bool} outcome is 'anonymized' or 'not_found'; name is the display name captured before any changes; wp_user_orphaned is true when an account still claims this member id but could not be verified, so it was left alone.
 */
function mtl_delete_or_anonymize_member( $member_id ) {
	global $wpdb;
	$member_id   = (int) $member_id;
	$tbl_members = $wpdb->prefix . 'members';
	$tbl_verif   = $wpdb->prefix . 'member_verifications';
	$tbl_res     = $wpdb->prefix . 'tool_reservations';

	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT first_name, last_name, email FROM {$tbl_members} WHERE member_id = %d",
			$member_id
		)
	);
	if ( ! $row ) {
		// Already gone (double-submit, stale page) -- nothing to do.
		return array(
			'outcome'                => 'not_found',
			'name'                   => '',
			'cancelled_reservations' => 0,
			'wp_user_orphaned'       => false,
		);
	}
	$name = trim( $row->first_name . ' ' . $row->last_name );

	// Resolved BEFORE the row is anonymized, while its email still identifies
	// the person. Only an account that proves the link is deleted: if the link
	// is stale -- e.g. a database reset renumbered member ids out from under
	// the surviving sign-ins -- the account is left alone rather than risk
	// deleting an unrelated person's WordPress login, which is irreversible.
	$wp_user_id       = mtl_find_wp_user_id_by_member_id( $member_id, (string) $row->email );
	$wp_user_orphaned = ( 0 === $wp_user_id && ! empty( mtl_find_wp_user_ids_claiming_member_id( $member_id ) ) );

	$cancelled_reservations = (int) $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"UPDATE {$tbl_res} SET expiry_date = %s WHERE member_id = %d AND expiry_date IS NULL",
			current_time( 'mysql' ),
			$member_id
		)
	);

	$wpdb->update(
		$tbl_members,
		array(
			'first_name'    => 'Former',
			'last_name'     => 'Member',
			'address_line1' => '(removed)',
			'address_line2' => null,
			'city'          => '(removed)',
			'state'         => 'N/A',
			'zip_code'      => '00000',
			'country'       => 'United States',
			'phone_number'  => '(removed)',
			// .invalid is the IANA-reserved, never-resolving TLD (RFC 2606) --
			// guaranteed unique against the UNIQUE constraint without risking a
			// real mailbox, and it frees their real address for a future signup.
			'email'         => 'deleted-member-' . $member_id . '@example.invalid',
			// Staff-only notes are about the person, so they go with the rest
			// of their identifying details.
			'private_notes' => null,
			'anonymized_at' => current_time( 'mysql' ),
		),
		array( 'member_id' => $member_id ),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
		array( '%d' )
	);

	// Their ID and proof-of-address scans: identifying material, so removed
	// outright. Their training records are NOT touched -- those are library
	// history, and the anonymized row keeps them attached to a "Former Member"
	// rather than to a name.
	$wpdb->delete( $tbl_verif, array( 'member_id' => $member_id ), array( '%d' ) );

	if ( $wp_user_id ) {
		// Deletes the wp_users row and every wp_usermeta row belonging to it,
		// including the mtl_member_id link. Nothing about the sign-in is kept.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $wp_user_id );
	}

	return array(
		'outcome'                => 'anonymized',
		'name'                   => $name,
		'cancelled_reservations' => $cancelled_reservations,
		'wp_user_orphaned'       => $wp_user_orphaned,
	);
}

// Admin pages.
require_once MTL_PLUGIN_DIR . 'admin/dashboard-page.php';
require_once MTL_PLUGIN_DIR . 'admin/inventory-page.php';
require_once MTL_PLUGIN_DIR . 'admin/membership-page.php';
require_once MTL_PLUGIN_DIR . 'admin/loans-page.php';
require_once MTL_PLUGIN_DIR . 'admin/workflows-page.php';
require_once MTL_PLUGIN_DIR . 'admin/setup-page.php';

// Public-facing customer pages.
require_once MTL_PLUGIN_DIR . 'public/shop-page.php';
require_once MTL_PLUGIN_DIR . 'public/member-pages.php';

// Inject Custom Colors and Fonts into the Admin Pages.
add_action( 'admin_head', 'mtl_apply_custom_admin_styles' );

/**
 * Injects the branding colors/fonts configured on the Setup page as inline
 * CSS on this plugin's own admin screens.
 */
function mtl_apply_custom_admin_styles() {
	// Only apply these styles on this plugin's own pages. Every page slug
	// starts with "mtl-" (mtl-dashboard, mtl-inventory, ...), which is present
	// in the screen id of BOTH the top-level dashboard page
	// (toplevel_page_mtl-dashboard) and the submenu pages
	// (my-tool-library_page_mtl-inventory). Matching on it is what makes the
	// theme apply on the dashboard too, not just the submenu pages.
	$screen = get_current_screen();
	if ( $screen && false !== strpos( $screen->id, 'mtl-' ) ) {

		// Header Options.
		$h_color     = get_option( 'mtl_header_color', '#ff6600' );
		$h_font      = get_option( 'mtl_header_font', 'inherit' );
		$h_size      = get_option( 'mtl_header_size', '2em' );
		$h_weight    = get_option( 'mtl_header_weight', '700' );
		$h_transform = get_option( 'mtl_header_transform', 'none' );

		// Body Options.
		$b_color  = get_option( 'mtl_body_color', '#096491' );
		$b_font   = get_option( 'mtl_body_font', 'inherit' );
		$b_size   = get_option( 'mtl_body_size', '14px' );
		$b_weight = get_option( 'mtl_body_weight', '400' );

		// Link Options.
		$l_color = get_option( 'mtl_link_color', '#00b3ff' );
		$l_font  = get_option( 'mtl_link_font', 'inherit' );
		$l_size  = get_option( 'mtl_link_size', 'inherit' );
		$l_dec   = get_option( 'mtl_link_decoration', 'none' );

		// Buttons & Page Accents.
		$accent_color = get_option( 'mtl_accent_color', '#f7c600' );
		$bg_color     = get_option( 'mtl_background_color', '#ffffff' );
		$radius       = get_option( 'mtl_border_radius', '4px' );
		$btn_scale    = get_option( 'mtl_button_scale', '1' );

		echo '<style>
            .mtl-admin-wrapper {
                color: ' . esc_attr( $b_color ) . ';
                font-family: ' . esc_attr( $b_font ) . ';
                font-size: ' . esc_attr( $b_size ) . ';
                font-weight: ' . esc_attr( $b_weight ) . ';
                background: ' . esc_attr( $bg_color ) . ';
                --mtl-accent-color: ' . esc_attr( $accent_color ) . ';
                --mtl-radius: ' . esc_attr( $radius ) . ';
                --mtl-header-color: ' . esc_attr( $h_color ) . ';
                --mtl-body-color: ' . esc_attr( $b_color ) . ';
                --mtl-link-color: ' . esc_attr( $l_color ) . ';
                --mtl-btn-scale: ' . esc_attr( $btn_scale ) . ';
            }
            .mtl-admin-wrapper h2,
            .mtl-admin-wrapper h3,
            .mtl-admin-wrapper h4,
            .mtl-admin-wrapper summary {
                color: ' . esc_attr( $h_color ) . ' !important;
                font-family: ' . esc_attr( $h_font ) . ';
                font-size: ' . esc_attr( $h_size ) . ';
                font-weight: ' . esc_attr( $h_weight ) . ';
                text-transform: ' . esc_attr( $h_transform ) . ';
            }
            .mtl-admin-wrapper a {
                color: ' . esc_attr( $l_color ) . ';
                font-family: ' . esc_attr( $l_font ) . ';
                font-size: ' . esc_attr( $l_size ) . ';
                text-decoration: ' . esc_attr( $l_dec ) . ';
            }
            .mtl-admin-wrapper a:hover {
                text-decoration: underline;
                filter: brightness(85%);
            }
            /*
             * Button size scaling. Every metric is multiplied by the same
             * --mtl-btn-scale factor rather than using transform/zoom, so the
             * buttons genuinely occupy more or less layout space instead of
             * just being drawn larger and overlapping their neighbours.
             * WordPress core sizes buttons in fixed px, so each px value is
             * restated here through calc(); .button-small keeps its own
             * smaller base values, which preserves the relative difference
             * between the two sizes at any scale.
             */
            .mtl-admin-wrapper .button,
            .mtl-admin-wrapper .button-primary,
            .mtl-admin-wrapper .button-secondary {
                border-radius: var(--mtl-radius) !important;
                font-size: calc(13px * var(--mtl-btn-scale)) !important;
                line-height: calc(28px * var(--mtl-btn-scale)) !important;
                min-height: calc(30px * var(--mtl-btn-scale)) !important;
                padding: 0 calc(10px * var(--mtl-btn-scale)) !important;
            }
            .mtl-admin-wrapper .button.button-small {
                font-size: calc(11px * var(--mtl-btn-scale)) !important;
                line-height: calc(24px * var(--mtl-btn-scale)) !important;
                min-height: calc(26px * var(--mtl-btn-scale)) !important;
                padding: 0 calc(8px * var(--mtl-btn-scale)) !important;
            }
            .mtl-admin-wrapper .button-primary {
                background: ' . esc_attr( $h_color ) . ' !important;
                border-color: ' . esc_attr( $h_color ) . ' !important;
                color: #fff !important;
            }
            .mtl-admin-wrapper .button-secondary {
                background: transparent;
                border-color: ' . esc_attr( $accent_color ) . ' !important;
                color: ' . esc_attr( $accent_color ) . ' !important;
            }
            .mtl-admin-wrapper .button-secondary:hover {
                background: ' . esc_attr( $accent_color ) . ' !important;
                color: #fff !important;
            }
        </style>';
	}
}

// ==========================================================================
// STAFF PERMISSIONS
//
// Two levels of library staff:
//
// Administrator -- everything, including the Setup page (branding, database
// setup, exports) and deleting a member's record.
// Editor        -- the day-to-day desk role: members, inventory, loans and
// reservations, dashboard and workflows. No Setup page and
// no member deletion.
//
// Editor is WordPress's own built-in role, so no custom role is created here;
// the plugin only adds one capability to it. Anything a member can do to
// their OWN account (including deleting it) is unaffected by all of this --
// that runs on the public site and is gated by mtl_current_member(), never by
// these capabilities.
// ==========================================================================

// The capability that grants access to the plugin's admin portal. Held by
// Administrator and Editor; checked through mtl_can_manage_library().
const MTL_STAFF_CAP = 'mtl_manage_library';

add_action( 'init', 'mtl_register_staff_capabilities' );

/**
 * Grants the library staff capability to the Administrator and Editor roles.
 *
 * Runs on init rather than only on activation so it also reaches installs
 * that were already active before this feature shipped, matching how
 * mtl_register_member_role() handles the member role. add_cap() writes to the
 * roles option, so each role is checked first and only touched when the
 * capability is genuinely missing.
 */
function mtl_register_staff_capabilities() {
	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role && ! $role->has_cap( MTL_STAFF_CAP ) ) {
			$role->add_cap( MTL_STAFF_CAP );
		}
	}
}

/**
 * Whether the current user may use the plugin's admin portal at all --
 * Dashboard, Membership, Inventory, Loans & Reservations, and Workflows.
 * True for Editors and Administrators.
 *
 * Administrators are accepted via manage_options as well as the capability
 * itself, so an administrator can never be locked out of their own library
 * even if the roles option is missing the capability (a partially restored
 * database, a role editor plugin, or a Super Admin on multisite).
 *
 * @return bool
 */
function mtl_can_manage_library() {
	return current_user_can( 'manage_options' ) || current_user_can( MTL_STAFF_CAP );
}

/**
 * Whether the current user may open the Setup page and run the actions on it
 * (branding and appearance, category/tag/training lists, database setup, and
 * the data exports). Administrators only -- these change how the whole
 * library behaves, or hand over a file containing every member's details.
 *
 * @return bool
 */
function mtl_can_manage_settings() {
	return current_user_can( 'manage_options' );
}

/**
 * Whether the current user may delete a member's record. Administrators only:
 * it destroys personal data irreversibly, and where the member has loan
 * history it rewrites that history's owner (see
 * mtl_delete_or_anonymize_member()).
 *
 * Members deleting their OWN account from the public Account page do not go
 * through this -- see mtl_render_account_page() in public/member-pages.php.
 * That right is theirs regardless of who is on staff.
 *
 * @return bool
 */
function mtl_can_delete_members() {
	return current_user_can( 'manage_options' );
}

// ADMIN MENUS: Register the portal pages.
// add_submenu_page() both places a sidebar link AND registers the page's
// routing/render callback/capability check -- so all six must stay
// registered here even though their sidebar links are hidden below; only
// the top-level "My Tool Library" button stays visible, and navigation
// happens through the portal tab bar.
add_action( 'admin_menu', 'mtl_register_admin_menus' );

/**
 * Registers the plugin's top-level admin page and its six portal pages.
 *
 * Every page except Setup is registered against MTL_STAFF_CAP, so Editors
 * reach them and WordPress itself refuses anyone else. Setup keeps
 * manage_options, which is what stops an Editor opening it by URL -- core
 * returns "Sorry, you are not allowed to access this page" before the render
 * callback ever runs (mtl_render_setup_page() re-checks anyway).
 */
function mtl_register_admin_menus() {
	add_menu_page( 'My Tool Library Dashboard', 'My Tool Library', MTL_STAFF_CAP, 'mtl-dashboard', 'mtl_render_dashboard_page', 'dashicons-hammer', 25 );
	add_submenu_page( 'mtl-dashboard', 'My Tool Library Dashboard', 'Dashboard', MTL_STAFF_CAP, 'mtl-dashboard', 'mtl_render_dashboard_page' );
	add_submenu_page( 'mtl-dashboard', 'Manage Membership', 'Membership', MTL_STAFF_CAP, 'mtl-membership', 'mtl_render_membership_page' );
	add_submenu_page( 'mtl-dashboard', 'Tool Inventory', 'Inventory', MTL_STAFF_CAP, 'mtl-inventory', 'mtl_render_inventory_page' );
	add_submenu_page( 'mtl-dashboard', 'Loans & Reservations', 'Loans & Reservations', MTL_STAFF_CAP, 'mtl-loans', 'mtl_render_loans_page' );
	add_submenu_page( 'mtl-dashboard', 'Staff Workflows', 'Workflows', MTL_STAFF_CAP, 'mtl-workflows', 'mtl_render_workflows_page' );
	add_submenu_page( 'mtl-dashboard', 'Plugin Setup', 'Setup', 'manage_options', 'mtl-setup', 'mtl_render_setup_page' );
}

// Hide the submenu links from the WordPress sidebar so the six pages are
// reached only via the portal tab bar (the top-level "My Tool Library" button
// stays and opens the Dashboard).
//
// TIMING IS LOAD-BEARING: this runs on admin_head, not admin_menu. WordPress
// resolves the requested page's hook name, capability check and title by
// searching the $submenu registration DURING routing -- removing the entries
// before that search would make every one of these pages die with "Sorry, you
// are not allowed to access this page", even for administrators. admin_head
// fires after all routing decisions are made but just before the sidebar menu
// is printed, so the pages keep working while their sidebar links vanish.
add_action( 'admin_head', 'mtl_hide_portal_sidebar_links' );

/**
 * Removes the six portal pages' sidebar entries, leaving only the
 * top-level "My Tool Library" link. See the comment above for why this
 * has to run on admin_head rather than admin_menu.
 */
function mtl_hide_portal_sidebar_links() {
	remove_submenu_page( 'mtl-dashboard', 'mtl-dashboard' );
	remove_submenu_page( 'mtl-dashboard', 'mtl-membership' );
	remove_submenu_page( 'mtl-dashboard', 'mtl-inventory' );
	remove_submenu_page( 'mtl-dashboard', 'mtl-loans' );
	remove_submenu_page( 'mtl-dashboard', 'mtl-workflows' );
	remove_submenu_page( 'mtl-dashboard', 'mtl-setup' );
}

// ==========================================================================
// ADMIN PORTAL TAB BAR
// Renders a horizontal Dashboard / Membership / Inventory / Loans / Workflows
// / Setup tab strip across the top of all six plugin admin pages, plus
// "View Main Page" and "Log Out" links -- so the six separate page files
// read as one tabbed portal without merging them into a single file.
// ==========================================================================
add_action( 'admin_notices', 'mtl_render_admin_portal_tabs' );

/**
 * Renders the Dashboard/Membership/Inventory/Loans/Workflows/Setup tab strip
 * shown at the top of all six plugin admin pages.
 */
function mtl_render_admin_portal_tabs() {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'mtl-' ) ) {
		return;
	}

	$tabs = array(
		'mtl-dashboard'  => 'Dashboard',
		'mtl-membership' => 'Membership',
		'mtl-inventory'  => 'Inventory',
		'mtl-loans'      => 'Loans & Reservations',
		'mtl-workflows'  => 'Workflows',
	);
	// Setup is administrators-only, so Editors never see the tab. Hiding it is
	// a courtesy; the capability on the page itself is what enforces it.
	if ( mtl_can_manage_settings() ) {
		$tabs['mtl-setup'] = 'Setup';
	}
	$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$h_color = get_option( 'mtl_header_color', '#ff6600' );

	echo '<div style="margin: 10px 20px 0 2px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; display: flex; align-items: center; flex-wrap: wrap; padding: 0 12px;">';

	echo '<nav style="display: flex; gap: 4px; flex: 1; flex-wrap: wrap;">';
	foreach ( $tabs as $slug => $label ) {
		$is_active = ( $current === $slug );
		$style     = 'display: inline-block; padding: 12px 14px; text-decoration: none; font-weight: ' . ( $is_active ? '600' : '400' ) . ';'
			. ' border-bottom: 3px solid ' . ( $is_active ? esc_attr( $h_color ) : 'transparent' ) . ';'
			. ' color: ' . ( $is_active ? esc_attr( $h_color ) : '#3c434a' ) . ';';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '" style="' . esc_attr( $style ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';

	echo '<div style="display: flex; gap: 14px; align-items: center; font-size: 0.9em;">';
	echo '<a href="' . esc_url( mtl_front_page_url( 'main' ) ) . '" style="text-decoration: none;">View Main Page</a>';
	// wp_logout_url() carries WordPress's logout nonce, so core validates the
	// request before clearing the auth cookies, then sends the user back to
	// the public main page -- where admin pages are no longer accessible.
	echo '<a href="' . esc_url( wp_logout_url( mtl_front_page_url( 'main' ) ) ) . '" style="text-decoration: none; color: #b32d2e;">Log Out</a>';
	echo '</div>';

	echo '</div>';
}

// ==========================================================================
// PUBLIC PERMALINK: /tool-library/
//
// Gives the public catalog a clean URL a site owner can paste into a nav
// menu or link from any page/post, instead of the raw ?mtl_page=main query
// string. mtl_front_page_url() prefers this pretty URL and falls back to
// the query string on sites using Plain permalinks (no rewriting at all).
// ==========================================================================

register_activation_hook( __FILE__, 'mtl_plugin_activate' );

/**
 * Registers and flushes the plugin's rewrite rule on activation.
 */
function mtl_plugin_activate() {
	mtl_register_rewrite_rules();
	// Rewrite rules are cached in the database. A fresh activation must
	// flush that cache once so /tool-library/ resolves immediately instead
	// of 404ing until something else happens to trigger a flush.
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'mtl_plugin_deactivate' );

/**
 * Flushes the plugin's rewrite rule out of the cache on deactivation.
 */
function mtl_plugin_deactivate() {
	// Drops the custom rule from the cached rewrite rules on deactivation,
	// so a deactivated plugin doesn't leave a dangling route behind.
	flush_rewrite_rules();
}

add_action( 'init', 'mtl_register_rewrite_rules' );

/**
 * Registers the /tool-library/ rewrite rule.
 */
function mtl_register_rewrite_rules() {
	add_rewrite_rule( '^tool-library/?$', 'index.php?mtl_page=main', 'top' );
}

add_filter( 'query_vars', 'mtl_register_query_vars' );

/**
 * Registers this plugin's public query vars so WordPress preserves them.
 *
 * @param string[] $vars Existing public query vars.
 * @return string[] $vars with this plugin's vars appended.
 */
function mtl_register_query_vars( $vars ) {
	$vars[] = 'mtl_page';
	// Customer shopping-page controls. Registering them keeps WordPress's
	// canonical-redirect from stripping these params off the public /tool-
	// library/ URL, and lets the values survive on every permalink style.
	$vars[] = 'mtl_q';       // Basic search text.
	$vars[] = 'mtl_name';    // Advanced: tool name.
	$vars[] = 'mtl_brand';   // Advanced: brand.
	$vars[] = 'mtl_cat';     // Advanced: category id.
	$vars[] = 'mtl_tag';     // Advanced: tag id.
	$vars[] = 'mtl_status';  // Advanced: availability.
	$vars[] = 'mtl_sort';    // Sort order.
	$vars[] = 'mtl_view';    // Tiles | rows.
	$vars[] = 'mtl_pg';      // Page number.
	$vars[] = 'mtl_tool';    // Selected tool id (for the detail box).
	$vars[] = 'mtl_msg';     // One-off status banner key (after a POST action).
	return $vars;
}

// ==========================================================================
// FRONT-END PAGES (public catalog, sign-in, sign-up, member area, admin gate)
//
// Lightweight standalone pages served via the mtl_page query var, handled
// on template_redirect:
// mtl_page=main         -- public tool catalog (shop-page.php); also
// processes the "reserve a tool" POST.
// mtl_page=login        -- branded sign-in for members AND admins, via
// core's wp_login_form() -- WordPress handles all
// credential/cookie/session security.
// mtl_page=signup       -- member self-registration (member-pages.php):
// creates a WP user + a {prefix}members row.
// mtl_page=reservations -- a member's queue, place in line, cancel.
// mtl_page=account      -- a member's profile, verification status, loan
// history, and profile edits.
// mtl_page=admin        -- gate: routes a signed-in admin into the admin
// portal, any other signed-in user (a member)
// back to the catalog. Admin capability checks
// remain the real enforcement.
// ==========================================================================

/**
 * URL helper for the plugin's front-end pages. The main page prefers the
 * clean /tool-library/ permalink so it is easy to paste into a menu or a
 * page/post; it falls back to the ?mtl_page=main query string on sites using
 * Plain permalinks (where WordPress never rewrites custom paths), and for
 * the login/gate pages, which are only ever reached through this plugin's
 * own links rather than being hand-typed or embedded by a site owner.
 *
 * @param string $page One of 'main', 'login', 'signup', 'reservations', 'account', 'admin'.
 * @return string Escaped URL.
 */
function mtl_front_page_url( $page ) {
	if ( 'main' === $page && get_option( 'permalink_structure' ) ) {
		return home_url( '/tool-library/' );
	}
	return add_query_arg( 'mtl_page', rawurlencode( $page ), home_url( '/' ) );
}

add_action( 'template_redirect', 'mtl_handle_front_pages' );

/**
 * Routes the mtl_page query var (permalink or query-string form) to the
 * matching front-end page renderer.
 */
function mtl_handle_front_pages() {
	// get_query_var() recognizes BOTH the pretty /tool-library/ permalink
	// (matched by the rewrite rule above) and the raw ?mtl_page=main query
	// string, since "mtl_page" is registered as a public query var above.
	// The $_GET check is a defensive fallback only.
	$page = get_query_var( 'mtl_page' );
	if ( '' === $page && isset( $_GET['mtl_page'] ) ) {
		$page = sanitize_key( wp_unslash( $_GET['mtl_page'] ) );
	}
	if ( '' === $page || false === $page ) {
		return;
	}

	$page = sanitize_key( $page );

	// These pages depend on login state, so never let them be cached.
	nocache_headers();

	if ( 'main' === $page ) {
		mtl_render_front_main_page();
	} elseif ( 'login' === $page ) {
		mtl_render_front_login_page();
	} elseif ( 'admin' === $page ) {
		mtl_handle_admin_gate();
	} elseif ( 'signup' === $page ) {
		mtl_render_signup_page();
	} elseif ( 'reservations' === $page ) {
		mtl_render_member_reservations_page();
	} elseif ( 'account' === $page ) {
		mtl_render_account_page();
	}
	// Unknown values fall through to the theme's normal 404/home handling.
}

/**
 * Shared standalone HTML shell for the front-end pages, themed from the same
 * appearance settings as the admin pages.
 *
 * @param string $title       Page title.
 * @param string $body_html   Fills the centered main area. Built internally
 *                             from escaped pieces -- never from raw user input.
 * @param string $footer_html Fills the discreet footer link row at the bottom.
 * @return void Outputs the page directly and exits.
 */
function mtl_render_front_shell( $title, $body_html, $footer_html = '' ) {
	$org_name = get_option( 'mtl_org_name', '' );
	if ( '' === $org_name ) {
		$org_name = 'My Tool Library';
	}
	$logo_url = get_option( 'mtl_logo_url', '' );

	$h_color = get_option( 'mtl_header_color', '#ff6600' );
	$b_color = get_option( 'mtl_body_color', '#096491' );
	$l_color = get_option( 'mtl_link_color', '#00b3ff' );
	$bg      = get_option( 'mtl_background_color', '#ffffff' );
	$radius  = get_option( 'mtl_border_radius', '4px' );
	// Same button scale the admin pages use, so the Setup page's Button Size
	// setting reaches the public-facing pages too.
	$btn_scale = get_option( 'mtl_button_scale', '1' );

	// On a standalone page "inherit" would fall back to the browser's default
	// serif; substitute a neutral system stack instead.
	$b_font = get_option( 'mtl_body_font', 'inherit' );
	if ( 'inherit' === $b_font || '' === $b_font ) {
		$b_font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
	}

	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html( $title . ' - ' . $org_name ); ?></title>
	<style>
		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			flex-direction: column;
			background: <?php echo esc_html( $bg ); ?>;
			color: <?php echo esc_html( $b_color ); ?>;
			font-family: <?php echo esc_html( $b_font ); ?>;
		}
		.mtl-front-header {
			text-align: center;
			padding: 40px 20px 10px 20px;
		}
		.mtl-front-header img {
			max-height: 80px;
			width: auto;
		}
		.mtl-front-header h1 {
			color: <?php echo esc_html( $h_color ); ?>;
			margin: 10px 0 0 0;
		}
		.mtl-front-content {
			flex: 1;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 20px;
		}
		.mtl-front-card {
			max-width: 480px;
			width: 100%;
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: <?php echo esc_html( '999px' === $radius ? '16px' : $radius ); ?>;
			box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
			padding: 30px;
			text-align: center;
		}
		.mtl-front-card p {
			line-height: 1.6;
		}
		a {
			color: <?php echo esc_html( $l_color ); ?>;
		}
		.mtl-front-footer {
			text-align: center;
			padding: 25px 20px;
			font-size: 0.8em;
		}
		.mtl-front-footer a {
			color: #8c8f94;
			text-decoration: none;
			margin: 0 8px;
		}
		.mtl-front-footer a:hover {
			text-decoration: underline;
		}
		/* Make the core wp_login_form() output match the card. */
		.mtl-front-card form p {
			text-align: left;
			margin: 0 0 14px 0;
		}
		.mtl-front-card label {
			display: block;
			font-weight: 600;
			margin-bottom: 4px;
		}
		.mtl-front-card input[type="text"],
		.mtl-front-card input[type="password"] {
			width: 100%;
			box-sizing: border-box;
			padding: 8px 10px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
			font-size: 1em;
		}
		.mtl-front-card input[type="submit"] {
			background: <?php echo esc_html( $h_color ); ?>;
			border: 1px solid <?php echo esc_html( $h_color ); ?>;
			color: #fff;
			padding: calc(9px * <?php echo esc_html( $btn_scale ); ?>) calc(22px * <?php echo esc_html( $btn_scale ); ?>);
			border-radius: <?php echo esc_html( $radius ); ?>;
			font-size: calc(1em * <?php echo esc_html( $btn_scale ); ?>);
			cursor: pointer;
		}
		.login-remember {
			font-weight: 400;
		}
		.login-remember label {
			font-weight: 400;
		}
	</style>
</head>
<body>
	<header class="mtl-front-header">
		<?php if ( ! empty( $logo_url ) ) : ?>
			<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $org_name ); ?>">
		<?php endif; ?>
		<h1><?php echo esc_html( $org_name ); ?></h1>
	</header>
	<main class="mtl-front-content">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built internally from esc_*()-wrapped pieces, never from raw user input (see docblock).
		echo $body_html;
		?>
	</main>
	<?php if ( '' !== $footer_html ) : ?>
		<footer class="mtl-front-footer">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built internally from esc_*()-wrapped pieces, never from raw user input (see docblock).
			echo $footer_html;
			?>
		</footer>
	<?php endif; ?>
</body>
</html>
	<?php
	exit;
}

/**
 * The public main page -- the customer-facing shopping catalog, with a
 * small, discreet Admin Sign In link at the bottom of the page. The
 * catalog itself is built (server-side, no JavaScript) in
 * public/shop-page.php.
 */
function mtl_render_front_main_page() {
	// Process a "reserve a tool" POST before any output, so it can finish
	// with a redirect back to the catalog (no double-submit on refresh).
	mtl_handle_reserve_action();

	$body = mtl_render_shop_page();

	// Discreet footer links, varying with login state. (The primary member
	// sign-in / sign-up / account controls live in the catalog's own top-bar
	// nav; these footer links are a quiet secondary path).
	if ( is_user_logged_in() && mtl_can_manage_library() ) {
		$footer  = '<a href="' . esc_url( admin_url( 'admin.php?page=mtl-dashboard' ) ) . '">Open Admin Portal</a>';
		$footer .= '<a href="' . esc_url( wp_logout_url( mtl_front_page_url( 'main' ) ) ) . '">Log Out</a>';
	} elseif ( is_user_logged_in() ) {
		$footer  = '<a href="' . esc_url( mtl_front_page_url( 'account' ) ) . '">My Account</a>';
		$footer .= '<a href="' . esc_url( wp_logout_url( mtl_front_page_url( 'main' ) ) ) . '">Log Out</a>';
	} else {
		$footer  = '<a href="' . esc_url( mtl_front_page_url( 'login' ) ) . '">Sign In</a>';
		$footer .= '<a href="' . esc_url( mtl_front_page_url( 'signup' ) ) . '">Create an Account</a>';
	}

	mtl_render_front_shell( 'Browse Tools', $body, $footer );
}

/**
 * Branded sign-in screen for members AND administrators. The form itself
 * is core's wp_login_form(): it posts to wp-login.php, so WordPress
 * performs the actual authentication and cookie handling. On success the
 * user lands on the admin gate below, which sends admins to the portal
 * and members back to the catalog.
 */
function mtl_render_front_login_page() {
	// Already signed in? Skip the form and go straight to the gate.
	if ( is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'admin' ) );
		exit;
	}

	$login_form = wp_login_form(
		array(
			'echo'           => false,
			'redirect'       => mtl_front_page_url( 'admin' ),
			'label_username' => 'Email Address',
			'label_password' => 'Password',
			'label_log_in'   => 'Sign In',
			'remember'       => true,
		)
	);

	$body  = '<div class="mtl-front-card">';
	$body .= '<h2 style="margin-top: 0;">Sign In</h2>';
	$body .= '<p style="font-size: 0.9em; color: #666;">Sign in with your email address and password. New members can <a href="' . esc_url( mtl_front_page_url( 'signup' ) ) . '">create an account</a>.</p>';
	$body .= $login_form;
	$body .= '</div>';

	$footer  = '<a href="' . esc_url( mtl_front_page_url( 'main' ) ) . '">&larr; Back to the catalog</a>';
	$footer .= '<a href="' . esc_url( mtl_front_page_url( 'signup' ) ) . '">Create an Account</a>';

	mtl_render_front_shell( 'Sign In', $body, $footer );
}

/**
 * Post-login router. Library staff (Editors and Administrators) continue into
 * the admin portal; any other signed-in user (i.e. a member) is sent back to
 * the public catalog, where their reservation and account tools live. (This
 * gate is a courtesy router -- the real enforcement is WordPress's own
 * capability check on every admin page and form handler.)
 */
function mtl_handle_admin_gate() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'login' ) );
		exit;
	}

	if ( mtl_can_manage_library() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=mtl-dashboard' ) );
		exit;
	}

	// Signed in, but not staff -- a member. Send them to the shop.
	wp_safe_redirect( mtl_front_page_url( 'main' ) );
	exit;
}

// Add the Custom Footer to Plugin Pages.
add_action( 'admin_footer', 'mtl_custom_admin_footer' );

/**
 * Renders the branded footer (org name/logo) at the bottom of this
 * plugin's own admin screens.
 */
function mtl_custom_admin_footer() {
	$screen = get_current_screen();
	if ( $screen && false !== strpos( $screen->id, 'mtl-' ) ) {

		$org_name = get_option( 'mtl_org_name', '' );
		$logo_url = get_option( 'mtl_logo_url', '' );

		echo '<div style="text-align: center; padding: 30px 20px; margin-top: 40px; border-top: 1px solid #ccd0d4; display: flex; align-items: center; justify-content: center; gap: 15px;">';

		if ( ! empty( $logo_url ) ) {
			echo '<img src="' . esc_url( $logo_url ) . '" alt="Organization Logo" style="max-height: 50px; width: auto;">';
		}

		if ( ! empty( $org_name ) ) {
			echo '<strong style="font-size: 1.2em; color: #555;">' . esc_html( $org_name ) . '</strong>';
		}

		echo '</div>';
	}
}
