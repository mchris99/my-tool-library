<?php
/**
 * Public tool catalog (server-side rendered, no JavaScript required).
 *
 * Search, advanced filters, sort, tile/row view and pagination are plain GET
 * links; selecting a tool for the detail box uses a same-page URL fragment
 * (e.g. "#tool-45") revealed via the CSS :target pseudo-class, so selection
 * is instant with no reload. Every selectable tool's detail content is
 * pre-rendered (hidden) so :target has something to reveal; see
 * mtl_shop_panel_id() and mtl_shop_render_detail_panel(). A tool can be
 * deep-linked reliably via mtl_shop_tool_share_url(), which pairs the
 * fragment with a matching ?mtl_tool= query string so the link works
 * regardless of the visitor's current filters/pagination.
 *
 * The main render function returns the catalog HTML as a string; the caller
 * drops it into the shared front-end shell (see mtl_render_front_main_page()
 * in my-tool-library.php).
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the availability badges shared by tiles, rows and the detail box.
 *
 * @param bool $on_loan  Whether the tool is currently on loan.
 * @param int  $res_count Number of active reservations for the tool.
 * @return string HTML markup.
 */
function mtl_shop_status_badges( $on_loan, $res_count ) {
	$out = $on_loan
		? '<span class="mtl-shop-badge mtl-shop-badge-out">On Loan</span>'
		: '<span class="mtl-shop-badge mtl-shop-badge-avail">Available</span>';
	if ( (int) $res_count > 0 ) {
		$out .= '<span class="mtl-shop-badge mtl-shop-badge-res">'
			. esc_html( (int) $res_count ) . ' reserved</span>';
	}
	return $out;
}

/**
 * Renders a comma-separated lookup value as a row of small pill badges.
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
 * The CSS for the badges and pills the two helpers above render.
 *
 * Both the catalog (mtl_render_shop_page()) and the member pages
 * (mtl_member_page_styles()) call those helpers, so both need these rules.
 * Keeping one copy here means restyling a badge restyles it everywhere it
 * appears, rather than in whichever of the two files got edited.
 *
 * @return string CSS, ready to drop inside a <style> block.
 */
function mtl_shop_badge_pill_css() {
	ob_start();
	?>
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
	<?php
	return ob_get_clean();
}

/**
 * Canonical DOM id for a tool's hidden detail panel and CSS :target anchor.
 * Used everywhere a fragment or panel id is needed so every call site stays
 * in sync with the same "tool-<id>" format.
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
 * matching fragment makes the browser apply :target on load, and reliable
 * deep-linking with no JS.
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
 * Renders one tool's full detail-panel body: photo, badges, availability,
 * a shareable link, categories/tags, description, components, and a
 * context-aware Reserve control.
 *
 * @param object $tool Tool row from the catalog query.
 * @param string $base Base page URL.
 * @param array  $ctx  Viewer context: is_member, is_admin (bool); reserved,
 *                      loaned (tool_id => true lookups); reserve_nonce_field
 *                      (pre-rendered wp_nonce_field string); login_url,
 *                      signup_url, reservations_url.
 * @return string
 */
function mtl_shop_render_detail_panel( $tool, $base, $ctx = array() ) {
	$on_loan   = ( (int) $tool->active_loans > 0 );
	$res       = (int) $tool->active_res;
	$share_url = mtl_shop_tool_share_url( $tool->tool_id, $base );
	$tool_id   = (int) $tool->tool_id;

	ob_start();
	?>
	<?php if ( ! empty( $tool->photo_url ) ) : ?>
		<img class="mtl-shop-detail-photo" src="<?php echo esc_url( $tool->photo_url ); ?>" alt="<?php echo esc_attr( stripslashes( $tool->tool_name ) ); ?>" loading="lazy">
	<?php endif; ?>
	<div class="mtl-shop-detail-body">
		<p class="mtl-shop-detail-name"><?php echo esc_html( stripslashes( $tool->tool_name ) ); ?></p>
		<?php if ( ! empty( $tool->brand ) ) : ?>
			<p class="mtl-shop-detail-brand"><?php echo esc_html( stripslashes( $tool->brand ) ); ?></p>
		<?php endif; ?>

		<div class="mtl-shop-badges"><?php echo mtl_shop_status_badges( $on_loan, $res ); ?></div>

		<p class="mtl-shop-avail-line">
			<?php echo $on_loan ? 'Currently on loan' : 'Available to borrow'; ?>
		</p>
		<p style="margin-top:0; color:#50575e; font-size:0.9em;">
			<?php echo esc_html( $res ); ?> active reservation<?php echo 1 === $res ? '' : 's'; ?> in the queue.
		</p>

		<?php
		// Collapsed by default (native <details>, no JS) so visitors see
				// a small button instead of a raw URL; the field is readonly
				// since click-to-select would require JavaScript.
		?>
		<details class="mtl-shop-share">
			<summary class="mtl-shop-btn mtl-shop-btn-ghost" style="list-style:none;">Link to this tool</summary>
			<?php
			// $share_url is already esc_url()-escaped; wrapping it in
					// esc_attr() too would re-encode the "&" and corrupt the
					// link on any site whose base URL has a query string
					// (e.g. Plain-permalink installs).
			?>
			<input type="text" class="mtl-shop-share-input" readonly value="<?php echo $share_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_url()-escaped by mtl_shop_tool_share_url(); see comment above. ?>" aria-label="Shareable link to this tool">
		</details>

		<?php if ( ! empty( $tool->categories ) ) : ?>
			<h4>Categories</h4>
			<div><?php echo mtl_shop_pills( $tool->categories ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $tool->subcategories ) ) : ?>
			<h4>Sub-categories</h4>
			<div><?php echo mtl_shop_pills( $tool->subcategories ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $tool->tags ) ) : ?>
			<h4>Tags</h4>
			<div><?php echo mtl_shop_pills( $tool->tags ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $tool->required_trainings ) ) : ?>
			<h4>Training needed</h4>
			<div><?php echo mtl_shop_pills( $tool->required_trainings ); ?></div>
			<p class="mtl-shop-note">Ask staff about completing these before you borrow this tool.</p>
		<?php endif; ?>

		<?php if ( ! empty( $tool->description ) ) : ?>
			<h4>Description</h4>
			<p><?php echo nl2br( esc_html( stripslashes( $tool->description ) ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $tool->components ) ) : ?>
			<h4>What's included</h4>
			<p><?php echo nl2br( esc_html( stripslashes( $tool->components ) ) ); ?></p>
		<?php endif; ?>

		<?php
		// The Reserve control adapts to the viewer: a member with this
				// tool already on loan/reserved sees a note instead of a
				// button; a member otherwise gets a POST "Reserve" button; an
				// admin gets a pointer to the admin tools; a logged-out
				// visitor gets a sign-in prompt. Reserving is POST + nonce,
				// never GET, so it can't be triggered by prefetch or CSRF.
		?>
		<?php if ( ! empty( $ctx['is_member'] ) ) : ?>
			<?php if ( isset( $ctx['loaned'][ $tool_id ] ) ) : ?>
				<p class="mtl-shop-reserve-note">You currently have this tool checked out.</p>
			<?php elseif ( isset( $ctx['reserved'][ $tool_id ] ) ) : ?>
				<p class="mtl-shop-reserve-note">You&rsquo;re in the queue for this tool. <a href="<?php echo esc_url( $ctx['reservations_url'] ); ?>">View My Reservations</a>.</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $base ); ?>">
					<?php echo $ctx['reserve_nonce_field']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered wp_nonce_field() output. ?>
					<input type="hidden" name="mtl_action" value="reserve">
					<input type="hidden" name="mtl_tool" value="<?php echo (int) $tool_id; ?>">
					<button type="submit" class="mtl-shop-reserve">Reserve This Tool</button>
				</form>
				<p class="mtl-shop-reserve-note">You&rsquo;ll join the waiting queue and can track your place under My Reservations.</p>
			<?php endif; ?>
		<?php elseif ( ! empty( $ctx['is_admin'] ) ) : ?>
			<p class="mtl-shop-reserve-note">Administrators manage reservations from the Loans &amp; Reservations page.</p>
		<?php else : ?>
			<a class="mtl-shop-reserve" href="<?php echo esc_url( $ctx['login_url'] ); ?>">Sign In to Reserve</a>
			<p class="mtl-shop-reserve-note">New here? <a href="<?php echo esc_url( $ctx['signup_url'] ); ?>">Create a member account</a> to reserve tools.</p>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Renders the public tool catalog.
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

	// Lookup data for the advanced panel's category/tag pickers. Fetched here,
	// before the filters are read, because the selections are validated
	// against it below.
	$categories = $wpdb->get_results( "SELECT category_id, category_name FROM {$tbl_cats} ORDER BY category_name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	$tags_list  = $wpdb->get_results( "SELECT tag_id, tag_name FROM {$tbl_tags} ORDER BY tag_name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	// Qualified for display, since a bare sub-category name is only unique
	// within its category.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, no request-derived data.
	$subcats_list = $wpdb->get_results(
		"SELECT s.subcategory_id, s.subcategory_name, c.category_name
		 FROM {$tbl_subcats} s
		 INNER JOIN {$tbl_cats} c ON c.category_id = s.category_id
		 ORDER BY c.category_name ASC, s.subcategory_name ASC"
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Read + sanitize the request parameters.
	$q        = isset( $_GET['mtl_q'] ) ? sanitize_text_field( wp_unslash( $_GET['mtl_q'] ) ) : '';
	$a_name   = isset( $_GET['mtl_name'] ) ? sanitize_text_field( wp_unslash( $_GET['mtl_name'] ) ) : '';
	$a_brand  = isset( $_GET['mtl_brand'] ) ? sanitize_text_field( wp_unslash( $_GET['mtl_brand'] ) ) : '';
	$a_status = isset( $_GET['mtl_status'] ) ? sanitize_key( wp_unslash( $_GET['mtl_status'] ) ) : '';
	$sort     = isset( $_GET['mtl_sort'] ) ? sanitize_key( wp_unslash( $_GET['mtl_sort'] ) ) : '';
	$view     = ( isset( $_GET['mtl_view'] ) && 'rows' === $_GET['mtl_view'] ) ? 'rows' : 'tiles';
	$page_no  = isset( $_GET['mtl_pg'] ) ? max( 1, (int) $_GET['mtl_pg'] ) : 1;
	$sel_id   = isset( $_GET['mtl_tool'] ) ? (int) $_GET['mtl_tool'] : 0;

	// Category and tag are multi-select, so both arrive as id lists. The
	// (array) cast also accepts the single scalar the filters used to send,
	// which keeps old bookmarks and shared links working.
	//
	// Selections are intersected with the ids that actually exist: a stale or
	// hand-edited value is dropped here rather than being carried into every
	// link on the page, and the IN () list below can only ever hold real ids.
	$id_list_param = function ( $key, array $valid_ids ) {
		if ( ! isset( $_GET[ $key ] ) ) {
			return array();
		}
		$requested = array_map( 'intval', (array) wp_unslash( $_GET[ $key ] ) );
		return array_values( array_unique( array_intersect( $requested, $valid_ids ) ) );
	};
	$a_cats        = $id_list_param( 'mtl_cat', array_map( 'intval', wp_list_pluck( $categories, 'category_id' ) ) );
	$a_tags        = $id_list_param( 'mtl_tag', array_map( 'intval', wp_list_pluck( $tags_list, 'tag_id' ) ) );
	$a_subcats     = $id_list_param( 'mtl_subcat', array_map( 'intval', wp_list_pluck( $subcats_list, 'subcategory_id' ) ) );

	$advanced_active = ( '' !== $a_name || '' !== $a_brand || $a_cats || $a_subcats || $a_tags || '' !== $a_status );

	// The catalog's sort modes, whitelisted: each accepted mtl_sort value
	// mapped to its safe ORDER BY fragment (never user SQL) and to the label
	// the sort menu shows for it. The query, the menu and the menu's
	// active-item highlight all read this one list, so adding a sort option
	// means editing here and nowhere else.
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
			'order' => 't.tool_name ASC',
			'label' => 'Name A&ndash;Z',
		),
		'name_desc' => array(
			'order' => 't.tool_name DESC',
			'label' => 'Name Z&ndash;A',
		),
		'brand'     => array(
			'order' => 't.brand ASC',
			'label' => 'Brand',
		),
	);
	// "newest" is an accepted alias for the default; anything else is a stale
	// or tampered URL. Both fold to '' here so the rest of this function has
	// exactly one spelling of "default sort" to handle. Normalizing up front
	// also keeps an unrecognized value from being carried forward: the value
	// is only whitelisted where ORDER BY is chosen, so a stale one used to
	// survive into every link and the search form's hidden field, and left the
	// sort menu with no entry marked active.
	if ( ! isset( $sort_modes[ $sort ] ) ) {
		$sort = '';
	}
	$order_by = $sort_modes[ $sort ]['order'];

	$per_page = ( 'rows' === $view ) ? 20 : 12;

	// Build the dynamic WHERE from the active filters. Conditions carry
	// %s / %d placeholders; $args holds the matching values, run through
	// $wpdb->prepare() below. Every {$tbl_*} / {$sub_*} / {$from} fragment
	// interpolated below is a table name or safe SQL fragment built only
	// from $wpdb->prefix and this whitelist array, never request data;
	// phpcs can't verify that across this many lines, hence the disable
	// block through the end of the query-building section.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	// Retired tools are never shown publicly; see admin/schema.sql's note
	// on tool_inventory.retired_at.
	$where = array( 't.retired_at IS NULL' );
	$args  = array();

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
	// Several categories (or tags) match ANY of them, so picking Woodworking and
	// Plumbing widens the results rather than narrowing them to tools filed
	// under both. The two filters still combine with each other, and with
	// everything else here, as AND.
	if ( $a_cats ) {
		$cat_ph  = implode( ',', array_fill( 0, count( $a_cats ), '%d' ) );
		$where[] = "EXISTS (SELECT 1 FROM {$tbl_cat_map} fcm WHERE fcm.tool_id = t.tool_id AND fcm.category_id IN ({$cat_ph}))";
		$args    = array_merge( $args, $a_cats );
	}
	if ( $a_subcats ) {
		$sub_ph  = implode( ',', array_fill( 0, count( $a_subcats ), '%d' ) );
		$where[] = "EXISTS (SELECT 1 FROM {$tbl_subcat_map} fsm WHERE fsm.tool_id = t.tool_id AND fsm.subcategory_id IN ({$sub_ph}))";
		$args    = array_merge( $args, $a_subcats );
	}
	if ( $a_tags ) {
		$tag_ph  = implode( ',', array_fill( 0, count( $a_tags ), '%d' ) );
		$where[] = "EXISTS (SELECT 1 FROM {$tbl_tag_map} ftm WHERE ftm.tool_id = t.tool_id AND ftm.tag_id IN ({$tag_ph}))";
		$args    = array_merge( $args, $a_tags );
	}
	$where_sql = 'WHERE ' . implode( ' AND ', $where );

	// The availability filter compares computed loan/reservation counts, so
	// it belongs in HAVING (after GROUP BY) rather than WHERE.
	$having = '';
	if ( 'available' === $a_status ) {
		$having = 'HAVING active_loans = 0';
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
		. " LEFT JOIN {$tbl_subcat_map} tsm ON t.tool_id = tsm.tool_id"
		. " LEFT JOIN {$tbl_subcats} sc ON tsm.subcategory_id = sc.subcategory_id"
		. " LEFT JOIN {$tbl_tool_train} ttr ON t.tool_id = ttr.tool_id"
		. " LEFT JOIN {$tbl_trainings} tr ON ttr.training_id = tr.training_id";

	// Total matching count (for pagination).
	$count_sql = "SELECT COUNT(*) FROM (SELECT t.tool_id, {$sub_loans} AS active_loans, {$sub_res} AS active_res {$from} {$where_sql} GROUP BY t.tool_id {$having}) sub";
	$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no-args branch: $count_sql carries no request-derived data when $args is empty.

	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	if ( $page_no > $total_pages ) {
		$page_no = $total_pages;
	}
	$offset = ( $page_no - 1 ) * $per_page;

	// The page of results.
	$page_sql  = 'SELECT t.tool_id, t.tool_name, t.brand, t.description, t.components, t.photo_url,'
		. " GROUP_CONCAT(DISTINCT c.category_name ORDER BY c.category_name SEPARATOR ', ') AS categories,"
		. " GROUP_CONCAT(DISTINCT tg.tag_name ORDER BY tg.tag_name SEPARATOR ', ') AS tags,"
		. " GROUP_CONCAT(DISTINCT sc.subcategory_name ORDER BY sc.subcategory_name SEPARATOR ', ') AS subcategories,"
		. " GROUP_CONCAT(DISTINCT tr.training_name ORDER BY tr.training_name SEPARATOR ', ') AS required_trainings,"
		. " {$sub_loans} AS active_loans, {$sub_res} AS active_res"
		. " {$from} {$where_sql} GROUP BY t.tool_id {$having} ORDER BY {$order_by} LIMIT %d OFFSET %d";
	$page_args = array_merge( $args, array( $per_page, $offset ) );
	$tools     = $wpdb->get_results( $wpdb->prepare( $page_sql, $page_args ) );

	// The selected tool for the detail box, fetched independently so it
	// shows even if it isn't on the current page of results.
	$selected = null;
	if ( $sel_id > 0 ) {
		$selected = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT t.tool_id, t.tool_name, t.brand, t.description, t.components, t.photo_url, t.date_acquired,'
				. " GROUP_CONCAT(DISTINCT c.category_name ORDER BY c.category_name SEPARATOR ', ') AS categories,"
				. " GROUP_CONCAT(DISTINCT tg.tag_name ORDER BY tg.tag_name SEPARATOR ', ') AS tags,"
				. " {$sub_loans} AS active_loans, {$sub_res} AS active_res"
				. " {$from} WHERE t.tool_id = %d AND t.retired_at IS NULL GROUP BY t.tool_id",
				$sel_id
			)
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

	// Every tool needing a pre-rendered detail panel: the current results
	// page plus the deep-linked tool if not already among them. $tools rows
	// already carry every column the detail view needs, so this adds no
	// extra queries.
	$panel_tools = $tools;
	if ( $selected ) {
		$page_tool_ids = array_map( 'intval', array_column( $tools, 'tool_id' ) );
		if ( ! in_array( (int) $selected->tool_id, $page_tool_ids, true ) ) {
			$panel_tools[] = $selected;
		}
	}

	// URL helpers. All links preserve the current filter/sort/view state and
	// only override what changes; the search form carries the same state
	// through hidden inputs.
	$base  = mtl_front_page_url( 'main' );
	$state = array(
		'mtl_q'      => $q,
		'mtl_name'   => $a_name,
		'mtl_brand'  => $a_brand,
		// Id lists: add_query_arg() expands these to mtl_cat[0]=..&mtl_cat[1]=..
		// and PHP reads them straight back as an array. Empty stays '' so the
		// $not_empty filter below drops the key entirely.
		'mtl_cat'    => $a_cats ? $a_cats : '',
		'mtl_tag'    => $a_tags ? $a_tags : '',
		'mtl_status' => $a_status,
		'mtl_sort'   => $sort,
		'mtl_view'   => 'rows' === $view ? 'rows' : '',
		'mtl_tool'   => $sel_id > 0 ? $sel_id : '',
	);
	// Drop empty values so URLs stay tidy.
	$not_empty   = function ( $v ) {
		return '' !== $v && null !== $v;
	};
	$clean_state = array_filter( $state, $not_empty );

	// Pagination, sort and view-toggle links change the query string, so they
	// always trigger a full reload. Whenever the resulting URL carries
	// mtl_tool, the matching #tool-<id> fragment is appended automatically so
	// visibility (100% fragment/:target-driven) never gets left out of sync.
	$make_url = function ( array $overrides ) use ( $base, $clean_state, $not_empty ) {
		$args = array_filter( array_merge( $clean_state, $overrides ), $not_empty );
		$url  = add_query_arg( $args, $base );
		if ( ! empty( $args['mtl_tool'] ) ) {
			$url .= '#' . mtl_shop_panel_id( $args['mtl_tool'] );
		}
		return esc_url( $url );
	};

	$result_word = ( 1 === $total ) ? 'tool' : 'tools';

	// Viewer's member context, fetched once and handed to every detail panel
	// so the Reserve control can adapt to who's looking (see
	// mtl_shop_render_detail_panel). The lookups only run when signed in.
	$member_ctx = array(
		'is_member'           => false,
		'is_admin'            => ( is_user_logged_in() && mtl_can_manage_library() ),
		'reserved'            => array(), // Tool_id => true (active reservations).
		'loaned'              => array(), // Tool_id => true (currently on loan).
		'reserve_nonce_field' => '',
		'login_url'           => mtl_front_page_url( 'login' ),
		'signup_url'          => mtl_front_page_url( 'signup' ),
		'reservations_url'    => mtl_front_page_url( 'reservations' ),
	);
	$viewer     = mtl_current_member();
	if ( $viewer ) {
		$mid                               = (int) $viewer->member_id;
		$member_ctx['is_member']           = true;
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

	// ======================================================================
	// RENDER
	// ======================================================================
	ob_start();
	?>
	<style>
		/* Full-width override for the shared shell's centered content area. */
		.mtl-front-content {
			display: block;
			padding: 16px 20px 40px 20px;
		}

		.mtl-shop {
			max-width: 1400px;
			margin: 0 auto;
		}

		/* Button-styled <details>; hide the browser's default disclosure
			triangle so only our own caret shows. */
		.mtl-shop summary.mtl-shop-btn {
			list-style: none;
		}

		.mtl-shop summary.mtl-shop-btn::-webkit-details-marker {
			display: none;
		}

		.mtl-shop-toolbar {
			display: flex;
			flex-wrap: wrap;
			align-items: flex-end;
			justify-content: space-between;
			gap: 12px;
			margin-bottom: 16px;
		}

		.mtl-shop-search {
			flex: 1 1 340px;
		}

		.mtl-shop-search-row {
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
		}

		.mtl-shop-search input[type="text"] {
			flex: 1 1 220px;
			padding: 9px 12px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
			font-size: 1em;
		}

		.mtl-shop-btn {
			display: inline-block;
			padding: 9px 16px;
			border: 1px solid var(--mtl-shop-accent);
			border-radius: 4px;
			background: var(--mtl-shop-accent);
			color: #fff;
			font-size: 0.95em;
			cursor: pointer;
			text-decoration: none;
			white-space: nowrap;
		}

		.mtl-shop-btn-ghost {
			background: #fff;
			color: #3c434a;
			border-color: #ccd0d4;
		}

		/* Pinned to the screen corner (not the page top) so it stays reachable
			while scrolling. z-index is below the mobile detail overlay's
			(100/101, see max-width:900px block) so an open overlay covers it. */
		.mtl-shop-home-btn {
			position: fixed;
			top: 16px;
			left: 16px;
			z-index: 10;
			box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
		}

		/* Member nav, pinned top-right to mirror the Home button (same
			z-index so an open detail overlay covers both). */
		.mtl-shop-account-nav {
			position: fixed;
			top: 16px;
			right: 16px;
			z-index: 10;
			display: flex;
			gap: 8px;
			align-items: flex-start;
		}

		.mtl-shop-account-nav .mtl-shop-btn {
			box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
		}

		/* Native <details> account menu, a zero-JS disclosure. */
		.mtl-shop-account-menu {
			position: relative;
		}

		.mtl-shop-account-menu > summary {
			list-style: none;
		}

		.mtl-shop-account-menu > summary::-webkit-details-marker {
			display: none;
		}

		.mtl-shop-account-menu-panel {
			position: absolute;
			right: 0;
			margin-top: 6px;
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			box-shadow: 0 3px 10px rgba(0, 0, 0, .12);
			min-width: 180px;
			padding: 4px 0;
		}

		.mtl-shop-account-menu-panel a {
			display: block;
			padding: 8px 14px;
			text-decoration: none;
			color: #3c434a;
		}

		.mtl-shop-account-menu-panel a:hover {
			background: #f6f7f7;
		}

		/* Status banner shown after a reserve / cancel action (via mtl_msg). */
		.mtl-front-notice {
			margin: 0 0 16px 0;
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

		.mtl-shop-advanced {
			margin-top: 10px;
			border: 1px solid #e2e5e8;
			border-radius: 4px;
			padding: 0 14px;
		}

		.mtl-shop-advanced > summary {
			cursor: pointer;
			padding: 10px 0;
			font-weight: 600;
			font-size: 0.9em;
		}

		.mtl-shop-adv-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
			gap: 10px 12px;
			padding: 0 0 14px 0;
		}

		.mtl-shop-adv-grid label {
			display: block;
			font-size: 0.78em;
			font-weight: 600;
			margin-bottom: 2px;
		}

		.mtl-shop-adv-grid input,
		.mtl-shop-adv-grid select {
			width: 100%;
			box-sizing: border-box;
			min-height: 30px;
			padding: 3px 6px;
		}

		/* The category/tag multi-selects. Given two grid columns so a list of
			names is readable, and resizable for libraries with a long list. */
		.mtl-shop-adv-multi {
			grid-column: span 2;
		}

		.mtl-shop-adv-multi select[multiple] {
			min-height: 84px;
			padding: 2px;
			resize: vertical;
			overflow: auto;
		}

		.mtl-shop-adv-multi small {
			display: block;
			font-size: 0.72em;
			color: #6b7280;
			margin-top: 3px;
		}

		/* One column wide, so span 2 would overflow the grid. */
		@media (max-width: 480px) {
			.mtl-shop-adv-multi {
				grid-column: span 1;
			}
		}

		.mtl-shop-adv-actions {
			display: flex;
			gap: 8px;
			align-items: center;
			flex-wrap: wrap;
			margin: 0 0 12px 0;
		}

		.mtl-shop-controls {
			display: flex;
			gap: 16px;
			align-items: flex-end;
			flex-wrap: wrap;
		}

		.mtl-shop-control-group {
			font-size: 0.82em;
		}

		.mtl-shop-control-group .mtl-shop-control-label {
			display: block;
			font-weight: 600;
			margin-bottom: 3px;
			color: #50575e;
		}

		.mtl-shop-toggle a {
			display: inline-block;
			padding: 6px 12px;
			border: 1px solid #ccd0d4;
			text-decoration: none;
			color: #3c434a;
			font-size: 0.9em;
		}

		.mtl-shop-toggle a:first-child {
			border-radius: 4px 0 0 4px;
		}

		.mtl-shop-toggle a:last-child {
			border-radius: 0 4px 4px 0;
			border-left: none;
		}

		.mtl-shop-toggle a.mtl-shop-active {
			background: var(--mtl-shop-accent);
			border-color: var(--mtl-shop-accent);
			color: #fff;
			font-weight: 600;
		}

		.mtl-shop-count {
			font-size: 0.9em;
			color: #50575e;
			margin: 6px 0 14px 0;
		}

		/* Two-column layout: catalog left, detail box right. */
		.mtl-shop-layout {
			display: flex;
			gap: 24px;
			align-items: flex-start;
		}

		.mtl-shop-main {
			flex: 1 1 auto;
			min-width: 0;
		}

		.mtl-shop-detail-col {
			flex: 0 0 340px;
			position: sticky;
			top: 16px;
		}

		@media (max-width: 900px) {
			.mtl-shop-layout {
				flex-direction: column;
			}

			.mtl-shop-detail-col {
				position: static;
				width: 100%;
			}

			/* On narrow screens, a selected tool's detail box is lifted out of
				normal flow and pinned over the viewport instead of sitting
				below a possibly long list of tiles/rows. position:fixed is
				anchored to the viewport, not the page, so there's nothing in
				page flow for the browser's native fragment-scroll to jump to.
				Relies on :has(); browsers without it simply keep the detail
				box in normal flow below the grid (a functional, if less
				convenient, fallback). */
			.mtl-shop-detail-col:has(.mtl-shop-detail-panel:target) {
				position: fixed;
				inset: 0;
				width: auto;
				z-index: 100;
				display: flex;
				align-items: flex-start;
				justify-content: center;
				overflow-y: auto;
				padding: 40px 14px;
				background: rgba(30, 34, 38, .6);
			}

			.mtl-shop-detail-col:has(.mtl-shop-detail-panel:target) .mtl-shop-detail {
				width: 100%;
				max-width: 480px;
				margin: auto 0;
			}

			.mtl-shop-detail-col:has(.mtl-shop-detail-panel:target) .mtl-shop-detail-close {
				display: flex;
			}
		}

		/* Tile grid */
		.mtl-shop-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
			gap: 16px;
		}

		.mtl-shop-tile {
			display: flex;
			flex-direction: column;
			border: 1px solid #d5d8dc;
			border-radius: 8px;
			overflow: hidden;
			background: #fff;
			text-decoration: none;
			color: inherit;
			transition: box-shadow 0.15s ease, border-color 0.15s ease;
		}

		.mtl-shop-tile:hover {
			box-shadow: 0 4px 14px rgba(0, 0, 0, .12);
		}

		.mtl-shop-thumb {
			aspect-ratio: 4 / 3;
			width: 100%;
			object-fit: contain;
			background: #f6f7f7;
		}

		.mtl-shop-noimg {
			aspect-ratio: 4 / 3;
			width: 100%;
			display: flex;
			align-items: center;
			justify-content: center;
			background: #f0f1f2;
			color: #a7abb0;
			font-size: 0.85em;
			letter-spacing: 0.03em;
		}

		.mtl-shop-tile-body {
			padding: 10px 12px 12px 12px;
			display: flex;
			flex-direction: column;
			gap: 4px;
		}

		.mtl-shop-tile-name {
			font-weight: 700;
			line-height: 1.25;
		}

		.mtl-shop-tile-brand {
			font-size: 0.85em;
			color: #787c82;
		}

		/* Row (compact) view */
		.mtl-shop-rows {
			display: flex;
			flex-direction: column;
			border: 1px solid #d5d8dc;
			border-radius: 6px;
			overflow: hidden;
			background: #fff;
		}

		.mtl-shop-row {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 10px 14px;
			border-top: 1px solid #eef0f2;
			text-decoration: none;
			color: inherit;
		}

		.mtl-shop-row:first-child {
			border-top: none;
		}

		.mtl-shop-row:hover {
			background: #f6fafd;
		}

		.mtl-shop-row-main {
			flex: 1 1 auto;
			min-width: 0;
		}

		.mtl-shop-row-name {
			font-weight: 600;
		}

		.mtl-shop-row-sub {
			font-size: 0.85em;
			color: #787c82;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		/* Badges + pills */
		<?php echo mtl_shop_badge_pill_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS from a developer-defined string, never user input. ?>

		/* Detail box */
		.mtl-shop-detail {
			border: 1px solid #d5d8dc;
			border-radius: 8px;
			background: #fff;
			overflow: hidden;
		}

		/* Every tool's panel is pre-rendered (hidden); CSS :target reveals
			whichever one matches the URL fragment. */
		.mtl-shop-detail-panel {
			display: none;
		}

		.mtl-shop-detail-panel:target {
			display: block;
		}

		.mtl-shop-detail:has(.mtl-shop-detail-panel:target) .mtl-shop-detail-empty {
			display: none;
		}

		/* Zero-size and fixed, so navigating here to close the overlay (see
			max-width:900px block) can't cause a scroll jump either. */
		.mtl-shop-close-anchor {
			position: fixed;
			top: 0;
			left: 0;
			width: 0;
			height: 0;
		}

		/* Hidden by default; shown only while the narrow-screen overlay is
			active. position:fixed so it stays pinned to the corner. */
		.mtl-shop-detail-close {
			display: none;
			position: fixed;
			top: 16px;
			right: 16px;
			z-index: 101;
			width: 32px;
			height: 32px;
			align-items: center;
			justify-content: center;
			border-radius: 50%;
			background: #fff;
			color: #3c434a;
			text-decoration: none;
			font-size: 1.4em;
			line-height: 1;
			box-shadow: 0 2px 8px rgba(0, 0, 0, .25);
		}

		.mtl-shop-detail-empty {
			padding: 30px 20px;
			text-align: center;
			color: #8c8f94;
		}

		.mtl-shop-detail-photo {
			width: 100%;
			max-height: 240px;
			object-fit: contain;
			background: #f6f7f7;
		}

		.mtl-shop-detail-body {
			padding: 16px 18px 20px 18px;
		}

		.mtl-shop-share {
			margin: 14px 0;
		}

		/* Smaller variant of .mtl-shop-btn-ghost for this low-emphasis action. */
		.mtl-shop-share summary.mtl-shop-btn {
			padding: 4px 10px;
			font-size: 0.78em;
		}

		.mtl-shop-share[open] summary.mtl-shop-btn {
			margin-bottom: 6px;
		}

		.mtl-shop-share-input {
			display: block;
			width: 100%;
			box-sizing: border-box;
			padding: 7px 9px;
			border: 1px solid #d5d8dc;
			border-radius: 4px;
			background: #f6f7f7;
			color: #50575e;
			font-family: Consolas, Menlo, monospace;
			font-size: 0.82em;
		}

		.mtl-shop-detail-name {
			font-size: 1.25em;
			font-weight: 700;
			margin: 0;
		}

		.mtl-shop-detail-brand {
			color: #787c82;
			margin: 2px 0 12px 0;
		}

		.mtl-shop-detail h4 {
			font-size: 0.78em;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			color: #8c8f94;
			margin: 16px 0 4px 0;
		}

		.mtl-shop-detail p {
			margin: 4px 0;
			line-height: 1.5;
		}

		.mtl-shop-avail-line {
			font-weight: 600;
			margin: 12px 0 4px 0;
		}

		.mtl-shop-reserve {
			display: block;
			width: 100%;
			box-sizing: border-box;
			margin-top: 16px;
			padding: 11px 0;
			border: none;
			border-radius: 4px;
			background: var(--mtl-shop-accent);
			color: #fff;
			font-size: 1.02em;
			font-weight: 600;
			text-align: center;
			text-decoration: none;
			cursor: pointer;
		}

		/* Keep the Reserve button flush with the panel; the form itself
			adds no margin. */
		.mtl-shop-detail-body form {
			margin: 0;
		}

		.mtl-shop-reserve-note {
			font-size: 0.8em;
			color: #8c8f94;
			text-align: center;
			margin-top: 6px;
		}

		/* Pagination */
		.mtl-shop-pagination {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 14px;
			margin-top: 22px;
			font-size: 0.9em;
		}

		.mtl-shop-pagination a {
			display: inline-block;
			padding: 7px 14px;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			text-decoration: none;
			color: #3c434a;
		}

		.mtl-shop-pagination span.mtl-shop-page-disabled {
			display: inline-block;
			padding: 7px 14px;
			border: 1px solid #eef0f2;
			border-radius: 4px;
			color: #c3c7cb;
		}

		.mtl-shop-empty {
			border: 1px dashed #ccd0d4;
			border-radius: 8px;
			padding: 40px 20px;
			text-align: center;
			color: #787c82;
		}
	</style>

	<?php
	// Accent color mirrors the admin theme's header color (set on the Setup
	// page) so the shop matches it.
	$accent = get_option( 'mtl_header_color', '#ff6600' );
	// Defaults to the real WordPress home page so this works with zero
	// configuration; overridable on the Setup page.
	$home_url = get_option( 'mtl_home_url', home_url( '/' ) );
	?>
	<div class="mtl-shop" style="--mtl-shop-accent: <?php echo esc_attr( $accent ); ?>;">

		<a href="<?php echo esc_url( $home_url ); ?>" class="mtl-shop-btn mtl-shop-btn-ghost mtl-shop-home-btn">&larr; Home</a>

		<?php
		// Member sign-in / sign-up (logged out) or account menu (signed
				// in), pinned top-right to mirror the Home button.
		?>
		<?php echo mtl_member_nav_html(); ?>

		<?php
		// Close target for the mobile detail overlay: its id matches no
				// panel, so linking here clears :target and closes it.
		?>
		<div id="mtl-shop-closed" class="mtl-shop-close-anchor" aria-hidden="true"></div>

		<?php // One-off status banner after a reserve/cancel action. ?>
		<?php echo mtl_front_notice_html(); ?>
		<?php echo mtl_agreements_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>

		<!-- Search + advanced filter (one GET form; native <details> for advanced) -->
		<div class="mtl-shop-toolbar">
			<form class="mtl-shop-search" method="get" action="<?php echo esc_url( $base ); ?>">
				<?php // Needed on Plain-permalink sites where the pretty route is unavailable. ?>
				<input type="hidden" name="mtl_page" value="main">
				<?php if ( 'rows' === $view ) : ?>
					<input type="hidden" name="mtl_view" value="rows">
				<?php endif; ?>
				<?php if ( '' !== $sort ) : ?>
					<input type="hidden" name="mtl_sort" value="<?php echo esc_attr( $sort ); ?>">
				<?php endif; ?>

				<div class="mtl-shop-search-row">
					<input type="text" name="mtl_q" value="<?php echo esc_attr( $q ); ?>" placeholder="Search tools by name, brand, category or tag...">
					<button type="submit" class="mtl-shop-btn">Search</button>
					<?php if ( '' !== $q || $advanced_active || $sel_id > 0 ) : ?>
						<a href="<?php echo esc_url( $base ); ?>" class="mtl-shop-btn mtl-shop-btn-ghost">Clear</a>
					<?php endif; ?>
				</div>

				<details class="mtl-shop-advanced" <?php echo $advanced_active ? 'open' : ''; ?>>
					<summary>Advanced Search</summary>
					<div class="mtl-shop-adv-grid">
						<div>
							<label for="mtl-a-name">Tool Name</label>
							<input type="text" id="mtl-a-name" name="mtl_name" value="<?php echo esc_attr( $a_name ); ?>">
						</div>
						<div>
							<label for="mtl-a-brand">Brand</label>
							<input type="text" id="mtl-a-brand" name="mtl_brand" value="<?php echo esc_attr( $a_brand ); ?>">
						</div>
						<?php
						// Categories and tags are multi-select: nothing selected
						// means "any", and picking several widens the results to
						// tools matching any one of them.
						?>
						<div class="mtl-shop-adv-multi">
							<label for="mtl-a-cat">Categories</label>
							<select id="mtl-a-cat" name="mtl_cat[]" multiple size="4">
								<?php foreach ( $categories as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->category_id ); ?>" <?php echo in_array( (int) $cat->category_id, $a_cats, true ) ? 'selected' : ''; ?>><?php echo esc_html( $cat->category_name ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>Leave empty for any. Ctrl-click (&#8984;-click on Mac) to pick or unpick several.</small>
						</div>
						<div class="mtl-shop-adv-multi">
							<label for="mtl-a-subcat">Sub-categories</label>
							<select id="mtl-a-subcat" name="mtl_subcat[]" multiple size="4">
								<?php foreach ( $subcats_list as $sc ) : ?>
									<option value="<?php echo esc_attr( $sc->subcategory_id ); ?>" <?php echo in_array( (int) $sc->subcategory_id, $a_subcats, true ) ? 'selected' : ''; ?>><?php echo esc_html( $sc->category_name . ' > ' . $sc->subcategory_name ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>Leave empty for any. Named by category, since two categories can share a sub-category name.</small>
						</div>
						<div class="mtl-shop-adv-multi">
							<label for="mtl-a-tag">Tags</label>
							<select id="mtl-a-tag" name="mtl_tag[]" multiple size="4">
								<?php foreach ( $tags_list as $tg ) : ?>
									<option value="<?php echo esc_attr( $tg->tag_id ); ?>" <?php echo in_array( (int) $tg->tag_id, $a_tags, true ) ? 'selected' : ''; ?>><?php echo esc_html( $tg->tag_name ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>Leave empty for any. Ctrl-click (&#8984;-click on Mac) to pick or unpick several.</small>
						</div>
						<div>
							<label for="mtl-a-status">Availability</label>
							<select id="mtl-a-status" name="mtl_status">
								<option value="">Any</option>
								<option value="available" <?php selected( $a_status, 'available' ); ?>>Available</option>
								<option value="onloan" <?php selected( $a_status, 'onloan' ); ?>>On Loan</option>
								<option value="noreserved" <?php selected( $a_status, 'noreserved' ); ?>>No Reservations</option>
							</select>
						</div>
					</div>
					<p class="mtl-shop-adv-actions">
						<button type="submit" class="mtl-shop-btn">Apply Filters</button>
						<a href="<?php echo esc_url( $base ); ?>" class="mtl-shop-btn mtl-shop-btn-ghost">Clear Filters</a>
					</p>
				</details>
			</form>

			<!-- View toggle + sort (instant links, no JS) -->
			<div class="mtl-shop-controls">
				<div class="mtl-shop-control-group">
					<span class="mtl-shop-control-label">View</span>
					<span class="mtl-shop-toggle">
						<?php
						// Both toggles keep every other filter and land on page 1.
						foreach ( array(
							'tiles' => 'Tiles',
							'rows'  => 'Rows',
						) as $view_val => $view_label ) :
							$view_url = $make_url(
								array(
									// Tiles is the default, so it stays out of the URL.
									'mtl_view' => 'tiles' === $view_val ? '' : $view_val,
									'mtl_pg'   => '',
								)
							);
							?>
							<a href="<?php echo $view_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $make_url() always returns esc_url()-escaped output. ?>" class="<?php echo $view === $view_val ? 'mtl-shop-active' : ''; ?>"><?php echo esc_html( $view_label ); ?></a>
						<?php endforeach; ?>
					</span>
				</div>
				<div class="mtl-shop-control-group">
					<span class="mtl-shop-control-label">Sort</span>
					<details style="display:inline-block; position:relative;">
						<summary class="mtl-shop-btn mtl-shop-btn-ghost" style="list-style:none;">
							<?php echo $sort_modes[ $sort ]['label']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static developer-defined label from $sort_modes (incl. HTML entities like &ndash;), never user input. ?>
							&#9662;
						</summary>
						<div style="position:absolute; right:0; z-index:20; background:#fff; border:1px solid #ccd0d4; border-radius:4px; box-shadow:0 3px 10px rgba(0,0,0,.12); min-width:150px; padding:4px 0;">
							<?php
							foreach ( $sort_modes as $val => $mode ) :
								$is_active = ( $sort === $val );
								$sort_url  = $make_url(
									array(
										'mtl_sort' => $val,
										'mtl_pg'   => '',
									)
								);
								?>
								<a href="<?php echo $sort_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $make_url() always returns esc_url()-escaped output; $val is a fixed key of $sort_modes, never user input. ?>" style="display:block; padding:6px 14px; text-decoration:none; color:<?php echo $is_active ? esc_attr( $accent ) : '#3c434a'; ?>; font-weight:<?php echo $is_active ? '600' : '400'; ?>;"><?php echo $mode['label']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static developer-defined label from $sort_modes (incl. HTML entities like &ndash;), never user input. ?></a>
							<?php endforeach; ?>
						</div>
					</details>
				</div>
			</div>
		</div>

		<p class="mtl-shop-count"><strong><?php echo esc_html( number_format( $total ) ); ?></strong> <?php echo esc_html( $result_word ); ?> found<?php echo ( $total > 0 ) ? ' (page ' . esc_html( $page_no ) . ' of ' . esc_html( $total_pages ) : ''; ?></p>

		<div class="mtl-shop-layout">
			<div class="mtl-shop-main">
				<?php if ( empty( $tools ) ) : ?>
					<div class="mtl-shop-empty">
						<p style="margin:0;">No tools match your search. Try removing a filter or searching for something else.</p>
					</div>
				<?php elseif ( 'rows' === $view ) : ?>
					<div class="mtl-shop-rows">
						<?php
						foreach ( $tools as $tool ) :
							$on_loan = ( (int) $tool->active_loans > 0 );
							// Bare fragment (no query string) so the browser
							// treats this as same-document navigation and
							// never reloads.
							$row_url = '#' . mtl_shop_panel_id( $tool->tool_id );
							?>
							<a class="mtl-shop-row" href="<?php echo esc_url( $row_url ); ?>">
								<div class="mtl-shop-row-main">
									<div class="mtl-shop-row-name"><?php echo esc_html( stripslashes( $tool->tool_name ) ); ?></div>
									<div class="mtl-shop-row-sub">
										<?php echo esc_html( stripslashes( (string) $tool->brand ) ); ?>
										<?php
										if ( ! empty( $tool->categories ) ) {
											echo ' &middot; ' . esc_html( $tool->categories );
										}
										?>
									</div>
								</div>
								<div class="mtl-shop-badges"><?php echo mtl_shop_status_badges( $on_loan, $tool->active_res ); ?></div>
							</a>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div class="mtl-shop-grid">
						<?php
						foreach ( $tools as $tool ) :
							$on_loan  = ( (int) $tool->active_loans > 0 );
							$tile_url = '#' . mtl_shop_panel_id( $tool->tool_id );
							?>
							<a class="mtl-shop-tile" href="<?php echo esc_url( $tile_url ); ?>">
								<?php if ( ! empty( $tool->photo_url ) ) : ?>
									<img class="mtl-shop-thumb" src="<?php echo esc_url( $tool->photo_url ); ?>" alt="<?php echo esc_attr( stripslashes( $tool->tool_name ) ); ?>" loading="lazy">
								<?php else : ?>
									<div class="mtl-shop-noimg">No photo</div>
								<?php endif; ?>
								<div class="mtl-shop-tile-body">
									<span class="mtl-shop-tile-name"><?php echo esc_html( stripslashes( $tool->tool_name ) ); ?></span>
									<?php if ( ! empty( $tool->brand ) ) : ?>
										<span class="mtl-shop-tile-brand"><?php echo esc_html( stripslashes( $tool->brand ) ); ?></span>
									<?php endif; ?>
									<div class="mtl-shop-badges"><?php echo mtl_shop_status_badges( $on_loan, $tool->active_res ); ?></div>
									<?php if ( ! empty( $tool->categories ) ) : ?>
										<div><?php echo mtl_shop_pills( $tool->categories ); ?></div>
									<?php endif; ?>
								</div>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="mtl-shop-pagination">
						<?php if ( $page_no > 1 ) : ?>
							<a href="<?php echo $make_url( array( 'mtl_pg' => ( $page_no - 1 <= 1 ) ? '' : $page_no - 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $make_url() always returns esc_url()-escaped output; $page_no is cast (int) at assignment. ?>">&larr; Previous</a>
						<?php else : ?>
							<span class="mtl-shop-page-disabled">&larr; Previous</span>
						<?php endif; ?>

						<span>Page <?php echo esc_html( $page_no ); ?> of <?php echo esc_html( $total_pages ); ?></span>

						<?php if ( $page_no < $total_pages ) : ?>
							<a href="<?php echo $make_url( array( 'mtl_pg' => $page_no + 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $make_url() always returns esc_url()-escaped output; $page_no is cast (int) at assignment. ?>">Next &rarr;</a>
						<?php else : ?>
							<span class="mtl-shop-page-disabled">Next &rarr;</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Detail box (right) -->
			<div class="mtl-shop-detail-col">
				<div class="mtl-shop-detail" id="mtl-shop-detail">
					<?php // Only visible on narrow screens while the overlay is open. ?>
					<a href="#mtl-shop-closed" class="mtl-shop-detail-close" aria-label="Close">&times;</a>
					<div class="mtl-shop-detail-empty">
						<p style="margin:0;">Select a tool to see its full details, availability and reservation options.</p>
					</div>
					<?php foreach ( $panel_tools as $panel_tool ) : ?>
						<div class="mtl-shop-detail-panel" id="<?php echo esc_attr( mtl_shop_panel_id( $panel_tool->tool_id ) ); ?>" tabindex="-1">
							<?php echo mtl_shop_render_detail_panel( $panel_tool, $base, $member_ctx ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
