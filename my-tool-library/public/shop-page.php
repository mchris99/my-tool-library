<?php
/**
 * Public tool catalog, rendered server-side with no JavaScript.
 *
 * Search, filters, sort, view and pagination are plain GET requests. Picking
 * a tool links to a same-page fragment ("#tool-45") that CSS :target reveals,
 * so there is no reload; that's why every selectable tool's panel is
 * pre-rendered, hidden. mtl_shop_tool_share_url() adds a matching ?mtl_tool=
 * so a shared link opens the tool whatever the recipient's filters or page.
 *
 * mtl_render_shop_page() returns the HTML for the shared front-end shell
 * (mtl_render_front_main_page() in my-tool-library.php), which prints the top
 * bar and the shared styles from public/public-look.php around it.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A tool's availability as the shared status line: a colored square and one
 * word.
 *
 * A retired tool is never "Available": it can't be borrowed or reserved at
 * all (mtl_reserve_tool() refuses one), so it says Retired in place of that.
 * One retired mid-loan still says On loan, since that is a fact about where
 * the tool is right now.
 *
 * A tool under maintenance is not "Available" either, though it can still be
 * reserved. On loan wins over it for the same where-is-it-now reason.
 *
 * @param bool $on_loan     Whether the tool is currently on loan.
 * @param bool $retired     Whether the tool is retired from the collection.
 * @param bool $maintenance Whether the tool is under maintenance.
 * @return string HTML markup.
 */
function mtl_shop_status_word( $on_loan, $retired = false, $maintenance = false ) {
	if ( $on_loan ) {
		return '<span class="mtl-status mtl-status-out">On loan</span>';
	}
	if ( $retired ) {
		return '<span class="mtl-status mtl-status-retired">Retired</span>';
	}
	if ( $maintenance ) {
		return '<span class="mtl-status mtl-status-maint">Under maintenance</span>';
	}
	return '<span class="mtl-status mtl-status-ok">Available</span>';
}

/**
 * Renders the status line shared by the catalog views, the tool panel and
 * My loans & reservations: the status word and, when members are queued for
 * the tool, how many.
 *
 * A retired tool's count still shows, since those members are still queued
 * behind it.
 *
 * @param bool $on_loan     Whether the tool is currently on loan.
 * @param int  $res_count   Number of active reservations for the tool.
 * @param bool $retired     Whether the tool is retired from the collection.
 * @param bool $maintenance Whether the tool is under maintenance.
 * @return string HTML markup.
 */
function mtl_shop_status_badges( $on_loan, $res_count, $retired = false, $maintenance = false ) {
	$out = mtl_shop_status_word( $on_loan, $retired, $maintenance );
	if ( (int) $res_count > 0 ) {
		$out .= ' <span class="mtl-status-note">' . esc_html( (int) $res_count ) . ' waiting</span>';
	}
	return $out;
}

/**
 * Renders a comma-separated lookup value as a row of quiet chips.
 *
 * @param string $csv Comma-separated list of labels.
 * @return string HTML markup.
 */
function mtl_shop_pills( $csv ) {
	$csv = trim( (string) $csv );
	if ( '' === $csv ) {
		return '';
	}
	$out = '';
	foreach ( array_filter( array_map( 'trim', explode( ',', $csv ) ) ) as $label ) {
		$out .= '<span class="mtl-shop-pill">' . esc_html( $label ) . '</span>';
	}
	return $out;
}

/**
 * Renders a tool's shelf location for a member, or nothing at all.
 *
 * It renders nothing, and shows no message, when the library keeps shelf
 * locations to staff or the tool has none on file.
 *
 * Both checks live here, not at the call sites, so a page can't forget one
 * and leak a location; it fails closed. That's also why it takes the raw
 * column value rather than a "show it" flag. Used by My Reservations, and by
 * mtl_shop_location_fact() for the catalog's tool panel.
 *
 * @param string $location Raw tool_inventory.location value; NULL/'' for a
 *                         tool with no location recorded.
 * @return string HTML markup, or '' when the member is shown nothing.
 */
function mtl_shop_location_block( $location ) {
	$location = (string) $location;
	if ( '' === $location || ! mtl_tool_location_visible_to_members() ) {
		return '';
	}
	return '<h4>Where to find it</h4><p>' . esc_html( stripslashes( $location ) ) . '</p>';
}

/**
 * The shelf location as a dt/dd pair for the tool panel's facts list, or ''.
 *
 * Asks mtl_shop_location_block() first, so the two can never disagree about
 * whether a member may see it.
 *
 * @param string $location Raw tool_inventory.location value.
 * @return string HTML markup, or '' when the member is shown nothing.
 */
function mtl_shop_location_fact( $location ) {
	if ( '' === mtl_shop_location_block( $location ) ) {
		return '';
	}
	return '<dt>Shelf</dt><dd>' . esc_html( stripslashes( (string) $location ) ) . '</dd>';
}

/**
 * The CSS for what mtl_shop_status_badges() and mtl_shop_pills() render.
 *
 * The catalog (mtl_render_shop_page()) and the member pages
 * (mtl_member_page_styles()) both use those helpers, so the rules live here
 * once for both. The status words themselves (.mtl-status) are styled by the
 * shared base stylesheet.
 *
 * @return string CSS, ready to drop inside a <style> block.
 */
function mtl_shop_badge_pill_css() {
	ob_start();
	?>
		.mtl-shop-badges {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 2px 10px;
		}

		.mtl-shop-pill,
		.mtl-links-count {
			display: inline-block;
			margin: 2px 4px 0 0;
			padding: 1px 9px;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: 999px;
			color: var(--mtl-ink-2);
			font-size: 14px;
			font-weight: 400;
			line-height: 1.4;
		}
	<?php
	return ob_get_clean();
}

/**
 * DOM id ("tool-<id>") of a tool's detail panel, which is also its :target
 * fragment. Every link and panel builds it here so they always match.
 *
 * @param int $tool_id Tool row ID.
 * @return string
 */
function mtl_shop_panel_id( $tool_id ) {
	return 'tool-' . (int) $tool_id;
}

/**
 * Shareable URL for a tool: the query string makes the server render this
 * tool's panel regardless of the recipient's filters/pagination, and the
 * matching fragment makes the browser apply :target on load, so deep links
 * work without JS.
 *
 * @param int    $tool_id Tool row ID.
 * @param string $base    Base page URL.
 * @return string Escaped URL.
 */
function mtl_shop_tool_share_url( $tool_id, $base ) {
	$url = add_query_arg( 'mtl_tool', (int) $tool_id, $base ) . '#' . mtl_shop_panel_id( $tool_id );
	return esc_url( $url );
}

/**
 * "1st", "2nd", "3rd", "4th", "11th"...
 *
 * @param int $n A positive number.
 * @return string
 */
function mtl_shop_ordinal( $n ) {
	$n       = (int) $n;
	$endings = array(
		1 => 'st',
		2 => 'nd',
		3 => 'rd',
	);
	$teen    = $n % 100 >= 11 && $n % 100 <= 13;
	return $n . ( ! $teen && isset( $endings[ $n % 10 ] ) ? $endings[ $n % 10 ] : 'th' );
}

/**
 * What the tool panel's availability box says: the state and the queue, in
 * words.
 *
 * The "you'll be 4th in line" part only appears for someone who could join
 * the queue from here, so it never tells a member already in it, or staff,
 * to reserve.
 *
 * @param bool $on_loan     Whether the tool is on loan.
 * @param int  $res         Active reservations.
 * @param bool $retired     Whether the tool is retired.
 * @param bool $maintenance Whether the tool is under maintenance.
 * @param bool $can_queue   Whether the viewer could reserve it from here.
 * @return array array( tone, title, sentence ). Plain text, not yet escaped.
 */
function mtl_shop_availability_text( $on_loan, $res, $retired, $maintenance, $can_queue ) {
	if ( $retired ) {
		return array( 'retired', 'Retired', 'It has been retired from the collection and can no longer be borrowed or reserved.' );
	}

	if ( 0 === $res ) {
		$waiting = 'Nobody is waiting.';
	} elseif ( 1 === $res ) {
		$waiting = '1 member is waiting.';
	} else {
		$waiting = $res . ' members are waiting.';
	}
	$place = 0 === $res ? 'first' : mtl_shop_ordinal( $res + 1 );

	if ( $on_loan ) {
		return array( 'out', 'On loan', $waiting . ( $can_queue ? ' Reserve now and you’ll be ' . $place . ' in line.' : '' ) );
	}

	if ( $maintenance ) {
		if ( ! $can_queue ) {
			return array( 'maint', 'Under maintenance', 'It can’t be borrowed until it’s back in service.' . ( $res ? ' ' . $waiting : '' ) );
		}
		return array( 'maint', 'Under maintenance', $res ? $waiting . ' You can still join the queue and you’ll be ' . $place . ' in line.' : 'You can still join the queue.' );
	}

	if ( $res && $can_queue ) {
		$waiting .= ' Reserve now and you’ll be ' . $place . ' in line.';
	}
	return array( 'ok', 'Available to borrow', $waiting );
}

/**
 * Renders the body of one tool's detail panel: photo, name, availability,
 * then the Reserve control for whoever is viewing (none for a retired tool),
 * then the tool's description and facts.
 *
 * @param object $tool Tool row from the catalog query.
 * @param string $base Base page URL.
 * @param array  $ctx  Viewer context: is_member, is_locked, is_admin,
 *                     reserve_closed, show_numbers (bool);
 *                     reserved, loaned (tool_id => true lookups);
 *                     reserve_nonce_field (pre-rendered wp_nonce_field
 *                     string); login_url, signup_url, reservations_url;
 *                     cat_names (category_id => name) and sub_parents
 *                     (subcategory_id => array( category_id, name )).
 * @return string
 */
function mtl_shop_render_detail_panel( $tool, $base, $ctx = array() ) {
	$on_loan = ( (int) $tool->active_loans > 0 );
	$res     = (int) $tool->active_res;
	$tool_id = (int) $tool->tool_id;
	// Only ever true when the visitor asked for retired tools by filter, since
	// the catalog query hides them otherwise.
	$retired     = ! empty( $tool->retired_at );
	$maintenance = ! empty( $tool->maintenance_at );
	// A retired tool's link has to ask for retired tools too, or it opens an
	// empty detail box.
	$share_url = mtl_shop_tool_share_url( $tool_id, $retired ? add_query_arg( 'mtl_retired', 'include', $base ) : $base );

	$is_member = ! empty( $ctx['is_member'] );
	$has_it    = $is_member && isset( $ctx['loaned'][ $tool_id ] );
	$queued    = $is_member && isset( $ctx['reserved'][ $tool_id ] );
	$can_queue = ! $retired && empty( $ctx['reserve_closed'] )
		&& ( $is_member ? ( ! $has_it && ! $queued && empty( $ctx['is_locked'] ) ) : empty( $ctx['is_admin'] ) );

	list( $tone, $avail_title, $avail_text ) = mtl_shop_availability_text( $on_loan, $res, $retired, $maintenance, $can_queue );

	$brand = trim( stripslashes( (string) $tool->brand ) );
	$sub   = $brand;
	if ( ! empty( $ctx['show_numbers'] ) ) {
		$sub = '' !== $brand ? $brand . ', tool no. ' . $tool_id : 'Tool no. ' . $tool_id;
	}

	// Each category on its own line with the tool's sub-categories under it
	// ("General hand tools: Drills & drivers"). The query only gives ids, so
	// the names and parents come from the tree the filter panel already read.
	$cat_names   = isset( $ctx['cat_names'] ) ? $ctx['cat_names'] : array();
	$sub_parents = isset( $ctx['sub_parents'] ) ? $ctx['sub_parents'] : array();
	$tool_cats   = array_map( 'intval', array_filter( explode( ',', (string) ( $tool->category_ids ?? '' ) ) ) );
	$tool_subs   = array_map( 'intval', array_filter( explode( ',', (string) ( $tool->subcategory_ids ?? '' ) ) ) );
	$subs_by_cat = array();
	foreach ( $tool_subs as $sub_id ) {
		if ( isset( $sub_parents[ $sub_id ] ) ) {
			$subs_by_cat[ $sub_parents[ $sub_id ][0] ][] = $sub_parents[ $sub_id ][1];
		}
	}
	$cat_lines = array();
	foreach ( $cat_names as $cat_id => $cat_name ) {
		if ( in_array( $cat_id, $tool_cats, true ) ) {
			$cat_lines[] = esc_html( $cat_name ) . ( isset( $subs_by_cat[ $cat_id ] ) ? ': ' . esc_html( implode( ', ', $subs_by_cat[ $cat_id ] ) ) : '' );
			unset( $subs_by_cat[ $cat_id ] );
		}
	}
	// A sub-category whose parent isn't mapped to the tool still gets listed.
	foreach ( $subs_by_cat as $names ) {
		$cat_lines[] = esc_html( implode( ', ', $names ) );
	}

	$trainings = array_filter( array_map( 'trim', explode( ',', (string) $tool->required_trainings ) ) );
	$shelf     = mtl_shop_location_fact( $tool->location );

	ob_start();
	?>
	<?php if ( ! empty( $tool->photo_url ) ) : ?>
		<div class="mtl-shop-detail-photo"><img src="<?php echo esc_url( $tool->photo_url ); ?>" alt="<?php echo esc_attr( stripslashes( $tool->tool_name ) ); ?>" loading="lazy"></div>
	<?php endif; ?>
	<div class="mtl-shop-detail-body">
		<h2 class="mtl-shop-detail-name"><?php echo esc_html( stripslashes( $tool->tool_name ) ); ?></h2>
		<?php if ( '' !== $sub ) : ?>
			<p class="mtl-shop-detail-sub"><?php echo esc_html( $sub ); ?></p>
		<?php endif; ?>

		<div class="mtl-shop-avail mtl-shop-avail-<?php echo esc_attr( $tone ); ?>">
			<strong><?php echo esc_html( $avail_title ); ?></strong>
			<span><?php echo esc_html( $avail_text ); ?></span>
		</div>

		<?php
		// Reserving is POST + nonce, never GET, so prefetch or CSRF can't
		// trigger it. A retired tool needs nothing here: the box above says it.
		?>
		<?php if ( $retired ) : ?>
			<?php // Nothing to offer. ?>
		<?php elseif ( ! empty( $ctx['reserve_closed'] ) ) : ?>
			<?php // In place of the button before launch, for staff previewing as well as visitors. mtl_handle_reserve_action() refuses regardless. ?>
			<p class="mtl-shop-reserve-state">Reservations open when we launch.</p>
		<?php elseif ( $is_member ) : ?>
			<?php if ( $has_it ) : ?>
				<p class="mtl-shop-reserve-state">You have this tool checked out.</p>
			<?php elseif ( ! empty( $ctx['is_locked'] ) ) : ?>
				<?php // In place of the button, so the reason is right where they went to reserve. mtl_handle_reserve_action() refuses a locked member regardless. ?>
				<p class="mtl-shop-reserve-state mtl-shop-locked-note">Your account is locked, so you can&rsquo;t reserve this tool. Please speak with library staff.</p>
			<?php elseif ( $queued ) : ?>
				<p class="mtl-shop-reserve-state">You&rsquo;re in the queue for this tool. See <a href="<?php echo esc_url( $ctx['reservations_url'] ); ?>">My loans &amp; reservations</a>.</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $base ); ?>">
					<?php echo $ctx['reserve_nonce_field']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered wp_nonce_field() output. ?>
					<input type="hidden" name="mtl_action" value="reserve">
					<input type="hidden" name="mtl_tool" value="<?php echo (int) $tool_id; ?>">
					<button type="submit" class="mtl-btn mtl-btn-primary mtl-btn-wide">Reserve</button>
				</form>
				<p class="mtl-shop-fine">
					You can follow your place in line under <a href="<?php echo esc_url( $ctx['reservations_url'] ); ?>">My loans &amp; reservations</a>.
					<?php if ( $maintenance ) : ?>
						It can be picked up once it&rsquo;s back in service.
					<?php endif; ?>
				</p>
			<?php endif; ?>
		<?php elseif ( ! empty( $ctx['is_admin'] ) ) : ?>
			<p class="mtl-shop-reserve-state">Staff reserve tools for members from the Loans &amp; Reservations page.</p>
		<?php else : ?>
			<a class="mtl-btn mtl-btn-primary mtl-btn-wide" href="<?php echo esc_url( $ctx['login_url'] ); ?>">Sign in to reserve</a>
			<p class="mtl-shop-fine">New here? <a href="<?php echo esc_url( $ctx['signup_url'] ); ?>">Create a member account</a> to reserve tools.</p>
		<?php endif; ?>

		<?php if ( ! empty( $tool->description ) ) : ?>
			<h3>About this tool</h3>
			<p><?php echo nl2br( esc_html( stripslashes( $tool->description ) ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $tool->components ) ) : ?>
			<h3>What&rsquo;s included</h3>
			<p><?php echo nl2br( esc_html( stripslashes( $tool->components ) ) ); ?></p>
		<?php endif; ?>

		<?php if ( $trainings ) : ?>
			<div class="mtl-shop-train">
				<strong>Training needed: <?php echo esc_html( implode( ', ', $trainings ) ); ?></strong>
				Ask staff about <?php echo 1 === count( $trainings ) ? 'it' : 'these'; ?> before you borrow this tool.
			</div>
		<?php endif; ?>

		<?php if ( $cat_lines || ! empty( $tool->tags ) || '' !== $shelf ) : ?>
			<dl class="mtl-shop-facts">
				<?php if ( $cat_lines ) : ?>
					<dt><?php echo 1 === count( $cat_lines ) ? 'Category' : 'Categories'; ?></dt>
					<dd><?php echo implode( '<br>', $cat_lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each line is built from esc_html() pieces above. ?></dd>
				<?php endif; ?>
				<?php if ( ! empty( $tool->tags ) ) : ?>
					<dt>Tags</dt>
					<dd><?php echo esc_html( $tool->tags ); ?></dd>
				<?php endif; ?>
				<?php echo $shelf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside mtl_shop_location_fact(). ?>
			</dl>
		<?php endif; ?>

		<?php
		// Resources and Partner Links (nothing for a tool with neither), from
		// the same helper the admin Inventory detail uses so the two can't drift.
		$links = mtl_tool_all_links_html( $tool );
		?>
		<?php if ( '' !== $links ) : ?>
			<div class="mtl-shop-links"><?php echo $links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- from mtl_tool_all_links_html(), which escapes every value. ?></div>
		<?php endif; ?>

		<?php
		// Native <details>, so visitors see a link rather than a raw URL. The
		// field is plain readonly text because click-to-select would need
		// JavaScript.
		?>
		<details class="mtl-shop-share">
			<summary>Copy a link to this tool</summary>
			<input type="text" class="mtl-shop-share-input" readonly value="<?php echo $share_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_url()-escaped by mtl_shop_tool_share_url(). ?>" aria-label="Link to this tool">
		</details>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * The catalog's own styles. Built only from the shared custom properties
 * (public/public-look.php), so every Setup look reaches it, and the same text
 * whatever the look.
 *
 * Under 900px the tool panel becomes a bottom sheet over a dimmed catalog.
 * That needs :has(); a browser without it shows the panel below the list.
 *
 * @return string CSS.
 */
function mtl_shop_page_css() {
	ob_start();
	?>
		.mtl-shop {
			max-width: 1320px;
			margin: 0 auto;
			padding: 34px 24px 60px;
		}

		/* Zero-size and fixed, so closing the phone sheet by linking here
			can't cause a scroll jump. */
		.mtl-shop-close-anchor {
			position: fixed;
			top: 0;
			left: 0;
			width: 0;
			height: 0;
		}

		/* Above the catalog during the preview before launch. */
		.mtl-shop-prelaunch-banner {
			margin: 0 0 18px;
			padding: 12px 16px;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-left: 5px solid var(--mtl-btn);
			border-radius: var(--mtl-radius);
		}

		/* ---------- Search ---------- */

		.mtl-shop .mtl-page-title {
			margin: 0 0 16px;
		}

		.mtl-shop-search {
			display: flex;
			gap: 10px;
			max-width: 760px;
		}

		.mtl-shop-search-input {
			flex: 1 1 auto;
			min-width: 0;
			min-height: 52px;
			margin: 0;
			padding: 0 16px;
			background: var(--mtl-panel);
			border: 2px solid var(--mtl-ink);
			border-radius: var(--mtl-radius);
			color: var(--mtl-ink);
			font-family: var(--mtl-font-text);
			font-size: 18px;
			-webkit-appearance: none;
			appearance: none;
		}

		.mtl-shop-search-input::placeholder {
			color: var(--mtl-ink-2);
			opacity: 1;
		}

		.mtl-shop-search .mtl-btn {
			min-height: max(52px, calc(52px * var(--mtl-btn-scale)));
			padding: 0 calc(26px * var(--mtl-btn-scale));
			font-size: calc(17px * var(--mtl-btn-scale));
		}

		/* ---------- Category links ---------- */

		.mtl-shop-shelf {
			margin: 16px 0 0;
		}

		.mtl-shop-shelf-chips {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 8px;
			margin: 0;
			padding: 0;
			list-style: none;
		}

		/* Open, the full list takes a line of its own under the chips. */
		.mtl-shop-cats-item:has(> .mtl-shop-cats[open]) {
			flex-basis: 100%;
		}

		.mtl-shop-shelf .mtl-chip {
			min-height: 40px;
			padding: 0 14px;
			background: color-mix(in srgb, var(--mtl-panel) 60%, transparent);
			cursor: pointer;
		}

		.mtl-shop-shelf .mtl-chip:hover {
			background: var(--mtl-panel);
			border-color: var(--mtl-ink);
		}

		.mtl-shop-chip-n {
			color: var(--mtl-ink-2);
			font-size: 13.5px;
		}

		.mtl-shop-shelf .mtl-chip[aria-current="true"] {
			background: var(--mtl-ink);
			border-color: var(--mtl-ink);
			color: var(--mtl-panel);
			font-weight: 700;
		}

		.mtl-shop-shelf .mtl-chip[aria-current="true"] .mtl-shop-chip-n {
			color: inherit;
		}

		.mtl-shop-cats > summary {
			list-style: none;
			font-weight: 700;
		}

		.mtl-shop-cats > summary::-webkit-details-marker {
			display: none;
		}

		.mtl-shop-cats > summary::after {
			content: "";
			width: 7px;
			height: 7px;
			border-right: 2px solid currentColor;
			border-bottom: 2px solid currentColor;
			transform: translateY(-2px) rotate(45deg);
		}

		.mtl-shop-cats[open] > summary::after {
			transform: translateY(2px) rotate(-135deg);
		}

		.mtl-shop-cats-list {
			columns: 4 210px;
			column-gap: 24px;
			margin: 10px 0 0;
			padding: 10px 14px;
			list-style: none;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
		}

		.mtl-shop-cats-list li {
			break-inside: avoid;
		}

		.mtl-shop-cats-list a {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			min-height: 40px;
			padding: 0 8px;
			border-radius: 4px;
			color: var(--mtl-ink);
			text-decoration: none;
		}

		.mtl-shop-cats-list a:hover {
			background: var(--mtl-hover);
			text-decoration: underline;
		}

		.mtl-shop-cats-list a[aria-current="true"] {
			background: var(--mtl-highlight);
			font-weight: 700;
		}

		/* ---------- More filters ---------- */

		.mtl-shop-filters {
			margin: 14px 0 18px;
		}

		.mtl-shop-filters > summary {
			list-style: none;
			cursor: pointer;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			min-height: 44px;
			font-weight: 700;
			font-size: 15.5px;
		}

		.mtl-shop-filters > summary::-webkit-details-marker {
			display: none;
		}

		.mtl-shop-filters > summary::after {
			content: "+";
			width: 12px;
			font-size: 20px;
			font-weight: 400;
			line-height: 1;
		}

		.mtl-shop-filters[open] > summary::after {
			content: "\2212";
		}

		.mtl-shop-filters-panel {
			margin-top: 6px;
			padding: 22px 24px 20px;
		}

		.mtl-shop-filters-cols {
			display: grid;
			grid-template-columns: minmax(0, 1fr) minmax(0, 1.25fr) minmax(0, 1fr);
			gap: 28px;
		}

		.mtl-shop-filters-cols > div {
			min-width: 0;
		}

		.mtl-shop-group-label {
			margin: 0;
			font-weight: 700;
			font-size: 15.5px;
		}

		.mtl-shop-filters .mtl-hint {
			margin: 2px 0 10px;
		}

		/* The shared category tree, restyled only inside this panel. The admin
			pages print their own tree styles; this page doesn't load those. */
		.mtl-shop-filters .mtl-tx-tree,
		.mtl-shop-tags {
			max-height: 286px;
			overflow-y: auto;
			margin: 0;
			padding: 6px 12px;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-field-border);
			border-radius: var(--mtl-radius);
		}

		.mtl-shop-filters .mtl-tx-tree {
			height: 286px;
		}

		.mtl-shop-filters .mtl-tx-branch {
			margin: 0;
			padding: 4px 0;
			border-top: 1px solid var(--mtl-rule-soft);
		}

		.mtl-shop-filters .mtl-tx-branch:first-child {
			border-top: 0;
		}

		.mtl-shop-filters .mtl-tx-parent,
		.mtl-shop-filters .mtl-tx-child,
		.mtl-shop-tags label {
			display: flex;
			align-items: center;
			gap: 10px;
			min-height: 32px;
			margin: 0;
			font-size: 15.5px;
			font-weight: 400;
			line-height: 1.3;
			cursor: pointer;
		}

		.mtl-shop-filters .mtl-tx-parent {
			font-weight: 700;
		}

		.mtl-shop-filters .mtl-tx-child {
			margin-left: 28px;
		}

		.mtl-shop-filters input[type="checkbox"] {
			flex: none;
			width: 18px;
			height: 18px;
			margin: 0;
		}

		/* A ticked category already covers its sub-categories, so they read as
			redundant. A greyed one ticked anyway still submits and changes
			nothing, since the match is an OR. */
		.mtl-shop-filters .mtl-tx-branch:has(.mtl-tx-parent input:checked) .mtl-tx-child {
			color: var(--mtl-ink-2);
		}

		.mtl-shop-filters .mtl-tx-empty,
		.mtl-shop-tags-empty {
			margin: 0;
			color: var(--mtl-ink-2);
			font-size: 15px;
		}

		.mtl-shop-tags {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 0 12px;
		}

		.mtl-shop-filters-foot {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 14px 22px;
			margin-top: 22px;
			padding-top: 18px;
			border-top: 1px solid var(--mtl-rule);
		}

		.mtl-shop-filters-foot p {
			max-width: 46ch;
			margin: 0 0 0 auto;
			color: var(--mtl-ink-2);
			font-size: 14px;
		}

		/* ---------- Results bar ---------- */

		.mtl-shop-results-bar {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 12px 22px;
			margin: 0 0 18px;
			padding: 12px 0 0;
			border-top: 1px solid var(--mtl-rule);
			font-size: 15px;
		}

		.mtl-shop-count {
			margin: 0 auto 0 0;
		}

		.mtl-shop-count strong {
			font-size: 17px;
		}

		.mtl-shop-views {
			display: inline-flex;
			overflow: hidden;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
		}

		.mtl-shop-views a {
			display: flex;
			align-items: center;
			min-height: 40px;
			padding: 0 14px;
			color: var(--mtl-ink);
			text-decoration: none;
		}

		.mtl-shop-views a:hover {
			background: var(--mtl-hover);
		}

		.mtl-shop-views a[aria-current="true"] {
			background: var(--mtl-ink);
			color: var(--mtl-panel);
			font-weight: 700;
		}

		.mtl-shop-sort {
			position: relative;
		}

		.mtl-shop-sort > summary {
			list-style: none;
			cursor: pointer;
			display: flex;
			align-items: center;
			gap: 10px;
			min-height: 40px;
			padding: 0 14px;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
		}

		.mtl-shop-sort > summary::-webkit-details-marker {
			display: none;
		}

		.mtl-shop-sort > summary::after {
			content: "";
			width: 7px;
			height: 7px;
			border-right: 2px solid currentColor;
			border-bottom: 2px solid currentColor;
			transform: translateY(-2px) rotate(45deg);
		}

		.mtl-shop-sort[open] > summary::after {
			transform: translateY(2px) rotate(-135deg);
		}

		.mtl-shop-sort-menu {
			position: absolute;
			right: 0;
			top: calc(100% + 6px);
			z-index: 30;
			min-width: 190px;
			padding: 6px 0;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
			box-shadow: 0 8px 24px rgba(0, 0, 0, .16);
		}

		.mtl-shop-sort-menu a {
			display: flex;
			align-items: center;
			min-height: 40px;
			padding: 0 16px 0 13px;
			border-left: 3px solid transparent;
			color: var(--mtl-ink);
			text-decoration: none;
		}

		.mtl-shop-sort-menu a:hover {
			background: var(--mtl-hover);
		}

		.mtl-shop-sort-menu a[aria-current="true"] {
			border-left-color: var(--mtl-btn);
			font-weight: 700;
		}

		/* ---------- Filters in use ---------- */

		.mtl-shop-active {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 8px;
			margin: 0 0 18px;
			font-size: 15px;
		}

		.mtl-shop-active-label {
			margin-right: 2px;
			color: var(--mtl-ink-2);
		}

		.mtl-shop-chip-x {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			min-height: 34px;
			padding: 4px 6px 4px 12px;
			background: var(--mtl-ink);
			border-radius: 999px;
			color: var(--mtl-panel);
			text-decoration: none;
		}

		.mtl-shop-chip-x b {
			display: grid;
			place-items: center;
			width: 22px;
			height: 22px;
			border-radius: 50%;
			background: color-mix(in srgb, var(--mtl-panel) 20%, transparent);
			font-size: 16px;
			font-weight: 400;
			line-height: 1;
		}

		.mtl-shop-chip-x:hover b {
			background: color-mix(in srgb, var(--mtl-panel) 38%, transparent);
		}

		.mtl-shop-clear-all {
			margin-left: 6px;
		}

		/* ---------- Layout ---------- */

		.mtl-shop-layout {
			display: grid;
			grid-template-columns: minmax(0, 1fr) 360px;
			gap: 28px;
			align-items: start;
		}

		.mtl-shop-main {
			min-width: 0;
		}

		/* ---------- Cards ---------- */

		.mtl-shop-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(196px, 1fr));
			gap: 22px 16px;
			margin: 0;
			padding: 0;
			list-style: none;
		}

		.mtl-shop-grid > li {
			display: flex;
		}

		.mtl-shop-card {
			display: flex;
			flex-direction: column;
			width: 100%;
			color: inherit;
			text-decoration: none;
		}

		.mtl-shop-card-photo {
			position: relative;
			display: block;
			aspect-ratio: 4 / 3;
			overflow: hidden;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
		}

		.mtl-shop-card-photo img {
			display: block;
			width: 100%;
			height: 100%;
			object-fit: contain;
		}

		.mtl-shop-nophoto {
			display: grid;
			place-items: center;
			border-style: dashed;
			color: var(--mtl-ink-2);
			font-size: 14px;
		}

		.mtl-shop-card-no {
			position: absolute;
			top: 8px;
			left: 8px;
			padding: 1px 7px;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: 4px;
			color: var(--mtl-ink-2);
			font-size: 12.5px;
			line-height: 1.5;
		}

		.mtl-shop-card:hover .mtl-shop-card-photo {
			border-color: var(--mtl-ink);
		}

		/* A second pixel drawn outside, so the photo doesn't shift. */
		.mtl-shop-card.is-selected .mtl-shop-card-photo {
			border-color: var(--mtl-ink);
			box-shadow: 0 0 0 1px var(--mtl-ink);
		}

		.mtl-shop-card-name {
			margin: 10px 0 1px;
			font-size: 19px;
			font-weight: 700;
			line-height: 1.15;
		}

		.mtl-shop-card-brand {
			margin: 0;
			color: var(--mtl-ink-2);
			font-size: 14.5px;
		}

		.mtl-shop-card .mtl-shop-badges {
			margin-top: auto;
			padding-top: 6px;
		}

		/* ---------- Table ---------- */

		.mtl-shop-table-wrap {
			overflow-x: auto;
		}

		.mtl-shop-table {
			width: 100%;
			border-collapse: collapse;
			font-size: 15.5px;
		}

		.mtl-shop-table th {
			padding: 10px 12px;
			border-bottom: 2px solid var(--mtl-rule);
			color: var(--mtl-ink-2);
			font-size: 14px;
			font-weight: 700;
			text-align: left;
			white-space: nowrap;
		}

		.mtl-shop-table td {
			padding: 8px 12px;
			border-top: 1px solid var(--mtl-rule-soft);
			vertical-align: middle;
		}

		.mtl-shop-table tbody tr:hover {
			background: var(--mtl-hover);
		}

		.mtl-shop-table tr.is-selected,
		.mtl-shop-c-row.is-selected {
			background: var(--mtl-highlight);
		}

		.mtl-shop-table tr.is-selected > td:first-child,
		.mtl-shop-c-row.is-selected {
			box-shadow: inset 4px 0 0 var(--mtl-btn);
		}

		.mtl-shop-t-thumb {
			width: 72px;
		}

		.mtl-shop-t-thumb img,
		.mtl-shop-t-nophoto {
			display: grid;
			place-items: center;
			width: 60px;
			height: 45px;
			object-fit: contain;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: 4px;
			color: var(--mtl-ink-2);
			font-size: 11px;
			line-height: 1.1;
			text-align: center;
		}

		.mtl-shop-t-nophoto {
			border-style: dashed;
		}

		.mtl-shop-t-name a {
			display: block;
			color: var(--mtl-ink);
			font-family: var(--mtl-font-display);
			font-stretch: 82%;
			font-size: 18px;
			font-weight: 700;
			line-height: 1.2;
			text-decoration: none;
		}

		.mtl-shop-t-name a:hover {
			text-decoration: underline;
		}

		.mtl-shop-t-name span {
			color: var(--mtl-ink-2);
			font-size: 14px;
		}

		.mtl-shop-t-no {
			width: 56px;
			color: var(--mtl-ink-2);
		}

		.mtl-shop-t-cat,
		.mtl-shop-t-none {
			color: var(--mtl-ink-2);
		}

		.mtl-shop-table .mtl-status,
		.mtl-shop-t-wait {
			white-space: nowrap;
		}

		/* The waiting count, repeated under the status for phones, where the
			Waiting column is hidden. */
		.mtl-shop-t-wait-inline {
			display: none;
		}

		/* ---------- Compact ---------- */

		.mtl-shop-compact {
			margin: 0;
			padding: 0;
			overflow: hidden;
			list-style: none;
		}

		.mtl-shop-compact li + li {
			border-top: 1px solid var(--mtl-rule-soft);
		}

		.mtl-shop-c-row {
			display: grid;
			grid-template-columns: minmax(0, auto) minmax(0, 1fr) auto 84px;
			align-items: baseline;
			gap: 12px;
			min-height: 44px;
			padding: 9px 14px;
			color: var(--mtl-ink);
			text-decoration: none;
		}

		.mtl-shop-c-row:hover {
			background: var(--mtl-hover);
		}

		.mtl-shop-c-name,
		.mtl-shop-c-brand {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		.mtl-shop-c-name {
			font-weight: 700;
		}

		.mtl-shop-c-brand {
			color: var(--mtl-ink-2);
			font-size: 14.5px;
		}

		.mtl-shop-c-row .mtl-status {
			font-size: 14px;
			white-space: nowrap;
		}

		.mtl-shop-c-wait {
			color: var(--mtl-ink-2);
			font-size: 14px;
			text-align: right;
			white-space: nowrap;
		}

		/* ---------- Pagination and empty results ---------- */

		.mtl-shop-pagination {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			justify-content: center;
			gap: 10px 16px;
			margin-top: 30px;
			font-size: 15px;
		}

		.mtl-shop-page-off {
			padding: 0 16px;
			color: var(--mtl-ink-2);
		}

		.mtl-shop-empty {
			padding: 36px 24px;
			border: 1px dashed var(--mtl-rule);
			border-radius: var(--mtl-radius);
			text-align: center;
		}

		.mtl-shop-empty p {
			margin: 12px 0 0;
		}

		.mtl-shop-empty .mtl-shop-empty-title {
			margin: 0;
			color: var(--mtl-heading);
			font-family: var(--mtl-font-display);
			font-stretch: 82%;
			font-size: 21px;
			font-weight: 700;
		}

		/* ---------- Tool panel ---------- */

		.mtl-shop-detail-col {
			position: sticky;
			top: 20px;
		}

		/* Capped at the viewport and scrolled inside, since the page can't
			scroll to the bottom of a sticky column. */
		.mtl-shop-detail {
			position: relative;
			max-height: calc(100vh - 40px);
			overflow-x: hidden;
			overflow-y: auto;
			overscroll-behavior: contain;
			background: var(--mtl-panel);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
		}

		/* Every tool's panel is pre-rendered (hidden); CSS :target reveals
			whichever one matches the URL fragment. */
		.mtl-shop-detail-panel {
			display: none;
			/* Matches the sticky column's offset, so picking a tool doesn't
				nudge the page. */
			scroll-margin-top: 20px;
		}

		.mtl-shop-detail-panel:target {
			display: block;
		}

		.mtl-shop-detail:has(.mtl-shop-detail-panel:target) .mtl-shop-detail-empty {
			display: none;
		}

		.mtl-shop-detail-empty {
			margin: 0;
			padding: 28px 22px;
			color: var(--mtl-ink-2);
			text-align: center;
		}

		/* Phone sheet parts, shown only under 900px. */
		.mtl-shop-scrim,
		.mtl-shop-grabber,
		.mtl-shop-detail-close {
			display: none;
		}

		.mtl-shop-detail-photo {
			aspect-ratio: 16 / 10;
			background: var(--mtl-panel);
			border-bottom: 1px solid var(--mtl-rule);
		}

		.mtl-shop-detail-photo img {
			display: block;
			width: 100%;
			height: 100%;
			object-fit: contain;
		}

		.mtl-shop-detail-body {
			padding: 18px 22px 22px;
		}

		.mtl-shop-detail p {
			margin: 0;
		}

		.mtl-shop-detail .mtl-shop-detail-name {
			font-size: 30px;
			letter-spacing: -0.01em;
		}

		.mtl-shop-detail .mtl-shop-detail-sub {
			margin: 4px 0 0;
			color: var(--mtl-ink-2);
			font-size: 15px;
		}

		.mtl-shop-avail {
			margin: 18px 0 14px;
			padding: 12px 14px;
			background: color-mix(in srgb, var(--mtl-shop-tone) 9%, var(--mtl-panel));
			border-left: 4px solid var(--mtl-shop-tone);
			border-radius: 0 var(--mtl-radius) var(--mtl-radius) 0;
		}

		.mtl-shop-avail-ok {
			--mtl-shop-tone: var(--mtl-ok);
		}

		.mtl-shop-avail-out {
			--mtl-shop-tone: var(--mtl-out);
		}

		.mtl-shop-avail-maint {
			--mtl-shop-tone: var(--mtl-maint);
		}

		.mtl-shop-avail-retired {
			--mtl-shop-tone: var(--mtl-retired);
		}

		.mtl-shop-avail strong {
			display: block;
			color: var(--mtl-shop-tone);
			font-size: 17px;
		}

		.mtl-shop-avail span {
			display: block;
			font-size: 15px;
		}

		.mtl-shop-detail form {
			margin: 0;
		}

		.mtl-shop-detail .mtl-shop-fine {
			margin: 8px 0 0;
			color: var(--mtl-ink-2);
			font-size: 14px;
		}

		/* Takes the Reserve button's place when there is nothing to press. */
		.mtl-shop-detail .mtl-shop-reserve-state {
			padding: 11px 14px;
			background: var(--mtl-hover);
			border: 1px solid var(--mtl-rule);
			border-radius: var(--mtl-radius);
			font-size: 15px;
		}

		.mtl-shop-detail .mtl-shop-locked-note {
			background: var(--mtl-panel);
			border-left: 4px solid var(--mtl-late);
		}

		.mtl-shop-detail h3 {
			margin: 22px 0 4px;
			color: var(--mtl-ink);
			font-family: var(--mtl-font-text);
			font-stretch: 100%;
			font-size: 15px;
			font-weight: 700;
			line-height: 1.4;
		}

		.mtl-shop-train {
			margin-top: 22px;
			padding: 12px 14px;
			background: color-mix(in srgb, var(--mtl-maint) 10%, var(--mtl-panel));
			border: 1px solid color-mix(in srgb, var(--mtl-maint) 45%, var(--mtl-panel));
			border-radius: var(--mtl-radius);
			font-size: 15px;
		}

		.mtl-shop-train strong {
			display: block;
		}

		.mtl-shop-facts {
			display: grid;
			grid-template-columns: max-content minmax(0, 1fr);
			gap: 6px 14px;
			margin: 22px 0 0;
			padding-top: 16px;
			border-top: 1px solid var(--mtl-rule);
			font-size: 15px;
		}

		.mtl-shop-facts dt {
			color: var(--mtl-ink-2);
		}

		.mtl-shop-facts dd {
			margin: 0;
			overflow-wrap: anywhere;
		}

		.mtl-shop-links {
			margin-top: 16px;
			font-size: 15px;
		}

		.mtl-shop-links .mtl-links {
			margin: 0;
			padding: 6px 0;
			border-top: 1px solid var(--mtl-rule-soft);
		}

		.mtl-shop-links .mtl-links > summary {
			display: flex;
			align-items: center;
			gap: 8px;
			min-height: 36px;
			font-weight: 700;
			cursor: pointer;
		}

		.mtl-shop-links .mtl-links-count {
			margin: 0;
		}

		.mtl-shop-links .mtl-links-list {
			margin: 4px 0 0;
			padding: 0;
			list-style: none;
		}

		.mtl-shop-links .mtl-links-list li {
			padding: 6px 0;
			border-top: 1px solid var(--mtl-rule-soft);
		}

		.mtl-shop-share {
			margin-top: 18px;
			font-size: 14.5px;
		}

		.mtl-shop-share > summary {
			display: inline-block;
			color: var(--mtl-link);
			text-decoration: underline;
			text-underline-offset: 2px;
			cursor: pointer;
		}

		.mtl-shop-share-input {
			display: block;
			width: 100%;
			min-height: 42px;
			margin-top: 8px;
			padding: 0 10px;
			background: var(--mtl-hover);
			border: 1px solid var(--mtl-field-border);
			border-radius: var(--mtl-radius);
			color: var(--mtl-ink);
			font-family: ui-monospace, Consolas, Menlo, monospace;
			font-size: 16px;
		}

		/* ---------- Tablets and phones ---------- */

		@media (max-width: 960px) {
			.mtl-shop-filters-cols {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}

			.mtl-shop-filters-cols > div:last-child {
				grid-column: 1 / -1;
			}
		}

		/* The tool panel as a bottom sheet over a dimmed catalog. With nothing
			picked the column is hidden, as there is no room beside the list. */
		@media (max-width: 900px) {
			.mtl-shop-layout {
				grid-template-columns: minmax(0, 1fr);
			}

			.mtl-shop-detail-col {
				position: static;
			}

			.mtl-shop-detail {
				max-height: none;
			}

			.mtl-shop-detail-empty {
				display: none;
			}

			/* Opening a panel scrolls the sheet to it; this keeps the grabber
				above it in view. */
			.mtl-shop-detail-panel {
				scroll-margin-top: 60px;
			}

			.mtl-shop-detail-col:not(:has(.mtl-shop-detail-panel:target)) {
				display: none;
			}

			html:has(.mtl-shop-detail-panel:target) {
				overflow: hidden;
			}

			.mtl-shop-detail-col:has(.mtl-shop-detail-panel:target) .mtl-shop-scrim {
				display: block;
				position: fixed;
				inset: 0;
				z-index: 80;
				background: rgba(15, 22, 27, .55);
			}

			.mtl-shop-detail-col:has(.mtl-shop-detail-panel:target) .mtl-shop-detail {
				position: fixed;
				left: 0;
				right: 0;
				bottom: 0;
				z-index: 81;
				width: 100%;
				max-width: 640px;
				max-height: 88vh;
				margin: 0 auto;
				border: 0;
				border-radius: 14px 14px 0 0;
				box-shadow: 0 -8px 30px rgba(0, 0, 0, .25);
			}

			.mtl-shop-detail-col:has(.mtl-shop-detail-panel:target) .mtl-shop-grabber {
				display: block;
				width: 44px;
				height: 5px;
				margin: 8px auto 0;
				border-radius: 3px;
				background: var(--mtl-rule);
			}

			/* Sticky, so it stays in reach while the sheet scrolls; the
				negative margin lays it over the photo rather than above it. */
			.mtl-shop-detail-col:has(.mtl-shop-detail-panel:target) .mtl-shop-detail-close {
				display: flex;
				position: sticky;
				top: 10px;
				z-index: 2;
				align-items: center;
				justify-content: center;
				width: 44px;
				height: 44px;
				margin: 0 10px -44px auto;
				background: var(--mtl-panel);
				border: 1px solid var(--mtl-rule);
				border-radius: 50%;
				color: var(--mtl-ink);
				font-size: 24px;
				line-height: 1;
				text-decoration: none;
			}
		}

		@media (max-width: 760px) {
			.mtl-shop {
				padding: 22px 16px 44px;
			}

			.mtl-shop-search {
				flex-direction: column;
			}

			/* One line that scrolls sideways, running to the screen edge, until
				the full list is opened from its end. */
			.mtl-shop-shelf {
				margin-right: -16px;
			}

			.mtl-shop-shelf-chips {
				flex-wrap: nowrap;
				overflow-x: auto;
				padding: 0 16px 4px 0;
			}

			.mtl-shop-shelf-chips:has(.mtl-shop-cats[open]) {
				flex-wrap: wrap;
				overflow: visible;
			}

			.mtl-shop-shelf-chips > li {
				flex: none;
			}

			.mtl-shop-shelf .mtl-chip,
			.mtl-shop-views a,
			.mtl-shop-sort > summary,
			.mtl-shop-sort-menu a,
			.mtl-shop-cats-list a {
				min-height: 44px;
			}

			.mtl-shop-cats-list {
				columns: 1;
			}

			.mtl-shop-filters-panel {
				padding: 18px 16px;
			}

			.mtl-shop-filters-cols {
				grid-template-columns: minmax(0, 1fr);
				gap: 22px;
			}

			.mtl-shop-filters .mtl-tx-tree {
				height: 240px;
			}

			.mtl-shop-filters-foot p {
				margin: 0;
			}

			.mtl-shop-filters-foot .mtl-btn-primary {
				width: 100%;
			}

			.mtl-shop-results-bar {
				gap: 10px 12px;
			}

			.mtl-shop-count {
				flex-basis: 100%;
			}

			.mtl-shop-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 16px 12px;
			}

			.mtl-shop-card-name {
				font-size: 17px;
			}

			.mtl-shop-t-no,
			.mtl-shop-t-cat,
			.mtl-shop-t-wait {
				display: none;
			}

			.mtl-shop-t-wait-inline {
				display: block;
			}

			.mtl-shop-t-thumb {
				width: 60px;
			}

			.mtl-shop-t-thumb img,
			.mtl-shop-t-nophoto {
				width: 48px;
				height: 36px;
			}

			.mtl-shop-table td {
				padding: 8px 10px;
			}

			.mtl-shop-t-name a {
				font-size: 16.5px;
			}

			.mtl-shop-c-row {
				grid-template-columns: minmax(0, 1fr) auto;
				row-gap: 0;
				padding: 8px 12px;
			}

			.mtl-shop-c-brand {
				grid-column: 1;
				grid-row: 2;
				font-size: 13.5px;
			}

			.mtl-shop-c-row .mtl-status {
				grid-column: 2;
				grid-row: 1;
				justify-self: end;
			}

			.mtl-shop-c-wait {
				grid-column: 2;
				grid-row: 2;
				font-size: 13.5px;
			}

			.mtl-shop-detail .mtl-shop-detail-name {
				font-size: 26px;
			}
		}

		<?php echo mtl_shop_badge_pill_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS from a developer-defined string, never user input. ?>
	<?php
	return ob_get_clean();
}

/**
 * Renders the public tool catalog.
 *
 * May set the mtl_catalog_view cookie, so it must run before any output;
 * mtl_render_front_main_page() prints the shell only after this returns.
 *
 * @return string Catalog HTML.
 */
function mtl_render_shop_page() {
	global $wpdb;

	$tbl_inv        = $wpdb->prefix . 'tool_inventory';
	$tbl_cats       = $wpdb->prefix . 'tool_categories';
	$tbl_cat_map    = $wpdb->prefix . 'tool_category_mappings';
	$tbl_tags       = $wpdb->prefix . 'tool_tags';
	$tbl_tag_map    = $wpdb->prefix . 'tool_tag_mappings';
	$tbl_subcats    = $wpdb->prefix . 'tool_subcategories';
	$tbl_subcat_map = $wpdb->prefix . 'tool_subcategory_mappings';
	$tbl_trainings  = $wpdb->prefix . 'member_trainings';
	$tbl_tool_train = $wpdb->prefix . 'tool_training_mappings';
	$tbl_loans      = $wpdb->prefix . 'loans';
	$tbl_res        = $wpdb->prefix . 'tool_reservations';

	// Bail gracefully if the plugin's tables don't exist yet.
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_inv ) ) ) {
		return '<div class="mtl-front-card"><p>Our tool catalog is being set up. Please check back soon.</p></div>';
	}

	$look = mtl_public_look();

	// Lookup data for the filter panel, fetched before the filters are read
	// because the selections are validated against it below. $tx_rows carries
	// the categories and their sub-categories together, so it is also what the
	// tree renders from.
	$tx_rows = mtl_taxonomy_tree_rows();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	$tags_list = $wpdb->get_results( "SELECT tag_id, tag_name FROM {$tbl_tags} ORDER BY tag_name ASC" );

	$cat_names   = array();
	$sub_names   = array();
	$sub_parents = array();
	foreach ( $tx_rows as $tx_cat ) {
		$cat_names[ (int) $tx_cat->category_id ] = $tx_cat->category_name;
		foreach ( $tx_cat->children as $tx_sub ) {
			$sub_names[ (int) $tx_sub->subcategory_id ]   = $tx_sub->subcategory_name;
			$sub_parents[ (int) $tx_sub->subcategory_id ] = array( (int) $tx_cat->category_id, $tx_sub->subcategory_name );
		}
	}
	$tag_names = array();
	foreach ( $tags_list as $tg ) {
		$tag_names[ (int) $tg->tag_id ] = $tg->tag_name;
	}

	// The filter menus' options. The values are what the query understands;
	// the labels match the staff Inventory page's filters, in sentence case.
	$status_options  = array(
		''            => 'Any',
		'available'   => 'Available',
		'onloan'      => 'On loan',
		'maintenance' => 'Under maintenance',
		'noreserved'  => 'No reservations',
	);
	$retired_options = array(
		''        => 'Active only',
		'include' => 'Active + retired',
		'only'    => 'Retired only',
	);

	// Read + sanitize the request parameters.
	$q        = isset( $_GET['mtl_q'] ) ? sanitize_text_field( wp_unslash( $_GET['mtl_q'] ) ) : '';
	$a_name   = isset( $_GET['mtl_name'] ) ? sanitize_text_field( wp_unslash( $_GET['mtl_name'] ) ) : '';
	$a_brand  = isset( $_GET['mtl_brand'] ) ? sanitize_text_field( wp_unslash( $_GET['mtl_brand'] ) ) : '';
	$a_status = isset( $_GET['mtl_status'] ) ? sanitize_key( wp_unslash( $_GET['mtl_status'] ) ) : '';
	$sort     = isset( $_GET['mtl_sort'] ) ? sanitize_key( wp_unslash( $_GET['mtl_sort'] ) ) : '';
	$page_no  = isset( $_GET['mtl_pg'] ) ? max( 1, (int) $_GET['mtl_pg'] ) : 1;
	$sel_id   = isset( $_GET['mtl_tool'] ) ? (int) $_GET['mtl_tool'] : 0;

	// Unknown status values fold to no filter, like mtl_retired and mtl_sort.
	if ( ! isset( $status_options[ $a_status ] ) ) {
		$a_status = '';
	}

	// Retired scope: '' active only (the default, and the only state a plain
	// catalog URL produces), 'include' active + retired, 'only' retired.
	// Whitelisted, so a stale or hand-edited value folds to the default rather
	// than widening what the catalog lists.
	$a_retired = isset( $_GET['mtl_retired'] ) ? sanitize_key( wp_unslash( $_GET['mtl_retired'] ) ) : '';
	if ( ! isset( $retired_options[ $a_retired ] ) ) {
		$a_retired = '';
	}

	// The view: one named in the link, else the visitor's last pick from the
	// view switch (a cookie), else the Setup default. Old links say "rows",
	// which was the table. Only the switch's own links carry mtl_set_view, so
	// a link someone shares shows its view without changing the recipient's.
	$views     = array( 'cards', 'table', 'compact' );
	$url_view  = isset( $_GET['mtl_view'] ) ? sanitize_key( wp_unslash( $_GET['mtl_view'] ) ) : '';
	$url_view  = 'rows' === $url_view ? 'table' : $url_view;
	$url_view  = in_array( $url_view, $views, true ) ? $url_view : '';
	$saved     = isset( $_COOKIE['mtl_catalog_view'] ) ? sanitize_key( wp_unslash( $_COOKIE['mtl_catalog_view'] ) ) : '';
	$view_name = $look['default_view'];
	if ( '' !== $url_view ) {
		$view_name = $url_view;
	} elseif ( in_array( $saved, $views, true ) ) {
		$view_name = $saved;
	}
	if ( '' !== $url_view && isset( $_GET['mtl_set_view'] ) && ! headers_sent() ) {
		setcookie(
			'mtl_catalog_view',
			$url_view,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		// Reload without the flag, so a link copied from the address bar
		// doesn't reset whoever opens it.
		wp_safe_redirect( remove_query_arg( 'mtl_set_view' ) );
		exit;
	}

	// Categories, sub-categories and tags all arrive as id lists. The (array)
	// cast also accepts a single scalar, so old bookmarks and shared links
	// still work. Ids that don't exist are dropped here, so a stale or
	// hand-edited value isn't carried into every link, and the IN () lists
	// below only ever hold real ids.
	$id_list_param = function ( $key, array $valid_ids ) {
		if ( ! isset( $_GET[ $key ] ) ) {
			return array();
		}
		$requested = array_map( 'intval', (array) wp_unslash( $_GET[ $key ] ) );
		return array_values( array_unique( array_intersect( $requested, $valid_ids ) ) );
	};
	$a_cats        = $id_list_param( 'mtl_cat', array_keys( $cat_names ) );
	$a_tags        = $id_list_param( 'mtl_tag', array_keys( $tag_names ) );
	$a_subcats     = $id_list_param( 'mtl_subcat', array_keys( $sub_names ) );

	$advanced_active = ( '' !== $a_name || '' !== $a_brand || $a_cats || $a_subcats || $a_tags || '' !== $a_status || '' !== $a_retired );
	$filtered        = '' !== $q || $advanced_active;

	// Whitelisted sort modes: each mtl_sort value maps to a fixed ORDER BY
	// fragment (never user SQL) and its menu label. The query and the sort
	// menu both read this list, so a new option only needs adding here.
	$sort_modes = array(
		''          => array(
			'order' => 't.tool_id DESC',
			'label' => 'Newest',
		),
		'oldest'    => array(
			'order' => 't.tool_id ASC',
			'label' => 'Oldest',
		),
		'name'      => array(
			'order' => 't.tool_name ASC, t.tool_id ASC',
			'label' => 'Name A&ndash;Z',
		),
		'name_desc' => array(
			'order' => 't.tool_name DESC, t.tool_id DESC',
			'label' => 'Name Z&ndash;A',
		),
		'brand'     => array(
			'order' => 't.brand ASC, t.tool_id ASC',
			'label' => 'Brand',
		),
	);
	// Unknown values, including "newest", fold to the default sort.
	if ( ! isset( $sort_modes[ $sort ] ) ) {
		$sort = '';
	}
	$order_by = $sort_modes[ $sort ]['order'];

	$page_sizes = array(
		'cards'   => 12,
		'table'   => 20,
		'compact' => 30,
	);
	$per_page   = $page_sizes[ $view_name ];

	// Build the WHERE from the active filters: conditions use %s / %d
	// placeholders, and $args holds their values for $wpdb->prepare(). Every
	// {$tbl_*} / {$sub_*} / {$from} interpolated below is a table name or
	// fixed SQL built from $wpdb->prefix and whitelists, never request data.
	// phpcs can't see that across this many lines, hence the disable block.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	// Retired tools are hidden unless the Retired filter asks for them (see
	// tool_inventory.retired_at in admin/schema.sql). Kept as its own fragment
	// because the deep-linked tool's separate query needs it too, or a retired
	// tool picked from a retired-only list would open an empty detail box.
	$retired_where = 'only' === $a_retired ? 't.retired_at IS NOT NULL' : 't.retired_at IS NULL';
	if ( 'include' === $a_retired ) {
		$retired_where = '';
	}

	$where = array();
	if ( '' !== $retired_where ) {
		$where[] = $retired_where;
	}
	$args = array();

	if ( '' !== $q ) {
		$like    = '%' . $wpdb->esc_like( $q ) . '%';
		$where[] = '(t.tool_name LIKE %s OR t.brand LIKE %s OR t.description LIKE %s'
			. " OR EXISTS (SELECT 1 FROM {$tbl_cat_map} xcm JOIN {$tbl_cats} xc ON xcm.category_id = xc.category_id WHERE xcm.tool_id = t.tool_id AND xc.category_name LIKE %s)"
			. " OR EXISTS (SELECT 1 FROM {$tbl_tag_map} xtm JOIN {$tbl_tags} xt ON xtm.tag_id = xt.tag_id WHERE xtm.tool_id = t.tool_id AND xt.tag_name LIKE %s))";
		array_push( $args, $like, $like, $like, $like, $like );
	}
	if ( '' !== $a_name ) {
		$where[] = 't.tool_name LIKE %s';
		$args[]  = '%' . $wpdb->esc_like( $a_name ) . '%';
	}
	if ( '' !== $a_brand ) {
		$where[] = 't.brand LIKE %s';
		$args[]  = '%' . $wpdb->esc_like( $a_brand ) . '%';
	}
	// Picking several categories (or tags) matches tools with ANY of them, so
	// it widens the results. Categories and sub-categories are one control,
	// OR'd together (see TAXONOMY TREE FILTER in my-tool-library.php). That
	// control, tags and the other filters combine with each other as AND.
	list( $tx_where, $tx_args ) = mtl_taxonomy_where( $a_cats, $a_subcats, 't.tool_id' );
	if ( '' !== $tx_where ) {
		$where[] = $tx_where;
		$args    = array_merge( $args, $tx_args );
	}
	if ( $a_tags ) {
		$tag_ph  = implode( ',', array_fill( 0, count( $a_tags ), '%d' ) );
		$where[] = "EXISTS (SELECT 1 FROM {$tbl_tag_map} ftm WHERE ftm.tool_id = t.tool_id AND ftm.tag_id IN ({$tag_ph}))";
		$args    = array_merge( $args, $a_tags );
	}
	$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

	// The availability filter compares computed loan/reservation counts, so
	// it belongs in HAVING (after GROUP BY) rather than WHERE.
	// Maintenance is a plain column, so it goes in WHERE: a tool under
	// maintenance is not "Available" even when nobody has it on loan.
	$having = '';
	if ( 'available' === $a_status ) {
		$having     = 'HAVING active_loans = 0';
		$where_sql .= ( '' === $where_sql ? 'WHERE ' : ' AND ' ) . 't.maintenance_at IS NULL AND t.retired_at IS NULL';
	} elseif ( 'maintenance' === $a_status ) {
		// Both filters exclude retired tools, matching their status line,
		// which says Retired and never Available or Under maintenance.
		$where_sql .= ( '' === $where_sql ? 'WHERE ' : ' AND ' ) . 't.maintenance_at IS NOT NULL AND t.retired_at IS NULL';
	} elseif ( 'onloan' === $a_status ) {
		$having = 'HAVING active_loans > 0';
	} elseif ( 'noreserved' === $a_status ) {
		$having = 'HAVING active_res = 0';
	}

	// Scalar subqueries reused for both status and display, with no placeholders.
	$sub_loans = "(SELECT COUNT(*) FROM {$tbl_loans} l WHERE l.tool_id = t.tool_id AND l.return_date IS NULL)";
	$sub_res   = "(SELECT COUNT(*) FROM {$tbl_res} r WHERE r.tool_id = t.tool_id AND r.expiry_date IS NULL)";

	$from = "FROM {$tbl_inv} t"
		. " LEFT JOIN {$tbl_cat_map} tcm ON t.tool_id = tcm.tool_id"
		. " LEFT JOIN {$tbl_cats} c ON tcm.category_id = c.category_id"
		. " LEFT JOIN {$tbl_tag_map} ttm ON t.tool_id = ttm.tool_id"
		. " LEFT JOIN {$tbl_tags} tg ON ttm.tag_id = tg.tag_id"
		. " LEFT JOIN {$tbl_subcat_map} tsm ON t.tool_id = tsm.tool_id"
		. " LEFT JOIN {$tbl_subcats} sc ON tsm.subcategory_id = sc.subcategory_id"
		. " LEFT JOIN {$tbl_tool_train} ttr ON t.tool_id = ttr.tool_id"
		. " LEFT JOIN {$tbl_trainings} tr ON ttr.training_id = tr.training_id";

	// Total matching count (for pagination). Every filter is its own EXISTS
	// subquery, so the count skips $from's joins, which only multiply rows.
	$count_sql = "SELECT COUNT(*) FROM (SELECT t.tool_id, {$sub_loans} AS active_loans, {$sub_res} AS active_res FROM {$tbl_inv} t {$where_sql} GROUP BY t.tool_id {$having}) sub";
	$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );

	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	if ( $page_no > $total_pages ) {
		$page_no = $total_pages;
	}
	$offset = ( $page_no - 1 ) * $per_page;

	// The page of results. t.location is selected whatever the Setup switch
	// says; mtl_shop_location_block() is the single place that decides whether
	// a member is shown it, so the query does not have to be built two ways.
	// The selected-tool query below reuses $select, so both give the panel the
	// same columns. The id lists let the panel pair each sub-category with
	// its category.
	$select    = 'SELECT t.tool_id, t.tool_name, t.brand, t.description, t.components, t.photo_url, t.location, t.retired_at, t.maintenance_at'
		. mtl_tool_link_columns_sql() . ','
		. " GROUP_CONCAT(DISTINCT c.category_name ORDER BY c.category_name SEPARATOR ', ') AS categories,"
		. ' GROUP_CONCAT(DISTINCT c.category_id) AS category_ids,'
		. ' GROUP_CONCAT(DISTINCT sc.subcategory_id) AS subcategory_ids,'
		. " GROUP_CONCAT(DISTINCT tg.tag_name ORDER BY tg.tag_name SEPARATOR ', ') AS tags,"
		. " GROUP_CONCAT(DISTINCT tr.training_name ORDER BY tr.training_name SEPARATOR ', ') AS required_trainings,"
		. " {$sub_loans} AS active_loans, {$sub_res} AS active_res";
	$page_sql  = "{$select} {$from} {$where_sql} GROUP BY t.tool_id {$having} ORDER BY {$order_by} LIMIT %d OFFSET %d";
	$page_args = array_merge( $args, array( $per_page, $offset ) );
	$tools     = $wpdb->get_results( $wpdb->prepare( $page_sql, $page_args ) );

	// The selected tool for the detail box, fetched independently so it
	// shows even if it isn't on the current page of results.
	$selected = null;
	if ( $sel_id > 0 ) {
		$sel_retired_sql = '' !== $retired_where ? ' AND ' . $retired_where : '';
		$selected        = $wpdb->get_row(
			$wpdb->prepare(
				"{$select} {$from} WHERE t.tool_id = %d{$sel_retired_sql} GROUP BY t.tool_id",
				$sel_id
			)
		);
	}

	// Active tools per category for the category links: one grouped query.
	$cat_counts = array();
	if ( $look['show_categories'] && $cat_names ) {
		foreach ( $wpdb->get_results( "SELECT cm.category_id, COUNT(DISTINCT cm.tool_id) AS n FROM {$tbl_cat_map} cm JOIN {$tbl_inv} t ON t.tool_id = cm.tool_id WHERE t.retired_at IS NULL GROUP BY cm.category_id" ) as $row ) {
			$cat_counts[ (int) $row->category_id ] = (int) $row->n;
		}
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

	// Tools that get a pre-rendered detail panel: this page's results plus
	// the deep-linked tool if it isn't among them. Both queries select every
	// column the panel needs, so this adds no extra queries.
	$panel_tools = $tools;
	if ( $selected ) {
		$page_tool_ids = array_map( 'intval', array_column( $tools, 'tool_id' ) );
		if ( ! in_array( (int) $selected->tool_id, $page_tool_ids, true ) ) {
			$panel_tools[] = $selected;
		}
	}

	// URL helpers. All links preserve the current filter/sort/view state and
	// only override what changes; the search form carries the same state
	// through its fields and hidden inputs. The view is carried only when the
	// link named one: otherwise the cookie or the default gives the same view.
	$base  = mtl_front_page_url( 'main' );
	$state = array(
		'mtl_q'       => $q,
		'mtl_name'    => $a_name,
		'mtl_brand'   => $a_brand,
		// Id lists: add_query_arg() expands these to mtl_cat[0]=..&mtl_cat[1]=..
		// and PHP reads them straight back as an array. Empty stays '' so the
		// $not_empty filter below drops the key entirely.
		'mtl_cat'     => $a_cats ? $a_cats : '',
		'mtl_subcat'  => $a_subcats ? $a_subcats : '',
		'mtl_tag'     => $a_tags ? $a_tags : '',
		'mtl_status'  => $a_status,
		'mtl_retired' => $a_retired,
		'mtl_sort'    => $sort,
		'mtl_view'    => $url_view,
		'mtl_tool'    => $sel_id > 0 ? $sel_id : '',
	);
	// Drop empty values so URLs stay tidy.
	$not_empty   = function ( $v ) {
		return '' !== $v && null !== $v;
	};
	$clean_state = array_filter( $state, $not_empty );

	// Pagination, sort and view links change the query string, so they reload
	// the page. When the URL carries mtl_tool, the matching #tool-<id>
	// fragment is appended too, since only :target shows the detail panel.
	$make_url = function ( array $overrides ) use ( $base, $clean_state, $not_empty ) {
		$args = array_filter( array_merge( $clean_state, $overrides ), $not_empty );
		// add_query_arg() doesn't encode values, so a search for "AT&T" would
		// otherwise split into two parameters in every link.
		$url = add_query_arg( rawurlencode_deep( $args ), $base );
		if ( ! empty( $args['mtl_tool'] ) ) {
			$url .= '#' . mtl_shop_panel_id( $args['mtl_tool'] );
		}
		return esc_url( $url );
	};

	// A fresh catalog in the same view and order: the category links and
	// "Clear filters" start from this.
	$fresh_url = function ( array $args = array() ) use ( $base, $url_view, $sort, $not_empty ) {
		$args = array_filter(
			array_merge(
				array(
					'mtl_view' => $url_view,
					'mtl_sort' => $sort,
				),
				$args
			),
			$not_empty
		);
		return esc_url( add_query_arg( rawurlencode_deep( $args ), $base ) );
	};
	$clear_url = $fresh_url();

	// One chip per filter value in use, each a link to this page without it.
	$drop_url     = function ( $key, $id = null ) use ( $make_url, $state ) {
		$value = '';
		if ( null !== $id && is_array( $state[ $key ] ) ) {
			$value = array_values( array_diff( $state[ $key ], array( $id ) ) );
			$value = $value ? $value : '';
		}
		return $make_url(
			array(
				$key       => $value,
				'mtl_pg'   => '',
				'mtl_tool' => '',
			)
		);
	};
	$active_chips = array();
	if ( '' !== $q ) {
		$active_chips[] = array( '“' . $q . '”', $drop_url( 'mtl_q' ) );
	}
	if ( '' !== $a_name ) {
		$active_chips[] = array( 'Name: ' . $a_name, $drop_url( 'mtl_name' ) );
	}
	if ( '' !== $a_brand ) {
		$active_chips[] = array( 'Brand: ' . $a_brand, $drop_url( 'mtl_brand' ) );
	}
	foreach ( $a_cats as $id ) {
		$active_chips[] = array( $cat_names[ $id ], $drop_url( 'mtl_cat', $id ) );
	}
	foreach ( $a_subcats as $id ) {
		$active_chips[] = array( $sub_names[ $id ], $drop_url( 'mtl_subcat', $id ) );
	}
	foreach ( $a_tags as $id ) {
		$active_chips[] = array( $tag_names[ $id ], $drop_url( 'mtl_tag', $id ) );
	}
	if ( '' !== $a_status ) {
		$active_chips[] = array( $status_options[ $a_status ], $drop_url( 'mtl_status' ) );
	}
	if ( '' !== $a_retired ) {
		$active_chips[] = array( 'include' === $a_retired ? 'Including retired' : 'Retired only', $drop_url( 'mtl_retired' ) );
	}

	// The category links: the six with the most tools, and every category in
	// a fold when there are too many to show at once.
	$shelf = array();
	if ( $look['show_categories'] ) {
		foreach ( $cat_names as $id => $name ) {
			$shelf[ $id ] = array(
				'name'  => $name,
				'count' => isset( $cat_counts[ $id ] ) ? $cat_counts[ $id ] : 0,
				'url'   => $fresh_url( array( 'mtl_cat' => array( $id ) ) ),
			);
		}
	}
	$shelf_fold = count( $shelf ) > 8;
	$shelf_top  = $shelf;
	uasort(
		$shelf_top,
		function ( $a, $b ) {
			$by_count = $b['count'] <=> $a['count'];
			return 0 !== $by_count ? $by_count : strcasecmp( $a['name'], $b['name'] );
		}
	);
	if ( $shelf_fold ) {
		$shelf_top = array_slice( $shelf_top, 0, 6, true );
	}
	$single_cat = 1 === count( $a_cats ) ? $a_cats[0] : 0;

	// The count line.
	$count_html = '<strong>' . esc_html( number_format( $total ) . ' ' . ( 1 === $total ? 'tool' : 'tools' ) ) . '</strong>';
	if ( $filtered ) {
		$what       = $advanced_active ? 'your filters' : 'your search';
		$count_html = $total ? $count_html . ( 1 === $total ? ' matches ' : ' match ' ) . $what : 'No tools match ' . $what;
	}
	if ( $total_pages > 1 ) {
		$count_html .= ', page ' . (int) $page_no . ' of ' . (int) $total_pages;
	}
	$page_label = $total_pages > 1 ? 'Tools, page ' . $page_no . ' of ' . $total_pages : 'Tools';

	// Viewer's member context, fetched once and handed to every detail panel
	// so the Reserve control can adapt to who's looking (see
	// mtl_shop_render_detail_panel). The lookups only run when signed in.
	$member_ctx = array(
		// The catalog preview before launch: every visitor counts as signed
		// out, even one who is signed in, and no tool offers a way to reserve.
		'not_live'            => mtl_is_prelaunch_visitor(),
		// Nobody reserves before launch, staff who are also members included,
		// matching mtl_handle_reserve_action().
		'reserve_closed'      => ! mtl_library_is_live(),
		'is_member'           => false,
		'is_locked'           => false,
		'is_admin'            => ( is_user_logged_in() && mtl_can_manage_library() ),
		'reserved'            => array(), // Tool_id => true (active reservations).
		'loaned'              => array(), // Tool_id => true (currently on loan).
		'reserve_nonce_field' => '',
		'login_url'           => mtl_front_page_url( 'login' ),
		'signup_url'          => mtl_front_page_url( 'signup' ),
		'reservations_url'    => mtl_front_page_url( 'reservations' ),
		'show_numbers'        => $look['show_tool_numbers'],
		'cat_names'           => $cat_names,
		'sub_parents'         => $sub_parents,
	);
	$viewer     = $member_ctx['not_live'] ? null : mtl_current_member();
	if ( $viewer ) {
		$mid                               = (int) $viewer->member_id;
		$member_ctx['is_member']           = true;
		$member_ctx['is_locked']           = ! empty( $viewer->locked_at );
		$member_ctx['reserve_nonce_field'] = wp_nonce_field( 'mtl_reserve_action', 'mtl_reserve_nonce', true, false );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT tool_id FROM {$tbl_res} WHERE member_id = %d AND expiry_date IS NULL", $mid ) ) as $tid ) {
			$member_ctx['reserved'][ (int) $tid ] = true;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT tool_id FROM {$tbl_loans} WHERE member_id = %d AND return_date IS NULL", $mid ) ) as $tid ) {
			$member_ctx['loaned'][ (int) $tid ] = true;
		}
	}

	$show_numbers = $look['show_tool_numbers'];

	// ======================================================================
	// RENDER
	// ======================================================================
	ob_start();
	?>
	<style>
	<?php echo mtl_shop_page_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS, never user input. ?>
	</style>

	<div class="mtl-shop">
		<?php
		// Close target for the phone sheet: its id matches no panel, so
		// linking here clears :target and closes it.
		?>
		<div id="mtl-shop-closed" class="mtl-shop-close-anchor" aria-hidden="true"></div>

		<?php if ( $member_ctx['not_live'] ) : ?>
			<?php // The catalog preview before launch, which shows no account banners at all. ?>
			<div class="mtl-shop-prelaunch-banner">
				<strong>Coming soon.</strong>
				<?php echo nl2br( esc_html( mtl_coming_soon_message() ) ); ?>
			</div>
		<?php else : ?>
			<?php // Above everything else on the catalog, for as long as the account stays locked. ?>
			<?php echo mtl_account_locked_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>
		<?php endif; ?>

		<?php // One-off status banner after a reserve/cancel action. ?>
		<?php echo mtl_front_notice_html(); ?>
		<?php if ( ! $member_ctx['not_live'] ) : ?>
			<?php echo mtl_agreements_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>
		<?php endif; ?>

		<h1 class="mtl-page-title">Find a tool to borrow</h1>

		<?php
		// One GET form for the search box and every filter, so Search and
		// Show tools both submit everything.
		?>
		<form class="mtl-shop-find" method="get" action="<?php echo esc_url( $base ); ?>" role="search" aria-label="Tools">
			<?php // Needed on Plain-permalink sites where the pretty route is unavailable. ?>
			<input type="hidden" name="mtl_page" value="main">
			<?php if ( '' !== $url_view ) : ?>
				<input type="hidden" name="mtl_view" value="<?php echo esc_attr( $url_view ); ?>">
			<?php endif; ?>
			<?php if ( '' !== $sort ) : ?>
				<input type="hidden" name="mtl_sort" value="<?php echo esc_attr( $sort ); ?>">
			<?php endif; ?>

			<div class="mtl-shop-search">
				<label class="mtl-sr-only" for="mtl-shop-q">Search tools</label>
				<input type="search" id="mtl-shop-q" class="mtl-shop-search-input" name="mtl_q" value="<?php echo esc_attr( $q ); ?>" placeholder="Drill, ladder, tile saw, brand&hellip;">
				<button type="submit" class="mtl-btn mtl-btn-primary">Search</button>
			</div>

			<?php if ( $shelf ) : ?>
				<nav class="mtl-shop-shelf" aria-label="Browse by category">
					<ul class="mtl-shop-shelf-chips">
						<?php foreach ( $shelf_top as $id => $cat ) : ?>
							<li><a class="mtl-chip" href="<?php echo $cat['url']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url()-escaped by $fresh_url(). ?>"<?php echo $single_cat === $id ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $cat['name'] ); ?> <span class="mtl-shop-chip-n"><?php echo esc_html( $cat['count'] ); ?></span></a></li>
						<?php endforeach; ?>
						<?php if ( $shelf_fold ) : ?>
							<li class="mtl-shop-cats-item">
								<details class="mtl-shop-cats">
									<summary class="mtl-chip">All <?php echo esc_html( count( $shelf ) ); ?> categories</summary>
									<ul class="mtl-shop-cats-list">
										<?php foreach ( $shelf as $id => $cat ) : ?>
											<li><a href="<?php echo $cat['url']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url()-escaped by $fresh_url(). ?>"<?php echo $single_cat === $id ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $cat['name'] ); ?> <span class="mtl-shop-chip-n"><?php echo esc_html( $cat['count'] ); ?></span></a></li>
										<?php endforeach; ?>
									</ul>
								</details>
							</li>
						<?php endif; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<?php
			// Closed even while filters are in use: the chips above the
			// results say what is set.
			?>
			<details class="mtl-shop-filters">
				<summary>More filters</summary>
				<div class="mtl-shop-filters-panel mtl-panel">
					<div class="mtl-shop-filters-cols">
						<div>
							<div class="mtl-field">
								<label for="mtl-a-name">Tool name</label>
								<input type="text" id="mtl-a-name" name="mtl_name" value="<?php echo esc_attr( $a_name ); ?>">
							</div>
							<div class="mtl-field">
								<label for="mtl-a-brand">Brand</label>
								<input type="text" id="mtl-a-brand" name="mtl_brand" value="<?php echo esc_attr( $a_brand ); ?>">
							</div>
							<div class="mtl-field">
								<label for="mtl-a-status">Availability</label>
								<select id="mtl-a-status" name="mtl_status">
									<?php foreach ( $status_options as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $a_status, $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<?php
							// Same states and wording as the staff Inventory page's
							// Retired filter, so volunteers who know one know both.
							?>
							<div class="mtl-field">
								<label for="mtl-a-retired">Retired tools</label>
								<select id="mtl-a-retired" name="mtl_retired">
									<?php foreach ( $retired_options as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $a_retired, $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div role="group" aria-labelledby="mtl-shop-tx-label">
							<p class="mtl-shop-group-label" id="mtl-shop-tx-label">Categories</p>
							<p class="mtl-hint">Ticking a category includes all of its sub-categories.</p>
							<?php mtl_taxonomy_tree( $tx_rows, $a_cats, $a_subcats, 'mtl-shop-tx' ); ?>
						</div>
						<div role="group" aria-labelledby="mtl-shop-tag-label">
							<p class="mtl-shop-group-label" id="mtl-shop-tag-label">Tags</p>
							<?php if ( $tags_list ) : ?>
								<p class="mtl-hint">Tick as many as you like.</p>
								<div class="mtl-shop-tags">
									<?php foreach ( $tags_list as $tg ) : ?>
										<label><input type="checkbox" name="mtl_tag[]" value="<?php echo esc_attr( $tg->tag_id ); ?>" <?php checked( in_array( (int) $tg->tag_id, $a_tags, true ) ); ?>><span><?php echo esc_html( $tg->tag_name ); ?></span></label>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<p class="mtl-shop-tags-empty">No tags yet.</p>
							<?php endif; ?>
						</div>
					</div>
					<div class="mtl-shop-filters-foot">
						<button type="submit" class="mtl-btn mtl-btn-primary">Show tools</button>
						<a href="<?php echo $clear_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url()-escaped by $fresh_url(). ?>">Clear filters</a>
						<p>A tool has to match every section you use. Within the category and tag lists, matching any one ticked box is enough.</p>
					</div>
				</div>
			</details>
		</form>

		<div class="mtl-shop-results-bar">
			<p class="mtl-shop-count"><?php echo $count_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html() and integers. ?></p>
			<nav class="mtl-shop-views" aria-label="View">
				<?php
				// The switch keeps every filter and lands on page 1. Its links
				// alone carry mtl_set_view, which saves the pick in a cookie.
				foreach ( array(
					'cards'   => 'Cards',
					'table'   => 'Table',
					'compact' => 'Compact',
				) as $view_val => $view_label ) :
					$view_url = $make_url(
						array(
							'mtl_view'     => $view_val,
							'mtl_set_view' => 1,
							'mtl_pg'       => '',
						)
					);
					?>
					<a href="<?php echo $view_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $make_url() always returns esc_url()-escaped output. ?>"<?php echo $view_name === $view_val ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $view_label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<details class="mtl-shop-sort">
				<summary>Sort: <?php echo $sort_modes[ $sort ]['label']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static developer-defined label from $sort_modes (incl. HTML entities like &ndash;), never user input. ?></summary>
				<div class="mtl-shop-sort-menu">
					<?php
					foreach ( $sort_modes as $val => $mode ) :
						$sort_url = $make_url(
							array(
								'mtl_sort' => $val,
								'mtl_pg'   => '',
							)
						);
						?>
						<a href="<?php echo $sort_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $make_url() always returns esc_url()-escaped output; $val is a fixed key of $sort_modes, never user input. ?>"<?php echo $sort === $val ? ' aria-current="true"' : ''; ?>><?php echo $mode['label']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static developer-defined label from $sort_modes (incl. HTML entities like &ndash;), never user input. ?></a>
					<?php endforeach; ?>
				</div>
			</details>
		</div>

		<?php if ( $active_chips ) : ?>
			<div class="mtl-shop-active">
				<span class="mtl-shop-active-label">Filtered by</span>
				<?php foreach ( $active_chips as $chip ) : ?>
					<a class="mtl-shop-chip-x" href="<?php echo $chip[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $make_url() always returns esc_url()-escaped output. ?>" aria-label="<?php echo esc_attr( 'Remove filter: ' . $chip[0] ); ?>"><?php echo esc_html( $chip[0] ); ?> <b aria-hidden="true">&times;</b></a>
				<?php endforeach; ?>
				<a class="mtl-shop-clear-all" href="<?php echo $clear_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url()-escaped by $fresh_url(). ?>">Clear all</a>
			</div>
		<?php endif; ?>

		<div class="mtl-shop-layout">
			<div class="mtl-shop-main">
				<h2 class="mtl-sr-only">Tools</h2>
				<?php if ( empty( $tools ) ) : ?>
					<?php
					// Optional "request a tool" ask from the Setup page. The
					// message and link are independent; either blank is left out.
					// The link is re-normalized on read, like the giving link.
					$request_text = trim( (string) get_option( 'mtl_tool_request_text', mtl_default_tool_request_text() ) );
					$request_url  = mtl_normalize_web_url( get_option( 'mtl_tool_request_url', '' ) );
					if ( '' !== $q ) {
						$empty_title = 'No tools match “' . $q . '”' . ( $advanced_active ? ' with these filters.' : '.' );
					} elseif ( $advanced_active ) {
						$empty_title = 'No tools match these filters.';
					} else {
						$empty_title = 'There are no tools in the catalog yet. Please check back soon.';
					}
					?>
					<div class="mtl-shop-empty">
						<p class="mtl-shop-empty-title"><?php echo esc_html( $empty_title ); ?></p>
						<?php if ( $filtered ) : ?>
							<p><a href="<?php echo $clear_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url()-escaped by $fresh_url(). ?>">Clear filters</a></p>
						<?php endif; ?>
						<?php if ( '' !== $request_text ) : ?>
							<p><?php echo nl2br( esc_html( $request_text ) ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $request_url ) : ?>
							<p><a class="mtl-btn" href="<?php echo esc_url( $request_url ); ?>" target="_blank" rel="noopener noreferrer nofollow">Request a tool</a></p>
						<?php endif; ?>
					</div>
				<?php elseif ( 'table' === $view_name ) : ?>
					<div class="mtl-shop-table-wrap mtl-panel">
						<table class="mtl-shop-table">
							<caption class="mtl-sr-only"><?php echo esc_html( $page_label ); ?></caption>
							<thead>
								<tr>
									<th scope="col"><span class="mtl-sr-only">Photo</span></th>
									<th scope="col">Tool</th>
									<?php if ( $show_numbers ) : ?>
										<th scope="col" class="mtl-shop-t-no">No.</th>
									<?php endif; ?>
									<th scope="col" class="mtl-shop-t-cat">Category</th>
									<th scope="col">Status</th>
									<th scope="col" class="mtl-shop-t-wait">Waiting</th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $tools as $tool ) :
									$res = (int) $tool->active_res;
									?>
									<tr<?php echo (int) $tool->tool_id === $sel_id ? ' class="is-selected"' : ''; ?>>
										<td class="mtl-shop-t-thumb">
											<?php if ( ! empty( $tool->photo_url ) ) : ?>
												<img src="<?php echo esc_url( $tool->photo_url ); ?>" alt="" loading="lazy">
											<?php else : ?>
												<span class="mtl-shop-t-nophoto">No photo</span>
											<?php endif; ?>
										</td>
										<td class="mtl-shop-t-name">
											<?php
											// Bare fragment (no query string) so the browser
											// treats this as same-document navigation and
											// never reloads.
											?>
											<a href="<?php echo esc_url( '#' . mtl_shop_panel_id( $tool->tool_id ) ); ?>"><?php echo esc_html( stripslashes( $tool->tool_name ) ); ?></a>
											<?php if ( ! empty( $tool->brand ) ) : ?>
												<span><?php echo esc_html( stripslashes( $tool->brand ) ); ?></span>
											<?php endif; ?>
										</td>
										<?php if ( $show_numbers ) : ?>
											<td class="mtl-shop-t-no"><?php echo (int) $tool->tool_id; ?></td>
										<?php endif; ?>
										<td class="mtl-shop-t-cat"><?php echo esc_html( (string) $tool->categories ); ?></td>
										<td>
											<?php echo mtl_shop_status_word( (int) $tool->active_loans > 0, ! empty( $tool->retired_at ), ! empty( $tool->maintenance_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?>
											<?php if ( $res > 0 ) : ?>
												<span class="mtl-status-note mtl-shop-t-wait-inline"><?php echo esc_html( $res ); ?> waiting</span>
											<?php endif; ?>
										</td>
										<td class="mtl-shop-t-wait">
											<?php if ( $res > 0 ) : ?>
												<?php echo esc_html( $res ); ?> waiting
											<?php else : ?>
												<span class="mtl-shop-t-none">None</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php elseif ( 'compact' === $view_name ) : ?>
					<ul class="mtl-shop-compact mtl-panel" aria-label="<?php echo esc_attr( $page_label ); ?>">
						<?php
						foreach ( $tools as $tool ) :
							$res = (int) $tool->active_res;
							?>
							<li>
								<a class="mtl-shop-c-row<?php echo (int) $tool->tool_id === $sel_id ? ' is-selected' : ''; ?>" href="<?php echo esc_url( '#' . mtl_shop_panel_id( $tool->tool_id ) ); ?>">
									<span class="mtl-shop-c-name"><?php echo esc_html( stripslashes( $tool->tool_name ) ); ?></span>
									<span class="mtl-shop-c-brand"><?php echo esc_html( stripslashes( (string) $tool->brand ) ); ?></span>
									<?php echo mtl_shop_status_word( (int) $tool->active_loans > 0, ! empty( $tool->retired_at ), ! empty( $tool->maintenance_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?>
									<span class="mtl-shop-c-wait"><?php echo $res > 0 ? esc_html( $res . ' waiting' ) : ''; ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<ul class="mtl-shop-grid" aria-label="<?php echo esc_attr( $page_label ); ?>">
						<?php foreach ( $tools as $tool ) : ?>
							<li>
								<a class="mtl-shop-card<?php echo (int) $tool->tool_id === $sel_id ? ' is-selected' : ''; ?>" href="<?php echo esc_url( '#' . mtl_shop_panel_id( $tool->tool_id ) ); ?>">
									<?php if ( ! empty( $tool->photo_url ) ) : ?>
										<span class="mtl-shop-card-photo"><img src="<?php echo esc_url( $tool->photo_url ); ?>" alt="" loading="lazy">
									<?php else : ?>
										<span class="mtl-shop-card-photo mtl-shop-nophoto">No photo yet
									<?php endif; ?>
									<?php if ( $show_numbers ) : ?>
										<span class="mtl-shop-card-no">No. <?php echo (int) $tool->tool_id; ?></span>
									<?php endif; ?>
									</span>
									<h3 class="mtl-shop-card-name"><?php echo esc_html( stripslashes( $tool->tool_name ) ); ?></h3>
									<?php if ( ! empty( $tool->brand ) ) : ?>
										<span class="mtl-shop-card-brand"><?php echo esc_html( stripslashes( $tool->brand ) ); ?></span>
									<?php endif; ?>
									<span class="mtl-shop-badges"><?php echo mtl_shop_status_badges( (int) $tool->active_loans > 0, $tool->active_res, ! empty( $tool->retired_at ), ! empty( $tool->maintenance_at ) ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $total_pages > 1 ) : ?>
					<nav class="mtl-shop-pagination" aria-label="Pages">
						<?php if ( $page_no > 1 ) : ?>
							<a class="mtl-btn" rel="prev" href="<?php echo $make_url( array( 'mtl_pg' => ( $page_no - 1 <= 1 ) ? '' : $page_no - 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $make_url() always returns esc_url()-escaped output; $page_no is cast (int) at assignment. ?>">Previous page</a>
						<?php else : ?>
							<span class="mtl-shop-page-off">Previous page</span>
						<?php endif; ?>

						<span>Page <?php echo esc_html( $page_no ); ?> of <?php echo esc_html( $total_pages ); ?></span>

						<?php if ( $page_no < $total_pages ) : ?>
							<a class="mtl-btn" rel="next" href="<?php echo $make_url( array( 'mtl_pg' => $page_no + 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $make_url() always returns esc_url()-escaped output; $page_no is cast (int) at assignment. ?>">Next page</a>
						<?php else : ?>
							<span class="mtl-shop-page-off">Next page</span>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			</div>

			<div class="mtl-shop-detail-col">
				<?php // The dimmed catalog behind the phone sheet; tapping it closes the sheet. ?>
				<a href="#mtl-shop-closed" class="mtl-shop-scrim" aria-hidden="true" tabindex="-1"></a>
				<aside class="mtl-shop-detail" id="mtl-shop-detail" aria-label="Tool details">
					<span class="mtl-shop-grabber" aria-hidden="true"></span>
					<a href="#mtl-shop-closed" class="mtl-shop-detail-close" aria-label="Close">&times;</a>
					<p class="mtl-shop-detail-empty">Select a tool to see its details.</p>
					<?php foreach ( $panel_tools as $panel_tool ) : ?>
						<div class="mtl-shop-detail-panel" id="<?php echo esc_attr( mtl_shop_panel_id( $panel_tool->tool_id ) ); ?>" tabindex="-1">
							<?php echo mtl_shop_render_detail_panel( $panel_tool, $base, $member_ctx ); ?>
						</div>
					<?php endforeach; ?>
				</aside>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
