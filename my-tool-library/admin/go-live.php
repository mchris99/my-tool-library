<?php
/**
 * Go Live: the Setup section that opens the library to the public, its save
 * handler, the launch checklist, and the "not live yet" notice on the staff
 * pages.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ==========================================================================
// GO LIVE
//
// A new install starts closed to the public (see mtl_plugin_activate()).
// Until an administrator turns on Go Live, every public page shows a Coming
// soon page instead (mtl_handle_front_pages()), member accounts can't sign in
// (mtl_refuse_member_login_before_launch()), and setup emails and agreement
// requests are held, since their links lead to pages nobody can reach yet.
// Staff see and use everything as normal.
//
// Before launch, Setup can also let customers browse the catalog, with every
// account feature taken out, and set the message on the Coming soon page.
//
// The options:
// mtl_library_live       '0' until Go Live. Missing means live, so a library
// already running when this shipped is unaffected.
// mtl_library_live_since When Go Live was last turned on, as a timestamp.
// mtl_catalog_preview    '1' to show the catalog before launch.
// mtl_coming_soon_message The library's own Coming soon wording, or ''.
// ==========================================================================

add_action( 'admin_init', 'mtl_maybe_save_library_status' );

/**
 * Saves the Go Live section's forms, then redirects back to it.
 *
 * On admin_init rather than inside mtl_render_setup_page() like the other
 * Setup forms, because the "not live yet" notice prints before the page body
 * does: saving during the render would show the old state for one page load.
 */
function mtl_maybe_save_library_status() {
	if ( ! isset( $_POST['mtl_library_status_action'] ) ) {
		return;
	}

	// admin_init also runs for signed-out admin-post.php and admin-ajax.php
	// requests, so the capability is checked first, then the nonce.
	if ( ! mtl_can_manage_settings() ) {
		return;
	}

	$msg = 'security';
	if ( isset( $_POST['mtl_library_status_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mtl_library_status_nonce'] ) ), 'mtl_library_status_action' ) ) {
		$action = sanitize_key( wp_unslash( $_POST['mtl_library_status_action'] ) );
		$msg    = '';

		if ( 'live' === $action ) {
			$want_live = isset( $_POST['mtl_library_live'] );
			if ( $want_live && ! mtl_library_is_live() ) {
				update_option( 'mtl_library_live', '1' );
				update_option( 'mtl_library_live_since', time() );
				$msg = 'went_live';
			} elseif ( ! $want_live && mtl_library_is_live() ) {
				update_option( 'mtl_library_live', '0' );
				$msg = 'closed';
			}
		} elseif ( 'preview' === $action ) {
			$preview = isset( $_POST['mtl_catalog_preview'] );
			update_option( 'mtl_catalog_preview', $preview ? '1' : '' );
			$msg = $preview ? 'preview_on' : 'preview_off';
		} elseif ( 'message' === $action ) {
			update_option( 'mtl_coming_soon_message', isset( $_POST['mtl_coming_soon_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mtl_coming_soon_message'] ) ) : '' );
			$msg = 'message_saved';
		}
	}

	// A fixed URL, never the referer, and the result as a key looked up in
	// mtl_library_status_notice(), never as text.
	$args = array(
		'page'     => 'mtl-setup',
		'mtl_open' => 'golive',
	);
	if ( '' !== $msg ) {
		$args['mtl_status_msg'] = $msg;
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#mtl-section-golive' );
	exit;
}

/**
 * The notice shown in the Go Live section after one of its forms is saved.
 *
 * @param string $key Result key from mtl_maybe_save_library_status().
 * @return string Notice HTML, or '' for an unknown key.
 */
function mtl_library_status_notice( $key ) {
	$membership = esc_url( admin_url( 'admin.php?page=mtl-membership' ) );

	switch ( $key ) {
		case 'went_live':
			$text = '<strong>You&rsquo;re live.</strong> Customers can now sign up, sign in and reserve tools.';

			// Counted now rather than carried in the URL. Members added by staff
			// can't sign in until they choose a password, and their setup emails
			// were held until now.
			// The setup email batch only reaches members who already have a
			// sign-in, so anyone without one needs Create logins first.
			if ( mtl_members_table_exists() ) {
				$no_login = mtl_count_members_without_login();
				$waiting  = $no_login + mtl_count_members_setup_pending();
				if ( $waiting > 0 ) {
					$text .= ' ' . intval( $waiting ) . ' member(s) haven&rsquo;t chosen a password yet. Under <a href="' . $membership . '">Membership &rarr; Member Logins</a>, ';
					$text .= $no_login > 0 ? 'press Create logins, then Send setup emails' : 'press Send setup emails';
					$text .= mtl_agreements_online() ? ', then ask members to agree under Member Agreements.' : '.';
				} elseif ( mtl_agreements_online() ) {
					$text .= ' Ask members to agree from <a href="' . $membership . '">Membership &rarr; Member Agreements</a>.';
				}
			}
			return '<div class="notice notice-success inline"><p>' . $text . '</p></div>';

		case 'closed':
			return '<div class="notice notice-warning inline"><p><strong>The library is closed to the public.</strong> Customers see the Coming soon page, and members can&rsquo;t sign in, until you go live again.</p></div>';

		case 'preview_on':
			return '<div class="notice notice-success inline"><p>Customers can browse the catalog now. Sign-up, sign-in and reservations stay closed until you go live.</p></div>';

		case 'preview_off':
			return '<div class="notice notice-success inline"><p>The catalog is hidden again. Customers see the Coming soon page.</p></div>';

		case 'message_saved':
			return '<div class="notice notice-success inline"><p>Coming soon message saved.</p></div>';

		case 'security':
			return '<div class="notice notice-error inline"><p><strong>Security Error:</strong> Form submission could not be verified.</p></div>';
	}

	return '';
}

/**
 * The notice a Membership action gets when it would email members before
 * launch. Its buttons are disabled then, so this is for a page opened while
 * the library was still live.
 *
 * @param string $what 'setup emails' or 'agreement requests'.
 * @return string Notice HTML.
 */
function mtl_held_until_live_notice( $what ) {
	return '<div class="notice notice-warning is-dismissible"><p><strong>Not sent.</strong> '
		. esc_html( ucfirst( $what ) ) . ' are held until the library goes live, because their links lead to pages customers can&rsquo;t open yet.</p></div>';
}

/**
 * The launch checklist: what's worth sorting out before going live.
 *
 * Advice only. Nothing here stops Go Live, since a library may have good
 * reasons for each of these, and outgoing email can't be checked at all.
 *
 * @param bool|null $tables_ready Whether Database Setup has been run, when the
 *                                caller already knows; null to check.
 * @return array[] One item each, in order: 'status' ('ok', 'warn' or 'info'),
 *                 'label' and 'hint' (plain text), and 'url' (where it is
 *                 fixed, or '').
 */
function mtl_launch_checklist( $tables_ready = null ) {
	global $wpdb;

	$section = function ( $key ) {
		return add_query_arg( 'mtl_open', $key, admin_url( 'admin.php?page=mtl-setup' ) ) . '#mtl-section-' . $key;
	};

	$items = array();

	$tables_ready = null === $tables_ready ? mtl_members_table_exists() : (bool) $tables_ready;
	$items[]      = array(
		'status' => $tables_ready ? 'ok' : 'warn',
		'label'  => $tables_ready ? 'Database tables are set up' : 'Database tables aren&rsquo;t set up yet',
		'hint'   => $tables_ready ? '' : 'Run Database Setup. Nothing else works until it&rsquo;s done.',
		'url'    => $tables_ready ? '' : $section( 'database' ),
	);

	// A named zone such as America/Chicago. UTC, Etc/ zones and the bare
	// offsets (which WordPress stores as an empty timezone_string) don't
	// follow Daylight Saving.
	$timezone = (string) get_option( 'timezone_string', '' );
	$tz_city  = '' !== $timezone && 'UTC' !== $timezone && 0 !== strpos( $timezone, 'Etc/' );
	$items[]  = array(
		'status' => $tz_city ? 'ok' : 'warn',
		'label'  => $tz_city ? 'Timezone is set to ' . $timezone : 'Timezone isn&rsquo;t set to a city',
		'hint'   => $tz_city ? '' : 'Every loan and reservation is timestamped with it, and past times can&rsquo;t be corrected later.',
		'url'    => $tz_city ? '' : admin_url( 'options-general.php' ),
	);

	$has_name = '' !== trim( (string) get_option( 'mtl_org_name', '' ) );
	$items[]  = array(
		'status' => $has_name ? 'ok' : 'warn',
		'label'  => $has_name ? 'Organization name is set' : 'No organization name',
		'hint'   => $has_name ? '' : 'Customers see &ldquo;My Tool Library&rdquo; at the top of every page instead.',
		'url'    => $has_name ? '' : $section( 'org' ),
	);

	$has_contact = '' !== mtl_contact_email();
	$items[]     = array(
		'status' => $has_contact ? 'ok' : 'warn',
		'label'  => $has_contact ? 'Public contact email is set' : 'No public contact email',
		'hint'   => $has_contact ? '' : 'Customers have no address to write to with questions.',
		'url'    => $has_contact ? '' : $section( 'org' ),
	);

	$tools = 0;
	if ( $tables_ready ) {
		$inventory = $wpdb->prefix . 'tool_inventory';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, built from $wpdb->prefix, not user input.
		$tools = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inventory} WHERE retired_at IS NULL" );
	}
	$items[] = array(
		'status' => $tools > 0 ? 'ok' : 'warn',
		'label'  => $tools > 0 ? sprintf( '%d tool(s) in the catalog', $tools ) : 'No tools in the catalog yet',
		'hint'   => '',
		'url'    => $tools > 0 ? '' : admin_url( 'admin.php?page=mtl-inventory' ),
	);

	// Off counts as done: plenty of libraries don't use waivers. Switched on
	// with nothing to agree to is the one state that silently does nothing.
	$agreements_mode = mtl_agreements_mode();
	if ( 'off' === $agreements_mode ) {
		$items[] = array(
			'status' => 'ok',
			'label'  => 'Member agreements are off',
			'hint'   => 'Fine if members don&rsquo;t need to agree to a waiver. If they do, set it up before launch, so nobody joins without agreeing.',
			'url'    => $section( 'agreements' ),
		);
	} elseif ( ! $tables_ready ) {
		// mtl_agreements_tracking() counts rows in a table that doesn't exist yet.
		$items[] = array(
			'status' => 'warn',
			'label'  => 'Member agreements are on, with nothing to agree to yet',
			'hint'   => 'Add agreements once Database Setup has been run, or switch them off.',
			'url'    => $section( 'agreements' ),
		);
	} else {
		$tracking = mtl_agreements_tracking();
		$items[]  = array(
			'status' => $tracking ? 'ok' : 'warn',
			'label'  => $tracking ? 'Member agreements are set up' : 'Member agreements are on, but there&rsquo;s nothing to agree to',
			'hint'   => $tracking ? '' : 'Add an agreement, or switch them off.',
			'url'    => $tracking ? '' : $section( 'agreements' ),
		);
	}

	$backups = mtl_backup_settings();
	$items[] = array(
		'status' => $backups['enabled'] ? 'ok' : 'warn',
		'label'  => $backups['enabled'] ? 'Automatic backups are on' : 'Automatic backups are off',
		'hint'   => $backups['enabled'] ? '' : 'Turn them on, and save the backup key somewhere other than this site.',
		'url'    => $backups['enabled'] ? '' : $section( 'backups' ),
	);

	$items[] = array(
		'status' => 'info',
		'label'  => 'Check that outgoing email works',
		'hint'   => 'This can&rsquo;t be checked from here. Setup emails go out on launch day, so send yourself a test from your SMTP plugin first.',
		'url'    => '',
	);

	return $items;
}

/**
 * Renders the Go Live section at the top of the Setup page.
 *
 * @param string    $requested_section The section the request came from; see
 *                                     mtl_setup_requested_section().
 * @param bool|null $tables_ready      Whether Database Setup has been run, as
 *                                     the Setup page already worked out.
 */
function mtl_render_go_live_section( $requested_section, $tables_ready = null ) {
	if ( ! mtl_can_manage_settings() ) {
		return;
	}

	$live      = mtl_library_is_live();
	$preview   = '1' === (string) get_option( 'mtl_catalog_preview', '' );
	$since     = (int) get_option( 'mtl_library_live_since', 0 );
	$setup_url = admin_url( 'admin.php?page=mtl-setup' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a result key after the redirect, only ever looked up in a fixed list.
	$notice = isset( $_GET['mtl_status_msg'] ) ? mtl_library_status_notice( sanitize_key( wp_unslash( $_GET['mtl_status_msg'] ) ) ) : '';

	// The Go Live switch asks before it saves, in the shared dialog. Opening
	// is ordinary; closing again is not, since members lose their accounts
	// until it is turned back on.
	$asks = array(
		'open'  => array(
			'title'   => 'Go Live',
			'message' => 'Open the library to the public?',
			'details' => array(
				'Customers will be able to sign up, sign in and reserve tools.',
				'Setup emails and agreement requests can be sent from Membership once you&rsquo;re live.',
			),
			'confirm' => 'Go Live',
		),
		'close' => array(
			'title'   => 'Close the Library',
			'message' => 'Close the library to the public?',
			'details' => array(
				'Customers will see the Coming soon page again.',
				'Members won&rsquo;t be able to sign in, or see their loans and due dates, until you go live again.',
			),
			'confirm' => 'Close the Library',
			'danger'  => true,
		),
	);
	// The dialog sets its text with textContent, so entities would show as
	// typed. Decoded here; JSON_HEX_TAG keeps the result safe inside <script>.
	array_walk_recursive(
		$asks,
		function ( &$value ) {
			if ( is_string( $value ) ) {
				$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
			}
		}
	);
	?>
	<style>
		.mtl-golive-help {
			margin: 8px 0 0 0;
			color: #50575e;
			line-height: 1.5;
		}

		/* A plain row of text, not the heading the shared ".mtl-admin-wrapper
			summary" rule makes of every summary. */
		.mtl-admin-wrapper .mtl-golive-checklist > summary {
			font-family: inherit;
			font-size: inherit;
			font-weight: 600;
			line-height: 1.5;
			text-transform: none;
			cursor: pointer;
		}

		.mtl-golive-checklist ul {
			margin: 10px 0 0 0;
			list-style: none;
		}

		.mtl-golive-checklist li {
			display: grid;
			grid-template-columns: 22px 1fr;
			margin: 0 0 8px 0;
			line-height: 1.5;
		}

		.mtl-golive-mark {
			font-weight: 700;
		}

		.mtl-golive-ok .mtl-golive-mark {
			color: #1e7e34;
		}

		.mtl-golive-warn .mtl-golive-mark {
			color: #b26200;
		}

		.mtl-golive-info .mtl-golive-mark {
			color: #646970;
		}

		.mtl-golive-hint {
			display: block;
			color: #646970;
			font-size: 0.9em;
		}
	</style>

	<div class="mtl-setup-group">
		<h2 class="mtl-setup-group-title">Library Status</h2>

		<?php
		mtl_setup_section_start(
			'golive',
			array(
				'title' => 'Go Live',
				'desc'  => $live ? 'Open to the public: customers can sign up, sign in and reserve tools.' : 'Customers see a Coming soon page until you turn this on.',
				'open'  => ! $live || 'golive' === $requested_section,
				'badge' => $live ? 'Live' : 'Not live',
				'tone'  => $live ? '' : 'warn',
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from static text and esc_url()-wrapped links in mtl_library_status_notice().
		echo $notice;
		?>
			<form method="post" action="<?php echo esc_url( $setup_url ); ?>" id="mtl-golive-form">
				<?php wp_nonce_field( 'mtl_library_status_action', 'mtl_library_status_nonce' ); ?>
				<input type="hidden" name="mtl_library_status_action" value="live">
				<label class="mtl-view-toggle">
					<input type="checkbox" name="mtl_library_live" value="1" <?php checked( $live ); ?>>
					<span class="mtl-view-slider"></span>
					<span><strong>Go Live</strong> <?php echo $live ? 'on' : 'off'; ?></span>
				</label>
				<noscript><p style="margin: 8px 0 0 0;"><button type="submit" class="button">Save</button></p></noscript>
			</form>
			<?php if ( $live ) : ?>
				<p class="mtl-golive-help">
					<?php if ( $since > 0 ) : ?>
						Live since <?php echo esc_html( wp_date( get_option( 'date_format' ), $since ) ); ?>.
					<?php endif; ?>
					Customers can sign up, sign in and reserve tools. Turning this off puts the Coming soon page back, and members can&rsquo;t sign in or see their loans until it&rsquo;s on again.
				</p>
			<?php else : ?>
				<p class="mtl-golive-help">While this is off, customers see a Coming soon page and can&rsquo;t sign up, sign in or reserve tools. Setup emails and agreement requests wait until you go live. Staff can still use everything. Turn it on when you&rsquo;re ready to open.</p>

				<hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">

				<form method="post" action="<?php echo esc_url( $setup_url ); ?>">
					<?php wp_nonce_field( 'mtl_library_status_action', 'mtl_library_status_nonce' ); ?>
					<input type="hidden" name="mtl_library_status_action" value="preview">
					<label class="mtl-view-toggle">
						<input type="checkbox" name="mtl_catalog_preview" value="1" onchange="this.form.submit();" <?php checked( $preview ); ?>>
						<span class="mtl-view-slider"></span>
						<span><strong>Show the catalog before launch</strong> <?php echo $preview ? 'on' : 'off'; ?></span>
					</label>
					<noscript><p style="margin: 8px 0 0 0;"><button type="submit" class="button">Save</button></p></noscript>
				</form>
				<p class="mtl-golive-help">Lets customers browse your tools while you get ready. Sign-up, sign-in and reservations stay closed until you go live.</p>

				<hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">

				<form method="post" action="<?php echo esc_url( $setup_url ); ?>">
					<?php wp_nonce_field( 'mtl_library_status_action', 'mtl_library_status_nonce' ); ?>
					<input type="hidden" name="mtl_library_status_action" value="message">
					<label for="mtl_coming_soon_message"><strong>Coming soon message</strong></label>
					<textarea name="mtl_coming_soon_message" id="mtl_coming_soon_message" rows="3" class="large-text" style="margin-top: 6px;" placeholder="<?php echo esc_attr( mtl_coming_soon_default_message() ); ?>"><?php echo esc_textarea( (string) get_option( 'mtl_coming_soon_message', '' ) ); ?></textarea>
					<p class="mtl-golive-help" style="margin-top: 4px;">Shown to customers on the Coming soon page<?php echo $preview ? ' and above the catalog' : ''; ?>. Leave it blank to use the wording shown in the box.</p>
					<p class="submit" style="margin: 10px 0 0 0; padding: 0;">
						<button type="submit" class="button button-primary">Save Message</button>
					</p>
				</form>

				<hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">

				<?php
				$checklist = mtl_launch_checklist( $tables_ready );
				$to_check  = 0;
				foreach ( $checklist as $item ) {
					if ( 'warn' === $item['status'] ) {
						++$to_check;
					}
				}
				$marks = array(
					'ok'   => array( '&#10003;', 'Done:' ),
					'warn' => array( '!', 'Needs attention:' ),
					'info' => array( '&bull;', 'Reminder:' ),
				);
				?>
				<?php // Collapsed: it advises, it never blocks Go Live, and the summary line says whether there is anything in it worth opening for. ?>
				<details class="mtl-golive-checklist">
					<summary>
						Launch checklist:
						<?php echo 0 === $to_check ? 'all set' : esc_html( $to_check . ( 1 === $to_check ? ' thing' : ' things' ) . ' to look at' ); ?>
					</summary>
					<ul>
						<?php foreach ( $checklist as $item ) : ?>
							<li class="mtl-golive-<?php echo esc_attr( $item['status'] ); ?>">
								<span class="mtl-golive-mark" aria-hidden="true"><?php echo $marks[ $item['status'] ][0]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static entity from the array above. ?></span>
								<span>
									<span class="screen-reader-text"><?php echo esc_html( $marks[ $item['status'] ][1] ); ?></span>
									<?php echo wp_kses_post( $item['label'] ); ?>
									<?php if ( '' !== $item['url'] ) : ?>
										<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo 'ok' === $item['status'] ? 'Review' : 'Fix'; ?></a>
									<?php endif; ?>
									<?php if ( '' !== $item['hint'] ) : ?>
										<span class="mtl-golive-hint"><?php echo wp_kses_post( $item['hint'] ); ?></span>
									<?php endif; ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<script>
				(function() {
					var form = document.getElementById('mtl-golive-form');
					if (!form) {
						return;
					}
					var box = form.querySelector('input[name="mtl_library_live"]');
					var asks = <?php echo wp_json_encode( $asks, JSON_HEX_TAG | JSON_HEX_AMP ); ?>;

					box.addEventListener('change', function() {
						var opening = box.checked;
						if (!window.mtlDialog) {
							form.submit();
							return;
						}
						window.mtlDialog.confirm(opening ? asks.open : asks.close).then(function(ok) {
							if (ok) {
								window.mtlDialog.submit(form);
							} else {
								box.checked = !opening;
							}
						});
					});
				}());
			</script>
		<?php mtl_setup_section_end(); ?>
	</div>
	<?php
}

add_action( 'admin_notices', 'mtl_render_not_live_notice', 20 );

/**
 * Reminds staff on every plugin screen that customers can't use the library
 * yet. Priority 20, after the portal tab bar.
 */
function mtl_render_not_live_notice() {
	if ( mtl_library_is_live() || ! mtl_can_manage_library() ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'mtl-' ) ) {
		return;
	}

	// On Setup the Go Live section says the same thing, open at the top of the
	// page. Not while the admin view is off, which hides that section.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'mtl-setup' === $page && mtl_can_manage_settings() ) {
		return;
	}

	$what = mtl_catalog_preview_enabled()
		? 'Customers can browse the catalog, but can&rsquo;t sign up, sign in or reserve tools.'
		: 'Customers see a Coming soon page and can&rsquo;t sign up, sign in or reserve tools.';

	echo wp_kses_post( '<div class="notice notice-warning"><p><strong>Your library isn&rsquo;t live yet.</strong> ' . $what . ' ' . mtl_go_live_next_step_html() . '</p></div>' );
}

/**
 * What this staff member can do about the library not being live, for the
 * notice above and the preview bar on the public pages.
 *
 * The Go Live section needs the admin view on as well as administrator
 * rights, so an administrator who has switched it off is told how to get
 * back rather than sent to a section that isn't there.
 *
 * @return string HTML.
 */
function mtl_go_live_next_step_html() {
	if ( mtl_can_manage_settings() ) {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=mtl-setup&mtl_open=golive#mtl-section-golive' ) ) . '">Go Live in Setup</a> when you&rsquo;re ready to open.';
	}
	if ( mtl_is_administrator() ) {
		return 'To go live, switch your Admin view back on at the top of <a href="' . esc_url( admin_url( 'admin.php?page=mtl-setup' ) ) . '">Setup</a>.';
	}
	return 'An administrator can go live from Setup.';
}
