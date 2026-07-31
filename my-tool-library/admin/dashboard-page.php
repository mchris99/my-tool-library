<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of every dashboard panel, in default order, with its default size.
 *
 * The saved per-user layout (user_meta 'mtl_dashboard_layout') is validated
 * against this whitelist -- unknown panel ids in saved/posted data are
 * discarded, so a tampered layout JSON can never inject markup or resurrect
 * removed panels. Sizes map to grid spans: small = 1/3 row, medium = 1/2 row,
 * large = full row.
 *
 * @return array<string, array{title: string, size: string}>
 */
function mtl_dashboard_panels() {
	return array(
		'stat_members'   => array(
			'title' => 'Current Membership',
			'size'  => 'small',
		),
		'stat_borrowed'  => array(
			'title' => 'Tools On Loan',
			'size'  => 'small',
		),
		'stat_avg_age'   => array(
			'title' => 'Average Tool Age',
			'size'  => 'small',
		),
		'overdue'        => array(
			'title' => 'Overdue Tools',
			'size'  => 'medium',
		),
		'reservations'   => array(
			'title' => 'Upcoming Reservations',
			'size'  => 'medium',
		),
		'popular'        => array(
			'title' => 'Most Popular Tools',
			'size'  => 'medium',
		),
		'unpopular'      => array(
			'title' => 'Least Popular Tools',
			'size'  => 'medium',
		),
		'value_initial'  => array(
			'title' => 'Asset Value (Initial)',
			'size'  => 'small',
		),
		'value_current'  => array(
			'title' => 'Asset Value (Depreciated)',
			'size'  => 'small',
		),
		'depreciation'   => array(
			'title' => 'Depreciation Overview',
			'size'  => 'small',
		),
		'areas'          => array(
			'title' => 'Most Popular Member Areas',
			'size'  => 'medium',
		),
		'renters'        => array(
			'title' => 'Member Rental Leaderboard',
			'size'  => 'medium',
		),
		'donors'         => array(
			'title' => 'Donor Leaderboard',
			'size'  => 'medium',
		),
		'tool_history'   => array(
			'title' => 'Tool History Lookup',
			'size'  => 'large',
		),
		'member_history' => array(
			'title' => 'Member History Lookup',
			'size'  => 'large',
		),
	);
}

/**
 * Builds the layout to render: registry defaults overlaid with the current
 * admin's saved preferences, each field re-validated on read so a bad stored
 * value can't break rendering.
 *
 * @return array<string, array{order: int, size: string, visible: bool}> Panel id => prefs, sorted by order.
 */
function mtl_get_dashboard_layout() {
	$panels = mtl_dashboard_panels();
	$layout = array();
	$order  = 0;
	foreach ( $panels as $id => $def ) {
		$layout[ $id ] = array(
			'order'   => $order++,
			'size'    => $def['size'],
			'visible' => true,
		);
	}

	$saved = get_user_meta( get_current_user_id(), 'mtl_dashboard_layout', true );
	if ( is_array( $saved ) ) {
		foreach ( $saved as $id => $prefs ) {
			if ( ! isset( $layout[ $id ] ) || ! is_array( $prefs ) ) {
				continue;
			}
			if ( isset( $prefs['order'] ) ) {
				$layout[ $id ]['order'] = intval( $prefs['order'] );
			}
			if ( isset( $prefs['size'] ) && in_array( $prefs['size'], array( 'small', 'medium', 'large' ), true ) ) {
				$layout[ $id ]['size'] = $prefs['size'];
			}
			if ( isset( $prefs['visible'] ) ) {
				$layout[ $id ]['visible'] = (bool) $prefs['visible'];
			}
		}
	}

	uasort(
		$layout,
		function ( $a, $b ) {
			return $a['order'] <=> $b['order'];
		}
	);
	return $layout;
}

/**
 * Renders a two-segment SVG donut chart with a legend.
 *
 * Pure inline SVG, no chart library. Segment colors come from the theme's
 * CSS variables, so the Setup page's appearance settings restyle every chart
 * automatically.
 *
 * @return string HTML markup.
 */
function mtl_dash_donut( $seg_a_label, $seg_a_value, $seg_b_label, $seg_b_value, $center_label, $currency ) {
	$total = $seg_a_value + $seg_b_value;
	if ( $total <= 0 ) {
		return '<p class="mtl-empty">No data yet.</p>';
	}
	$pct_a = round( $seg_a_value / $total * 100, 1 );
	$pct_b = 100 - $pct_a;

	$out  = '<div class="mtl-donut">';
	$out .= '<svg viewBox="0 0 42 42" class="mtl-donut-svg" role="img" aria-label="' . esc_attr( $seg_a_label . ' ' . $pct_a . '%' ) . '">';
	$out .= '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="#e8ecef" stroke-width="4.5"></circle>';
	$out .= '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="var(--mtl-header-color, #ff6600)" stroke-width="4.5" stroke-dasharray="' . esc_attr( $pct_a . ' ' . ( 100 - $pct_a ) ) . '" stroke-dashoffset="25"></circle>';
	$out .= '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="var(--mtl-link-color, #00b3ff)" stroke-width="4.5" stroke-dasharray="' . esc_attr( $pct_b . ' ' . ( 100 - $pct_b ) ) . '" stroke-dashoffset="' . esc_attr( 25 - $pct_a ) . '"></circle>';
	$out .= '<text x="21" y="20.2" class="mtl-donut-big">' . esc_html( round( $pct_a ) ) . '%</text>';
	$out .= '<text x="21" y="26" class="mtl-donut-small">' . esc_html( $center_label ) . '</text>';
	$out .= '</svg>';
	$out .= '<ul class="mtl-legend">';
	$out .= '<li><span class="mtl-dot" style="background: var(--mtl-header-color, #ff6600);"></span>' . esc_html( $seg_a_label ) . '<strong>' . esc_html( $currency . number_format( $seg_a_value, 2 ) ) . '</strong></li>';
	$out .= '<li><span class="mtl-dot" style="background: var(--mtl-link-color, #00b3ff);"></span>' . esc_html( $seg_b_label ) . '<strong>' . esc_html( $currency . number_format( $seg_b_value, 2 ) ) . '</strong></li>';
	$out .= '</ul>';
	$out .= '</div>';
	return $out;
}

/**
 * Renders a horizontal bar chart; bar widths are relative to the max value.
 *
 * @param array $items Array of [label, value, sublabel] tuples.
 * @return string HTML markup.
 */
function mtl_dash_bars( $items, $bar_color_var = '--mtl-header-color' ) {
	if ( empty( $items ) ) {
		return '<p class="mtl-empty">No data yet.</p>';
	}
	$max = 0;
	foreach ( $items as $item ) {
		$max = max( $max, $item[1] );
	}
	$max = max( $max, 1 );

	$out = '<div class="mtl-bars">';
	foreach ( $items as $item ) {
		$pct  = round( $item[1] / $max * 100 );
		$sub  = isset( $item[2] ) ? '<span class="mtl-bar-sub">' . esc_html( $item[2] ) . '</span>' : '';
		$out .= '<div class="mtl-bar-row">';
		$out .= '<div class="mtl-bar-label" title="' . esc_attr( $item[0] ) . '">' . esc_html( $item[0] ) . $sub . '</div>';
		$out .= '<div class="mtl-bar-track"><div class="mtl-bar-fill" style="width: ' . esc_attr( max( $pct, 2 ) ) . '%; background: var(' . esc_attr( $bar_color_var ) . ', #ff6600);"></div></div>';
		$out .= '<div class="mtl-bar-value">' . esc_html( $item[1] ) . '</div>';
		$out .= '</div>';
	}
	$out .= '</div>';
	return $out;
}

/**
 * Renders the Dashboard admin page.
 */
function mtl_render_dashboard_page() {
	global $wpdb;

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tbl_members       = $wpdb->prefix . 'members';
	$tbl_verifications = $wpdb->prefix . 'member_verifications';
	$tbl_inventory     = $wpdb->prefix . 'tool_inventory';
	$tbl_loans         = $wpdb->prefix . 'loans';
	$tbl_reservations  = $wpdb->prefix . 'tool_reservations';

	$base_url = menu_page_url( 'mtl-dashboard', false );
	$currency = get_option( 'mtl_currency_symbol', '$' );

	echo '<div class="wrap mtl-admin-wrapper">';
	echo '<h2>My Tool Library Dashboard</h2>';

	// ==========================================
	// 1. HANDLE "SAVE LAYOUT" / "RESET LAYOUT" SUBMISSIONS
	// ==========================================
	if ( isset( $_POST['mtl_save_dashboard_layout'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_dashboard_layout_nonce'] ) && wp_verify_nonce( $_POST['mtl_dashboard_layout_nonce'], 'mtl_dashboard_layout_action' ) ) {
			$raw     = isset( $_POST['mtl_dashboard_layout_json'] ) ? wp_unslash( $_POST['mtl_dashboard_layout_json'] ) : '';
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) ) {
				// Whitelist-sanitize every field before storing. Unknown panel
				// ids and invalid sizes are dropped entirely.
				$panels = mtl_dashboard_panels();
				$clean  = array();
				foreach ( $decoded as $id => $prefs ) {
					if ( ! isset( $panels[ $id ] ) || ! is_array( $prefs ) ) {
						continue;
					}
					$clean[ $id ] = array(
						'order'   => isset( $prefs['order'] ) ? intval( $prefs['order'] ) : 0,
						'size'    => ( isset( $prefs['size'] ) && in_array( $prefs['size'], array( 'small', 'medium', 'large' ), true ) ) ? $prefs['size'] : $panels[ $id ]['size'],
						'visible' => ! empty( $prefs['visible'] ),
					);
				}
				update_user_meta( get_current_user_id(), 'mtl_dashboard_layout', $clean );
				echo '<div class="notice notice-success is-dismissible"><p><strong>Saved.</strong> Your dashboard layout has been saved and will be restored on your next visit.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The layout could not be read. Please try again.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	if ( isset( $_POST['mtl_reset_dashboard_layout'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_dashboard_layout_nonce'] ) && wp_verify_nonce( $_POST['mtl_dashboard_layout_nonce'], 'mtl_dashboard_layout_action' ) ) {
			delete_user_meta( get_current_user_id(), 'mtl_dashboard_layout' );
			echo '<div class="notice notice-success is-dismissible"><p><strong>Reset.</strong> The dashboard layout is back to its defaults.</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// ==========================================
	// 2. DATE-RANGE FILTER (GET)
	// Applies to the popularity panels and the rental leaderboard. Values are
	// strictly validated as YYYY-MM-DD; anything else is treated as unset.
	// ==========================================
	$date_from = '';
	$date_to   = '';
	if ( isset( $_GET['mtl_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['mtl_from'] ) ) ) {
		$date_from = wp_unslash( $_GET['mtl_from'] );
	}
	if ( isset( $_GET['mtl_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET['mtl_to'] ) ) ) {
		$date_to = wp_unslash( $_GET['mtl_to'] );
	}

	// $date_from/$date_to themselves stay ISO below (SQL params + the
	// <input type="date"> value attributes require it); only this display
	// label is human-formatted.
	if ( $date_from !== '' && $date_to !== '' ) {
		$range_label = mtl_format_date( $date_from ) . ' to ' . mtl_format_date( $date_to );
	} elseif ( $date_from !== '' ) {
		$range_label = 'From ' . mtl_format_date( $date_from );
	} elseif ( $date_to !== '' ) {
		$range_label = 'Through ' . mtl_format_date( $date_to );
	} else {
		$range_label = 'All time';
	}

	// ==========================================
	// 3. GATHER DATA
	// ==========================================

	// --- Membership stats ---
	// Anonymized members are excluded from headcounts -- they've deleted
	// their account, so they're no longer a current member.
	$member_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_members} WHERE anonymized_at IS NULL" );
	// A row can exist with only one scan URL on file (a member with just one
	// form of ID so far) -- only count it here once BOTH are present.
	$verified_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_verifications} WHERE photo_id_scan_url IS NOT NULL AND address_proof_scan_url IS NOT NULL" );
	$new_members_90 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_members} WHERE anonymized_at IS NULL AND signup_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)" );

	// --- Inventory + active-loan flags, one row per tool ---
	// The LEFT JOIN keys on return_date IS NULL so l.loan_id is non-null only
	// when the tool is currently checked out. Depreciated value and age are
	// computed in PHP from these rows so one query feeds four panels.
	// Retired tools are excluded -- they're no longer active holdings, so
	// counting them would overstate current inventory/asset value.
	$inventory_rows = $wpdb->get_results(
		"
        SELECT t.tool_id, t.tool_name, t.barcode, t.brand, t.initial_cash_value,
               t.annual_depreciation_amount, t.date_acquired,
               l.loan_id AS active_loan_id
        FROM {$tbl_inventory} t
        LEFT JOIN {$tbl_loans} l ON l.tool_id = t.tool_id AND l.return_date IS NULL
        WHERE t.retired_at IS NULL
    "
	);

	$tool_count      = 0;
	$tools_out       = 0;
	$on_hand_initial = 0.0;
	$on_loan_initial = 0.0;
	$on_hand_current = 0.0;
	$on_loan_current = 0.0;
	$total_age_years = 0.0;
	$oldest_tool     = null;
	$newest_tool     = null;
	$seen_tool_ids   = array();

	foreach ( $inventory_rows as $row ) {
		// Guard against duplicate rows if bad data ever leaves a tool with
		// two open loans at once.
		if ( isset( $seen_tool_ids[ $row->tool_id ] ) ) {
			continue;
		}
		$seen_tool_ids[ $row->tool_id ] = true;

		++$tool_count;
		$age_years        = max( 0, ( time() - strtotime( $row->date_acquired ) ) / 31557600 );
		$total_age_years += $age_years;

		$initial = (float) $row->initial_cash_value;
		$current = max( 0, $initial - ( (float) $row->annual_depreciation_amount * $age_years ) );

		if ( $row->active_loan_id ) {
			++$tools_out;
			$on_loan_initial += $initial;
			$on_loan_current += $current;
		} else {
			$on_hand_initial += $initial;
			$on_hand_current += $current;
		}

		if ( $oldest_tool === null || $age_years > $oldest_tool['age'] ) {
			$oldest_tool = array(
				'name'    => $row->tool_name,
				'barcode' => $row->barcode,
				'age'     => $age_years,
			);
		}
		if ( $newest_tool === null || $age_years < $newest_tool['age'] ) {
			$newest_tool = array(
				'name'    => $row->tool_name,
				'barcode' => $row->barcode,
				'age'     => $age_years,
			);
		}
	}

	$avg_age                = $tool_count > 0 ? $total_age_years / $tool_count : 0;
	$total_initial          = $on_hand_initial + $on_loan_initial;
	$total_current          = $on_hand_current + $on_loan_current;
	$total_depreciated_away = max( 0, $total_initial - $total_current );
	$utilization_pct        = $tool_count > 0 ? round( $tools_out / $tool_count * 100 ) : 0;
	$value_retained_pct     = $total_initial > 0 ? round( $total_current / $total_initial * 100 ) : 0;

	// --- Member areas (by ZIP code) ---
	// Excludes anonymized members -- their real address is gone, and this
	// panel is identity-adjacent (it's about where members live).
	$zip_rows   = $wpdb->get_results( "SELECT zip_code FROM {$tbl_members} WHERE anonymized_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only, no request-derived data.
	$zip_counts = array();
	foreach ( $zip_rows as $row ) {
		$zip                = trim( (string) $row->zip_code ) !== '' ? $row->zip_code : 'Unknown';
		$zip_counts[ $zip ] = isset( $zip_counts[ $zip ] ) ? $zip_counts[ $zip ] + 1 : 1;
	}
	arsort( $zip_counts );

	// --- Overdue loans ---
	$overdue_rows = $wpdb->get_results(
		"
        SELECT l.loan_id, l.loan_date, l.due_date,
               DATEDIFF(CURDATE(), l.due_date) AS days_overdue,
               t.tool_name, t.barcode,
               m.first_name, m.last_name, m.email, m.phone_number
        FROM {$tbl_loans} l
        JOIN {$tbl_inventory} t ON t.tool_id = l.tool_id
        JOIN {$tbl_members} m ON m.member_id = l.member_id
        WHERE l.return_date IS NULL AND l.due_date < CURDATE()
        ORDER BY days_overdue DESC
    "
	);

	// --- Upcoming reservations (unexpired), flagged if the tool is still out ---
	// reservation_date is a TIMESTAMP, so a tool can carry a multi-member
	// waiting queue; queue_place/queue_size are derived here rather than stored
	// (earliest reservation for a tool is position 1, ties broken by id).
	$reservation_rows = $wpdb->get_results(
		"
        SELECT r.reservation_id, r.reservation_date,
               t.tool_name, t.barcode,
               m.first_name, m.last_name, m.email,
               l.loan_id AS tool_out_loan_id,
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
        JOIN {$tbl_members} m ON m.member_id = r.member_id
        LEFT JOIN {$tbl_loans} l ON l.tool_id = r.tool_id AND l.return_date IS NULL
        WHERE r.expiry_date IS NULL
        ORDER BY r.reservation_date ASC
    "
	);

	// --- Tool popularity (loan counts per tool, optionally date-filtered) ---
	// The range conditions live in the LEFT JOIN's ON clause (not WHERE) so
	// never-borrowed tools still appear with a count of 0 -- essential for
	// the "least popular" panel.
	$pop_on     = 'l.tool_id = t.tool_id';
	$pop_params = array();
	if ( $date_from !== '' ) {
		$pop_on      .= ' AND l.loan_date >= %s';
		$pop_params[] = $date_from;
	}
	if ( $date_to !== '' ) {
		// DATE(), not a raw <=: loan_date is a full timestamp, so a bare
		// comparison against a plain "to" date would exclude any loan later
		// that same day (its time-of-day would push it past midnight).
		$pop_on      .= ' AND DATE(l.loan_date) <= %s';
		$pop_params[] = $date_to;
	}
	// Retired tools are excluded -- a retired tool showing up as "least
	// popular" would be misleading (it's retired, not unpopular).
	$pop_sql = "
        SELECT t.tool_id, t.tool_name, t.barcode, t.brand, COUNT(l.loan_id) AS loan_count
        FROM {$tbl_inventory} t
        LEFT JOIN {$tbl_loans} l ON {$pop_on}
        WHERE t.retired_at IS NULL
        GROUP BY t.tool_id
        ORDER BY loan_count DESC, t.tool_name ASC
    ";
	if ( $pop_params ) {
		$pop_sql = $wpdb->prepare( $pop_sql, $pop_params );
	}
	$popularity_rows = $wpdb->get_results( $pop_sql );

	$most_popular         = array_slice(
		array_filter(
			$popularity_rows,
			function ( $r ) {
				return (int) $r->loan_count > 0;
			}
		),
		0,
		8
	);
	$least_popular        = array_slice( array_reverse( $popularity_rows ), 0, 8 );
	$never_borrowed_count = count(
		array_filter(
			$popularity_rows,
			function ( $r ) {
				return (int) $r->loan_count === 0;
			}
		)
	);

	// --- Member rental leaderboard (optionally date-filtered) ---
	$rent_on     = 'l.member_id = m.member_id';
	$rent_params = array();
	if ( $date_from !== '' ) {
		$rent_on      .= ' AND l.loan_date >= %s';
		$rent_params[] = $date_from;
	}
	if ( $date_to !== '' ) {
		// Same DATE() reasoning as the popularity query above.
		$rent_on      .= ' AND DATE(l.loan_date) <= %s';
		$rent_params[] = $date_to;
	}
	$rent_sql = "
        SELECT m.member_id, m.first_name, m.last_name, m.email,
               COUNT(l.loan_id) AS loan_count, MAX(l.loan_date) AS last_loan
        FROM {$tbl_members} m
        LEFT JOIN {$tbl_loans} l ON {$rent_on}
        WHERE m.anonymized_at IS NULL
        GROUP BY m.member_id
        HAVING loan_count > 0
        ORDER BY loan_count DESC, last_loan DESC
    ";
	if ( $rent_params ) {
		$rent_sql = $wpdb->prepare( $rent_sql, $rent_params );
	}
	$renter_rows = $wpdb->get_results( $rent_sql );

	// --- Donor leaderboard (all time; donated_by is free text on tools) ---
	$donor_rows = $wpdb->get_results(
		"
        SELECT donated_by, COUNT(*) AS items_donated, SUM(initial_cash_value) AS total_value
        FROM {$tbl_inventory}
        WHERE donated_by IS NOT NULL AND donated_by != ''
        GROUP BY donated_by
        ORDER BY total_value DESC
    "
	);

	// --- Tool History Lookup / Member History Lookup panels ---
	// Both panels are search-first: staff type a name into an autocomplete
	// box (preloaded below, same client-side pattern as Quick Loan on the
	// Inventory page) and the page reloads with the picked id in the query
	// string. The actual detail queries only run once something is picked --
	// there is no reason to compute a full history for every tool/member on
	// every dashboard load.
	$th_tool_id   = isset( $_GET['mtl_th_tool'] ) ? intval( $_GET['mtl_th_tool'] ) : 0;
	$mh_member_id = isset( $_GET['mtl_mh_member'] ) ? intval( $_GET['mtl_mh_member'] ) : 0;

	// Tools are intentionally not filtered by retired_at here -- a retired
	// tool's rental history is exactly the kind of thing this lookup is for.
	$dash_tool_options = array();
	foreach ( $wpdb->get_results( "SELECT tool_id, tool_name, barcode FROM {$tbl_inventory} ORDER BY tool_name ASC" ) as $t ) {
		$t_name              = stripslashes( (string) $t->tool_name );
		$dash_tool_options[] = array(
			'id'     => (int) $t->tool_id,
			'name'   => $t_name,
			'sub'    => (string) $t->barcode,
			'label'  => $t_name . ' (' . $t->barcode . ')',
			'search' => strtolower( $t_name . ' ' . $t->barcode ),
		);
	}

	// Anonymized members are excluded -- they no longer have a real name to
	// search by. Their history can still be found via a tool's own lookup
	// (it will just show as "Former Member").
	$dash_member_options = array();
	foreach ( $wpdb->get_results( "SELECT member_id, first_name, last_name, email FROM {$tbl_members} WHERE anonymized_at IS NULL ORDER BY last_name ASC, first_name ASC" ) as $m ) {
		$m_name                = trim( stripslashes( (string) $m->first_name ) . ' ' . stripslashes( (string) $m->last_name ) );
		$dash_member_options[] = array(
			'id'     => (int) $m->member_id,
			'name'   => $m_name,
			'sub'    => (string) $m->email,
			'label'  => $m_name . ' (' . $m->email . ')',
			'search' => strtolower( $m_name . ' ' . $m->email ),
		);
	}

	// --- Selected tool's detail: who has rented it, grouped by member, plus
	// the full loan-by-loan log. ---
	$th_tool      = null;
	$th_by_member = array();
	$th_loans     = array();
	if ( $th_tool_id > 0 ) {
		$th_tool = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT tool_id, tool_name, barcode, brand, retired_at FROM {$tbl_inventory} WHERE tool_id = %d",
				$th_tool_id
			)
		);
		if ( $th_tool ) {
			$th_by_member = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.member_id, m.first_name, m.last_name, m.email,
                        COUNT(l.loan_id) AS loan_count, MAX(l.loan_date) AS last_loan,
                        SUM(CASE WHEN l.return_date IS NULL THEN 1 ELSE 0 END) AS currently_out
                 FROM {$tbl_loans} l
                 JOIN {$tbl_members} m ON m.member_id = l.member_id
                 WHERE l.tool_id = %d
                 GROUP BY m.member_id
                 ORDER BY loan_count DESC, last_loan DESC",
					$th_tool_id
				)
			);
			$th_loans     = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.loan_id, l.loan_date, l.due_date, l.return_date, m.first_name, m.last_name, m.email
                 FROM {$tbl_loans} l
                 JOIN {$tbl_members} m ON m.member_id = l.member_id
                 WHERE l.tool_id = %d
                 ORDER BY l.loan_date DESC",
					$th_tool_id
				)
			);
		}
	}

	// --- Selected member's detail: full loan history, plus reservation
	// history (including past/expired ones -- Membership's own detail panel
	// only shows currently-active reservations, not the full record). ---
	$mh_member       = null;
	$mh_loans        = array();
	$mh_reservations = array();
	if ( $mh_member_id > 0 ) {
		$mh_member = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.member_id, m.first_name, m.last_name, m.email, m.signup_date, m.anonymized_at,
                    (v.photo_id_scan_url IS NOT NULL AND v.address_proof_scan_url IS NOT NULL) AS is_verified
             FROM {$tbl_members} m
             LEFT JOIN {$tbl_verifications} v ON v.member_id = m.member_id
             WHERE m.member_id = %d",
				$mh_member_id
			)
		);
		if ( $mh_member ) {
			$mh_loans        = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.loan_id, l.loan_date, l.due_date, l.return_date, t.tool_name, t.barcode
                 FROM {$tbl_loans} l
                 JOIN {$tbl_inventory} t ON t.tool_id = l.tool_id
                 WHERE l.member_id = %d
                 ORDER BY l.loan_date DESC",
					$mh_member_id
				)
			);
			$mh_reservations = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.reservation_id, r.reservation_date, r.expiry_date, t.tool_name, t.barcode
                 FROM {$tbl_reservations} r
                 JOIN {$tbl_inventory} t ON t.tool_id = r.tool_id
                 WHERE r.member_id = %d
                 ORDER BY r.reservation_date DESC",
					$mh_member_id
				)
			);
		}
	}

	$layout = mtl_get_dashboard_layout();
	$panels = mtl_dashboard_panels();

	// Pre-fill the save form with the layout as currently rendered, so if
	// JavaScript is unavailable, clicking Save simply re-saves the existing
	// arrangement instead of wiping it.
	$layout_json = wp_json_encode( $layout );

	// ==========================================
	// 4. STYLES
	// ==========================================
	?>
	<style>
		.mtl-dash-toolbar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 10px;
			margin: 15px 0;
		}

		.mtl-dash-toolbar form {
			display: flex;
			align-items: center;
			gap: 6px;
			flex-wrap: wrap;
		}

		.mtl-dash-toolbar label {
			font-size: 0.85em;
			font-weight: 600;
		}

		.mtl-panels-menu {
			position: relative;
		}

		.mtl-panels-menu>summary {
			list-style: none;
			cursor: pointer;
		}

		.mtl-panels-menu-body {
			position: absolute;
			right: 0;
			top: calc(100% + 6px);
			z-index: 50;
			background: #fff;
			border: 1px solid #ccd0d4;
			/* Same pill-clamp reasoning as .mtl-panel above. */
			border-radius: min(var(--mtl-radius, 4px), 12px);
			box-shadow: 0 4px 12px rgba(0, 0, 0, .12);
			padding: 12px 15px;
			min-width: 260px;
		}

		.mtl-panels-menu-body label {
			display: flex;
			align-items: center;
			gap: 7px;
			padding: 3px 0;
			font-size: 0.9em;
			cursor: pointer;
			white-space: nowrap;
		}

		/* The dashboard grid. 12 columns; panel sizes are column spans. */
		.mtl-dash-grid {
			display: grid;
			grid-template-columns: repeat(12, 1fr);
			gap: 16px;
			align-items: start;
		}

		.mtl-panel {
			background: #fff;
			border: 1px solid #ccd0d4;
			/*
			 * Clamp the themed corner radius so the "Pill" setting (999px)
			 * can't carve giant quarter-circles out of the card corners and
			 * clip the charts inside (the card has overflow:hidden). Sharp
			 * (0), Soft (4px) and Rounded (10px) still pass through unchanged;
			 * only the extreme pill value is capped to a sane 12px.
			 */
			border-radius: min(var(--mtl-radius, 4px), 12px);
			box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
			transition: box-shadow 0.15s ease;
			overflow: hidden;
		}

		.mtl-panel:hover {
			box-shadow: 0 3px 10px rgba(0, 0, 0, .10);
		}

		.mtl-size-small {
			grid-column: span 4;
		}

		.mtl-size-medium {
			grid-column: span 6;
		}

		.mtl-size-large {
			grid-column: span 12;
		}

		@media (max-width: 1200px) {
			.mtl-size-small {
				grid-column: span 6;
			}
		}

		@media (max-width: 850px) {

			.mtl-size-small,
			.mtl-size-medium {
				grid-column: span 12;
			}
		}

		.mtl-panel.mtl-hidden {
			display: none;
		}

		.mtl-panel.mtl-dragging {
			opacity: 0.4;
		}

		.mtl-panel-head {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 10px 12px;
			border-bottom: 1px solid #eef0f2;
			background: #fafbfc;
			cursor: grab;
		}

		/* Card titles must stay compact -- the theme's h4 header size (often
			2em) is meant for section headings, not card chrome. Higher
			specificity than the injected .mtl-admin-wrapper h4 rule. */
		.mtl-admin-wrapper .mtl-panel-head h4 {
			font-size: 1em;
			margin: 0;
			flex: 1;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		.mtl-drag-handle {
			color: #a0a5aa;
			cursor: grab;
			font-size: 1.05em;
		}

		.mtl-panel-controls {
			display: flex;
			gap: 2px;
		}

		.mtl-panel-controls button {
			background: transparent;
			border: none;
			cursor: pointer;
			color: #787c82;
			font-size: 1.05em;
			line-height: 1;
			padding: 2px 5px;
			border-radius: 3px;
			/*
			 * The hide control is an emoji (eye), which browsers render in
			 * full color regardless of `color`. Desaturating it keeps both
			 * controls looking like plain grey UI glyphs rather than a
			 * colorful sticker; the muted opacity brightens on hover.
			 */
			filter: grayscale(100%);
			opacity: 0.55;
		}

		.mtl-panel-controls button:hover {
			background: #eef0f2;
			opacity: 1;
		}

		.mtl-panel-body {
			padding: 14px;
		}

		.mtl-panel-sub {
			font-size: 0.8em;
			color: #787c82;
			margin: -6px 0 10px 0;
		}

		.mtl-insight {
			font-size: 0.85em;
			background: #f6f9fb;
			border-left: 3px solid var(--mtl-accent-color, #f7c600);
			padding: 7px 10px;
			margin-top: 12px;
			border-radius: 0 4px 4px 0;
		}

		.mtl-empty {
			color: #999;
			text-align: center;
			padding: 18px 0;
		}

		/* Stat tiles */
		.mtl-stat-number {
			font-size: 2.6em;
			font-weight: 700;
			line-height: 1.1;
			color: var(--mtl-header-color, #ff6600);
		}

		.mtl-stat-sub {
			color: #787c82;
			font-size: 0.9em;
			margin-bottom: 8px;
		}

		.mtl-stat-facts {
			display: flex;
			gap: 14px;
			flex-wrap: wrap;
			margin-top: 8px;
			font-size: 0.85em;
		}

		.mtl-stat-facts span strong {
			display: block;
			font-size: 1.25em;
		}

		.mtl-meter {
			height: 10px;
			background: #e8ecef;
			border-radius: 999px;
			overflow: hidden;
			margin-top: 10px;
		}

		.mtl-meter-fill {
			height: 100%;
			background: var(--mtl-header-color, #ff6600);
			border-radius: 999px;
		}

		/* Donut charts */
		.mtl-donut {
			display: flex;
			align-items: center;
			gap: 14px;
			flex-wrap: wrap;
		}

		.mtl-donut-svg {
			width: 130px;
			height: 130px;
			flex-shrink: 0;
		}

		.mtl-donut-big {
			font-size: 0.55em;
			font-weight: 700;
			text-anchor: middle;
			fill: currentColor;
		}

		.mtl-donut-small {
			font-size: 0.22em;
			text-anchor: middle;
			fill: #787c82;
		}

		.mtl-legend {
			list-style: none;
			margin: 0;
			padding: 0;
			font-size: 0.85em;
			flex: 1;
			min-width: 140px;
		}

		.mtl-legend li {
			display: flex;
			align-items: center;
			gap: 6px;
			padding: 3px 0;
		}

		.mtl-legend li strong {
			margin-left: auto;
		}

		.mtl-dot {
			width: 10px;
			height: 10px;
			border-radius: 50%;
			flex-shrink: 0;
		}

		/* Bar charts */
		.mtl-bars {
			display: flex;
			flex-direction: column;
			gap: 7px;
		}

		.mtl-bar-row {
			display: grid;
			grid-template-columns: 130px 1fr 34px;
			align-items: center;
			gap: 8px;
			font-size: 0.85em;
		}

		.mtl-bar-label {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
			text-align: right;
		}

		.mtl-bar-sub {
			display: block;
			color: #999;
			font-size: 0.85em;
		}

		.mtl-bar-track {
			background: #eef1f3;
			border-radius: 3px;
			height: 18px;
			overflow: hidden;
		}

		.mtl-bar-fill {
			height: 100%;
			border-radius: 3px;
			min-width: 2px;
			transition: width 0.4s ease;
		}

		.mtl-bar-value {
			font-weight: 600;
			text-align: right;
		}

		/* Scrollable data tables */
		.mtl-scroll-table {
			max-height: 300px;
			overflow-y: auto;
			border: 1px solid #eef0f2;
			border-radius: 4px;
		}

		.mtl-size-large .mtl-scroll-table {
			max-height: 560px;
		}

		.mtl-scroll-table table {
			width: 100%;
			border-collapse: collapse;
			font-size: 0.85em;
		}

		.mtl-scroll-table th {
			position: sticky;
			top: 0;
			background: #f6f7f7;
			text-align: left;
			padding: 7px 9px;
			font-size: 0.9em;
			text-transform: uppercase;
			letter-spacing: 0.02em;
			z-index: 5;
		}

		.mtl-scroll-table td {
			padding: 7px 9px;
			border-top: 1px solid #f0f2f4;
			vertical-align: top;
		}

		.mtl-scroll-table tbody tr:hover {
			background: #f6fafd;
		}

		.mtl-overdue-days {
			color: #b32d2e;
			font-weight: 700;
		}

		.mtl-panel-overdue-alert {
			border-color: #d63638;
		}

		.mtl-panel-overdue-alert .mtl-panel-head {
			background: #fdf2f2;
		}

		.mtl-count-pill {
			background: #d63638;
			color: #fff;
			border-radius: 999px;
			font-size: 0.75em;
			font-weight: 700;
			padding: 1px 8px;
		}

		.mtl-ok-pill {
			background: #edf7ed;
			color: #1e7e34;
			border: 1px solid #bfe3c0;
			border-radius: 999px;
			font-size: 0.75em;
			padding: 1px 8px;
		}

		.mtl-wait-pill {
			background: #fff8e5;
			color: #8a6d00;
			border: 1px solid #f0dca0;
			border-radius: 999px;
			font-size: 0.75em;
			padding: 1px 8px;
		}

		/* "View full data" expanders inside chart panels */
		.mtl-panel-more {
			margin-top: 12px;
			border-top: 1px solid #eef0f2;
			padding-top: 8px;
		}

		.mtl-panel-more>summary {
			cursor: pointer;
			font-size: 0.85em;
			color: var(--mtl-link-color, #00b3ff);
		}

		/* Save button attention state when the layout has unsaved changes */
		#mtl-save-layout-btn.mtl-dirty {
			background: var(--mtl-accent-color, #f7c600) !important;
			border-color: var(--mtl-accent-color, #f7c600) !important;
			color: #1d2327 !important;
		}

		/* Tool/Member History Lookup: search-box + dropdown, same pattern as
			Quick Loan's member autocomplete on the Inventory page. */
		.mtl-dash-lookup-label {
			display: block;
			font-weight: 600;
			font-size: 0.9em;
			margin-bottom: 4px;
		}

		.mtl-dash-lookup-row {
			display: flex;
			gap: 8px;
			align-items: flex-start;
			flex-wrap: wrap;
		}

		.mtl-dash-autocomplete {
			position: relative;
			flex: 1;
			min-width: 220px;
		}

		.mtl-dash-autocomplete input[type="text"] {
			width: 100%;
			box-sizing: border-box;
			padding: 7px 10px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
		}

		.mtl-dash-dropdown {
			position: absolute;
			left: 0;
			right: 0;
			top: 100%;
			z-index: 10;
			background: #fff;
			border: 1px solid #ccd0d4;
			border-top: none;
			border-radius: 0 0 4px 4px;
			box-shadow: 0 6px 14px rgba(0, 0, 0, .12);
			max-height: 220px;
			overflow-y: auto;
		}

		.mtl-dash-option {
			padding: 7px 10px;
			cursor: pointer;
			font-size: 0.9em;
			border-top: 1px solid #f0f1f2;
		}

		.mtl-dash-option:first-child {
			border-top: none;
		}

		.mtl-dash-option:hover,
		.mtl-dash-option.mtl-dash-option-active {
			background: #f0f7fb;
		}

		.mtl-dash-option .mtl-dash-option-sub {
			color: #787c82;
			font-size: 0.9em;
		}

		.mtl-dash-empty-option {
			padding: 8px 10px;
			color: #787c82;
			font-size: 0.9em;
		}

		.mtl-dash-lookup-header {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			flex-wrap: wrap;
			gap: 8px;
			margin: 16px 0 10px 0;
			padding-bottom: 8px;
			border-bottom: 1px solid #eef0f2;
		}

		.mtl-admin-wrapper .mtl-dash-lookup-header h5 {
			margin: 0;
			font-size: 1.1em;
		}
	</style>

	<?php
	// ==========================================
	// 5. TOOLBAR (date filter, panel visibility, save/reset layout)
	// ==========================================
	?>
	<div class="mtl-dash-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="mtl-dashboard">
			<label for="mtl_from">Loan activity from</label>
			<input type="date" id="mtl_from" name="mtl_from" value="<?php echo esc_attr( $date_from ); ?>">
			<label for="mtl_to">to</label>
			<input type="date" id="mtl_to" name="mtl_to" value="<?php echo esc_attr( $date_to ); ?>">
			<button type="submit" class="button">Apply</button>
			<?php if ( $date_from !== '' || $date_to !== '' ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="button">Clear</a>
			<?php endif; ?>
		</form>

		<div style="display: flex; gap: 8px; align-items: center;">
			<details class="mtl-panels-menu">
				<summary class="button">Panels</summary>
				<div class="mtl-panels-menu-body">
					<p style="margin: 0 0 6px 0; font-size: 0.8em; color: #787c82;">Untick a panel to hide it. Click Save Layout to keep your changes.</p>
					<?php foreach ( $layout as $panel_id => $prefs ) : ?>
						<label>
							<input type="checkbox" class="mtl-panel-toggle" data-panel="<?php echo esc_attr( $panel_id ); ?>" <?php checked( $prefs['visible'] ); ?>>
							<?php echo esc_html( $panels[ $panel_id ]['title'] ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</details>

			<form method="post" action="<?php echo esc_url( $base_url ); ?>" id="mtl-layout-form">
				<?php wp_nonce_field( 'mtl_dashboard_layout_action', 'mtl_dashboard_layout_nonce' ); ?>
				<input type="hidden" name="mtl_dashboard_layout_json" id="mtl-layout-json" value="<?php echo esc_attr( $layout_json ); ?>">
				<button type="submit" name="mtl_save_dashboard_layout" id="mtl-save-layout-btn" class="button button-primary">Save Layout</button>
				<button type="submit" name="mtl_reset_dashboard_layout" class="button" onclick="return confirm('Reset the dashboard to its default layout?');">Reset</button>
			</form>
		</div>
	</div>

	<p style="font-size: 0.85em; color: #787c82; margin: 0 0 12px 0;">
		Drag a panel by its header to rearrange &bull; the resize icon (&#x2922;) cycles its size &bull; the eye icon hides a panel &bull; changes stick after <strong>Save Layout</strong>.
	</p>

	<?php
	// ==========================================
	// 6. RENDER PANELS (in the saved order)
	// ==========================================
	echo '<div class="mtl-dash-grid" id="mtl-dash-grid">';

	foreach ( $layout as $panel_id => $prefs ) {
		$extra_class = $prefs['visible'] ? '' : ' mtl-hidden';
		if ( $panel_id === 'overdue' && ! empty( $overdue_rows ) ) {
			$extra_class .= ' mtl-panel-overdue-alert';
		}

		echo '<section class="mtl-panel mtl-size-' . esc_attr( $prefs['size'] ) . esc_attr( $extra_class ) . '" data-panel="' . esc_attr( $panel_id ) . '" data-size="' . esc_attr( $prefs['size'] ) . '">';
		echo '<header class="mtl-panel-head">';
		echo '<span class="mtl-drag-handle" title="Drag to rearrange">⠿</span>';
		echo '<h4>' . esc_html( $panels[ $panel_id ]['title'] ) . '</h4>';
		if ( $panel_id === 'overdue' && ! empty( $overdue_rows ) ) {
			echo '<span class="mtl-count-pill">' . count( $overdue_rows ) . '</span>';
		}
		echo '<div class="mtl-panel-controls">';
		echo '<button type="button" class="mtl-resize-btn" title="Cycle size: small / medium / large" aria-label="Resize panel">⤢</button>';
		echo '<button type="button" class="mtl-hide-btn" title="Hide this panel" aria-label="Hide panel">👁</button>';
		echo '</div>';
		echo '</header>';
		echo '<div class="mtl-panel-body">';

		switch ( $panel_id ) {

			case 'stat_members':
				?>
				<div class="mtl-stat-number"><?php echo esc_html( number_format( $member_count ) ); ?></div>
				<div class="mtl-stat-sub">active members</div>
				<div class="mtl-stat-facts">
					<span><strong><?php echo esc_html( number_format( $verified_count ) ); ?></strong> verified</span>
					<span><strong><?php echo esc_html( number_format( max( 0, $member_count - $verified_count ) ) ); ?></strong> unverified</span>
					<span><strong><?php echo esc_html( number_format( $new_members_90 ) ); ?></strong> new in 90 days</span>
				</div>
				<?php if ( $member_count > 0 && $verified_count < $member_count ) : ?>
					<p class="mtl-insight"><?php echo esc_html( number_format( $member_count - $verified_count ) ); ?> member(s) do not have verification documents on file.</p>
				<?php endif; ?>
				<?php
				break;

			case 'stat_borrowed':
				?>
				<div class="mtl-stat-number"><?php echo esc_html( number_format( $tools_out ) ); ?></div>
				<div class="mtl-stat-sub">of <?php echo esc_html( number_format( $tool_count ) ); ?> tools currently on loan</div>
				<div class="mtl-meter">
					<div class="mtl-meter-fill" style="width: <?php echo esc_attr( $utilization_pct ); ?>%;"></div>
				</div>
				<p class="mtl-insight">Inventory utilization is at <strong><?php echo esc_html( $utilization_pct ); ?>%</strong>.</p>
				<?php
				break;

			case 'stat_avg_age':
				?>
				<div class="mtl-stat-number"><?php echo esc_html( number_format( $avg_age, 1 ) ); ?></div>
				<div class="mtl-stat-sub">years &mdash; average tool age</div>
				<?php if ( $oldest_tool && $newest_tool ) : ?>
					<div class="mtl-stat-facts">
						<span><strong><?php echo esc_html( number_format( $oldest_tool['age'], 1 ) ); ?>y</strong> oldest: <?php echo esc_html( stripslashes( $oldest_tool['name'] ) ); ?><br><span style="color:#999;"><?php echo esc_html( stripslashes( $oldest_tool['barcode'] ) ); ?></span></span>
						<span><strong><?php echo esc_html( number_format( $newest_tool['age'], 1 ) ); ?>y</strong> newest: <?php echo esc_html( stripslashes( $newest_tool['name'] ) ); ?><br><span style="color:#999;"><?php echo esc_html( stripslashes( $newest_tool['barcode'] ) ); ?></span></span>
					</div>
				<?php endif; ?>
				<?php
				break;

			case 'overdue':
				if ( empty( $overdue_rows ) ) {
					echo '<p class="mtl-empty">Nothing is overdue.</p>';
					break;
				}
				?>
				<div class="mtl-scroll-table">
					<table>
						<thead>
							<tr>
								<th>Tool</th>
								<th>Member</th>
								<th>Due</th>
								<th>Overdue</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $overdue_rows as $row ) : ?>
								<tr>
									<td><strong><?php echo esc_html( stripslashes( $row->tool_name ) ); ?></strong><br><span style="color:#999;"><?php echo esc_html( stripslashes( $row->barcode ) ); ?></span></td>
									<td><?php echo esc_html( stripslashes( $row->first_name . ' ' . $row->last_name ) ); ?><br>
										<span style="color:#999;"><?php echo esc_html( $row->email ); ?> &bull; <?php echo esc_html( stripslashes( $row->phone_number ) ); ?></span>
									</td>
									<td><?php echo mtl_format_date( $row->due_date ); ?></td>
									<td class="mtl-overdue-days"><?php echo esc_html( $row->days_overdue ); ?> day<?php echo (int) $row->days_overdue === 1 ? '' : 's'; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php
				break;

			case 'reservations':
				if ( empty( $reservation_rows ) ) {
					echo '<p class="mtl-empty">No upcoming reservations.</p>';
					break;
				}
				?>
				<div class="mtl-scroll-table">
					<table>
						<thead>
							<tr>
								<th>Tool</th>
								<th>Member</th>
								<th>Reserved</th>
								<th>Queue</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $reservation_rows as $row ) : ?>
								<tr>
									<td><strong><?php echo esc_html( stripslashes( $row->tool_name ) ); ?></strong><br><span style="color:#999;"><?php echo esc_html( stripslashes( $row->barcode ) ); ?></span></td>
									<td><?php echo esc_html( stripslashes( $row->first_name . ' ' . $row->last_name ) ); ?><br><span style="color:#999;"><?php echo esc_html( $row->email ); ?></span></td>
									<td><?php echo mtl_format_date( $row->reservation_date ); ?><br><span style="color:#999;"><?php echo esc_html( date( 'H:i', strtotime( $row->reservation_date ) ) ); ?></span></td>
									<td><?php echo esc_html( '#' . $row->queue_place . ' of ' . $row->queue_size ); ?></td>
									<td><?php echo $row->tool_out_loan_id ? '<span class="mtl-wait-pill">Waiting, tool out</span>' : '<span class="mtl-ok-pill">Ready for pickup</span>'; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php
				break;

			case 'popular':
				// $range_label already carries pre-escaped, formatted dates
				// (mtl_format_date()) plus plain literal text -- not re-escaped
				// here, since esc_html() would corrupt the em dash entity.
				echo '<p class="mtl-panel-sub">' . $range_label . '</p>';
				$bar_items = array();
				foreach ( $most_popular as $row ) {
					$bar_items[] = array( stripslashes( $row->tool_name ), (int) $row->loan_count, stripslashes( (string) $row->barcode ) );
				}
				echo mtl_dash_bars( $bar_items, '--mtl-header-color' );
				if ( ! empty( $popularity_rows ) ) {
					?>
					<details class="mtl-panel-more">
						<summary>View full ranking (<?php echo count( $popularity_rows ); ?> tools)</summary>
						<div class="mtl-scroll-table" style="margin-top: 8px;">
							<table>
								<thead>
									<tr>
										<th>#</th>
										<th>Tool</th>
										<th>Brand</th>
										<th>Loans</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $popularity_rows as $i => $row ) : ?>
										<tr>
											<td><?php echo esc_html( $i + 1 ); ?></td>
											<td><?php echo esc_html( stripslashes( $row->tool_name ) ); ?><br><span style="color:#999;"><?php echo esc_html( stripslashes( $row->barcode ) ); ?></span></td>
											<td><?php echo esc_html( stripslashes( (string) $row->brand ) ); ?></td>
											<td><strong><?php echo esc_html( $row->loan_count ); ?></strong></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</details>
					<?php
				}
				break;

			case 'unpopular':
				// See 'popular' case above re: $range_label escaping.
				echo '<p class="mtl-panel-sub">' . $range_label . '</p>';
				$bar_items = array();
				foreach ( $least_popular as $row ) {
					$bar_items[] = array( stripslashes( $row->tool_name ), (int) $row->loan_count, stripslashes( (string) $row->barcode ) );
				}
				echo mtl_dash_bars( $bar_items, '--mtl-link-color' );
				if ( $never_borrowed_count > 0 ) {
					echo '<p class="mtl-insight"><strong>' . esc_html( $never_borrowed_count ) . '</strong> tool(s) have never been borrowed in this period.</p>';
				}
				break;

			case 'value_initial':
				echo mtl_dash_donut( 'On hand', $on_hand_initial, 'On loan', $on_loan_initial, 'on hand', $currency );
				echo '<p class="mtl-insight">Total inventory (initial value): <strong>' . esc_html( $currency . number_format( $total_initial, 2 ) ) . '</strong> across ' . esc_html( number_format( $tool_count ) ) . ' tools.</p>';
				break;

			case 'value_current':
				echo mtl_dash_donut( 'On hand', $on_hand_current, 'On loan', $on_loan_current, 'on hand', $currency );
				echo '<p class="mtl-insight">Current (depreciated) inventory value: <strong>' . esc_html( $currency . number_format( $total_current, 2 ) ) . '</strong>.</p>';
				break;

			case 'depreciation':
				echo mtl_dash_donut( 'Value retained', $total_current, 'Depreciated away', $total_depreciated_away, 'retained', $currency );
				echo '<p class="mtl-insight">Inventory has retained <strong>' . esc_html( $value_retained_pct ) . '%</strong> of its original ' . esc_html( $currency . number_format( $total_initial, 2 ) ) . ' value.</p>';
				break;

			case 'areas':
				$bar_items = array();
				foreach ( array_slice( $zip_counts, 0, 8, true ) as $zip => $count ) {
					$bar_items[] = array( $zip === 'Unknown' ? 'Unknown' : 'ZIP ' . $zip, $count );
				}
				echo mtl_dash_bars( $bar_items, '--mtl-accent-color' );
				if ( ! empty( $zip_counts ) ) {
					$top_zip   = array_key_first( $zip_counts );
					$top_share = $member_count > 0 ? round( reset( $zip_counts ) / $member_count * 100 ) : 0;
					echo '<p class="mtl-insight">' . esc_html( $top_zip === 'Unknown' ? 'The largest member group has no ZIP on file' : 'ZIP ' . $top_zip . ' is the largest member area' ) . ' (<strong>' . esc_html( $top_share ) . '%</strong> of members).</p>';
					?>
					<details class="mtl-panel-more">
						<summary>View all areas (<?php echo count( $zip_counts ); ?>)</summary>
						<div class="mtl-scroll-table" style="margin-top: 8px;">
							<table>
								<thead>
									<tr>
										<th>Area</th>
										<th>Members</th>
										<th>Share</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $zip_counts as $zip => $count ) : ?>
										<tr>
											<td><?php echo esc_html( $zip === 'Unknown' ? 'Unknown' : 'ZIP ' . $zip ); ?></td>
											<td><strong><?php echo esc_html( $count ); ?></strong></td>
											<td><?php echo esc_html( $member_count > 0 ? round( $count / $member_count * 100 ) : 0 ); ?>%</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</details>
					<?php
				}
				break;

			case 'renters':
				// See 'popular' case above re: $range_label escaping.
				echo '<p class="mtl-panel-sub">' . $range_label . '</p>';
				if ( empty( $renter_rows ) ) {
					echo '<p class="mtl-empty">No loans recorded in this period.</p>';
					break;
				}
				$medals = array( '🥇', '🥈', '🥉' );
				?>
				<div class="mtl-scroll-table">
					<table>
						<thead>
							<tr>
								<th>#</th>
								<th>Member</th>
								<th>Loans</th>
								<th>Last Loan</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $renter_rows as $i => $row ) : ?>
								<tr>
									<td><?php echo esc_html( isset( $medals[ $i ] ) ? $medals[ $i ] : $i + 1 ); ?></td>
									<td><?php echo esc_html( stripslashes( $row->first_name . ' ' . $row->last_name ) ); ?><br><span style="color:#999;"><?php echo esc_html( $row->email ); ?></span></td>
									<td><strong><?php echo esc_html( $row->loan_count ); ?></strong></td>
									<td><?php echo mtl_format_date( $row->last_loan ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php
				break;

			case 'donors':
				if ( empty( $donor_rows ) ) {
					echo '<p class="mtl-empty">No donated tools recorded yet.</p>';
					break;
				}
				$medals        = array( '🥇', '🥈', '🥉' );
				$donated_total = 0;
				foreach ( $donor_rows as $row ) {
					$donated_total += (float) $row->total_value;
				}
				?>
				<div class="mtl-scroll-table">
					<table>
						<thead>
							<tr>
								<th>#</th>
								<th>Donor</th>
								<th>Items</th>
								<th>Total Value</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $donor_rows as $i => $row ) : ?>
								<tr>
									<td><?php echo esc_html( isset( $medals[ $i ] ) ? $medals[ $i ] : $i + 1 ); ?></td>
									<td><?php echo esc_html( stripslashes( $row->donated_by ) ); ?></td>
									<td><strong><?php echo esc_html( $row->items_donated ); ?></strong></td>
									<td><?php echo esc_html( $currency . number_format( (float) $row->total_value, 2 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="mtl-insight">Donated tools account for <strong><?php echo esc_html( $currency . number_format( $donated_total, 2 ) ); ?></strong> of inventory value (<?php echo esc_html( $total_initial > 0 ? round( $donated_total / $total_initial * 100 ) : 0 ); ?>%).</p>
				<?php
				break;

			case 'tool_history':
				?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="mtl-dashboard">
					<?php
					if ( $date_from !== '' ) :
						?>
						<input type="hidden" name="mtl_from" value="<?php echo esc_attr( $date_from ); ?>"><?php endif; ?>
					<?php
					if ( $date_to !== '' ) :
						?>
						<input type="hidden" name="mtl_to" value="<?php echo esc_attr( $date_to ); ?>"><?php endif; ?>
					<input type="hidden" name="mtl_th_tool" id="mtl-th-tool-id" value="<?php echo esc_attr( $th_tool_id > 0 ? $th_tool_id : '' ); ?>">
					<label class="mtl-dash-lookup-label" for="mtl-th-search">Tool name or barcode</label>
					<div class="mtl-dash-lookup-row">
						<div class="mtl-dash-autocomplete">
							<input type="text" id="mtl-th-search" autocomplete="off" placeholder="Start typing a tool name..." value="<?php echo $th_tool ? esc_attr( stripslashes( $th_tool->tool_name ) . ' (' . $th_tool->barcode . ')' ) : ''; ?>">
							<div class="mtl-dash-dropdown" id="mtl-th-dropdown" style="display: none;"></div>
						</div>
						<button type="submit" class="button button-primary">View History</button>
						<?php if ( $th_tool_id > 0 ) : ?>
							<a class="button" href="<?php echo esc_url( remove_query_arg( 'mtl_th_tool' ) ); ?>">Clear</a>
						<?php endif; ?>
					</div>
				</form>
				<?php
				if ( $th_tool ) :
					$th_total = 0;
					foreach ( $th_by_member as $r ) {
						$th_total += (int) $r->loan_count;
					}
					?>
					<div class="mtl-dash-lookup-header">
						<h5><?php echo esc_html( stripslashes( $th_tool->tool_name ) ); ?> <span style="color:#999; font-weight:400;">(<?php echo esc_html( $th_tool->barcode ); ?>)</span><?php echo ! empty( $th_tool->retired_at ) ? ' <span style="color:#999; font-weight:400;">&mdash; retired</span>' : ''; ?></h5>
						<span><strong><?php echo esc_html( $th_total ); ?></strong> total loan<?php echo $th_total === 1 ? '' : 's'; ?></span>
					</div>

					<?php if ( empty( $th_by_member ) ) : ?>
						<p class="mtl-empty">This tool has never been rented.</p>
					<?php else : ?>
						<p class="mtl-panel-sub">Rented by, grouped by member &mdash; how many times each person has rented this specific tool.</p>
						<div class="mtl-scroll-table">
							<table>
								<thead>
									<tr>
										<th>Member</th>
										<th>Times Rented</th>
										<th>Last Rented</th>
										<th>Status</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $th_by_member as $r ) : ?>
										<tr>
											<td><?php echo esc_html( trim( stripslashes( $r->first_name ) . ' ' . stripslashes( $r->last_name ) ) ); ?><br><span style="color:#999;"><?php echo esc_html( $r->email ); ?></span></td>
											<td><strong><?php echo esc_html( $r->loan_count ); ?></strong></td>
											<td><?php echo mtl_format_date( $r->last_loan ); ?></td>
											<td><?php echo ( (int) $r->currently_out > 0 ) ? '<span class="mtl-wait-pill">Currently has it</span>' : ''; ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<details class="mtl-panel-more">
							<summary>View full loan-by-loan log (<?php echo count( $th_loans ); ?>)</summary>
							<div class="mtl-scroll-table" style="margin-top: 8px;">
								<table>
									<thead>
										<tr>
											<th>Member</th>
											<th>Loaned</th>
											<th>Due</th>
											<th>Returned</th>
										</tr>
									</thead>
									<tbody>
										<?php
										foreach ( $th_loans as $l ) :
											$late        = ( $l->return_date !== null && date( 'Y-m-d', strtotime( $l->return_date ) ) > $l->due_date );
											$out_overdue = ( $l->return_date === null && $l->due_date < date( 'Y-m-d' ) );
											?>
											<tr>
												<td><?php echo esc_html( trim( stripslashes( $l->first_name ) . ' ' . stripslashes( $l->last_name ) ) ); ?></td>
												<td><?php echo mtl_format_date( $l->loan_date ); ?></td>
												<td><?php echo mtl_format_date( $l->due_date ); ?></td>
												<td>
													<?php if ( $l->return_date ) : ?>
														<?php echo mtl_format_date( $l->return_date ); ?><?php echo $late ? ' <span class="mtl-overdue-days">(late)</span>' : ''; ?>
													<?php elseif ( $out_overdue ) : ?>
														<span class="mtl-overdue-days">Still out, overdue</span>
													<?php else : ?>
														Still out
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</details>
					<?php endif; ?>
				<?php elseif ( $th_tool_id > 0 ) : ?>
					<p class="mtl-empty">That tool could not be found.</p>
				<?php else : ?>
					<p class="mtl-empty">Search for a tool above to see who has rented it and how often.</p>
				<?php endif; ?>
				<?php
				break;

			case 'member_history':
				?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="mtl-dashboard">
					<?php
					if ( $date_from !== '' ) :
						?>
						<input type="hidden" name="mtl_from" value="<?php echo esc_attr( $date_from ); ?>"><?php endif; ?>
					<?php
					if ( $date_to !== '' ) :
						?>
						<input type="hidden" name="mtl_to" value="<?php echo esc_attr( $date_to ); ?>"><?php endif; ?>
					<input type="hidden" name="mtl_mh_member" id="mtl-mh-member-id" value="<?php echo esc_attr( $mh_member_id > 0 ? $mh_member_id : '' ); ?>">
					<label class="mtl-dash-lookup-label" for="mtl-mh-search">Member name or email</label>
					<div class="mtl-dash-lookup-row">
						<div class="mtl-dash-autocomplete">
							<input type="text" id="mtl-mh-search" autocomplete="off" placeholder="Start typing a member&rsquo;s name..." value="<?php echo $mh_member ? esc_attr( trim( stripslashes( $mh_member->first_name ) . ' ' . stripslashes( $mh_member->last_name ) ) . ' (' . $mh_member->email . ')' ) : ''; ?>">
							<div class="mtl-dash-dropdown" id="mtl-mh-dropdown" style="display: none;"></div>
						</div>
						<button type="submit" class="button button-primary">View History</button>
						<?php if ( $mh_member_id > 0 ) : ?>
							<a class="button" href="<?php echo esc_url( remove_query_arg( 'mtl_mh_member' ) ); ?>">Clear</a>
						<?php endif; ?>
					</div>
				</form>
				<?php
				if ( $mh_member ) :
					$mh_total  = count( $mh_loans );
					$mh_active = 0;
					foreach ( $mh_loans as $l ) {
						if ( ! $l->return_date ) {
							++$mh_active;
						}
					}
					?>
					<div class="mtl-dash-lookup-header">
						<h5><?php echo esc_html( trim( stripslashes( $mh_member->first_name ) . ' ' . stripslashes( $mh_member->last_name ) ) ); ?> <span style="color:#999; font-weight:400;">(<?php echo esc_html( $mh_member->email ); ?>)</span></h5>
						<span><?php echo (bool) $mh_member->is_verified ? '<span class="mtl-ok-pill">Verified</span>' : '<span class="mtl-wait-pill">Not verified</span>'; ?></span>
					</div>

					<?php if ( ! empty( $mh_member->anonymized_at ) ) : ?>
						<p class="mtl-insight">This member&rsquo;s personal data has been anonymized (their account was deleted); their loan history below is kept on record for accurate library statistics.</p>
					<?php endif; ?>

					<?php if ( empty( $mh_loans ) ) : ?>
						<p class="mtl-empty">This member has never rented a tool.</p>
					<?php else : ?>
						<p class="mtl-panel-sub"><strong><?php echo esc_html( $mh_total ); ?></strong> loan<?php echo $mh_total === 1 ? '' : 's'; ?> on record<?php echo $mh_active > 0 ? ', <strong>' . esc_html( $mh_active ) . '</strong> currently out' : ''; ?>.</p>
						<div class="mtl-scroll-table">
							<table>
								<thead>
									<tr>
										<th>Tool</th>
										<th>Loaned</th>
										<th>Due</th>
										<th>Returned</th>
									</tr>
								</thead>
								<tbody>
									<?php
									foreach ( $mh_loans as $l ) :
										$late        = ( $l->return_date !== null && date( 'Y-m-d', strtotime( $l->return_date ) ) > $l->due_date );
										$out_overdue = ( $l->return_date === null && $l->due_date < date( 'Y-m-d' ) );
										?>
										<tr>
											<td><strong><?php echo esc_html( stripslashes( $l->tool_name ) ); ?></strong><br><span style="color:#999;"><?php echo esc_html( stripslashes( $l->barcode ) ); ?></span></td>
											<td><?php echo mtl_format_date( $l->loan_date ); ?></td>
											<td><?php echo mtl_format_date( $l->due_date ); ?></td>
											<td>
												<?php if ( $l->return_date ) : ?>
													<?php echo mtl_format_date( $l->return_date ); ?><?php echo $late ? ' <span class="mtl-overdue-days">(late)</span>' : ''; ?>
												<?php elseif ( $out_overdue ) : ?>
													<span class="mtl-overdue-days">Still out, overdue</span>
												<?php else : ?>
													Still out
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>

					<?php
					$mh_active_res = 0;
					foreach ( $mh_reservations as $r ) {
						if ( ! $r->expiry_date ) {
							++$mh_active_res;
						}
					}
					?>
					<p class="mtl-panel-sub" style="margin-top: 16px;">Reservations &mdash; <strong><?php echo esc_html( count( $mh_reservations ) ); ?></strong> on record<?php echo $mh_active_res > 0 ? ', <strong>' . esc_html( $mh_active_res ) . '</strong> active' : ''; ?>.</p>
					<?php if ( empty( $mh_reservations ) ) : ?>
						<p class="mtl-empty">This member has no reservations on record.</p>
					<?php else : ?>
						<div class="mtl-scroll-table">
							<table>
								<thead>
									<tr>
										<th>Tool</th>
										<th>Reserved</th>
										<th>Status</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $mh_reservations as $r ) : ?>
										<tr>
											<td><?php echo esc_html( stripslashes( $r->tool_name ) ); ?><br><span style="color:#999;"><?php echo esc_html( stripslashes( $r->barcode ) ); ?></span></td>
											<td><?php echo mtl_format_date( $r->reservation_date ); ?></td>
											<td><?php echo $r->expiry_date ? 'Closed ' . mtl_format_date( $r->expiry_date ) : '<span class="mtl-ok-pill">Active</span>'; ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				<?php elseif ( $mh_member_id > 0 ) : ?>
					<p class="mtl-empty">That member could not be found.</p>
				<?php else : ?>
					<p class="mtl-empty">Search for a member above to see their full loan history.</p>
				<?php endif; ?>
				<?php
				break;
		}

		echo '</div>'; // .mtl-panel-body
		echo '</section>';
	}

	echo '</div>'; // .mtl-dash-grid
	?>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			const grid = document.getElementById('mtl-dash-grid');
			const saveBtn = document.getElementById('mtl-save-layout-btn');
			const layoutInput = document.getElementById('mtl-layout-json');
			const sizes = ['small', 'medium', 'large'];

			function markDirty() {
				saveBtn.classList.add('mtl-dirty');
				saveBtn.textContent = 'Save Layout *';
			}

			// Serializes the current DOM state (order, size, visibility) into
			// the hidden field the Save Layout form posts. Runs on submit so
			// the server always receives exactly what is on screen.
			function serializeLayout() {
				const layout = {};
				grid.querySelectorAll('.mtl-panel').forEach(function(panel, i) {
					layout[panel.dataset.panel] = {
						order: i,
						size: panel.dataset.size,
						visible: !panel.classList.contains('mtl-hidden')
					};
				});
				layoutInput.value = JSON.stringify(layout);
			}

			document.getElementById('mtl-layout-form').addEventListener('submit', serializeLayout);

			// --- Resize (cycle small -> medium -> large) ---
			grid.addEventListener('click', function(e) {
				const resizeBtn = e.target.closest('.mtl-resize-btn');
				if (resizeBtn) {
					const panel = resizeBtn.closest('.mtl-panel');
					const next = sizes[(sizes.indexOf(panel.dataset.size) + 1) % sizes.length];
					panel.classList.remove('mtl-size-' + panel.dataset.size);
					panel.classList.add('mtl-size-' + next);
					panel.dataset.size = next;
					markDirty();
					return;
				}

				const hideBtn = e.target.closest('.mtl-hide-btn');
				if (hideBtn) {
					const panel = hideBtn.closest('.mtl-panel');
					panel.classList.add('mtl-hidden');
					const toggle = document.querySelector('.mtl-panel-toggle[data-panel="' + panel.dataset.panel + '"]');
					if (toggle) toggle.checked = false;
					markDirty();
				}
			});

			// --- Show/hide via the Panels menu ---
			document.querySelectorAll('.mtl-panel-toggle').forEach(function(toggle) {
				toggle.addEventListener('change', function() {
					const panel = grid.querySelector('.mtl-panel[data-panel="' + toggle.dataset.panel + '"]');
					if (panel) {
						panel.classList.toggle('mtl-hidden', !toggle.checked);
						markDirty();
					}
				});
			});

			// --- Drag & drop rearranging ---
			// Panels become draggable only while the pointer is on the header,
			// so text selection and clicks inside panel bodies stay normal.
			let dragged = null;

			grid.querySelectorAll('.mtl-panel').forEach(function(panel) {
				const head = panel.querySelector('.mtl-panel-head');

				head.addEventListener('mousedown', function() {
					panel.setAttribute('draggable', 'true');
				});

				panel.addEventListener('dragstart', function(e) {
					dragged = panel;
					panel.classList.add('mtl-dragging');
					e.dataTransfer.effectAllowed = 'move';
				});

				panel.addEventListener('dragend', function() {
					panel.classList.remove('mtl-dragging');
					panel.removeAttribute('draggable');
					dragged = null;
				});

				panel.addEventListener('dragover', function(e) {
					if (!dragged || dragged === panel) return;
					e.preventDefault();
					const rect = panel.getBoundingClientRect();
					// Insert before or after depending on which half of the
					// hovered panel the pointer is in.
					const before = (e.clientY - rect.top) < rect.height / 2;
					if (before) {
						grid.insertBefore(dragged, panel);
					} else {
						grid.insertBefore(dragged, panel.nextSibling);
					}
				});
			});

			grid.addEventListener('drop', function(e) {
				e.preventDefault();
				markDirty();
			});

			grid.addEventListener('dragover', function(e) {
				e.preventDefault();
			});
		});
	</script>

	<script>
		// ---- Tool History / Member History lookup autocompletes ----
		document.addEventListener('DOMContentLoaded', function() {
			<?php
			// JSON_HEX_TAG/AMP/APOS/QUOT so a tool/member name containing
					// "</script>" or quotes can't break out of this inline script.
			?>
			const dashToolOptions   = <?php echo wp_json_encode( $dash_tool_options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
			const dashMemberOptions = <?php echo wp_json_encode( $dash_member_options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;

			function mtlDashSetupLookup(inputId, dropdownId, hiddenId, options) {
				const input = document.getElementById(inputId);
				const dropdown = document.getElementById(dropdownId);
				const hidden = document.getElementById(hiddenId);
				if (!input || !dropdown || !hidden) return;

				function hide() {
					dropdown.style.display = 'none';
					dropdown.innerHTML = '';
				}

				function render(matches) {
					if (matches.length === 0) {
						dropdown.innerHTML = '<div class="mtl-dash-empty-option">No matches</div>';
						dropdown.style.display = 'block';
						return;
					}
					dropdown.innerHTML = '';
					matches.forEach(function(opt) {
						const row = document.createElement('div');
						row.className = 'mtl-dash-option';
						const name = document.createElement('span');
						name.textContent = opt.name + ' ';
						const sub = document.createElement('span');
						sub.className = 'mtl-dash-option-sub';
						sub.textContent = '(' + opt.sub + ')';
						row.appendChild(name);
						row.appendChild(sub);
						// mousedown (not click) so it fires before the input's blur.
						row.addEventListener('mousedown', function(e) {
							e.preventDefault();
							hidden.value = opt.id;
							input.value = opt.label;
							hide();
						});
						dropdown.appendChild(row);
					});
					dropdown.style.display = 'block';
				}

				input.addEventListener('input', function() {
					// Editing the text invalidates any previously picked id.
					hidden.value = '';
					const q = this.value.trim().toLowerCase();
					if (!q) {
						hide();
						return;
					}
					render(options.filter(function(opt) {
						return opt.search.indexOf(q) !== -1;
					}).slice(0, 8));
				});

				input.addEventListener('focus', function() {
					if (this.value.trim() && !hidden.value) {
						this.dispatchEvent(new Event('input'));
					}
				});

				document.addEventListener('click', function(e) {
					if (!e.target.closest('#' + inputId) && !e.target.closest('#' + dropdownId)) {
						hide();
					}
				});
			}

			mtlDashSetupLookup('mtl-th-search', 'mtl-th-dropdown', 'mtl-th-tool-id', dashToolOptions);
			mtlDashSetupLookup('mtl-mh-search', 'mtl-mh-dropdown', 'mtl-mh-member-id', dashMemberOptions);
		});
	</script>
	<?php
	echo '</div>'; // .mtl-admin-wrapper
}
