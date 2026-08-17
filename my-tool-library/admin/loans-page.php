<?php
/**
 * Loans & Reservations admin page.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a full display name from a member row's first/last name.
 *
 * @param string $first First name.
 * @param string $last  Last name.
 * @return string
 */
function mtl_lr_name( $first, $last ) {
	return trim( stripslashes( (string) $first ) . ' ' . stripslashes( (string) $last ) );
}

/**
 * Builds a labeled field row for the detail box.
 *
 * @param string $label      Field label.
 * @param string $value_html Already-escaped HTML value.
 * @return string
 */
function mtl_lr_field( $label, $value_html ) {
	return '<div class="mtl-detail-field"><span class="mtl-detail-label">'
		. esc_html( $label ) . '</span><span class="mtl-detail-value">'
		. $value_html . '</span></div>';
}

/**
 * Thin wrapper around mtl_format_date() so this page's date fields stay in
 * sync with the site-wide MM/DD/YYYY convention.
 *
 * @param string $value  Any date/datetime string MySQL would return.
 * @param string $format PHP date() format.
 * @return string
 */
function mtl_lr_fmt( $value, $format = 'm/d/Y' ) {
	return mtl_format_date( $value, $format );
}

/**
 * Builds the right-hand detail-box HTML for a single normalized record.
 * Every interpolated value is escaped here, so the result is safe to echo verbatim.
 *
 * @param array  $rec          Normalized loan/reservation record.
 * @param string $nonce_field  Pre-built hidden nonce input HTML.
 * @param string $default_due  Default due date for the checkout form.
 * @param int    $default_days Default loan length, in days.
 * @return string
 */
function mtl_lr_detail_html( $rec, $nonce_field = '', $default_due = '', $default_days = 21 ) {
	$html  = '<div class="mtl-detail-headline"><span class="mtl-lr-pill ' . esc_attr( $rec['status_class'] ) . '">' . esc_html( $rec['status_label'] ) . '</span></div>';
	$html .= '<h3 class="mtl-detail-tool">' . esc_html( $rec['tool_name'] ) . '</h3>';
	$html .= '<p class="mtl-detail-sub">' . esc_html( $rec['barcode'] );
	if ( '' !== $rec['brand'] ) {
		$html .= ' &bull; ' . esc_html( $rec['brand'] );
	}
	$html .= '</p>';

	$html .= mtl_lr_field( 'Member', esc_html( $rec['member_name'] ) );
	$html .= mtl_lr_field( 'Email', esc_html( $rec['member_email'] ) );
	$html .= mtl_lr_field( 'Phone', esc_html( $rec['member_phone'] ) );

	// Verification status matters when an admin is approving a checkout in
	// person, so surface it on reservations and active loans.
	if ( 'reservation' === $rec['type'] || 'current' === $rec['type'] ) {
		$html .= mtl_lr_field(
			'ID verified',
			! empty( $rec['is_verified'] )
			? '<span style="color:#1e7e34;font-weight:600;">Verified</span>'
			: '<span style="color:#b45309;font-weight:600;">Not verified</span>'
		);
	}

	if ( 'reservation' === $rec['type'] ) {
		$html .= mtl_lr_field( 'Reserved', mtl_lr_fmt( $rec['reserved_at'], 'm/d/Y H:i' ) );
		$html .= mtl_lr_field( 'Queue place', esc_html( '#' . $rec['queue_place'] . ' of ' . $rec['queue_size'] ) );
		if ( '' !== $rec['current_loan_due'] ) {
			$html .= mtl_lr_field( 'Tool status', 'On loan to another member, due ' . mtl_lr_fmt( $rec['current_loan_due'] ) );
		} else {
			$html .= mtl_lr_field( 'Tool status', 'Available, not currently on loan' );
		}
		// Only a collectable reservation has a hold period running; anyone
		// still queued behind a loan has no deadline yet.
		if ( '' !== $rec['collect_by'] ) {
			$html .= mtl_lr_field( 'Collect by', mtl_lr_fmt( $rec['collect_by'] ) . ' <span style="color:#646970;">(auto-cancels after this)</span>' );
		}
	} elseif ( 'current' === $rec['type'] ) {
		$html .= mtl_lr_field( 'On loan since', mtl_lr_fmt( $rec['loan_date'], 'm/d/Y H:i' ) );
		$html .= mtl_lr_field( 'Due date', mtl_lr_fmt( $rec['due_date'] ) );
		if ( $rec['overdue'] ) {
			$d     = (int) $rec['days_past_due'];
			$html .= mtl_lr_field( 'Status', '<span style="color:#b32d2e;font-weight:600;">Overdue by ' . esc_html( $d . ' day' . ( 1 === $d ? '' : 's' ) ) . '</span>' );
		} else {
			$days  = -( (int) $rec['days_past_due'] );
			$txt   = 0 === $days ? 'Due today' : ( 'Due in ' . $days . ' day' . ( 1 === $days ? '' : 's' ) );
			$html .= mtl_lr_field( 'Status', esc_html( $txt ) );
		}
	} else { // Previous.
		$html .= mtl_lr_field( 'On loan', mtl_lr_fmt( $rec['loan_date'], 'm/d/Y H:i' ) );
		$html .= mtl_lr_field( 'Due date', mtl_lr_fmt( $rec['due_date'] ) );
		$html .= mtl_lr_field( 'Returned', mtl_lr_fmt( $rec['return_date'], 'm/d/Y H:i' ) );
		$html .= mtl_lr_field(
			'Status',
			$rec['returned_late']
			? '<span style="color:#b45309;">Returned late</span>'
			: 'Returned on time'
		);
	}

	// Tool-level totals, not totals for this member or single record.
	$html .= '<p class="mtl-detail-section">This tool</p>';
	$html .= mtl_lr_field( 'Total loans (all time)', esc_html( (string) $rec['tool_total_loans'] ) );
	$html .= mtl_lr_field( 'Active reservations', esc_html( (string) $rec['tool_active_reservations'] ) );

	// --- Admin actions ---
	// Each form posts back to this page (handled by mtl_lr_handle_actions).
	// Forms live inside the hidden per-record detail source and are copied
	// verbatim into the detail box on row-click, so they submit as-is.
	if ( 'reservation' === $rec['type'] ) {
		$html .= '<p class="mtl-detail-section">Actions</p>';

		if ( '' === $rec['current_loan_due'] ) {
			// Available tools can be checked out to this member even if they
			// are not first in line; the admin decides in person.
			$html .= '<form method="post" action="" class="mtl-lr-action-form">';
			$html .= $nonce_field;
			$html .= '<input type="hidden" name="mtl_lr_action" value="checkout">';
			$html .= '<input type="hidden" name="reservation_id" value="' . (int) $rec['reservation_id'] . '">';
			$html .= '<label class="mtl-lr-action-label">Due date</label>';
			// Quick-select buttons set the date field via JS (mtl_lr_set_due,
			// global since this HTML is injected with innerHTML). The
			// configured default day count starts active to match $default_due.
			$html .= '<div class="mtl-lr-due-quick">';
			foreach ( array( 7, 14, 21, 30 ) as $days_option ) {
				$is_default = ( $default_days === $days_option );
				$html      .= '<button type="button" class="button button-small' . ( $is_default ? ' mtl-lr-due-quick-active' : '' ) . '" onclick="mtl_lr_set_due(this, ' . (int) $days_option . ')">' . (int) $days_option . ' days</button>';
			}
			$html .= '</div>';
			$html .= '<input type="date" name="due_date" value="' . esc_attr( $default_due ) . '" min="' . esc_attr( gmdate( 'Y-m-d' ) ) . '" required>';
			$html .= '<button type="submit" class="button button-primary">Check out to this member</button>';
			$html .= '</form>';
		} else {
			$html .= '<p class="mtl-lr-action-note">This tool is currently on loan. End that loan before checking it out to a new member.</p>';
		}

		$html .= '<form method="post" action="" class="mtl-lr-action-form" onsubmit="return confirm(\'Cancel this reservation? This ends it and removes it from the member list.\');">';
		$html .= $nonce_field;
		$html .= '<input type="hidden" name="mtl_lr_action" value="cancel_reservation">';
		$html .= '<input type="hidden" name="reservation_id" value="' . (int) $rec['reservation_id'] . '">';
		$html .= '<button type="submit" class="button mtl-lr-danger">Cancel reservation</button>';
		$html .= '</form>';
	} elseif ( 'current' === $rec['type'] ) {
		$html .= '<p class="mtl-detail-section">Actions</p>';

		// Renew pre-fills the loan's CURRENT due date (not a fresh default), so
		// submitting untouched is a no-op rather than silently changing the
		// loan. No quick button starts "active" for the same reason.
		$html .= '<form method="post" action="" class="mtl-lr-action-form">';
		$html .= $nonce_field;
		$html .= '<input type="hidden" name="mtl_lr_action" value="renew_loan">';
		$html .= '<input type="hidden" name="loan_id" value="' . (int) $rec['loan_id'] . '">';
		$html .= '<label class="mtl-lr-action-label">New due date</label>';
		$html .= '<div class="mtl-lr-due-quick">';
		foreach ( array( 7, 14, 21, 30 ) as $days_option ) {
			$html .= '<button type="button" class="button button-small" onclick="mtl_lr_set_due(this, ' . (int) $days_option . ')">' . (int) $days_option . ' days</button>';
		}
		$html .= '</div>';
		$html .= '<input type="date" name="due_date" value="' . esc_attr( $rec['due_date'] ) . '" min="' . esc_attr( gmdate( 'Y-m-d' ) ) . '" required>';
		$html .= '<button type="submit" class="button button-primary">Renew loan</button>';
		$html .= '</form>';

		// The return date defaults to today; backdating it is for staff working
		// through a backlog of drop-offs (see mtl_resolve_return_timestamp()).
		$html .= '<form method="post" action="" class="mtl-lr-action-form" onsubmit="return confirm(\'Mark this tool as returned? This ends the loan.\');">';
		$html .= $nonce_field;
		$html .= '<input type="hidden" name="mtl_lr_action" value="end_loan">';
		$html .= '<input type="hidden" name="loan_id" value="' . (int) $rec['loan_id'] . '">';
		$html .= mtl_return_date_field_html( $rec['loan_date'] );
		$html .= '<button type="submit" class="button button-primary">End loan (mark returned)</button>';
		$html .= '</form>';
	}

	return $html;
}

/**
 * Request-scoped state passed from the Bulk Checkout handler to the renderer,
 * held in a prefixed global.
 *
 * The page renders straight after handling the POST rather than redirecting, so
 * a refused batch has to reopen the modal with every cell as the staff member
 * left it. `rows` carries the per-row complaint, keyed by row index, so the
 * offending rows can be marked rather than just counted.
 *
 * @return array{open:bool, rows:array<int,string>}
 */
function &mtl_bulk_checkout_state() {
	if ( ! isset( $GLOBALS['mtl_bulk_checkout_state'] ) ) {
		$GLOBALS['mtl_bulk_checkout_state'] = array(
			'open' => false,
			'rows' => array(),
		);
	}
	return $GLOBALS['mtl_bulk_checkout_state'];
}

/**
 * Loans or reserves a column of tools to one member in a single action.
 *
 * Validates EVERY row before writing ANY of them. A batch is one decision by
 * the staff member, so committing three of five tools and reporting a failure
 * would leave them reconciling what actually happened against a counter.
 * Nothing is written unless the whole batch passes.
 *
 * Three outcomes per row, not two: act, block, or skip. A skip is a row that
 * asks for something the member already has, and it neither writes nor refuses.
 * See mtl_tool_row_status() for which state produces which.
 *
 * Between validating and writing, another staff member could take one of these
 * tools. The window is milliseconds and both writers re-check the condition
 * that would corrupt the table, so the worst case is a partial batch reported
 * honestly rather than a bad row.
 *
 * @return string Admin-notice HTML.
 */
function mtl_lr_handle_bulk_checkout() {
	global $wpdb;

	$state         = &mtl_bulk_checkout_state();
	$state['open'] = true;

	// The nonce and the capability are both verified by mtl_lr_handle_actions()
	// before it dispatches here, which phpcs cannot follow across the call. Each
	// array's members are sanitized individually below, since sanitizing a whole
	// posted array in one call is not something the sniff recognises either.
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$member_id = isset( $_POST['bulk_member_id'] ) ? (int) $_POST['bulk_member_id'] : 0;
	$barcodes  = isset( $_POST['bulk_barcode'] ) ? (array) wp_unslash( $_POST['bulk_barcode'] ) : array();
	$reserves  = isset( $_POST['bulk_reserve'] ) ? (array) wp_unslash( $_POST['bulk_reserve'] ) : array();
	$dues      = isset( $_POST['bulk_due'] ) ? (array) wp_unslash( $_POST['bulk_due'] ) : array();
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	$tbl_members   = $wpdb->prefix . 'members';
	$tbl_inventory = $wpdb->prefix . 'tool_inventory';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	$member = $member_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT member_id, first_name, last_name FROM {$tbl_members} WHERE member_id = %d", $member_id ) ) : null;
	if ( ! $member ) {
		return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was checked out.</strong> Pick a member from the list first.</p></div>';
	}

	$today   = gmdate( 'Y-m-d' );
	$plan    = array();
	$errors  = array();
	$seen    = array();
	$jumped  = array();
	$skipped = 0;

	foreach ( $barcodes as $index => $raw_barcode ) {
		$row_no  = (int) $index + 1;
		$barcode = sanitize_text_field( (string) $raw_barcode );
		if ( '' === $barcode ) {
			continue;
		}

		$reserve = ! empty( $reserves[ $index ] );
		$tool_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT tool_id FROM {$tbl_inventory} WHERE barcode = %s", $barcode ) );
		$status  = mtl_tool_row_status( $tool_id, $member_id );

		if ( ! $status['found'] ) {
			$errors[]                = sprintf( 'Row %1$d: no tool matches barcode &ldquo;%2$s&rdquo;.', $row_no, esc_html( $barcode ) );
			$state['rows'][ $index ] = 'No tool matches that barcode.';
			continue;
		}

		// One physical tool cannot be handled twice in one batch, whichever
		// action each row asks for.
		if ( isset( $seen[ $tool_id ] ) ) {
			$errors[]                = sprintf( 'Row %1$d: %2$s is already on row %3$d.', $row_no, esc_html( $status['tool_name'] ), (int) $seen[ $tool_id ] );
			$state['rows'][ $index ] = 'Already entered on another row.';
			continue;
		}
		$seen[ $tool_id ] = $row_no;

		if ( $reserve ) {
			if ( '' !== $status['reserve_skip'] ) {
				++$skipped;
				continue;
			}
			if ( ! $status['can_reserve'] ) {
				$errors[]                = sprintf( 'Row %1$d: %2$s cannot be reserved. %3$s', $row_no, esc_html( $status['tool_name'] ), esc_html( $status['reserve_blocker'] ) );
				$state['rows'][ $index ] = $status['reserve_blocker'];
				continue;
			}
			$plan[] = array(
				'act'  => 'reserve',
				'tool' => $tool_id,
				'name' => $status['tool_name'],
			);
			continue;
		}

		if ( ! $status['can_loan'] ) {
			$errors[]                = sprintf( 'Row %1$d: %2$s cannot be loaned. %3$s', $row_no, esc_html( $status['tool_name'] ), esc_html( $status['loan_blocker'] ) );
			$state['rows'][ $index ] = $status['loan_blocker'];
			continue;
		}

		// A malformed date falls back to the configured length, matching the
		// single-reservation checkout above; a past one is refused outright.
		$due = isset( $dues[ $index ] ) ? sanitize_text_field( (string) $dues[ $index ] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) || ! strtotime( $due ) ) {
			$due = gmdate( 'Y-m-d', strtotime( '+' . (int) get_option( 'mtl_default_loan_days', 21 ) . ' days' ) );
		} elseif ( $due < $today ) {
			$errors[]                = sprintf( 'Row %1$d: the due date for %2$s is in the past.', $row_no, esc_html( $status['tool_name'] ) );
			$state['rows'][ $index ] = 'The due date cannot be in the past.';
			continue;
		}

		if ( '' !== $status['loan_warning'] ) {
			$jumped[] = $status['tool_name'];
		}

		$plan[] = array(
			'act'  => 'loan',
			'tool' => $tool_id,
			'name' => $status['tool_name'],
			'due'  => $due,
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $errors ) {
		return '<div class="notice notice-error is-dismissible"><p><strong>Nothing was checked out.</strong> Fix the rows below and try again.</p><ul style="list-style: disc; margin-left: 20px;"><li>'
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each message is assembled from literals and esc_html()'d values above.
			. implode( '</li><li>', $errors ) . '</li></ul></div>';
	}

	if ( ! $plan ) {
		$nothing = $skipped > 0
			? 'Every row asked for something this member already has, so nothing needed doing.'
			: 'Enter at least one barcode first.';
		return '<div class="notice notice-warning is-dismissible"><p><strong>Nothing was checked out.</strong> ' . esc_html( $nothing ) . '</p></div>';
	}

	$loaned   = 0;
	$reserved = 0;
	$failed   = array();
	foreach ( $plan as $item ) {
		if ( 'loan' === $item['act'] ) {
			if ( mtl_create_loan( $item['tool'], $member_id, $item['due'] ) > 0 ) {
				++$loaned;
			} else {
				$failed[] = $item['name'];
			}
		} elseif ( mtl_create_reservation( $item['tool'], $member_id ) > 0 ) {
			++$reserved;
		} else {
			$failed[] = $item['name'];
		}
	}

	$state['open'] = false;

	$member_name = trim( stripslashes( (string) $member->first_name ) . ' ' . stripslashes( (string) $member->last_name ) );
	$parts       = array();
	if ( $loaned > 0 ) {
		/* translators: %s: number of tools. */
		$parts[] = sprintf( _n( '%s tool loaned', '%s tools loaned', $loaned, 'my-tool-library' ), number_format_i18n( $loaned ) );
	}
	if ( $reserved > 0 ) {
		/* translators: %s: number of tools. */
		$parts[] = sprintf( _n( '%s tool reserved', '%s tools reserved', $reserved, 'my-tool-library' ), number_format_i18n( $reserved ) );
	}
	if ( $skipped > 0 ) {
		/* translators: %s: number of table rows. */
		$parts[] = sprintf( _n( '%s row skipped', '%s rows skipped', $skipped, 'my-tool-library' ), number_format_i18n( $skipped ) );
	}

	$notice = '<div class="notice notice-' . ( $failed ? 'warning' : 'success' ) . ' is-dismissible"><p><strong>'
		. esc_html( implode( ', ', $parts ) ) . '</strong> for ' . esc_html( $member_name ) . '.';

	if ( $jumped ) {
		$notice .= ' Reserved by another member at the time: ' . esc_html( implode( ', ', $jumped ) ) . '.';
	}
	if ( $failed ) {
		$notice .= ' <strong>Could not be recorded:</strong> ' . esc_html( implode( ', ', $failed ) )
			. '. Somebody else may have taken these while the batch was being entered.';
	}

	return $notice . '</p></div>';
}

/**
 * Handles the admin actions on this page: checking a reservation out as a
 * loan, cancelling a reservation, and ending (returning) a loan. Processes
 * the POST inline (before the page's data is queried) and returns a
 * WordPress admin-notice HTML string to echo, rather than redirecting.
 * Every mutation is nonce- and capability-guarded and carries an
 * "... IS NULL" clause, so an accidental re-submit can never double-apply.
 *
 * @return string Admin-notice HTML, or '' when there is nothing to do.
 */
function mtl_lr_handle_actions() {
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'POST' !== $request_method || ! isset( $_POST['mtl_lr_action'] ) ) {
		return '';
	}
	if ( ! mtl_can_manage_library() ) {
		return '';
	}
	if ( ! isset( $_POST['mtl_lr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_lr_nonce'] ) ), 'mtl_lr_action' ) ) {
		return '<div class="notice notice-error is-dismissible"><p><strong>Security check failed.</strong> Please try again.</p></div>';
	}

	global $wpdb;
	$tbl_loans        = $wpdb->prefix . 'loans';
	$tbl_reservations = $wpdb->prefix . 'tool_reservations';
	$tbl_inventory    = $wpdb->prefix . 'tool_inventory';

	$action = sanitize_key( wp_unslash( $_POST['mtl_lr_action'] ) );
	// Full timestamp: writes the TIMESTAMP columns (loan_date/expiry_date/
	// return_date) with the exact moment; the due_date fallback below still
	// re-formats it to a plain Y-m-d via strtotime().
	$today        = current_time( 'mysql' );
	$default_days = (int) get_option( 'mtl_default_loan_days', 21 );

	if ( 'bulk' === $action ) {
		return mtl_lr_handle_bulk_checkout();
	}

	if ( 'checkout' === $action ) {
		$reservation_id = isset( $_POST['reservation_id'] ) ? (int) $_POST['reservation_id'] : 0;
		// Fall back to the Setup page's Default Loan Length if the posted
		// value isn't a clean YYYY-MM-DD date.
		$due_date = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_date ) || ! strtotime( $due_date ) ) {
			$due_date = gmdate( 'Y-m-d', strtotime( $today . ' +' . $default_days . ' days' ) );
		} elseif ( $due_date < gmdate( 'Y-m-d', strtotime( $today ) ) ) {
			return '<div class="notice notice-error is-dismissible"><p>The due date can&rsquo;t be in the past. Please pick today or a later date.</p></div>';
		}

		// Derive tool + member from the reservation itself (authoritative), and
		// only if it is still active; never trust posted tool/member ids.
		$res = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"SELECT tool_id, member_id FROM {$tbl_reservations} WHERE reservation_id = %d AND expiry_date IS NULL",
				$reservation_id
			)
		);
		if ( ! $res ) {
			return '<div class="notice notice-error is-dismissible"><p>That reservation is no longer active, so it could not be checked out.</p></div>';
		}

		// Retiring a tool auto-cancels its active reservations (see Inventory's
		// Retire action), so this should be unreachable in normal use, kept
		// as a defense-in-depth check, same as the tool-existence checks
		// elsewhere in this plugin.
		$is_retired = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"SELECT retired_at FROM {$tbl_inventory} WHERE tool_id = %d",
				(int) $res->tool_id
			)
		);
		if ( $is_retired ) {
			return '<div class="notice notice-error is-dismissible"><p>That tool is retired and can&rsquo;t be checked out.</p></div>';
		}

		// A tool is a single physical item: it can't be checked out while it is
		// already out on another loan.
		$on_loan = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"SELECT loan_id FROM {$tbl_loans} WHERE tool_id = %d AND return_date IS NULL LIMIT 1",
				(int) $res->tool_id
			)
		);
		if ( $on_loan ) {
			return '<div class="notice notice-error is-dismissible"><p>That tool is already checked out to someone else. End the current loan first.</p></div>';
		}

		// Closing this member's reservation and re-syncing the rest of the queue
		// both happen inside the writer, which is why it is shared.
		if ( mtl_create_loan( (int) $res->tool_id, (int) $res->member_id, $due_date ) <= 0 ) {
			return '<div class="notice notice-error is-dismissible"><p>Sorry, the loan could not be recorded. Please try again.</p></div>';
		}

		return '<div class="notice notice-success is-dismissible"><p><strong>Checked out.</strong> The tool is on loan, due ' . mtl_format_date( $due_date ) . ', and the member&rsquo;s reservation has been closed.</p></div>';
	}

	if ( 'cancel_reservation' === $action ) {
		$reservation_id = isset( $_POST['reservation_id'] ) ? (int) $_POST['reservation_id'] : 0;
		// Read before the cancel, while the row still matches.
		$cancel_tool_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"SELECT tool_id FROM {$tbl_reservations} WHERE reservation_id = %d AND expiry_date IS NULL",
				$reservation_id
			)
		);
		$done = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"UPDATE {$tbl_reservations} SET expiry_date = %s WHERE reservation_id = %d AND expiry_date IS NULL",
				$today,
				$reservation_id
			)
		);
		if ( $done ) {
			// Whoever was behind them may now be at the front.
			mtl_sync_reservation_readiness( $cancel_tool_id );
			return '<div class="notice notice-success is-dismissible"><p><strong>Reservation cancelled.</strong></p></div>';
		}
		return '<div class="notice notice-error is-dismissible"><p>That reservation could not be cancelled (it may already be closed).</p></div>';
	}

	if ( 'renew_loan' === $action ) {
		$loan_id = isset( $_POST['loan_id'] ) ? (int) $_POST['loan_id'] : 0;
		// Same validation/fallback as checkout's due date.
		$due_date = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_date ) || ! strtotime( $due_date ) ) {
			$due_date = gmdate( 'Y-m-d', strtotime( $today . ' +' . $default_days . ' days' ) );
		} elseif ( $due_date < gmdate( 'Y-m-d', strtotime( $today ) ) ) {
			return '<div class="notice notice-error is-dismissible"><p>The due date can&rsquo;t be in the past. Please pick today or a later date.</p></div>';
		}

		$done = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"UPDATE {$tbl_loans} SET due_date = %s WHERE loan_id = %d AND return_date IS NULL",
				$due_date,
				$loan_id
			)
		);
		if ( $done ) {
			return '<div class="notice notice-success is-dismissible"><p><strong>Loan renewed.</strong> New due date: ' . mtl_format_date( $due_date ) . '.</p></div>';
		}
		return '<div class="notice notice-error is-dismissible"><p>That loan could not be renewed (it may already be returned).</p></div>';
	}

	if ( 'end_loan' === $action ) {
		$loan_id     = isset( $_POST['loan_id'] ) ? (int) $_POST['loan_id'] : 0;
		$end_tool_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"SELECT tool_id FROM {$tbl_loans} WHERE loan_id = %d",
				$loan_id
			)
		);
		// Today unless the form backdated it, validated (and, if backdated,
		// dated) by the shared helper.
		$end_return = mtl_resolve_return_timestamp(
			$loan_id,
			isset( $_POST['return_date'] ) ? sanitize_text_field( wp_unslash( $_POST['return_date'] ) ) : ''
		);
		if ( '' !== $end_return['error'] ) {
			return '<div class="notice notice-error is-dismissible"><p>' . $end_return['error'] . '</p></div>';
		}
		$done = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				"UPDATE {$tbl_loans} SET return_date = %s WHERE loan_id = %d AND return_date IS NULL",
				$end_return['timestamp'],
				$loan_id
			)
		);
		if ( $done ) {
			// Tool is back on the shelf, so the front of its queue becomes
			// collectable. Their hold period starts now even on a backdated
			// return: nobody could have collected it while it sat unprocessed.
			mtl_sync_reservation_readiness( $end_tool_id );
			$end_note = $end_return['backdated']
				? ' Recorded as returned on ' . mtl_format_date( $end_return['timestamp'] ) . '.'
				: '';
			return '<div class="notice notice-success is-dismissible"><p><strong>Loan ended.</strong> The tool is now back in inventory.' . $end_note . '</p></div>';
		}
		return '<div class="notice notice-error is-dismissible"><p>That loan could not be ended (it may already be returned).</p></div>';
	}

	return '';
}

/**
 * Renders the Loans & Reservations admin page.
 */
function mtl_render_loans_page() {
	global $wpdb;

	if ( ! mtl_can_manage_library() ) {
		return;
	}

	// Process any submitted action first, so the queries below see the result.
	$action_notice = mtl_lr_handle_actions();

	$tbl_members       = $wpdb->prefix . 'members';
	$tbl_inventory     = $wpdb->prefix . 'tool_inventory';
	$tbl_loans         = $wpdb->prefix . 'loans';
	$tbl_reservations  = $wpdb->prefix . 'tool_reservations';
	$tbl_verifications = $wpdb->prefix . 'member_verifications';

	echo '<div class="wrap mtl-admin-wrapper">';
	echo '<h2>Loans &amp; Reservations</h2>';
	echo '<p style="margin: 0 0 6px 0;"><button type="button" class="button button-primary" id="mtl-bc-open">Bulk checkout</button></p>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built, escaped HTML from mtl_lr_handle_actions().
	echo $action_notice;

	// --- Loans (current + previous) ---
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only ($wpdb->prefix-derived), no request-derived data.
	$loan_rows = $wpdb->get_results(
		"
        SELECT l.loan_id, l.tool_id, l.member_id, l.loan_date, l.due_date, l.return_date,
               t.tool_name, t.barcode, t.brand,
               m.first_name, m.last_name, m.email, m.phone_number,
               DATEDIFF(CURDATE(), l.due_date) AS days_past_due,
               (SELECT COUNT(*) FROM {$tbl_verifications} v WHERE v.member_id = l.member_id AND v.photo_id_scan_url IS NOT NULL AND v.address_proof_scan_url IS NOT NULL) AS is_verified
        FROM {$tbl_loans} l
        JOIN {$tbl_inventory} t ON t.tool_id = l.tool_id
        JOIN {$tbl_members} m ON m.member_id = l.member_id
    "
	);

	// --- Active reservations, with queue position + size derived on the fly ---
	// queue_place counts reservations for the same tool ahead in line (earlier
	// reservation_date, ties broken by reservation_id). current_loan_due is the
	// due date of the tool's active loan, if any.
	$res_rows = $wpdb->get_results(
		"
        SELECT r.reservation_id, r.tool_id, r.member_id, r.reservation_date, r.ready_since,
               t.tool_name, t.barcode, t.brand,
               m.first_name, m.last_name, m.email, m.phone_number,
               (SELECT COUNT(*) FROM {$tbl_verifications} v WHERE v.member_id = r.member_id AND v.photo_id_scan_url IS NOT NULL AND v.address_proof_scan_url IS NOT NULL) AS is_verified,
               (SELECT COUNT(*) FROM {$tbl_reservations} r2
                  WHERE r2.tool_id = r.tool_id
                    AND r2.expiry_date IS NULL
                    AND (r2.reservation_date < r.reservation_date
                         OR (r2.reservation_date = r.reservation_date AND r2.reservation_id <= r.reservation_id))
               ) AS queue_place,
               (SELECT COUNT(*) FROM {$tbl_reservations} r3
                  WHERE r3.tool_id = r.tool_id AND r3.expiry_date IS NULL
               ) AS queue_size,
               (SELECT l.due_date FROM {$tbl_loans} l
                  WHERE l.tool_id = r.tool_id AND l.return_date IS NULL
                  ORDER BY l.due_date DESC LIMIT 1
               ) AS current_loan_due
        FROM {$tbl_reservations} r
        JOIN {$tbl_inventory} t ON t.tool_id = r.tool_id
        JOIN {$tbl_members} m ON m.member_id = r.member_id
        WHERE r.expiry_date IS NULL
        ORDER BY t.tool_name ASC, r.reservation_date ASC
    "
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// --- Per-tool activity totals ---
	// Aggregates a tool's full history (across all members) for the detail
	// box, keyed by tool_id for O(1) lookup below.
	$tool_total_loans = array();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	foreach ( $wpdb->get_results( "SELECT tool_id, COUNT(*) AS total FROM {$tbl_loans} GROUP BY tool_id" ) as $row ) {
		$tool_total_loans[ (int) $row->tool_id ] = (int) $row->total;
	}

	$tool_active_res = array();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	foreach ( $wpdb->get_results( "SELECT tool_id, COUNT(*) AS total FROM {$tbl_reservations} WHERE expiry_date IS NULL GROUP BY tool_id" ) as $row ) {
		$tool_active_res[ (int) $row->tool_id ] = (int) $row->total;
	}

	// --- Normalize everything into one $records list ---
	$current_loans  = array();
	$previous_loans = array();
	foreach ( $loan_rows as $l ) {
		if ( null === $l->return_date ) {
			$current_loans[] = $l;
		} else {
			$previous_loans[] = $l;
		}
	}
	// Current loans: most overdue first. Previous loans: most recently returned first.
	usort(
		$current_loans,
		function ( $a, $b ) {
			return (int) $b->days_past_due - (int) $a->days_past_due;
		}
	);
	usort(
		$previous_loans,
		function ( $a, $b ) {
			return strcmp( (string) $b->return_date, (string) $a->return_date );
		}
	);

	$records = array();
	$idx     = 0;

	foreach ( $current_loans as $l ) {
		$overdue   = ( (int) $l->days_past_due > 0 );
		$records[] = array(
			'idx'              => $idx++,
			'type'             => 'current',
			'status_label'     => $overdue ? 'Overdue' : 'On Loan',
			'status_class'     => $overdue ? 'mtl-pill-overdue' : 'mtl-pill-loan',
			'overdue'          => $overdue,
			'tool_id'          => (int) $l->tool_id,
			'tool_name'        => stripslashes( $l->tool_name ),
			'barcode'          => stripslashes( $l->barcode ),
			'brand'            => stripslashes( (string) $l->brand ),
			'member_name'      => mtl_lr_name( $l->first_name, $l->last_name ),
			'member_email'     => $l->email,
			'member_phone'     => stripslashes( (string) $l->phone_number ),
			'reserved_at'      => '',
			'collect_by'       => '',
			'loan_date'        => $l->loan_date,
			'due_date'         => $l->due_date,
			'return_date'      => '',
			'expiry_date'      => '',
			'queue_place'      => 0,
			'queue_size'       => 0,
			'days_past_due'    => (int) $l->days_past_due,
			'days_left'        => 0,
			'loan_id'          => (int) $l->loan_id,
			'reservation_id'   => 0,
			'is_verified'      => ( (int) $l->is_verified > 0 ),
			'current_loan_due' => '',
			'returned_late'    => false,
		);
	}

	foreach ( $res_rows as $r ) {
		$tool_out  = ( null !== $r->current_loan_due );
		$records[] = array(
			'idx'              => $idx++,
			'type'             => 'reservation',
			'status_label'     => $tool_out ? 'Waiting' : 'Ready',
			'status_class'     => $tool_out ? 'mtl-pill-wait' : 'mtl-pill-ready',
			'overdue'          => false,
			'tool_id'          => (int) $r->tool_id,
			'tool_name'        => stripslashes( $r->tool_name ),
			'barcode'          => stripslashes( $r->barcode ),
			'brand'            => stripslashes( (string) $r->brand ),
			'member_name'      => mtl_lr_name( $r->first_name, $r->last_name ),
			'member_email'     => $r->email,
			'member_phone'     => stripslashes( (string) $r->phone_number ),
			'reserved_at'      => $r->reservation_date,
			'collect_by'       => mtl_reservation_collect_by( $r->ready_since ),
			'loan_date'        => '',
			'due_date'         => '',
			'return_date'      => '',
			'expiry_date'      => '',
			'queue_place'      => (int) $r->queue_place,
			'queue_size'       => (int) $r->queue_size,
			'days_past_due'    => 0,
			'days_left'        => 0,
			'loan_id'          => 0,
			'reservation_id'   => (int) $r->reservation_id,
			'is_verified'      => ( (int) $r->is_verified > 0 ),
			'current_loan_due' => ( null !== $r->current_loan_due ? $r->current_loan_due : '' ),
			'returned_late'    => false,
		);
	}

	foreach ( $previous_loans as $l ) {
		// Compare on the DATE portion only: return_date is a full timestamp
		// now, and due_date stays a plain date (implicitly midnight), so a
		// raw > comparison would wrongly flag anything returned after 00:00
		// on the due date itself as late.
		$returned_late = ( null !== $l->return_date && null !== $l->due_date && gmdate( 'Y-m-d', strtotime( $l->return_date ) ) > $l->due_date );
		$records[]     = array(
			'idx'              => $idx++,
			'type'             => 'previous',
			'status_label'     => 'Returned',
			'status_class'     => 'mtl-pill-returned',
			'overdue'          => false,
			'tool_id'          => (int) $l->tool_id,
			'tool_name'        => stripslashes( $l->tool_name ),
			'barcode'          => stripslashes( $l->barcode ),
			'brand'            => stripslashes( (string) $l->brand ),
			'member_name'      => mtl_lr_name( $l->first_name, $l->last_name ),
			'member_email'     => $l->email,
			'member_phone'     => stripslashes( (string) $l->phone_number ),
			'reserved_at'      => '',
			'collect_by'       => '',
			'loan_date'        => $l->loan_date,
			'due_date'         => $l->due_date,
			'return_date'      => $l->return_date,
			'expiry_date'      => '',
			'queue_place'      => 0,
			'queue_size'       => 0,
			'days_past_due'    => 0,
			'days_left'        => 0,
			'loan_id'          => (int) $l->loan_id,
			'reservation_id'   => 0,
			'is_verified'      => ( (int) $l->is_verified > 0 ),
			'current_loan_due' => '',
			'returned_late'    => $returned_late,
		);
	}

	// Attach tool-level totals to every record. status_rank is the Status
	// column's sort value: ascending puts the most urgent work first (overdue,
	// then out, then waiting, then ready, then finished) instead of sorting
	// the labels alphabetically.
	$status_rank_map = array(
		'Overdue'  => 0,
		'On Loan'  => 1,
		'Waiting'  => 2,
		'Ready'    => 3,
		'Returned' => 4,
	);
	foreach ( $records as &$rec_ref ) {
		$tid                                 = $rec_ref['tool_id'];
		$rec_ref['tool_total_loans']         = isset( $tool_total_loans[ $tid ] ) ? $tool_total_loans[ $tid ] : 0;
		$rec_ref['tool_active_reservations'] = isset( $tool_active_res[ $tid ] ) ? $tool_active_res[ $tid ] : 0;
		$rec_ref['status_rank']              = isset( $status_rank_map[ $rec_ref['status_label'] ] ) ? $status_rank_map[ $rec_ref['status_label'] ] : 9;
	}
	unset( $rec_ref ); // Break the reference so later loops can't clobber the last record.

	// Counts for the one-click view buttons.
	$count_all     = count( $records );
	$count_res     = 0;
	$count_loans   = 0;
	$count_overdue = 0;
	foreach ( $records as $rec ) {
		if ( 'reservation' === $rec['type'] ) {
			++$count_res;
		} else {
			++$count_loans;
		}
		if ( $rec['overdue'] ) {
			++$count_overdue;
		}
	}
	?>

	<style>
		.mtl-lr-toolbar {
			display: flex;
			justify-content: space-between;
			align-items: flex-end;
			flex-wrap: wrap;
			gap: 10px;
			margin: 18px 0 10px 0;
		}

		.mtl-lr-views {
			display: flex;
			gap: 6px;
			flex-wrap: wrap;
		}

		.mtl-lr-view-btn {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 999px;
			padding: 5px 14px;
			cursor: pointer;
			font-size: 0.9em;
			color: #3c434a;
		}

		.mtl-lr-view-btn:hover {
			border-color: #8c8f94;
		}

		.mtl-lr-view-active {
			background: var(--mtl-header-color, #ff6600);
			border-color: var(--mtl-header-color, #ff6600);
			color: #fff;
			font-weight: 600;
		}

		.mtl-lr-view-btn .mtl-lr-count {
			opacity: 0.7;
			font-size: 0.85em;
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

		/*
		 * Table on the left, detail box on the right, resizable with pure
		 * CSS. The table uses the native `resize: horizontal` handle; the
		 * detail column is a plain flex-fill sibling (`flex: 1 1 auto`) that
		 * automatically takes up whatever space the table doesn't.
		 */
		.mtl-lr-layout {
			display: flex;
			gap: 20px;
			align-items: flex-start;
		}

		.mtl-lr-main {
			flex: 0 0 auto;
			width: 64%;
			min-width: 480px;
			max-width: 82%;
			resize: horizontal;
			overflow: auto;
			/* A visible edge makes the draggable boundary (and its native
				resize grip, bottom-right) obvious without relying on JS. */
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			padding: 2px;
		}

		.mtl-lr-detail-col {
			flex: 1 1 auto;
			min-width: 280px;
			position: sticky;
			top: 40px;
		}

		@media (max-width: 1100px) {
			.mtl-lr-layout {
				flex-direction: column;
			}

			.mtl-lr-main {
				/* Stacked full width, since there is nothing to trade width with. */
				width: 100%;
				max-width: none;
				resize: none;
			}

			.mtl-lr-detail-col {
				position: static;
				width: 100%;
			}
		}

		.mtl-table-wrap {
			overflow-x: auto;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			background: #fff;
		}

		#mtl-lr-table {
			border: none;
			/* Fixed layout would otherwise squeeze 8 columns into the narrower
				left-hand column. A floor keeps them readable and lets the
				wrapper scroll horizontally on smaller screens instead. */
			min-width: 900px;
		}

		/* box-sizing makes a th's set width match its on-screen width exactly,
			and position:relative anchors the resize grip to its right edge. */
		#mtl-lr-table th {
			background: #f6f7f7;
			text-transform: uppercase;
			font-size: 0.75em;
			letter-spacing: 0.03em;
			padding: 10px 8px;
			box-sizing: border-box;
			position: relative;
		}

		#mtl-lr-table th.sortable {
			cursor: pointer;
		}

		#mtl-lr-table td {
			padding: 9px 8px;
			vertical-align: top;
			/* The table uses fixed layout, so long values must wrap rather
				than spill into the neighbouring column. */
			overflow-wrap: break-word;
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

		.mtl-lr-row {
			cursor: pointer;
		}

		.mtl-lr-row:hover {
			background-color: #f0f7fb;
		}

		.mtl-lr-row.mtl-lr-selected {
			background-color: #eaf3fa;
			box-shadow: inset 3px 0 0 var(--mtl-header-color, #ff6600);
		}

		.mtl-lr-pill {
			display: inline-block;
			border-radius: 999px;
			padding: 2px 9px;
			font-size: 0.78em;
			font-weight: 600;
			white-space: nowrap;
		}

		.mtl-pill-overdue {
			background: #fdecea;
			color: #b32d2e;
			border: 1px solid #f0b7b2;
		}

		.mtl-pill-loan {
			background: #eaf3fb;
			color: #135e96;
			border: 1px solid #b9d7ef;
		}

		.mtl-pill-wait {
			background: #fff8e5;
			color: #8a6d00;
			border: 1px solid #f0dca0;
		}

		.mtl-pill-ready {
			background: #edf7ed;
			color: #1e7e34;
			border: 1px solid #bfe3c0;
		}

		.mtl-pill-returned {
			background: #f0f0f1;
			color: #50575e;
			border: 1px solid #d5d8dc;
		}

		.mtl-lr-muted {
			color: #999;
		}

		/* Pagination bars (shared look with Inventory/Membership). */
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

		#mtl-lr-page-indicator {
			font-size: 0.9em;
			min-width: 120px;
			text-align: center;
		}

		.mtl-pagination-bar .button[disabled] {
			opacity: 0.5;
			cursor: not-allowed;
		}

		/* Detail box */
		.mtl-lr-detail-box {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
			padding: 18px 20px;
		}

		.mtl-lr-detail-empty {
			color: #8c8f94;
			text-align: center;
			padding: 30px 10px;
		}

		.mtl-detail-headline {
			margin-bottom: 8px;
		}

		.mtl-detail-tool {
			margin: 4px 0 2px 0;
		}

		.mtl-detail-sub {
			color: #787c82;
			font-size: 0.85em;
			margin: 0 0 14px 0;
		}

		.mtl-detail-section {
			margin: 16px 0 0 0;
			font-size: 0.72em;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			color: #8c8f94;
		}

		.mtl-detail-field {
			display: flex;
			justify-content: space-between;
			gap: 12px;
			padding: 6px 0;
			border-top: 1px solid #f0f2f4;
			font-size: 0.9em;
		}

		.mtl-detail-label {
			color: #787c82;
			flex: 0 0 auto;
		}

		.mtl-detail-value {
			text-align: right;
			word-break: break-word;
		}

		/* Admin action forms in the detail box (checkout / cancel / end loan). */
		.mtl-lr-action-form {
			margin: 10px 0 0 0;
		}

		.mtl-lr-action-label {
			display: block;
			font-size: 0.8em;
			font-weight: 600;
			color: #50575e;
			margin-bottom: 3px;
		}

		.mtl-lr-action-form input[type="date"] {
			margin-bottom: 8px;
		}

		.mtl-lr-due-quick {
			display: flex;
			gap: 6px;
			flex-wrap: wrap;
			margin-bottom: 8px;
		}

		.mtl-lr-due-quick-active {
			background: var(--mtl-header-color, #ff6600) !important;
			border-color: var(--mtl-header-color, #ff6600) !important;
			color: #fff !important;
		}

		.mtl-lr-action-note {
			font-size: 0.85em;
			color: #787c82;
			margin: 10px 0 0 0;
		}

		/* Destructive action, so a red outline matching the admin danger style. */
		.mtl-admin-wrapper .button.mtl-lr-danger {
			border-color: #d63638;
			color: #d63638;
		}

		.mtl-admin-wrapper .button.mtl-lr-danger:hover {
			background: #d63638;
			color: #fff;
		}

		/* Bulk Checkout. Same overlay/modal proportions as the Inventory page's
			Quick Loan, so the two read as one component; the class names are
			local because each admin page ships its own stylesheet. */
		.mtl-bc-overlay {
			position: fixed;
			inset: 0;
			background: rgba(0, 0, 0, 0.5);
			z-index: 100000;
			display: flex;
			align-items: flex-start;
			justify-content: center;
			padding: 40px 16px;
			overflow-y: auto;
		}

		.mtl-bc-modal {
			background: #fff;
			border-radius: 4px;
			padding: 22px 26px;
			width: 100%;
			max-width: 760px;
			position: relative;
			box-shadow: 0 4px 24px rgba(0, 0, 0, 0.25);
		}

		.mtl-bc-close {
			position: absolute;
			top: 10px;
			right: 12px;
			border: 0;
			background: none;
			font-size: 22px;
			line-height: 1;
			cursor: pointer;
			color: #646970;
		}

		.mtl-bc-close:hover { color: #d63638; }

		.mtl-bc-label {
			display: block;
			font-weight: 600;
			margin: 14px 0 4px;
		}

		/* The picker. These rules exist on the Inventory page too, for the
			Quick Loan version; each admin page carries its own stylesheet, so
			they are duplicated rather than shared. Without them the results
			render as unstyled text and do not read as a list you can click. */
		.mtl-bc-picker {
			position: relative;
			width: 100%;
		}

		.mtl-bc-picker input[type="text"] {
			width: 100%;
			padding: 7px 10px;
			font-size: 1em;
		}

		.mtl-bc-dropdown {
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
			max-height: 240px;
			overflow-y: auto;
		}

		.mtl-bc-option {
			padding: 7px 10px;
			cursor: pointer;
			font-size: 0.9em;
			border-top: 1px solid #f0f1f2;
			display: flex;
			justify-content: space-between;
			gap: 10px;
		}

		.mtl-bc-option:first-child { border-top: none; }

		.mtl-bc-option:hover,
		.mtl-bc-option.mtl-bc-option-active { background: #f0f7fb; }

		.mtl-bc-option-email {
			color: #787c82;
			font-size: 0.92em;
			white-space: nowrap;
		}

		.mtl-bc-empty {
			padding: 8px 10px;
			color: #787c82;
			font-size: 0.9em;
		}

		/* What staff need to know about the member before handing tools over. */
		.mtl-bc-member-info {
			margin: 8px 0 0 0;
			padding: 10px 12px;
			background: #f6f7f7;
			border-radius: 4px;
			font-size: 0.9em;
			display: none;
		}

		.mtl-bc-member-info dl {
			margin: 0;
			display: grid;
			grid-template-columns: max-content 1fr;
			gap: 3px 12px;
		}

		.mtl-bc-member-info dt {
			color: #646970;
			font-weight: 600;
		}

		.mtl-bc-member-info dd { margin: 0; }

		.mtl-bc-tool-name {
			font-size: 0.9em;
			color: #1d2327;
		}

		/* No rules anywhere in the grid: the pills carry the structure. */
		.mtl-bc-table {
			width: 100%;
			border-collapse: collapse;
			margin-top: 6px;
		}

		.mtl-bc-table th {
			text-align: left;
			font-size: 0.85em;
			color: #646970;
			font-weight: 600;
			padding: 4px 8px 4px 0;
		}

		.mtl-bc-table td {
			padding: 3px 8px 3px 0;
			border: 0;
			vertical-align: middle;
		}

		.mtl-bc-table input[type="text"] { width: 100%; }
		.mtl-bc-table input[type="date"] { width: 100%; }

		.mtl-bc-due-cell { white-space: nowrap; }
		.mtl-bc-due-cell .button { margin-left: 2px; }
		.mtl-bc-due-cell input[type="date"] { width: 140px; }

		.mtl-bc-due-active {
			border-color: #2271b1;
			box-shadow: 0 0 0 1px #2271b1;
		}

		.mtl-bc-pill {
			display: inline-block;
			padding: 1px 9px;
			border-radius: 10px;
			font-size: 0.82em;
			font-weight: 600;
			white-space: nowrap;
		}

		.mtl-bc-pill-ok     { background: #edfaef; color: #1c7c33; }
		.mtl-bc-pill-warn   { background: #fcf5e6; color: #8a6d00; }
		.mtl-bc-pill-bad    { background: #fcf0f1; color: #b32d2e; }
		.mtl-bc-pill-skip   { background: #f0f0f1; color: #646970; }
		.mtl-bc-row-note    { font-size: 0.82em; color: #b32d2e; }

		.mtl-bc-actions {
			margin-top: 18px;
			display: flex;
			gap: 8px;
			align-items: center;
		}

		.mtl-bc-summary {
			margin-left: auto;
			font-size: 0.9em;
			color: #646970;
		}
	</style>

	<div class="mtl-lr-toolbar">
		<div class="mtl-lr-views">
			<button type="button" class="mtl-lr-view-btn mtl-lr-view-active" data-view="all">All <span class="mtl-lr-count">(<?php echo (int) $count_all; ?>)</span></button>
			<button type="button" class="mtl-lr-view-btn" data-view="reservation">Reservations <span class="mtl-lr-count">(<?php echo (int) $count_res; ?>)</span></button>
			<button type="button" class="mtl-lr-view-btn" data-view="loans">Loans <span class="mtl-lr-count">(<?php echo (int) $count_loans; ?>)</span></button>
			<button type="button" class="mtl-lr-view-btn" data-view="overdue">Overdue <span class="mtl-lr-count">(<?php echo (int) $count_overdue; ?>)</span></button>
		</div>
		<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
			<input type="text" id="mtl-lr-search" placeholder="Quick filter..." style="padding: 5px 10px; width: 200px; border: 1px solid #8c8f94; border-radius: 4px;">
			<button type="button" id="mtl-lr-toggle-advanced" class="button">Advanced Search</button>
			<button type="button" id="mtl-lr-clear-filters" class="button">Clear Filters</button>
		</div>
	</div>

	<div id="mtl-lr-advanced-search" style="display: none; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px 20px; margin-bottom: 15px;">
		<div class="mtl-adv-groups">

			<fieldset class="mtl-adv-group">
				<legend>Tool &amp; Member</legend>
				<div class="mtl-adv-fields">
					<div>
						<label for="adv-lr-tool">Tool Name</label>
						<input type="text" id="adv-lr-tool">
					</div>
					<div>
						<label for="adv-lr-barcode">Barcode</label>
						<input type="text" id="adv-lr-barcode">
					</div>
					<div>
						<label for="adv-lr-member">Member Name</label>
						<input type="text" id="adv-lr-member">
					</div>
					<div>
						<label for="adv-lr-type">Record Type</label>
						<select id="adv-lr-type">
							<option value="">Any</option>
							<option value="reservation">Reservation</option>
							<option value="current">Current Loan</option>
							<option value="previous">Previous Loan</option>
						</select>
					</div>
				</div>
			</fieldset>

			<fieldset class="mtl-adv-group">
				<legend>Dates</legend>
				<div class="mtl-adv-fields">
					<div>
						<label for="adv-lr-loan-from">Loaned From</label>
						<input type="date" id="adv-lr-loan-from">
					</div>
					<div>
						<label for="adv-lr-loan-to">Loaned To</label>
						<input type="date" id="adv-lr-loan-to">
					</div>
					<div>
						<label for="adv-lr-due-from">Due From</label>
						<input type="date" id="adv-lr-due-from">
					</div>
					<div>
						<label for="adv-lr-due-to">Due To</label>
						<input type="date" id="adv-lr-due-to">
					</div>
					<div>
						<label for="adv-lr-res-from">Reserved From</label>
						<input type="date" id="adv-lr-res-from">
					</div>
					<div>
						<label for="adv-lr-res-to">Reserved To</label>
						<input type="date" id="adv-lr-res-to">
					</div>
				</div>
			</fieldset>

			<fieldset class="mtl-adv-group">
				<legend>Status</legend>
				<div class="mtl-adv-fields">
					<div>
						<label for="adv-lr-overdue">Overdue?</label>
						<select id="adv-lr-overdue">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-lr-returned-late" title="Applies to returned loans only">Returned Late?</label>
						<select id="adv-lr-returned-late">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-lr-first-queue" title="Applies to reservations only">First In Queue?</label>
						<select id="adv-lr-first-queue">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-lr-tool-on-loan" title="Applies to reservations only: is the reserved tool currently checked out?">Waiting On Tool?</label>
						<select id="adv-lr-tool-on-loan">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
				</div>
			</fieldset>

		</div>
	</div>

	<div class="mtl-lr-layout">
		<div class="mtl-lr-main">
			<?php if ( $records ) : ?>
				<div class="mtl-pagination-bar mtl-pagination-top">
					<span class="mtl-results-info" id="mtl-lr-results-info"></span>
					<label class="mtl-page-size-label">Rows per page:
						<select id="mtl-lr-page-size">
							<option value="20">20</option>
							<option value="50">50</option>
							<option value="100">100</option>
						</select>
					</label>
				</div>
			<?php endif; ?>

			<div class="mtl-table-wrap">
				<table class="wp-list-table widefat fixed striped table-view-list" id="mtl-lr-table">
					<thead>
						<tr>
							<th class="sortable" data-sort-key="statusRank" data-sort-type="num" title="Click to sort" style="width: 95px;">Status ↕</th>
							<th class="sortable" data-sort-key="barcode" data-sort-type="text" title="Click to sort">Barcode ↕</th>
							<th class="sortable" data-sort-key="tool" data-sort-type="text" title="Click to sort">Tool ↕</th>
							<th class="sortable" data-sort-key="member" data-sort-type="text" title="Click to sort">Member ↕</th>
							<th class="sortable" data-sort-key="reserved" data-sort-type="text" title="Click to sort" style="width: 105px;">Reserved ↕</th>
							<th class="sortable" data-sort-key="loan" data-sort-type="text" title="Click to sort" style="width: 105px;">On Loan ↕</th>
							<th class="sortable" data-sort-key="due" data-sort-type="text" title="Click to sort" style="width: 105px;">Due ↕</th>
							<th class="sortable" data-sort-key="queue" data-sort-type="num" title="Click to sort" style="width: 90px;">Queue ↕</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( $records ) : ?>
							<?php
							foreach ( $records as $rec ) :
								// $reserved_date keeps the full ISO timestamp for data-reserved (JS sorts
								// and filters on it); the visible cell uses the formatted display value.
								$reserved_date         = $rec['reserved_at'];
								$reserved_date_display = '' !== $reserved_date ? mtl_format_date( $reserved_date ) : '';
								?>
								<tr class="mtl-lr-row"
									data-rec="<?php echo esc_attr( $rec['idx'] ); ?>"
									data-type="<?php echo esc_attr( $rec['type'] ); ?>"
									data-overdue="<?php echo $rec['overdue'] ? '1' : '0'; ?>"
									data-barcode="<?php echo esc_attr( strtolower( $rec['barcode'] ) ); ?>"
									data-tool="<?php echo esc_attr( strtolower( $rec['tool_name'] ) ); ?>"
									data-member="<?php echo esc_attr( strtolower( $rec['member_name'] ) ); ?>"
									data-reserved="<?php echo esc_attr( $reserved_date ); ?>"
									data-loan="<?php echo esc_attr( $rec['loan_date'] ); ?>"
									data-due="<?php echo esc_attr( $rec['due_date'] ); ?>"
									data-status-rank="<?php echo esc_attr( $rec['status_rank'] ); ?>"
									<?php // Blank (not 0) for non-reservations, so they sort to the bottom. ?>
									data-queue="<?php echo 'reservation' === $rec['type'] ? esc_attr( $rec['queue_place'] ) : ''; ?>"
									<?php
									// Advanced-filter flags: left blank on record types the question
									// doesn't apply to, so a "No" filter doesn't sweep them in too.
									?>
									data-returnedlate="<?php echo 'previous' === $rec['type'] ? ( $rec['returned_late'] ? '1' : '0' ) : ''; ?>"
									data-firstinqueue="<?php echo 'reservation' === $rec['type'] ? ( 1 === (int) $rec['queue_place'] ? '1' : '0' ) : ''; ?>"
									data-toolonloan="<?php echo 'reservation' === $rec['type'] ? ( '' !== $rec['current_loan_due'] ? '1' : '0' ) : ''; ?>">
									<td><span class="mtl-lr-pill <?php echo esc_attr( $rec['status_class'] ); ?>"><?php echo esc_html( $rec['status_label'] ); ?></span></td>
									<td><?php echo esc_html( $rec['barcode'] ); ?></td>
									<td><strong><?php echo esc_html( $rec['tool_name'] ); ?></strong></td>
									<td><?php echo esc_html( $rec['member_name'] ); ?></td>
									<td><?php echo '' !== $reserved_date_display ? $reserved_date_display : '<span class="mtl-lr-muted">&mdash;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mtl_format_date() escapes internally. ?></td>
									<td><?php echo '' !== $rec['loan_date'] ? mtl_format_date( $rec['loan_date'] ) : '<span class="mtl-lr-muted">&mdash;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mtl_format_date() escapes internally. ?></td>
									<td><?php echo '' !== $rec['due_date'] ? mtl_format_date( $rec['due_date'] ) : '<span class="mtl-lr-muted">&mdash;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mtl_format_date() escapes internally. ?></td>
									<td><?php echo 'reservation' === $rec['type'] ? esc_html( '#' . $rec['queue_place'] . ' of ' . $rec['queue_size'] ) : '<span class="mtl-lr-muted">&mdash;</span>'; ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="8" style="text-align: center; padding: 20px;">
									No loans or reservations found. Add loan and reservation records to the database to see them here.
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $records ) : ?>
				<div class="mtl-pagination-bar mtl-pagination-bottom">
					<button type="button" class="button" id="mtl-lr-prev-page">&larr; Previous</button>
					<span id="mtl-lr-page-indicator"></span>
					<button type="button" class="button" id="mtl-lr-next-page">Next &rarr;</button>
				</div>
			<?php endif; ?>
		</div>

		<div class="mtl-lr-detail-col" id="mtl-lr-detail-col">
			<div class="mtl-lr-detail-box" id="mtl-lr-detail-box">
				<div class="mtl-lr-detail-empty">Select a row to view its full details here.</div>
			</div>
		</div>
	</div>

	<!-- Hidden per-record detail sources; JS copies the matching one into the
		detail box when a row is selected. Rendered server-side (already
		escaped) so no data is assembled from strings in the browser. The
		action forms inside carry a nonce and are copied verbatim. -->
	<?php
	// No id on the nonce input, so it can repeat across every record's forms
	// without producing duplicate DOM ids.
	$lr_nonce_field  = '<input type="hidden" name="mtl_lr_nonce" value="' . esc_attr( wp_create_nonce( 'mtl_lr_action' ) ) . '">';
	$lr_default_days = (int) get_option( 'mtl_default_loan_days', 21 );
	$lr_default_due  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . $lr_default_days . ' days' ) );
	?>
	<div id="mtl-lr-detail-sources" style="display: none;">
		<?php foreach ( $records as $rec ) : ?>
			<div class="mtl-lr-detail-src" data-rec="<?php echo esc_attr( $rec['idx'] ); ?>"><?php echo mtl_lr_detail_html( $rec, $lr_nonce_field, $lr_default_due, $lr_default_days ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mtl_lr_detail_html() escapes every interpolated value internally (see its docblock). ?></div>
		<?php endforeach; ?>
	</div>

	<script>
		// Global on purpose: the checkout form's quick-select buttons live in
		// the hidden #mtl-lr-detail-sources markup and get copied into the
		// visible detail box via innerHTML when a row is selected, so their
		// inline onclick="mtl_lr_set_due(...)" attributes resolve against the
		// global scope, not the DOMContentLoaded closure below.
		function mtl_lr_set_due(btn, days) {
			const form = btn.closest('form');
			const input = form ? form.querySelector('input[name="due_date"]') : null;
			if (!input) return;

			const d = new Date();
			d.setDate(d.getDate() + days);
			const mm = String(d.getMonth() + 1).padStart(2, '0');
			const dd = String(d.getDate()).padStart(2, '0');
			input.value = d.getFullYear() + '-' + mm + '-' + dd;

			const group = btn.parentElement;
			if (group) {
				group.querySelectorAll('button').forEach(function(b) {
					b.classList.remove('mtl-lr-due-quick-active');
				});
				btn.classList.add('mtl-lr-due-quick-active');
			}
		}

		document.addEventListener('DOMContentLoaded', function() {
			const table = document.getElementById('mtl-lr-table');
			const tbody = table.querySelector('tbody');
			const searchInput = document.getElementById('mtl-lr-search');
			const detailBox = document.getElementById('mtl-lr-detail-box');
			const detailDefault = detailBox ? detailBox.innerHTML : '';

			// --- Pagination state + control references ---
			let pageSize = 20;
			let currentPage = 1;
			const pageSizeSelect = document.getElementById('mtl-lr-page-size');
			const resultsInfo = document.getElementById('mtl-lr-results-info');
			const pageIndicator = document.getElementById('mtl-lr-page-indicator');
			const prevBtn = document.getElementById('mtl-lr-prev-page');
			const nextBtn = document.getElementById('mtl-lr-next-page');

			// --- One-click view + advanced filter controls ---
			let currentView = 'all';
			const viewButtons = document.querySelectorAll('.mtl-lr-view-btn');
			const advToggle = document.getElementById('mtl-lr-toggle-advanced');
			const advPanel = document.getElementById('mtl-lr-advanced-search');
			const clearBtn = document.getElementById('mtl-lr-clear-filters');

			const advFields = {
				tool: document.getElementById('adv-lr-tool'),
				barcode: document.getElementById('adv-lr-barcode'),
				member: document.getElementById('adv-lr-member'),
				type: document.getElementById('adv-lr-type'),
				loanFrom: document.getElementById('adv-lr-loan-from'),
				loanTo: document.getElementById('adv-lr-loan-to'),
				dueFrom: document.getElementById('adv-lr-due-from'),
				dueTo: document.getElementById('adv-lr-due-to'),
				resFrom: document.getElementById('adv-lr-res-from'),
				resTo: document.getElementById('adv-lr-res-to'),
				overdue: document.getElementById('adv-lr-overdue'),
				returnedLate: document.getElementById('adv-lr-returned-late'),
				firstInQueue: document.getElementById('adv-lr-first-queue'),
				toolOnLoan: document.getElementById('adv-lr-tool-on-loan'),
			};

			// A row is visible only if it passes the active one-click view AND
			// the quick text filter AND every non-empty advanced field.
			function rowMatches(row) {
				const d = row.dataset;

				if (currentView === 'reservation' && d.type !== 'reservation') return false;
				if (currentView === 'loans' && !(d.type === 'current' || d.type === 'previous')) return false;
				if (currentView === 'overdue' && d.overdue !== '1') return false;

				const quick = searchInput.value.trim().toLowerCase();
				if (quick && !row.textContent.toLowerCase().includes(quick)) return false;

				const tool = advFields.tool.value.trim().toLowerCase();
				if (tool && !d.tool.includes(tool)) return false;
				const barcode = advFields.barcode.value.trim().toLowerCase();
				if (barcode && !d.barcode.includes(barcode)) return false;
				const member = advFields.member.value.trim().toLowerCase();
				if (member && !d.member.includes(member)) return false;
				if (advFields.type.value && d.type !== advFields.type.value) return false;

				// d.loan/d.reserved carry a full timestamp, so range-filtering
				// compares just the date portion (first 10 chars) against the
				// plain-date From/To inputs; otherwise a loan later in the day
				// on the boundary date would wrongly compare as past it.
				const loanFrom = advFields.loanFrom.value;
				const loanTo = advFields.loanTo.value;
				const loanDateOnly = d.loan ? d.loan.slice(0, 10) : '';
				if (loanFrom && (!loanDateOnly || loanDateOnly < loanFrom)) return false;
				if (loanTo && (!loanDateOnly || loanDateOnly > loanTo)) return false;

				const dueFrom = advFields.dueFrom.value;
				const dueTo = advFields.dueTo.value;
				if (dueFrom && (!d.due || d.due < dueFrom)) return false;
				if (dueTo && (!d.due || d.due > dueTo)) return false;

				const resFrom = advFields.resFrom.value;
				const resTo = advFields.resTo.value;
				const reservedDateOnly = d.reserved ? d.reserved.slice(0, 10) : '';
				if (resFrom && (!reservedDateOnly || reservedDateOnly < resFrom)) return false;
				if (resTo && (!reservedDateOnly || reservedDateOnly > resTo)) return false;

				// Flags are "1"/"0" and blank where the question doesn't apply;
				// blank never equals "1" or "0", so those rows drop out of a
				// Yes/No filter instead of being counted as a false "No".
				if (advFields.overdue.value && d.overdue !== advFields.overdue.value) return false;
				if (advFields.returnedLate.value && d.returnedlate !== advFields.returnedLate.value) return false;
				if (advFields.firstInQueue.value && d.firstinqueue !== advFields.firstInQueue.value) return false;
				if (advFields.toolOnLoan.value && d.toolonloan !== advFields.toolOnLoan.value) return false;

				return true;
			}

			function applyFilters() {
				tbody.querySelectorAll('tr.mtl-lr-row').forEach(function(row) {
					row.dataset.matched = rowMatches(row) ? '1' : '0';
				});
				currentPage = 1;
				renderPage();
			}

			function renderPage() {
				const allRows = Array.from(tbody.querySelectorAll('tr.mtl-lr-row'));
				const matched = allRows.filter(function(r) { return r.dataset.matched !== '0'; });
				const total = matched.length;
				const totalPages = Math.max(1, Math.ceil(total / pageSize));
				if (currentPage > totalPages) currentPage = totalPages;
				if (currentPage < 1) currentPage = 1;
				const start = (currentPage - 1) * pageSize;
				const end = start + pageSize;

				allRows.forEach(function(row) { row.style.display = 'none'; });
				matched.forEach(function(row, i) {
					if (i >= start && i < end) row.style.display = '';
				});

				const shownStart = total === 0 ? 0 : start + 1;
				const shownEnd = Math.min(end, total);
				if (resultsInfo) {
					resultsInfo.innerHTML = total === 0
						? 'No matching records'
						: 'Showing <strong>' + shownStart + '–' + shownEnd + '</strong> of <strong>' + total + '</strong> records';
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

			// --- Row selection -> detail box ---
			tbody.addEventListener('click', function(e) {
				const row = e.target.closest('tr.mtl-lr-row');
				if (!row) return;
				tbody.querySelectorAll('tr.mtl-lr-row.mtl-lr-selected').forEach(function(r) {
					r.classList.remove('mtl-lr-selected');
				});
				row.classList.add('mtl-lr-selected');
				const src = document.querySelector('.mtl-lr-detail-src[data-rec="' + row.dataset.rec + '"]');
				if (src && detailBox) detailBox.innerHTML = src.innerHTML;
			});

			// --- One-click view buttons ---
			viewButtons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					currentView = btn.dataset.view;
					viewButtons.forEach(function(b) { b.classList.remove('mtl-lr-view-active'); });
					btn.classList.add('mtl-lr-view-active');
					applyFilters();
				});
			});

			// --- Advanced search toggle ---
			advToggle.addEventListener('click', function() {
				const isOpen = advPanel.style.display !== 'none';
				advPanel.style.display = isOpen ? 'none' : 'block';
				advToggle.textContent = isOpen ? 'Advanced Search' : 'Hide Advanced Search';
			});

			searchInput.addEventListener('keyup', applyFilters);
			Object.values(advFields).forEach(function(el) {
				el.addEventListener('input', applyFilters);
				el.addEventListener('change', applyFilters);
			});

			clearBtn.addEventListener('click', function() {
				searchInput.value = '';
				Object.values(advFields).forEach(function(el) {
					el.value = '';
				});
				currentView = 'all';
				viewButtons.forEach(function(b) { b.classList.toggle('mtl-lr-view-active', b.dataset.view === 'all'); });
				if (detailBox) detailBox.innerHTML = detailDefault;
				applyFilters();
			});

			// --- Column sorting ---
			// Sorts on each row's data-* value rather than its cell text: the
			// visible cells contain em dashes for non-applicable fields and
			// strings like "#2 of 3", which would not order correctly.
			const headers = table.querySelectorAll('thead th.sortable');
			headers.forEach(function(header) {
				header.addEventListener('click', function() {
					const key = header.dataset.sortKey;
					const type = header.dataset.sortType;
					const isAscending = header.classList.contains('asc');

					headers.forEach(function(h) { h.classList.remove('asc', 'desc'); });
					header.classList.add(isAscending ? 'desc' : 'asc');
					const dir = isAscending ? -1 : 1;

					const rows = Array.from(tbody.querySelectorAll('tr.mtl-lr-row'));
					rows.sort(function(a, b) {
						const av = a.dataset[key] || '';
						const bv = b.dataset[key] || '';

						// Records with no value in this column (e.g. a loan has
						// no reserved date) always sink to the bottom, in both
						// sort directions, so they never bury the real data.
						if (av === '' && bv === '') return 0;
						if (av === '') return 1;
						if (bv === '') return -1;

						if (type === 'num') {
							return (parseFloat(av) - parseFloat(bv)) * dir;
						}
						// Values are ISO (YYYY-MM-DD, or YYYY-MM-DD HH:MM:SS for
						// "Reserved"/"On Loan"), so text order is chronological
						// order either way, so this sorts reserved/loan by their
						// full timestamp, even though the visible cell shows only
						// the date.
						return av.localeCompare(bv) * dir;
					});

					rows.forEach(function(row) { tbody.appendChild(row); });

					currentPage = 1;
					renderPage();
				});
			});

			// --- Resizable columns ---
			// A thin grip on each header cell's right edge drags its width.
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

			// Panel resizing is handled entirely by CSS `resize: horizontal` on
			// .mtl-lr-main (see the stylesheet above), so no pointer handlers
			// needed; the detail column is a plain flex-fill sibling.

			// Establish the initial paginated view (all rows matched, page 1).
			applyFilters();
		});
	</script>

	<?php
	// ---- Bulk Checkout ------------------------------------------------
	//
	// Members and tools are both embedded rather than fetched, matching the
	// Quick Loan picker: the whole point is a desk worker scanning barcodes
	// without waiting on a round trip per row. The status shown is therefore a
	// snapshot from page load, which is why the handler revalidates everything
	// server-side before it writes anything.
	$bc_state   = mtl_bulk_checkout_state();
	$bc_members = mtl_get_member_picker_list();
	$bc_days    = (int) get_option( 'mtl_default_loan_days', 21 );
	$bc_due     = gmdate( 'Y-m-d', strtotime( '+' . $bc_days . ' days' ) );
	// Only read back when the handler above already ran, which means it already
	// verified the nonce and the capability; phpcs cannot see that from here.
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$bc_posted   = $bc_state['open'];
	$bc_barcodes = $bc_posted && isset( $_POST['bulk_barcode'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bulk_barcode'] ) ) : array();
	$bc_reserves = $bc_posted && isset( $_POST['bulk_reserve'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bulk_reserve'] ) ) : array();
	$bc_dues     = $bc_posted && isset( $_POST['bulk_due'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bulk_due'] ) ) : array();
	$bc_member   = $bc_posted && isset( $_POST['bulk_member_id'] ) ? (int) $_POST['bulk_member_id'] : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	// Five to start with. A refused batch keeps however many rows were
	// submitted, so nothing the staff member typed is lost on the way back.
	$bc_base_rows = 5;
	$bc_rows      = max( $bc_base_rows, count( $bc_barcodes ) );

	$bc_info = mtl_get_member_info_map( wp_list_pluck( $bc_members, 'id' ) );

	// Barcode => status, for the client to resolve a typed barcode without a
	// query. Only what the pill needs; nothing here is authoritative.
	$bc_tools = array();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, built from $wpdb->prefix, not user input.
	foreach (
		$wpdb->get_results(
			"SELECT t.tool_id, t.barcode, t.tool_name, t.retired_at,
                    (SELECT l.member_id FROM {$tbl_loans} l WHERE l.tool_id = t.tool_id AND l.return_date IS NULL LIMIT 1) AS on_loan_by,
                    (SELECT COUNT(*) FROM {$tbl_reservations} r WHERE r.tool_id = t.tool_id AND r.expiry_date IS NULL) AS queue_size,
                    (SELECT GROUP_CONCAT(r2.member_id) FROM {$tbl_reservations} r2 WHERE r2.tool_id = t.tool_id AND r2.expiry_date IS NULL) AS queue_members
             FROM {$tbl_inventory} t"
		) as $bc_tool
	) {
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bc_tools[ (string) $bc_tool->barcode ] = array(
			'id'      => (int) $bc_tool->tool_id,
			'name'    => stripslashes( (string) $bc_tool->tool_name ),
			'retired' => ! empty( $bc_tool->retired_at ),
			'loanBy'  => (int) $bc_tool->on_loan_by,
			'queue'   => array_map( 'intval', array_filter( explode( ',', (string) $bc_tool->queue_members ) ) ),
		);
	}
	?>

	<div id="mtl-bc-overlay" class="mtl-bc-overlay" style="display: none;">
		<div class="mtl-bc-modal" role="dialog" aria-modal="true" aria-labelledby="mtl-bc-title">
			<button type="button" class="mtl-bc-close" id="mtl-bc-close" aria-label="Close">&times;</button>
			<h3 id="mtl-bc-title" style="margin-top: 0;">Bulk checkout</h3>
			<p style="color: #646970; margin-top: 0;">Pick a member, then scan or type one barcode per row. Tick <strong>Reserve?</strong> to put the member in a tool&rsquo;s queue instead of lending it.</p>

			<form method="post" action="" id="mtl-bc-form">
				<?php wp_nonce_field( 'mtl_lr_action', 'mtl_lr_nonce' ); ?>
				<input type="hidden" name="mtl_lr_action" value="bulk">
				<input type="hidden" name="bulk_member_id" id="mtl-bc-member-id" value="<?php echo esc_attr( $bc_member ); ?>">

				<label class="mtl-bc-label" for="mtl-bc-member-search">Member</label>
				<div class="mtl-bc-picker">
					<input type="text" id="mtl-bc-member-search" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="mtl-bc-dropdown" aria-autocomplete="list" placeholder="Start typing a name or email, then pick from the list">
					<div class="mtl-bc-dropdown" id="mtl-bc-dropdown" role="listbox" style="display: none;"></div>
				</div>
				<div class="mtl-bc-member-info" id="mtl-bc-member-info">
					<dl>
						<dt>Verification</dt><dd id="mtl-bc-info-verified"></dd>
						<dt>Trainings</dt><dd id="mtl-bc-info-trainings"></dd>
						<dt>Overdue</dt><dd id="mtl-bc-info-overdue"></dd>
						<dt id="mtl-bc-info-agreement-label">Agreements</dt><dd id="mtl-bc-info-agreement"></dd>
					</dl>
				</div>

				<table class="mtl-bc-table">
					<thead>
						<tr>
							<th style="width: 22%;">Tool Barcode</th>
							<th style="width: 24%;">Tool</th>
							<th style="width: 8%;">Reserve?</th>
							<th style="width: 20%;">Loan Status</th>
							<th style="width: 26%;">Due Date</th>
						</tr>
					</thead>
					<tbody id="mtl-bc-rows">
						<?php for ( $bc_i = 0; $bc_i < $bc_rows; $bc_i++ ) : ?>
							<?php
							$bc_row_barcode = isset( $bc_barcodes[ $bc_i ] ) ? $bc_barcodes[ $bc_i ] : '';
							$bc_row_due     = isset( $bc_dues[ $bc_i ] ) && '' !== $bc_dues[ $bc_i ] ? $bc_dues[ $bc_i ] : $bc_due;
							$bc_row_note    = isset( $bc_state['rows'][ $bc_i ] ) ? $bc_state['rows'][ $bc_i ] : '';
							?>
							<tr>
								<td>
									<input type="text" name="bulk_barcode[<?php echo (int) $bc_i; ?>]" class="mtl-bc-barcode" autocomplete="off" value="<?php echo esc_attr( $bc_row_barcode ); ?>">
									<?php if ( '' !== $bc_row_note ) : ?>
										<span class="mtl-bc-row-note"><?php echo esc_html( $bc_row_note ); ?></span>
									<?php endif; ?>
								</td>
								<td class="mtl-bc-tool-name"></td>
								<td style="text-align: center;">
									<input type="checkbox" name="bulk_reserve[<?php echo (int) $bc_i; ?>]" value="1" class="mtl-bc-reserve" <?php checked( ! empty( $bc_reserves[ $bc_i ] ) ); ?>>
								</td>
								<td class="mtl-bc-status"></td>
								<td class="mtl-bc-due-cell">
									<input type="date" name="bulk_due[<?php echo (int) $bc_i; ?>]" class="mtl-bc-due" value="<?php echo esc_attr( $bc_row_due ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
									<?php foreach ( array( 7, 14, 21, 30 ) as $bc_quick ) : ?>
										<button type="button" class="button button-small mtl-bc-due-btn<?php echo $bc_quick === $bc_days ? ' mtl-bc-due-active' : ''; ?>" data-days="<?php echo (int) $bc_quick; ?>"><?php echo (int) $bc_quick; ?></button>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>

				<div class="mtl-bc-actions">
					<button type="submit" class="button button-primary" id="mtl-bc-submit" disabled>Loan</button>
					<button type="button" class="button" id="mtl-bc-clear">Clear</button>
					<span class="mtl-bc-summary" id="mtl-bc-summary"></span>
				</div>
			</form>
		</div>
	</div>

	<script>
		// Bulk Checkout. Mirrors the status rules in mtl_tool_row_status(); the
		// server reruns all of them before writing, so anything wrong here costs
		// a refused batch rather than a bad row.
		document.addEventListener('DOMContentLoaded', function() {
			const overlay = document.getElementById('mtl-bc-overlay');
			if (!overlay) return;

			const members = <?php echo wp_json_encode( $bc_members, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
			const tools = <?php echo wp_json_encode( $bc_tools, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
			const memberInfo = <?php echo wp_json_encode( $bc_info, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
			const defaultDays = <?php echo (int) $bc_days; ?>;

			const form = document.getElementById('mtl-bc-form');
			const search = document.getElementById('mtl-bc-member-search');
			const dropdown = document.getElementById('mtl-bc-dropdown');
			const memberField = document.getElementById('mtl-bc-member-id');
			const info = document.getElementById('mtl-bc-member-info');
			const tbody = document.getElementById('mtl-bc-rows');
			const submit = document.getElementById('mtl-bc-submit');
			const summary = document.getElementById('mtl-bc-summary');
			// From PHP, not from the DOM: a refused batch re-renders with as
			// many rows as were submitted, and Clear should still go back to
			// the starting five rather than to however many were on screen.
			const startingRows = <?php echo (int) $bc_base_rows; ?>;

			function memberId() { return parseInt(memberField.value, 10) || 0; }

			function dueFromDays(days) {
				const d = new Date();
				d.setDate(d.getDate() + days);
				return d.toISOString().slice(0, 10);
			}

			// The client half of mtl_tool_row_status(). Returns the pill plus
			// what the row would actually do.
			function rowStatus(barcode, reserve) {
				const tool = tools[barcode.trim()];
				if (!barcode.trim()) return null;
				if (!tool) return { cls: 'bad', text: 'No such barcode', act: 'block' };

				const me = memberId();
				const onLoanSelf = me > 0 && tool.loanBy === me;
				const onLoanOther = tool.loanBy > 0 && !onLoanSelf;
				const mine = me > 0 && tool.queue.indexOf(me) !== -1;
				const othersQueued = tool.queue.length > (mine ? 1 : 0);

				if (tool.retired) return { cls: 'bad', text: 'Retired', act: 'block' };

				if (reserve) {
					if (onLoanSelf) return { cls: 'skip', text: 'Already on loan to them', act: 'skip' };
					if (mine) return { cls: 'skip', text: 'Already reserved', act: 'skip' };
					return { cls: 'ok', text: 'Will join the queue', act: 'reserve' };
				}
				if (onLoanSelf) return { cls: 'bad', text: 'They already have it', act: 'block' };
				if (onLoanOther) return { cls: 'bad', text: 'On loan', act: 'block' };
				if (othersQueued) return { cls: 'warn', text: 'Reserved by another member', act: 'loan', warn: tool.name };
				if (mine) return { cls: 'ok', text: 'Reserved by member', act: 'loan' };
				return { cls: 'ok', text: 'Available', act: 'loan' };
			}

			function refresh() {
				let loans = 0, reserves = 0, skips = 0, blocked = 0;
				tbody.querySelectorAll('tr').forEach(function(tr) {
					const barcode = tr.querySelector('.mtl-bc-barcode');
					const reserve = tr.querySelector('.mtl-bc-reserve');
					const cell = tr.querySelector('.mtl-bc-status');
					const due = tr.querySelector('.mtl-bc-due');
					const dueBtns = tr.querySelectorAll('.mtl-bc-due-btn');

					// A reservation has no due date, so the cell is disabled
					// rather than hidden, which would make rows jump about.
					due.disabled = reserve.checked;
					dueBtns.forEach(function(b) { b.disabled = reserve.checked; });

					const tool = tools[barcode.value.trim()];
					tr.querySelector('.mtl-bc-tool-name').textContent = tool ? tool.name : '';

					const st = rowStatus(barcode.value, reserve.checked);
					if (!st) { cell.innerHTML = ''; return; }
					cell.innerHTML = '<span class="mtl-bc-pill mtl-bc-pill-' + st.cls + '"></span>';
					cell.firstChild.textContent = st.text;

					if (st.act === 'loan') loans++;
					else if (st.act === 'reserve') reserves++;
					else if (st.act === 'skip') skips++;
					else blocked++;
				});

				const parts = [];
				if (loans) parts.push('Loan ' + loans);
				if (reserves) parts.push('reserve ' + reserves);
				if (skips) parts.push('skip ' + skips);
				submit.textContent = parts.length ? parts.join(', ').replace(/^l/, 'L') : 'Loan';
				summary.textContent = blocked ? blocked + ' row(s) need attention' : '';
				// Disabled only when the batch would do nothing at all. Blocked
				// rows stay submittable so the server produces the real message.
				submit.disabled = (loans + reserves + skips) === 0;
			}

			function addRow() {
				const last = tbody.querySelector('tr:last-child');
				const clone = last.cloneNode(true);
				const index = tbody.querySelectorAll('tr').length;
				clone.querySelectorAll('input').forEach(function(input) {
					if (input.name) input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
					if (input.type === 'checkbox') input.checked = false;
					else if (input.type === 'date') input.value = dueFromDays(defaultDays);
					else input.value = '';
					input.disabled = false;
				});
				clone.querySelectorAll('.mtl-bc-row-note').forEach(function(n) { n.remove(); });
				clone.querySelectorAll('.mtl-bc-due-btn').forEach(function(b) {
					b.disabled = false;
					b.classList.toggle('mtl-bc-due-active', parseInt(b.dataset.days, 10) === defaultDays);
				});
				clone.querySelector('.mtl-bc-status').innerHTML = '';
				clone.querySelector('.mtl-bc-tool-name').textContent = '';
				tbody.appendChild(clone);
				return clone;
			}

			// Advancing on a resolved barcode is what makes this a scanning
			// workflow: fill the last row and the next one is already waiting.
			function advanceFrom(tr) {
				let next = tr.nextElementSibling;
				if (!next) next = addRow();
				next.querySelector('.mtl-bc-barcode').focus();
			}

			tbody.addEventListener('input', function(e) {
				if (!e.target.classList.contains('mtl-bc-barcode')) return;
				refresh();
				const tr = e.target.closest('tr');
				if (tools[e.target.value.trim()]) advanceFrom(tr);
			});

			tbody.addEventListener('change', function(e) {
				if (e.target.classList.contains('mtl-bc-reserve') || e.target.classList.contains('mtl-bc-due')) refresh();
			});

			tbody.addEventListener('click', function(e) {
				if (!e.target.classList.contains('mtl-bc-due-btn')) return;
				const tr = e.target.closest('tr');
				tr.querySelector('.mtl-bc-due').value = dueFromDays(parseInt(e.target.dataset.days, 10));
				tr.querySelectorAll('.mtl-bc-due-btn').forEach(function(b) { b.classList.remove('mtl-bc-due-active'); });
				e.target.classList.add('mtl-bc-due-active');
			});

			// Enter would submit the batch mid-scan, so it advances instead.
			tbody.addEventListener('keydown', function(e) {
				if (e.key !== 'Enter' || !e.target.classList.contains('mtl-bc-barcode')) return;
				e.preventDefault();
				advanceFrom(e.target.closest('tr'));
			});

			// A pill rather than plain text, so "Not verified" and an overdue
			// count read as flags to weigh rather than as sentences to skim.
			// Built as an element with textContent, so a training name or any
			// other stored value cannot become markup on its way in.
			function setPill(el, cls, text) {
				const span = document.createElement('span');
				span.className = 'mtl-bc-pill mtl-bc-pill-' + cls;
				span.textContent = text;
				el.innerHTML = '';
				el.appendChild(span);
			}

			function showMemberInfo(m) {
				const extra = memberInfo[m.id] || { trainings: [], overdue: 0, agreement: '' };
				info.style.display = 'block';
				setPill(document.getElementById('mtl-bc-info-verified'),
					m.verified ? 'ok' : 'warn', m.verified ? 'Verified' : 'Not verified');
				document.getElementById('mtl-bc-info-trainings').textContent =
					extra.trainings.length ? extra.trainings.join(', ') : 'None current';
				setPill(document.getElementById('mtl-bc-info-overdue'),
					extra.overdue ? 'bad' : 'ok',
					extra.overdue ? extra.overdue + ' tool(s) overdue' : 'Nothing overdue');

				// The agreements row is absent entirely when the feature is off,
				// rather than present and reassuring about nothing.
				const agLabel = document.getElementById('mtl-bc-info-agreement-label');
				const agValue = document.getElementById('mtl-bc-info-agreement');
				agLabel.style.display = extra.agreement ? '' : 'none';
				agValue.style.display = extra.agreement ? '' : 'none';
				if (extra.agreement) setPill(agValue, 'warn', extra.agreement);
			}

			function pickMember(m) {
				memberField.value = m.id;
				search.value = m.label;
				dropdown.style.display = 'none';
				search.setAttribute('aria-expanded', 'false');
				showMemberInfo(m);
				refresh();
			}

			search.addEventListener('input', function() {
				const q = search.value.trim().toLowerCase();
				memberField.value = '';
				info.style.display = 'none';
				refresh();
				dropdown.innerHTML = '';
				if (q.length < 2) {
					dropdown.style.display = 'none';
					search.setAttribute('aria-expanded', 'false');
					return;
				}
				const hits = members.filter(function(m) { return m.search.indexOf(q) !== -1; }).slice(0, 10);
				if (!hits.length) {
					const none = document.createElement('div');
					none.className = 'mtl-bc-empty';
					none.textContent = 'No member matches that.';
					dropdown.appendChild(none);
				}
				hits.forEach(function(m) {
					// Name and email on opposite sides so two members with the
					// same name are still told apart at a glance.
					const opt = document.createElement('div');
					opt.className = 'mtl-bc-option';
					opt.setAttribute('role', 'option');
					const name = document.createElement('span');
					name.textContent = m.name;
					const email = document.createElement('span');
					email.className = 'mtl-bc-option-email';
					email.textContent = m.email;
					opt.appendChild(name);
					opt.appendChild(email);
					opt.addEventListener('mousedown', function(e) {
						// mousedown, not click: blurring the field first would
						// close the dropdown out from under the pointer.
						e.preventDefault();
						pickMember(m);
					});
					dropdown.appendChild(opt);
				});
				dropdown.style.display = 'block';
				search.setAttribute('aria-expanded', 'true');
			});

			// Arrow keys and Enter, so the list is usable without the mouse.
			search.addEventListener('keydown', function(e) {
				const opts = Array.prototype.slice.call(dropdown.querySelectorAll('.mtl-bc-option'));
				if (!opts.length || dropdown.style.display === 'none') return;
				let at = opts.findIndex(function(o) { return o.classList.contains('mtl-bc-option-active'); });
				if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
					e.preventDefault();
					if (at >= 0) opts[at].classList.remove('mtl-bc-option-active');
					at = e.key === 'ArrowDown' ? (at + 1) % opts.length : (at <= 0 ? opts.length - 1 : at - 1);
					opts[at].classList.add('mtl-bc-option-active');
					opts[at].scrollIntoView({ block: 'nearest' });
				} else if (e.key === 'Enter' && at >= 0) {
					e.preventDefault();
					opts[at].dispatchEvent(new MouseEvent('mousedown'));
				} else if (e.key === 'Escape') {
					// Closes the list only. Without this the modal's own Escape
					// handler would also fire and throw away the whole batch.
					e.stopPropagation();
					dropdown.style.display = 'none';
					search.setAttribute('aria-expanded', 'false');
				}
			});

			document.addEventListener('click', function(e) {
				if (!e.target.closest('#mtl-bc-overlay .mtl-bc-picker')) {
					dropdown.style.display = 'none';
					search.setAttribute('aria-expanded', 'false');
				}
			});

			form.addEventListener('submit', function(e) {
				if (!memberId()) {
					e.preventDefault();
					window.alert('Pick a member from the list first.');
					search.focus();
					return;
				}
				// Loaning a tool somebody else is queued for is allowed, but it
				// takes their turn, so it is acknowledged rather than just logged.
				const jumped = [];
				tbody.querySelectorAll('tr').forEach(function(tr) {
					const st = rowStatus(tr.querySelector('.mtl-bc-barcode').value, tr.querySelector('.mtl-bc-reserve').checked);
					if (st && st.warn) jumped.push(st.warn);
				});
				if (jumped.length && !window.confirm(
					'Reserved by another member:\n\n' + jumped.join('\n') +
					'\n\nLending these takes the turn of whoever is waiting. Continue?'
				)) {
					e.preventDefault();
				}
			});

			function open() {
				overlay.style.display = 'flex';
				refresh();
				// The member is always the first thing to establish, and every
				// row's status depends on it.
				search.focus();
				search.select();
			}
			function close() { overlay.style.display = 'none'; }

			// Puts the window back exactly as it opens, which is the recovery
			// staff actually want after scanning the wrong pile or picking the
			// wrong member. Every per-row choice goes with the barcode that
			// prompted it: a Reserve? tick or a hand-set due date left behind
			// would silently apply to whatever is scanned next. Rows added
			// during the batch go too, back to the starting five.
			// Closing is still the X, the backdrop, or Escape.
			function clearAll() {
				memberField.value = '';
				search.value = '';
				info.style.display = 'none';
				dropdown.style.display = 'none';
				search.setAttribute('aria-expanded', 'false');

				const rows = tbody.querySelectorAll('tr');
				for (let i = rows.length - 1; i >= startingRows; i--) {
					rows[i].remove();
				}

				tbody.querySelectorAll('tr').forEach(function(tr) {
					tr.querySelector('.mtl-bc-barcode').value = '';
					tr.querySelector('.mtl-bc-reserve').checked = false;
					tr.querySelector('.mtl-bc-due').value = dueFromDays(defaultDays);
					tr.querySelector('.mtl-bc-status').innerHTML = '';
					tr.querySelector('.mtl-bc-tool-name').textContent = '';
					tr.querySelectorAll('.mtl-bc-row-note').forEach(function(n) { n.remove(); });
					tr.querySelectorAll('.mtl-bc-due-btn').forEach(function(b) {
						b.classList.toggle('mtl-bc-due-active', parseInt(b.dataset.days, 10) === defaultDays);
					});
				});

				refresh();
				search.focus();
			}

			document.getElementById('mtl-bc-open').addEventListener('click', open);
			document.getElementById('mtl-bc-close').addEventListener('click', close);
			document.getElementById('mtl-bc-clear').addEventListener('click', clearAll);
			overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' && overlay.style.display !== 'none') close();
			});

			<?php if ( $bc_state['open'] ) : ?>
				// The batch was refused. Reopen with every cell as it was left.
				<?php $bc_picked = $bc_member > 0 ? wp_list_filter( $bc_members, array( 'id' => $bc_member ) ) : array(); ?>
				<?php if ( $bc_picked ) : ?>
					pickMember(<?php echo wp_json_encode( array_values( $bc_picked )[0], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>);
				<?php endif; ?>
				open();
			<?php endif; ?>
		});
	</script>
	<?php
	echo '</div>';
}
