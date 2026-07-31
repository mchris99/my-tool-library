<?php
// If this file is called directly (or the plugin was only deactivated, not
// deleted), WordPress never defines this constant -- bail out immediately.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Removes the plugin's own SETTINGS only (branding, appearance, and other
// Setup-page values). Deliberately does NOT touch the custom database
// tables (members, tool_inventory, loans, tool_reservations, etc.) or the
// mtl_member_id user meta linking a WP account to a member record -- that
// is operational/financial history, not a "setting," and an accidental or
// temporary plugin deletion should never silently destroy it. Use the
// Setup page's export feature to back up that data before removing tables
// manually.
$mtl_options = array(
	'mtl_accent_color',
	'mtl_background_color',
	'mtl_body_color',
	'mtl_body_font',
	'mtl_body_size',
	'mtl_body_weight',
	'mtl_border_radius',
	'mtl_button_scale',
	'mtl_contact_email',
	'mtl_currency_symbol',
	'mtl_default_loan_days',
	'mtl_header_color',
	'mtl_header_font',
	'mtl_header_size',
	'mtl_header_transform',
	'mtl_header_weight',
	'mtl_home_url',
	'mtl_link_color',
	'mtl_link_decoration',
	'mtl_link_font',
	'mtl_link_size',
	'mtl_logo_url',
	'mtl_org_name',
	'mtl_pickup_directions',
	'mtl_verification_directions',
);

foreach ( $mtl_options as $mtl_option ) {
	delete_option( $mtl_option );
}

// Per-admin dashboard panel layout preference -- a display setting, not
// business data, so (unlike mtl_member_id) it's cleaned up like the options
// above. $delete_all = true removes this meta key for every user, not just
// one, without needing to know their individual user IDs.
delete_metadata( 'user', 0, 'mtl_dashboard_layout', '', true );
