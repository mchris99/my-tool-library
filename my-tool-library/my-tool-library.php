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
 * An appearance setting, made safe to print inside a <style> block.
 *
 * ESCAPING FOR HTML IS THE WRONG TOOL HERE, and using it silently breaks the
 * setting. esc_html()/esc_attr() turn the apostrophes in a font stack into
 * &#039;, and a browser parsing CSS does not decode HTML entities, so
 * `font-family: 'Segoe UI', sans-serif` arrives as
 * `font-family: &#039;Segoe UI&#039;, sans-serif`, the declaration is invalid,
 * and the font is dropped with no error anywhere. Every quoted font stack the
 * Setup page offers is affected.
 *
 * So values are made safe by ALLOWLIST instead: letters, digits, space, comma,
 * period, hyphen, percent, quotes and hash. That covers font stacks, sizes
 * (1.2em, 120%), hex colours, weights, transforms and scales, while excluding
 * every character needed to escape the declaration or the element. No
 * semicolon, braces, parentheses, colon, backslash or angle bracket, so
 * neither `</style>`, a second declaration, `url(...)` nor a CSS comment can be
 * smuggled through.
 *
 * @param string $value    Stored option value.
 * @param string $fallback Used when $value is empty after cleaning. An empty
 *                         CSS value is an invalid declaration, so a caller
 *                         should pass something usable ('inherit', a colour).
 * @return string Safe to echo directly into a stylesheet.
 */
function mtl_css_value( $value, $fallback = 'inherit' ) {
	$value = preg_replace( '/[^a-zA-Z0-9 ,.\-%\'"#]/', '', (string) $value );
	$value = trim( (string) $value );
	return '' !== $value ? $value : $fallback;
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
 * Formats a UTC datetime from the member-agreement tables for display in the
 * site's timezone.
 *
 * SEPARATE FROM mtl_format_date() on purpose. Every other date in this plugin
 * is a TIMESTAMP read back in the database session timezone; the two
 * member_agreement* tables store DATETIME holding explicit UTC. One helper
 * could not tell the two bases apart from the value alone, and a caller that
 * picked wrong would shift a legal timestamp by hours with no error anywhere.
 * The column type decides which you call.
 *
 * EVERY date from member_agreements and member_agreement_acceptances goes
 * through this, admin screens included: member receipt, staff detail panel,
 * Setup's "in use since", the record download, and both emails.
 *
 * @param string $utc    A 'Y-m-d H:i:s' string holding UTC.
 * @param string $format PHP date() format.
 * @return string Formatted in site time, or an em dash if empty/unparseable.
 */
function mtl_format_utc_datetime( $utc, $format = 'm/d/Y' ) {
	if ( empty( $utc ) ) {
		return '&mdash;';
	}
	try {
		$dt = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
	} catch ( Exception $e ) {
		return '&mdash;';
	}
	$dt->setTimezone( wp_timezone() );
	return esc_html( $dt->format( $format ) );
}

/**
 * Builds a member's postal address as two lines: street (plus unit, if any)
 * on line 1, "City, State ZIP, Country" on line 2 (see readme.txt's
 * "Assumptions and intended use"). Returns raw, unescaped, stripslashes()'d
 * strings; callers decide how to escape/join them for their context.
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

// ==========================================================================
// PHONE NUMBERS
//
// Every phone number in this plugin is collected as two pieces, a country
// (an ISO 3166-1 alpha-2 code, picked from a <select>) and a national number
// (free-typed digits), and stored as ONE canonical string:
// "+<calling code> <national number, grouped for readability>", e.g.
// "+1 (414) 555-0123" or "+44 20 7946 0958". There is no separate "country"
// column; the stored string is self-describing, and every existing display
// site in the plugin already just echoes phone_number as-is, so storing it
// pre-formatted means no display code anywhere needs to change.
//
// This is deliberately NOT a full international validator (that is what
// libphonenumber exists for, and this plugin takes no third-party
// dependencies; see readme.txt's FAQ). NANP numbers (country code 1: the
// US, Canada, and the Caribbean nations that share the same numbering plan)
// get real formatting, because that is this library's primary audience and
// the format is simple and fixed (10 digits, NXX-NXX-XXXX). Every other
// country gets a generic "group digits in 3s" treatment and a loose length
// sanity check (4-14 digits) rather than that country's true national
// format, which would require per-country metadata this plugin doesn't
// carry. See mtl_format_phone_number().
// ==========================================================================

/**
 * Country, ISO code, and calling code for every phone-number country
 * selector in the plugin (Signup, My Account, Add/Edit Member). Same country
 * set and order as mtl_get_country_options(): United States pinned first,
 * everything else alphabetical, so the two dropdowns read consistently,
 * keyed by ISO 3166-1 alpha-2 for O(1) lookup by mtl_format_phone_number()
 * and mtl_parse_stored_phone_number().
 *
 * Several entries share a calling code on purpose: the US, Canada, and every
 * NANP Caribbean nation all dial as country code 1 and format identically
 * (10-digit NXX-NXX-XXXX), so mtl_format_phone_number() only ever branches
 * on the code, never on which specific country was picked.
 *
 * @return array<string,array{country:string,code:string}>
 */
function mtl_get_phone_country_options() {
	return array(
		'US' => array(
			'country' => 'United States',
			'code'    => '1',
		),
		'AF' => array(
			'country' => 'Afghanistan',
			'code'    => '93',
		),
		'AL' => array(
			'country' => 'Albania',
			'code'    => '355',
		),
		'DZ' => array(
			'country' => 'Algeria',
			'code'    => '213',
		),
		'AD' => array(
			'country' => 'Andorra',
			'code'    => '376',
		),
		'AO' => array(
			'country' => 'Angola',
			'code'    => '244',
		),
		'AG' => array(
			'country' => 'Antigua and Barbuda',
			'code'    => '1',
		),
		'AR' => array(
			'country' => 'Argentina',
			'code'    => '54',
		),
		'AM' => array(
			'country' => 'Armenia',
			'code'    => '374',
		),
		'AU' => array(
			'country' => 'Australia',
			'code'    => '61',
		),
		'AT' => array(
			'country' => 'Austria',
			'code'    => '43',
		),
		'AZ' => array(
			'country' => 'Azerbaijan',
			'code'    => '994',
		),
		'BS' => array(
			'country' => 'Bahamas',
			'code'    => '1',
		),
		'BH' => array(
			'country' => 'Bahrain',
			'code'    => '973',
		),
		'BD' => array(
			'country' => 'Bangladesh',
			'code'    => '880',
		),
		'BB' => array(
			'country' => 'Barbados',
			'code'    => '1',
		),
		'BY' => array(
			'country' => 'Belarus',
			'code'    => '375',
		),
		'BE' => array(
			'country' => 'Belgium',
			'code'    => '32',
		),
		'BZ' => array(
			'country' => 'Belize',
			'code'    => '501',
		),
		'BJ' => array(
			'country' => 'Benin',
			'code'    => '229',
		),
		'BT' => array(
			'country' => 'Bhutan',
			'code'    => '975',
		),
		'BO' => array(
			'country' => 'Bolivia',
			'code'    => '591',
		),
		'BA' => array(
			'country' => 'Bosnia and Herzegovina',
			'code'    => '387',
		),
		'BW' => array(
			'country' => 'Botswana',
			'code'    => '267',
		),
		'BR' => array(
			'country' => 'Brazil',
			'code'    => '55',
		),
		'BN' => array(
			'country' => 'Brunei',
			'code'    => '673',
		),
		'BG' => array(
			'country' => 'Bulgaria',
			'code'    => '359',
		),
		'BF' => array(
			'country' => 'Burkina Faso',
			'code'    => '226',
		),
		'BI' => array(
			'country' => 'Burundi',
			'code'    => '257',
		),
		'CV' => array(
			'country' => 'Cabo Verde',
			'code'    => '238',
		),
		'KH' => array(
			'country' => 'Cambodia',
			'code'    => '855',
		),
		'CM' => array(
			'country' => 'Cameroon',
			'code'    => '237',
		),
		'CA' => array(
			'country' => 'Canada',
			'code'    => '1',
		),
		'CF' => array(
			'country' => 'Central African Republic',
			'code'    => '236',
		),
		'TD' => array(
			'country' => 'Chad',
			'code'    => '235',
		),
		'CL' => array(
			'country' => 'Chile',
			'code'    => '56',
		),
		'CN' => array(
			'country' => 'China',
			'code'    => '86',
		),
		'CO' => array(
			'country' => 'Colombia',
			'code'    => '57',
		),
		'KM' => array(
			'country' => 'Comoros',
			'code'    => '269',
		),
		'CG' => array(
			'country' => 'Congo (Congo-Brazzaville)',
			'code'    => '242',
		),
		'CR' => array(
			'country' => 'Costa Rica',
			'code'    => '506',
		),
		'HR' => array(
			'country' => 'Croatia',
			'code'    => '385',
		),
		'CU' => array(
			'country' => 'Cuba',
			'code'    => '53',
		),
		'CY' => array(
			'country' => 'Cyprus',
			'code'    => '357',
		),
		'CZ' => array(
			'country' => 'Czechia',
			'code'    => '420',
		),
		'CD' => array(
			'country' => 'Democratic Republic of the Congo',
			'code'    => '243',
		),
		'DK' => array(
			'country' => 'Denmark',
			'code'    => '45',
		),
		'DJ' => array(
			'country' => 'Djibouti',
			'code'    => '253',
		),
		'DM' => array(
			'country' => 'Dominica',
			'code'    => '1',
		),
		'DO' => array(
			'country' => 'Dominican Republic',
			'code'    => '1',
		),
		'EC' => array(
			'country' => 'Ecuador',
			'code'    => '593',
		),
		'EG' => array(
			'country' => 'Egypt',
			'code'    => '20',
		),
		'SV' => array(
			'country' => 'El Salvador',
			'code'    => '503',
		),
		'GQ' => array(
			'country' => 'Equatorial Guinea',
			'code'    => '240',
		),
		'ER' => array(
			'country' => 'Eritrea',
			'code'    => '291',
		),
		'EE' => array(
			'country' => 'Estonia',
			'code'    => '372',
		),
		'SZ' => array(
			'country' => 'Eswatini',
			'code'    => '268',
		),
		'ET' => array(
			'country' => 'Ethiopia',
			'code'    => '251',
		),
		'FJ' => array(
			'country' => 'Fiji',
			'code'    => '679',
		),
		'FI' => array(
			'country' => 'Finland',
			'code'    => '358',
		),
		'FR' => array(
			'country' => 'France',
			'code'    => '33',
		),
		'GA' => array(
			'country' => 'Gabon',
			'code'    => '241',
		),
		'GM' => array(
			'country' => 'Gambia',
			'code'    => '220',
		),
		'GE' => array(
			'country' => 'Georgia',
			'code'    => '995',
		),
		'DE' => array(
			'country' => 'Germany',
			'code'    => '49',
		),
		'GH' => array(
			'country' => 'Ghana',
			'code'    => '233',
		),
		'GR' => array(
			'country' => 'Greece',
			'code'    => '30',
		),
		'GD' => array(
			'country' => 'Grenada',
			'code'    => '1',
		),
		'GT' => array(
			'country' => 'Guatemala',
			'code'    => '502',
		),
		'GN' => array(
			'country' => 'Guinea',
			'code'    => '224',
		),
		'GW' => array(
			'country' => 'Guinea-Bissau',
			'code'    => '245',
		),
		'GY' => array(
			'country' => 'Guyana',
			'code'    => '592',
		),
		'HT' => array(
			'country' => 'Haiti',
			'code'    => '509',
		),
		'HN' => array(
			'country' => 'Honduras',
			'code'    => '504',
		),
		'HU' => array(
			'country' => 'Hungary',
			'code'    => '36',
		),
		'IS' => array(
			'country' => 'Iceland',
			'code'    => '354',
		),
		'IN' => array(
			'country' => 'India',
			'code'    => '91',
		),
		'ID' => array(
			'country' => 'Indonesia',
			'code'    => '62',
		),
		'IR' => array(
			'country' => 'Iran',
			'code'    => '98',
		),
		'IQ' => array(
			'country' => 'Iraq',
			'code'    => '964',
		),
		'IE' => array(
			'country' => 'Ireland',
			'code'    => '353',
		),
		'IL' => array(
			'country' => 'Israel',
			'code'    => '972',
		),
		'IT' => array(
			'country' => 'Italy',
			'code'    => '39',
		),
		'CI' => array(
			'country' => 'Ivory Coast',
			'code'    => '225',
		),
		'JM' => array(
			'country' => 'Jamaica',
			'code'    => '1',
		),
		'JP' => array(
			'country' => 'Japan',
			'code'    => '81',
		),
		'JO' => array(
			'country' => 'Jordan',
			'code'    => '962',
		),
		'KZ' => array(
			'country' => 'Kazakhstan',
			'code'    => '7',
		),
		'KE' => array(
			'country' => 'Kenya',
			'code'    => '254',
		),
		'KI' => array(
			'country' => 'Kiribati',
			'code'    => '686',
		),
		'XK' => array(
			'country' => 'Kosovo',
			'code'    => '383',
		),
		'KW' => array(
			'country' => 'Kuwait',
			'code'    => '965',
		),
		'KG' => array(
			'country' => 'Kyrgyzstan',
			'code'    => '996',
		),
		'LA' => array(
			'country' => 'Laos',
			'code'    => '856',
		),
		'LV' => array(
			'country' => 'Latvia',
			'code'    => '371',
		),
		'LB' => array(
			'country' => 'Lebanon',
			'code'    => '961',
		),
		'LS' => array(
			'country' => 'Lesotho',
			'code'    => '266',
		),
		'LR' => array(
			'country' => 'Liberia',
			'code'    => '231',
		),
		'LY' => array(
			'country' => 'Libya',
			'code'    => '218',
		),
		'LI' => array(
			'country' => 'Liechtenstein',
			'code'    => '423',
		),
		'LT' => array(
			'country' => 'Lithuania',
			'code'    => '370',
		),
		'LU' => array(
			'country' => 'Luxembourg',
			'code'    => '352',
		),
		'MG' => array(
			'country' => 'Madagascar',
			'code'    => '261',
		),
		'MW' => array(
			'country' => 'Malawi',
			'code'    => '265',
		),
		'MY' => array(
			'country' => 'Malaysia',
			'code'    => '60',
		),
		'MV' => array(
			'country' => 'Maldives',
			'code'    => '960',
		),
		'ML' => array(
			'country' => 'Mali',
			'code'    => '223',
		),
		'MT' => array(
			'country' => 'Malta',
			'code'    => '356',
		),
		'MH' => array(
			'country' => 'Marshall Islands',
			'code'    => '692',
		),
		'MR' => array(
			'country' => 'Mauritania',
			'code'    => '222',
		),
		'MU' => array(
			'country' => 'Mauritius',
			'code'    => '230',
		),
		'MX' => array(
			'country' => 'Mexico',
			'code'    => '52',
		),
		'FM' => array(
			'country' => 'Micronesia',
			'code'    => '691',
		),
		'MD' => array(
			'country' => 'Moldova',
			'code'    => '373',
		),
		'MC' => array(
			'country' => 'Monaco',
			'code'    => '377',
		),
		'MN' => array(
			'country' => 'Mongolia',
			'code'    => '976',
		),
		'ME' => array(
			'country' => 'Montenegro',
			'code'    => '382',
		),
		'MA' => array(
			'country' => 'Morocco',
			'code'    => '212',
		),
		'MZ' => array(
			'country' => 'Mozambique',
			'code'    => '258',
		),
		'MM' => array(
			'country' => 'Myanmar',
			'code'    => '95',
		),
		'NA' => array(
			'country' => 'Namibia',
			'code'    => '264',
		),
		'NR' => array(
			'country' => 'Nauru',
			'code'    => '674',
		),
		'NP' => array(
			'country' => 'Nepal',
			'code'    => '977',
		),
		'NL' => array(
			'country' => 'Netherlands',
			'code'    => '31',
		),
		'NZ' => array(
			'country' => 'New Zealand',
			'code'    => '64',
		),
		'NI' => array(
			'country' => 'Nicaragua',
			'code'    => '505',
		),
		'NE' => array(
			'country' => 'Niger',
			'code'    => '227',
		),
		'NG' => array(
			'country' => 'Nigeria',
			'code'    => '234',
		),
		'KP' => array(
			'country' => 'North Korea',
			'code'    => '850',
		),
		'MK' => array(
			'country' => 'North Macedonia',
			'code'    => '389',
		),
		'NO' => array(
			'country' => 'Norway',
			'code'    => '47',
		),
		'OM' => array(
			'country' => 'Oman',
			'code'    => '968',
		),
		'PK' => array(
			'country' => 'Pakistan',
			'code'    => '92',
		),
		'PW' => array(
			'country' => 'Palau',
			'code'    => '680',
		),
		'PS' => array(
			'country' => 'Palestine',
			'code'    => '970',
		),
		'PA' => array(
			'country' => 'Panama',
			'code'    => '507',
		),
		'PG' => array(
			'country' => 'Papua New Guinea',
			'code'    => '675',
		),
		'PY' => array(
			'country' => 'Paraguay',
			'code'    => '595',
		),
		'PE' => array(
			'country' => 'Peru',
			'code'    => '51',
		),
		'PH' => array(
			'country' => 'Philippines',
			'code'    => '63',
		),
		'PL' => array(
			'country' => 'Poland',
			'code'    => '48',
		),
		'PT' => array(
			'country' => 'Portugal',
			'code'    => '351',
		),
		'QA' => array(
			'country' => 'Qatar',
			'code'    => '974',
		),
		'RO' => array(
			'country' => 'Romania',
			'code'    => '40',
		),
		'RU' => array(
			'country' => 'Russia',
			'code'    => '7',
		),
		'RW' => array(
			'country' => 'Rwanda',
			'code'    => '250',
		),
		'KN' => array(
			'country' => 'Saint Kitts and Nevis',
			'code'    => '1',
		),
		'LC' => array(
			'country' => 'Saint Lucia',
			'code'    => '1',
		),
		'VC' => array(
			'country' => 'Saint Vincent and the Grenadines',
			'code'    => '1',
		),
		'WS' => array(
			'country' => 'Samoa',
			'code'    => '685',
		),
		'SM' => array(
			'country' => 'San Marino',
			'code'    => '378',
		),
		'ST' => array(
			'country' => 'Sao Tome and Principe',
			'code'    => '239',
		),
		'SA' => array(
			'country' => 'Saudi Arabia',
			'code'    => '966',
		),
		'SN' => array(
			'country' => 'Senegal',
			'code'    => '221',
		),
		'RS' => array(
			'country' => 'Serbia',
			'code'    => '381',
		),
		'SC' => array(
			'country' => 'Seychelles',
			'code'    => '248',
		),
		'SL' => array(
			'country' => 'Sierra Leone',
			'code'    => '232',
		),
		'SG' => array(
			'country' => 'Singapore',
			'code'    => '65',
		),
		'SK' => array(
			'country' => 'Slovakia',
			'code'    => '421',
		),
		'SI' => array(
			'country' => 'Slovenia',
			'code'    => '386',
		),
		'SB' => array(
			'country' => 'Solomon Islands',
			'code'    => '677',
		),
		'SO' => array(
			'country' => 'Somalia',
			'code'    => '252',
		),
		'ZA' => array(
			'country' => 'South Africa',
			'code'    => '27',
		),
		'KR' => array(
			'country' => 'South Korea',
			'code'    => '82',
		),
		'SS' => array(
			'country' => 'South Sudan',
			'code'    => '211',
		),
		'ES' => array(
			'country' => 'Spain',
			'code'    => '34',
		),
		'LK' => array(
			'country' => 'Sri Lanka',
			'code'    => '94',
		),
		'SD' => array(
			'country' => 'Sudan',
			'code'    => '249',
		),
		'SR' => array(
			'country' => 'Suriname',
			'code'    => '597',
		),
		'SE' => array(
			'country' => 'Sweden',
			'code'    => '46',
		),
		'CH' => array(
			'country' => 'Switzerland',
			'code'    => '41',
		),
		'SY' => array(
			'country' => 'Syria',
			'code'    => '963',
		),
		'TW' => array(
			'country' => 'Taiwan',
			'code'    => '886',
		),
		'TJ' => array(
			'country' => 'Tajikistan',
			'code'    => '992',
		),
		'TZ' => array(
			'country' => 'Tanzania',
			'code'    => '255',
		),
		'TH' => array(
			'country' => 'Thailand',
			'code'    => '66',
		),
		'TL' => array(
			'country' => 'Timor-Leste',
			'code'    => '670',
		),
		'TG' => array(
			'country' => 'Togo',
			'code'    => '228',
		),
		'TO' => array(
			'country' => 'Tonga',
			'code'    => '676',
		),
		'TT' => array(
			'country' => 'Trinidad and Tobago',
			'code'    => '1',
		),
		'TN' => array(
			'country' => 'Tunisia',
			'code'    => '216',
		),
		'TR' => array(
			'country' => 'Turkey',
			'code'    => '90',
		),
		'TM' => array(
			'country' => 'Turkmenistan',
			'code'    => '993',
		),
		'TV' => array(
			'country' => 'Tuvalu',
			'code'    => '688',
		),
		'UG' => array(
			'country' => 'Uganda',
			'code'    => '256',
		),
		'UA' => array(
			'country' => 'Ukraine',
			'code'    => '380',
		),
		'AE' => array(
			'country' => 'United Arab Emirates',
			'code'    => '971',
		),
		'GB' => array(
			'country' => 'United Kingdom',
			'code'    => '44',
		),
		'UY' => array(
			'country' => 'Uruguay',
			'code'    => '598',
		),
		'UZ' => array(
			'country' => 'Uzbekistan',
			'code'    => '998',
		),
		'VU' => array(
			'country' => 'Vanuatu',
			'code'    => '678',
		),
		'VA' => array(
			'country' => 'Vatican City',
			'code'    => '39',
		),
		'VE' => array(
			'country' => 'Venezuela',
			'code'    => '58',
		),
		'VN' => array(
			'country' => 'Vietnam',
			'code'    => '84',
		),
		'YE' => array(
			'country' => 'Yemen',
			'code'    => '967',
		),
		'ZM' => array(
			'country' => 'Zambia',
			'code'    => '260',
		),
		'ZW' => array(
			'country' => 'Zimbabwe',
			'code'    => '263',
		),
	);
}

/**
 * Whitelists a phone-country ISO code against mtl_get_phone_country_options(),
 * same purpose as mtl_valid_state()/mtl_valid_country(). Falls back to "US"
 * rather than '', because there is no blank option in the phone country <select>
 * (it always has a real selection, U.S. pinned first/default), so an invalid
 * or tampered value should behave exactly like nothing was selected at all.
 *
 * @param string $value Posted ISO code.
 * @return string A valid ISO code: $value if it was one, else 'US'.
 */
function mtl_valid_phone_country( $value ) {
	$value = strtoupper( trim( (string) $value ) );
	return isset( mtl_get_phone_country_options()[ $value ] ) ? $value : 'US';
}

/**
 * Groups a digit string into a generic, readable "chunks of 3" phone format
 * for any country this plugin doesn't have real formatting rules for (see
 * the PHONE NUMBERS block comment above). Chunks from the RIGHT so a
 * leftover short group lands at the front (e.g. "2079460958" -> "2 079 460
 * 958"), never as a lone trailing digit.
 *
 * @param string $digits Digits only, no punctuation.
 * @return string Space-separated groups.
 */
function mtl_group_digits_generic( $digits ) {
	$groups = array();
	for ( $i = strlen( $digits ); $i > 0; $i -= 3 ) {
		$start = max( 0, $i - 3 );
		array_unshift( $groups, substr( $digits, $start, $i - $start ) );
	}
	return implode( ' ', $groups );
}

/**
 * Validates and formats a phone number into this plugin's canonical stored
 * form: "+<calling code> <national number>". This is the single source of
 * truth for every phone_number write in the plugin (Signup, Account edit,
 * Add/Edit Member, CSV import). The client-side live formatter
 * (mtl_phone_formatter_script()) is cosmetic only; this is what actually
 * gets validated and stored, regardless of what the browser sent.
 *
 * @param string $iso          Country ISO code from the phone <select> (see
 *                              mtl_get_phone_country_options()); coerced to
 *                              "US" if invalid via mtl_valid_phone_country().
 * @param string $national_raw Raw national-number text. Any punctuation,
 *                              spaces, or a habitually-typed leading "+code"
 *                              / NANP "1" are stripped before formatting.
 * @return array{value:string,error:string} value is '' on failure; error is
 *         '' on success.
 */
function mtl_format_phone_number( $iso, $national_raw ) {
	$iso       = mtl_valid_phone_country( $iso );
	$countries = mtl_get_phone_country_options();
	$code      = $countries[ $iso ]['code'];

	$digits = preg_replace( '/\D+/', '', (string) $national_raw );

	// Trim a redundant country/NANP prefix the member typed out of habit,
	// e.g. selecting "United States" and then typing "1-414-555-0123" or
	// "+1 414 555 0123". Conservative: only trimmed when enough digits are
	// left afterward to plausibly still be a real number.
	if ( '1' === $code && 11 === strlen( $digits ) && '1' === $digits[0] ) {
		$digits = substr( $digits, 1 );
	} elseif ( '1' !== $code && 0 === strpos( $digits, $code ) && strlen( $digits ) - strlen( $code ) >= 4 ) {
		$digits = substr( $digits, strlen( $code ) );
	}

	if ( '' === $digits ) {
		return array(
			'value' => '',
			'error' => 'Please enter a phone number.',
		);
	}

	if ( '1' === $code ) {
		// NANP: always exactly 10 digits (area code + exchange + line).
		if ( 10 !== strlen( $digits ) ) {
			return array(
				'value' => '',
				'error' => 'Please enter a valid 10-digit U.S./Canada phone number.',
			);
		}
		$formatted = '(' . substr( $digits, 0, 3 ) . ') ' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
	} else {
		// No per-country metadata to check against (see the PHONE NUMBERS
		// block comment), just a loose sanity range. 4 is short enough to
		// cover small nations' shortest lines; 14 is the longest a national
		// number can be and still fit an E.164 total of 15 digits alongside
		// a 1-digit calling code.
		if ( strlen( $digits ) < 4 || strlen( $digits ) > 14 ) {
			return array(
				'value' => '',
				'error' => 'Please enter a valid phone number.',
			);
		}
		$formatted = mtl_group_digits_generic( $digits );
	}

	return array(
		'value' => '+' . $code . ' ' . $formatted,
		'error' => '',
	);
}

/**
 * Splits a stored phone_number value back into (ISO country, raw national
 * digits) for redisplaying in the two-part editor: Edit Member's prefill,
 * and CSV bulk import's per-row parsing (a CSV cell is just another external
 * representation of the same "maybe has a country prefix, maybe doesn't"
 * text this function already has to handle).
 *
 * A value with no leading "+" is read as a legacy NANP number, since every
 * phone_number in this plugin was stored as plain NANP text before this
 * feature existed, with no country code at all.
 *
 * Ambiguity note: several countries share calling code 1 (US, Canada, and
 * NANP Caribbean nations). A stored "+1 ..." value can't be traced back to
 * which of them was originally selected, so this always resolves a shared
 * code to whichever of those countries is listed first in
 * mtl_get_phone_country_options() (the United States). That only affects
 * which country name is pre-selected on re-edit; formatting and storage
 * are identical for all of them either way.
 *
 * @param string $stored Raw phone_number value (from the DB or a CSV cell).
 * @return array{iso:string,national:string}
 */
function mtl_parse_stored_phone_number( $stored ) {
	$stored = trim( (string) $stored );
	if ( '' === $stored ) {
		return array(
			'iso'      => 'US',
			'national' => '',
		);
	}

	if ( '+' !== substr( $stored, 0, 1 ) ) {
		return array(
			'iso'      => 'US',
			'national' => preg_replace( '/\D+/', '', $stored ),
		);
	}

	$digits    = preg_replace( '/\D+/', '', $stored );
	$countries = mtl_get_phone_country_options();
	// Calling codes are 1-3 digits; try the longest prefix first so e.g. a
	// 2-digit code isn't mistaken for a 1-digit code plus wrong leftovers.
	foreach ( array( 3, 2, 1 ) as $len ) {
		$candidate = substr( $digits, 0, $len );
		foreach ( $countries as $iso => $info ) {
			if ( $info['code'] === $candidate ) {
				return array(
					'iso'      => $iso,
					'national' => substr( $digits, $len ),
				);
			}
		}
	}

	return array(
		'iso'      => 'US',
		'national' => $digits,
	);
}

/**
 * Renders the two-part phone number control (country <select> + national
 * number text input) shared by every place a phone number is collected:
 * Signup, My Account, and the admin Add/Edit Member form, so the option
 * list, markup, and JS hook (.mtl-phone-widget) can never drift between
 * them. Echoes directly (matches mtl_render_member_form_fields()'s own
 * style); every value is escaped inline, so no customEscapingFunctions entry
 * is needed.
 *
 * Both inputs are named phone_country / phone_national in every caller.
 * That's safe even when two instances of this widget are on the same PAGE at
 * once (the admin Membership page's Add and Edit forms both call this);
 * each instance lives inside its own <form>, so only that form's own fields
 * are ever submitted together. $id_prefix only needs to keep the *ids*
 * unique page-wide, same purpose as everywhere else that pattern is used.
 *
 * @param string $iso       Selected ISO country code.
 * @param string $national  Raw national-number text to prefill.
 * @param string $id_prefix Prefix for element ids, e.g. "edit_".
 */
function mtl_render_phone_input( $iso, $national, $id_prefix = '' ) {
	$countries  = mtl_get_phone_country_options();
	$iso        = mtl_valid_phone_country( $iso );
	$codes_json = wp_json_encode( wp_list_pluck( $countries, 'code' ) );
	?>
	<div class="mtl-phone-widget" data-codes="<?php echo esc_attr( $codes_json ); ?>">
		<select name="phone_country" id="<?php echo esc_attr( $id_prefix . 'phone_country' ); ?>" class="mtl-phone-country" required>
			<?php foreach ( $countries as $c_iso => $info ) : ?>
				<option value="<?php echo esc_attr( $c_iso ); ?>" <?php selected( $iso, $c_iso ); ?>><?php echo esc_html( $info['country'] ); ?> (+<?php echo esc_html( $info['code'] ); ?>)</option>
			<?php endforeach; ?>
		</select>
		<input type="tel" name="phone_national" id="<?php echo esc_attr( $id_prefix . 'phone_national' ); ?>" class="mtl-phone-national" value="<?php echo esc_attr( $national ); ?>" placeholder="(414) 555-0123" required>
	</div>
	<?php
}

/**
 * Live-formatting <script> for every .mtl-phone-widget on the current page
 * (there can be more than one, e.g. the admin Membership page's Add and
 * Edit forms are both in the DOM at once; one call here covers all of them).
 * Purely cosmetic: mtl_format_phone_number() always re-derives the canonical
 * value from scratch server-side on submit, using only the digits, so
 * nothing typed here has to be trusted.
 */
function mtl_phone_formatter_script() {
	?>
	<script>
	( function () {
		document.querySelectorAll( '.mtl-phone-widget' ).forEach( function ( widget ) {
			var countrySelect = widget.querySelector( '.mtl-phone-country' );
			var numberInput   = widget.querySelector( '.mtl-phone-national' );
			if ( ! countrySelect || ! numberInput ) {
				return;
			}
			var codes = {};
			try {
				codes = JSON.parse( widget.getAttribute( 'data-codes' ) || '{}' );
			} catch ( e ) {
				codes = {};
			}

			function format() {
				var code   = codes[ countrySelect.value ] || '1';
				var digits = numberInput.value.replace( /\D+/g, '' );

				if ( '1' === code ) {
					if ( digits.length > 10 ) {
						digits = digits.slice( -10 );
					}
					var area = digits.slice( 0, 3 );
					var mid  = digits.slice( 3, 6 );
					var last = digits.slice( 6, 10 );
					var out  = '';
					if ( area ) {
						out = '(' + area;
					}
					if ( 3 === area.length ) {
						out += ') ';
					}
					out += mid;
					if ( 3 === mid.length && last ) {
						out += '-';
					}
					out += last;
					numberInput.value = out;
					return;
				}

				if ( digits.length > 14 ) {
					digits = digits.slice( 0, 14 );
				}
				var groups = [];
				for ( var i = digits.length; i > 0; i -= 3 ) {
					groups.unshift( digits.slice( Math.max( 0, i - 3 ), i ) );
				}
				numberInput.value = groups.join( ' ' );
			}

			numberInput.addEventListener( 'input', format );
			countrySelect.addEventListener( 'change', format );
			if ( numberInput.value ) {
				format();
			}
		} );
	}() );
	</script>
	<?php
}

// ==========================================================================
// TRAININGS
//
// A training (member_trainings) has a name, an optional badge image, and an
// optional certification_length_months. A member holds a training via
// member_training_mappings, which records the start_date they completed it.
// A tool requires one via tool_training_mappings.
//
// Expiry is always DERIVED from those two, never stored; see
// mtl_training_expiry_date(). That means an admin editing a training's
// certification length on the Setup page instantly re-dates every member who
// holds it, with no backfill step and no stale copies to go wrong.
//
// "Current" vs "expired" matters in four different places, and they
// deliberately behave differently:
// - My Account badge images: current trainings only.
// - My Account trainings table: everything, current and expired, with the
// status spelled out.
// - Membership filter: current only, since the question staff are asking
// is "who is qualified to use this tool today".
// - Checkout: the tool's required trainings, current only; a lapsed one
// reads the same as one never taken. See mtl_tool_training_gap().
// ==========================================================================

/**
 * The date a member's training certification lapses.
 *
 * @param string   $start_date Mapping start_date (Y-m-d).
 * @param int|null $months     Training certification_length_months; null/0
 *                             means the certification never expires.
 * @return string Expiry date as Y-m-d, or '' when it never expires (or the
 *                start date is unusable).
 */
function mtl_training_expiry_date( $start_date, $months ) {
	$months     = (int) $months;
	$start_date = trim( (string) $start_date );
	if ( $months <= 0 || '' === $start_date ) {
		return '';
	}
	$ts = strtotime( $start_date );
	if ( ! $ts ) {
		return '';
	}
	// PHP's relative month arithmetic OVERFLOWS a short month rather than
	// clamping to its last day: 31 Jan + 1 month is 3 March (not 28 Feb),
	// and a 29 Feb start + 12 months is 1 March (not 28 Feb). Left as-is
	// deliberately: the drift is at most a few days, it always lands in the
	// member's favour (certification lasts slightly longer, never cut short),
	// and hand-rolled clamping is more date arithmetic to get wrong than the
	// problem is worth for a tool-library certification.
	return gmdate( 'Y-m-d', strtotime( '+' . $months . ' months', $ts ) );
}

/**
 * Whether a member's training certification is still current today.
 *
 * A training with no certification length never expires and is always
 * current. Expiry day itself still counts as current, since the certification
 * lapses at the END of that day, which is what "valid for 12 months" means
 * to the person holding it.
 *
 * @param string   $start_date Mapping start_date (Y-m-d).
 * @param int|null $months     Training certification_length_months.
 * @return bool
 */
function mtl_training_is_current( $start_date, $months ) {
	$expiry = mtl_training_expiry_date( $start_date, $months );
	if ( '' === $expiry ) {
		return true;
	}
	return current_time( 'Y-m-d' ) <= $expiry;
}

/**
 * The trainings a member still needs before this tool should go out.
 *
 * A tool's requirements live in tool_training_mappings; whether the member
 * satisfies one is decided by mtl_training_is_current() against the start_date
 * in member_training_mappings. A lapsed certification counts the same as one
 * never taken, since both mean the member is not currently qualified.
 *
 * @param int $tool_id   Tool being borrowed.
 * @param int $member_id Member borrowing it.
 * @return string[] Names of the missing or expired trainings, in name order.
 *                  Empty when the member is clear, or the tool requires none.
 */
function mtl_tool_training_gap( $tool_id, $member_id ) {
	global $wpdb;

	$tool_id   = (int) $tool_id;
	$member_id = (int) $member_id;
	if ( $tool_id <= 0 || $member_id <= 0 ) {
		return array();
	}

	$tbl_tool_map   = $wpdb->prefix . 'tool_training_mappings';
	$tbl_trainings  = $wpdb->prefix . 'member_trainings';
	$tbl_member_map = $wpdb->prefix . 'member_training_mappings';

	// LEFT JOIN: a training the member never took must still come back, with a
	// NULL start_date.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix; both ids are prepared.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.training_name, t.certification_length_months, mtm.start_date
			FROM {$tbl_tool_map} ttm
			INNER JOIN {$tbl_trainings} t ON t.training_id = ttm.training_id
			LEFT JOIN {$tbl_member_map} mtm ON mtm.training_id = ttm.training_id AND mtm.member_id = %d
			WHERE ttm.tool_id = %d
			ORDER BY t.training_name ASC",
			$member_id,
			$tool_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$missing = array();
	foreach ( (array) $rows as $row ) {
		// mtl_training_is_current() reads a blank start_date as current, so the
		// missing row must be tested first.
		if ( '' === (string) $row->start_date
			|| ! mtl_training_is_current( $row->start_date, $row->certification_length_months ) ) {
			$missing[] = $row->training_name;
		}
	}
	return $missing;
}

/**
 * Renders the trainings picker used by the admin Add/Edit Member forms: one
 * checkbox per training, each with its own start-date input that only
 * matters when the box is ticked.
 *
 * This replaced a plain <select multiple>, which could record WHICH
 * trainings a member held but had nowhere to put the date each was
 * completed on, and without a date there is nothing to expire.
 *
 * Posts two parallel fields: training_id[] (the ticked ids) and
 * training_start[<id>] (that training's date). The handler only reads a
 * start date for an id that was actually ticked, so a date left behind from
 * un-ticking a box is harmless.
 *
 * @param array  $trainings  Training rows (training_id, training_name, ...).
 * @param array  $selected   member_id-agnostic map of training_id => start_date
 *                           (Y-m-d) for the trainings this member holds.
 * @param string $id_prefix  Prefix for element ids, e.g. "edit_", so two
 *                           instances on one page keep unique ids.
 */
function mtl_render_trainings_picker( $trainings, $selected, $id_prefix = '' ) {
	if ( empty( $trainings ) ) {
		?>
		<p style="font-size: 0.85em; color: #666; margin: 0;">No trainings have been set up yet. Add them under <strong>Setup &rarr; Member Trainings</strong>, then they&rsquo;ll be selectable here.</p>
		<?php
		return;
	}
	$today = current_time( 'Y-m-d' );
	?>
	<div class="mtl-trainings-picker">
		<?php foreach ( $trainings as $mtl_training ) : ?>
			<?php
			$mtl_tid     = (int) $mtl_training->training_id;
			$mtl_checked = array_key_exists( $mtl_tid, $selected );
			// An unticked row still needs a sensible date sitting ready for
			// when it IS ticked, so default it to today rather than blank.
			$mtl_start = $mtl_checked ? $selected[ $mtl_tid ] : $today;
			$mtl_cid   = $id_prefix . 'training_' . $mtl_tid;
			?>
			<div class="mtl-training-row">
				<label class="mtl-training-check" for="<?php echo esc_attr( $mtl_cid ); ?>">
					<input type="checkbox" name="training_id[]" id="<?php echo esc_attr( $mtl_cid ); ?>" value="<?php echo esc_attr( $mtl_tid ); ?>" <?php checked( $mtl_checked ); ?>>
					<span><?php echo esc_html( $mtl_training->training_name ); ?></span>
				</label>
				<label class="mtl-training-date">
					<span>Completed</span>
					<input type="date" name="training_start[<?php echo esc_attr( $mtl_tid ); ?>]" value="<?php echo esc_attr( $mtl_start ); ?>" <?php disabled( ! $mtl_checked ); ?>>
				</label>
				<?php
				$mtl_len = (int) ( isset( $mtl_training->certification_length_months ) ? $mtl_training->certification_length_months : 0 );
				?>
				<span class="mtl-training-len">
					<?php echo $mtl_len > 0 ? esc_html( 'valid ' . $mtl_len . ' month' . ( 1 === $mtl_len ? '' : 's' ) ) : 'never expires'; ?>
				</span>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Styles + behavior for every .mtl-trainings-picker on the page. The date
 * input next to an unticked training is disabled, so it neither submits nor
 * invites the admin to fill in a date for a training the member doesn't
 * hold; ticking the box enables it.
 *
 * Emitted once per page (the admin Membership page has two pickers, Add
 * and Edit, in the DOM at once), same pairing as
 * mtl_render_phone_input()/mtl_phone_formatter_script().
 */
function mtl_trainings_picker_script() {
	?>
	<style>
		.mtl-trainings-picker {
			border: 1px solid #dcdcde;
			border-radius: 4px;
			padding: 4px 10px;
			max-width: 520px;
			max-height: 240px;
			overflow-y: auto;
			background: #fff;
		}
		.mtl-training-row {
			display: flex;
			align-items: center;
			gap: 10px;
			padding: 5px 0;
			border-bottom: 1px solid #f0f0f1;
		}
		.mtl-training-row:last-child {
			border-bottom: 0;
		}
		.mtl-training-check {
			flex: 1 1 auto;
			display: flex;
			align-items: center;
			gap: 6px;
			cursor: pointer;
			font-weight: 600;
		}
		.mtl-training-date {
			display: flex;
			align-items: center;
			gap: 5px;
			font-size: 0.85em;
			color: #646970;
		}
		.mtl-training-date input[disabled] {
			background: #f6f7f7;
			color: #a7aaad;
		}
		.mtl-training-len {
			flex: 0 0 auto;
			font-size: 0.8em;
			color: #787c82;
			min-width: 96px;
			text-align: right;
		}
	</style>
	<script>
	( function () {
		document.querySelectorAll( '.mtl-trainings-picker' ).forEach( function ( picker ) {
			picker.querySelectorAll( '.mtl-training-row' ).forEach( function ( row ) {
				var box  = row.querySelector( 'input[type="checkbox"]' );
				var date = row.querySelector( 'input[type="date"]' );
				if ( ! box || ! date ) {
					return;
				}
				box.addEventListener( 'change', function () {
					date.disabled = ! box.checked;
					// Never leave a ticked training without a date: if the
					// admin cleared it while it was disabled, put today back.
					if ( box.checked && ! date.value ) {
						date.value = new Date().toISOString().slice( 0, 10 );
					}
				} );
			} );
		} );
	}() );
	</script>
	<?php
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
 * Finds the WordPress account linked to a member row, but only when that
 * account still proves the link: its email must match the member row AND its
 * mtl_member_id must still point back at that row. A member added by staff
 * with no online account has none, and returns 0.
 *
 * Resolving by email FIRST (rather than by the mtl_member_id meta value) is
 * deliberate. member_id is AUTO_INCREMENT and restarts at 1 whenever the
 * Setup page rebuilds the tables, so after a reset several surviving accounts
 * can carry the same stale mtl_member_id: one already repaired by
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
 * Diagnostics only. This is how the plugin notices a link it can no longer
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

/**
 * The account using this address, but only when it is a member's own rather
 * than somebody else's.
 *
 * Only this plugin ever writes mtl_member_id, so its presence is what separates
 * an account created for a member from an administrator's, an editor's, or an
 * unrelated login that happens to share the address. The value is not checked:
 * after a Setup > Set Up Database rebuild every surviving account points at a
 * member id that no longer means anything, and those are exactly the accounts
 * this is here to recognise.
 *
 * @param string $email Address to look up.
 * @return int WP user ID, or 0 if there is no account or it is not a member's.
 */
function mtl_member_account_id_by_email( $email ) {
	$user = get_user_by( 'email', trim( (string) $email ) );
	if ( ! $user ) {
		return 0;
	}
	if ( '' === (string) get_user_meta( $user->ID, 'mtl_member_id', true ) ) {
		return 0;
	}
	return (int) $user->ID;
}

/**
 * Whether a member record using this address could never be given a sign-in,
 * because the address already belongs to a non-member account.
 *
 * The narrow question the Add and Import validators need. A plain
 * email_exists() is the wrong test there: it also catches a member's own
 * account surviving a database rebuild, and rejecting those would break the
 * documented way of reconnecting members afterwards, by re-adding them with the
 * same address (see the staff guide, "Members' online accounts and the database
 * reset").
 *
 * @param string $email Address to check.
 * @return bool
 */
function mtl_email_taken_by_non_member( $email ) {
	$email = trim( (string) $email );
	if ( ! email_exists( $email ) ) {
		return false;
	}
	return 0 === mtl_member_account_id_by_email( $email );
}

/**
 * Creates the WordPress sign-in for a member row that has none.
 *
 * Staff-added and CSV-imported members get a {prefix}members row but no
 * account, which used to leave them unable to sign in, sign up, or reset a
 * password. This is the one place that gap is closed, shared by the Add Member
 * handler, the batch backfill, and the lost-password self-heal, so all three
 * produce identical accounts.
 *
 * Takes only the member id and reads the rest from the row on purpose: a
 * caller passing an email that no longer matches the record is exactly how an
 * account ends up linked to the wrong person.
 *
 * No password is chosen here. The account gets an unguessable random one purely
 * to occupy the hash field; the member sets a real password through the link
 * mtl_send_member_setup_email() sends.
 *
 * @param int $member_id Member row ID.
 * @return int|WP_Error New WP user ID, or WP_Error explaining why not.
 */
function mtl_create_member_login( $member_id ) {
	global $wpdb;

	$member_id = (int) $member_id;
	if ( $member_id <= 0 ) {
		return new WP_Error( 'mtl_bad_member_id', 'No member record was identified.' );
	}

	$tbl = $wpdb->prefix . 'members';
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT member_id, first_name, last_name, email, anonymized_at FROM {$tbl} WHERE member_id = %d",
			$member_id
		)
	);

	if ( ! $row ) {
		return new WP_Error( 'mtl_no_member', 'That member record no longer exists.' );
	}

	// An anonymized row is a deleted person. Their address has been rewritten to
	// the reserved deleted-member-<id>@example.invalid form, which is_email()
	// accepts quite happily because WordPress does not validate TLDs, so
	// without this guard a backfill would mint accounts for people who asked to
	// be forgotten. See mtl_delete_or_anonymize_member().
	if ( null !== $row->anonymized_at ) {
		return new WP_Error( 'mtl_member_anonymized', 'That member record has been anonymized.' );
	}

	$email = trim( (string) $row->email );
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'mtl_bad_member_email', 'That member has no usable email address.' );
	}

	// A member's own account already on this address, almost always one that
	// outlived a Setup > Set Up Database rebuild. Point it back at the new row
	// rather than refusing: this is the documented way to reconnect members
	// after a reset, and it is the same repair mtl_current_member() performs by
	// email on their next sign-in, just done now instead of then.
	//
	// Deliberately does NOT mark it setup-pending. They already have a working
	// password, and flagging them would put them on the outstanding list and
	// email them a link they never asked for.
	$existing_member_account = mtl_member_account_id_by_email( $email );
	if ( $existing_member_account > 0 ) {
		update_user_meta( $existing_member_account, 'mtl_member_id', $member_id );
		return $existing_member_account;
	}

	// Anything else on this address belongs to somebody who is not this member.
	// Never adopt it: claiming an administrator's account by address alone would
	// hand it this member's name, address and loan history, the disclosure
	// mtl_current_member() deliberately fails closed to avoid.
	if ( email_exists( $email ) ) {
		return new WP_Error(
			'email_taken_by_other_account',
			'A WordPress account that is not a member sign-in already uses that email address, so one could not be created for this member. Sort it out under Users, then try again.'
		);
	}

	// Belt and braces. The role registers on init and every caller runs later,
	// but were it ever missing, wp_insert_user() would still succeed and write
	// the role name with no capabilities behind it, so a whole batch of accounts
	// that cannot even read, and nothing in the output to say so.
	mtl_register_member_role();

	// user_login is capped at 60 characters by wp_insert_user() while
	// members.email allows 100. Identity is matched on user_email everywhere
	// (see mtl_current_member()), so the login is only a label and a longer
	// address can safely fall back to something generated.
	$login = ( mb_strlen( $email ) <= 60 ) ? $email : 'mtl_member_' . $member_id;

	$first = trim( (string) $row->first_name );
	$last  = trim( (string) $row->last_name );

	$user_id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ),
			// Hardcoded, and must stay that way: a role reaching this array from
			// a caller or a request is the one mistake here that would turn
			// member import into privilege escalation.
			'role'         => 'mtl_member',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	update_user_meta( $user_id, 'mtl_member_id', $member_id );
	// Marks an account whose owner has never chosen a password. Cleared by
	// mtl_clear_setup_pending() once they do. Everything that counts or emails
	// "members who still need to set a password" keys off this.
	update_user_meta( $user_id, 'mtl_setup_pending', 1 );

	return (int) $user_id;
}

// --------------------------------------------------------------------------
// BULK ACCOUNT LOOKUPS
//
// mtl_find_wp_user_id_by_member_id() is the right answer for one member and the
// wrong one for a list: it costs two queries each, and the Membership page
// renders every member with no LIMIT, so asking it per row would add thousands
// of queries to a single page load. Everything below answers the same question
// in one query.
//
// All of it joins through to a live member row rather than trusting usermeta on
// its own. The Setup page's "Set Up Database" drops every plugin table while
// WordPress accounts survive untouched, so mtl_member_id and mtl_setup_pending
// can easily outlive the member they described. Counting raw meta would report
// people who no longer exist, and worse, email them.
// --------------------------------------------------------------------------

/**
 * Every WordPress account linked to a member, keyed by lowercased email.
 *
 * Each entry is array( 'user_id' => int, 'member_id' => int, 'pending' => bool ).
 * Callers must still check that member_id matches the row they are looking at:
 * that is the same "email agrees AND the meta points back" rule
 * mtl_find_wp_user_id_by_member_id() applies, and skipping it is what would let
 * a stale link show one member another's account.
 *
 * @return array<string, array{user_id:int, member_id:int, pending:bool}>
 */
function mtl_member_login_map() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT u.ID, u.user_email, um.meta_value AS member_id, p.umeta_id AS pending
		 FROM {$wpdb->users} u
		 INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = 'mtl_member_id'
		 LEFT JOIN {$wpdb->usermeta} p ON p.user_id = u.ID AND p.meta_key = 'mtl_setup_pending'"
	);

	$map = array();
	foreach ( (array) $rows as $row ) {
		$map[ strtolower( trim( (string) $row->user_email ) ) ] = array(
			'user_id'   => (int) $row->ID,
			'member_id' => (int) $row->member_id,
			'pending'   => null !== $row->pending,
		);
	}

	return $map;
}

/**
 * The FROM/WHERE selecting live members whose sign-in needs sorting out, shared
 * by the count and the batch so the button can never disagree with the number
 * printed above it.
 *
 * Two kinds qualify, and mtl_create_member_login() handles both: members with
 * no account at all (create one) and members whose own account survived a
 * database rebuild pointing at a stale id (relink it).
 *
 * Members whose address belongs to a NON-member account are excluded, because
 * no batch can fix those, and feeding them in would mean retrying the same
 * guaranteed failure on every run and never reaching zero. They are counted by
 * mtl_count_members_with_blocked_login() and need a person.
 *
 * @return string SQL fragment beginning "FROM".
 */
function mtl_members_needing_login_from_where() {
	global $wpdb;
	$tbl = $wpdb->prefix . 'members';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	return "FROM {$tbl} m
		 LEFT JOIN {$wpdb->users} u ON u.user_email = m.email
		 LEFT JOIN {$wpdb->usermeta} link ON link.user_id = u.ID AND link.meta_key = 'mtl_member_id'
		 WHERE m.anonymized_at IS NULL
		   AND ( link.umeta_id IS NULL OR CAST(link.meta_value AS UNSIGNED) <> m.member_id )
		   AND ( u.ID IS NULL OR link.umeta_id IS NOT NULL )";
}

/**
 * How many live members still need a sign-in created or reconnected.
 *
 * @return int
 */
function mtl_count_members_without_login() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment is table names only; see mtl_members_needing_login_from_where().
	return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . mtl_members_needing_login_from_where() );
}

/**
 * Live members whose address belongs to an account that is not a member
 * sign-in: a staff login, or a leftover from a member deleted earlier.
 *
 * Reported separately because it cannot be resolved automatically: the address
 * has to be freed, or the member given a different one, by hand under Users.
 *
 * @return int
 */
function mtl_count_members_with_blocked_login() {
	global $wpdb;
	$tbl = $wpdb->prefix . 'members';

	return (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
		"SELECT COUNT(*) FROM {$tbl} m
		 INNER JOIN {$wpdb->users} u ON u.user_email = m.email
		 LEFT JOIN {$wpdb->usermeta} link ON link.user_id = u.ID AND link.meta_key = 'mtl_member_id'
		 WHERE m.anonymized_at IS NULL AND link.umeta_id IS NULL"
	);
}

/**
 * How many members have an account but have never chosen a password.
 *
 * @return int
 */
function mtl_count_members_setup_pending() {
	global $wpdb;
	$tbl = $wpdb->prefix . 'members';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	return (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->users} u
		 INNER JOIN {$wpdb->usermeta} p ON p.user_id = u.ID AND p.meta_key = 'mtl_setup_pending'
		 INNER JOIN {$tbl} m ON m.email = u.user_email AND m.anonymized_at IS NULL"
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * The next batch of member ids needing an account.
 *
 * Re-derived from scratch on every call rather than paged with an OFFSET. Each
 * successful creation removes a member from this set, so starting from the top
 * each time is self-correcting; an offset would step over rows as the set
 * shrank underneath it and quietly leave members behind.
 *
 * @param int $limit Maximum ids to return.
 * @return int[]
 */
function mtl_members_needing_login( $limit ) {
	global $wpdb;

	$sql = 'SELECT m.member_id ' . mtl_members_needing_login_from_where()
		. $wpdb->prepare( ' ORDER BY m.member_id ASC LIMIT %d', (int) $limit );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment is table names only, plus a prepare()d LIMIT; see mtl_members_needing_login_from_where().
	return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
}

/**
 * The FROM/WHERE shared by the setup-email queue's count and its fetch, so the
 * two can never disagree about who is due an invitation.
 *
 * @param bool $resend_all Include people already emailed in the last day.
 * @return string SQL fragment beginning "FROM".
 */
function mtl_setup_email_queue_from_where( $resend_all ) {
	global $wpdb;
	$tbl = $wpdb->prefix . 'members';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$sql = "FROM {$wpdb->users} u
		 INNER JOIN {$wpdb->usermeta} p ON p.user_id = u.ID AND p.meta_key = 'mtl_setup_pending'
		 INNER JOIN {$tbl} m ON m.email = u.user_email AND m.anonymized_at IS NULL
		 LEFT JOIN {$wpdb->usermeta} i ON i.user_id = u.ID AND i.meta_key = 'mtl_setup_invited_at'";

	if ( ! $resend_all ) {
		// Parenthesised deliberately. It is the only WHERE condition today, so
		// the brackets change nothing, but an unbracketed OR is precisely what
		// silently breaks the day somebody ANDs another condition onto this.
		$sql .= $wpdb->prepare(
			' WHERE ( i.umeta_id IS NULL OR CAST(i.meta_value AS UNSIGNED) < %d )',
			time() - DAY_IN_SECONDS
		);
	}

	return $sql;
}

/**
 * How many members are due a setup email right now.
 *
 * @param bool $resend_all Include people already emailed in the last day.
 * @return int
 */
function mtl_count_members_awaiting_setup_email( $resend_all = false ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment is table names plus a prepare()d timestamp; see mtl_setup_email_queue_from_where().
	return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . mtl_setup_email_queue_from_where( (bool) $resend_all ) );
}

/**
 * The next batch of user ids to send a setup email to.
 *
 * Re-derived each call for the same reason as mtl_members_needing_login():
 * sending stamps mtl_setup_invited_at, which drops that member out of the set,
 * so repeated runs walk forward on their own without an offset to get wrong.
 *
 * @param int  $limit      Maximum ids to return.
 * @param bool $resend_all When false, anyone emailed within the last day is
 *                         skipped, so a second click cannot mail the roster
 *                         twice. When true, everyone still pending is included.
 * @return int[]
 */
function mtl_members_awaiting_setup_email( $limit, $resend_all = false ) {
	global $wpdb;

	$sql = 'SELECT u.ID ' . mtl_setup_email_queue_from_where( (bool) $resend_all )
		. $wpdb->prepare( ' ORDER BY u.ID ASC LIMIT %d', (int) $limit );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment is table names plus prepare()d values; see mtl_setup_email_queue_from_where().
	return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
}

// --------------------------------------------------------------------------
// BATCH RUNNERS
//
// Both run to a wall-clock budget rather than a fixed row count, because the
// per-item cost varies by two orders of magnitude between environments and
// between the two jobs. Creating a login is bcrypt-bound (~50-100ms); sending
// mail is bound by the SMTP round trip (~0.3-2s), so the same "safe" row count
// is either pointlessly small locally or a guaranteed timeout in production.
//
// The budget is checked BETWEEN items, so one pathological wp_mail() against a
// misconfigured SMTP host can still overrun it by that call's socket timeout.
// Nothing short of a real queue fixes that, and it is not worth one here.
// --------------------------------------------------------------------------

/**
 * Creates accounts for as many login-less members as fit in the time budget.
 *
 * @param int $budget_seconds Wall-clock budget.
 * @param int $max_items      Hard cap, as a second guard on the budget.
 * @return array{created:int, failed:array<int, array{member_id:int, reason:string}>, remaining:int}
 */
function mtl_run_create_logins_batch( $budget_seconds = 20, $max_items = 500 ) {
	$started = microtime( true );
	$created = 0;
	$failed  = array();

	foreach ( mtl_members_needing_login( (int) $max_items ) as $member_id ) {
		if ( microtime( true ) - $started > $budget_seconds ) {
			break;
		}

		$result = mtl_create_member_login( $member_id );
		if ( is_wp_error( $result ) ) {
			$failed[] = array(
				'member_id' => (int) $member_id,
				'reason'    => $result->get_error_message(),
			);
			continue;
		}
		++$created;
	}

	return array(
		'created'   => $created,
		'failed'    => $failed,
		'remaining' => mtl_count_members_without_login(),
	);
}

/**
 * Sends setup emails to as many pending members as fit in the time budget.
 *
 * @param bool $resend_all     Ignore the one-per-day guard.
 * @param int  $budget_seconds Wall-clock budget.
 * @param int  $max_items      Hard cap, as a second guard on the budget.
 * @return array{sent:int, failed:int, remaining:int, pending:int}
 */
function mtl_run_setup_email_batch( $resend_all = false, $budget_seconds = 20, $max_items = 200 ) {
	$started = microtime( true );
	$sent    = 0;
	$failed  = 0;

	foreach ( mtl_members_awaiting_setup_email( (int) $max_items, $resend_all ) as $user_id ) {
		if ( microtime( true ) - $started > $budget_seconds ) {
			break;
		}

		if ( mtl_send_member_setup_email( $user_id ) ) {
			++$sent;
		} else {
			++$failed;
		}
	}

	return array(
		'sent'      => $sent,
		'failed'    => $failed,
		'remaining' => mtl_count_members_awaiting_setup_email( $resend_all ),
		'pending'   => mtl_count_members_setup_pending(),
	);
}

add_action( 'profile_update', 'mtl_sync_member_email_from_wp_user', 10, 2 );

/**
 * Keeps {prefix}members.email in step when a linked WordPress account's email
 * changes anywhere outside the Membership page, including the member's own
 * /wp-admin/profile.php screen (the mtl_member role has the "read"
 * capability, so members can reach it), Users > Edit User, or WP-CLI.
 *
 * This matters because mtl_current_member() proves a member row belongs to
 * the signed-in account by comparing the two email addresses. Without this
 * hook the two would drift apart on any of those paths and the member would
 * be locked out of their own account page.
 *
 * The row is only rewritten when the OLD address still matched it, i.e.
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
		// No such row, or the Membership page already moved both sides;
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

// ==========================================================================
// CONSIDER GIVING
//
// An optional fundraising ask, shown to signed-in members on their Account
// page and on My Reservations. Both the message and the link are set on the
// Setup page; either one blank simply omits that part, and both blank hides
// the section everywhere.
//
// The link is normalized on save rather than only escaped on output, so what
// is stored is already known to be an ordinary web address. That keeps the
// two display sites from each having to re-litigate whether a stored value
// is safe to put in an href.
// ==========================================================================

/**
 * The default fundraising message, used until an admin saves their own.
 *
 * Kept in one function because it has to be identical in two places, the
 * Setup page textarea's starting value and the member-facing fallback, and
 * a copy-paste drift between them would show members different words than
 * the admin sees in the box they think they are editing.
 *
 * @return string
 */
function mtl_default_giving_text() {
	return 'Every tool on our shelves got here because someone chipped in. If borrowing from us has saved you a trip to the hardware store, please consider giving back so we can repair what we have, replace what wears out, and keep lending free for your neighbors.';
}

/**
 * Cleans up an admin-entered giving link for storage.
 *
 * Returns '' for anything that is not an ordinary http/https web address, so
 * a "javascript:" or "data:" URL pasted into the Setup field is discarded at
 * the point of saving instead of being stored and later rendered into a
 * button that every signed-in member sees.
 *
 * A bare host like "example.org/donate" is assumed to be https rather than
 * rejected, since admins paste addresses without the scheme constantly, and
 * silently saving nothing would look like the field was broken.
 *
 * @param string $url Raw value from the Setup form.
 * @return string A normalized absolute http/https URL, or '' if unusable.
 */
function mtl_normalize_giving_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}

	// Add a scheme before validating, but only when one is genuinely absent.
	// Requiring the colon to come before the first slash stops a path like
	// "example.org/pay:now" from being mistaken for a scheme.
	$colon      = strpos( $url, ':' );
	$slash      = strpos( $url, '/' );
	$has_scheme = ( false !== $colon ) && ( false === $slash || $colon < $slash );

	if ( ! $has_scheme ) {
		// A protocol-relative "//example.org" has no page scheme to inherit
		// once it is stored in a setting, so pin it to https rather than
		// leaving it ambiguous; anything else is treated as a bare host.
		$url = ( 0 === strpos( $url, '//' ) ) ? 'https:' . $url : 'https://' . $url;
	}

	// esc_url_raw() drops disallowed protocols entirely, so restricting the
	// allowed list here is what rejects javascript:/data:/mailto: and friends.
	$url = esc_url_raw( $url, array( 'http', 'https' ) );
	if ( '' === $url ) {
		return '';
	}

	// A scheme alone ("https://") parses as valid but points nowhere; require
	// an actual host before treating this as a usable destination.
	$host = wp_parse_url( $url, PHP_URL_HOST );
	return ( is_string( $host ) && '' !== $host ) ? $url : '';
}

/**
 * The Consider Giving section, or '' when there is nothing to show.
 *
 * Shared by the Account page and My Reservations so the two can never drift
 * apart in wording or behaviour.
 *
 * @param string $extra_class Optional extra class on the wrapper, for callers
 *                            that need to adjust spacing in their layout.
 * @return string Ready-to-echo HTML, fully escaped.
 */
function mtl_giving_section_html( $extra_class = '' ) {
	$text = trim( (string) get_option( 'mtl_giving_text', mtl_default_giving_text() ) );

	// Re-normalized on read, not trusted from storage: an option can also be
	// set by WP-CLI, an import, or another plugin, none of which go through
	// the Setup form's save path.
	$url = mtl_normalize_giving_url( get_option( 'mtl_giving_url', '' ) );

	// The message is what carries the ask, so it decides whether the section
	// exists at all, because a bare "Give Now" button with no explanation would be
	// worse than showing nothing. The link is independent: without it the
	// message still stands on its own, just without a button.
	if ( '' === $text ) {
		return '';
	}

	$classes = 'mtl-member-card mtl-member-giving';
	if ( '' !== $extra_class ) {
		$classes .= ' ' . $extra_class;
	}

	$html  = '<div class="' . esc_attr( $classes ) . '">';
	$html .= '<strong>Consider Giving</strong>';
	$html .= '<p class="mtl-member-giving-text">' . nl2br( esc_html( $text ) ) . '</p>';
	if ( '' !== $url ) {
		// Opens in a new tab so a member part-way through renewing a loan or
		// cancelling a reservation does not lose that page. rel="noopener"
		// stops the opened page from reaching back through window.opener.
		$html .= '<p class="mtl-member-giving-action">';
		$html .= '<a class="mtl-member-btn mtl-member-btn-giving" href="' . esc_url( $url ) . '"';
		$html .= ' target="_blank" rel="noopener noreferrer nofollow">Give Now</a>';
		$html .= '</p>';
	}
	$html .= '</div>';

	return $html;
}

// ==========================================================================
// RETURNS (CHECK-IN)
//
// A tool can be checked in from three places (Loans & Reservations, a
// tool's row on Inventory, and the Manage Loan modal on Membership), and all
// three write loans.return_date the same way, through the two helpers below.
//
// Every one of those forms offers an optional return date so a check-in can
// be BACKDATED. Staff who are backed up on drop-offs often process a bin of
// returns the following day; without this, the member's record would show
// them returning a tool late when they did not.
// ==========================================================================

/**
 * The "Return date" field shared by every check-in form: a date input that
 * starts on today, is capped at today, and (when the loan's checkout date is
 * known) can't be taken back past it.
 *
 * The field is deliberately not `required`, since an empty value falls back to
 * today in mtl_resolve_return_timestamp(), so a form posted with the field
 * cleared still behaves like the plain "mark returned" button it replaces.
 *
 * @param string $loan_date The loan's loan_date, for the input's `min`. Pass
 *                          '' when it isn't known at render time (the shared
 *                          Membership modal sets `min` from JS instead).
 * @param string $input_id  Optional id for the input, when a label or script
 *                          needs to address it.
 * @return string Field HTML, ready to echo.
 */
function mtl_return_date_field_html( $loan_date = '', $input_id = '' ) {
	$today = gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );

	$min    = '';
	$ts_min = '' !== trim( (string) $loan_date ) ? strtotime( $loan_date ) : false;
	if ( $ts_min ) {
		$min = gmdate( 'Y-m-d', $ts_min );
	}

	$id_attr  = '' !== $input_id ? ' id="' . esc_attr( $input_id ) . '"' : '';
	$for_attr = '' !== $input_id ? ' for="' . esc_attr( $input_id ) . '"' : '';

	$html  = '<div class="mtl-return-date-field">';
	$html .= '<label class="mtl-return-date-label"' . $for_attr . '>Return date</label>';
	$html .= '<input type="date" name="return_date"' . $id_attr . ' class="mtl-return-date-input"';
	$html .= ' value="' . esc_attr( $today ) . '" max="' . esc_attr( $today ) . '"';
	$html .= '' !== $min ? ' min="' . esc_attr( $min ) . '"' : '';
	$html .= '>';
	$html .= '<span class="mtl-return-date-hint">Leave as today for a normal drop-off. Backdate it if the tool actually came back earlier and you are catching up, so the member is not recorded as returning it late.</span>';
	$html .= '</div>';

	return $html;
}

/**
 * Works out the return_date TIMESTAMP to write for a check-in, from the
 * optional date a staff member picked on the return form.
 *
 * Blank or today's date is the ordinary same-day drop-off, and records the
 * exact current moment just as it did before backdating existed. An earlier
 * date is a catch-up entry: the tool came back that day but was only
 * processed later, and is stored at the start of that day, because the real
 * check-in time is not known. Recording the true date is the whole point:
 * "returned late" is a date-only comparison against due_date everywhere in
 * this plugin, so a backdated return that made the due date correctly counts
 * as on time.
 *
 * Rejects a future date, and a date before the tool was checked out. A date
 * that lands ON the checkout day is pulled forward to the checkout moment
 * rather than rejected, so a tool loaned and returned the same day can still
 * be backdated and stay in a sane order.
 *
 * @param int    $loan_id     Loan row ID, used to read loan_date for the
 *                            lower bound. An unknown or already-closed loan
 *                            simply has no lower bound to test here; the
 *                            caller's own "... AND return_date IS NULL"
 *                            UPDATE is what reports that case.
 * @param string $posted_date Raw $_POST['return_date'], or '' when the form
 *                            did not send one.
 * @return array {
 *     @type string $timestamp Y-m-d H:i:s to store, or '' when $error is set.
 *     @type string $error     Admin-notice sentence, or '' when valid.
 *     @type bool   $backdated Whether this check-in is dated before today.
 * }
 */
function mtl_resolve_return_timestamp( $loan_id, $posted_date ) {
	global $wpdb;

	$now   = current_time( 'mysql' );
	$today = gmdate( 'Y-m-d', strtotime( $now ) );
	$date  = trim( (string) $posted_date );

	if ( '' === $date || $date === $today ) {
		return array(
			'timestamp' => $now,
			'error'     => '',
			'backdated' => false,
		);
	}

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! strtotime( $date ) ) {
		return array(
			'timestamp' => '',
			'error'     => 'Please provide a valid return date.',
			'backdated' => false,
		);
	}

	if ( $date > $today ) {
		return array(
			'timestamp' => '',
			'error'     => 'The return date can&rsquo;t be in the future. Please pick today or an earlier date.',
			'backdated' => false,
		);
	}

	$tbl_loans = $wpdb->prefix . 'loans';
	$loan_date = $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT loan_date FROM {$tbl_loans} WHERE loan_id = %d",
			(int) $loan_id
		)
	);

	$timestamp = $date . ' 00:00:00';
	if ( null !== $loan_date && '' !== trim( (string) $loan_date ) ) {
		$loan_day = gmdate( 'Y-m-d', strtotime( $loan_date ) );
		if ( $date < $loan_day ) {
			return array(
				'timestamp' => '',
				'error'     => 'That tool wasn&rsquo;t checked out until ' . mtl_format_date( $loan_date ) . ', so it can&rsquo;t have been returned before then.',
				'backdated' => false,
			);
		}
		if ( $timestamp < $loan_date ) {
			// Same calendar day as the checkout: midnight would put the return
			// before the loan, so use the checkout moment itself.
			$timestamp = $loan_date;
		}
	}

	return array(
		'timestamp' => $timestamp,
		'error'     => '',
		'backdated' => true,
	);
}

// ==========================================================================
// RESERVATION HOLD PERIOD
//
// A reservation is collectable once the member reaches the front of the
// queue AND the tool is back on the shelf. tool_reservations.ready_since
// records the moment that became true; the hold period runs from there, NOT
// from when the reservation was placed, so somebody queued behind a six-week
// loan is never timed out for a tool they could not have collected.
//
// Two moving parts:
// mtl_sync_reservation_readiness() keeps ready_since correct after any
// event that changes a tool's queue.
// mtl_expire_stale_reservations() closes out anything left uncollected
// past the hold period.
// ==========================================================================

/**
 * The reservation hold period, in days, from the Setup page.
 *
 * @return int Days a ready reservation is held, or 0 for "never expires".
 */
function mtl_reservation_hold_days() {
	$days = (int) get_option( 'mtl_reservation_hold_days', 14 );
	if ( $days <= 0 ) {
		return 0;
	}
	return min( 365, $days );
}

/**
 * The date a ready reservation must be collected by, as a Y-m-d string.
 *
 * @param string $ready_since The reservation's ready_since value; blank/NULL
 *                            means the member is still queued and no deadline
 *                            applies yet.
 * @return string Y-m-d date, or '' when there is no deadline (still waiting,
 *                or the library holds reservations indefinitely).
 */
function mtl_reservation_collect_by( $ready_since ) {
	$days        = mtl_reservation_hold_days();
	$ready_since = trim( (string) $ready_since );
	if ( 0 === $days || '' === $ready_since ) {
		return '';
	}
	$ts = strtotime( $ready_since );
	if ( ! $ts ) {
		return '';
	}
	return gmdate( 'Y-m-d', $ts + ( $days * DAY_IN_SECONDS ) );
}

/**
 * The member list behind every "type a name or email" picker: Quick Loan,
 * Quick Reserve, the donor field on Inventory, and Bulk Checkout.
 *
 * `search` is what the client-side filter matches against, pre-lowercased so
 * the browser does not redo that on every keystroke, and it carries the name
 * and the email together so typing part of either finds the member.
 *
 * `verified` needs BOTH scan URLs on file, per mtl_verification_urls_complete().
 * One document alone leaves a member unverified, and the pickers show that so
 * staff can see at a glance whether a walk-in has produced their papers.
 *
 * @return array<int, array{id:int, verified:bool, name:string, email:string, label:string, search:string}>
 */
function mtl_get_member_picker_list() {
	global $wpdb;

	$tbl_members       = $wpdb->prefix . 'members';
	$tbl_verifications = $wpdb->prefix . 'member_verifications';

	// A one-line ignore covers only its own line, and this query spans several.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$out = array();
	foreach (
		$wpdb->get_results(
			"SELECT m.member_id, m.first_name, m.last_name, m.email,
                    (v.photo_id_scan_url IS NOT NULL AND v.address_proof_scan_url IS NOT NULL) AS verified
             FROM {$tbl_members} m
             LEFT JOIN {$tbl_verifications} v ON v.member_id = m.member_id
             ORDER BY m.last_name ASC, m.first_name ASC"
		) as $row
	) {
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$name  = trim( stripslashes( (string) $row->first_name ) . ' ' . stripslashes( (string) $row->last_name ) );
		$out[] = array(
			'id'       => (int) $row->member_id,
			'verified' => (bool) $row->verified,
			'name'     => $name,
			'email'    => (string) $row->email,
			'label'    => $name . ' (' . $row->email . ')',
			'search'   => strtolower( $name . ' ' . $row->email ),
		);
	}

	return $out;
}

/**
 * What staff weigh before releasing a tool: current trainings, anything already
 * overdue, and any outstanding agreement. Keyed by member_id.
 *
 * None of it blocks anything. Verification, trainings and agreements are all
 * staff judgement at the desk, so this reports and the person decides.
 *
 * Batched across the whole membership in three queries rather than three per
 * selection, because every caller embeds the picker list anyway and would
 * otherwise need a round trip each time the chosen member changed.
 *
 * @param int[] $member_ids Members to describe.
 * @return array<int, array{trainings:string[], overdue:int, agreement:string}>
 */
function mtl_get_member_info_map( $member_ids ) {
	global $wpdb;

	$member_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $member_ids ) ) ) );
	if ( ! $member_ids ) {
		return array();
	}

	$map = array();
	foreach ( $member_ids as $id ) {
		$map[ $id ] = array(
			'trainings' => array(),
			'overdue'   => 0,
			'agreement' => '',
		);
	}

	$tbl_training_map = $wpdb->prefix . 'member_training_mappings';
	$tbl_trainings    = $wpdb->prefix . 'member_trainings';
	$tbl_loans        = $wpdb->prefix . 'loans';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	foreach (
		$wpdb->get_results(
			"SELECT tm.member_id, t.training_name, tm.start_date, t.certification_length_months
             FROM {$tbl_training_map} tm
             JOIN {$tbl_trainings} t ON t.training_id = tm.training_id
             ORDER BY t.training_name ASC"
		) as $row
	) {
		$id = (int) $row->member_id;
		// Current only: a lapsed certification is not a qualification.
		if ( isset( $map[ $id ] ) && mtl_training_is_current( $row->start_date, $row->certification_length_months ) ) {
			$map[ $id ]['trainings'][] = stripslashes( (string) $row->training_name );
		}
	}

	foreach (
		$wpdb->get_results(
			"SELECT member_id, COUNT(*) AS overdue
             FROM {$tbl_loans}
             WHERE return_date IS NULL AND due_date < CURDATE()
             GROUP BY member_id"
		) as $row
	) {
		$id = (int) $row->member_id;
		if ( isset( $map[ $id ] ) ) {
			$map[ $id ]['overdue'] = (int) $row->overdue;
		}
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Silent unless agreements are on; the map returns 'disabled' for everyone.
	foreach ( mtl_member_agreements_status_map( $member_ids ) as $id => $status ) {
		if ( ! isset( $map[ $id ] ) ) {
			continue;
		}
		if ( 'none' === $status ) {
			$map[ $id ]['agreement'] = __( 'Has not agreed to the member agreements', 'my-tool-library' );
		} elseif ( 'outdated' === $status ) {
			$map[ $id ]['agreement'] = __( 'Needs to agree again to a revised agreement', 'my-tool-library' );
		}
	}

	return $map;
}

/**
 * Everything one tool/member pair needs for a checkout decision.
 *
 * Returns a record rather than a single verdict because loaning and reserving
 * disagree about what counts as a problem. A tool out on loan to somebody else
 * cannot be loaned but can perfectly well be queued for, so a caller asking
 * "is this tool okay" would get an answer that is wrong half the time.
 *
 * Three outcomes per action, not two. Beyond can/cannot there is SKIP: ticking
 * Reserve on a tool the member already holds or has already queued for is a
 * harmless mistake, so it writes nothing and reports nothing rather than
 * refusing a batch of five other tools over it.
 *
 * The two skip cases are the same pair Quick Reserve refuses outright
 * (admin/inventory-page.php) and the same pair the member's own reserve button
 * refuses (public/member-pages.php). Bulk Checkout treats them more gently but
 * never more permissively, so no path can create a duplicate reservation.
 *
 * @param int $tool_id   Tool row ID.
 * @param int $member_id Member the row is for; 0 before one is picked, which
 *                       leaves the self/other distinctions unresolved.
 * @return array{found:bool, tool_id:int, tool_name:string, barcode:string,
 *               retired:bool, on_loan_by:int, reserved_by_self:bool,
 *               queue_size:int, display:string, can_loan:bool,
 *               loan_blocker:string, loan_warning:string, can_reserve:bool,
 *               reserve_blocker:string, reserve_skip:string}
 */
function mtl_tool_row_status( $tool_id, $member_id = 0 ) {
	global $wpdb;

	$tool_id   = (int) $tool_id;
	$member_id = (int) $member_id;

	$status = array(
		'found'            => false,
		'tool_id'          => $tool_id,
		'tool_name'        => '',
		'barcode'          => '',
		'retired'          => false,
		'on_loan_by'       => 0,
		'reserved_by_self' => false,
		'queue_size'       => 0,
		'display'          => 'unknown',
		'can_loan'         => false,
		'loan_blocker'     => __( 'No tool matches that barcode.', 'my-tool-library' ),
		'loan_warning'     => '',
		'can_reserve'      => false,
		'reserve_blocker'  => __( 'No tool matches that barcode.', 'my-tool-library' ),
		'reserve_skip'     => '',
	);

	if ( $tool_id <= 0 ) {
		return $status;
	}

	$tbl_inventory = $wpdb->prefix . 'tool_inventory';
	$tbl_loans     = $wpdb->prefix . 'loans';
	$tbl_res       = $wpdb->prefix . 'tool_reservations';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$tool = $wpdb->get_row(
		$wpdb->prepare( "SELECT tool_id, tool_name, barcode, retired_at FROM {$tbl_inventory} WHERE tool_id = %d", $tool_id )
	);
	if ( ! $tool ) {
		return $status;
	}

	$status['found']     = true;
	$status['tool_name'] = stripslashes( (string) $tool->tool_name );
	$status['barcode']   = (string) $tool->barcode;
	$status['retired']   = ! empty( $tool->retired_at );

	// Who holds it, not merely whether somebody does: reserving is legal behind
	// another member's loan and illegal behind your own.
	$status['on_loan_by'] = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT member_id FROM {$tbl_loans} WHERE tool_id = %d AND return_date IS NULL LIMIT 1", $tool_id )
	);
	$status['queue_size'] = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_res} WHERE tool_id = %d AND expiry_date IS NULL", $tool_id )
	);
	if ( $member_id > 0 ) {
		$status['reserved_by_self'] = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT reservation_id FROM {$tbl_res} WHERE tool_id = %d AND member_id = %d AND expiry_date IS NULL LIMIT 1",
				$tool_id,
				$member_id
			)
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$on_loan_self  = ( $member_id > 0 && $status['on_loan_by'] === $member_id );
	$on_loan_other = ( $status['on_loan_by'] > 0 && ! $on_loan_self );
	$queued_other  = ( $status['queue_size'] > ( $status['reserved_by_self'] ? 1 : 0 ) );

	// Precedence is what staff need to read first, so being out beats being
	// queued for: a tool on loan with three people waiting reads "On loan".
	if ( $status['retired'] ) {
		$status['display'] = 'retired';
	} elseif ( $on_loan_self ) {
		$status['display'] = 'on_loan_self';
	} elseif ( $on_loan_other ) {
		$status['display'] = 'on_loan_other';
	} elseif ( $status['reserved_by_self'] ) {
		$status['display'] = 'reserved_self';
	} elseif ( $queued_other ) {
		$status['display'] = 'reserved_other';
	} else {
		$status['display'] = 'available';
	}

	if ( $status['retired'] ) {
		$status['loan_blocker']    = __( 'Retired. Reactivate it before lending it.', 'my-tool-library' );
		$status['reserve_blocker'] = __( 'Retired, so it cannot be reserved.', 'my-tool-library' );
		return $status;
	}

	if ( $on_loan_self ) {
		$status['loan_blocker'] = __( 'This member already has it on loan.', 'my-tool-library' );
		$status['reserve_skip'] = __( 'Already on loan to this member.', 'my-tool-library' );
	} elseif ( $on_loan_other ) {
		$status['loan_blocker'] = __( 'On loan to another member. End that loan first.', 'my-tool-library' );
		$status['can_reserve']  = true;
	} else {
		$status['can_loan']     = true;
		$status['loan_blocker'] = '';
		if ( $status['reserved_by_self'] ) {
			$status['reserve_skip'] = __( 'This member has already reserved it.', 'my-tool-library' );
		} else {
			$status['can_reserve'] = true;
		}
		if ( $queued_other ) {
			// Allowed on purpose: staff at the desk know things the queue does
			// not. It warns rather than blocks, because the member in front of
			// them is real and the queue position is a policy.
			$status['loan_warning'] = __( 'Reserved by another member.', 'my-tool-library' );
		}
	}

	if ( $status['can_reserve'] ) {
		$status['reserve_blocker'] = '';
	} elseif ( '' !== $status['reserve_skip'] ) {
		$status['reserve_blocker'] = '';
	}

	// Same advisory channel as the queue warning above, so every caller that
	// already reads loan_warning gets this without changing. Only with a member
	// in view: a gap is a fact about the pair, not the tool.
	if ( $member_id > 0 ) {
		$training_gap = mtl_tool_training_gap( $tool_id, $member_id );
		if ( $training_gap ) {
			$gap_text = sprintf(
				/* translators: %s: comma-separated training names. */
				__( 'Training not current: %s.', 'my-tool-library' ),
				implode( ', ', $training_gap )
			);
			$status['loan_warning'] = '' === $status['loan_warning']
				? $gap_text
				: $status['loan_warning'] . ' ' . $gap_text;
		}
	}

	return $status;
}

/**
 * Writes one loan and settles what the loan implies.
 *
 * The insert is the easy part. What every caller kept forgetting is the pair of
 * follow-ups: a member handed a tool they had queued for should not still be
 * queued for it, and the rest of that queue needs its readiness recomputed now
 * the tool is off the shelf. Both live here so no call site can omit them.
 *
 * Validation is the CALLER's job, via mtl_tool_row_status(). This refuses only
 * what would corrupt the table, since a second open loan on one physical tool
 * is unrecoverable from the data alone.
 *
 * @param int    $tool_id   Tool row ID.
 * @param int    $member_id Member row ID.
 * @param string $due_date  Due date as Y-m-d.
 * @return int The new loan_id, or 0 if nothing was written.
 */
function mtl_create_loan( $tool_id, $member_id, $due_date ) {
	global $wpdb;

	$tool_id   = (int) $tool_id;
	$member_id = (int) $member_id;
	$due_date  = trim( (string) $due_date );

	if ( $tool_id <= 0 || $member_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_date ) ) {
		return 0;
	}

	$tbl_loans = $wpdb->prefix . 'loans';
	$tbl_res   = $wpdb->prefix . 'tool_reservations';
	$now       = current_time( 'mysql' );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$already_out = $wpdb->get_var(
		$wpdb->prepare( "SELECT loan_id FROM {$tbl_loans} WHERE tool_id = %d AND return_date IS NULL LIMIT 1", $tool_id )
	);
	if ( $already_out ) {
		return 0;
	}

	$inserted = $wpdb->insert(
		$tbl_loans,
		array(
			'tool_id'   => $tool_id,
			'member_id' => $member_id,
			'loan_date' => $now,
			'due_date'  => $due_date,
		),
		array( '%d', '%d', '%s', '%s' )
	);
	if ( ! $inserted ) {
		return 0;
	}
	$loan_id = (int) $wpdb->insert_id;

	// Their own reservation for this tool is now satisfied, so close it the same
	// way the Loans page checkout does, by stamping today's expiry.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$tbl_res} SET expiry_date = %s WHERE tool_id = %d AND member_id = %d AND expiry_date IS NULL",
			$now,
			$tool_id,
			$member_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	mtl_sync_reservation_readiness( $tool_id );

	return $loan_id;
}

/**
 * Writes one reservation at the back of a tool's queue.
 *
 * Queue order is reservation_date with ties broken by reservation_id, so a row
 * written now sorts last with no position to calculate or store.
 *
 * Validation is the CALLER's job, via mtl_tool_row_status(). This refuses only
 * a duplicate active reservation, which no path should ever produce and which
 * would give one member two places in the same queue.
 *
 * @param int $tool_id   Tool row ID.
 * @param int $member_id Member row ID.
 * @return int The new reservation_id, or 0 if nothing was written.
 */
function mtl_create_reservation( $tool_id, $member_id ) {
	global $wpdb;

	$tool_id   = (int) $tool_id;
	$member_id = (int) $member_id;
	if ( $tool_id <= 0 || $member_id <= 0 ) {
		return 0;
	}

	$tbl_res = $wpdb->prefix . 'tool_reservations';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$duplicate = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT reservation_id FROM {$tbl_res} WHERE tool_id = %d AND member_id = %d AND expiry_date IS NULL LIMIT 1",
			$tool_id,
			$member_id
		)
	);
	if ( $duplicate ) {
		return 0;
	}

	$inserted = $wpdb->insert(
		$tbl_res,
		array(
			'tool_id'          => $tool_id,
			'member_id'        => $member_id,
			'reservation_date' => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%s' )
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $inserted ) {
		return 0;
	}
	// Read before the sync below, which runs its own queries and would leave
	// $wpdb->insert_id describing one of those instead.
	$reservation_id = (int) $wpdb->insert_id;

	// A tool already on the shelf makes a sole reservation collectable at once.
	mtl_sync_reservation_readiness( $tool_id );

	return $reservation_id;
}

/**
 * Recomputes ready_since for one tool's active reservations.
 *
 * Only the front of the queue can be ready, and only while the tool is not
 * out on loan. This clears ready_since on everyone else and stamps the front
 * reservation the first time it becomes collectable, and an already-stamped
 * reservation keeps its original timestamp, so the member's hold period is
 * never quietly restarted by unrelated activity on the same tool.
 *
 * Safe to call after any queue change, and cheap enough to call
 * unconditionally: it is two small indexed queries plus at most two writes.
 *
 * @param int $tool_id Tool row ID.
 * @return void
 */
function mtl_sync_reservation_readiness( $tool_id ) {
	global $wpdb;
	$tool_id = (int) $tool_id;
	if ( $tool_id <= 0 ) {
		return;
	}

	$tbl_res   = $wpdb->prefix . 'tool_reservations';
	$tbl_loans = $wpdb->prefix . 'loans';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$on_loan = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT loan_id FROM {$tbl_loans} WHERE tool_id = %d AND return_date IS NULL LIMIT 1",
			$tool_id
		)
	);

	// Front of the queue: earliest reservation, ties broken by id, the same
	// ordering the Loans & Reservations page uses to show queue position.
	$front_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT reservation_id FROM {$tbl_res}
             WHERE tool_id = %d AND expiry_date IS NULL
             ORDER BY reservation_date ASC, reservation_id ASC
             LIMIT 1",
			$tool_id
		)
	);

	// Anyone who is not collectable right now has no clock running. Passing
	// 0 as the exception id when the tool is on loan clears the whole queue,
	// since reservation_id is AUTO_INCREMENT and never 0.
	$keep_id = ( $on_loan || $front_id <= 0 ) ? 0 : $front_id;
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$tbl_res} SET ready_since = NULL
             WHERE tool_id = %d AND expiry_date IS NULL
               AND ready_since IS NOT NULL AND reservation_id != %d",
			$tool_id,
			$keep_id
		)
	);

	if ( $keep_id > 0 ) {
		// Only stamps when it is still NULL, so an existing hold period keeps
		// running rather than restarting.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tbl_res} SET ready_since = %s
                 WHERE reservation_id = %d AND expiry_date IS NULL AND ready_since IS NULL",
				current_time( 'mysql' ),
				$keep_id
			)
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

add_action( 'init', 'mtl_expire_stale_reservations' );

/**
 * Closes out reservations that sat collectable for longer than the hold
 * period, stamping today's date as their expiry_date.
 *
 * Runs on init, i.e. whenever anyone loads any page, admin or public.
 * rather than relying on WP-Cron alone. WP-Cron is triggered by traffic, not
 * by the clock, so on a quiet library site a nightly job would not actually
 * fire overnight; this way the data is correct the moment anybody looks at
 * it, on any host, with no server configuration. The daily cron event
 * registered below is a supplement that keeps the timestamps tidy on sites
 * that do get overnight traffic.
 *
 * Guarded to run at most once per request. It is a single UPDATE against an
 * indexed column, plus a readiness re-sync for each tool it touched. When
 * the person at the front times out, the next member in line becomes
 * collectable and their own clock has to start.
 *
 * @return void
 */
function mtl_expire_stale_reservations() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$days = mtl_reservation_hold_days();
	if ( 0 === $days ) {
		// "Never expires": the library holds reservations indefinitely.
		return;
	}

	global $wpdb;
	$tbl_res = $wpdb->prefix . 'tool_reservations';
	$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -' . $days . ' days' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	// Collected first: once these rows are expired their tool_ids can no
	// longer be found by this condition, and each of those queues needs its
	// next member promoting.
	$tool_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT tool_id FROM {$tbl_res}
             WHERE expiry_date IS NULL AND ready_since IS NOT NULL AND ready_since <= %s",
			$cutoff
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( empty( $tool_ids ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$tbl_res} SET expiry_date = %s
             WHERE expiry_date IS NULL AND ready_since IS NOT NULL AND ready_since <= %s",
			current_time( 'mysql' ),
			$cutoff
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	foreach ( $tool_ids as $expired_tool_id ) {
		mtl_sync_reservation_readiness( (int) $expired_tool_id );
	}
}

add_action( 'init', 'mtl_schedule_reservation_sweep' );
add_action( 'mtl_daily_reservation_sweep', 'mtl_expire_stale_reservations' );

/**
 * Registers the daily reservation sweep, if it isn't already scheduled.
 *
 * Strictly a convenience: the sweep on init is what actually guarantees
 * correctness (see mtl_expire_stale_reservations()). WordPress's scheduler is
 * driven by incoming traffic rather than by the clock, so this event fires
 * "daily" only on a site that gets visited, and on such a site the init
 * sweep would have caught everything anyway. Its real value is on sites whose
 * host runs a genuine system cron against wp-cron.php, where it makes the
 * expiry timestamps land overnight instead of at whatever hour the first
 * visitor happens to arrive.
 *
 * Runs on init rather than only on activation so installs that were already
 * active before this feature shipped pick it up too, matching how the member
 * role and staff capabilities are registered.
 */
function mtl_schedule_reservation_sweep() {
	if ( ! wp_next_scheduled( 'mtl_daily_reservation_sweep' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mtl_daily_reservation_sweep' );
	}
}

/**
 * The organization name to sign library email with, falling back to the site
 * title and then to the plugin's own name.
 *
 * @return string Never empty.
 */
function mtl_email_org_name() {
	$org_name = trim( (string) get_option( 'mtl_org_name', '' ) );
	if ( '' === $org_name ) {
		$org_name = trim( (string) get_bloginfo( 'name' ) );
	}
	return '' === $org_name ? 'My Tool Library' : $org_name;
}

/**
 * One "Label: value" row of the deleted-member table, with the label padded
 * to a common width.
 *
 * The padding makes a real column in a fixed-width mail client and simply
 * reads as extra space in a proportional one, which is why every row still
 * carries its own "Label:" rather than relying on the alignment to convey
 * what it is. A multi-line value (an address, private notes) is indented to
 * hang under the first line.
 *
 * @param string $label Row label, without the colon.
 * @param string $value Row value; '' becomes an explicit "(none on file)".
 * @return string One or more "\r\n"-joined lines.
 */
function mtl_email_table_row( $label, $value ) {
	$pad   = 22;
	$value = trim( (string) $value );
	if ( '' === $value ) {
		$value = '(none on file)';
	}

	$out    = '';
	$indent = str_repeat( ' ', $pad );
	foreach ( preg_split( '/\R/', $value ) as $i => $value_line ) {
		$out .= ( 0 === $i )
			? str_pad( $label . ':', $pad ) . $value_line
			: "\r\n" . $indent . $value_line;
	}
	return $out;
}

/**
 * Asks the site administrator to delete the verification files belonging to a
 * member whose record has just been removed, and hands over the member's full
 * details as the library's record of what was deleted.
 *
 * Deleting a member drops the member_verifications row, which destroys the
 * LINKS to their ID and proof-of-address scans, but the files themselves live
 * wherever the library uploaded them (a Drive folder, a media library, a share)
 * and nothing in this plugin can reach out and delete them. So the links are
 * mailed to the administrator before they are lost, together with the request
 * to delete what they point at. That email is then the only remaining copy: it
 * doubles as the library's written record that the deletion was asked for, and
 * once the files are gone it is the last thing left to destroy.
 *
 * Sent even when no documents were on file, since the record of the deletion
 * is worth having either way, and just says so plainly instead of listing
 * links.
 *
 * Goes to the WordPress site administrator, NOT mtl_contact_email(), because that
 * address is published on public pages, and a member's full contact details
 * and ID scans must not be handed to a shared inbox just because it happens
 * to be the one printed in the footer.
 *
 * @param object $row          The member's $wpdb row, read before anonymizing.
 * @param int    $member_id    Member row ID.
 * @param array  $doc_urls     Label => URL for each document on file; may be
 *                             empty, which the email states outright.
 * @param array  $open_loans   Rows of still-open loans (tool_name, barcode,
 *                             due_date), for the outstanding-loans section.
 * @param string $initiated_by 'member' when they deleted their own account,
 *                             'staff' when an administrator did it for them.
 * @return bool True if the mail was handed off successfully.
 */
function mtl_send_verification_cleanup_email( $row, $member_id, $doc_urls, $open_loans, $initiated_by ) {
	$admin_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
	if ( ! is_email( $admin_email ) ) {
		return false;
	}

	$org_name = mtl_email_org_name();

	// "Last, First": a record to file and look up, not a greeting.
	$last  = trim( stripslashes( (string) $row->last_name ) );
	$first = trim( stripslashes( (string) $row->first_name ) );
	$name  = trim( trim( $last . ', ' . $first ), ', ' );
	if ( '' === $name ) {
		$name = '(no name on record)';
	}

	// wp_date(), not gmdate(), so the timestamp on the record matches the
	// library's own clock.
	$deleted_at = wp_date( 'F j, Y \a\t g:i a' );
	$by_whom    = 'member' === $initiated_by
		? 'The member deleted their own account from the Account page.'
		: 'A library administrator deleted the record from the Membership page.';

	$address     = implode( "\r\n", array_filter( mtl_member_address_lines( $row ) ) );
	$signup_ts   = strtotime( (string) $row->signup_date );
	$signup_date = $signup_ts ? gmdate( 'F j, Y', $signup_ts ) : '';
	$donation    = (float) $row->recurring_donation_amount;
	$donated     = 'Y' === strtoupper( trim( (string) $row->has_donated_tools ) ) ? 'Yes' : 'No';

	// Both the subject and the opening line promise action only when there is
	// action to take. An admin who reads "please delete their files" and
	// finds none listed learns to skim the next one.
	$subject = $doc_urls
		? sprintf( '[%s] Member record deleted: please delete their verification files', $org_name )
		: sprintf( '[%s] Member record deleted: no verification files to delete', $org_name );

	$purpose = $doc_urls
		? 'This email is the library\'s record of what was deleted, and of the request to remove the files listed at the end.'
		: 'This email is the library\'s record of what was deleted. There are no verification files to remove. See the end.';

	$lines = array(
		sprintf( 'The library record below was deleted on %s.', $deleted_at ),
		'',
		$by_whom,
		'',
		$purpose,
		'',
		'DELETED MEMBER RECORD',
		str_repeat( '-', 58 ),
		mtl_email_table_row( 'Member ID', '#' . (int) $member_id ),
		mtl_email_table_row( 'Name (Last, First)', $name ),
		mtl_email_table_row( 'Address', $address ),
		mtl_email_table_row( 'Phone number', stripslashes( (string) $row->phone_number ) ),
		mtl_email_table_row( 'Email', (string) $row->email ),
		mtl_email_table_row( 'Signup date', $signup_date ),
		mtl_email_table_row( 'Recurring donation', $donation > 0 ? sprintf( '$%s', number_format( $donation, 2 ) ) : 'None' ),
		mtl_email_table_row( 'Has donated tools', $donated ),
		mtl_email_table_row( 'Private notes', stripslashes( (string) $row->private_notes ) ),
		str_repeat( '-', 58 ),
		'',
	);

	// Outstanding loans. Deleting a record does not end a loan, because the member
	// still physically has the tool, so whoever reads this needs to know
	// which items are still out and who is no longer reachable through the
	// system to chase them.
	if ( $open_loans ) {
		$lines[] = sprintf(
			'OUTSTANDING LOANS (%d), still out, and this member can no longer be contacted through the library:',
			count( $open_loans )
		);
		foreach ( $open_loans as $loan ) {
			$due     = strtotime( (string) $loan->due_date );
			$lines[] = sprintf(
				'  - %s (%s), due %s',
				trim( stripslashes( (string) $loan->tool_name ) ),
				trim( stripslashes( (string) $loan->barcode ) ),
				$due ? gmdate( 'F j, Y', $due ) : 'unknown'
			);
		}
		$lines[] = '';
		$lines[] = 'These loans stay open on the Loans & Reservations page, attached to "Former Member". End them when the tools come back, or retire the tools if they are gone for good.';
	} else {
		$lines[] = 'OUTSTANDING LOANS: none. The member had nothing on loan when the record was deleted.';
	}

	$lines[] = '';

	if ( $doc_urls ) {
		$lines[] = 'VERIFICATION FILES TO DELETE';
		$lines[] = '';
		$lines[] = 'The links below have been removed from the database, but the FILES they point at are stored outside it and could not be deleted automatically. Please delete them from wherever the library keeps them:';
		$lines[] = '';
		foreach ( $doc_urls as $label => $url ) {
			$lines[] = sprintf( '  %s: %s', $label, $url );
		}
		$lines[] = '';
		$lines[] = 'Once the files are gone, please delete this email too, because after them it is the last copy of those links.';
	} else {
		$lines[] = 'VERIFICATION FILES TO DELETE: none.';
		$lines[] = '';
		$lines[] = 'This member had no verification documents on file, so there are no files to delete. No action is needed beyond keeping this record.';
	}

	$lines[] = '';
	$lines[] = sprintf( '-- %s', $org_name );

	return (bool) wp_mail( $admin_email, $subject, implode( "\r\n", $lines ) );
}

/**
 * Confirms to a member that their account and personal details are gone.
 *
 * Sent to the address captured before the record was anonymized, since by the
 * time this runs that column holds the reserved deleted-member-<id>@
 * example.invalid placeholder and their WordPress account no longer exists.
 *
 * @param string $email          The member's real email, captured pre-delete.
 * @param string $first_name     Their first name, captured pre-delete.
 * @param int    $open_loans     Loans they still have out; they must still be
 *                               returned, so the email says so.
 * @param int    $cancelled_res  Active reservations closed by the deletion.
 * @return bool True if the mail was handed off successfully.
 */
function mtl_send_account_deleted_email( $email, $first_name, $open_loans, $cancelled_res ) {
	$email = sanitize_email( (string) $email );
	if ( ! is_email( $email ) ) {
		return false;
	}

	$org_name      = mtl_email_org_name();
	$greeting_name = trim( (string) $first_name );

	$lines = array(
		'' !== $greeting_name ? sprintf( 'Hi %s,', $greeting_name ) : 'Hello,',
		'',
		sprintf( 'Your %s account has been deleted, as requested.', $org_name ),
		'',
		'Your name, contact details and any identification documents we held for you have been permanently removed. Your borrowing history is kept as part of the library\'s records, but it is no longer linked to your name.',
	);

	if ( $cancelled_res > 0 ) {
		$lines[] = '';
		$lines[] = 1 === $cancelled_res
			? 'The reservation you had waiting has been cancelled.'
			: sprintf( 'The %d reservations you had waiting have been cancelled.', $cancelled_res );
	}

	// Deleting an account does not conjure the tools back onto the shelf, and
	// this is the last message that can reach them about it.
	if ( $open_loans > 0 ) {
		$lines[] = '';
		$lines[] = 1 === $open_loans
			? 'Please note: you still have a tool on loan. It is still due back, so please return it to the library.'
			: sprintf( 'Please note: you still have %d tools on loan. They are still due back, so please return them to the library.', $open_loans );
	}

	$contact_email = mtl_contact_email();
	if ( '' !== $contact_email ) {
		$lines[] = '';
		$lines[] = sprintf( 'If you did not ask for this, please contact library staff at %s.', $contact_email );
	}

	$lines[] = '';
	$lines[] = 'You are welcome back any time, and signing up again simply starts a new record.';
	$lines[] = '';
	$lines[] = sprintf( '-- %s', $org_name );

	return (bool) wp_mail( $email, sprintf( '[%s] Your account has been deleted', $org_name ), implode( "\r\n", $lines ) );
}

/**
 * Honors a member delete request, either self-service (Account page) or
 * admin-initiated (Membership page).
 *
 * The member's row is always ANONYMIZED, never dropped: their identifying
 * fields are overwritten with placeholders, anonymized_at is stamped, and
 * they read as "Former Member" everywhere afterwards. Everything that records
 * what they did with the library is deliberately kept: loans, reservations,
 * and the trainings they completed, so tool-level statistics, borrowing
 * counts and training records all stay accurate. Keeping the row is what
 * makes that possible: loans and tool_reservations reference member_id, and
 * member_training_mappings would be swept away by ON DELETE CASCADE if the
 * row were dropped (see schema.sql).
 *
 * What IS destroyed is the personal, identifying material: the row's own
 * name/address/contact fields, the member_verifications row holding their ID
 * and proof-of-address scans, and, fully rather than anonymized, their WordPress
 * account, which wp_delete_user() removes from both wp_users and wp_usermeta.
 *
 * Any still-active reservation is cancelled first, otherwise a departed
 * member would keep occupying a spot in a tool's queue indefinitely; this
 * mirrors how retiring a tool auto-cancels its own reservations (see the
 * Retire handler in admin/inventory-page.php). A currently open loan is
 * deliberately left alone, same as a retired tool's loan, since the member still
 * physically has the item, so it can still be ended normally when returned.
 *
 * The WordPress account is only deleted when it still proves the link (see
 * mtl_find_wp_user_id_by_member_id()). An account whose mtl_member_id is
 * stale is left in place and reported via wp_user_orphaned: deleting a
 * sign-in cannot be undone, and a stale id is not evidence of whose it is.
 *
 * Two emails go out once the record is gone, both built entirely from details
 * captured before the row was touched: the deleted member's full record to
 * the site administrator, asking them to delete the verification FILES this
 * plugin cannot reach (see mtl_send_verification_cleanup_email()), and a
 * confirmation to the member that their account has been deleted. Neither is
 * sent when the record was already anonymized, since that deletion, and its
 * emails, happened the first time.
 *
 * @param int    $member_id    Member row ID.
 * @param string $initiated_by 'member' when they deleted their own account,
 *                             'staff' when an administrator did it for them.
 *                             Only affects the wording of the admin email.
 * @return array{outcome:string,name:string,cancelled_reservations:int,wp_user_orphaned:bool,cleanup_email_sent:bool,member_email_sent:bool} outcome is 'anonymized' or 'not_found'; name is the display name captured before any changes; wp_user_orphaned is true when an account still claims this member id but could not be verified, so it was left alone; cleanup_email_sent is whether the administrator's copy of the record got out.
 */
function mtl_delete_or_anonymize_member( $member_id, $initiated_by = 'staff' ) {
	global $wpdb;
	$member_id   = (int) $member_id;
	$tbl_members = $wpdb->prefix . 'members';
	$tbl_verif   = $wpdb->prefix . 'member_verifications';
	$tbl_res     = $wpdb->prefix . 'tool_reservations';
	$tbl_loans   = $wpdb->prefix . 'loans';

	// The whole row, not just the fields this function overwrites: the record
	// mailed to the administrator has to carry every personal detail that is
	// about to be destroyed, and this is the last moment it exists.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT * FROM {$tbl_members} WHERE member_id = %d",
			$member_id
		)
	);
	if ( ! $row ) {
		// Already gone (double-submit, stale page), so nothing to do.
		return array(
			'outcome'                => 'not_found',
			'name'                   => '',
			'cancelled_reservations' => 0,
			'wp_user_orphaned'       => false,
			'cleanup_email_sent'     => false,
			'member_email_sent'      => false,
		);
	}
	$name = trim( $row->first_name . ' ' . $row->last_name );

	// A row anonymized by an earlier delete has no personal data left to
	// remove and no real address to write to; the rest of this function is
	// harmless to repeat, but the emails must not be.
	$already_anonymized = ( null !== $row->anonymized_at );

	// Everything the two emails need, read while the row still says who this
	// is. The verification links especially: the row holding them is deleted
	// further down, and once it is gone nothing can point at those files.
	$doc_urls   = array();
	$verif_urls = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT photo_id_scan_url, address_proof_scan_url FROM {$tbl_verif} WHERE member_id = %d",
			$member_id
		)
	);
	if ( $verif_urls ) {
		// Either scan can stand alone, since a member may have provided only one
		// so far (see schema.sql), so each is listed only if it is on file.
		if ( '' !== trim( (string) $verif_urls->photo_id_scan_url ) ) {
			$doc_urls['Photo ID scan'] = trim( (string) $verif_urls->photo_id_scan_url );
		}
		if ( '' !== trim( (string) $verif_urls->address_proof_scan_url ) ) {
			$doc_urls['Proof of address scan'] = trim( (string) $verif_urls->address_proof_scan_url );
		}
	}

	// Deleting an account does not bring the tools back: an open loan is left
	// alone below, so the member is told they still owe it and the admin
	// record lists what is still out.
	$tbl_inventory = $wpdb->prefix . 'tool_inventory';

	// Both interpolations are table names built from $wpdb->prefix. Disabled
	// across the block rather than per line, because the sniff fires on every
	// line of a multi-line string.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$open_loans = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.tool_name, t.barcode, l.due_date
			   FROM {$tbl_loans} l
			   JOIN {$tbl_inventory} t ON t.tool_id = l.tool_id
			  WHERE l.member_id = %d AND l.return_date IS NULL
			  ORDER BY l.due_date ASC",
			$member_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Resolved BEFORE the row is anonymized, while its email still identifies
	// the person. Only an account that proves the link is deleted: if the link
	// is stale, e.g. a database reset renumbered member ids out from under
	// the surviving sign-ins, the account is left alone rather than risk
	// deleting an unrelated person's WordPress login, which is irreversible.
	$wp_user_id       = mtl_find_wp_user_id_by_member_id( $member_id, (string) $row->email );
	$wp_user_orphaned = ( 0 === $wp_user_id && ! empty( mtl_find_wp_user_ids_claiming_member_id( $member_id ) ) );

	// Captured before the cancel, since afterwards these rows no longer match
	// Each of those tools needs the next member in line promoting.
	$freed_tool_ids = $wpdb->get_col(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT DISTINCT tool_id FROM {$tbl_res} WHERE member_id = %d AND expiry_date IS NULL",
			$member_id
		)
	);

	$cancelled_reservations = (int) $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"UPDATE {$tbl_res} SET expiry_date = %s WHERE member_id = %d AND expiry_date IS NULL",
			current_time( 'mysql' ),
			$member_id
		)
	);

	foreach ( $freed_tool_ids as $freed_tool_id ) {
		mtl_sync_reservation_readiness( (int) $freed_tool_id );
	}

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
			// .invalid is the IANA-reserved, never-resolving TLD (RFC 2606),
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
	// outright. Their training records are NOT touched, because those are library
	// history, and the anonymized row keeps them attached to a "Former Member"
	// rather than to a name.
	$wpdb->delete( $tbl_verif, array( 'member_id' => $member_id ), array( '%d' ) );

	if ( $wp_user_id ) {
		// Deletes the wp_users row and every wp_usermeta row belonging to it,
		// including the mtl_member_id link. Nothing about the sign-in is kept.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $wp_user_id );
	}

	// Mail goes out last, once every change above has stuck: a mail failure
	// must never leave a half-deleted record behind, and nothing should
	// announce a deletion that did not happen. Both are built entirely from
	// the values captured at the top, since the row they came from no longer
	// holds any of them.
	$cleanup_sent = ! $already_anonymized && mtl_send_verification_cleanup_email(
		$row,
		$member_id,
		$doc_urls,
		$open_loans,
		$initiated_by
	);

	$member_notified = ! $already_anonymized && mtl_send_account_deleted_email(
		(string) $row->email,
		(string) $row->first_name,
		count( $open_loans ),
		$cancelled_reservations
	);

	return array(
		'outcome'                => 'anonymized',
		'name'                   => $name,
		'cancelled_reservations' => $cancelled_reservations,
		'wp_user_orphaned'       => $wp_user_orphaned,
		'cleanup_email_sent'     => (bool) $cleanup_sent,
		'member_email_sent'      => (bool) $member_notified,
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
                color: ' . mtl_css_value( $b_color, '#096491' ) . ';
                font-family: ' . mtl_css_value( $b_font ) . ';
                font-size: ' . mtl_css_value( $b_size ) . ';
                font-weight: ' . mtl_css_value( $b_weight, '400' ) . ';
                background: ' . mtl_css_value( $bg_color, '#ffffff' ) . ';
                --mtl-accent-color: ' . mtl_css_value( $accent_color, '#f7c600' ) . ';
                --mtl-radius: ' . mtl_css_value( $radius, '4px' ) . ';
                --mtl-header-color: ' . mtl_css_value( $h_color, '#ff6600' ) . ';
                --mtl-body-color: ' . mtl_css_value( $b_color, '#096491' ) . ';
                --mtl-link-color: ' . mtl_css_value( $l_color, '#00b3ff' ) . ';
                --mtl-btn-scale: ' . mtl_css_value( $btn_scale, '1' ) . ';
            }
            .mtl-admin-wrapper h2,
            .mtl-admin-wrapper h3,
            .mtl-admin-wrapper h4,
            .mtl-admin-wrapper summary {
                color: ' . mtl_css_value( $h_color, '#ff6600' ) . ' !important;
                font-family: ' . mtl_css_value( $h_font ) . ';
                font-size: ' . mtl_css_value( $h_size ) . ';
                font-weight: ' . mtl_css_value( $h_weight, '700' ) . ';
                text-transform: ' . mtl_css_value( $h_transform, 'none' ) . ';
            }
            .mtl-admin-wrapper a {
                color: ' . mtl_css_value( $l_color, '#00b3ff' ) . ';
                font-family: ' . mtl_css_value( $l_font ) . ';
                font-size: ' . mtl_css_value( $l_size ) . ';
                text-decoration: ' . mtl_css_value( $l_dec, 'none' ) . ';
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
                background: ' . mtl_css_value( $h_color, '#ff6600' ) . ' !important;
                border-color: ' . mtl_css_value( $h_color, '#ff6600' ) . ' !important;
                color: #fff !important;
            }
            .mtl-admin-wrapper .button-secondary {
                background: transparent;
                border-color: ' . mtl_css_value( $accent_color, '#f7c600' ) . ' !important;
                color: ' . mtl_css_value( $accent_color, '#f7c600' ) . ' !important;
            }
            .mtl-admin-wrapper .button-secondary:hover {
                background: ' . mtl_css_value( $accent_color, '#f7c600' ) . ' !important;
                color: #fff !important;
            }
            /*
             * The optional "Return date" field on every check-in form (see
             * mtl_return_date_field_html()). Styled once here because the same
             * markup is used on Loans & Reservations, Inventory and the
             * Membership Manage Loan modal, each of which otherwise carries
             * its own local CSS.
             */
            .mtl-return-date-field {
                display: block;
                margin: 8px 0;
                text-align: left;
            }
            .mtl-return-date-label {
                display: block;
                font-size: 12px;
                font-weight: 600;
                margin-bottom: 3px;
            }
            .mtl-return-date-input {
                max-width: 190px;
            }
            .mtl-return-date-hint {
                color: #666;
                display: block;
                font-size: 11px;
                line-height: 1.4;
                margin-top: 3px;
                max-width: 340px;
            }
        </style>';
	}
}

// ==========================================================================
// MEMBER AGREEMENTS: MODE AND CACHING
//
// Three modes, held in the mtl_agreements_mode option:
//
// off   : nothing recorded, computed or shown anywhere but the mode control
// itself. The default.
// paper : staff record signatures against paper copies and the plugin tracks
// who is outstanding. Members are never asked to agree online and are
// never blocked, but can see their own record.
// full  : members agree online at signup, agree again after a revision, and
// cannot self-serve a reservation until they do.
//
// Every surface asks mtl_agreements_tracking() (paper OR full),
// mtl_agreements_online() (full ONLY) or mtl_agreements_staff_recording()
// (paper, plus full when the administrator allowed it). Gating the reserve gate
// or the front-end banner on tracking() would block or nag paper-mode members
// who have no way to act, which is the likeliest bug in this feature.
// ==========================================================================

/**
 * Request-scoped cache for the agreements mode and the active-agreement count,
 * held in a prefixed global.
 *
 * Both values must be readable by the predicates below AND clearable by the
 * Setup handlers within the same request, which rules out a function-local
 * static, since nothing outside the function can reset one. A global rather than a
 * class property because this file is entirely procedural, and mixing the two
 * trips Universal.Files.SeparateFunctionsFromOO.
 *
 * @return array{mode:string|null, active_count:int|null}
 */
function &mtl_agreements_cache() {
	if ( ! isset( $GLOBALS['mtl_agreements_cache'] ) ) {
		$GLOBALS['mtl_agreements_cache'] = array(
			'mode'         => null,
			'active_count' => null,
		);
	}
	return $GLOBALS['mtl_agreements_cache'];
}

/**
 * Clears the request-scoped agreement caches.
 *
 * Call at the END of EVERY handler that writes mtl_agreements_mode or changes a
 * row in member_agreements: add, edit, retire, un-retire, move up, move down,
 * and the mode save. The same request then re-renders the page, so a missed
 * call does not crash; it shows the admin the state from a moment ago.
 *
 * @return void
 */
function mtl_agreements_flush_cache() {
	$cache                 = &mtl_agreements_cache();
	$cache['mode']         = null;
	$cache['active_count'] = null;
}

/**
 * Counts agreements that are currently active (not retired).
 *
 * Cached for the request: this is consulted on nearly every page the feature
 * touches, via mtl_agreements_mode(), and must not become a query per call site.
 *
 * @return int
 */
function mtl_count_active_agreements() {
	$cache = &mtl_agreements_cache();
	if ( null !== $cache['active_count'] ) {
		return $cache['active_count'];
	}

	global $wpdb;
	$table = $wpdb->prefix . 'member_agreements';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
	$cache['active_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE retired_at IS NULL" );

	return $cache['active_count'];
}

/**
 * The mode the administrator chose: 'off', 'paper' or 'full'.
 *
 * THIS IS THE ADMINISTRATOR'S SETTING AND NOTHING ELSE. It changes only when
 * somebody picks a different option on Setup and saves it. Do not fold "is
 * there anything to enforce" in here, because retiring the last agreement would then
 * appear to switch the feature off by itself, which reads as the plugin
 * losing the setting. That question belongs to the two gates below.
 *
 * Fails CLOSED on the value itself: absent, misspelled or hand-edited is
 * treated as 'off' rather than assumed to be something permissive.
 *
 * @return string 'off'|'paper'|'full'
 */
function mtl_agreements_mode() {
	$cache = &mtl_agreements_cache();
	if ( null !== $cache['mode'] ) {
		return $cache['mode'];
	}

	$stored = (string) get_option( 'mtl_agreements_mode', 'off' );

	$cache['mode'] = in_array( $stored, array( 'paper', 'full' ), true ) ? $stored : 'off';

	return $cache['mode'];
}

/**
 * Whether agreements are recorded and shown to staff at all. True for BOTH
 * paper and full.
 *
 * Gates the staff side: the detail panel block, row badges, Advanced Search, the
 * record download, the confirmation email, and whether any acceptance row is
 * written. The surfaces that WRITE a staff row ask
 * mtl_agreements_staff_recording() instead, which is narrower.
 *
 * @return bool
 */
function mtl_agreements_tracking() {
	// The active-agreement check lives here rather than in mtl_agreements_mode()
	// so that having nothing to track never rewrites the administrator's
	// setting. With no agreement active there is nothing to record against, so
	// every surface stays hidden, but Setup still shows the chosen mode, and
	// adding an agreement brings it all straight back.
	return 'off' !== mtl_agreements_mode() && 0 < mtl_count_active_agreements();
}

/**
 * Whether member-facing surfaces and enforcement are live. True for full ONLY.
 *
 * Gates the signup requirement, the account-page agreement form, the front-end
 * banner, the reserve gate, the bulk request panel and both request emails.
 *
 * NEVER gate these on mtl_agreements_tracking(): in paper mode a member cannot
 * agree online, so blocking or prompting them would leave them behind a door
 * with no handle.
 *
 * @return bool
 */
function mtl_agreements_online() {
	// Same active-agreement guard as tracking(), and it is load-bearing: with
	// the mode on but nothing active, every member reads as having agreed to
	// nothing, so without this the banner and the reserve gate would block the
	// whole membership over a list with no entries.
	return 'full' === mtl_agreements_mode() && 0 < mtl_count_active_agreements();
}

/**
 * Whether staff may record a signature against a paper copy at the desk.
 *
 * Gates the Add New Member checkboxes, the detail panel's Record agreement
 * button and the dialog behind it, and both staff contexts in the writer.
 *
 * Always true in paper mode: staff recording IS that mode, so honouring the
 * option there would leave a mode switched on that can record nothing. In full
 * mode it is the administrator's choice, and it is off by default. A library
 * whose members all agree online never wants a desk button that writes an
 * attestation on their behalf.
 *
 * @return bool
 */
function mtl_agreements_staff_recording() {
	if ( ! mtl_agreements_tracking() ) {
		return false;
	}

	return 'paper' === mtl_agreements_mode() || '1' === (string) get_option( 'mtl_agreements_allow_paper', '' );
}

// ==========================================================================
// MEMBER AGREEMENTS: CONTEXTS AND ASSENT LANGUAGE
//
// An acceptance row records not just WHAT the member agreed to but HOW they
// were asked. The context is the only thing a caller of
// mtl_record_agreement_acceptance() states about the circumstances; everything
// else the row says is derived from the map below.
// ==========================================================================

/**
 * The four contexts in which an acceptance can be recorded, and what each one
 * means.
 *
 * MUST build the array on every call, never a file-scope constant or a static
 * cache. The assent strings are wrapped in __(), and WordPress does not load a
 * plugin's text domain until `init`, so an array built at file scope would
 * resolve them to English permanently. Since these strings are snapshotted into
 * acceptance rows, that would record a non-English member as having been shown
 * wording they never saw. Building per call also survives a mid-request locale
 * switch.
 *
 * staff_add and staff_edit carry identical wording today but stay separate:
 * this map is where they would diverge if "signed at the desk on joining" ever
 * needs to read differently from "signed later, after a revision".
 *
 * @return array<string, array{is_staff: bool, assent: string}>
 */
function mtl_agreement_contexts() {
	return array(
		'signup'     => array(
			'is_staff' => false,
			/* translators: LEGALLY OPERATIVE. This exact sentence is stored permanently against each member as the wording that framed their agreement, and may be produced as evidence. Translate it precisely rather than idiomatically; a loose rendering weakens the record for every member who reads it in this language. */
			'assent'   => __( 'By ticking this box and creating an account, I confirm that I have read and agree to the statement above.', 'my-tool-library' ),
		),
		'agree_page' => array(
			'is_staff' => false,
			/* translators: LEGALLY OPERATIVE. Stored permanently against each member as the wording that framed their agreement, and may be produced as evidence. Translate precisely rather than idiomatically. */
			'assent'   => __( 'By ticking this box, I confirm that I have read and agree to the statement above.', 'my-tool-library' ),
		),
		'staff_add'  => array(
			'is_staff' => true,
			/* translators: LEGALLY OPERATIVE. Stored permanently as the attestation library staff made on a member's behalf against a signed paper form, and may be produced as evidence. Translate precisely rather than idiomatically. */
			'assent'   => __( 'Library staff confirm that this member has signed a paper copy of the statement above.', 'my-tool-library' ),
		),
		'staff_edit' => array(
			'is_staff' => true,
			/* translators: LEGALLY OPERATIVE. Stored permanently as the attestation library staff made on a member's behalf against a signed paper form, and may be produced as evidence. Translate precisely rather than idiomatically. */
			'assent'   => __( 'Library staff confirm that this member has signed a paper copy of the statement above.', 'my-tool-library' ),
		),
	);
}

/**
 * Whether a context is a recognised one.
 *
 * The writer refuses to insert a row for anything else, because both the assent
 * wording and the staff/member distinction are looked up from the context. An
 * unrecognised value has no honest answer for either.
 *
 * @param string $context Context key.
 * @return bool
 */
function mtl_agreement_context_is_valid( $context ) {
	return isset( mtl_agreement_contexts()[ $context ] );
}

/**
 * The words that frame the tick, for one context.
 *
 * This is the ONLY source of the assent wording. The screen that asks and the
 * row that records must show the same sentence, so both read it from here,
 * never a literal typed into a template.
 *
 * @param string $context One of the keys of mtl_agreement_contexts().
 * @return string Assent wording, or '' for an unrecognised context.
 */
function mtl_assent_language( $context ) {
	$contexts = mtl_agreement_contexts();
	return isset( $contexts[ $context ] ) ? $contexts[ $context ]['assent'] : '';
}

/**
 * Whether an acceptance recorded in this context was entered by staff on a
 * member's behalf rather than ticked by the member themselves.
 *
 * @param string $context One of the keys of mtl_agreement_contexts().
 * @return bool False for an unrecognised context.
 */
function mtl_acceptance_is_staff( $context ) {
	$contexts = mtl_agreement_contexts();
	return isset( $contexts[ $context ] ) ? $contexts[ $context ]['is_staff'] : false;
}

// ==========================================================================
// MEMBER AGREEMENTS: READING THE AGREEMENT LIST
// ==========================================================================

/**
 * Longest agreement text an admin may write, in characters.
 *
 * The column is TEXT and would take far more. The cap is a usability limit, not
 * a storage one: every active agreement appears in full on the signup form, and
 * six long ones stacked together is a wall of text nobody reads, which is the
 * failure mode this whole feature exists to avoid.
 */
define( 'MTL_AGREEMENT_TEXT_MAXLENGTH', 2000 );

/**
 * Every agreement still in circulation, in the order members see them.
 *
 * ALWAYS ordered by sort_order then agreement_id. Two rows can end up sharing a
 * sort_order through concurrent reordering, and without the tiebreak the signup
 * form and the account page could list them differently on different page
 * loads, which looks like a bug in the record.
 *
 * @return object[] Rows from member_agreements, retired ones excluded.
 */
function mtl_get_active_agreements() {
	global $wpdb;
	$table = $wpdb->prefix . 'member_agreements';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix, not user input.
	return $wpdb->get_results( "SELECT * FROM {$table} WHERE retired_at IS NULL ORDER BY sort_order ASC, agreement_id ASC" );
}

/**
 * Every retired agreement, newest retirement first.
 *
 * @return object[] Rows from member_agreements, active ones excluded.
 */
function mtl_get_retired_agreements() {
	global $wpdb;
	$table = $wpdb->prefix . 'member_agreements';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix, not user input.
	return $wpdb->get_results( "SELECT * FROM {$table} WHERE retired_at IS NOT NULL ORDER BY retired_at DESC, agreement_id ASC" );
}

/**
 * One agreement row by ID, retired or not.
 *
 * @param int $agreement_id Agreement ID.
 * @return object|null Row, or null if there is no such agreement.
 */
function mtl_get_agreement( $agreement_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'member_agreements';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix, not user input.
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE agreement_id = %d", (int) $agreement_id ) );
}

/**
 * How many acceptance rows exist for an agreement.
 *
 * Decides whether Setup offers Delete or Retire: an agreement nobody has ever
 * accepted can be deleted outright, and this is how that is established:
 * counted, not guessed from how recently it was created. The ON DELETE RESTRICT
 * foreign key is the real guarantee; this only decides which button to show.
 *
 * @param int $agreement_id Agreement ID.
 * @return int
 */
function mtl_count_agreement_acceptances( $agreement_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'member_agreement_acceptances';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix, not user input.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE agreement_id = %d", (int) $agreement_id ) );
}

/**
 * How many current members are up to date on one agreement.
 *
 * The number the edit-an-agreement warning names: members who ARE up to date,
 * since those are the ones a version bump knocks back out of agreement. Anyone
 * who had not agreed yet is already outstanding.
 *
 * Anonymized members are excluded, since they have no account to be prompted on.
 *
 * @param int $agreement_id Agreement ID.
 * @param int $version_num  Version to measure against.
 * @return int
 */
function mtl_count_members_agreed_to( $agreement_id, $version_num ) {
	global $wpdb;
	$acceptances = $wpdb->prefix . 'member_agreement_acceptances';
	$members     = $wpdb->prefix . 'members';
	// Every interpolation below is a table name built from $wpdb->prefix; the
	// two values are placeholders. Disabled across the block rather than per
	// line because the sniff fires on each line of a multi-line string.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting count, no cache invalidation point.
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT a.member_id)
			   FROM {$acceptances} a
			   JOIN {$members} m ON m.member_id = a.member_id AND m.anonymized_at IS NULL
			  WHERE a.agreement_id = %d
			    AND a.agreement_version_num >= %d",
			(int) $agreement_id,
			(int) $version_num
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $count;
}

/**
 * SQL predicate for "this member is outstanding", as a WHERE fragment.
 *
 * The definition of outstanding lives here and nowhere else. Three queries need
 * it: the paper-to-full count, the request queue's audience filter, and the
 * excluded count. If it were written out in each, changing what
 * outstanding means would mean finding all three and keeping them in step.
 *
 * Expects the members table aliased as `m` in the surrounding query. Contains no
 * user input: every interpolation is a table name from $wpdb->prefix.
 *
 * @return string SQL beginning "(", suitable for appending to a WHERE.
 */
function mtl_agreements_outstanding_sql() {
	global $wpdb;
	$agreements  = $wpdb->prefix . 'member_agreements';
	$acceptances = $wpdb->prefix . 'member_agreement_acceptances';

	// Counts the active agreements this member holds a current-or-newer
	// acceptance for; anyone short of the full set is outstanding.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return "(
		SELECT COUNT(*)
		  FROM {$agreements} g
		 WHERE g.retired_at IS NULL
		   AND EXISTS (
		       SELECT 1 FROM {$acceptances} a
		        WHERE a.member_id = m.member_id
		          AND a.agreement_id = g.agreement_id
		          AND a.agreement_version_num >= g.version_num
		   )
	) < (SELECT COUNT(*) FROM {$agreements} WHERE retired_at IS NULL)";
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * How many current members are missing at least one current-version acceptance.
 *
 * Used for one thing: the count in the paper-to-full confirmation, so an admin
 * is told how many people that switch is about to block before they make it.
 *
 * Returns 0 when no agreement is active, which is correct, because with nothing to
 * agree to, nobody is outstanding.
 *
 * @return int
 */
function mtl_count_members_not_in_agreement() {
	$active = mtl_count_active_agreements();
	if ( 0 === $active ) {
		return 0;
	}

	global $wpdb;
	$members = $wpdb->prefix . 'members';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting count, no cache invalidation point.
	return (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix plus the shared predicate; no user input.
		"SELECT COUNT(*) FROM {$members} m WHERE m.anonymized_at IS NULL AND " . mtl_agreements_outstanding_sql()
	);
}

// ==========================================================================
// MEMBER AGREEMENTS: EMAIL COPY
//
// Three admin-editable strings, each shipped with a working default so no email
// this feature sends is ever blank or subject-less.
// ==========================================================================

/**
 * The shipped defaults for the three admin-editable email strings.
 *
 * Built per call, for the same reason mtl_agreement_contexts() is: __() before
 * `init` resolves to English permanently.
 *
 * @return array{subject: string, body: string, request_body: string}
 */
function mtl_agreement_email_defaults() {
	return array(
		'subject'      => __( 'Your agreement record', 'my-tool-library' ),
		'body'         => __( 'This email is your record of the agreements you have made with us. Please keep it for your reference.', 'my-tool-library' ),
		'request_body' => __( 'We have updated our member agreements, and we need you to review and agree to them before you borrow again. Sign in to your account to read them and agree.', 'my-tool-library' ),
	);
}

/**
 * Subject line for the confirmation email, admin-set or shipped default.
 *
 * Line breaks are stripped HERE as well as on save. A subject containing CR or
 * LF is classic mail-header injection, since everything after the break becomes a
 * new header, which is how a Bcc: gets added to every agreement email the site
 * sends. The option could have been written by anything (WP-CLI, a migration, a
 * hand-edited database), so the value is never trusted just because a save
 * handler once cleaned it.
 *
 * @return string
 */
function mtl_agreement_email_subject() {
	$defaults = mtl_agreement_email_defaults();
	$stored   = (string) get_option( 'mtl_agreement_email_subject', '' );
	$stored   = str_replace( array( "\r", "\n" ), '', sanitize_text_field( $stored ) );
	return '' !== trim( $stored ) ? $stored : $defaults['subject'];
}

/**
 * Admin-supplied body of the confirmation email, or the shipped default.
 *
 * @return string Plain text.
 */
function mtl_agreement_email_body() {
	$defaults = mtl_agreement_email_defaults();
	$stored   = (string) get_option( 'mtl_agreement_email_body', '' );
	return '' !== trim( $stored ) ? $stored : $defaults['body'];
}

/**
 * Admin-supplied body of the agreement *request* email, or the shipped default.
 *
 * A different message from the confirmation, with a different purpose, so it
 * gets its own option rather than reusing the one above. Used by both the bulk
 * sender and the per-member Send agreement request action, which are one
 * message and should not be two options to keep in step.
 *
 * @return string Plain text.
 */
function mtl_agreement_request_email_body() {
	$defaults = mtl_agreement_email_defaults();
	$stored   = (string) get_option( 'mtl_agreement_request_email_body', '' );
	return '' !== trim( $stored ) ? $stored : $defaults['request_body'];
}

/**
 * Asks one member to review and agree.
 *
 * Sent by both the per-member action on the detail panel and the bulk sender.
 * One message with one purpose, so it is one function and one Setup option
 * rather than two to keep in step.
 *
 * WRITES USER META ONLY. Sending a request never changes a member's agreement
 * status, which is derived from version comparison and nothing else. The
 * meta is a send-throttle, not a state.
 *
 * The generated part varies by what the recipient actually needs, which is what
 * makes "all active members" a safe audience: telling somebody who is fully
 * current to "please agree" would be both wrong and the fastest way to teach
 * members to ignore these emails.
 *
 * No files are attached. The recipient has not agreed to anything yet; the
 * attachments belong on the confirmation they get once they do.
 *
 * @param int $member_id Member to write to.
 * @return bool Whether the mail was accepted for delivery.
 */
function mtl_send_agreement_request_email( $member_id ) {
	$member_id = (int) $member_id;
	if ( $member_id <= 0 || ! mtl_agreements_online() ) {
		return false;
	}

	global $wpdb;
	$members = $wpdb->prefix . 'members';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix.
	$member = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name, email, anonymized_at FROM {$members} WHERE member_id = %d", $member_id ) );
	if ( ! $member || null !== $member->anonymized_at || '' === trim( (string) $member->email ) ) {
		return false;
	}

	$org_name = get_option( 'mtl_org_name', '' );
	if ( '' === trim( (string) $org_name ) ) {
		$org_name = 'My Tool Library';
	}

	$greeting_name = trim( (string) $member->first_name );
	if ( '' === $greeting_name ) {
		$greeting_name = trim( $member->first_name . ' ' . $member->last_name );
	}
	if ( '' === $greeting_name ) {
		$greeting_name = 'there';
	}

	$lines = array(
		sprintf( 'Hi %s,', $greeting_name ),
		'',
		mtl_agreement_request_email_body(),
		'',
	);

	$outstanding = mtl_member_outstanding_agreements( $member_id );
	if ( $outstanding ) {
		$lines[] = __( 'We need you to review and agree to the following:', 'my-tool-library' );
		$number  = 1;
		foreach ( $outstanding as $agreement ) {
			$lines[] = mtl_wrap_numbered_line( $number, $agreement->agreement_text );
			++$number;
		}
		$lines[] = '';
		$lines[] = __( 'You can do that here:', 'my-tool-library' );
		$lines[] = mtl_front_page_url( 'account' ) . '#mtl-agreements';
	} else {
		// No task, and it must not invent one. No anchor either: there is no
		// agreement form on that page for a member who is up to date, so an
		// anchor would point at nothing.
		$lines[] = __( 'You have already agreed to everything currently required, so there is nothing you need to do. You can see what you agreed to here:', 'my-tool-library' );
		$lines[] = mtl_front_page_url( 'account' );
	}

	$contact_email = mtl_contact_email();
	if ( '' !== $contact_email ) {
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: the library's contact email address. */
			__( 'If anything looks wrong, contact library staff at %s.', 'my-tool-library' ),
			$contact_email
		);
	}

	$lines[] = '';
	$lines[] = sprintf( '-- %s', $org_name );

	$subject = sprintf(
		/* translators: %s: the library's name. */
		__( '[%s] Please review our member agreements', 'my-tool-library' ),
		$org_name
	);
	$sent = wp_mail( $member->email, $subject, implode( "\r\n", $lines ) );

	if ( $sent ) {
		// Read by both senders to skip anyone contacted in the last day, so a
		// second click cannot mail somebody three times in a minute.
		// The email is required, not optional: the helper refuses to guess a
		// link from the id alone.
		$user_id = mtl_find_wp_user_id_by_member_id( $member_id, $member->email );
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, 'mtl_agreement_requested_at', time() );
		}
	}

	return (bool) $sent;
}

/**
 * One numbered item of a generated agreement list, wrapped with a hanging
 * indent so the numbers stay readable in a plain-text mail client.
 *
 * @param int    $number 1-based position within this email, not the agreement id.
 * @param string $text   Agreement text.
 * @return string
 */
function mtl_wrap_numbered_line( $number, $text ) {
	$prefix  = $number . '. ';
	$body    = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
	$wrapped = wordwrap( $body, 68 - strlen( $prefix ), "\r\n" . str_repeat( ' ', strlen( $prefix ) ), false );
	return $prefix . $wrapped;
}

/**
 * Largest combined attachment payload, in bytes.
 *
 * Mail providers commonly reject anything over 10-25 MB, and a rejection loses
 * the whole email rather than just the attachment. 10 MB is the conservative
 * end of that range.
 */
define( 'MTL_AGREEMENT_MAIL_MAX_BYTES', 10 * 1024 * 1024 );

/**
 * Sends a member their record of what they just agreed to.
 *
 * The generated list comes from the ACCEPTANCE ROWS just written, not from a
 * fresh read of member_agreements. At send time the two are identical, since the
 * rows were snapshotted moments earlier, but reading the snapshot means the
 * email can never disagree with the record it is confirming, even if an admin
 * saves an edit in the same second.
 *
 * THE RESULT CANNOT ROLL ANYTHING BACK. The acceptance rows are committed
 * first, so a member whose mail provider is down has still agreed. Callers use
 * the return value only to report the failure to staff.
 *
 * The generated wording stays generic because it has to hold for every document
 * a library might ever use: it says that they agreed, to what wording, and when,
 * without characterising what they took on. Library-specific framing belongs in
 * the admin-supplied body, and assent_text is not quoted because it varies by
 * context.
 *
 * @param int   $member_id      Member to write to.
 * @param int[] $acceptance_ids The rows this email is about.
 * @return bool Whether the mail was accepted for delivery.
 */
function mtl_send_agreement_confirmation_email( $member_id, $acceptance_ids ) {
	$member_id      = (int) $member_id;
	$acceptance_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $acceptance_ids ) ) ) );
	if ( $member_id <= 0 || ! $acceptance_ids || ! mtl_agreements_tracking() ) {
		return false;
	}

	global $wpdb;
	$acceptances  = $wpdb->prefix . 'member_agreement_acceptances';
	$agreements   = $wpdb->prefix . 'member_agreements';
	$placeholders = implode( ',', array_fill( 0, count( $acceptance_ids ), '%d' ) );

	// Scoped to this member as well as these ids, so a caller passing an id
	// belonging to somebody else cannot mail one member another's record.
	// Ordered by the agreements' sort_order, matching every other listing.
	$args = array_merge( array( $member_id ), $acceptance_ids );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read per send; there is no cache to invalidate.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT a.*
			   FROM {$acceptances} a
			   LEFT JOIN {$agreements} g ON g.agreement_id = a.agreement_id
			  WHERE a.member_id = %d
			    AND a.acceptance_id IN ({$placeholders})
			  ORDER BY g.sort_order ASC, a.agreement_id ASC",
			$args
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	if ( ! $rows ) {
		return false;
	}

	// The snapshot, not the live members row: this is a receipt, and it should
	// name whoever the member was when they agreed.
	$to = trim( (string) $rows[0]->member_email );
	if ( '' === $to || ! is_email( $to ) ) {
		return false;
	}

	$org_name = get_option( 'mtl_org_name', '' );
	if ( '' === trim( (string) $org_name ) ) {
		$org_name = 'My Tool Library';
	}

	$greeting_name = trim( (string) $rows[0]->member_name );
	if ( '' !== $greeting_name ) {
		$parts         = explode( ' ', $greeting_name );
		$greeting_name = $parts[0];
	}
	if ( '' === $greeting_name ) {
		$greeting_name = 'there';
	}

	$lines = array(
		sprintf( 'Hi %s,', $greeting_name ),
		'',
		mtl_agreement_email_body(),
		'',
		__( 'You agreed to the following:', 'my-tool-library' ),
	);

	// Numbered from 1 within THIS email; these are not agreement ids and not
	// positions in the full list. A member re-accepting only agreement 3 gets a
	// single item numbered 1.
	$number = 1;
	foreach ( $rows as $row ) {
		$lines[] = mtl_wrap_numbered_line( $number, $row->agreement_text );
		++$number;
	}

	$lines[] = '';
	$lines[] = sprintf(
		/* translators: %s: date and time the member agreed, in the site's timezone. */
		__( 'Agreed %s.', 'my-tool-library' ),
		wp_strip_all_tags( mtl_format_utc_datetime( $rows[0]->accepted_at, 'j F Y \a\t g:i a' ) )
	);

	// Attachments: only the files for the agreements in THIS email.
	$attachments = array();
	$file_urls   = array();
	$total_bytes = 0;
	foreach ( $rows as $row ) {
		if ( empty( $row->file_url ) ) {
			continue;
		}
		$attachment_id = attachment_url_to_postid( $row->file_url );
		$path          = $attachment_id > 0 ? get_attached_file( $attachment_id ) : '';

		// A file missing from disk is skipped rather than failing the send;
		// the member still gets their record.
		if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			continue;
		}

		// Two agreements can legitimately point at the same PDF; attaching it
		// twice is noise.
		if ( in_array( $path, $attachments, true ) ) {
			continue;
		}

		$attachments[] = $path;
		$file_urls[]   = (string) $row->file_url;
		$total_bytes  += (int) filesize( $path );
	}

	if ( $attachments && $total_bytes <= MTL_AGREEMENT_MAIL_MAX_BYTES ) {
		$lines[] = '';
		$lines[] = __( 'The documents referred to above are attached to this email.', 'my-tool-library' );
	} elseif ( $attachments ) {
		// All-or-nothing rather than attaching until the budget runs out: a
		// partial set is worse than none, because the member cannot tell which
		// are missing.
		$attachments = array();
		$lines[]     = '';
		$lines[]     = __( 'The documents referred to above were too large to attach. You can view them here:', 'my-tool-library' );
		$link_number = 1;
		foreach ( $file_urls as $url ) {
			$lines[] = $link_number . '. ' . $url;
			++$link_number;
		}
	}

	$contact_email = mtl_contact_email();
	if ( '' !== $contact_email ) {
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: the library's contact email address. */
			__( 'If anything looks wrong, contact library staff at %s.', 'my-tool-library' ),
			$contact_email
		);
	}

	$lines[] = '';
	$lines[] = sprintf( '-- %s', $org_name );

	// Stripped again here, not only on save: the option could have been written
	// by WP-CLI, a migration or a hand-edited database, and a CR or LF in a
	// subject is how a Bcc: gets added to every agreement email the site sends.
	$subject = str_replace( array( "\r", "\n" ), '', mtl_agreement_email_subject() );
	$subject = sprintf( '[%s] %s', $org_name, $subject );

	return (bool) wp_mail( $to, $subject, implode( "\r\n", $lines ), '', $attachments );
}

/**
 * The acceptance rows written to a member most recently, as ids.
 *
 * "Most recently" means every row sharing the highest accepted_at for that
 * member. That is the newest acceptance event, never an older one.
 *
 * ONE-SECOND RESOLUTION IS THE LIMIT OF THIS. accepted_at is a DATETIME, so two
 * separate events in the same second come back together. Only safe where that
 * cannot happen or does not matter:
 *
 * - Signup and Add New Member: the member was created moments ago, so every row
 *   they have is this one event.
 * - Resend: an extra row from the same second is still theirs.
 *
 * Where a member may already have history and the email must name exactly what
 * was just written, keep the ids mtl_record_agreement_acceptance() returns.
 *
 * @param int $member_id Member to read.
 * @return int[] Acceptance ids, empty when the member has none.
 */
function mtl_latest_acceptance_event_ids( $member_id ) {
	global $wpdb;
	$acceptances = $wpdb->prefix . 'member_agreement_acceptances';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read per send; there is no cache to invalidate.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT acceptance_id
			   FROM {$acceptances}
			  WHERE member_id = %d
			    AND accepted_at = (
			        SELECT MAX(accepted_at) FROM {$acceptances} WHERE member_id = %d
			    )",
			(int) $member_id,
			(int) $member_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array_map( 'intval', (array) $ids );
}

// --------------------------------------------------------------------------
// AGREEMENT REQUESTS: THE BULK QUEUE
//
// Mirrors the setup-email queue directly above: one shared FROM/WHERE fragment
// so the count and the fetch can never disagree about who is due, and a runner
// working to a wall-clock budget rather than a row count.
// --------------------------------------------------------------------------

/**
 * The FROM/WHERE shared by the agreement-request queue's count and its fetch.
 *
 * THE JOINS ARE THE CORRECTNESS, not an optimisation. The email tells the
 * member to agree on the website, and the Account page sends anyone not signed
 * in to a login form, so a member with no account, or one who has never set a
 * password, gets an instruction they cannot follow. That is the default state
 * of the population this panel serves: staff-added and CSV-imported members.
 *
 * - No WordPress account at all: excluded by the INNER JOIN on users.
 * - Account but no password yet: excluded by the mtl_setup_pending check, since
 *   a setup link is a different email with a different job.
 *
 * The panel reports the exclusion rather than dropping people silently, which
 * would look identical to a successful send.
 *
 * @param string $audience   'outstanding' or 'all'. Validated by the caller.
 * @param bool   $resend_all Include people emailed in the last day.
 * @return string SQL fragment beginning "FROM".
 */
function mtl_agreement_request_queue_from_where( $audience, $resend_all ) {
	global $wpdb;
	$members     = $wpdb->prefix . 'members';
	$agreements  = $wpdb->prefix . 'member_agreements';
	$acceptances = $wpdb->prefix . 'member_agreement_acceptances';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "FROM {$members} m
		 INNER JOIN {$wpdb->users} u ON u.user_email = m.email
		 INNER JOIN {$wpdb->usermeta} l ON l.user_id = u.ID AND l.meta_key = 'mtl_member_id' AND CAST(l.meta_value AS UNSIGNED) = m.member_id
		  LEFT JOIN {$wpdb->usermeta} s ON s.user_id = u.ID AND s.meta_key = 'mtl_setup_pending'
		  LEFT JOIN {$wpdb->usermeta} r ON r.user_id = u.ID AND r.meta_key = 'mtl_agreement_requested_at'
		 WHERE m.anonymized_at IS NULL
		   AND m.email <> ''
		   AND s.umeta_id IS NULL";

	if ( 'outstanding' === $audience ) {
		// The same set the Advanced Search filter's two amber states return:
		// missing ANY active agreement, whether they never agreed to anything
		// or simply have not caught up with a revision.
		$sql .= ' AND ' . mtl_agreements_outstanding_sql();
	}

	if ( ! $resend_all ) {
		$sql .= $wpdb->prepare(
			' AND ( r.umeta_id IS NULL OR CAST(r.meta_value AS UNSIGNED) < %d )',
			time() - DAY_IN_SECONDS
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return $sql;
}

/**
 * The only place an audience string is trusted.
 *
 * $audience originates in a POST field and is threaded into query
 * construction, so it is whitelisted here and only the validated value is
 * passed onward. An unrecognised value must never WIDEN the audience: the safe
 * fallback is the narrower set.
 *
 * @param string $audience Raw value.
 * @return string 'outstanding' or 'all'.
 */
function mtl_valid_agreement_audience( $audience ) {
	return in_array( $audience, array( 'outstanding', 'all' ), true ) ? $audience : 'outstanding';
}

/**
 * How many members are due an agreement request right now.
 *
 * @param string $audience   'outstanding' or 'all'.
 * @param bool   $resend_all Include people emailed in the last day.
 * @return int
 */
function mtl_count_members_awaiting_agreement_request( $audience = 'outstanding', $resend_all = false ) {
	global $wpdb;
	$audience = mtl_valid_agreement_audience( $audience );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment is table names plus a prepare()d timestamp; see mtl_agreement_request_queue_from_where().
	return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . mtl_agreement_request_queue_from_where( $audience, (bool) $resend_all ) );
}

/**
 * The next batch of member ids to ask.
 *
 * Re-derived each call rather than paged with an offset: sending stamps
 * mtl_agreement_requested_at, which drops that member out of the set, so
 * repeated runs walk forward on their own.
 *
 * @param int    $limit      Maximum ids to return.
 * @param string $audience   'outstanding' or 'all'.
 * @param bool   $resend_all Include people emailed in the last day.
 * @return int[]
 */
function mtl_members_awaiting_agreement_request( $limit, $audience = 'outstanding', $resend_all = false ) {
	global $wpdb;
	$audience = mtl_valid_agreement_audience( $audience );

	$sql = 'SELECT m.member_id ' . mtl_agreement_request_queue_from_where( $audience, (bool) $resend_all )
		. $wpdb->prepare( ' ORDER BY m.member_id ASC LIMIT %d', (int) $limit );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragment is table names plus prepare()d values; see mtl_agreement_request_queue_from_where().
	return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
}

/**
 * How many of the members due a request have never agreed to anything.
 *
 * A subset of mtl_count_members_awaiting_agreement_request( 'outstanding' ),
 * built from the same shared fragment so the two populations cannot drift;
 * the panel reports this as "N of those", and it has to be true of that N.
 *
 * @param bool $resend_all Include people emailed in the last day.
 * @return int
 */
function mtl_count_agreement_request_never_agreed( $resend_all = false ) {
	global $wpdb;
	$acceptances = $wpdb->prefix . 'member_agreement_acceptances';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = 'SELECT COUNT(*) ' . mtl_agreement_request_queue_from_where( 'outstanding', (bool) $resend_all )
		. " AND NOT EXISTS ( SELECT 1 FROM {$acceptances} a2 WHERE a2.member_id = m.member_id )";
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names plus the prepare()d fragment above.
	return (int) $wpdb->get_var( $sql );
}

/**
 * How many members would be asked but cannot sign in, so are excluded.
 *
 * Reported prominently by the panel rather than silently dropped: a silent drop
 * looks identical to a successful send, and the admin would never learn that
 * 47 people were skipped.
 *
 * @param string $audience 'outstanding' or 'all'.
 * @return int
 */
function mtl_count_agreement_request_excluded( $audience = 'outstanding' ) {
	global $wpdb;
	$audience = mtl_valid_agreement_audience( $audience );
	$members  = $wpdb->prefix . 'members';

	// The same population inverted: everyone in the audience who has no usable
	// account. A separate query rather than a flag on the one above, so the
	// exclusion cannot be confused with the send list.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "SELECT COUNT(*) FROM {$members} m
		  LEFT JOIN {$wpdb->users} u ON u.user_email = m.email
		  LEFT JOIN {$wpdb->usermeta} l ON l.user_id = u.ID AND l.meta_key = 'mtl_member_id' AND CAST(l.meta_value AS UNSIGNED) = m.member_id
		  LEFT JOIN {$wpdb->usermeta} s ON s.user_id = u.ID AND s.meta_key = 'mtl_setup_pending'
		 WHERE m.anonymized_at IS NULL
		   AND ( u.ID IS NULL OR l.umeta_id IS NULL OR s.umeta_id IS NOT NULL OR m.email = '' )";

	if ( 'outstanding' === $audience ) {
		$sql .= ' AND ' . mtl_agreements_outstanding_sql();
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names only, built from $wpdb->prefix.
	return (int) $wpdb->get_var( $sql );
}

/**
 * Asks as many members as fit in the time budget.
 *
 * Same shape as mtl_run_setup_email_batch(), and for the same reason: this is
 * SMTP-bound work whose per-item cost varies by orders of magnitude between
 * environments, so a fixed row count is either pointlessly small locally or a
 * guaranteed timeout in production.
 *
 * SENDING CHANGES NO MEMBER'S STATUS. Not one acceptance row is written,
 * altered or invalidated. The only thing it writes is the per-member send
 * throttle.
 *
 * @param string $audience       'outstanding' or 'all'.
 * @param bool   $resend_all     Ignore the once-a-day guard.
 * @param int    $budget_seconds Wall-clock budget.
 * @param int    $max_items      Hard cap, as a second guard on the budget.
 * @return array{sent:int, failed:int, remaining:int, excluded:int}
 */
function mtl_run_agreement_request_batch( $audience = 'outstanding', $resend_all = false, $budget_seconds = 20, $max_items = 200 ) {
	$audience = mtl_valid_agreement_audience( $audience );
	$started  = microtime( true );
	$sent     = 0;
	$failed   = 0;

	foreach ( mtl_members_awaiting_agreement_request( (int) $max_items, $audience, $resend_all ) as $member_id ) {
		if ( microtime( true ) - $started > $budget_seconds ) {
			break;
		}

		if ( mtl_send_agreement_request_email( $member_id ) ) {
			++$sent;
		} else {
			++$failed;
		}
	}

	return array(
		'sent'      => $sent,
		'failed'    => $failed,
		'remaining' => mtl_count_members_awaiting_agreement_request( $audience, $resend_all ),
		'excluded'  => mtl_count_agreement_request_excluded( $audience ),
	);
}

// ==========================================================================
// MEMBER AGREEMENTS: FILE FINGERPRINTS
//
// Attached files are Media Library items, so fingerprinting one is a plain
// filesystem read of a path WordPress computed: no network call, no
// authentication, no Content-Type sniffing and no admin-supplied path to
// contain.
// ==========================================================================

/**
 * Largest file this will hash, in bytes.
 *
 * Hashing a multi-gigabyte file blocks the request for as long as it takes
 * hash_file() to read it. Anything above this is treated exactly like an unreadable
 * file: no fingerprint, nothing blocked.
 */
define( 'MTL_AGREEMENT_HASH_MAX_BYTES', 64 * 1024 * 1024 );

/**
 * SHA-256 of an attachment's file.
 *
 * Single return contract: a 64-character lowercase hex hash, or '' meaning "not
 * recorded". Never throws, never fatals, and never blocks anything. A member
 * must still be able to agree when a fingerprint could not be taken.
 *
 * Takes an attachment ID, not a URL, because the ID is what the plugin stores.
 *
 * @param int $attachment_id Media Library attachment ID.
 * @return string 64 hex characters, or '' if no fingerprint could be taken.
 */
function mtl_agreement_file_hash( $attachment_id ) {
	if ( 'ok' !== mtl_agreement_file_hash_status( $attachment_id ) ) {
		return '';
	}
	$path = get_attached_file( (int) $attachment_id );
	$hash = hash_file( 'sha256', $path );
	return is_string( $hash ) ? $hash : '';
}

/**
 * Why mtl_agreement_file_hash() would or would not produce a fingerprint.
 *
 * Exists so the Setup page can say WHY no fingerprint was recorded instead of
 * leaving the admin guessing at a blank field.
 *
 * @param int $attachment_id Media Library attachment ID.
 * @return string 'ok', 'not_an_attachment', 'missing_file' or 'too_large'.
 */
function mtl_agreement_file_hash_status( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
		return 'not_an_attachment';
	}
	$path = get_attached_file( $attachment_id );
	if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
		return 'missing_file';
	}
	$size = filesize( $path );
	if ( false === $size || $size > MTL_AGREEMENT_HASH_MAX_BYTES ) {
		return 'too_large';
	}
	return 'ok';
}

// ==========================================================================
// MEMBER AGREEMENTS: THE WRITE PATH
//
// One writer. Its four call sites (signup, the account page, Add New Member,
// Edit Member) state only who, which agreement, which context and what version
// they had on screen; every other column is derived here.
//
// Two rules govern everything below:
//
// 1. Nothing UPDATEs or DELETEs an acceptance row. Agreeing again inserts
// another; reads take the newest.
// 2. Snapshot columns are copied at insert and never joined for afterwards, so
// editing an agreement in 2028 cannot change what a member is recorded as having
// agreed to in 2026.
// ==========================================================================

/**
 * Records one member's acceptance of one agreement.
 *
 * Callers pass only who, which, from where, and what version they displayed;
 * never the snapshot values, since a caller that forgot one would write a
 * permanent record that is quietly wrong.
 *
 * Fails closed on every doubt. Each early return below is a case where the row
 * could not be trusted to describe what happened, and a missing record is
 * recoverable where a false one is not.
 *
 * @param int      $member_id    Member the acceptance belongs to.
 * @param int      $agreement_id Agreement being accepted.
 * @param string   $context      One of the keys of mtl_agreement_contexts().
 * @param int|null $seen_version The version_num the form displayed. Null skips
 *                               the check, which every real caller supplies;
 *                               nothing writes an acceptance without having
 *                               shown somebody something first.
 * @param string   $accepted_at  UTC 'Y-m-d H:i:s' for when the member agreed.
 *                               Blank means now, which every interactive path
 *                               wants. Only the bulk CSV passes a value, since
 *                               a paper form carries the date it was signed;
 *                               it is validated against version_published_at
 *                               there, not here.
 * @return int The new acceptance_id, or 0 if nothing was written. Truthy on
 *             success either way, so a caller that only asks "did it write?"
 *             reads correctly, but a caller that needs to email exactly the
 *             rows it just wrote should keep the ids rather than re-deriving
 *             them from the timestamp, which has one-second resolution and
 *             cannot separate two events in the same second.
 */
function mtl_record_agreement_acceptance( $member_id, $agreement_id, $context, $seen_version = null, $accepted_at = '' ) {
	global $wpdb;

	$member_id    = (int) $member_id;
	$agreement_id = (int) $agreement_id;

	if ( $member_id <= 0 || $agreement_id <= 0 ) {
		return 0;
	}

	// tracking(), not online(): paper mode must still write staff-recorded
	// rows. Off writes nothing at all.
	if ( ! mtl_agreements_tracking() ) {
		return 0;
	}

	// An unrecognised context has no assent wording and no staff/member answer,
	// so a row written under one would claim agreement framed by nothing.
	if ( ! mtl_agreement_context_is_valid( $context ) ) {
		return 0;
	}

	$accepted_at = trim( (string) $accepted_at );
	if ( '' === $accepted_at || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $accepted_at ) ) {
		$accepted_at = gmdate( 'Y-m-d H:i:s' );
	}

	$is_staff = mtl_acceptance_is_staff( $context );

	// Neither side can tick a box on a site where no box is rendered. One
	// arriving anyway means a forged POST or a form rendered before the setting
	// changed, and writing it would make accepted_context a lie: a member row in
	// paper mode, or a staff attestation at a library that switched the desk
	// button off.
	if ( 'paper' === mtl_agreements_mode() && ! $is_staff ) {
		return 0;
	}
	if ( $is_staff && ! mtl_agreements_staff_recording() ) {
		return 0;
	}

	// Deriving acted_by from the session rather than accepting it is what makes
	// accepted_context and acted_by impossible to contradict. A staff context
	// with nobody logged in would be an unattributed attestation, which is the
	// exact thing that derivation exists to prevent, so it is refused rather
	// than recorded as user 0.
	$acted_by = $is_staff ? get_current_user_id() : 0;
	if ( $is_staff && $acted_by <= 0 ) {
		return 0;
	}

	$agreement = mtl_get_agreement( $agreement_id );
	if ( ! $agreement || null !== $agreement->retired_at ) {
		return 0;
	}

	// The one caller-supplied value, and it can only ever cause a refusal. If
	// the version on screen is not the version now live, the row would claim
	// agreement to wording the person never read.
	if ( null !== $seen_version && (int) $seen_version !== (int) $agreement->version_num ) {
		return 0;
	}

	$members_table = $wpdb->prefix . 'members';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix.
	$member = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name, email FROM {$members_table} WHERE member_id = %d", $member_id ) );
	if ( ! $member ) {
		return 0;
	}

	// Resolved live from the attachment ID rather than read from a stored URL,
	// so a site that has since moved domain still records a working address.
	$file_url = (int) $agreement->attachment_id > 0 ? (string) wp_get_attachment_url( (int) $agreement->attachment_id ) : '';

	// The column holds 512 characters. A longer URL is dropped rather than
	// truncated or allowed to fail the insert: a truncated URL looks valid and
	// is not, and refusing the whole acceptance over an unusually long path
	// would block a member from agreeing for a reason that has nothing to do
	// with them. file_sha256 still identifies the document either way.
	if ( strlen( $file_url ) > 512 ) {
		$file_url = '';
	}

	$member_name = trim( $member->first_name . ' ' . $member->last_name );

	$inserted = $wpdb->insert(
		$wpdb->prefix . 'member_agreement_acceptances',
		array(
			'member_id'                      => $member_id,
			'agreement_id'                   => $agreement_id,
			'accepted_at'                    => $accepted_at,
			'agreement_text'                 => $agreement->agreement_text,
			'assent_text'                    => mtl_assent_language( $context ),
			'agreement_version_num'          => (int) $agreement->version_num,
			'agreement_version_published_at' => $agreement->version_published_at,
			'file_url'                       => '' !== $file_url ? $file_url : null,
			'file_sha256'                    => ! empty( $agreement->file_sha256 ) ? $agreement->file_sha256 : null,
			'accepted_context'               => $context,
			'acted_by'                       => $is_staff ? $acted_by : null,
			'member_name'                    => $member_name,
			'member_email'                   => $member->email,
		),
		array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	return $inserted ? (int) $wpdb->insert_id : 0;
}

/**
 * Records everything a member currently owes, in one call.
 *
 * Three of the four call sites want exactly this: signup, Add New Member and
 * Edit Member all present the whole outstanding set at once. Only the account
 * page records individually, because a member there may tick some and not
 * others.
 *
 * PARTIAL FAILURE IS A FAILURE. Compare the return value against the number you
 * expected to write, not against zero. A member left with three of five rows
 * written reads as somebody who has not got round to it, which is the worst
 * available outcome because it looks like ordinary member behaviour rather than
 * a bug. Signup and Add New Member must roll the whole thing back on a
 * shortfall; the account page re-renders what remains.
 *
 * @param int    $member_id     Member to record for.
 * @param string $context       One of the keys of mtl_agreement_contexts().
 * @param array  $seen_versions agreement_id => version_num, straight from the
 *                              form's hidden fields. Never legitimately empty:
 *                              every caller renders a form first.
 * @return int How many rows were written.
 */
function mtl_record_all_outstanding_agreements( $member_id, $context, $seen_versions = array() ) {
	$written = 0;
	foreach ( mtl_member_outstanding_agreements( $member_id ) as $agreement ) {
		$agreement_id = (int) $agreement->agreement_id;
		$seen         = isset( $seen_versions[ $agreement_id ] ) ? (int) $seen_versions[ $agreement_id ] : null;
		if ( mtl_record_agreement_acceptance( $member_id, $agreement_id, $context, $seen ) ) {
			++$written;
		}
	}
	return $written;
}

// ==========================================================================
// MEMBER AGREEMENTS: READING A MEMBER'S POSITION
//
// Status is derived from version comparison and nothing else. There is no
// per-member override, no reset column and no deadline anybody can set: a
// member moves out of agreement when a version number goes up, and back into it
// when they accept the new one.
// ==========================================================================

/**
 * Where one member stands: 'disabled', 'ok', 'outdated' or 'none'.
 *
 * | disabled | The feature is off, or no agreement is active.                |
 * | ok       | Every active agreement accepted at >= its current version.    |
 * | outdated | Something is on record, but not all of it, or not current.    |
 * | none     | No acceptance on record at all.                               |
 *
 * `ok` uses greater-than-or-equal, not equality: if a version_num is ever
 * lowered by hand, members who accepted the higher version must not be dragged
 * backwards and re-prompted for something they have already agreed to.
 *
 * `outdated` and `none` stay distinct because they mean different things to
 * staff: one member was caught by a revision, the other never agreed to
 * anything.
 *
 * Delegates to the map so there is exactly one implementation of the rule. Use
 * this for a single member (the account page, the reserve gate, the Edit form)
 * and mtl_member_agreements_status_map() for a list.
 *
 * @param int $member_id Member to check.
 * @return string
 */
function mtl_member_agreements_status( $member_id ) {
	$map = mtl_member_agreements_status_map( array( (int) $member_id ) );
	return isset( $map[ (int) $member_id ] ) ? $map[ (int) $member_id ] : 'disabled';
}

/**
 * The same answer for many members, in one query.
 *
 * The Membership list must never call the single-member function per row;
 * that is a query per member on a paginated table. This is a batching wrapper
 * around the same comparison, not a second implementation of it.
 *
 * @param int[] $member_ids Members to check.
 * @return array<int, string> member_id => status. Every requested ID is present.
 */
function mtl_member_agreements_status_map( $member_ids ) {
	$member_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $member_ids ) ) ) );
	if ( ! $member_ids ) {
		return array();
	}

	// A mode of off already covers "no agreement is active";
	// mtl_agreements_tracking() is false when no agreement is active, so
	// this one check answers both halves of `disabled`.
	if ( ! mtl_agreements_tracking() ) {
		return array_fill_keys( $member_ids, 'disabled' );
	}

	$active_count = mtl_count_active_agreements();
	$statuses     = array_fill_keys( $member_ids, 'none' );

	global $wpdb;
	$acceptances  = $wpdb->prefix . 'member_agreement_acceptances';
	$agreements   = $wpdb->prefix . 'member_agreements';
	$placeholders = implode( ',', array_fill( 0, count( $member_ids ), '%d' ) );

	// One row per member: how many DISTINCT active agreements they hold a
	// current-or-newer acceptance for, and whether they have any acceptance at
	// all. The retired filter sits in the LEFT JOIN's ON clause so a retired
	// agreement drops out of `satisfied` while its acceptance still counts
	// toward `rows_total`, which is what separates `outdated` from `none` for
	// somebody whose only record is against a retired agreement.
	//
	// The %d placeholders are a counted run built from $member_ids, which the
	// placeholder sniff cannot see through, hence the disable below covering
	// it as well as the table names.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- status is derived per request; there is no cache to invalidate.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT a.member_id,
			        COUNT(DISTINCT CASE WHEN a.agreement_version_num >= g.version_num THEN a.agreement_id END) AS satisfied,
			        COUNT(*) AS rows_total
			   FROM {$acceptances} a
			   LEFT JOIN {$agreements} g
			          ON g.agreement_id = a.agreement_id
			         AND g.retired_at IS NULL
			  WHERE a.member_id IN ({$placeholders})
			  GROUP BY a.member_id",
			$member_ids
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	foreach ( (array) $rows as $row ) {
		$id = (int) $row->member_id;
		if ( (int) $row->satisfied >= $active_count ) {
			$statuses[ $id ] = 'ok';
		} elseif ( (int) $row->rows_total > 0 ) {
			$statuses[ $id ] = 'outdated';
		}
	}

	return $statuses;
}

/**
 * The agreements a member still owes, in the order they are shown.
 *
 * Every active agreement with no acceptance at its current version. The account
 * page renders this, the signup handler compares against it, and the bulk
 * writer loops it.
 *
 * @param int $member_id Member to check.
 * @return object[] Rows from member_agreements, empty when nothing is owed.
 */
function mtl_member_outstanding_agreements( $member_id ) {
	$member_id = (int) $member_id;
	if ( $member_id <= 0 || ! mtl_agreements_tracking() ) {
		return array();
	}

	global $wpdb;
	$agreements  = $wpdb->prefix . 'member_agreements';
	$acceptances = $wpdb->prefix . 'member_agreement_acceptances';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names only, built from $wpdb->prefix.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT g.*
			   FROM {$agreements} g
			  WHERE g.retired_at IS NULL
			    AND NOT EXISTS (
			        SELECT 1
			          FROM {$acceptances} a
			         WHERE a.member_id = %d
			           AND a.agreement_id = g.agreement_id
			           AND a.agreement_version_num >= g.version_num
			    )
			  ORDER BY g.sort_order ASC, g.agreement_id ASC",
			$member_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return (array) $rows;
}

/**
 * Current acceptances for many members, in one query.
 *
 * The same answer mtl_get_member_acceptances() gives for one member.
 * The Membership page renders every listed member's detail panel inline, so the
 * per-member helper would be one query per row on a fifty-row page. Same shape
 * as the existing $member_trainings map on that page.
 *
 * @param int[] $member_ids Members to read.
 * @return array<int, object[]> member_id => acceptance rows, newest per agreement.
 */
function mtl_get_member_acceptances_map( $member_ids ) {
	$member_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $member_ids ) ) ) );
	if ( ! $member_ids ) {
		return array();
	}

	global $wpdb;
	$acceptances  = $wpdb->prefix . 'member_agreement_acceptances';
	$agreements   = $wpdb->prefix . 'member_agreements';
	$placeholders = implode( ',', array_fill( 0, count( $member_ids ), '%d' ) );

	// The subquery groups by member AND agreement, so "newest" is per member per
	// agreement rather than per agreement across everybody.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read per request; there is no cache to invalidate.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT a.*
			   FROM {$acceptances} a
			   JOIN (
			        SELECT member_id, agreement_id, MAX(acceptance_id) AS latest
			          FROM {$acceptances}
			         WHERE member_id IN ({$placeholders})
			         GROUP BY member_id, agreement_id
			   ) newest ON newest.latest = a.acceptance_id
			   LEFT JOIN {$agreements} g ON g.agreement_id = a.agreement_id
			  ORDER BY a.member_id ASC, g.sort_order ASC, a.agreement_id ASC",
			$member_ids
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	$map = array_fill_keys( $member_ids, array() );
	foreach ( (array) $rows as $row ) {
		$map[ (int) $row->member_id ][] = $row;
	}
	return $map;
}

/**
 * Every agreement/version pair each member has EVER accepted.
 *
 * Reads beyond the newest row, unlike everything else in the staff UI, because
 * it backs Advanced Search's "who accepted version 2?" filter: a member who
 * accepted v2 and has since accepted v3 must still be found.
 *
 * @param int[] $member_ids Members to read.
 * @return array<int, string[]> member_id => array of "agreementId:version".
 */
function mtl_get_member_acceptance_versions_map( $member_ids ) {
	$member_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $member_ids ) ) ) );
	if ( ! $member_ids ) {
		return array();
	}

	global $wpdb;
	$acceptances  = $wpdb->prefix . 'member_agreement_acceptances';
	$placeholders = implode( ',', array_fill( 0, count( $member_ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read per request; there is no cache to invalidate.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DISTINCT member_id, agreement_id, agreement_version_num
			   FROM {$acceptances}
			  WHERE member_id IN ({$placeholders})",
			$member_ids
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	$map = array_fill_keys( $member_ids, array() );
	foreach ( (array) $rows as $row ) {
		$map[ (int) $row->member_id ][] = (int) $row->agreement_id . ':' . (int) $row->agreement_version_num;
	}
	return $map;
}

/**
 * Which versions of an agreement anybody has actually accepted.
 *
 * Built from the acceptances rather than counted up to the current version_num,
 * so Advanced Search never offers a version that would return nothing.
 *
 * @param int $agreement_id Agreement to look up.
 * @return int[] Version numbers, ascending.
 */
function mtl_agreement_accepted_versions( $agreement_id ) {
	global $wpdb;
	$acceptances = $wpdb->prefix . 'member_agreement_acceptances';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix.
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT agreement_version_num FROM {$acceptances} WHERE agreement_id = %d ORDER BY agreement_version_num ASC", (int) $agreement_id ) );
	return array_map( 'intval', (array) $rows );
}

/**
 * Every acceptance a member has ever made, oldest first.
 *
 * The one read that shows full history rather than the current position, for the
 * downloadable agreement record: "they agreed to v1 in 2026 and v2 in 2027" is
 * precisely the sequence a dispute turns on.
 *
 * @param int $member_id Member to read.
 * @return object[] Acceptance rows, oldest first.
 */
function mtl_get_member_acceptance_history( $member_id ) {
	global $wpdb;
	$acceptances = $wpdb->prefix . 'member_agreement_acceptances';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name only, built from $wpdb->prefix.
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$acceptances} WHERE member_id = %d ORDER BY acceptance_id ASC", (int) $member_id ) );
}

/**
 * A member's current acceptance of each agreement they have ever accepted.
 *
 * The newest row per agreement, selected on MAX(acceptance_id) rather than
 * MAX(accepted_at): accepted_at has one-second resolution, so two rows written
 * in the same second would be ambiguous, while the auto-increment is monotonic
 * and never collides.
 *
 * Shared by the Membership detail panel, the Edit form, the account page and
 * the agreement record download, so none of them can disagree about which row
 * is current. Retired agreements are included, since the member did agree to them,
 * and that does not stop being true.
 *
 * EVERY DISPLAYED VALUE COMES FROM THE ACCEPTANCE ROW. The join below exists
 * only to order the results the way the rest of the UI orders agreements; not
 * one column is read from it. Reading agreement_text or file_url through that
 * join instead would show today's wording against a years-old acceptance, which
 * is the single failure this table's design exists to prevent.
 *
 * @param int $member_id Member to read.
 * @return object[] Acceptance rows, newest per agreement, in display order.
 */
function mtl_get_member_acceptances( $member_id ) {
	$member_id = (int) $member_id;
	if ( $member_id <= 0 ) {
		return array();
	}

	global $wpdb;
	$acceptances = $wpdb->prefix . 'member_agreement_acceptances';
	$agreements  = $wpdb->prefix . 'member_agreements';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names only, built from $wpdb->prefix.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT a.*
			   FROM {$acceptances} a
			   JOIN (
			        SELECT agreement_id, MAX(acceptance_id) AS latest
			          FROM {$acceptances}
			         WHERE member_id = %d
			         GROUP BY agreement_id
			   ) newest ON newest.latest = a.acceptance_id
			   LEFT JOIN {$agreements} g ON g.agreement_id = a.agreement_id
			  ORDER BY g.sort_order ASC, a.agreement_id ASC",
			$member_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return (array) $rows;
}

// ==========================================================================
// STAFF PERMISSIONS
//
// Two levels of library staff:
//
// Administrator: everything, including the Setup page (branding, database
// setup, exports) and deleting a member's record.
// Editor runs the day-to-day desk: members, inventory, loans and
// reservations, dashboard and workflows. No Setup page and
// no member deletion.
//
// Editor is WordPress's own built-in role, so no custom role is created here;
// the plugin only adds one capability to it. Anything a member can do to
// their OWN account (including deleting it) is unaffected by all of this;
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

// Plain counter, not the plugin version: bump when a table or column is added.
define( 'MTL_DB_VERSION', 3 );

// admin_init, not init: nothing reads these tables on the front end, and it
// covers exactly the requests that can write them.
add_action( 'admin_init', 'mtl_maybe_upgrade_schema' );

/**
 * Creates tables added after this site's database was first set up. Additive
 * only: it creates what is missing, and never alters or drops.
 *
 * The fresh-install path, admin/schema.sql, DROPs every table before creating
 * them, so it can never be how a live library picks up a new one. Runs for the same reason
 * mtl_register_staff_capabilities() does: installs already active when a
 * feature shipped need it too.
 */
function mtl_maybe_upgrade_schema() {
	// Options round-trip as strings, so cast before comparing. >= leaves a
	// downgraded site alone rather than re-running.
	if ( (int) get_option( 'mtl_db_version', 0 ) >= MTL_DB_VERSION ) {
		return;
	}

	global $wpdb;
	$p = $wpdb->prefix;

	// A site that has never run Database Setup has no tables at all, and every
	// definition below foreign-keys one of these. schema.sql will create the new
	// tables with the rest, so there is nothing to add and nothing to record.
	foreach ( array( 'tool_inventory', 'tool_categories', 'member_trainings' ) as $parent ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $parent ) ) !== $p . $parent ) {
			return;
		}
	}

	// Frozen copies of the admin/schema.sql definitions, in dependency order.
	// They stay as they are even if that file changes, so an upgrade always
	// produces the shape it promised. Adding a table here is the whole job of
	// shipping one to sites that already exist.
	$tables = array(
		"CREATE TABLE IF NOT EXISTS {$p}tool_training_mappings (
			tool_id INT,
			training_id INT,
			PRIMARY KEY (tool_id, training_id),
			FOREIGN KEY (tool_id) REFERENCES {$p}tool_inventory(tool_id) ON DELETE CASCADE,
			FOREIGN KEY (training_id) REFERENCES {$p}member_trainings(training_id) ON DELETE CASCADE
		)",
		"CREATE TABLE IF NOT EXISTS {$p}tool_subcategories (
			subcategory_id INT AUTO_INCREMENT PRIMARY KEY,
			category_id INT NOT NULL,
			subcategory_name VARCHAR(50) NOT NULL,
			UNIQUE KEY category_subcategory (category_id, subcategory_name),
			UNIQUE KEY subcategory_category (subcategory_id, category_id),
			FOREIGN KEY (category_id) REFERENCES {$p}tool_categories(category_id) ON DELETE CASCADE
		)",
		"CREATE TABLE IF NOT EXISTS {$p}tool_subcategory_mappings (
			tool_id INT,
			category_id INT,
			subcategory_id INT,
			PRIMARY KEY (tool_id, category_id),
			KEY subcategory (subcategory_id),
			FOREIGN KEY (tool_id) REFERENCES {$p}tool_inventory(tool_id) ON DELETE CASCADE,
			FOREIGN KEY (subcategory_id, category_id) REFERENCES {$p}tool_subcategories(subcategory_id, category_id) ON DELETE CASCADE
		)",
	);

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table names only, built from $wpdb->prefix, not user input.
	foreach ( $tables as $sql ) {
		// Not recorded on failure, so the next request retries the whole set.
		// IF NOT EXISTS makes the ones that already landed a no-op.
		if ( false === $wpdb->query( $sql ) ) {
			return;
		}
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

	update_option( 'mtl_db_version', MTL_DB_VERSION );
}

/**
 * Whether the current user may use the plugin's admin portal at all:
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
 * the data exports). Administrators only, because these change how the whole
 * library behaves, or hand over a file containing every member's details.
 *
 * @return bool
 */
function mtl_can_manage_settings() {
	return current_user_can( 'manage_options' ) && mtl_admin_view_enabled();
}

/**
 * Whether this administrator is currently using the full admin view.
 *
 * A PER-USER PREFERENCE, NOT A PERMISSION. Switching it off narrows what the
 * plugin offers the person who set it, so Delete, Export and Run Database Setup
 * cannot be hit by accident during a shift on the desk. The account keeps
 * manage_options throughout and can switch back whenever it likes, so never use
 * this in place of a role.
 *
 * Per user rather than site-wide: one administrator going to the desk must not
 * take Setup away from the others.
 *
 * @param int $user_id User to ask about; 0 means whoever is signed in.
 * @return bool
 */
function mtl_admin_view_enabled( $user_id = 0 ) {
	$user_id = $user_id > 0 ? (int) $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return true;
	}

	// Absent meta means on, so an existing site is unchanged by this feature.
	return '0' !== (string) get_user_meta( $user_id, 'mtl_admin_view_off', true );
}

/**
 * Whether this account could use the full admin view if it chose to.
 *
 * Stays true while the view is switched off, which is why Setup's routing, its
 * tab and its toggle all ask this rather than mtl_can_manage_settings(): those
 * three are the way back, and gating them on the view would strand the user.
 *
 * @return bool
 */
function mtl_is_administrator() {
	return current_user_can( 'manage_options' );
}

/**
 * Whether the current user may delete a member's record. Administrators only:
 * it destroys personal data irreversibly, and where the member has loan
 * history it rewrites that history's owner (see
 * mtl_delete_or_anonymize_member()).
 *
 * Members deleting their OWN account from the public Account page do not go
 * through this; see mtl_render_account_page() in public/member-pages.php.
 * That right is theirs regardless of who is on staff.
 *
 * Withdrawn while the admin view is off, which is the main thing that switch
 * is for: this is the button a librarian on the desk should not be able to hit
 * by accident.
 *
 * @return bool
 */
function mtl_can_delete_members() {
	return mtl_can_manage_settings();
}

/**
 * Whether the current user may delete a tool from inventory. Administrators
 * only, matching mtl_can_delete_members(): it is irreversible and drops the
 * record of an asset the library owns.
 *
 * Editors keep every other tool action, including Retire, which is the
 * reversible way to take something out of circulation, and the right answer
 * for almost every case Delete used to be reached for.
 *
 * Deleting a tool that has loan or reservation history is separately blocked
 * by a foreign key, and that is unchanged by this check (see the delete
 * handler in admin/inventory-page.php).
 *
 * Withdrawn while the admin view is off, matching mtl_can_delete_members().
 *
 * @return bool
 */
function mtl_can_delete_tools() {
	return mtl_can_manage_settings();
}

/**
 * Whether the current user may bulk-import tools or members from a CSV file,
 * and download the CSV templates that go with it. Administrators only.
 *
 * A bulk import writes hundreds of rows from a single upload, with no
 * per-row confirmation and no undo -- a mistyped column or a stale
 * spreadsheet lands in the live library all at once, and unpicking it means
 * deleting the rows by hand. That is a different kind of action from the
 * per-record adds and edits Editors do at the desk all day, so it sits with
 * the other irreversible, whole-library operations (Setup, deleting a member,
 * deleting a tool) rather than with everyday membership and inventory work.
 *
 * Editors keep Add a New Member and Add New Tool, so nothing about running
 * the desk needs an administrator.
 *
 * Withdrawn while the admin view is off, matching mtl_can_delete_members().
 *
 * @return bool
 */
function mtl_can_bulk_import() {
	return mtl_can_manage_settings();
}

// ==========================================================================
// TAXONOMY TREE FILTER
//
// Categories and sub-categories are chosen together, in one control, with OR
// between them: ticking "Automotive" matches every Automotive tool whether or
// not it has a sub-category, and ticking "Electrical > Testing & Metering"
// matches that sub-category alone. Nothing expands a parent into its children,
// because tool_category_mappings is independent of tool_subcategory_mappings.
//
// The rule is written TWICE, once as SQL for the public catalog and once as
// JavaScript for the admin pages, which filter rendered rows rather than
// querying. mtl_taxonomy_where() and the matcher in
// mtl_taxonomy_matcher_script() are a pair and must agree; change one, change the
// other, or the catalog and Inventory will disagree about the same tool.
//
// Within this control the selections are OR'd. Across controls (tags, status,
// and so on) they are AND'd, which is how the filters already behaved.
// ==========================================================================

/**
 * Rows for the tree, parents each carrying their children.
 *
 * @return array List of objects: category_id, category_name, children[].
 */
function mtl_taxonomy_tree_rows() {
	global $wpdb;

	$tbl_cats    = $wpdb->prefix . 'tool_categories';
	$tbl_subcats = $wpdb->prefix . 'tool_subcategories';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, no request-derived data.
	$categories = $wpdb->get_results( "SELECT category_id, category_name FROM {$tbl_cats} ORDER BY category_name ASC" );
	$subs       = $wpdb->get_results( "SELECT subcategory_id, category_id, subcategory_name FROM {$tbl_subcats} ORDER BY subcategory_name ASC" );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$by_parent = array();
	foreach ( (array) $subs as $sub ) {
		$by_parent[ (int) $sub->category_id ][] = $sub;
	}
	foreach ( (array) $categories as $cat ) {
		$cat->children = isset( $by_parent[ (int) $cat->category_id ] ) ? $by_parent[ (int) $cat->category_id ] : array();
	}
	return (array) $categories;
}

/**
 * Renders the category / sub-category tree as nested checkboxes.
 *
 * Used by the public catalog and by both admin pages, so the control reads the
 * same everywhere. The catalog submits it as a form; the admin pages read the
 * same checkboxes from JavaScript.
 *
 * @param array  $rows      From mtl_taxonomy_tree_rows().
 * @param array  $sel_cats  Ticked category ids.
 * @param array  $sel_subs  Ticked sub-category ids.
 * @param string $id_prefix Prefix for element ids, so two trees can coexist.
 */
function mtl_taxonomy_tree( $rows, $sel_cats = array(), $sel_subs = array(), $id_prefix = 'mtl-tx' ) {
	if ( ! $rows ) {
		echo '<p class="mtl-tx-empty">No categories yet.</p>';
		return;
	}
	?>
	<div class="mtl-tx-tree" id="<?php echo esc_attr( $id_prefix ); ?>">
		<?php foreach ( $rows as $cat ) : ?>
			<?php $cat_id = (int) $cat->category_id; ?>
			<div class="mtl-tx-branch">
				<label class="mtl-tx-parent">
					<input type="checkbox" name="mtl_cat[]" value="<?php echo esc_attr( $cat_id ); ?>" data-tx-parent="<?php echo esc_attr( $cat_id ); ?>" <?php checked( in_array( $cat_id, $sel_cats, true ) ); ?>>
					<span><?php echo esc_html( $cat->category_name ); ?></span>
				</label>
				<?php foreach ( $cat->children as $sub ) : ?>
					<?php $sub_id = (int) $sub->subcategory_id; ?>
					<label class="mtl-tx-child">
						<input type="checkbox" name="mtl_subcat[]" value="<?php echo esc_attr( $sub_id ); ?>" data-tx-child-of="<?php echo esc_attr( $cat_id ); ?>" <?php checked( in_array( $sub_id, $sel_subs, true ) ); ?>>
						<span><?php echo esc_html( $sub->subcategory_name ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * The SQL half of the OR rule. See the block comment above.
 *
 * @param array  $sel_cats    Ticked category ids.
 * @param array  $sel_subs    Ticked sub-category ids.
 * @param string $tool_column Tool id column to correlate against, e.g. 't.tool_id'.
 * @return array array( where_fragment_or_empty_string, args[] )
 */
function mtl_taxonomy_where( $sel_cats, $sel_subs, $tool_column = 't.tool_id' ) {
	global $wpdb;

	if ( ! $sel_cats && ! $sel_subs ) {
		return array( '', array() );
	}

	$tbl_cat_map    = $wpdb->prefix . 'tool_category_mappings';
	$tbl_subcat_map = $wpdb->prefix . 'tool_subcategory_mappings';

	$parts = array();
	$args  = array();
	if ( $sel_cats ) {
		$ph      = implode( ',', array_fill( 0, count( $sel_cats ), '%d' ) );
		$parts[] = "EXISTS (SELECT 1 FROM {$tbl_cat_map} txc WHERE txc.tool_id = {$tool_column} AND txc.category_id IN ({$ph}))";
		$args    = array_merge( $args, $sel_cats );
	}
	if ( $sel_subs ) {
		$ph      = implode( ',', array_fill( 0, count( $sel_subs ), '%d' ) );
		$parts[] = "EXISTS (SELECT 1 FROM {$tbl_subcat_map} txs WHERE txs.tool_id = {$tool_column} AND txs.subcategory_id IN ({$ph}))";
		$args    = array_merge( $args, $sel_subs );
	}

	return array( '(' . implode( ' OR ', $parts ) . ')', $args );
}

/**
 * Renders the "Requires Training" filter used by the staff advanced searches.
 *
 * Three states, not two. Nothing selected leaves the filter off and every tool
 * through, including tools that require no training at all. "Any" matches a
 * tool that requires at least one training, whichever it is, which is the same
 * answer as selecting every training in the list. Naming specific trainings
 * matches a tool requiring at least one of them.
 *
 * Public catalog does not get this: it is a staff question about what a tool
 * demands, not something a browsing member is choosing between.
 *
 * @param array  $trainings  Training rows (training_id, training_name).
 * @param string $element_id id for the select, so both pages stay distinct.
 */
function mtl_training_filter_select( $trainings, $element_id ) {
	?>
	<div class="mtl-adv-multi">
		<label for="<?php echo esc_attr( $element_id ); ?>">Requires Training</label>
		<select id="<?php echo esc_attr( $element_id ); ?>" multiple size="4">
			<option value="any">Any training</option>
			<?php foreach ( $trainings as $training ) : ?>
				<option value="<?php echo esc_attr( $training->training_id ); ?>"><?php echo esc_html( $training->training_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<small>Leave empty for any. Ctrl-click (&#8984;-click on Mac) to pick several.</small>
	</div>
	<?php
}

/**
 * The JavaScript half of the OR rule, and the twin of mtl_taxonomy_where().
 *
 * The admin pages render every row and hide the non-matches, so they cannot
 * use the SQL above. This prints the same rule as two globals:
 *
 *   mtlTaxonomySelection( tree )              -> { cats: [ids], subs: [ids] }
 *   mtlTaxonomyMatches( sel, catIds, subIds ) -> bool
 *   mtlIdsIntersect( csvIds, picked )         -> bool, the same any-of test
 *                                                the tag selects need
 *   mtlTaxonomyClear( tree )                  -> unticks it, and re-enables
 *                                                children a parent had covered
 *   mtlTrainingMatches( picked, csvIds )      -> bool, with "any" meaning
 *                                                "requires at least one"
 *
 * Matching is by id, not by name, so it agrees with the SQL exactly and is not
 * confused by two categories owning a sub-category of the same name. An empty
 * selection matches everything, which is what "leave it blank for any" means.
 *
 * A ticked parent is included on its own, never expanded into its children:
 * the category mapping already covers every tool in that category. Its
 * children are read as disabled and skipped, since they could only repeat it.
 */
function mtl_taxonomy_matcher_script() {
	?>
	<script>
		window.mtlTaxonomySelection = function ( tree ) {
			var sel = { cats: [], subs: [] };
			if ( ! tree ) {
				return sel;
			}
			tree.querySelectorAll( 'input[type="checkbox"]:checked' ).forEach( function ( box ) {
				if ( box.disabled ) {
					return;
				}
				if ( box.hasAttribute( 'data-tx-parent' ) ) {
					sel.cats.push( box.value );
				} else if ( box.hasAttribute( 'data-tx-child-of' ) ) {
					sel.subs.push( box.value );
				}
			} );
			return sel;
		};

		// Nothing picked means no match, which is what the OR below needs from
		// each side. mtlIdsIntersect() adds the "empty means any" rule that a
		// filter used on its own wants; the two must not be confused.
		var anyIn = function ( csvIds, picked ) {
			if ( ! picked.length ) {
				return false;
			}
			var rowIds = csvIds ? String( csvIds ).split( ',' ) : [];
			return picked.some( function ( id ) {
				return rowIds.indexOf( id ) !== -1;
			} );
		};

		window.mtlTaxonomyMatches = function ( sel, catIds, subIds ) {
			if ( ! sel.cats.length && ! sel.subs.length ) {
				return true;
			}
			return anyIn( catIds, sel.cats ) || anyIn( subIds, sel.subs );
		};

		window.mtlIdsIntersect = function ( csvIds, picked ) {
			return ! picked.length || anyIn( csvIds, picked );
		};

		window.mtlTrainingMatches = function ( picked, csvIds ) {
			if ( ! picked.length ) {
				return true;
			}
			var rowIds = csvIds ? String( csvIds ).split( ',' ) : [];
			// "Any" is the whole list, so it wins over anything picked with it.
			if ( picked.indexOf( 'any' ) !== -1 ) {
				return rowIds.length > 0;
			}
			return anyIn( csvIds, picked );
		};

		window.mtlTaxonomyClear = function ( tree ) {
			if ( ! tree ) {
				return;
			}
			tree.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( box ) {
				box.checked = false;
			} );
			tree.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		};
	</script>
	<?php
}

/**
 * Style and behaviour for the tree: indentation, and greying a branch's
 * children out while its parent is ticked.
 *
 * A ticked parent already matches every tool in that category, so its children
 * could only ever be redundant. Disabling them says so, and keeps redundant
 * ids out of the query string.
 *
 * Callers emit this wherever suits their page, sometimes above the tree markup,
 * so it waits for the document rather than assuming the tree is there.
 */
function mtl_taxonomy_tree_assets() {
	?>
	<style>
		.mtl-tx-tree { height: 170px; overflow-y: auto; border: 1px solid #ccd0d4; background: #fff; padding: 8px 10px; border-radius: 4px; box-sizing: border-box; }
		/* Both admin pages set width:100% on bare inputs in their filter panels,
		and a front-end theme may size checkboxes too. Pinned, and specific enough
		to win, so the box stays a box wherever the tree is dropped. */
		.mtl-tx-tree .mtl-tx-parent input[type="checkbox"],
		.mtl-tx-tree .mtl-tx-child input[type="checkbox"] {
			width: 16px;
			min-width: 16px;
			max-width: 16px;
			height: 16px;
			min-height: 16px;
			margin: 0;
			padding: 0;
			flex: 0 0 auto;
			box-sizing: border-box;
		}
		.mtl-tx-branch { margin-bottom: 6px; }
		.mtl-tx-parent, .mtl-tx-child { display: flex; align-items: center; flex-wrap: nowrap; gap: 6px; line-height: 1.6; }
		.mtl-tx-parent { font-weight: 600; }
		.mtl-tx-child { margin-left: 22px; font-weight: 400; }
		.mtl-tx-child.mtl-tx-covered { opacity: 0.5; }
		.mtl-tx-empty { color: #666; font-size: 0.85em; margin: 0; }
	</style>
	<script>
	( function () {
		var wire = function () {
			document.querySelectorAll( '.mtl-tx-tree' ).forEach( function ( tree ) {
				var sync = function () {
					tree.querySelectorAll( '[data-tx-parent]' ).forEach( function ( parent ) {
						var id = parent.getAttribute( 'data-tx-parent' );
						tree.querySelectorAll( '[data-tx-child-of="' + id + '"]' ).forEach( function ( child ) {
							child.disabled = parent.checked;
							child.closest( '.mtl-tx-child' ).classList.toggle( 'mtl-tx-covered', parent.checked );
						} );
					} );
				};
				tree.addEventListener( 'change', sync );
				sync();
			} );
		};
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', wire );
		} else {
			wire();
		}
	}() );
	</script>
	<?php
}


// ADMIN MENUS: Register the portal pages.
// add_submenu_page() both places a sidebar link AND registers the page's
// routing/render callback/capability check, so all six must stay
// registered here even though their sidebar links are hidden below; only
// the top-level "My Tool Library" button stays visible, and navigation
// happens through the portal tab bar.
add_action( 'admin_menu', 'mtl_register_admin_menus' );

/**
 * Registers the plugin's top-level admin page and its six portal pages.
 *
 * Every page except Setup is registered against MTL_STAFF_CAP, so Editors
 * reach them and WordPress itself refuses anyone else. Setup keeps
 * manage_options, which is what stops an Editor opening it by URL. Core
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
// searching the $submenu registration DURING routing, because removing the entries
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
// "View Main Page" and "Log Out" links, so the six separate page files
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
	// mtl_is_administrator(), not mtl_can_manage_settings(): this tab is the way
	// back when the admin view is off.
	if ( mtl_is_administrator() ) {
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
	// the public main page, where admin pages are no longer accessible.
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
 * Registers and flushes the rewrite rule, and creates any missing tables.
 */
function mtl_plugin_activate() {
	mtl_maybe_upgrade_schema();
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
	// Likewise unschedule the reservation sweep, so WordPress isn't left
	// trying to fire an event whose callback no longer exists.
	wp_clear_scheduled_hook( 'mtl_daily_reservation_sweep' );
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
	$vars[] = 'mtl_cat';     // Advanced: category ids (multi-select, so a list).
	$vars[] = 'mtl_tag';     // Advanced: tag ids (multi-select, so a list).
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
// mtl_page=main: public tool catalog (shop-page.php); also
// processes the "reserve a tool" POST.
// mtl_page=login: branded sign-in for members AND admins, via
// core's wp_login_form(). WordPress handles all
// credential/cookie/session security.
// mtl_page=signup: member self-registration (member-pages.php):
// creates a WP user + a {prefix}members row.
// mtl_page=reservations: a member's queue, place in line, cancel.
// mtl_page=account: a member's profile, verification status, loan
// history, and profile edits.
// mtl_page=admin: gate: routes a signed-in admin into the admin
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
 * @param string $page One of 'main', 'login', 'signup', 'reservations', 'account',
 *                     'admin', 'lostpassword', 'resetpass'.
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
	} elseif ( 'lostpassword' === $page ) {
		mtl_render_lost_password_page();
	} elseif ( 'resetpass' === $page ) {
		mtl_render_reset_password_page();
	}
	// Unknown values fall through to the theme's normal 404/home handling.
}

/**
 * The public contact address members are told to write to, or '' when the
 * library has not set one.
 *
 * Deliberately has no admin_email fallback. This address is printed on pages
 * anybody can read, and the site administrator's personal mailbox is not
 * something to publish because a setting was left blank; an unset value
 * means "show no contact line", never "guess one".
 *
 * @return string Valid email address, or '' when unset or unusable.
 */
function mtl_contact_email() {
	$email = sanitize_email( (string) get_option( 'mtl_contact_email', '' ) );

	// sanitize_email() already returns '' for anything malformed; the is_email()
	// check keeps a stored-but-broken value from reaching a mailto: link.
	return is_email( $email ) ? $email : '';
}

/**
 * The "contact staff" line for the front-end footer, or '' when no address is
 * configured.
 *
 * @return string
 */
function mtl_contact_line_html() {
	$email = mtl_contact_email();
	if ( '' === $email ) {
		return '';
	}

	return '<p class="mtl-front-contact">Questions? Email '
		. '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>'
		. '</p>';
}

/**
 * Shared standalone HTML shell for the front-end pages, themed from the same
 * appearance settings as the admin pages.
 *
 * @param string $title       Page title.
 * @param string $body_html   Fills the centered main area. Built internally
 *                             from escaped pieces, never from raw user input.
 * @param string $footer_html Fills the discreet footer link row at the bottom.
 *                             The public contact line is appended below it
 *                             automatically, so callers never pass it in;
 *                             that is what puts the address on every one of
 *                             these pages from this single place.
 * @return void Outputs the page directly and exits.
 */
function mtl_render_front_shell( $title, $body_html, $footer_html = '' ) {
	$org_name = get_option( 'mtl_org_name', '' );
	if ( '' === $org_name ) {
		$org_name = 'My Tool Library';
	}
	$logo_url = get_option( 'mtl_logo_url', '' );

	// Resolved once here so the footer markup below stays a plain echo.
	$contact_html = mtl_contact_line_html();

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
				background: <?php echo mtl_css_value( $bg, '#ffffff' ); ?>;
				color: <?php echo mtl_css_value( $b_color, '#096491' ); ?>;
				font-family: <?php echo mtl_css_value( $b_font ); ?>;
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
				color: <?php echo mtl_css_value( $h_color, '#ff6600' ); ?>;
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
				color: <?php echo mtl_css_value( $l_color, '#00b3ff' ); ?>;
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

			/* Contact line: its own row under the footer links. Kept in the
				footer's quiet grey, but underlined, since it is the one thing down
				here a member may actively need, and it should not read as
				body text. */
			.mtl-front-contact {
				margin: 10px 0 0 0;
				color: #8c8f94;
			}

			.mtl-front-contact a {
				margin: 0;
				text-decoration: underline;
			}

			/* Status banner (e.g. a failed sign-in). Same colors as the member
				pages' .mtl-front-notice, but sized for the narrower card:
				full width, no auto-centering. */
			.mtl-front-card .mtl-front-notice {
				margin: 0 0 16px 0;
				padding: 12px 16px;
				border-radius: 6px;
				font-size: 0.95em;
				text-align: left;
			}

			.mtl-front-card .mtl-front-notice-success {
				background: #edf7ed;
				border: 1px solid #b6dcb6;
				color: #1e5b25;
			}

			.mtl-front-card .mtl-front-notice-error {
				background: #fcf0f1;
				border: 1px solid #f0c0c4;
				color: #8a1f28;
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
				background: <?php echo mtl_css_value( $h_color, '#ff6600' ); ?>;
				border: 1px solid <?php echo mtl_css_value( $h_color, '#ff6600' ); ?>;
				color: #fff;
				padding: calc(9px * <?php echo mtl_css_value( $btn_scale, '1' ); ?>) calc(22px * <?php echo mtl_css_value( $btn_scale, '1' ); ?>);
				border-radius: <?php echo mtl_css_value( $radius, '4px' ); ?>;
				font-size: calc(1em * <?php echo mtl_css_value( $btn_scale, '1' ); ?>);
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
		<?php if ( '' !== $footer_html || '' !== $contact_html ) : ?>
			<footer class="mtl-front-footer">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built internally from esc_*()-wrapped pieces, never from raw user input (see docblock).
				echo $footer_html;
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url()/esc_html()-wrapped in mtl_contact_line_html().
				echo $contact_html;
				?>
			</footer>
		<?php endif; ?>
	</body>

	</html>
	<?php
	exit;
}

/**
 * The public main page: the customer-facing shopping catalog, with a
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

	// Tag this form so a failed attempt can be told apart from one made at
	// /wp-login.php directly; see mtl_handle_failed_front_login(). Added and
	// removed around the one call so the filter never affects another plugin's
	// login form elsewhere on the site.
	add_filter( 'login_form_bottom', 'mtl_front_login_marker' );
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
	remove_filter( 'login_form_bottom', 'mtl_front_login_marker' );

	// Stops an empty submit from ever leaving the page. The server-side guard
	// in mtl_block_empty_front_login() still covers a browser that ignores it.
	$login_form = mtl_require_login_fields( $login_form );

	$body  = '<div class="mtl-front-card">';
	$body .= '<h2 style="margin-top: 0;">Sign In</h2>';
	$body .= mtl_front_notice_html();
	$body .= '<p style="font-size: 0.9em; color: #666;">New members can <a href="' . esc_url( mtl_front_page_url( 'signup' ) ) . '">create an account</a>.</p>';
	$body .= $login_form;
	$body .= '</div>';

	$footer  = '<a href="' . esc_url( mtl_front_page_url( 'main' ) ) . '">&larr; Back to the catalog</a>';
	$footer .= '<a href="' . esc_url( mtl_front_page_url( 'signup' ) ) . '">Create an Account</a>';
	$footer .= '<a href="' . esc_url( mtl_front_page_url( 'lostpassword' ) ) . '">Lost your password?</a>';

	mtl_render_front_shell( 'Sign In', $body, $footer );
}

// ==========================================================================
// PASSWORD RESET
//
// Branded equivalents of wp-login.php?action=lostpassword and ?action=rp, so
// a member who forgets their password never leaves the plugin's own pages.
//
// The security-relevant work is all core's: get_password_reset_key() and the
// email are produced by retrieve_password(), the key is verified by
// check_password_reset_key(), and the new password is written by
// reset_password(). Nothing here re-implements any of that; these two pages
// supply the branding and the copy, and hand off.
// ==========================================================================

/**
 * Name of the cookie holding "login:key" during a reset.
 *
 * Mirrors core's wp-resetpass-COOKIEHASH, under a plugin-specific name so the
 * two flows cannot interfere with each other if both are used on one site.
 *
 * @return string
 */
function mtl_reset_cookie_name() {
	return 'mtl-resetpass-' . COOKIEHASH;
}

/**
 * The path the reset cookie is scoped to.
 *
 * These pages live at the site root (?mtl_page=resetpass), so the cookie is
 * root-scoped too. Kept in one function so the set and the clear can never
 * disagree, and a mismatched path silently fails to delete the cookie.
 *
 * @return string
 */
function mtl_reset_cookie_path() {
	$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	return is_string( $path ) && '' !== $path ? $path : '/';
}

add_filter( 'lostpassword_url', 'mtl_lost_password_url', 10, 0 );

/**
 * Points every "Lost your password?" link at the branded page.
 *
 * @return string
 */
function mtl_lost_password_url() {
	return mtl_front_page_url( 'lostpassword' );
}

add_filter( 'retrieve_password_message', 'mtl_reset_email_message', 10, 3 );

/**
 * Rewrites the reset link in the email to the branded page.
 *
 * Core's retrieve_password() hardcodes a wp-login.php?action=rp URL into the
 * message body, so the only way to redirect members to this plugin's page is
 * to rewrite the message here. Only the URL is swapped, so core's surrounding
 * copy, including its "if this was a mistake, ignore this email" line, is left
 * exactly as it is.
 *
 * @param string $message    Default email body.
 * @param string $key        Password reset key.
 * @param string $user_login Username for the user.
 * @return string
 */
function mtl_reset_email_message( $message, $key, $user_login ) {
	// Replace the whole wp-login.php line, including core's appended &wp_lang.
	return preg_replace(
		'#^.*wp-login\.php\?login=.*$#m',
		mtl_reset_link_url( $key, $user_login ),
		$message
	);
}

/**
 * Builds the branded "choose a password" link for a reset key.
 *
 * Shared by the reset email above and the new-member setup email below so the
 * two cannot drift into producing different URL shapes for the same page.
 *
 * @param string $key        Password reset key from get_password_reset_key().
 * @param string $user_login The account's username.
 * @return string
 */
function mtl_reset_link_url( $key, $user_login ) {
	return add_query_arg(
		array(
			'login' => rawurlencode( $user_login ),
			'key'   => $key,
		),
		mtl_front_page_url( 'resetpass' )
	);
}

/**
 * Emails a member the link that lets them choose their first password.
 *
 * Sent for accounts created by staff rather than by the member, so the copy is
 * its own rather than core's. Core's reset email opens "Someone has requested a
 * password reset for the following account", which is both wrong and alarming
 * for somebody who never asked for anything and may not know the library set an
 * account up for them.
 *
 * @param int $user_id WP user ID to invite.
 * @return bool True if the mail was handed off successfully.
 */
function mtl_send_member_setup_email( $user_id ) {
	$user = get_userdata( (int) $user_id );
	if ( ! $user || '' === trim( (string) $user->user_email ) ) {
		return false;
	}

	// Note this invalidates any key already sent to this member, so a resend
	// silently kills the link in the earlier email, which is why the Membership
	// page says so before staff press the button.
	$key = get_password_reset_key( $user );
	if ( is_wp_error( $key ) ) {
		return false;
	}

	$org_name = get_option( 'mtl_org_name', '' );
	if ( '' === trim( (string) $org_name ) ) {
		$org_name = 'My Tool Library';
	}

	$greeting_name = trim( (string) $user->first_name );
	if ( '' === $greeting_name ) {
		$greeting_name = trim( (string) $user->display_name );
	}
	if ( '' === $greeting_name ) {
		$greeting_name = (string) $user->user_login;
	}

	$subject = sprintf( '[%s] Your account is ready: choose a password', $org_name );

	$lines = array(
		sprintf( 'Hi %s,', $greeting_name ),
		'',
		sprintf( 'Library staff have set up a %s account for you, so you can browse the catalog, reserve tools and see your loans online.', $org_name ),
		'',
		'To finish, choose a password here:',
		mtl_reset_link_url( $key, $user->user_login ),
		'',
		'That link is good for 24 hours. If it has expired by the time you use it, the page it opens will offer to send you a fresh one.',
		'',
		sprintf( 'Your sign-in address is %s.', $user->user_email ),
	);

	$contact_email = mtl_contact_email();
	if ( '' !== $contact_email ) {
		$lines[] = '';
		$lines[] = sprintf( 'If you were not expecting this, or anything looks wrong, contact library staff at %s.', $contact_email );
	}

	$lines[] = '';
	$lines[] = sprintf( '-- %s', $org_name );

	$sent = wp_mail( $user->user_email, $subject, implode( "\r\n", $lines ) );

	if ( $sent ) {
		// Read by the batch sender to skip anyone contacted recently, so a
		// second click cannot mail the whole roster twice.
		update_user_meta( $user_id, 'mtl_setup_invited_at', time() );
	}

	return (bool) $sent;
}

/**
 * Creates and invites the account for a member who has a library record but no
 * WordPress sign-in, given whatever they typed into the lost-password form.
 *
 * Does nothing at all unless the submitted text is an email address matching a
 * live member row that has no account, so an unrecognised address, a
 * username, an anonymized record, or a member who already has a sign-in all
 * fall straight through and leave the page's response unchanged.
 *
 * @param string $submitted Raw "email or username" field from the form.
 * @return void
 */
function mtl_setup_login_for_unclaimed_member( $submitted ) {
	global $wpdb;

	$email = sanitize_email( trim( (string) $submitted ) );
	if ( '' === $email || ! is_email( $email ) ) {
		return;
	}

	// If any account already owns this address there is nothing to repair;
	// retrieve_password() failing for some other reason is not our business.
	if ( email_exists( $email ) ) {
		return;
	}

	$tbl       = $wpdb->prefix . 'members';
	$member_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			"SELECT member_id FROM {$tbl} WHERE email = %s AND anonymized_at IS NULL LIMIT 1",
			$email
		)
	);

	if ( $member_id <= 0 ) {
		return;
	}

	$user_id = mtl_create_member_login( $member_id );
	if ( is_wp_error( $user_id ) ) {
		return;
	}

	mtl_send_member_setup_email( $user_id );
}

/**
 * "Forgot your password?" request page.
 *
 * Always reports the same thing whether or not the address matched an account.
 * Core's own screen distinguishes the two, which turns the form into a way to
 * test whether a given person is a member here; for a library holding home
 * addresses that is worth avoiding, at the cost of a typo'd address looking
 * like success until no email arrives.
 */
function mtl_render_lost_password_page() {
	if ( is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'account' ) );
		exit;
	}

	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'POST' === $request_method && isset( $_POST['mtl_lostpassword_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_lostpassword_nonce'] ) ), 'mtl_lostpassword_action' ) ) {
			$submitted = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';

			if ( '' === trim( $submitted ) ) {
				wp_safe_redirect( add_query_arg( 'mtl_msg', 'reset_empty', mtl_front_page_url( 'lostpassword' ) ) );
				exit;
			}

			// Return value deliberately ignored: a WP_Error here is usually
			// "no such account", and acting on it differently is exactly the
			// disclosure this page avoids.
			$reset = retrieve_password( $submitted );

			// A member staff added or imported has a library record but no
			// WordPress account, so the call above found nothing to send. Make
			// the account now and email them the same kind of link.
			//
			// This is what stops the whole feature depending on somebody
			// remembering to press "Create logins" after an import: a member who
			// gives up waiting and uses "Lost your password?" gets in anyway.
			//
			// It does mean an unauthenticated request can create an account, but
			// only ever for an address staff have already entered, and the only
			// thing it produces is mail to that member's own mailbox.
			if ( is_wp_error( $reset ) ) {
				mtl_setup_login_for_unclaimed_member( $submitted );
			}

			wp_safe_redirect( add_query_arg( 'mtl_msg', 'reset_sent', mtl_front_page_url( 'login' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'mtl_msg', 'reset_expired_form', mtl_front_page_url( 'lostpassword' ) ) );
		exit;
	}

	$body  = '<div class="mtl-front-card">';
	$body .= '<h2 style="margin-top: 0;">Reset Your Password</h2>';
	$body .= mtl_front_notice_html();
	$body .= '<p style="font-size: 0.9em; color: #666;">Enter the email address you signed up with and we&rsquo;ll send you a link to choose a new password.</p>';
	$body .= '<form method="post" action="' . esc_url( mtl_front_page_url( 'lostpassword' ) ) . '">';
	$body .= wp_nonce_field( 'mtl_lostpassword_action', 'mtl_lostpassword_nonce', true, false );
	$body .= '<p><label for="mtl_user_login">Email Address</label>';
	$body .= '<input type="email" name="user_login" id="mtl_user_login" class="input" autocomplete="username" required></p>';
	$body .= '<p><input type="submit" value="Email me a reset link"></p>';
	$body .= '</form>';
	$body .= '</div>';

	$footer  = '<a href="' . esc_url( mtl_front_page_url( 'login' ) ) . '">&larr; Back to sign in</a>';
	$footer .= '<a href="' . esc_url( mtl_front_page_url( 'signup' ) ) . '">Create an Account</a>';

	mtl_render_front_shell( 'Reset Your Password', $body, $footer );
}

// --------------------------------------------------------------------------
// FIRST-PASSWORD SETUP vs. PASSWORD CHANGE
//
// Four things listen on after_password_reset and the order between them is
// load-bearing. In the order they run:
//
// Priority 1, mtl_suppress_setup_notifications(): takes core's admin notice off
// the hook when this is a first-time setup.
// Priority 10, mtl_send_password_changed_email(): skips, for the same reason.
// Priority 10, wp_password_change_notification(): core's own; already removed.
// Priority 99, mtl_finish_password_setup(): clears the flag the other two read,
// and puts core's notice back.
//
// The clear MUST stay last. Move it earlier and both suppressions silently stop
// working, while the happy path still looks perfect: the member sets a password
// and gets in, they are just also told their password "has been changed" and the
// administrator is emailed about it.
// --------------------------------------------------------------------------

/**
 * Whether this account was created for a member who has never chosen a
 * password, i.e. the next reset is a first-time setup, not a change.
 *
 * @param int $user_id WP user ID.
 * @return bool
 */
function mtl_is_setup_pending( $user_id ) {
	return '' !== (string) get_user_meta( (int) $user_id, 'mtl_setup_pending', true );
}

add_action( 'after_password_reset', 'mtl_suppress_setup_notifications', 1, 1 );

/**
 * Silences core's "password changed" notice to the administrator when a member
 * is setting their first password.
 *
 * Core hooks wp_password_change_notification() to this same action and mails
 * the site administrator every time. That is reasonable for a real change, but
 * inviting a 500-member roster would put 500 of them in the administrator's
 * inbox in one afternoon. Removed here and restored at priority 99, so the
 * behaviour is unchanged for every other reset.
 *
 * @param WP_User $user The user whose password was just reset.
 * @return void
 */
function mtl_suppress_setup_notifications( $user ) {
	if ( ! $user instanceof WP_User || ! mtl_is_setup_pending( $user->ID ) ) {
		return;
	}

	// remove_action() reports whether anything was actually removed. Recording
	// that is what stops the restore below from ADDING core's notification to a
	// site where another plugin had deliberately taken it off, since putting it back
	// would be us switching on behaviour the site owner turned off.
	if ( remove_action( 'after_password_reset', 'wp_password_change_notification' ) ) {
		mtl_password_notification_suppressed( true );
	}
}

/**
 * Tracks whether this request removed core's password-change notification, so
 * only a removal we made is ever undone.
 *
 * @param bool|null $set New value, or null to just read.
 * @return bool
 */
function mtl_password_notification_suppressed( $set = null ) {
	static $suppressed = false;
	if ( null !== $set ) {
		$suppressed = (bool) $set;
	}
	return $suppressed;
}

add_action( 'after_password_reset', 'mtl_finish_password_setup', 99, 1 );

/**
 * Marks a first-time setup as done, and undoes the suppression above.
 *
 * Priority 99 so it runs after everything that reads mtl_setup_pending. The
 * flag is what makes a member count as "still needs to set a password", so
 * clearing it here is also what removes them from the Membership page's
 * outstanding list.
 *
 * @param WP_User $user The user whose password was just reset.
 * @return void
 */
function mtl_finish_password_setup( $user ) {
	if ( ! $user instanceof WP_User ) {
		return;
	}

	if ( mtl_is_setup_pending( $user->ID ) ) {
		delete_user_meta( $user->ID, 'mtl_setup_pending' );
		delete_user_meta( $user->ID, 'mtl_setup_invited_at' );
	}

	// Put core's notice back for anything else resetting later in this same
	// request, so the removal above can never leak beyond the one user it was
	// meant for. Only ever restores a removal we made ourselves.
	if ( mtl_password_notification_suppressed() ) {
		add_action( 'after_password_reset', 'wp_password_change_notification' );
		mtl_password_notification_suppressed( false );
	}
}

add_action( 'wp_login', 'mtl_clear_setup_pending_on_login', 10, 2 );

/**
 * Clears the pending flag for a member who got a password some other way:
 * staff setting one from Users > Edit User, or WP-CLI. Neither fires
 * after_password_reset, so without this they would sit on the outstanding list
 * forever and collect invitations they do not need.
 *
 * Guarded by the meta check so a normal sign-in is a cached read, not a write.
 *
 * @param string  $user_login The username (unused).
 * @param WP_User $user       The user signing in.
 * @return void
 */
function mtl_clear_setup_pending_on_login( $user_login, $user ) {
	if ( ! $user instanceof WP_User || ! mtl_is_setup_pending( $user->ID ) ) {
		return;
	}
	delete_user_meta( $user->ID, 'mtl_setup_pending' );
	delete_user_meta( $user->ID, 'mtl_setup_invited_at' );
}

add_action( 'after_password_reset', 'mtl_send_password_changed_email', 10, 1 );

/**
 * Emails the member to confirm their password was changed.
 *
 * WordPress does not send this. reset_password() writes the password with
 * wp_set_password() rather than wp_update_user(), so core's own
 * "Notice of Password Change" email never fires on the reset path, and the
 * only thing core does send here, wp_password_change_notification() on this
 * same action, goes to the site administrator, not the member.
 *
 * Its real job is the sentence at the end: if the member did not do this, the
 * email is how they find out someone else did, while there is still time to
 * tell staff.
 *
 * Hooked to after_password_reset rather than to the branded page, so a reset
 * completed any other way still produces the confirmation.
 *
 * Skipped entirely for a first-time setup: a member who has just chosen their
 * opening password did not have one changed, and telling them it was, for an
 * account they may not have known existed until the invitation arrived, reads
 * as exactly the compromise this email exists to warn about.
 *
 * @param WP_User $user The user whose password was just reset.
 * @return void
 */
function mtl_send_password_changed_email( $user ) {
	if ( ! $user instanceof WP_User || empty( $user->user_email ) ) {
		return;
	}

	if ( mtl_is_setup_pending( $user->ID ) ) {
		return;
	}

	$org_name = mtl_email_org_name();

	// A name if we have one, otherwise the sign-in address, never a bare
	// "Hi," which reads like the spam this email needs to be trusted over.
	$greeting_name = trim( (string) $user->display_name );
	if ( '' === $greeting_name ) {
		$greeting_name = $user->user_login;
	}

	// wp_date(), not gmdate(), so the timestamp is in the library's own
	// timezone, since "at 3:14 pm" is only useful if it matches the member's clock.
	$changed_at = wp_date( 'F j, Y \a\t g:i a' );

	$subject = sprintf( '[%s] Your password has been changed', $org_name );

	// This is the one email that asks the member to act urgently, so name the
	// address to write to rather than leaving "contact staff" as a dead end.
	// Falls back to the unaddressed wording when no contact email is set.
	$contact_email = mtl_contact_email();
	$alarm_line    = '' !== $contact_email
		? sprintf( 'If you did not make this change, please contact library staff at %s as soon as you can, because somebody else may have access to your account.', $contact_email )
		: 'If you did not make this change, please contact library staff as soon as you can, because somebody else may have access to your account.';

	$lines = array(
		sprintf( 'Hi %s,', $greeting_name ),
		'',
		sprintf( 'This is a confirmation that the password for your %s account was changed on %s.', $org_name, $changed_at ),
		'',
		'You can sign in with your new password here:',
		mtl_front_page_url( 'login' ),
		'',
		$alarm_line,
		'',
		sprintf( '-- %s', $org_name ),
	);

	// Return value ignored on purpose: the password has already been changed
	// by this point, and a mail failure must not be reported to the member as
	// though the reset itself had failed.
	wp_mail( $user->user_email, $subject, implode( "\r\n", $lines ) );
}

/**
 * Checks a submitted new password, returning '' when it is acceptable.
 *
 * Split out from the page so the rules can be exercised directly: this is the
 * one place a wrong answer would let somebody else's password be changed.
 *
 * @param string $expected_key Reset key from the cookie, already verified
 *                             against the account by check_password_reset_key().
 * @param string $posted_key   Reset key echoed back by the form.
 * @param string $pass1        New password.
 * @param string $pass2        Confirmation of the new password.
 * @return string Error message for display, or '' if the password is fine.
 */
function mtl_validate_new_password( $expected_key, $posted_key, $pass1, $pass2 ) {
	// The submitted key must match the cookie's, so a form left open in
	// another tab cannot be used to reset against a newer key.
	// hash_equals(), not ===, to keep the comparison constant-time.
	if ( ! hash_equals( (string) $expected_key, (string) $posted_key ) ) {
		return 'That reset link is no longer valid. Please request a new one.';
	}

	// Only-spaces is rejected, but a password containing spaces is fine, so
	// the value itself is never trimmed before being stored.
	if ( '' === trim( $pass1 ) ) {
		return 'Please enter a new password.';
	}

	if ( $pass1 !== $pass2 ) {
		return 'Those two passwords don&rsquo;t match. Please retype them.';
	}

	// Length is counted in bytes, matching how the password is stored, so a
	// short passphrase of multi-byte characters is not over-credited.
	if ( strlen( $pass1 ) < 8 ) {
		return 'Please choose a password of at least 8 characters.';
	}

	return '';
}

/**
 * "Choose a new password" page, reached from the emailed link.
 *
 * Follows core's cookie handoff: the key and login arrive as query args, are
 * moved into an HttpOnly cookie, and the URL is then reloaded without them.
 * That keeps the reset key out of browser history, out of bookmarks, and out
 * of the Referer header sent to anything the page later loads.
 */
function mtl_render_reset_password_page() {
	if ( is_user_logged_in() ) {
		wp_safe_redirect( mtl_front_page_url( 'account' ) );
		exit;
	}

	$cookie = mtl_reset_cookie_name();
	$path   = mtl_reset_cookie_path();
	$self   = mtl_front_page_url( 'resetpass' );

	// Step 1: arriving from the email. Stash the credentials and drop them
	// from the address bar.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a GET arriving from an emailed link cannot carry a nonce; the reset key itself is the credential and is verified below by check_password_reset_key().
	if ( isset( $_GET['key'] ) && isset( $_GET['login'] ) ) {
		$value = sprintf(
			'%s:%s',
			sanitize_text_field( wp_unslash( $_GET['login'] ) ),
			sanitize_text_field( wp_unslash( $_GET['key'] ) )
		);
		setcookie( $cookie, $value, 0, $path, COOKIE_DOMAIN, is_ssl(), true );
		wp_safe_redirect( $self );
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// Step 2: read them back and let core decide whether they are still valid.
	// $rp_key is initialised here as well as assigned below: every path that
	// leaves it blank also leaves $user falsy and redirects away, but an empty
	// default means a future edit to that guard cannot turn into an undefined
	// variable feeding hash_equals().
	$user   = false;
	$rp_key = '';
	if ( isset( $_COOKIE[ $cookie ] ) && is_string( $_COOKIE[ $cookie ] ) ) {
		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) );
		if ( 0 < strpos( $raw, ':' ) ) {
			list( $rp_login, $rp_key ) = explode( ':', $raw, 2 );
			$user                      = check_password_reset_key( $rp_key, $rp_login );
		}
	}

	if ( ! $user || is_wp_error( $user ) ) {
		$expired = ( $user instanceof WP_Error && 'expired_key' === $user->get_error_code() );
		setcookie( $cookie, ' ', time() - YEAR_IN_SECONDS, $path, COOKIE_DOMAIN, is_ssl(), true );
		wp_safe_redirect(
			add_query_arg(
				'mtl_msg',
				$expired ? 'reset_expired' : 'reset_invalid',
				mtl_front_page_url( 'lostpassword' )
			)
		);
		exit;
	}

	// Step 3: handle the new password.
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	$error          = '';

	if ( 'POST' === $request_method && isset( $_POST['mtl_resetpass_nonce'] ) ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_resetpass_nonce'] ) ), 'mtl_resetpass_action' ) ) {
			$error = 'This page had been open too long to be safe to use. Please try again.';
		} else {
			$posted_key = isset( $_POST['rp_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rp_key'] ) ) : '';

			// Passwords are read RAW, on purpose. sanitize_text_field() strips
			// tags and collapses whitespace, so it would silently mangle a
			// perfectly good password, because the member would set one thing and be
			// unable to sign in with it. wp_unslash() undoes WordPress's magic
			// quotes and nothing else; the value is never echoed, and
			// reset_password() hashes it rather than storing it. Core reads
			// $_POST['pass1'] the same way in wp-login.php.
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above; sanitizing a password would corrupt it.
			$pass1 = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
			$pass2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : '';
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$error = mtl_validate_new_password( (string) $rp_key, $posted_key, $pass1, $pass2 );

			if ( '' === $error ) {
				reset_password( $user, $pass1 );
				setcookie( $cookie, ' ', time() - YEAR_IN_SECONDS, $path, COOKIE_DOMAIN, is_ssl(), true );
				wp_safe_redirect( add_query_arg( 'mtl_msg', 'reset_done', mtl_front_page_url( 'login' ) ) );
				exit;
			}
		}
	}

	$body  = '<div class="mtl-front-card">';
	$body .= '<h2 style="margin-top: 0;">Choose a New Password</h2>';
	if ( '' !== $error ) {
		$body .= '<div class="mtl-front-notice mtl-front-notice-error">' . $error . '</div>';
	}
	$body .= '<p style="font-size: 0.9em; color: #666;">Setting a new password for <strong>' . esc_html( $user->user_email ) . '</strong>.</p>';
	$body .= '<form method="post" action="' . esc_url( $self ) . '">';
	$body .= wp_nonce_field( 'mtl_resetpass_action', 'mtl_resetpass_nonce', true, false );
	$body .= '<input type="hidden" name="rp_key" value="' . esc_attr( $rp_key ) . '">';
	$body .= '<p><label for="mtl_pass1">New Password</label>';
	$body .= '<input type="password" name="pass1" id="mtl_pass1" class="input" autocomplete="new-password" minlength="8" required></p>';
	$body .= '<p><label for="mtl_pass2">Confirm New Password</label>';
	$body .= '<input type="password" name="pass2" id="mtl_pass2" class="input" autocomplete="new-password" minlength="8" required></p>';
	$body .= '<p><input type="submit" value="Save my new password"></p>';
	$body .= '</form>';
	$body .= '</div>';

	$footer = '<a href="' . esc_url( mtl_front_page_url( 'login' ) ) . '">&larr; Back to sign in</a>';

	mtl_render_front_shell( 'Choose a New Password', $body, $footer );
}

/**
 * Marks the branded sign-in form so a failed attempt can be traced back to it.
 *
 * Core's wp_login_form() posts to /wp-login.php, and on failure WordPress
 * renders its own unbranded page there. The hidden field below is what tells
 * mtl_handle_failed_front_login() that the attempt started on the plugin's
 * page and should be sent back to it.
 *
 * @param string $content Existing markup appended to the end of the form.
 * @return string
 */
function mtl_front_login_marker( $content ) {
	return $content . '<input type="hidden" name="mtl_front_login" value="1" />';
}

/**
 * Adds the required attribute to core's sign-in inputs.
 *
 * First line of defence for an empty submit: the browser refuses to send the
 * form at all, so nothing reaches /wp-login.php and there is no round trip.
 * wp_login_form() has no argument for this, hence patching its returned markup.
 *
 * Purely an enhancement. If core ever changes these attributes the replacement
 * simply does not match and the form still works; mtl_block_empty_front_login()
 * is what actually guarantees the behaviour.
 *
 * @param string $form Markup returned by wp_login_form().
 * @return string
 */
function mtl_require_login_fields( $form ) {
	return str_replace(
		array( 'name="log"', 'name="pwd"' ),
		array( 'name="log" required', 'name="pwd" required' ),
		$form
	);
}

// Priority 100, deliberately late: security plugins commonly count failed
// attempts on this same hook, and this handler ends the request. Running last
// means their counters still see the event before the redirect happens.
add_action( 'wp_login_failed', 'mtl_handle_failed_front_login', 100, 2 );

// Empty credentials never reach the action above. wp_authenticate() keeps an
// $ignore_codes list (empty_username and empty_password) and skips firing
// wp_login_failed for them (wp-includes/pluggable.php), on the reasoning that a
// blank form is not a real login attempt worth logging. The result is that
// clicking Sign In with both boxes empty fell straight through to wp-login.php.
// The authenticate filter runs earlier and does see them.
//
// Priority 50: after core's own authenticators (20-30) have had their say, so
// $user is already resolved, and before wp_authenticate_spam_check at 99.
add_filter( 'authenticate', 'mtl_block_empty_front_login', 50 );

/**
 * Catches the empty-credentials case that never reaches wp_login_failed.
 *
 * Deliberately narrow: it handles only the two error codes core excludes from
 * that action and returns everything else untouched, so genuine failures still
 * travel the normal path and remain visible to any plugin counting them.
 *
 * @param null|WP_User|WP_Error $user Result so far from the authenticate chain.
 * @return null|WP_User|WP_Error
 */
function mtl_block_empty_front_login( $user ) {
	if ( ! is_wp_error( $user ) ) {
		return $user;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the sign-in POST carries no nonce by design; this flag only selects a redirect target and changes no state.
	if ( empty( $_POST['mtl_front_login'] ) ) {
		return $user;
	}

	$code = $user->get_error_code();
	if ( 'empty_username' !== $code && 'empty_password' !== $code ) {
		return $user;
	}

	wp_safe_redirect( add_query_arg( 'mtl_msg', 'login_empty', mtl_front_page_url( 'login' ) ) );
	exit;
}

/**
 * Keeps a failed sign-in on the branded page instead of dumping the member on
 * /wp-login.php, where there is no catalog, no styling and no way to create an
 * account.
 *
 * Only acts on attempts that came from this plugin's own form: a failed login
 * at /wp-login.php (an admin signing in the usual way, or another plugin's
 * form) is left completely alone.
 *
 * @param string        $username Submitted username. Unused, deliberately
 *                                not echoed back through the URL, which would
 *                                put a member's email address into browser
 *                                history, server logs and referer headers.
 * @param WP_Error|null $error    The authentication failure, when core passes
 *                                one (WordPress 5.4+).
 */
function mtl_handle_failed_front_login( $username, $error = null ) {
	// No nonce here by design. wp-login.php's sign-in POST does not carry one.
	// Knowing the password IS the proof, so there is nothing to verify.
	// This flag is read only to decide which page to redirect to, and is never
	// trusted for anything else.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; no state is changed here.
	if ( empty( $_POST['mtl_front_login'] ) ) {
		return;
	}

	$code = ( $error instanceof WP_Error ) ? $error->get_error_code() : '';

	// A blank box is a different problem from a wrong password, and saying so
	// gives nothing away. Everything else collapses into one generic message.
	//
	// In practice the empty codes never arrive here, since core excludes them from
	// this action, which is why mtl_block_empty_front_login() exists and is
	// what actually handles them. Kept as a fallback so this stays correct if
	// core's $ignore_codes list ever changes.
	$msg = ( 'empty_username' === $code || 'empty_password' === $code )
		? 'login_empty'
		: 'login_failed';

	wp_safe_redirect( add_query_arg( 'mtl_msg', $msg, mtl_front_page_url( 'login' ) ) );
	exit;
}

/**
 * Post-login router. Library staff (Editors and Administrators) continue into
 * the admin portal; any other signed-in user (i.e. a member) is sent back to
 * the public catalog, where their reservation and account tools live. (This
 * gate is a courtesy router; the real enforcement is WordPress's own
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

	// Signed in, but not staff, so a member. Send them to the shop.
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
