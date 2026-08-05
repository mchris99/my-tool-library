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

// ==========================================================================
// PHONE NUMBERS
//
// Every phone number in this plugin is collected as two pieces -- a country
// (an ISO 3166-1 alpha-2 code, picked from a <select>) and a national number
// (free-typed digits) -- and stored as ONE canonical string:
// "+<calling code> <national number, grouped for readability>", e.g.
// "+1 (414) 555-0123" or "+44 20 7946 0958". There is no separate "country"
// column; the stored string is self-describing, and every existing display
// site in the plugin already just echoes phone_number as-is, so storing it
// pre-formatted means no display code anywhere needs to change.
//
// This is deliberately NOT a full international validator (that is what
// libphonenumber exists for, and this plugin takes no third-party
// dependencies -- see readme.txt's FAQ). NANP numbers (country code 1: the
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
 * set and order as mtl_get_country_options() -- United States pinned first,
 * everything else alphabetical -- so the two dropdowns read consistently,
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
 * rather than '' -- there is no blank option in the phone country <select>
 * (it always has a real selection, U.S. pinned first/default), so an invalid
 * or tampered value should behave exactly like nothing was selected at all.
 *
 * @param string $value Posted ISO code.
 * @return string A valid ISO code -- $value if it was one, else 'US'.
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
 * Add/Edit Member, CSV import) -- the client-side live formatter
 * (mtl_phone_formatter_script()) is cosmetic only; this is what actually
 * gets validated and stored, regardless of what the browser sent.
 *
 * @param string $iso          Country ISO code from the phone <select> (see
 *                              mtl_get_phone_country_options()); coerced to
 *                              "US" if invalid via mtl_valid_phone_country().
 * @param string $national_raw Raw national-number text -- any punctuation,
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
		// block comment) -- just a loose sanity range. 4 is short enough to
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
 * digits) for redisplaying in the two-part editor -- Edit Member's prefill,
 * and CSV bulk import's per-row parsing (a CSV cell is just another external
 * representation of the same "maybe has a country prefix, maybe doesn't"
 * text this function already has to handle).
 *
 * A value with no leading "+" is read as a legacy NANP number -- every
 * phone_number in this plugin was stored as plain NANP text before this
 * feature existed, with no country code at all.
 *
 * Ambiguity note: several countries share calling code 1 (US, Canada, and
 * NANP Caribbean nations). A stored "+1 ..." value can't be traced back to
 * which of them was originally selected, so this always resolves a shared
 * code to whichever of those countries is listed first in
 * mtl_get_phone_country_options() (the United States). That only affects
 * which country name is pre-selected on re-edit -- formatting and storage
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
 * number text input) shared by every place a phone number is collected --
 * Signup, My Account, and the admin Add/Edit Member form -- so the option
 * list, markup, and JS hook (.mtl-phone-widget) can never drift between
 * them. Echoes directly (matches mtl_render_member_form_fields()'s own
 * style); every value is escaped inline, so no customEscapingFunctions entry
 * is needed.
 *
 * Both inputs are named phone_country / phone_national in every caller.
 * That's safe even when two instances of this widget are on the same PAGE at
 * once (the admin Membership page's Add and Edit forms both call this) --
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
 * (there can be more than one -- e.g. the admin Membership page's Add and
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
// MEMBER TRAININGS
//
// A training (member_trainings) has a name, an optional badge image, and an
// optional certification_length_months. A member holds a training via
// member_training_mappings, which records the start_date they completed it.
//
// Expiry is always DERIVED from those two, never stored -- see
// mtl_training_expiry_date(). That means an admin editing a training's
// certification length on the Setup page instantly re-dates every member who
// holds it, with no backfill step and no stale copies to go wrong.
//
// "Current" vs "expired" matters in three different places, and they
// deliberately behave differently:
// - My Account badge images: current trainings only.
// - My Account trainings table: everything, current and expired, with the
// status spelled out.
// - Membership filter: current only, since the question staff are asking
// is "who is qualified to use this tool today".
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
	// deliberately -- the drift is at most a few days, it always lands in the
	// member's favour (certification lasts slightly longer, never cut short),
	// and hand-rolled clamping is more date arithmetic to get wrong than the
	// problem is worth for a tool-library certification.
	return gmdate( 'Y-m-d', strtotime( '+' . $months . ' months', $ts ) );
}

/**
 * Whether a member's training certification is still current today.
 *
 * A training with no certification length never expires and is always
 * current. Expiry day itself still counts as current -- the certification
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
 * Renders the trainings picker used by the admin Add/Edit Member forms: one
 * checkbox per training, each with its own start-date input that only
 * matters when the box is ticked.
 *
 * This replaced a plain <select multiple>, which could record WHICH
 * trainings a member held but had nowhere to put the date each was
 * completed on -- and without a date there is nothing to expire.
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
 * Emitted once per page (the admin Membership page has two pickers -- Add
 * and Edit -- in the DOM at once), same pairing as
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
 * Kept in one function because it has to be identical in two places -- the
 * Setup page textarea's starting value and the member-facing fallback -- and
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
 * rejected -- admins paste addresses without the scheme constantly, and
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
	// exists at all -- a bare "Give Now" button with no explanation would be
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
 * Recomputes ready_since for one tool's active reservations.
 *
 * Only the front of the queue can be ready, and only while the tool is not
 * out on loan. This clears ready_since on everyone else and stamps the front
 * reservation the first time it becomes collectable -- an already-stamped
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

	// Front of the queue: earliest reservation, ties broken by id -- the same
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
 * Runs on init -- i.e. whenever anyone loads any page, admin or public --
 * rather than relying on WP-Cron alone. WP-Cron is triggered by traffic, not
 * by the clock, so on a quiet library site a nightly job would not actually
 * fire overnight; this way the data is correct the moment anybody looks at
 * it, on any host, with no server configuration. The daily cron event
 * registered below is a supplement that keeps the timestamps tidy on sites
 * that do get overnight traffic.
 *
 * Guarded to run at most once per request. It is a single UPDATE against an
 * indexed column, plus a readiness re-sync for each tool it touched -- when
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
		// "Never expires" -- the library holds reservations indefinitely.
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
 * "daily" only on a site that gets visited -- and on such a site the init
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

	// Captured before the cancel, since afterwards these rows no longer match
	// -- each of those tools needs the next member in line promoting.
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

/**
 * Whether the current user may delete a tool from inventory. Administrators
 * only, matching mtl_can_delete_members(): it is irreversible and drops the
 * record of an asset the library owns.
 *
 * Editors keep every other tool action, including Retire -- which is the
 * reversible way to take something out of circulation, and the right answer
 * for almost every case Delete used to be reached for.
 *
 * Deleting a tool that has loan or reservation history is separately blocked
 * by a foreign key, and that is unchanged by this check (see the delete
 * handler in admin/inventory-page.php).
 *
 * @return bool
 */
function mtl_can_delete_tools() {
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

	// Tag this form so a failed attempt can be told apart from one made at
	// /wp-login.php directly -- see mtl_handle_failed_front_login(). Added and
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
// reset_password(). Nothing here re-implements any of that -- these two pages
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
 * disagree -- a mismatched path silently fails to delete the cookie.
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
 * to rewrite the message here. Only the URL is swapped -- core's surrounding
 * copy, including its "if this was a mistake, ignore this email" line, is left
 * exactly as it is.
 *
 * @param string $message    Default email body.
 * @param string $key        Password reset key.
 * @param string $user_login Username for the user.
 * @return string
 */
function mtl_reset_email_message( $message, $key, $user_login ) {
	$branded = add_query_arg(
		array(
			'login' => rawurlencode( $user_login ),
			'key'   => $key,
		),
		mtl_front_page_url( 'resetpass' )
	);

	// Replace the whole wp-login.php line, including core's appended &wp_lang.
	return preg_replace(
		'#^.*wp-login\.php\?login=.*$#m',
		$branded,
		$message
	);
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
			retrieve_password( $submitted );

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

add_action( 'after_password_reset', 'mtl_send_password_changed_email', 10, 1 );

/**
 * Emails the member to confirm their password was changed.
 *
 * WordPress does not send this. reset_password() writes the password with
 * wp_set_password() rather than wp_update_user(), so core's own
 * "Notice of Password Change" email never fires on the reset path, and the
 * only thing core does send here -- wp_password_change_notification() on this
 * same action -- goes to the site administrator, not the member.
 *
 * Its real job is the sentence at the end: if the member did not do this, the
 * email is how they find out someone else did, while there is still time to
 * tell staff.
 *
 * Hooked to after_password_reset rather than to the branded page, so a reset
 * completed any other way still produces the confirmation.
 *
 * @param WP_User $user The user whose password was just reset.
 * @return void
 */
function mtl_send_password_changed_email( $user ) {
	if ( ! $user instanceof WP_User || empty( $user->user_email ) ) {
		return;
	}

	$org_name = trim( (string) get_option( 'mtl_org_name', '' ) );
	if ( '' === $org_name ) {
		$org_name = get_bloginfo( 'name' );
	}
	if ( '' === trim( (string) $org_name ) ) {
		$org_name = 'My Tool Library';
	}

	// A name if we have one, otherwise the sign-in address -- never a bare
	// "Hi," which reads like the spam this email needs to be trusted over.
	$greeting_name = trim( (string) $user->display_name );
	if ( '' === $greeting_name ) {
		$greeting_name = $user->user_login;
	}

	// wp_date(), not gmdate(), so the timestamp is in the library's own
	// timezone -- "at 3:14 pm" is only useful if it matches the member's clock.
	$changed_at = wp_date( 'F j, Y \a\t g:i a' );

	$subject = sprintf( '[%s] Your password has been changed', $org_name );

	$lines = array(
		sprintf( 'Hi %s,', $greeting_name ),
		'',
		sprintf( 'This is a confirmation that the password for your %s account was changed on %s.', $org_name, $changed_at ),
		'',
		'You can sign in with your new password here:',
		mtl_front_page_url( 'login' ),
		'',
		'If you did not make this change, please contact library staff as soon as you can -- somebody else may have access to your account.',
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
			// perfectly good password -- the member would set one thing and be
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
 * simply does not match and the form still works -- mtl_block_empty_front_login()
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
// $ignore_codes list -- empty_username and empty_password -- and skips firing
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
 * @param string        $username Submitted username. Unused -- deliberately
 *                                not echoed back through the URL, which would
 *                                put a member's email address into browser
 *                                history, server logs and referer headers.
 * @param WP_Error|null $error    The authentication failure, when core passes
 *                                one (WordPress 5.4+).
 */
function mtl_handle_failed_front_login( $username, $error = null ) {
	// No nonce here by design. wp-login.php's sign-in POST does not carry one
	// -- knowing the password IS the proof -- so there is nothing to verify.
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
	// In practice the empty codes never arrive here -- core excludes them from
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
