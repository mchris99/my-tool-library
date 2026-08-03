<?php
/**
 * Inventory admin page.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a comma-separated lookup value (categories/tags) as a row of
 * pill-style badges instead of a raw text blob.
 *
 * @param string $csv Comma-separated list of labels.
 * @return string HTML markup.
 */
function mtl_render_pill_list( $csv ) {
	$csv = trim( (string) $csv );
	if ( '' === $csv ) {
		return '<span style="color: #999;">&mdash;</span>';
	}

	$out = '';
	foreach ( array_filter( array_map( 'trim', explode( ',', $csv ) ) ) as $label ) {
		$out .= '<span class="mtl-badge">' . esc_html( $label ) . '</span>';
	}
	return $out;
}

/**
 * Resolves the Add/Edit forms' two separate annual-depreciation inputs (a
 * dollar amount OR a percentage of the tool's initial value) into a single
 * dollar figure for storage. Exactly one of the two may be filled in --
 * filling both is a validation error, since it's ambiguous which one wins.
 *
 * @param string $dollar_display  Raw (sticky) dollar-amount field value.
 * @param string $percent_display Raw (sticky) percentage field value.
 * @param float  $initial_value   The tool's initial cash value.
 * @return array{amount:float,error:string} amount is the resolved dollar
 *         value (0.0 when both inputs are blank, matching the old
 *         single-field behavior); error is '' on success.
 */
function mtl_resolve_depreciation_amount( $dollar_display, $percent_display, $initial_value ) {
	$dollar_display  = trim( (string) $dollar_display );
	$percent_display = trim( (string) $percent_display );

	if ( '' !== $dollar_display && '' !== $percent_display ) {
		return array(
			'amount' => 0.0,
			'error'  => 'Please enter the annual depreciation as either a dollar amount or a percentage of the initial value &mdash; not both.',
		);
	}

	if ( '' !== $percent_display ) {
		return array(
			'amount' => round( (float) $initial_value * ( floatval( $percent_display ) / 100 ), 2 ),
			'error'  => '',
		);
	}

	return array(
		'amount' => floatval( $dollar_display ),
		'error'  => '',
	);
}

/**
 * Resolves a single Bulk Import CSV cell for annual_depreciation_amount:
 * a value containing "%" (e.g. "5%") is treated as a percentage of the
 * row's initial_cash_value and converted to a dollar figure; anything else
 * is treated as a plain dollar amount, same as before this feature existed.
 *
 * @param string $raw_value     Raw CSV cell value for annual_depreciation_amount.
 * @param float  $initial_value The row's initial_cash_value.
 * @return float Resolved dollar amount.
 */
function mtl_resolve_depreciation_csv_value( $raw_value, $initial_value ) {
	$raw_value = trim( (string) $raw_value );
	if ( '' !== $raw_value && false !== strpos( $raw_value, '%' ) ) {
		$percent = floatval( str_replace( '%', '', $raw_value ) );
		return round( (float) $initial_value * ( $percent / 100 ), 2 );
	}
	return floatval( $raw_value );
}

/**
 * Serves a downloadable CSV template for the Bulk Import feature.
 *
 * Runs on admin_init (before any HTML is sent) rather than inside
 * mtl_render_inventory_page(), since by the time that render callback runs
 * WordPress has already output the page's <head> -- too late for download headers.
 */
add_action( 'admin_init', 'mtl_maybe_serve_csv_template' );

/**
 * Serves the downloadable CSV template for the Bulk Import feature.
 */
function mtl_maybe_serve_csv_template() {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if (
		! isset( $_GET['mtl_download_csv_template'] ) || '' === $page ||
		'mtl-inventory' !== $page ||
		! current_user_can( 'manage_options' )
	) {
		return;
	}

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mtl_download_csv_template_action' ) ) {
		return;
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="tool-inventory-template.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv(
		$out,
		array(
			'tool_name',
			'barcode',
			'brand',
			'description',
			'components',
			'photo_url',
			'initial_cash_value',
			'annual_depreciation_amount',
			'donated_by',
			'date_acquired',
			'categories',
			'tags',
			'private_notes',
		)
	);
	// Clearly-fake example row so admins can see the expected format -- delete/overwrite before uploading real data.
	fputcsv(
		$out,
		array(
			'Example Tool - Delete This Row',
			'EXAMPLE-0000',
			'ExampleBrand',
			'A short description of the tool.',
			'Battery;Charger;Case',
			'https://example.com/photo.jpg',
			'49.99',
			'5.00',
			'Jane Doe',
			// The importer accepts any date string strtotime() understands, but the template shows the site-wide MM/DD/YYYY convention.
			gmdate( 'm/d/Y' ),
			'Woodworking;General Hand Tools',
			'Cordless;Heavy-Duty',
			'Staff-only -- never shown publicly.',
		)
	);
	fclose( $out );
	exit;
}

/**
 * Renders the shared set of tool fields used by both the "Add a New Tool"
 * and "Edit Tool" forms, so the two stay in sync automatically.
 *
 * @param array  $values     Current field values, keyed by field name.
 * @param array  $categories Available categories.
 * @param array  $tags       Available tags.
 * @param string $id_prefix  Prefix for element IDs (e.g. "edit_") so both forms
 *                           can appear on the page without <label for="..."> collisions.
 */
function mtl_render_tool_form_fields( $values, $categories, $tags, $id_prefix = '' ) {
	$field_id = function ( $name ) use ( $id_prefix ) {
		return esc_attr( $id_prefix . $name );
	};
	// $field_id() always returns esc_attr()-escaped output; phpcs can't see
	// through a closure assigned to a variable to verify that.
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'tool_name' ); ?>">Tool Name *</label></th>
		<td>
			<input type="text" name="tool_name" id="<?php echo $field_id( 'tool_name' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['tool_name'] ); ?>" required>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Required. The common name of the tool (e.g. &ldquo;Cordless Drill&rdquo;).</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'barcode' ); ?>">Barcode *</label></th>
		<td>
			<input type="text" name="barcode" id="<?php echo $field_id( 'barcode' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['barcode'] ); ?>" required>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Required. Scan or type the barcode printed on the tool&rsquo;s label (usually numbers, but letters are allowed). Each barcode must be unique &mdash; no two tools can share the same one.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'brand' ); ?>">Brand</label></th>
		<td>
			<input type="text" name="brand" id="<?php echo $field_id( 'brand' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['brand'] ); ?>">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Leave blank if unknown.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'category_id' ); ?>">Categories</label></th>
		<td>
			<select name="category_id[]" id="<?php echo $field_id( 'category_id' ); ?>" multiple size="6" class="mtl-resizable-select">
				<?php foreach ( $categories as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat->category_id ); ?>" <?php echo in_array( (int) $cat->category_id, $values['category_ids'], true ) ? 'selected' : ''; ?>><?php echo esc_html( $cat->category_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Select every category that applies &mdash; a tool can belong to more than one. Hold <strong>Ctrl</strong> (Windows) or <strong>&#8984; Cmd</strong> (Mac) to select or unselect multiple. Drag the bottom-right corner to resize the box. Leave blank if none apply.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'tag_id' ); ?>">Tags</label></th>
		<td>
			<select name="tag_id[]" id="<?php echo $field_id( 'tag_id' ); ?>" multiple size="6" class="mtl-resizable-select">
				<?php foreach ( $tags as $tag ) : ?>
					<option value="<?php echo esc_attr( $tag->tag_id ); ?>" <?php echo in_array( (int) $tag->tag_id, $values['tag_ids'], true ) ? 'selected' : ''; ?>><?php echo esc_html( $tag->tag_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Select every tag that applies &mdash; a tool can have more than one. Hold <strong>Ctrl</strong> (Windows) or <strong>&#8984; Cmd</strong> (Mac) to select or unselect multiple. Drag the bottom-right corner to resize the box. Leave blank if none apply.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'photo_url' ); ?>">Photo URL</label></th>
		<td>
			<input type="url" name="photo_url" id="<?php echo $field_id( 'photo_url' ); ?>" class="regular-text" value="<?php echo esc_url( $values['photo_url'] ); ?>" placeholder="https://...">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Leave blank if unknown. Please make sure the picture link&rsquo;s viewing permissions are set so anyone can view it.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'initial_cash_value' ); ?>">Initial Cash Value ($)</label></th>
		<td>
			<input type="number" step="0.01" min="0" name="initial_cash_value" id="<?php echo $field_id( 'initial_cash_value' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['initial_cash_value'] ); ?>" placeholder="0.00">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Leave blank or enter 0.00 if unknown.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Annual Depreciation</th>
		<td>
			<label for="<?php echo $field_id( 'annual_depreciation_amount' ); ?>" style="display:inline-block; min-width: 100px;">Dollar amount ($)</label>
			<input type="number" step="0.01" min="0" name="annual_depreciation_amount" id="<?php echo $field_id( 'annual_depreciation_amount' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['annual_depreciation_amount'] ); ?>" placeholder="0.00" style="max-width: 150px;">
			<br>
			<label for="<?php echo $field_id( 'annual_depreciation_percent' ); ?>" style="display:inline-block; min-width: 100px; margin-top: 6px;">Percent (%)</label>
			<input type="number" step="0.01" min="0" max="100" name="annual_depreciation_percent" id="<?php echo $field_id( 'annual_depreciation_percent' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['annual_depreciation_percent'] ); ?>" placeholder="0.00" style="max-width: 150px;">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Enter <strong>either</strong> a dollar amount <strong>or</strong> a percentage of the Initial Cash Value above &mdash; not both. A percentage is converted to a dollar amount and stored as one, same as if you&rsquo;d typed it directly. Leave both blank or enter 0 if unknown.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'date_acquired' ); ?>">Date Acquired</label></th>
		<td>
			<input type="date" name="date_acquired" id="<?php echo $field_id( 'date_acquired' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['date_acquired'] ); ?>">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Defaults to today. Change it if the tool was acquired on a different date.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'donated_by' ); ?>">Donated By</label></th>
		<td>
			<input type="text" name="donated_by" id="<?php echo $field_id( 'donated_by' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['donated_by'] ); ?>">
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Leave blank if unknown.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'components' ); ?>">Components</label></th>
		<td>
			<textarea name="components" id="<?php echo $field_id( 'components' ); ?>" rows="3" style="width: 100%; max-width: 400px;"><?php echo esc_textarea( $values['components'] ); ?></textarea>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">List any parts that must be returned with the tool, separated by commas (e.g. &ldquo;Battery, Charger, Carrying Case&rdquo;). Leave blank if none apply.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'description' ); ?>">Description</label></th>
		<td>
			<textarea name="description" id="<?php echo $field_id( 'description' ); ?>" rows="4" style="width: 100%; max-width: 400px;"><?php echo esc_textarea( $values['description'] ); ?></textarea>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;">Leave blank if unknown.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="<?php echo $field_id( 'private_notes' ); ?>">Private Notes</label></th>
		<td>
			<textarea name="private_notes" id="<?php echo $field_id( 'private_notes' ); ?>" rows="4" style="width: 100%; max-width: 400px;"><?php echo esc_textarea( $values['private_notes'] ); ?></textarea>
			<p style="font-size: 0.85em; color: #666; margin: 4px 0 0 0;"><strong>Staff-only.</strong> Never shown on the public catalog or anywhere a member can see it &mdash; visible only here and in this tool&rsquo;s detail view on this page. Leave blank if none.</p>
		</td>
	</tr>
	<?php
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Renders the Inventory admin page.
 */
function mtl_render_inventory_page() {
	global $wpdb;

	// Table names go through $wpdb->prefix to match the {prefix} tables created by schema.sql (see setup-page.php).
	$tbl_inventory    = $wpdb->prefix . 'tool_inventory';
	$tbl_categories   = $wpdb->prefix . 'tool_categories';
	$tbl_cat_map      = $wpdb->prefix . 'tool_category_mappings';
	$tbl_tags         = $wpdb->prefix . 'tool_tags';
	$tbl_tag_map      = $wpdb->prefix . 'tool_tag_mappings';
	$tbl_loans        = $wpdb->prefix . 'loans';
	$tbl_reservations = $wpdb->prefix . 'tool_reservations';
	$tbl_members      = $wpdb->prefix . 'members';

	// Every form on this page posts back to this exact (query-string-free)
	// URL rather than action="". That keeps an in-progress "?mtl_action=edit"
	// link from leaking into an unrelated Add/Delete submission.
	$base_url         = menu_page_url( 'mtl-inventory', false );
	$csv_template_url = wp_nonce_url( add_query_arg( 'mtl_download_csv_template', '1', $base_url ), 'mtl_download_csv_template_action' );

	echo '<div class="wrap mtl-admin-wrapper">';
	echo '<h2>Tool Inventory Management</h2>';

	// Default values for the "Add a New Tool" form; refilled from submitted data
	// on a failed submission so the admin doesn't lose their work. Barcode is
	// deliberately NOT preserved (it's the field that must change to fix the error).
	// $keep_form_open forces the panel open after an error so the data is visible.
	$form_values    = array(
		'tool_name'                   => '',
		'barcode'                     => '',
		'brand'                       => '',
		'photo_url'                   => '',
		'initial_cash_value'          => '',
		'annual_depreciation_amount'  => '',
		'annual_depreciation_percent' => '',
		'date_acquired'               => gmdate( 'Y-m-d' ),
		'donated_by'                  => '',
		'components'                  => '',
		'description'                 => '',
		'private_notes'               => '',
		'category_ids'                => array(),
		'tag_ids'                     => array(),
	);
	$keep_form_open = false;

	// State for the "Edit Tool" panel, which renders only when $editing is true
	// -- either a GET "Edit" link, or a submitted edit that failed validation
	// and needs to be redisplayed with the admin's input intact.
	$editing      = false;
	$edit_tool_id = 0;
	$edit_values  = null;

	// Lookup data for the Category/Tag multi-selects -- fetched up front because
	// the Bulk CSV Import handler below also needs to resolve category/tag
	// names from the uploaded file against these same lists.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	$categories = $wpdb->get_results( "SELECT category_id, category_name FROM {$tbl_categories} ORDER BY category_name ASC" );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no request-derived data.
	$tags = $wpdb->get_results( "SELECT tag_id, tag_name FROM {$tbl_tags} ORDER BY tag_name ASC" );

	// 1. HANDLE "ADD" FORM SUBMISSION (Insert Data)
	if ( isset( $_POST['mtl_add_tool'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_add_tool_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_add_tool_nonce'] ) ), 'mtl_add_tool_action' ) ) {

			// --- Gather + sanitize incoming data ---
			// wp_unslash() removes WordPress magic quotes before sanitizing.
			$tool_name     = sanitize_text_field( wp_unslash( $_POST['tool_name'] ?? '' ) );
			$barcode       = sanitize_text_field( wp_unslash( $_POST['barcode'] ?? '' ) );
			$brand         = sanitize_text_field( wp_unslash( $_POST['brand'] ?? '' ) );
			$photo_url     = sanitize_url( wp_unslash( $_POST['photo_url'] ?? '' ) );
			$donated_by    = sanitize_text_field( wp_unslash( $_POST['donated_by'] ?? '' ) );
			$date_acquired = sanitize_text_field( wp_unslash( $_POST['date_acquired'] ?? '' ) );
			$description   = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
			$components    = sanitize_textarea_field( wp_unslash( $_POST['components'] ?? '' ) );
			$private_notes = sanitize_textarea_field( wp_unslash( $_POST['private_notes'] ?? '' ) );

			// Numeric fields: keep the raw typed string for redisplay (so a blank
			// field stays blank instead of turning into "0"), but store a float.
			$initial_value_display    = isset( $_POST['initial_cash_value'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['initial_cash_value'] ) ) ) : '';
			$depreciation_display     = isset( $_POST['annual_depreciation_amount'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['annual_depreciation_amount'] ) ) ) : '';
			$depreciation_pct_display = isset( $_POST['annual_depreciation_percent'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['annual_depreciation_percent'] ) ) ) : '';
			$initial_value            = floatval( $initial_value_display );
			$depreciation_resolved    = mtl_resolve_depreciation_amount( $depreciation_display, $depreciation_pct_display, $initial_value );
			$depreciation             = $depreciation_resolved['amount'];

			// Multi-selects submit an ARRAY of IDs, and submit NOTHING at all when
			// nothing is chosen. Guard with isset() and coerce every value to int.
			$category_ids = isset( $_POST['category_id'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['category_id'] ) ) : array();
			$tag_ids      = isset( $_POST['tag_id'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['tag_id'] ) ) : array();

			// --- Validate ---
			$error         = false;
			$error_message = '';

			if ( '' !== $depreciation_resolved['error'] ) {
				$error         = true;
				$error_message = $depreciation_resolved['error'];
			} elseif ( '' === $barcode ) {
				// Barcode is required. The HTML "required" attribute normally
				// stops this client-side; this is a re-check in case it is bypassed.
				$error         = true;
				$error_message = 'A barcode is required. The tool was not added.';
			} else {
				// Barcode must be unique. Checked up front to show a clear
				// message. The UNIQUE column constraint in the DB is the final
				// backstop if two admins submit the same barcode simultaneously.
				$barcode_in_use = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
						"SELECT tool_id FROM {$tbl_inventory} WHERE barcode = %s LIMIT 1",
						$barcode
					)
				);
				if ( $barcode_in_use ) {
					$error         = true;
					$error_message = 'That barcode is already being used by another tool in the inventory. The tool was not added &mdash; please enter a different, unique barcode.';
				}
			}

			// --- Insert (only if validation passed) ---
			if ( ! $error ) {
				$inserted = $wpdb->insert(
					$tbl_inventory,
					array(
						'tool_name'                  => $tool_name,
						'barcode'                    => $barcode,
						'brand'                      => $brand,
						'description'                => $description,
						'components'                 => $components,
						'photo_url'                  => $photo_url,
						'initial_cash_value'         => $initial_value,
						'annual_depreciation_amount' => $depreciation,
						'donated_by'                 => $donated_by,
						'date_acquired'              => $date_acquired,
						'private_notes'              => '' !== $private_notes ? $private_notes : null,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s' )
				);

				if ( $inserted ) {
					// tool_id is AUTO_INCREMENT, so read back the ID MySQL assigned.
					$new_tool_id = $wpdb->insert_id;

					// MANY-TO-MANY: one row per selected category in the mapping table.
					foreach ( $category_ids as $cid ) {
						if ( $cid > 0 ) {
							$wpdb->insert(
								$tbl_cat_map,
								array(
									'tool_id'     => $new_tool_id,
									'category_id' => $cid,
								),
								array( '%d', '%d' )
							);
						}
					}

					// MANY-TO-MANY: one row per selected tag in the mapping table.
					foreach ( $tag_ids as $tid ) {
						if ( $tid > 0 ) {
							$wpdb->insert(
								$tbl_tag_map,
								array(
									'tool_id' => $new_tool_id,
									'tag_id'  => $tid,
								),
								array( '%d', '%d' )
							);
						}
					}

					echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> ' . esc_html( stripslashes( $tool_name ) ) . ' has been added to the database.</p></div>';
					// On success the form is left blank (defaults) for the next entry.
				} else {
					$error         = true;
					$error_message = 'Failed to add tool. Please verify the database connection and try again.';
				}
			}

			// --- On any error: show the message and refill the form (except barcode) ---
			if ( $error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . wp_kses_post( $error_message ) . '</p></div>';

				$keep_form_open = true;

				$form_values['tool_name']                   = $tool_name;
				$form_values['brand']                       = $brand;
				$form_values['photo_url']                   = $photo_url;
				$form_values['initial_cash_value']          = $initial_value_display;
				$form_values['annual_depreciation_amount']  = $depreciation_display;
				$form_values['annual_depreciation_percent'] = $depreciation_pct_display;
				$form_values['date_acquired']               = $date_acquired;
				$form_values['donated_by']                  = $donated_by;
				$form_values['components']                  = $components;
				$form_values['description']                 = $description;
				$form_values['private_notes']               = $private_notes;
				$form_values['category_ids']                = $category_ids;
				$form_values['tag_ids']                     = $tag_ids;
				// 'barcode' intentionally left as '' so the admin re-enters it.
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 1B. HANDLE BULK CSV IMPORT SUBMISSION
	// Each row is validated and inserted independently -- one bad row (missing
	// barcode, duplicate barcode, etc.) is skipped and reported, it doesn't
	// abort the rest of the file. tool_id is never read from the CSV; every
	// row goes through the same auto-increment insert as the single Add form.
	$bulk_import_ran      = false;
	$bulk_success_count   = 0;
	$bulk_failed_rows     = array();
	$bulk_warnings        = array();
	$keep_bulk_panel_open = false;

	if ( isset( $_POST['mtl_bulk_import'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_bulk_import_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_bulk_import_nonce'] ) ), 'mtl_bulk_import_action' ) ) {
			$keep_bulk_panel_open = true;

			// $_FILES values below: 'error' and 'size' are always plain
			// integers set by PHP itself (never sanitized per WPCS
			// convention); 'name' and 'tmp_name' are sanitized once here and
			// used via these locals for the rest of this block. 'tmp_name'
			// is also verified with is_uploaded_file() before it's opened.
			$csv_error    = isset( $_FILES['csv_file']['error'] ) ? (int) $_FILES['csv_file']['error'] : UPLOAD_ERR_NO_FILE;
			$csv_name     = isset( $_FILES['csv_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['csv_file']['name'] ) ) : '';
			$csv_tmp_name = isset( $_FILES['csv_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['csv_file']['tmp_name'] ) ) : '';

			if ( ! isset( $_FILES['csv_file'] ) || UPLOAD_ERR_NO_FILE === $csv_error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please choose a CSV file to upload.</p></div>';
			} elseif ( UPLOAD_ERR_OK !== $csv_error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The file failed to upload. Please try again.</p></div>';
			} elseif ( 'csv' !== strtolower( pathinfo( $csv_name, PATHINFO_EXTENSION ) ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please upload a .csv file.</p></div>';
			} elseif ( ! is_uploaded_file( $csv_tmp_name ) ) {
				// Standard defensive check for file uploads -- confirms
				// tmp_name genuinely came from this request's file upload.
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The upload could not be verified. Please try again.</p></div>';
			} elseif (
				// Content-based check on top of the extension check above. The explicit
				// $mimes override is required because core didn't allow "csv" uploads by
				// default until WP 5.9, and this plugin supports 5.8+.
				'csv' !== wp_check_filetype_and_ext(
					$csv_tmp_name,
					$csv_name,
					array( 'csv' => 'text/csv' )
				)['ext']
			) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The uploaded file does not appear to be a genuine CSV file.</p></div>';
			} else {
				$handle = fopen( $csv_tmp_name, 'r' );

				if ( ! $handle ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Could not read the uploaded file.</p></div>';
				} else {
					// Strip a UTF-8 byte-order-mark some spreadsheet apps
					// prepend -- left in place, it corrupts the first header
					// name (e.g. "tool_name" becomes an unmatched
					// "\xEF\xBB\xBFtool_name" and the required-column check
					// below fails even though the column is really there).
					if ( "\xEF\xBB\xBF" !== fread( $handle, 3 ) ) {
						rewind( $handle );
					}

					$header_row = fgetcsv( $handle );

					if ( false === $header_row ) {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The CSV file appears to be empty.</p></div>';
					} else {
						// Map column name -> position so the columns in the
						// uploaded file don't have to be in template order.
						$columns = array();
						foreach ( $header_row as $i => $col_name ) {
							$columns[ strtolower( trim( $col_name ) ) ] = $i;
						}

						if ( ! isset( $columns['tool_name'] ) || ! isset( $columns['barcode'] ) ) {
							echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The CSV must include <code>tool_name</code> and <code>barcode</code> columns. Please use the downloadable template.</p></div>';
						} else {
							// Case-insensitive name -> id lookups so
							// categories/tags in the CSV can be matched by name.
							$category_lookup = array();
							foreach ( $categories as $cat ) {
								$category_lookup[ strtolower( $cat->category_name ) ] = (int) $cat->category_id;
							}
							$tag_lookup = array();
							foreach ( $tags as $tag ) {
								$tag_lookup[ strtolower( $tag->tag_name ) ] = (int) $tag->tag_id;
							}

							// Barcodes claimed earlier in THIS file -- the DB
							// uniqueness check alone can't catch two rows in
							// the same upload both claiming the same barcode,
							// since neither exists in the DB yet when checked.
							$seen_barcodes = array();

							$get_col = function ( $row, $name ) use ( $columns ) {
								return isset( $columns[ $name ], $row[ $columns[ $name ] ] ) ? trim( (string) $row[ $columns[ $name ] ] ) : '';
							};

							$row_number      = 1; // First data row is row 2, matching what a spreadsheet program would show.
							$bulk_import_ran = true;
							$max_bulk_rows   = 5000; // Sanity cap so a huge file can't tie up the request indefinitely.

							while ( true ) {
								$row = fgetcsv( $handle );
								if ( false === $row ) {
									break;
								}
								++$row_number;

								if ( $row_number - 1 > $max_bulk_rows ) {
									$bulk_warnings[] = 'Import stopped after ' . $max_bulk_rows . ' rows; the remaining rows in this file were not processed. Split large imports into multiple files.';
									break;
								}

								// Skip a genuinely blank line.
								if ( 1 === count( $row ) && ( ! isset( $row[0] ) || '' === trim( (string) $row[0] ) ) ) {
									continue;
								}

								$row_tool_name     = sanitize_text_field( $get_col( $row, 'tool_name' ) );
								$row_barcode       = sanitize_text_field( $get_col( $row, 'barcode' ) );
								$row_brand         = sanitize_text_field( $get_col( $row, 'brand' ) );
								$row_photo_url     = sanitize_url( $get_col( $row, 'photo_url' ) );
								$row_donated_by    = sanitize_text_field( $get_col( $row, 'donated_by' ) );
								$row_description   = sanitize_textarea_field( $get_col( $row, 'description' ) );
								$row_components    = sanitize_textarea_field( $get_col( $row, 'components' ) );
								$row_private_notes = sanitize_textarea_field( $get_col( $row, 'private_notes' ) );

								$row_date_raw = $get_col( $row, 'date_acquired' );
								$row_date     = ( '' !== $row_date_raw && strtotime( $row_date_raw ) ) ? gmdate( 'Y-m-d', strtotime( $row_date_raw ) ) : gmdate( 'Y-m-d' );

								$row_initial_value = floatval( $get_col( $row, 'initial_cash_value' ) );
								// A "%" in this column (e.g. "5%") is a percentage of
								// initial_cash_value; anything else is a plain dollar amount.
								$row_depreciation = mtl_resolve_depreciation_csv_value( $get_col( $row, 'annual_depreciation_amount' ), $row_initial_value );

								if ( '' === $row_tool_name ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Missing tool_name.',
									);
									continue;
								}
								if ( '' === $row_barcode ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Missing barcode.',
									);
									continue;
								}
								if ( isset( $seen_barcodes[ strtolower( $row_barcode ) ] ) ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Duplicate barcode "' . $row_barcode . '" also appears earlier in this file.',
									);
									continue;
								}

								$barcode_in_use = $wpdb->get_var(
									$wpdb->prepare(
										// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
										"SELECT tool_id FROM {$tbl_inventory} WHERE barcode = %s LIMIT 1",
										$row_barcode
									)
								);
								if ( $barcode_in_use ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Barcode "' . $row_barcode . '" is already used by an existing tool.',
									);
									continue;
								}

								// Unknown category/tag names don't fail the row -- they're just
								// skipped and reported as notes, since categories/tags are optional.
								// sanitize_text_field() runs here (not just at output) as defense
								// in depth alongside the esc_html() applied when warnings render.
								$row_category_ids = array();
								foreach ( array_filter( array_map( 'sanitize_text_field', explode( ';', $get_col( $row, 'categories' ) ) ) ) as $name ) {
									if ( isset( $category_lookup[ strtolower( $name ) ] ) ) {
										$row_category_ids[] = $category_lookup[ strtolower( $name ) ];
									} else {
										$bulk_warnings[] = 'Row ' . $row_number . ': unknown category "' . $name . '" was skipped.';
									}
								}

								$row_tag_ids = array();
								foreach ( array_filter( array_map( 'sanitize_text_field', explode( ';', $get_col( $row, 'tags' ) ) ) ) as $name ) {
									if ( isset( $tag_lookup[ strtolower( $name ) ] ) ) {
										$row_tag_ids[] = $tag_lookup[ strtolower( $name ) ];
									} else {
										$bulk_warnings[] = 'Row ' . $row_number . ': unknown tag "' . $name . '" was skipped.';
									}
								}

								$row_inserted = $wpdb->insert(
									$tbl_inventory,
									array(
										'tool_name'     => $row_tool_name,
										'barcode'       => $row_barcode,
										'brand'         => $row_brand,
										'description'   => $row_description,
										'components'    => $row_components,
										'photo_url'     => $row_photo_url,
										'initial_cash_value' => $row_initial_value,
										'annual_depreciation_amount' => $row_depreciation,
										'donated_by'    => $row_donated_by,
										'date_acquired' => $row_date,
										'private_notes' => '' !== $row_private_notes ? $row_private_notes : null,
									),
									array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s' )
								);

								if ( ! $row_inserted ) {
									$bulk_failed_rows[] = array(
										'row'    => $row_number,
										'reason' => 'Database error while adding this row.',
									);
									continue;
								}

								// tool_id is AUTO_INCREMENT -- never taken from the CSV.
								$row_tool_id = $wpdb->insert_id;

								foreach ( $row_category_ids as $cid ) {
									$wpdb->insert(
										$tbl_cat_map,
										array(
											'tool_id'     => $row_tool_id,
											'category_id' => $cid,
										),
										array( '%d', '%d' )
									);
								}
								foreach ( $row_tag_ids as $tid ) {
									$wpdb->insert(
										$tbl_tag_map,
										array(
											'tool_id' => $row_tool_id,
											'tag_id'  => $tid,
										),
										array( '%d', '%d' )
									);
								}

								$seen_barcodes[ strtolower( $row_barcode ) ] = true;
								++$bulk_success_count;
							}
						}
					}
				}

				fclose( $handle );
			}

			// --- Report results ---
			if ( $bulk_import_ran ) {
				$bulk_fail_count = count( $bulk_failed_rows );

				if ( $bulk_success_count > 0 && 0 === $bulk_fail_count ) {
					echo '<div class="notice notice-success is-dismissible"><p><strong>Bulk Import Complete!</strong> ' . intval( $bulk_success_count ) . ' tool(s) were added to the inventory.</p></div>';
				} elseif ( $bulk_success_count > 0 && $bulk_fail_count > 0 ) {
					echo '<div class="notice notice-warning is-dismissible"><p><strong>Bulk Import Finished with Errors:</strong> ' . intval( $bulk_success_count ) . ' tool(s) added, but ' . intval( $bulk_fail_count ) . ' row(s) failed. See details below.</p></div>';
				} elseif ( 0 === $bulk_success_count && $bulk_fail_count > 0 ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Bulk Import Failed:</strong> None of the ' . intval( $bulk_fail_count ) . ' row(s) could be added. See details below.</p></div>';
				} else {
					echo '<div class="notice notice-warning is-dismissible"><p><strong>Nothing to import.</strong> The CSV file had no data rows.</p></div>';
				}

				if ( $bulk_fail_count > 0 ) {
					echo '<details open style="background: #fdf2f2; border: 1px solid #e6b3b3; border-radius: 4px; padding: 10px 15px; margin-bottom: 15px; max-width: 800px;">';
					echo '<summary style="cursor: pointer; font-weight: 600; color: #b32d2e;">' . intval( $bulk_fail_count ) . ' row(s) failed &mdash; click to view details</summary>';
					echo '<ul style="margin: 10px 0 0 20px;">';
					foreach ( $bulk_failed_rows as $f ) {
						echo '<li>Row ' . intval( $f['row'] ) . ': ' . esc_html( $f['reason'] ) . '</li>';
					}
					echo '</ul></details>';
				}

				if ( ! empty( $bulk_warnings ) ) {
					echo '<details style="background: #fff8e5; border: 1px solid #f0dca0; border-radius: 4px; padding: 10px 15px; margin-bottom: 15px; max-width: 800px;">';
					echo '<summary style="cursor: pointer; font-weight: 600; color: #8a6d00;">' . count( $bulk_warnings ) . ' note(s) &mdash; click to view details</summary>';
					echo '<ul style="margin: 10px 0 0 20px;">';
					foreach ( $bulk_warnings as $w ) {
						echo '<li>' . esc_html( $w ) . '</li>';
					}
					echo '</ul></details>';
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 2. HANDLE "EDIT" FORM SUBMISSION (Update Data)
	if ( isset( $_POST['mtl_update_tool'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_edit_tool_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_edit_tool_nonce'] ) ), 'mtl_edit_tool_action' ) ) {

			$edit_tool_id = isset( $_POST['tool_id'] ) ? intval( $_POST['tool_id'] ) : 0;

			$tool_name     = sanitize_text_field( wp_unslash( $_POST['tool_name'] ?? '' ) );
			$barcode       = sanitize_text_field( wp_unslash( $_POST['barcode'] ?? '' ) );
			$brand         = sanitize_text_field( wp_unslash( $_POST['brand'] ?? '' ) );
			$photo_url     = sanitize_url( wp_unslash( $_POST['photo_url'] ?? '' ) );
			$donated_by    = sanitize_text_field( wp_unslash( $_POST['donated_by'] ?? '' ) );
			$date_acquired = sanitize_text_field( wp_unslash( $_POST['date_acquired'] ?? '' ) );
			$description   = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
			$components    = sanitize_textarea_field( wp_unslash( $_POST['components'] ?? '' ) );
			$private_notes = sanitize_textarea_field( wp_unslash( $_POST['private_notes'] ?? '' ) );

			$initial_value_display    = isset( $_POST['initial_cash_value'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['initial_cash_value'] ) ) ) : '';
			$depreciation_display     = isset( $_POST['annual_depreciation_amount'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['annual_depreciation_amount'] ) ) ) : '';
			$depreciation_pct_display = isset( $_POST['annual_depreciation_percent'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['annual_depreciation_percent'] ) ) ) : '';
			$initial_value            = floatval( $initial_value_display );
			$depreciation_resolved    = mtl_resolve_depreciation_amount( $depreciation_display, $depreciation_pct_display, $initial_value );
			$depreciation             = $depreciation_resolved['amount'];

			$category_ids = isset( $_POST['category_id'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['category_id'] ) ) : array();
			$tag_ids      = isset( $_POST['tag_id'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['tag_id'] ) ) : array();

			$error         = false;
			$error_message = '';

			if ( '' !== $depreciation_resolved['error'] ) {
				$error         = true;
				$error_message = $depreciation_resolved['error'];
			} elseif ( $edit_tool_id <= 0 ) {
				$error         = true;
				$error_message = 'Could not determine which tool to update. Please try again.';
			} elseif ( '' === $barcode ) {
				$error         = true;
				$error_message = 'A barcode is required. The tool was not updated.';
			} else {
				// Barcode must stay unique, but must not collide with ITSELF.
				$barcode_in_use = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
						"SELECT tool_id FROM {$tbl_inventory} WHERE barcode = %s AND tool_id != %d LIMIT 1",
						$barcode,
						$edit_tool_id
					)
				);
				if ( $barcode_in_use ) {
					$error         = true;
					$error_message = 'That barcode is already being used by another tool in the inventory. Please enter a different, unique barcode.';
				}
			}

			if ( ! $error ) {
				// tool_id is intentionally excluded from this array -- it is the
				// primary key and is never editable.
				$updated = $wpdb->update(
					$tbl_inventory,
					array(
						'tool_name'                  => $tool_name,
						'barcode'                    => $barcode,
						'brand'                      => $brand,
						'description'                => $description,
						'components'                 => $components,
						'photo_url'                  => $photo_url,
						'initial_cash_value'         => $initial_value,
						'annual_depreciation_amount' => $depreciation,
						'donated_by'                 => $donated_by,
						'date_acquired'              => $date_acquired,
						'private_notes'              => '' !== $private_notes ? $private_notes : null,
					),
					array( 'tool_id' => $edit_tool_id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s' ),
					array( '%d' )
				);

				// $wpdb->update() returns the number of rows changed, which is
				// legitimately 0 when nothing actually differed -- only `false`
				// means a real failure.
				if ( false === $updated ) {
					$error         = true;
					$error_message = 'Failed to update tool. Please verify the database connection and try again.';
				} else {
					// Re-sync the category/tag mappings by clearing and re-inserting
					// the current selections -- simplest way to add AND remove
					// mappings in one step without diffing old vs. new.
					$wpdb->delete( $tbl_cat_map, array( 'tool_id' => $edit_tool_id ), array( '%d' ) );
					$wpdb->delete( $tbl_tag_map, array( 'tool_id' => $edit_tool_id ), array( '%d' ) );

					foreach ( $category_ids as $cid ) {
						if ( $cid > 0 ) {
							$wpdb->insert(
								$tbl_cat_map,
								array(
									'tool_id'     => $edit_tool_id,
									'category_id' => $cid,
								),
								array( '%d', '%d' )
							);
						}
					}
					foreach ( $tag_ids as $tid ) {
						if ( $tid > 0 ) {
							$wpdb->insert(
								$tbl_tag_map,
								array(
									'tool_id' => $edit_tool_id,
									'tag_id'  => $tid,
								),
								array( '%d', '%d' )
							);
						}
					}

					echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> ' . esc_html( stripslashes( $tool_name ) ) . ' has been updated.</p></div>';
				}
			}

			if ( $error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . wp_kses_post( $error_message ) . '</p></div>';

				// Re-open the Edit panel with what the admin typed (not the DB's
				// stale copy) so a validation error never discards their edits.
				$editing     = true;
				$edit_values = array(
					'tool_name'                   => $tool_name,
					'barcode'                     => $barcode,
					'brand'                       => $brand,
					'photo_url'                   => $photo_url,
					'initial_cash_value'          => $initial_value_display,
					'annual_depreciation_amount'  => $depreciation_display,
					'annual_depreciation_percent' => $depreciation_pct_display,
					'date_acquired'               => $date_acquired,
					'donated_by'                  => $donated_by,
					'components'                  => $components,
					'description'                 => $description,
					'private_notes'               => $private_notes,
					'category_ids'                => $category_ids,
					'tag_ids'                     => $tag_ids,
				);
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3. HANDLE "DELETE" FORM SUBMISSION
	if ( isset( $_POST['mtl_delete_tool'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_delete_tool_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_delete_tool_nonce'] ) ), 'mtl_delete_tool_action' ) ) {

			$delete_tool_id = isset( $_POST['tool_id'] ) ? intval( $_POST['tool_id'] ) : 0;

			if ( $delete_tool_id > 0 ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				$deleted_name = $wpdb->get_var( $wpdb->prepare( "SELECT tool_name FROM {$tbl_inventory} WHERE tool_id = %d", $delete_tool_id ) );
				$deleted      = $wpdb->delete( $tbl_inventory, array( 'tool_id' => $delete_tool_id ), array( '%d' ) );

				if ( $deleted ) {
					echo '<div class="notice notice-success is-dismissible"><p><strong>Deleted.</strong> ' . esc_html( stripslashes( (string) $deleted_name ) ) . ' has been permanently removed from the inventory.</p></div>';
				} else {
					// loans/tool_reservations reference tool_id WITHOUT a cascade,
					// so deleting a tool with loan/reservation history fails at the
					// FK constraint. Surface that as a clear message instead of a
					// raw SQL error.
					$db_error = $wpdb->last_error;
					if ( $db_error && stripos( $db_error, 'foreign key' ) !== false ) {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Cannot delete this tool.</strong> It has loan or reservation history on record, so it cannot be removed from inventory.</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That tool could not be found or was already deleted.</p></div>';
					}
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3B. HANDLE "QUICK LOAN" SUBMISSION -- loan a tool directly to a member who
	// has no reservation (a walk-in). Creates a loan row straight away, with the
	// admin-entered due date, after confirming the tool isn't already out.
	// Every {$tbl_*} fragment interpolated in the queries through the end of
	// the Quick Reserve handler below is a table name only, built from
	// $wpdb->prefix, never request data.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( isset( $_POST['mtl_quick_loan'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_quick_loan_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_quick_loan_nonce'] ) ), 'mtl_quick_loan_action' ) ) {

			$ql_tool_id   = isset( $_POST['tool_id'] ) ? intval( $_POST['tool_id'] ) : 0;
			$ql_member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
			$ql_due       = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
			$ql_due_error = false;
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ql_due ) || ! strtotime( $ql_due ) ) {
				$ql_due = gmdate( 'Y-m-d', strtotime( '+' . (int) get_option( 'mtl_default_loan_days', 21 ) . ' days' ) );
			} elseif ( $ql_due < gmdate( 'Y-m-d' ) ) {
				$ql_due_error = true;
			}

			$ql_tool_name = $ql_tool_id > 0 ? $wpdb->get_var( $wpdb->prepare( "SELECT tool_name FROM {$tbl_inventory} WHERE tool_id = %d", $ql_tool_id ) ) : null;
			$ql_retired   = $ql_tool_id > 0 ? $wpdb->get_var( $wpdb->prepare( "SELECT retired_at FROM {$tbl_inventory} WHERE tool_id = %d", $ql_tool_id ) ) : null;
			$ql_member_ok = $ql_member_id > 0 ? $wpdb->get_var( $wpdb->prepare( "SELECT member_id FROM {$tbl_members} WHERE member_id = %d", $ql_member_id ) ) : null;

			if ( $ql_due_error ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The due date can&rsquo;t be in the past. Please pick today or a later date.</p></div>';
			} elseif ( ! $ql_tool_name ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That tool could not be found.</p></div>';
			} elseif ( ! empty( $ql_retired ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Cannot loan this tool.</strong> It is retired. Reactivate it first.</p></div>';
			} elseif ( ! $ql_member_ok ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please pick a member from the list before creating the loan.</p></div>';
			} else {
				// A tool is one physical item -- it can't go out twice at once.
				$ql_on_loan = $wpdb->get_var( $wpdb->prepare( "SELECT loan_id FROM {$tbl_loans} WHERE tool_id = %d AND return_date IS NULL LIMIT 1", $ql_tool_id ) );
				if ( $ql_on_loan ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Cannot loan this tool.</strong> It is already checked out. End the current loan first.</p></div>';
				} else {
					$ql_inserted = $wpdb->insert(
						$tbl_loans,
						array(
							'tool_id'   => $ql_tool_id,
							'member_id' => $ql_member_id,
							'loan_date' => current_time( 'mysql' ),
							'due_date'  => $ql_due,
						),
						array( '%d', '%d', '%s', '%s' )
					);
					if ( $ql_inserted ) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>Loan created.</strong> ' . esc_html( stripslashes( (string) $ql_tool_name ) ) . ' is now on loan, due ' . mtl_format_date( $ql_due ) . '.</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The loan could not be recorded. Please try again.</p></div>';
					}
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3B2. HANDLE "QUICK RESERVE" SUBMISSION -- the admin-side counterpart to a
	// member's self-service reserve (see mtl_handle_reserve_action() in
	// public/member-pages.php), for a member who wants to reserve in person
	// rather than online. Uses the same shared Quick Loan modal/nonce, with
	// the due-date field hidden -- reservations don't have one.
	if ( isset( $_POST['mtl_quick_reserve'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_quick_loan_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_quick_loan_nonce'] ) ), 'mtl_quick_loan_action' ) ) {

			$qr_tool_id   = isset( $_POST['tool_id'] ) ? intval( $_POST['tool_id'] ) : 0;
			$qr_member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;

			$qr_tool_name = $qr_tool_id > 0 ? $wpdb->get_var( $wpdb->prepare( "SELECT tool_name FROM {$tbl_inventory} WHERE tool_id = %d", $qr_tool_id ) ) : null;
			$qr_retired   = $qr_tool_id > 0 ? $wpdb->get_var( $wpdb->prepare( "SELECT retired_at FROM {$tbl_inventory} WHERE tool_id = %d", $qr_tool_id ) ) : null;
			$qr_member_ok = $qr_member_id > 0 ? $wpdb->get_var( $wpdb->prepare( "SELECT member_id FROM {$tbl_members} WHERE member_id = %d", $qr_member_id ) ) : null;

			if ( ! $qr_tool_name ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That tool could not be found.</p></div>';
			} elseif ( ! empty( $qr_retired ) ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Cannot reserve this tool.</strong> It is retired. Reactivate it first.</p></div>';
			} elseif ( ! $qr_member_ok ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Please pick a member from the list before creating the reservation.</p></div>';
			} else {
				// Same two guards as the public self-service reserve flow: no
				// reserving a tool the member already has on loan, and no
				// duplicate active reservation for the same member/tool.
				$qr_on_loan = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT loan_id FROM {$tbl_loans} WHERE member_id = %d AND tool_id = %d AND return_date IS NULL LIMIT 1",
						$qr_member_id,
						$qr_tool_id
					)
				);
				$qr_already = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT reservation_id FROM {$tbl_reservations} WHERE member_id = %d AND tool_id = %d AND expiry_date IS NULL LIMIT 1",
						$qr_member_id,
						$qr_tool_id
					)
				);
				if ( $qr_on_loan ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Cannot reserve this tool.</strong> This member already has it on loan.</p></div>';
				} elseif ( $qr_already ) {
					echo '<div class="notice notice-error is-dismissible"><p><strong>Cannot reserve this tool.</strong> This member already has an active reservation for it.</p></div>';
				} else {
					$qr_inserted = $wpdb->insert(
						$tbl_reservations,
						array(
							'tool_id'          => $qr_tool_id,
							'member_id'        => $qr_member_id,
							'reservation_date' => current_time( 'mysql' ),
						),
						array( '%d', '%d', '%s' )
					);
					if ( $qr_inserted ) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>Reservation created.</strong> ' . esc_html( stripslashes( (string) $qr_tool_name ) ) . ' has been reserved for this member.</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> The reservation could not be recorded. Please try again.</p></div>';
					}
				}
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// 3C. HANDLE "MARK RETURNED" SUBMISSION -- the admin drop-off flow: scan the
	// barcode, expand that tool's row, click Mark Returned. Sets the current
	// moment as return_date on the tool's active loan, which is what puts it
	// back "in inventory" everywhere else on this page (return_date IS NULL
	// == on loan).
	if ( isset( $_POST['mtl_mark_returned'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_mark_returned_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_mark_returned_nonce'] ) ), 'mtl_mark_returned_action' ) ) {

			$mr_loan_id = isset( $_POST['loan_id'] ) ? intval( $_POST['loan_id'] ) : 0;
			$mr_done    = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
					"UPDATE {$tbl_loans} SET return_date = %s WHERE loan_id = %d AND return_date IS NULL",
					current_time( 'mysql' ),
					$mr_loan_id
				)
			);

			if ( $mr_done ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>Marked returned.</strong> The tool is back in inventory.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That loan could not be found, or was already marked returned.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3D. HANDLE "RETIRE" SUBMISSION -- the soft-delete counterpart to Delete,
	// for a tool with loan/reservation history (Delete stays blocked at the
	// FK constraint for those; see the DELETE handler above). Hides the tool
	// from the public catalog and blocks new loans/reservations, but keeps
	// the row and its full history intact -- and is reversible via Reactivate,
	// unlike a member delete/anonymize.
	if ( isset( $_POST['mtl_retire_tool'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_retire_tool_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_retire_tool_nonce'] ) ), 'mtl_retire_tool_action' ) ) {
			$rt_tool_id = isset( $_POST['tool_id'] ) ? intval( $_POST['tool_id'] ) : 0;
			$rt_done    = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
					"UPDATE {$tbl_inventory} SET retired_at = %s WHERE tool_id = %d AND retired_at IS NULL",
					current_time( 'mysql' ),
					$rt_tool_id
				)
			);
			if ( $rt_done ) {
				// A retired tool can't be reserved going forward, so any
				// reservations already queued for it no longer make sense --
				// close them out the same way a cancellation does. A currently
				// open LOAN is deliberately left alone; it can still be ended
				// normally whenever it's actually resolved.
				$rt_cancelled = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
						"UPDATE {$tbl_reservations} SET expiry_date = %s WHERE tool_id = %d AND expiry_date IS NULL",
						current_time( 'mysql' ),
						$rt_tool_id
					)
				);
				$rt_note = $rt_cancelled ? ( ' ' . (int) $rt_cancelled . ' active reservation(s) for it were also cancelled.' ) : '';
				echo '<div class="notice notice-success is-dismissible"><p><strong>Retired.</strong> This tool is now hidden from the public catalog and can&rsquo;t be loaned or reserved.' . esc_html( $rt_note ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That tool could not be found, or is already retired.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 3E. HANDLE "REACTIVATE" SUBMISSION -- clears a Retire, unlike a member
	// delete/anonymize this is fully and safely reversible.
	if ( isset( $_POST['mtl_reactivate_tool'] ) && current_user_can( 'manage_options' ) ) {
		if ( isset( $_POST['mtl_reactivate_tool_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_reactivate_tool_nonce'] ) ), 'mtl_reactivate_tool_action' ) ) {
			$ra_tool_id = isset( $_POST['tool_id'] ) ? intval( $_POST['tool_id'] ) : 0;
			$ra_done    = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
					"UPDATE {$tbl_inventory} SET retired_at = NULL WHERE tool_id = %d",
					$ra_tool_id
				)
			);
			if ( $ra_done ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>Reactivated.</strong> This tool is back in the public catalog and available to loan or reserve.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> That tool could not be found, or was already active.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
		}
	}

	// 4. HANDLE "EDIT" LINK (GET) -- load the requested tool into the Edit panel.
	// Skipped if a submitted edit above already failed validation, since that
	// block already populated $editing/$edit_values with the admin's input.
	$get_mtl_action = isset( $_GET['mtl_action'] ) ? sanitize_key( wp_unslash( $_GET['mtl_action'] ) ) : '';
	if ( ! $editing && current_user_can( 'manage_options' ) && isset( $_GET['tool_id'] ) && 'edit' === $get_mtl_action ) {
		$edit_tool_id = intval( $_GET['tool_id'] );

		if ( $edit_tool_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
			$tool_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl_inventory} WHERE tool_id = %d", $edit_tool_id ) );

			if ( $tool_row ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				$existing_cat_ids = $wpdb->get_col( $wpdb->prepare( "SELECT category_id FROM {$tbl_cat_map} WHERE tool_id = %d", $edit_tool_id ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
				$existing_tag_ids = $wpdb->get_col( $wpdb->prepare( "SELECT tag_id FROM {$tbl_tag_map} WHERE tool_id = %d", $edit_tool_id ) );

				$editing     = true;
				$edit_values = array(
					'tool_name'                   => stripslashes( $tool_row->tool_name ),
					'barcode'                     => stripslashes( $tool_row->barcode ),
					'brand'                       => stripslashes( $tool_row->brand ),
					'photo_url'                   => $tool_row->photo_url,
					'initial_cash_value'          => $tool_row->initial_cash_value,
					'annual_depreciation_amount'  => $tool_row->annual_depreciation_amount,
					// DB only ever stores a dollar amount; this stays blank so Edit
					// opens with the $ field populated and the % field empty, same
					// as if the admin had originally typed a dollar amount.
					'annual_depreciation_percent' => '',
					'date_acquired'               => $tool_row->date_acquired,
					'donated_by'                  => stripslashes( (string) $tool_row->donated_by ),
					'components'                  => stripslashes( (string) $tool_row->components ),
					'description'                 => stripslashes( (string) $tool_row->description ),
					'private_notes'               => stripslashes( (string) $tool_row->private_notes ),
					'category_ids'                => array_map( 'intval', $existing_cat_ids ),
					'tag_ids'                     => array_map( 'intval', $existing_tag_ids ),
				);
			} else {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Not found.</strong> That tool no longer exists.</p></div>';
			}
		}
	}

	?>

	<style>
		/* Multi-selects: let the admin drag-resize them instead of being stuck at size="6". */
		.mtl-resizable-select {
			min-width: 250px;
			min-height: 120px;
			padding: 4px;
			resize: vertical;
			overflow: auto;
		}

		.mtl-badge {
			display: inline-block;
			background: #eef3f7;
			color: #096491;
			border: 1px solid #d3dde4;
			border-radius: 12px;
			padding: 2px 9px;
			margin: 2px 4px 2px 0;
			font-size: 0.8em;
			white-space: nowrap;
		}

		.mtl-table-wrap {
			overflow-x: auto;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			background: #fff;
		}

		#mtl-inventory-table {
			border: none;
		}

		#mtl-inventory-table th {
			background: #f6f7f7;
			text-transform: uppercase;
			font-size: 0.75em;
			letter-spacing: 0.03em;
			padding: 10px 8px;
		}

		#mtl-inventory-table td {
			padding: 10px 8px;
			vertical-align: top;
		}

		#mtl-inventory-table tbody tr:hover {
			background-color: #f0f7fb;
		}

		/* table-layout: fixed (from the "fixed" class) sizes any column with
			no explicit width from that column's FIRST data row -- so one tool
			with a long name or a long list of categories/tags would otherwise
			silently force that column (and the whole table) wider than the
			screen, on every screen size, for every row. Every column below
			gets an explicit width for exactly this reason; text-only columns
			also truncate with an ellipsis (full value in the title tooltip,
			same pattern as Membership's table) so a long value can't stretch
			its column even before this rule ever applies. */
		#mtl-inventory-table .mtl-truncate {
			max-width: 100%;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		#mtl-inventory-table .mtl-actions {
			white-space: nowrap;
		}

		.mtl-btn-danger.button {
			color: #b32d2e;
			border-color: #b32d2e;
			background: #fff;
		}

		.mtl-btn-danger.button:hover {
			background: #b32d2e;
			color: #fff;
		}

		.mtl-verified-badge {
			display: inline-block;
			background: #edf7ed;
			color: #1e7e34;
			border: 1px solid #bfe3c0;
			border-radius: 12px;
			padding: 2px 9px;
			font-size: 0.8em;
			white-space: nowrap;
		}

		.mtl-unverified-badge {
			display: inline-block;
			background: #fdf2f2;
			color: #b32d2e;
			border: 1px solid #e6b3b3;
			border-radius: 12px;
			padding: 2px 9px;
			font-size: 0.8em;
			white-space: nowrap;
		}

		.mtl-sensitive-note {
			background: #fff8e5;
			border-left: 4px solid #dba617;
			padding: 8px 12px;
			font-size: 0.85em;
		}

		.mtl-sort-label {
			font-size: 0.85em;
			font-weight: 600;
			display: flex;
			align-items: center;
			gap: 5px;
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

		/* Expandable rows */
		.mtl-tool-row {
			cursor: pointer;
		}

		.mtl-tool-row.mtl-row-expanded {
			background-color: #eaf3fa;
		}

		.mtl-detail-row td {
			background: #fafbfc;
			padding: 16px 24px;
		}

		.mtl-detail-panel {
			display: flex;
			gap: 40px;
			flex-wrap: wrap;
			cursor: default;
		}

		.mtl-detail-col {
			flex: 1 1 320px;
			min-width: 280px;
		}

		.mtl-detail-photo {
			display: block;
			max-width: 160px;
			max-height: 160px;
			border-radius: 4px;
			border: 1px solid #ddd;
			margin-top: 4px;
		}

		.mtl-detail-panel p {
			margin: 4px 0 14px 0;
			white-space: pre-wrap;
		}

		/* Quick Loan action bar spans the full width above the two columns. */
		.mtl-detail-actions {
			flex: 1 1 100%;
			display: flex;
			align-items: center;
			gap: 12px;
			flex-wrap: wrap;
			padding-bottom: 14px;
			margin-bottom: 4px;
			border-bottom: 1px solid #e5e8eb;
		}

		.mtl-detail-actions-hint {
			font-size: 0.88em;
			color: #787c82;
		}

		.mtl-detail-actions-out {
			color: #8a6d00;
		}

		/* ---- Quick Loan modal ---- */
		.mtl-ql-overlay {
			position: fixed;
			inset: 0;
			z-index: 100000;
			background: rgba(0, 0, 0, .5);
			display: flex;
			align-items: flex-start;
			justify-content: center;
			padding: 8vh 16px 16px 16px;
			overflow-y: auto;
		}

		.mtl-ql-modal {
			position: relative;
			background: #fff;
			border-radius: 6px;
			box-shadow: 0 8px 30px rgba(0, 0, 0, .3);
			padding: 22px 24px 24px 24px;
			width: 100%;
			max-width: 420px;
		}

		.mtl-ql-close {
			position: absolute;
			top: 8px;
			right: 10px;
			border: none;
			background: none;
			font-size: 1.6em;
			line-height: 1;
			color: #787c82;
			cursor: pointer;
		}

		.mtl-ql-close:hover {
			color: #1d2327;
		}

		.mtl-ql-tool-line {
			margin: 0 0 16px 0;
			color: #50575e;
		}

		.mtl-ql-label {
			display: block;
			font-weight: 600;
			font-size: 0.9em;
			margin-bottom: 4px;
		}

		.mtl-ql-autocomplete {
			position: relative;
		}

		.mtl-ql-autocomplete input[type="text"] {
			width: 100%;
			box-sizing: border-box;
			padding: 7px 10px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
		}

		.mtl-ql-dropdown {
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

		.mtl-ql-option {
			padding: 7px 10px;
			cursor: pointer;
			font-size: 0.9em;
			border-top: 1px solid #f0f1f2;
		}

		.mtl-ql-option:first-child {
			border-top: none;
		}

		.mtl-ql-option:hover,
		.mtl-ql-option.mtl-ql-option-active {
			background: #f0f7fb;
		}

		.mtl-ql-option .mtl-ql-option-email {
			color: #787c82;
			font-size: 0.9em;
		}

		.mtl-ql-empty {
			padding: 8px 10px;
			color: #787c82;
			font-size: 0.9em;
		}

		.mtl-ql-hint {
			font-size: 0.8em;
			color: #787c82;
			margin: 5px 0 14px 0;
		}

		.mtl-ql-hint.mtl-ql-hint-error {
			color: #b32d2e;
			font-weight: 600;
		}

		.mtl-ql-verified-pill {
			margin: -2px 0 14px 0;
		}

		.mtl-ql-due-quick {
			display: flex;
			gap: 6px;
			flex-wrap: wrap;
			margin-bottom: 8px;
		}

		.mtl-ql-due-active {
			background: var(--mtl-header-color, #ff6600) !important;
			border-color: var(--mtl-header-color, #ff6600) !important;
			color: #fff !important;
		}

		#mtl-ql-due {
			padding: 6px 8px;
			border: 1px solid #8c8f94;
			border-radius: 4px;
		}

		.mtl-ql-actions {
			display: flex;
			gap: 10px;
			margin-top: 20px;
		}

		/* Per-tool activity tiles, queue list and value rows. */
		.mtl-tool-stats {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			margin: 8px 0 16px 0;
		}

		.mtl-tool-stat {
			display: flex;
			flex-direction: column;
			background: #fff;
			border: 1px solid #e2e5e8;
			border-radius: 4px;
			padding: 6px 12px;
			font-size: 0.78em;
			color: #787c82;
			min-width: 74px;
		}

		.mtl-tool-stat b {
			font-size: 1.6em;
			line-height: 1.1;
			color: var(--mtl-header-color, #ff6600);
		}

		.mtl-tool-stat-warn {
			border-color: #e6b3b3;
			background: #fdf6f6;
		}

		.mtl-tool-stat-warn b {
			color: #b32d2e;
		}

		.mtl-tool-list {
			list-style: none;
			margin: 6px 0 16px 0;
			padding: 0;
		}

		.mtl-tool-list li {
			padding: 6px 0;
			border-top: 1px solid #eef0f2;
			font-size: 0.9em;
		}

		.mtl-tool-list-meta {
			display: block;
			color: #999;
			font-size: 0.85em;
		}

		.mtl-tool-fields {
			margin: 6px 0 0 0;
		}

		.mtl-tool-field {
			display: flex;
			justify-content: space-between;
			gap: 12px;
			padding: 6px 0;
			border-top: 1px solid #eef0f2;
			font-size: 0.9em;
		}

		.mtl-tool-field span:first-child {
			color: #787c82;
		}

		/* Pagination bars (above + below the table). */
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

		#mtl-page-indicator {
			font-size: 0.9em;
			min-width: 120px;
			text-align: center;
		}

		.mtl-pagination-bar .button[disabled] {
			opacity: 0.5;
			cursor: not-allowed;
		}

		/* Resizable columns: border-box makes a th's set width match its
			on-screen width exactly, and the grip is a thin drag zone on the
			right edge of each header cell. */
		#mtl-inventory-table th {
			box-sizing: border-box;
			position: relative;
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
	</style>

	<details style="background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; max-width: 800px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);" <?php echo $keep_form_open ? ' open' : ''; ?>>
		<summary style="font-size: 1.1em; font-weight: 600; cursor: pointer; outline: none; color: var(--mtl-header-color);">
			Add a New Tool
		</summary>

		<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
			<form method="post" action="<?php echo esc_url( $base_url ); ?>">
				<?php wp_nonce_field( 'mtl_add_tool_action', 'mtl_add_tool_nonce' ); ?>

				<table class="form-table" style="margin-top: 0;">
					<?php mtl_render_tool_form_fields( $form_values, $categories, $tags ); ?>
				</table>
				<p class="submit">
					<input type="submit" name="mtl_add_tool" id="mtl_add_tool" class="button button-primary" value="Save to Database">
				</p>
			</form>
		</div>
	</details>

	<details style="background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; max-width: 800px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);" <?php echo $keep_bulk_panel_open ? ' open' : ''; ?>>
		<summary style="font-size: 1.1em; font-weight: 600; cursor: pointer; outline: none; color: var(--mtl-header-color);">
			Bulk Import from CSV
		</summary>

		<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
			<p>
				Add many tools at once by uploading a CSV file.
				<a href="<?php echo esc_url( $csv_template_url ); ?>">Download the CSV template</a>
				to get started, fill in one row per tool, then upload it below.
			</p>
			<ul style="font-size: 0.85em; color: #666; margin: 0 0 15px 20px;">
				<li><code>tool_name</code> and <code>barcode</code> are required for every row; each barcode must be unique.</li>
				<li>Do not include a <code>tool_id</code> column &mdash; it is assigned automatically when each tool is added.</li>
				<li>For <code>categories</code> and <code>tags</code>, separate multiple values with a semicolon (e.g. &ldquo;Woodworking;General Hand Tools&rdquo;). Names must match existing categories/tags exactly &mdash; add new ones on the Setup page first if needed.</li>
				<li><code>annual_depreciation_amount</code> accepts either a plain dollar amount (e.g. &ldquo;5.00&rdquo;) or a percentage of that row&rsquo;s <code>initial_cash_value</code> (e.g. &ldquo;5%&rdquo;) &mdash; any value containing a % sign is converted to a dollar amount before it&rsquo;s stored.</li>
				<li><code>private_notes</code> is staff-only and never shown publicly, same as typing it into the Add/Edit form &mdash; but remember that unlike the form, the CSV file itself isn&rsquo;t private once it leaves this page, so avoid emailing or sharing an import file that has sensitive notes filled in.</li>
				<li>Leave a cell blank to skip that field. If a row fails, the rest of the file still gets processed &mdash; failures are listed after upload.</li>
			</ul>
			<form method="post" action="<?php echo esc_url( $base_url ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'mtl_bulk_import_action', 'mtl_bulk_import_nonce' ); ?>
				<input type="file" name="csv_file" accept=".csv,text/csv" required>
				<p class="submit">
					<input type="submit" name="mtl_bulk_import" class="button button-primary" value="Upload & Import">
				</p>
			</form>
		</div>
	</details>

	<?php if ( $editing && $edit_values ) : ?>
		<details style="background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; max-width: 800px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);" open>
			<summary style="font-size: 1.1em; font-weight: 600; cursor: pointer; outline: none; color: var(--mtl-header-color);">
				Edit Tool: <?php echo esc_html( '' !== $edit_values['tool_name'] ? $edit_values['tool_name'] : ( '#' . $edit_tool_id ) ); ?>
			</summary>

			<div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
				<p style="margin-top: 0;"><strong>Tool ID:</strong> #<?php echo esc_html( $edit_tool_id ); ?> <span style="color:#666; font-size:0.85em;">(cannot be changed)</span></p>
				<form method="post" action="<?php echo esc_url( $base_url ); ?>" onsubmit="return confirm('Save changes to this tool?');">
					<?php wp_nonce_field( 'mtl_edit_tool_action', 'mtl_edit_tool_nonce' ); ?>
					<input type="hidden" name="tool_id" value="<?php echo esc_attr( $edit_tool_id ); ?>">

					<table class="form-table" style="margin-top: 0;">
						<?php mtl_render_tool_form_fields( $edit_values, $categories, $tags, 'edit_' ); ?>
					</table>
					<p class="submit">
						<input type="submit" name="mtl_update_tool" class="button button-primary" value="Update Tool">
						<a href="<?php echo esc_url( $base_url ); ?>" class="button">Cancel</a>
					</p>
				</form>
			</div>
		</details>
	<?php endif; ?>

	<?php
	// 5. FETCH ALL INVENTORY DATA FIELDS
	// Categories/tags are many-to-many, so GROUP_CONCAT + GROUP BY collapses each
	// tool's names into one comma-separated cell -- without it, the joins would
	// return one row per mapping. DISTINCT keeps the category and tag joins from
	// multiplying each other's values.
	// Every {$tbl_*} fragment interpolated through the end of this
	// data-gathering section is a table name only, built from $wpdb->prefix,
	// never request data.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$inventory = $wpdb->get_results(
		"
        SELECT
            t.tool_id,
            t.tool_name,
            t.barcode,
            t.brand,
            t.description,
            t.components,
            t.photo_url,
            t.initial_cash_value,
            t.annual_depreciation_amount,
            t.donated_by,
            t.date_acquired,
            t.retired_at,
            t.private_notes,
            GROUP_CONCAT(DISTINCT c.category_name ORDER BY c.category_name SEPARATOR ', ') AS categories,
            GROUP_CONCAT(DISTINCT tg.tag_name ORDER BY tg.tag_name SEPARATOR ', ') AS tags
        FROM {$tbl_inventory} t
        LEFT JOIN {$tbl_cat_map} tcm ON t.tool_id = tcm.tool_id
        LEFT JOIN {$tbl_categories} c ON tcm.category_id = c.category_id
        LEFT JOIN {$tbl_tag_map} ttm ON t.tool_id = ttm.tool_id
        LEFT JOIN {$tbl_tags} tg ON ttm.tag_id = tg.tag_id
        GROUP BY t.tool_id
        ORDER BY t.tool_id DESC
    "
	);

	// 5B. PER-TOOL LOAN / RESERVATION ACTIVITY
	// Two grouped queries folded into tool_id-keyed maps (rather than querying
	// per row) to back the boolean advanced filters below: whether a tool is
	// out right now, overdue, reserved, or has ever been borrowed at all.
	$tool_loan_stats = array();
	$tool_loan_rows  = $wpdb->get_results(
		"
        SELECT tool_id,
               COUNT(*) AS total_loans,
               SUM(CASE WHEN return_date IS NULL THEN 1 ELSE 0 END) AS active_loans,
               SUM(CASE WHEN return_date IS NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_now
        FROM {$tbl_loans}
        GROUP BY tool_id
    "
	);
	foreach ( $tool_loan_rows as $row ) {
		$tool_loan_stats[ (int) $row->tool_id ] = $row;
	}

	// The actual waiting queue per tool (who is in line, and in what order),
	// shown in the detail panel. Counting from this same list keeps the tile
	// and the list below it from ever disagreeing.
	$tool_res_by_tool = array();
	$tool_res_rows    = $wpdb->get_results(
		"
        SELECT r.tool_id, r.reservation_date,
               m.first_name, m.last_name,
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
        JOIN {$tbl_members} m ON m.member_id = r.member_id
        WHERE r.expiry_date IS NULL
        ORDER BY r.tool_id ASC, r.reservation_date ASC
    "
	);
	foreach ( $tool_res_rows as $row ) {
		$tool_res_by_tool[ (int) $row->tool_id ][] = $row;
	}

	// The active loan for each tool (at most one at a time, since a tool is a
	// single physical item), keyed by tool_id, so the detail panel can show who
	// has it and the "Mark Returned" form can post the right loan_id.
	$tool_active_loan = array();
	$active_loan_rows = $wpdb->get_results(
		"
        SELECT l.loan_id, l.tool_id, l.due_date, m.first_name, m.last_name
        FROM {$tbl_loans} l
        JOIN {$tbl_members} m ON m.member_id = l.member_id
        WHERE l.return_date IS NULL
    "
	);
	foreach ( $active_loan_rows as $row ) {
		$tool_active_loan[ (int) $row->tool_id ] = $row;
	}

	// Members for the Quick Loan/Quick Reserve search box, preloaded so the
	// name/email filter is instant with no AJAX. Each entry carries a display
	// label ("Name (email)"), a lowercased search string covering both the
	// name and the email so typing part of either matches, and whether the
	// member is fully verified (both scan URLs on file -- one alone doesn't
	// count, see mtl_verification_urls_complete()) so the admin can see at a
	// glance whether a walk-in has provided their documents yet.
	$tbl_verifications = $wpdb->prefix . 'member_verifications';
	$ql_members        = array();
	foreach (
		$wpdb->get_results(
			"
        SELECT m.member_id, m.first_name, m.last_name, m.email,
               (v.photo_id_scan_url IS NOT NULL AND v.address_proof_scan_url IS NOT NULL) AS verified
        FROM {$tbl_members} m
        LEFT JOIN {$tbl_verifications} v ON v.member_id = m.member_id
        ORDER BY m.last_name ASC, m.first_name ASC
    "
		) as $m
	) {
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ql_name      = trim( stripslashes( (string) $m->first_name ) . ' ' . stripslashes( (string) $m->last_name ) );
		$ql_members[] = array(
			'id'       => (int) $m->member_id,
			'verified' => (bool) $m->verified,
			'name'     => $ql_name,
			'email'    => (string) $m->email,
			'label'    => $ql_name . ' (' . $m->email . ')',
			'search'   => strtolower( $ql_name . ' ' . $m->email ),
		);
	}
	$ql_default_days = (int) get_option( 'mtl_default_loan_days', 21 );
	$ql_default_due  = gmdate( 'Y-m-d', strtotime( '+' . $ql_default_days . ' days' ) );

	// 6. RENDER THE FILTERABLE/SORTABLE INVENTORY TABLE
	?>
	<div style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 40px; margin-bottom: 10px;">
		<h3 style="margin: 0;">Current Inventory</h3>
		<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
			<input type="text" id="mtl-search" placeholder="Quick filter..." style="padding: 5px 10px; width: 220px; border: 1px solid #8c8f94; border-radius: 4px;">
			<?php
			// Sorting also covers fields that live in the detail panel
			// rather than in a column (value, depreciation, acquired, donor).
			?>
			<label class="mtl-sort-label">Sort:
				<select id="mtl-sort-field">
					<option value="toolId">Tool ID</option>
					<option value="name">Tool Name</option>
					<option value="barcode">Barcode</option>
					<option value="brand">Brand</option>
					<option value="categories">Categories</option>
					<option value="value">Initial Value</option>
					<option value="curvalue">Current Value</option>
					<option value="deprec">Depreciation</option>
					<option value="acquired">Acquired Date</option>
					<option value="donor">Donor</option>
				</select>
			</label>
			<button type="button" id="mtl-sort-dir" class="button" data-dir="desc" title="Toggle ascending / descending">&darr; Desc</button>
			<button type="button" id="mtl-toggle-advanced" class="button">Advanced Search</button>
			<button type="button" id="mtl-clear-filters" class="button">Clear Filters</button>
		</div>
	</div>

	<div id="mtl-advanced-search" style="display: none; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px 20px; margin-bottom: 15px;">
		<div class="mtl-adv-groups">

			<fieldset class="mtl-adv-group">
				<legend>Identity</legend>
				<div class="mtl-adv-fields">
					<div>
						<label for="adv-name">Tool Name</label>
						<input type="text" id="adv-name">
					</div>
					<div>
						<label for="adv-barcode">Barcode</label>
						<input type="text" id="adv-barcode">
					</div>
					<div>
						<label for="adv-brand">Brand</label>
						<input type="text" id="adv-brand">
					</div>
					<div>
						<label for="adv-donor">Donated By</label>
						<input type="text" id="adv-donor">
					</div>
					<div>
						<label for="adv-description">Description</label>
						<input type="text" id="adv-description">
					</div>
					<div>
						<label for="adv-components">Components</label>
						<input type="text" id="adv-components">
					</div>
				</div>
			</fieldset>

			<fieldset class="mtl-adv-group">
				<legend>Classification &amp; Value</legend>
				<div class="mtl-adv-fields">
					<div>
						<label for="adv-category">Category</label>
						<select id="adv-category">
							<option value="">Any</option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( strtolower( $cat->category_name ) ); ?>"><?php echo esc_html( $cat->category_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label for="adv-tag">Tag</label>
						<select id="adv-tag">
							<option value="">Any</option>
							<?php foreach ( $tags as $tag ) : ?>
								<option value="<?php echo esc_attr( strtolower( $tag->tag_name ) ); ?>"><?php echo esc_html( $tag->tag_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label for="adv-acquired-from">Acquired From</label>
						<input type="date" id="adv-acquired-from">
					</div>
					<div>
						<label for="adv-acquired-to">Acquired To</label>
						<input type="date" id="adv-acquired-to">
					</div>
					<div>
						<label for="adv-value-min">Min Value ($)</label>
						<input type="number" step="0.01" id="adv-value-min">
					</div>
					<div>
						<label for="adv-value-max">Max Value ($)</label>
						<input type="number" step="0.01" id="adv-value-max">
					</div>
				</div>
			</fieldset>

			<fieldset class="mtl-adv-group">
				<legend>Availability &amp; Records</legend>
				<div class="mtl-adv-fields">
					<div>
						<label for="adv-onloan">On Loan Now?</label>
						<select id="adv-onloan">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-overdue">Overdue?</label>
						<select id="adv-overdue">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-reserved">Has Reservations?</label>
						<select id="adv-reserved">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-everloaned">Ever Borrowed?</label>
						<select id="adv-everloaned">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-donated">Donated?</label>
						<select id="adv-donated">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-hasphoto">Has Photo?</label>
						<select id="adv-hasphoto">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-hasnotes">Has Private Notes?</label>
						<select id="adv-hasnotes">
							<option value="">Any</option>
							<option value="1">Yes</option>
							<option value="0">No</option>
						</select>
					</div>
					<div>
						<label for="adv-retired">Retired?</label>
						<select id="adv-retired">
							<option value="">Active only</option>
							<option value="include">Active + retired</option>
							<option value="only">Retired only</option>
						</select>
					</div>
				</div>
			</fieldset>

		</div>
	</div>

	<?php if ( $inventory ) : ?>
		<div class="mtl-pagination-bar mtl-pagination-top">
			<span class="mtl-results-info" id="mtl-results-info"></span>
			<label class="mtl-page-size-label">Rows per page:
				<select id="mtl-page-size">
					<option value="20">20</option>
					<option value="50">50</option>
					<option value="100">100</option>
				</select>
			</label>
		</div>
	<?php endif; ?>

	<div class="mtl-table-wrap">
		<table class="wp-list-table widefat fixed striped table-view-list" id="mtl-inventory-table">
			<thead>
				<tr>
					<th class="sortable" data-sort-key="toolId" style="cursor: pointer; width: 50px;" title="Click to sort">ID ↕</th>
					<th style="width: 60px;">Photo</th>
					<th class="sortable" data-sort-key="barcode" style="cursor: pointer; width: 11%;" title="Click to sort">Barcode ↕</th>
					<th class="sortable" data-sort-key="name" style="cursor: pointer; width: 19%;" title="Click to sort">Tool Name ↕</th>
					<th class="sortable" data-sort-key="brand" style="cursor: pointer; width: 11%;" title="Click to sort">Brand ↕</th>
					<th class="sortable" data-sort-key="categories" style="cursor: pointer; width: 19%;" title="Click to sort">Categories ↕</th>
					<th style="width: 19%;">Tags</th>
					<?php
					// Description, components, value, depreciation, acquired date
					// and donor all live in the expandable detail panel below.
					?>
					<th style="width: 140px;">Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $inventory ) : ?>
					<?php
					foreach ( $inventory as $item ) :
						$edit_url       = add_query_arg(
							array(
								'mtl_action' => 'edit',
								'tool_id'    => $item->tool_id,
							),
							$base_url
						);
						$delete_confirm = sprintf(
							'Permanently delete "%s" (Barcode: %s)? This cannot be undone.',
							stripslashes( $item->tool_name ),
							stripslashes( $item->barcode )
						);

						// Loan/reservation state backing the boolean filters.
						$tid       = (int) $item->tool_id;
						$tstats    = isset( $tool_loan_stats[ $tid ] ) ? $tool_loan_stats[ $tid ] : null;
						$t_total   = $tstats ? (int) $tstats->total_loans : 0;
						$t_active  = $tstats ? (int) $tstats->active_loans : 0;
						$t_overdue = $tstats ? (int) $tstats->overdue_now : 0;
						$t_queue   = isset( $tool_res_by_tool[ $tid ] ) ? $tool_res_by_tool[ $tid ] : array();
						$t_res     = count( $t_queue );

						// Straight-line depreciated value, floored at zero -- the
						// same calculation the dashboard's asset panels use.
						$age_years  = max( 0, ( time() - strtotime( $item->date_acquired ) ) / 31557600 );
						$t_curvalue = max( 0, (float) $item->initial_cash_value - ( (float) $item->annual_depreciation_amount * $age_years ) );
						?>
						<tr
							class="mtl-tool-row"
							data-tool-id="<?php echo esc_attr( $item->tool_id ); ?>"
							data-name="<?php echo esc_attr( strtolower( stripslashes( $item->tool_name ) ) ); ?>"
							data-barcode="<?php echo esc_attr( strtolower( stripslashes( $item->barcode ) ) ); ?>"
							data-brand="<?php echo esc_attr( strtolower( stripslashes( (string) $item->brand ) ) ); ?>"
							data-categories="<?php echo esc_attr( strtolower( (string) $item->categories ) ); ?>"
							data-tags="<?php echo esc_attr( strtolower( (string) $item->tags ) ); ?>"
							data-description="<?php echo esc_attr( strtolower( stripslashes( (string) $item->description ) ) ); ?>"
							data-components="<?php echo esc_attr( strtolower( stripslashes( (string) $item->components ) ) ); ?>"
							data-donor="<?php echo esc_attr( strtolower( stripslashes( (string) $item->donated_by ) ) ); ?>"
							data-value="<?php echo esc_attr( $item->initial_cash_value ); ?>"
							data-acquired="<?php echo esc_attr( $item->date_acquired ); ?>"
							<?php
							// Sort values for fields with no dedicated column.
							?>
							data-curvalue="<?php echo esc_attr( number_format( $t_curvalue, 2, '.', '' ) ); ?>"
							data-deprec="<?php echo esc_attr( $item->annual_depreciation_amount ); ?>"
							<?php
							// Boolean flags backing the availability filters ("1" / "0").
							?>
							data-onloan="<?php echo $t_active > 0 ? '1' : '0'; ?>"
							data-overdue="<?php echo $t_overdue > 0 ? '1' : '0'; ?>"
							data-reserved="<?php echo $t_res > 0 ? '1' : '0'; ?>"
							data-everloaned="<?php echo $t_total > 0 ? '1' : '0'; ?>"
							data-donated="<?php echo trim( (string) $item->donated_by ) !== '' ? '1' : '0'; ?>"
							data-hasphoto="<?php echo trim( (string) $item->photo_url ) !== '' ? '1' : '0'; ?>"
							data-hasnotes="<?php echo trim( (string) $item->private_notes ) !== '' ? '1' : '0'; ?>"
							data-retired="<?php echo ! empty( $item->retired_at ) ? '1' : '0'; ?>">
							<td><?php echo esc_html( $item->tool_id ); ?></td>
							<td>
								<?php if ( ! empty( $item->photo_url ) ) : ?>
									<a href="<?php echo esc_url( $item->photo_url ); ?>" target="_blank" rel="noopener noreferrer" style="text-decoration: none;">View</a>
								<?php endif; ?>
							</td>
							<td class="mtl-truncate" title="<?php echo esc_attr( stripslashes( $item->barcode ) ); ?>"><?php echo esc_html( stripslashes( $item->barcode ) ); ?></td>
							<!-- stripslashes() so older DB entries display cleanly -->
							<td>
								<strong class="mtl-truncate" style="display: inline-block; max-width: calc(100% - 60px); vertical-align: bottom;" title="<?php echo esc_attr( stripslashes( $item->tool_name ) ); ?>"><?php echo esc_html( stripslashes( $item->tool_name ) ); ?></strong>
								<?php if ( ! empty( $item->retired_at ) ) : ?>
									<span class="mtl-unverified-badge" style="margin-left: 6px;" title="Hidden from the public catalog and blocked from new loans/reservations">Retired</span>
								<?php endif; ?>
							</td>
							<td class="mtl-truncate" title="<?php echo esc_attr( stripslashes( $item->brand ) ); ?>"><?php echo esc_html( stripslashes( $item->brand ) ); ?></td>
							<td><?php echo mtl_render_pill_list( $item->categories ); ?></td>
							<td><?php echo mtl_render_pill_list( $item->tags ); ?></td>
							<td class="mtl-actions">
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Edit</a>
								<?php if ( ! empty( $item->retired_at ) ) : ?>
									<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display: inline;">
										<?php wp_nonce_field( 'mtl_reactivate_tool_action', 'mtl_reactivate_tool_nonce' ); ?>
										<input type="hidden" name="tool_id" value="<?php echo esc_attr( $item->tool_id ); ?>">
										<button type="submit" name="mtl_reactivate_tool" class="button button-small">Reactivate</button>
									</form>
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display: inline;" onsubmit="return confirm('Retire &quot;<?php echo esc_js( stripslashes( $item->tool_name ) ); ?>&quot;? It will be hidden from the public catalog and blocked from new loans/reservations, but its history is kept and this can be undone with Reactivate.');">
										<?php wp_nonce_field( 'mtl_retire_tool_action', 'mtl_retire_tool_nonce' ); ?>
										<input type="hidden" name="tool_id" value="<?php echo esc_attr( $item->tool_id ); ?>">
										<button type="submit" name="mtl_retire_tool" class="button button-small">Retire</button>
									</form>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( $delete_confirm ); ?>');">
									<?php wp_nonce_field( 'mtl_delete_tool_action', 'mtl_delete_tool_nonce' ); ?>
									<input type="hidden" name="tool_id" value="<?php echo esc_attr( $item->tool_id ); ?>">
									<button type="submit" name="mtl_delete_tool" class="button button-small mtl-btn-danger">Delete</button>
								</form>
							</td>
						</tr>
						<!--
							Hidden detail row: holds the FULL, untruncated text for
							this tool. Toggled open/closed by clicking anywhere on
							the row above (see the expand/collapse script below).
						-->
						<tr class="mtl-detail-row" id="mtl-detail-<?php echo esc_attr( $item->tool_id ); ?>" style="display: none;">
							<td colspan="8">
								<div class="mtl-detail-panel">

									<div class="mtl-detail-actions">
										<?php if ( ! empty( $item->retired_at ) ) : ?>
											<span class="mtl-detail-actions-hint">This tool is retired, so it can&rsquo;t be loaned or reserved. Reactivate it first if that&rsquo;s needed.</span>
										<?php else : ?>
											<?php if ( $t_active < 1 ) : ?>
												<button type="button" class="button button-primary mtl-ql-open"
													data-mode="loan"
													data-tool-id="<?php echo esc_attr( $item->tool_id ); ?>"
													data-tool-name="<?php echo esc_attr( stripslashes( $item->tool_name ) ); ?>">Quick Loan</button>
												<span class="mtl-detail-actions-hint">Loan this tool to a member who doesn&rsquo;t have a reservation.</span>
												<?php
											else :
												$active_loan = isset( $tool_active_loan[ $tid ] ) ? $tool_active_loan[ $tid ] : null;
												?>
												<?php if ( $active_loan ) : ?>
													<form method="post" action="<?php echo esc_url( $base_url ); ?>" style="display: inline;" onsubmit="return confirm('Mark this tool as returned today?');">
														<?php wp_nonce_field( 'mtl_mark_returned_action', 'mtl_mark_returned_nonce' ); ?>
														<input type="hidden" name="loan_id" value="<?php echo esc_attr( $active_loan->loan_id ); ?>">
														<button type="submit" name="mtl_mark_returned" class="button button-primary">Mark Returned</button>
													</form>
													<span class="mtl-detail-actions-hint mtl-detail-actions-out">
														On loan to <?php echo esc_html( trim( stripslashes( (string) $active_loan->first_name ) . ' ' . stripslashes( (string) $active_loan->last_name ) ) ); ?>,
														due <?php echo mtl_format_date( $active_loan->due_date ); ?>.
													</span>
												<?php else : ?>
													<span class="mtl-detail-actions-hint mtl-detail-actions-out">This tool is currently on loan &mdash; it must be returned before it can be loaned again.</span>
												<?php endif; ?>
											<?php endif; ?>
											<button type="button" class="button mtl-ql-open"
												data-mode="reserve"
												data-tool-id="<?php echo esc_attr( $item->tool_id ); ?>"
												data-tool-name="<?php echo esc_attr( stripslashes( $item->tool_name ) ); ?>">Quick Reserve</button>
											<?php if ( $t_active < 1 ) : ?>
												<span class="mtl-detail-actions-hint">Or reserve it for a member to pick up later.</span>
											<?php else : ?>
												<span class="mtl-detail-actions-hint">Reserve it for a member for when it&rsquo;s returned.</span>
											<?php endif; ?>
										<?php endif; ?>
									</div>

									<div class="mtl-detail-col">
										<strong>Description</strong>
										<p><?php echo $item->description ? nl2br( esc_html( stripslashes( $item->description ) ) ) : '<span style="color:#999;">&mdash;</span>'; ?></p>

										<strong>Components</strong>
										<p><?php echo $item->components ? nl2br( esc_html( stripslashes( $item->components ) ) ) : '<span style="color:#999;">&mdash;</span>'; ?></p>

										<?php if ( ! empty( $item->private_notes ) ) : ?>
											<strong>Private Notes</strong>
											<div class="mtl-sensitive-note">
												<?php echo nl2br( esc_html( stripslashes( $item->private_notes ) ) ); ?>
												<p style="margin: 6px 0 0 0; font-style: italic;">Staff-only &mdash; never shown on the public catalog or anywhere a member can see it.</p>
											</div>
										<?php endif; ?>

										<?php if ( ! empty( $item->photo_url ) ) : ?>
											<img src="<?php echo esc_url( $item->photo_url ); ?>" alt="" class="mtl-detail-photo">
										<?php endif; ?>
									</div>

									<div class="mtl-detail-col">
										<strong>Loan Activity</strong>
										<div class="mtl-tool-stats">
											<span class="mtl-tool-stat"><b><?php echo esc_html( $t_total ); ?></b>Total loans</span>
											<span class="mtl-tool-stat"><b><?php echo esc_html( $t_res ); ?></b>Reservations</span>
											<span class="mtl-tool-stat <?php echo $t_overdue > 0 ? 'mtl-tool-stat-warn' : ''; ?>"><b><?php echo $t_active > 0 ? 'Out' : 'In'; ?></b><?php echo $t_overdue > 0 ? 'Overdue' : 'Right now'; ?></span>
										</div>

										<strong>Upcoming Reservations</strong>
										<?php if ( $t_queue ) : ?>
											<ul class="mtl-tool-list">
												<?php foreach ( $t_queue as $res ) : ?>
													<li>
														#<?php echo esc_html( $res->queue_place ); ?>
														<?php echo esc_html( trim( stripslashes( $res->first_name ) . ' ' . stripslashes( $res->last_name ) ) ); ?>
														<span class="mtl-tool-list-meta">reserved <?php echo mtl_format_date( $res->reservation_date, 'm/d/Y H:i' ); ?></span>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p style="color: #999;">No one is waiting for this tool.</p>
										<?php endif; ?>

										<strong>Value &amp; Acquisition</strong>
										<div class="mtl-tool-fields">
											<div class="mtl-tool-field"><span>Initial value</span><span>$<?php echo esc_html( number_format( $item->initial_cash_value, 2 ) ); ?></span></div>
											<div class="mtl-tool-field" title="Initial value minus annual depreciation for each year since it was acquired"><span>Current value</span><span>$<?php echo esc_html( number_format( $t_curvalue, 2 ) ); ?></span></div>
											<div class="mtl-tool-field"><span>Annual depreciation</span><span>$<?php echo esc_html( number_format( $item->annual_depreciation_amount, 2 ) ); ?></span></div>
											<div class="mtl-tool-field"><span>Acquired</span><span><?php echo mtl_format_date( $item->date_acquired ); ?></span></div>
											<div class="mtl-tool-field"><span>Donated by</span><span><?php echo trim( (string) $item->donated_by ) !== '' ? esc_html( stripslashes( $item->donated_by ) ) : '<span style="color:#999;">Not donated</span>'; ?></span></div>
										</div>
									</div>

								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="8" style="text-align: center; padding: 20px;">
							No tools found in the database. Open the panel above to add one!
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<?php if ( $inventory ) : ?>
		<div class="mtl-pagination-bar mtl-pagination-bottom">
			<button type="button" class="button" id="mtl-prev-page">&larr; Previous</button>
			<span id="mtl-page-indicator"></span>
			<button type="button" class="button" id="mtl-next-page">Next &rarr;</button>
		</div>
	<?php endif; ?>

	<?php
	// ---- Shared Quick Loan modal (one per page; the tool is set by JS
	// when a tool's "Quick Loan" button is clicked). ----
	?>
	<div id="mtl-ql-overlay" class="mtl-ql-overlay" style="display: none;">
		<div class="mtl-ql-modal" role="dialog" aria-modal="true" aria-labelledby="mtl-ql-title">
			<button type="button" class="mtl-ql-close" id="mtl-ql-close" aria-label="Close">&times;</button>
			<h3 id="mtl-ql-title" style="margin-top: 0;">Quick Loan</h3>
			<p class="mtl-ql-tool-line">Tool: <strong id="mtl-ql-tool-name"></strong></p>

			<form method="post" action="<?php echo esc_url( $base_url ); ?>" id="mtl-ql-form">
				<?php wp_nonce_field( 'mtl_quick_loan_action', 'mtl_quick_loan_nonce' ); ?>
				<input type="hidden" name="tool_id" id="mtl-ql-tool-id" value="">
				<input type="hidden" name="member_id" id="mtl-ql-member-id" value="">

				<label class="mtl-ql-label" for="mtl-ql-member-search">Member</label>
				<div class="mtl-ql-autocomplete">
					<input type="text" id="mtl-ql-member-search" autocomplete="off" placeholder="Type a name or email...">
					<div class="mtl-ql-dropdown" id="mtl-ql-dropdown" style="display: none;"></div>
				</div>
				<p class="mtl-ql-hint" id="mtl-ql-member-hint">Start typing to find a member by name or email, then click to select.</p>
				<p class="mtl-ql-verified-pill" id="mtl-ql-verified-pill" style="display: none;"></p>

				<div id="mtl-ql-due-section">
					<label class="mtl-ql-label" for="mtl-ql-due">Due date</label>
					<div class="mtl-ql-due-quick">
						<?php foreach ( array( 7, 14, 21, 30 ) as $ql_days ) : ?>
							<button type="button" class="button button-small mtl-ql-due-btn<?php echo $ql_days === $ql_default_days ? ' mtl-ql-due-active' : ''; ?>" data-days="<?php echo (int) $ql_days; ?>"><?php echo (int) $ql_days; ?> days</button>
						<?php endforeach; ?>
					</div>
					<input type="date" name="due_date" id="mtl-ql-due" value="<?php echo esc_attr( $ql_default_due ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
				</div>

				<div class="mtl-ql-actions">
					<button type="submit" name="mtl_quick_loan" id="mtl-ql-submit-loan" class="button button-primary">Create Loan</button>
					<button type="submit" name="mtl_quick_reserve" id="mtl-ql-submit-reserve" class="button button-primary" style="display: none;" disabled>Create Reservation</button>
					<button type="button" class="button" id="mtl-ql-cancel">Cancel</button>
				</div>
			</form>
		</div>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			const searchInput = document.getElementById('mtl-search');
			const table = document.getElementById('mtl-inventory-table');
			const tbody = table.querySelector('tbody');

			// --- Pagination state + control references ---
			let pageSize = 20;
			let currentPage = 1;
			const pageSizeSelect = document.getElementById('mtl-page-size');
			const resultsInfo = document.getElementById('mtl-results-info');
			const pageIndicator = document.getElementById('mtl-page-indicator');
			const prevBtn = document.getElementById('mtl-prev-page');
			const nextBtn = document.getElementById('mtl-next-page');

			// --- Expandable rows (accordion: only one open at a time) ---
			// Tracks the tool_id of the currently expanded row, if any, so both
			// the click handler and the filters below can collapse it correctly.
			let expandedToolId = null;

			function collapseRow(toolId) {
				const row = tbody.querySelector('tr.mtl-tool-row[data-tool-id="' + toolId + '"]');
				const detail = document.getElementById('mtl-detail-' + toolId);
				if (row) row.classList.remove('mtl-row-expanded');
				if (detail) detail.style.display = 'none';
				if (expandedToolId === toolId) expandedToolId = null;
			}

			function expandRow(toolId) {
				const row = tbody.querySelector('tr.mtl-tool-row[data-tool-id="' + toolId + '"]');
				const detail = document.getElementById('mtl-detail-' + toolId);
				if (!row || !detail) return;
				if (expandedToolId !== null) collapseRow(expandedToolId);
				row.classList.add('mtl-row-expanded');
				detail.style.display = 'table-row';
				expandedToolId = toolId;
			}

			tbody.addEventListener('click', function(e) {
				// Ignore clicks on interactive controls (Edit link, Delete
				// button/form) -- only plain cell clicks toggle the row.
				if (e.target.closest('a, button, form, input, select, textarea')) {
					return;
				}

				const row = e.target.closest('tr.mtl-tool-row');
				if (!row) {
					return;
				}

				const toolId = row.dataset.toolId;
				if (expandedToolId === toolId) {
					collapseRow(toolId);
				} else {
					expandRow(toolId);
				}
			});

			// --- Advanced search panel toggle ---
			const advToggle = document.getElementById('mtl-toggle-advanced');
			const advPanel = document.getElementById('mtl-advanced-search');
			const clearBtn = document.getElementById('mtl-clear-filters');

			const advFields = {
				name: document.getElementById('adv-name'),
				barcode: document.getElementById('adv-barcode'),
				brand: document.getElementById('adv-brand'),
				category: document.getElementById('adv-category'),
				tag: document.getElementById('adv-tag'),
				description: document.getElementById('adv-description'),
				components: document.getElementById('adv-components'),
				donor: document.getElementById('adv-donor'),
				acquiredFrom: document.getElementById('adv-acquired-from'),
				acquiredTo: document.getElementById('adv-acquired-to'),
				valueMin: document.getElementById('adv-value-min'),
				valueMax: document.getElementById('adv-value-max'),
				onLoan: document.getElementById('adv-onloan'),
				overdue: document.getElementById('adv-overdue'),
				reserved: document.getElementById('adv-reserved'),
				everLoaned: document.getElementById('adv-everloaned'),
				donated: document.getElementById('adv-donated'),
				hasPhoto: document.getElementById('adv-hasphoto'),
				hasNotes: document.getElementById('adv-hasnotes'),
				retired: document.getElementById('adv-retired'),
			};

			advToggle.addEventListener('click', function() {
				const isOpen = advPanel.style.display !== 'none';
				advPanel.style.display = isOpen ? 'none' : 'block';
				advToggle.textContent = isOpen ? 'Advanced Search' : 'Hide Advanced Search';
			});

			// --- Combined quick filter + advanced search ---
			// A row must pass the quick filter (substring match anywhere in the
			// row) AND every non-empty advanced field to stay visible.
			function applyFilters() {
				const quick = searchInput.value.trim().toLowerCase();

				const f = {
					name: advFields.name.value.trim().toLowerCase(),
					barcode: advFields.barcode.value.trim().toLowerCase(),
					brand: advFields.brand.value.trim().toLowerCase(),
					category: advFields.category.value.trim().toLowerCase(),
					tag: advFields.tag.value.trim().toLowerCase(),
					description: advFields.description.value.trim().toLowerCase(),
					components: advFields.components.value.trim().toLowerCase(),
					donor: advFields.donor.value.trim().toLowerCase(),
					acquiredFrom: advFields.acquiredFrom.value,
					acquiredTo: advFields.acquiredTo.value,
					valueMin: advFields.valueMin.value !== '' ? parseFloat(advFields.valueMin.value) : null,
					valueMax: advFields.valueMax.value !== '' ? parseFloat(advFields.valueMax.value) : null,
					onLoan: advFields.onLoan.value,
					overdue: advFields.overdue.value,
					reserved: advFields.reserved.value,
					everLoaned: advFields.everLoaned.value,
					donated: advFields.donated.value,
					hasPhoto: advFields.hasPhoto.value,
					hasNotes: advFields.hasNotes.value,
					retired: advFields.retired.value,
				};

				// Only real tool rows are filtered -- detail rows follow their
				// parent row's visibility instead of being matched directly, and
				// the "No tools found" placeholder has no dataset to match on.
				tbody.querySelectorAll('tr.mtl-tool-row').forEach(function(row) {
					const d = row.dataset;
					let visible = true;

					// Quick filter searches the row's DATA values, not its rendered
					// text: description, components, donor, value and acquired date
					// live in the expandable detail panel rather than in cells,
					// and the data attributes hold the full untruncated strings.
					if (quick) {
						const haystack = [
							d.name, d.barcode, d.brand, d.categories, d.tags,
							d.description, d.components, d.donor, d.value, d.acquired
						].join(' ');
						if (!haystack.includes(quick)) visible = false;
					}
					if (visible && f.name && !d.name.includes(f.name)) visible = false;
					if (visible && f.barcode && !d.barcode.includes(f.barcode)) visible = false;
					if (visible && f.brand && !d.brand.includes(f.brand)) visible = false;
					if (visible && f.category && !d.categories.includes(f.category)) visible = false;
					if (visible && f.tag && !d.tags.includes(f.tag)) visible = false;
					if (visible && f.description && !d.description.includes(f.description)) visible = false;
					if (visible && f.components && !d.components.includes(f.components)) visible = false;
					if (visible && f.donor && !d.donor.includes(f.donor)) visible = false;
					if (visible && f.acquiredFrom && d.acquired < f.acquiredFrom) visible = false;
					if (visible && f.acquiredTo && d.acquired > f.acquiredTo) visible = false;

					if (visible && (f.valueMin !== null || f.valueMax !== null)) {
						const value = parseFloat(d.value);
						if (f.valueMin !== null && value < f.valueMin) visible = false;
						if (f.valueMax !== null && value > f.valueMax) visible = false;
					}

					// Availability booleans. The selects use "1"/"0" matching the
					// row flags, so "Any" (empty) simply skips the check and the
					// admin can filter for either side of each question.
					if (visible && f.onLoan && d.onloan !== f.onLoan) visible = false;
					if (visible && f.overdue && d.overdue !== f.overdue) visible = false;
					if (visible && f.reserved && d.reserved !== f.reserved) visible = false;
					if (visible && f.everLoaned && d.everloaned !== f.everLoaned) visible = false;
					if (visible && f.donated && d.donated !== f.donated) visible = false;
					if (visible && f.hasPhoto && d.hasphoto !== f.hasPhoto) visible = false;
					if (visible && f.hasNotes && d.hasnotes !== f.hasNotes) visible = false;

					// Retired tools are hidden unless explicitly included --
					// the one filter that isn't a plain "Any" 3-state, since
					// the default view should always exclude them.
					if (visible && f.retired === '' && d.retired === '1') visible = false;
					if (visible && f.retired === 'only' && d.retired !== '1') visible = false;

					// Mark the row's match state; renderPage() below turns that
					// into actual visibility for the current page window.
					row.dataset.matched = visible ? '1' : '0';
				});

				// A filter change always returns to the first page.
				currentPage = 1;
				renderPage();
			}

			// --- Pagination: show only the matched rows for the current page ---
			function renderPage() {
				const allRows = Array.from(tbody.querySelectorAll('tr.mtl-tool-row'));
				const matched = allRows.filter(function(r) {
					return r.dataset.matched !== '0';
				});
				const total = matched.length;
				const totalPages = Math.max(1, Math.ceil(total / pageSize));
				if (currentPage > totalPages) currentPage = totalPages;
				if (currentPage < 1) currentPage = 1;
				const start = (currentPage - 1) * pageSize;
				const end = start + pageSize;

				// Collapse any open detail row, then hide every row + detail.
				if (expandedToolId !== null) collapseRow(expandedToolId);
				allRows.forEach(function(row) {
					row.style.display = 'none';
					const detail = document.getElementById('mtl-detail-' + row.dataset.toolId);
					if (detail) detail.style.display = 'none';
				});

				// Reveal just this page's slice of the matched rows.
				matched.forEach(function(row, i) {
					if (i >= start && i < end) row.style.display = '';
				});

				const shownStart = total === 0 ? 0 : start + 1;
				const shownEnd = Math.min(end, total);
				if (resultsInfo) {
					resultsInfo.innerHTML = total === 0 ?
						'No matching tools' :
						'Showing <strong>' + shownStart + '–' + shownEnd + '</strong> of <strong>' + total + '</strong> tools';
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
					if (currentPage > 1) {
						currentPage--;
						renderPage();
					}
				});
			}
			if (nextBtn) {
				nextBtn.addEventListener('click', function() {
					currentPage++;
					renderPage();
				});
			}

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
				applyFilters();
			});

			// --- Sorting ---
			// Sorts on each row's data-* values rather than its cell text, so
			// fields that live in the detail panel (value, current value,
			// depreciation, acquired date, donor) can still be sorted even
			// though they have no column. The header clicks and the "Sort"
			// dropdown drive the same state, so the two never disagree.
			const headers = document.querySelectorAll('#mtl-inventory-table th.sortable');
			const sortFieldSelect = document.getElementById('mtl-sort-field');
			const sortDirBtn = document.getElementById('mtl-sort-dir');
			const NUMERIC_SORT_FIELDS = ['toolId', 'value', 'curvalue', 'deprec'];

			// Defaults mirror the order the SQL already returns (tool id, newest
			// first), so the controls describe the table accurately on load.
			let sortField = 'toolId';
			let sortDir = 'desc';

			function syncSortUI() {
				if (sortDirBtn) {
					sortDirBtn.dataset.dir = sortDir;
					sortDirBtn.innerHTML = sortDir === 'asc' ? '&uarr; Asc' : '&darr; Desc';
				}
				if (sortFieldSelect) sortFieldSelect.value = sortField;
				headers.forEach(function(h) {
					h.classList.remove('asc', 'desc');
					if (h.dataset.sortKey === sortField) {
						h.classList.add(sortDir);
					}
				});
			}

			function applySort() {
				const rows = Array.from(tbody.querySelectorAll('tr.mtl-tool-row'));
				const dir = sortDir === 'desc' ? -1 : 1;
				const field = sortField;

				rows.sort(function(a, b) {
					const av = a.dataset[field] || '';
					const bv = b.dataset[field] || '';

					// Rows with no value for this field always sink to the bottom.
					if (av === '' && bv === '') return 0;
					if (av === '') return 1;
					if (bv === '') return -1;

					if (NUMERIC_SORT_FIELDS.indexOf(field) !== -1) {
						return (parseFloat(av) - parseFloat(bv)) * dir;
					}
					// Dates are ISO (YYYY-MM-DD), so text order is date order.
					return av.localeCompare(bv) * dir;
				});

				// Each detail row is re-attached directly after its own tool row
				// so expand/collapse still lines up after re-ordering.
				rows.forEach(function(row) {
					tbody.appendChild(row);
					const detail = document.getElementById('mtl-detail-' + row.dataset.toolId);
					if (detail) tbody.appendChild(detail);
				});

				syncSortUI();
				currentPage = 1;
				renderPage();
			}

			headers.forEach(function(header) {
				header.addEventListener('click', function() {
					const key = header.dataset.sortKey;
					if (!key) return;
					// Clicking the active column flips direction; a new column
					// starts ascending.
					if (sortField === key) {
						sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
					} else {
						sortField = key;
						sortDir = 'asc';
					}
					applySort();
				});
			});

			if (sortFieldSelect) {
				sortFieldSelect.addEventListener('change', function() {
					sortField = this.value;
					applySort();
				});
			}

			if (sortDirBtn) {
				sortDirBtn.addEventListener('click', function() {
					sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
					applySort();
				});
			}

			// --- Resizable columns ---
			// A thin grip on each header cell's right edge drags its width.
			// The table uses fixed layout (the "fixed" class), so a th's width
			// dictates its whole column.
			table.querySelectorAll('thead th').forEach(function(th) {
				const grip = document.createElement('span');
				grip.className = 'mtl-col-resizer';
				th.appendChild(grip);

				// A click on the grip must not also trigger the column's sort.
				grip.addEventListener('click', function(e) {
					e.stopPropagation();
				});

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

			// The rows already arrive in tool-id-descending order, so only the
			// sort indicators need syncing -- no need to re-sort on load.
			syncSortUI();

			// Establish the initial paginated view (all rows matched, page 1).
			applyFilters();
		});
	</script>

	<script>
		// ---- Quick Loan modal ----
		document.addEventListener('DOMContentLoaded', function() {
			<?php
			// JSON_HEX_TAG/AMP/APOS/QUOT so a member name/email containing
			// "</script>" or quotes can't break out of this inline script.
			?>
			const members = <?php echo wp_json_encode( $ql_members, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
			const qlDefaultDays = <?php echo (int) $ql_default_days; ?>;

			const overlay = document.getElementById('mtl-ql-overlay');
			if (!overlay) return;
			const titleEl = document.getElementById('mtl-ql-title');
			const toolNameEl = document.getElementById('mtl-ql-tool-name');
			const toolIdInput = document.getElementById('mtl-ql-tool-id');
			const memberIdInput = document.getElementById('mtl-ql-member-id');
			const searchInput = document.getElementById('mtl-ql-member-search');
			const dropdown = document.getElementById('mtl-ql-dropdown');
			const memberHint = document.getElementById('mtl-ql-member-hint');
			const verifiedPill = document.getElementById('mtl-ql-verified-pill');
			const dueSection = document.getElementById('mtl-ql-due-section');
			const dueInput = document.getElementById('mtl-ql-due');
			const dueButtons = overlay.querySelectorAll('.mtl-ql-due-btn');
			const form = document.getElementById('mtl-ql-form');
			const submitLoan = document.getElementById('mtl-ql-submit-loan');
			const submitReserve = document.getElementById('mtl-ql-submit-reserve');

			function dateFromToday(days) {
				const d = new Date();
				d.setDate(d.getDate() + days);
				const mm = String(d.getMonth() + 1).padStart(2, '0');
				const dd = String(d.getDate()).padStart(2, '0');
				return d.getFullYear() + '-' + mm + '-' + dd;
			}

			function setActiveDueButton(days) {
				dueButtons.forEach(function(b) {
					b.classList.toggle('mtl-ql-due-active', parseInt(b.dataset.days, 10) === days);
				});
			}

			function hideDropdown() {
				dropdown.style.display = 'none';
				dropdown.innerHTML = '';
			}

			function resetMemberHint() {
				memberHint.textContent = 'Start typing to find a member by name or email, then click to select.';
				memberHint.classList.remove('mtl-ql-hint-error');
			}

			function resetVerifiedPill() {
				verifiedPill.style.display = 'none';
				verifiedPill.innerHTML = '';
			}

			function showVerifiedPill(verified) {
				verifiedPill.innerHTML = verified ?
					'<span class="mtl-verified-badge">Verified</span>' :
					'<span class="mtl-unverified-badge">Not Verified</span>';
				verifiedPill.style.display = 'block';
			}

			function openModal(toolId, toolName, mode) {
				mode = mode === 'reserve' ? 'reserve' : 'loan';
				toolIdInput.value = toolId;
				toolNameEl.textContent = toolName;
				memberIdInput.value = '';
				searchInput.value = '';
				hideDropdown();
				resetMemberHint();
				resetVerifiedPill();
				dueInput.value = dateFromToday(qlDefaultDays);
				setActiveDueButton(qlDefaultDays);

				const isReserve = mode === 'reserve';
				titleEl.textContent = isReserve ? 'Quick Reserve' : 'Quick Loan';
				dueSection.style.display = isReserve ? 'none' : '';
				dueInput.required = !isReserve;
				submitLoan.style.display = isReserve ? 'none' : '';
				submitLoan.disabled = isReserve;
				submitReserve.style.display = isReserve ? '' : 'none';
				submitReserve.disabled = !isReserve;

				overlay.style.display = 'flex';
				searchInput.focus();
			}

			function closeModal() {
				overlay.style.display = 'none';
				hideDropdown();
			}

			// Open from any tool's "Quick Loan" or "Quick Reserve" button.
			document.querySelectorAll('.mtl-ql-open').forEach(function(btn) {
				btn.addEventListener('click', function() {
					openModal(btn.dataset.toolId, btn.dataset.toolName, btn.dataset.mode);
				});
			});

			document.getElementById('mtl-ql-close').addEventListener('click', closeModal);
			document.getElementById('mtl-ql-cancel').addEventListener('click', closeModal);
			// Click on the dark backdrop (but not the modal itself) closes it.
			overlay.addEventListener('mousedown', function(e) {
				if (e.target === overlay) closeModal();
			});
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' && overlay.style.display !== 'none') closeModal();
			});

			// --- Member autocomplete ---
			function renderDropdown(matches) {
				if (matches.length === 0) {
					dropdown.innerHTML = '<div class="mtl-ql-empty">No matching members</div>';
					dropdown.style.display = 'block';
					return;
				}
				dropdown.innerHTML = '';
				matches.forEach(function(m) {
					const opt = document.createElement('div');
					opt.className = 'mtl-ql-option';
					const name = document.createElement('span');
					name.textContent = m.name + ' ';
					const email = document.createElement('span');
					email.className = 'mtl-ql-option-email';
					email.textContent = '(' + m.email + ')';
					opt.appendChild(name);
					opt.appendChild(email);
					// mousedown (not click) so it fires before the input's blur.
					opt.addEventListener('mousedown', function(e) {
						e.preventDefault();
						memberIdInput.value = m.id;
						searchInput.value = m.label;
						hideDropdown();
						resetMemberHint();
						showVerifiedPill(m.verified);
					});
					dropdown.appendChild(opt);
				});
				dropdown.style.display = 'block';
			}

			searchInput.addEventListener('input', function() {
				// Editing the text invalidates any previously picked member.
				memberIdInput.value = '';
				resetVerifiedPill();
				const q = this.value.trim().toLowerCase();
				if (!q) {
					hideDropdown();
					return;
				}
				const matches = members.filter(function(m) {
					return m.search.indexOf(q) !== -1;
				}).slice(0, 8);
				renderDropdown(matches);
			});

			searchInput.addEventListener('focus', function() {
				if (this.value.trim() && !memberIdInput.value) {
					this.dispatchEvent(new Event('input'));
				}
			});

			// Hide the dropdown when focus/click leaves the autocomplete.
			document.addEventListener('click', function(e) {
				if (!e.target.closest('.mtl-ql-autocomplete')) hideDropdown();
			});

			// --- Quick due-date buttons ---
			dueButtons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					const days = parseInt(btn.dataset.days, 10);
					dueInput.value = dateFromToday(days);
					setActiveDueButton(days);
				});
			});

			// Require a real member selection before submitting.
			form.addEventListener('submit', function(e) {
				if (!memberIdInput.value) {
					e.preventDefault();
					memberHint.textContent = 'Please pick a member from the list first.';
					memberHint.classList.add('mtl-ql-hint-error');
					searchInput.focus();
				}
			});
		});
	</script>
	<?php
	echo '</div>';
}
