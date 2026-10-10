<?php
/**
 * Automatic backups: the Export Data .sql dump, encrypted and saved to the
 * Media Library on a schedule.
 *
 * @package My_Tool_Library
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ==========================================================================
// AUTOMATIC BACKUPS
//
// Every few days, as set on the Setup page, the same dump Export Data
// downloads (mtl_write_sql_export()) is saved to the Media Library, and only
// the most recent few are kept.
//
// The Media Library is built to publish files. Everything in it has a public
// URL, Editors can browse private items, the REST API lists media, and
// offload plugins copy new uploads to public storage. A backup holds every
// member's details, so it is protected in layers, and the first is the one
// that still holds if any of the others fail:
//
// 1. Encrypted as it is written (libsodium's secretstream,
// XChaCha20-Poly1305), so no plaintext ever reaches the disk. The key is kept
// in the database, not beside the files, and administrators are shown it to
// keep elsewhere, because a lost database takes this site's copy of the key
// with it.
// 2. Private, and hidden from everyone but administrators in the Media
// Library, the REST API and attachment pages
// (mtl_backup_hide_from_non_admins(), mtl_backup_map_meta_cap()).
// 3. A random token in every file name, so no URL can be guessed.
// 4. A folder of their own whose .htaccess refuses every request on Apache,
// and an index.php against directory listings. The Media Library's download
// link goes through an administrator-only handler instead
// (mtl_backup_attachment_url()). Servers that ignore .htaccess, such as
// nginx, still have layers 1 to 3.
// ==========================================================================

/**
 * First line of every encrypted backup file. Restore from Backup checks for
 * it to tell an encrypted backup from a plain .sql dump.
 */
define( 'MTL_BACKUP_MAGIC', "MTL-ENCRYPTED-BACKUP 1\n" );

/**
 * Plaintext bytes per encrypted chunk. Each chunk is authenticated on its
 * own, and the last one is tagged as the last, so a file cut short or altered
 * anywhere fails to decrypt instead of restoring part of a library.
 */
define( 'MTL_BACKUP_CHUNK', 65536 );

/**
 * libsodium's SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE, the
 * tag on every chunk but the last. The polyfill WordPress loads on hosts
 * without the sodium extension defines the other tags but not this one (it
 * calls the same 0 TAG_PUSH), so the constant can't be relied on.
 */
define( 'MTL_BACKUP_TAG_MESSAGE', 0 );

/**
 * The automatic backup settings, each checked against its allowed range.
 *
 * @return array{enabled:bool, days:int, keep:int}
 */
function mtl_backup_settings() {
	$days = (int) get_option( 'mtl_backup_interval_days', 7 );
	$keep = (int) get_option( 'mtl_backup_keep', 10 );
	return array(
		'enabled' => '1' === (string) get_option( 'mtl_backup_enabled', '' ),
		'days'    => ( $days >= 1 && $days <= 90 ) ? $days : 7,
		'keep'    => ( $keep >= 1 && $keep <= 100 ) ? $keep : 10,
	);
}

/**
 * The key backups are encrypted with, created the first time one is needed.
 *
 * Stored as hex in an option that is never autoloaded, so it is only read
 * when a backup is made, restored or shown on the Setup page.
 *
 * @param bool $create Make one if there is none yet.
 * @return string The raw 32-byte key, or '' when there is none (or the stored
 *                one is damaged) and $create is false.
 */
function mtl_backup_key( $create = true ) {
	$hex = (string) get_option( 'mtl_backup_key', '' );
	if ( preg_match( '/^[0-9a-f]{64}$/i', $hex ) ) {
		return hex2bin( $hex );
	}
	if ( ! $create || '' !== $hex ) {
		return '';
	}

	$key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
	// add_option(), not update_option(): when two requests both find no key,
	// only one may set it, or backups would be made with a key that was then
	// overwritten. The loser uses the winner's.
	if ( ! add_option( 'mtl_backup_key', bin2hex( $key ), '', 'no' ) ) {
		return mtl_backup_key( false );
	}
	return $key;
}

/**
 * The backup key as an administrator copies it: 64 hex digits in groups of
 * eight. mtl_backup_decode() ignores the dashes and spaces when it is typed back.
 *
 * @param string $key Raw key.
 * @return string
 */
function mtl_backup_key_display( $key ) {
	return implode( '-', str_split( bin2hex( $key ), 8 ) );
}

/**
 * A short fingerprint of a key, written into each backup so a restore with
 * the wrong key can say so instead of reporting a damaged file. Eight bytes
 * of an HMAC, so it reveals nothing useful about the key itself.
 *
 * @param string $key Raw key.
 * @return string 8 raw bytes.
 */
function mtl_backup_key_id( $key ) {
	return substr( hash_hmac( 'sha256', 'mtl-backup-key-id', $key, true ), 0, 8 );
}

/**
 * Whether an attachment is one of this plugin's backups.
 *
 * @param int $attachment_id Attachment ID.
 * @return bool
 */
function mtl_is_backup( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	return $attachment_id > 0 && '1' === (string) get_post_meta( $attachment_id, '_mtl_backup', true );
}

/**
 * Stored backups, newest first.
 *
 * @param int $limit Most to return.
 * @return WP_Post[]
 */
function mtl_backup_list( $limit = 100 ) {
	return get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => array( 'private', 'inherit' ),
			'meta_key'    => '_mtl_backup',
			'meta_value'  => '1',
			'orderby'     => 'date',
			'order'       => 'DESC',
			'numberposts' => (int) $limit,
		)
	);
}

/**
 * The folder backups are written to, created with its guard files on first use.
 *
 * @return string|WP_Error Absolute path, without a trailing slash.
 */
function mtl_backup_dir() {
	$uploads = wp_upload_dir( null, false );
	if ( ! empty( $uploads['error'] ) ) {
		return new WP_Error( 'mtl_backup_dir', 'The uploads folder is not available: ' . $uploads['error'] );
	}
	$dir = trailingslashit( $uploads['basedir'] ) . 'mtl-backups';
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'mtl_backup_dir', 'The backup folder could not be created in the uploads folder.' );
	}

	// No "Options -Indexes" in the .htaccess: on a server that doesn't allow
	// it there, it turns every request into a 500 error. The index.php is
	// what stops a listing.
	$guards = array(
		'.htaccess' => "# Written by My Tool Library. Backups are downloaded through WordPress, never directly.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
		'index.php' => "<?php\n// Silence is golden.\n",
	);
	foreach ( $guards as $name => $contents ) {
		if ( file_exists( $dir . '/' . $name ) ) {
			continue;
		}
		$handle = fopen( $dir . '/' . $name, 'wb' );
		$wrote  = $handle && false !== fwrite( $handle, $contents );
		if ( $handle ) {
			fclose( $handle );
		}
		if ( ! $wrote ) {
			return new WP_Error( 'mtl_backup_dir', 'The backup folder\'s ' . $name . ' could not be written, so no backup was saved there.' );
		}
	}
	return $dir;
}

/**
 * Whether the web server hands out files from the backup folder to anyone
 * who asks, which is what happens wherever the .htaccess is ignored (nginx,
 * or Apache without AllowOverride). The backups are still encrypted and
 * unguessable there; this is so Setup can say which server rule closes the
 * last gap.
 *
 * Asks the site for a harmless probe file over a loopback request, the kind
 * Site Health makes, never anywhere else. Cached for a day either way, so
 * the Setup page doesn't wait on it every time it loads. A request that fails
 * outright proves nothing and counts as not public.
 *
 * @return bool
 */
function mtl_backup_folder_is_public() {
	$cached = get_transient( 'mtl_backup_folder_public' );
	if ( false !== $cached ) {
		return 'yes' === $cached;
	}

	$public = false;
	$dir    = mtl_backup_dir();
	if ( ! is_wp_error( $dir ) ) {
		$probe = $dir . '/mtl-probe.txt';
		if ( ! file_exists( $probe ) ) {
			$handle = fopen( $probe, 'wb' );
			if ( $handle ) {
				fwrite( $handle, 'mtl-backup-folder-probe' );
				fclose( $handle );
			}
		}
		$uploads  = wp_upload_dir( null, false );
		$response = wp_remote_get(
			trailingslashit( $uploads['baseurl'] ) . 'mtl-backups/mtl-probe.txt',
			array(
				'timeout'   => 5,
				// As core's own loopback requests do, since a local site's
				// certificate is often self-signed.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		$public = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && 'mtl-backup-folder-probe' === trim( wp_remote_retrieve_body( $response ) );
	}

	set_transient( 'mtl_backup_folder_public', $public ? 'yes' : 'no', DAY_IN_SECONDS );
	return $public;
}

/**
 * Opens a new encrypted backup file for writing.
 *
 * File layout: MTL_BACKUP_MAGIC, the key's 8-byte fingerprint, the 24-byte
 * secretstream header, then chunks, each a 4-byte big-endian length followed
 * by that many bytes of ciphertext. The last chunk carries the FINAL tag.
 *
 * @param string $path File to create. Must not exist yet.
 * @param string $key  Raw key.
 * @return array|WP_Error Writer state for mtl_backup_encrypt_write() and
 *                        mtl_backup_encrypt_close().
 */
function mtl_backup_encrypt_open( $path, $key ) {
	// "x" fails rather than overwrite, should a name ever repeat.
	$handle = fopen( $path, 'xb' );
	if ( ! $handle ) {
		return new WP_Error( 'mtl_backup_write', 'The backup file could not be created.' );
	}
	list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );

	return array(
		'handle' => $handle,
		'state'  => $state,
		'buffer' => '',
		'ok'     => false !== fwrite( $handle, MTL_BACKUP_MAGIC . mtl_backup_key_id( $key ) . $header ),
	);
}

/**
 * Encrypts and writes one chunk.
 *
 * @param array  $writer From mtl_backup_encrypt_open().
 * @param string $plain  Plaintext.
 * @param int    $tag    Secretstream tag.
 */
function mtl_backup_encrypt_chunk( &$writer, $plain, $tag ) {
	$cipher = sodium_crypto_secretstream_xchacha20poly1305_push( $writer['state'], $plain, '', $tag );
	if ( false === fwrite( $writer['handle'], pack( 'N', strlen( $cipher ) ) . $cipher ) ) {
		$writer['ok'] = false;
	}
}

/**
 * Adds plaintext to an encrypted backup, writing out each full chunk.
 *
 * @param array  $writer From mtl_backup_encrypt_open().
 * @param string $text   Plaintext.
 */
function mtl_backup_encrypt_write( &$writer, $text ) {
	$writer['buffer'] .= $text;
	$buffered          = strlen( $writer['buffer'] );
	while ( $buffered > MTL_BACKUP_CHUNK ) {
		mtl_backup_encrypt_chunk( $writer, substr( $writer['buffer'], 0, MTL_BACKUP_CHUNK ), MTL_BACKUP_TAG_MESSAGE );
		$writer['buffer'] = (string) substr( $writer['buffer'], MTL_BACKUP_CHUNK );
		$buffered        -= MTL_BACKUP_CHUNK;
	}
}

/**
 * Writes the final chunk and closes the file.
 *
 * @param array $writer From mtl_backup_encrypt_open().
 * @return bool Whether every write succeeded.
 */
function mtl_backup_encrypt_close( &$writer ) {
	mtl_backup_encrypt_chunk( $writer, $writer['buffer'], SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL );
	$writer['buffer'] = '';
	return fclose( $writer['handle'] ) && $writer['ok'];
}

/**
 * Turns an encrypted backup back into its .sql text. Anything that isn't an
 * encrypted backup is returned untouched, so the result can always go
 * straight to mtl_restore_from_sql().
 *
 * @param string $data     File contents.
 * @param string $key_text A key typed in by an administrator, or '' to use this site's.
 * @return string|WP_Error The .sql text.
 */
function mtl_backup_decode( $data, $key_text = '' ) {
	$magic = strlen( MTL_BACKUP_MAGIC );
	if ( 0 !== strncmp( $data, MTL_BACKUP_MAGIC, $magic ) ) {
		return $data;
	}

	$damaged = new WP_Error( 'mtl_backup_damaged', 'That backup file is damaged or incomplete, so it can\'t be decrypted. Try an earlier backup.' );

	if ( '' !== trim( $key_text ) ) {
		$hex = preg_replace( '/[^0-9a-fA-F]/', '', $key_text );
		if ( 64 !== strlen( $hex ) ) {
			return new WP_Error( 'mtl_backup_key', 'That backup key is incomplete. Copy all 64 letters and digits of it.' );
		}
		$key = hex2bin( $hex );
	} else {
		$key = mtl_backup_key( false );
		if ( '' === $key ) {
			return new WP_Error( 'mtl_backup_key', 'That is an encrypted automatic backup, and this site has no backup key to open it. Enter the key from the Setup page of the site that made it.' );
		}
	}

	$header_bytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
	$pos          = $magic + 8 + $header_bytes;
	$len          = strlen( $data );
	if ( $len < $pos ) {
		return $damaged;
	}
	if ( ! hash_equals( mtl_backup_key_id( $key ), substr( $data, $magic, 8 ) ) ) {
		return new WP_Error( 'mtl_backup_key', 'That backup was encrypted with a different backup key. Enter the key that was shown on the Setup page when it was made.' );
	}

	$plain = '';
	$final = false;
	try {
		$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( substr( $data, $magic + 8, $header_bytes ), $key );
		while ( $pos < $len ) {
			// Nothing may follow the final chunk, and every chunk needs its length.
			if ( $final || $pos + 4 > $len ) {
				return $damaged;
			}
			$size = unpack( 'N', substr( $data, $pos, 4 ) );
			$size = (int) $size[1];
			$pos += 4;
			if ( $size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $pos + $size > $len ) {
				return $damaged;
			}
			$out = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, substr( $data, $pos, $size ) );
			if ( false === $out ) {
				return $damaged;
			}
			$pos   += $size;
			$plain .= $out[0];
			$final  = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $out[1];
		}
	} catch ( Throwable $e ) {
		return $damaged;
	}

	return $final ? $plain : $damaged;
}

/**
 * Makes one encrypted backup and adds it to the Media Library.
 *
 * @return int|WP_Error The new attachment's ID.
 */
function mtl_backup_create() {
	$key = mtl_backup_key();
	if ( '' === $key ) {
		return new WP_Error( 'mtl_backup_key', 'The backup key stored on this site is damaged, so no backup was made.' );
	}
	$dir = mtl_backup_dir();
	if ( is_wp_error( $dir ) ) {
		return $dir;
	}

	// Site time, which is what an administrator reads the name in. The
	// random part is what keeps the URL unguessable.
	$path   = $dir . '/my-tool-library-backup-' . wp_date( 'Y-m-d-His' ) . '-' . bin2hex( random_bytes( 12 ) ) . '.sql.enc';
	$writer = mtl_backup_encrypt_open( $path, $key );
	if ( is_wp_error( $writer ) ) {
		return $writer;
	}

	// Caught so a crash part-way still removes the half-written file and lets
	// mtl_backup_run() release its lock and record what happened.
	try {
		$failure = mtl_write_sql_export(
			mtl_export_table_names(),
			function ( $text ) use ( &$writer ) {
				mtl_backup_encrypt_write( $writer, $text );
			}
		);
		$written = mtl_backup_encrypt_close( $writer );
	} catch ( Throwable $e ) {
		if ( is_resource( $writer['handle'] ) ) {
			fclose( $writer['handle'] );
		}
		wp_delete_file( $path );
		return new WP_Error( 'mtl_backup_write', 'The backup stopped part-way: ' . $e->getMessage() );
	}

	if ( '' !== $failure || ! $written ) {
		wp_delete_file( $path );
		return new WP_Error( 'mtl_backup_write', '' !== $failure ? 'The export failed: ' . $failure : 'The backup file could not be written. The server may be out of disk space.' );
	}

	// The marker goes in with the post itself, through meta_input, so there
	// is no moment when the attachment exists without it and an Editor's
	// Media Library could list it.
	$attachment_id = wp_insert_attachment(
		array(
			'post_title'     => 'My Tool Library backup ' . wp_date( 'Y-m-d H:i' ),
			'post_content'   => '',
			'post_status'    => 'private',
			'post_mime_type' => 'application/octet-stream',
			'meta_input'     => array( '_mtl_backup' => '1' ),
		),
		$path,
		0,
		true
	);
	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		wp_delete_file( $path );
		return new WP_Error( 'mtl_backup_media', 'The backup was made but could not be added to the Media Library.' );
	}
	return (int) $attachment_id;
}

/**
 * Deletes the oldest backups beyond the number Setup says to keep.
 */
function mtl_backup_prune() {
	$settings = mtl_backup_settings();
	$backups  = mtl_backup_list( 1000 );
	foreach ( array_slice( $backups, $settings['keep'] ) as $old ) {
		wp_delete_attachment( $old->ID, true );
	}

	// A run killed outright (out of memory or time) can't clean up after
	// itself, and leaves a half-written file that no attachment points to.
	// Swept once it is an hour old, long past any backup still being written.
	$dir = mtl_backup_dir();
	if ( is_wp_error( $dir ) ) {
		return;
	}
	$attached = array();
	foreach ( array_slice( $backups, 0, $settings['keep'] ) as $kept ) {
		$attached[ wp_normalize_path( (string) get_attached_file( $kept->ID ) ) ] = true;
	}
	foreach ( (array) glob( $dir . '/*.sql.enc' ) as $file ) {
		if ( ! isset( $attached[ wp_normalize_path( $file ) ] ) && filemtime( $file ) < time() - HOUR_IN_SECONDS ) {
			wp_delete_file( $file );
		}
	}
}

/**
 * Makes a backup, records how it went for the Setup page, and trims old ones.
 *
 * @param string $trigger 'schedule' or 'manual'.
 * @return int|WP_Error The new attachment's ID.
 */
function mtl_backup_run( $trigger = 'schedule' ) {
	// One at a time. add_option() only succeeds for whoever creates the row,
	// which makes it a lock. One older than half an hour was left by a run
	// that died, and is taken over.
	$now = time();
	if ( ! add_option( 'mtl_backup_lock', $now, '', 'no' ) ) {
		if ( (int) get_option( 'mtl_backup_lock', 0 ) > $now - 30 * MINUTE_IN_SECONDS ) {
			return new WP_Error( 'mtl_backup_busy', 'Another backup is being made right now. Try again in a few minutes.' );
		}
		update_option( 'mtl_backup_lock', $now, 'no' );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit -- a large library's backup can outlast the default limit.
		set_time_limit( 0 );
	}
	$result = mtl_backup_create();
	delete_option( 'mtl_backup_lock' );

	update_option(
		'mtl_backup_last',
		array(
			'time'    => $now,
			'trigger' => $trigger,
			'id'      => is_wp_error( $result ) ? 0 : $result,
			'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
		),
		'no'
	);
	if ( ! is_wp_error( $result ) ) {
		update_option( 'mtl_backup_last_success', $now, 'no' );
		mtl_backup_prune();
	}
	return $result;
}

// --------------------------------------------------------------------------
// Schedule
// --------------------------------------------------------------------------

// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- mtl_backup_settings() keeps the interval between 1 and 90 days, never under the 15 minutes this sniff guards against.
add_filter( 'cron_schedules', 'mtl_backup_cron_schedule' );
add_action( 'mtl_auto_backup', 'mtl_backup_scheduled_run' );
add_action( 'init', 'mtl_backup_keep_scheduled' );

/**
 * Registers the backup interval with WP-Cron, at whatever Setup says.
 *
 * @param array $schedules Registered schedules.
 * @return array
 */
function mtl_backup_cron_schedule( $schedules ) {
	$settings                         = mtl_backup_settings();
	$schedules['mtl_backup_interval'] = array(
		'interval' => $settings['days'] * DAY_IN_SECONDS,
		'display'  => 'My Tool Library backup interval',
	);
	return $schedules;
}

/**
 * The scheduled event. Checks the switch again, in case it was turned off
 * after the event was queued.
 */
function mtl_backup_scheduled_run() {
	$settings = mtl_backup_settings();
	if ( $settings['enabled'] ) {
		mtl_backup_run( 'schedule' );
	}
}

/**
 * Keeps the event in step with the switch on every request, so a schedule
 * lost to a reactivation or a cleared cron list comes back by itself. Cheap
 * once settled: the options and the cron list are all autoloaded.
 */
function mtl_backup_keep_scheduled() {
	$settings  = mtl_backup_settings();
	$scheduled = wp_next_scheduled( 'mtl_auto_backup' );
	if ( $settings['enabled'] && ! $scheduled ) {
		mtl_backup_reschedule();
	} elseif ( ! $settings['enabled'] && $scheduled ) {
		wp_clear_scheduled_hook( 'mtl_auto_backup' );
	}
}

/**
 * Schedules the next backup from scratch: one interval after the last
 * successful backup, or 3 a.m. site time if that has already passed (or there
 * has never been one), when the site is likely to be quiet.
 */
function mtl_backup_reschedule() {
	wp_clear_scheduled_hook( 'mtl_auto_backup' );
	$settings = mtl_backup_settings();
	if ( ! $settings['enabled'] ) {
		return;
	}

	$last = (int) get_option( 'mtl_backup_last_success', 0 );
	$next = $last > 0 ? $last + $settings['days'] * DAY_IN_SECONDS : 0;
	if ( $next <= time() ) {
		$at = new DateTimeImmutable( 'today 03:00', wp_timezone() );
		if ( $at->getTimestamp() <= time() ) {
			$at = $at->modify( '+1 day' );
		}
		$next = $at->getTimestamp();
	}
	wp_schedule_event( $next, 'mtl_backup_interval', 'mtl_auto_backup' );
}

// --------------------------------------------------------------------------
// Keeping backups away from everyone but administrators
// --------------------------------------------------------------------------

add_action( 'pre_get_posts', 'mtl_backup_hide_from_non_admins' );
add_filter( 'map_meta_cap', 'mtl_backup_map_meta_cap', 10, 4 );
add_filter( 'wp_get_attachment_url', 'mtl_backup_attachment_url', 10, 2 );
add_action( 'admin_post_mtl_download_backup', 'mtl_backup_download' );

/**
 * Leaves backups out of every attachment query run for a signed-in
 * non-administrator.
 *
 * Making them private is not enough on its own: the Media Library lists
 * private items to anyone who can read private posts, and that includes
 * Editors, this plugin's desk staff, who may not export member data.
 * Signed-out visitors never see private attachments in the first place.
 *
 * @param WP_Query $query The query about to run.
 */
function mtl_backup_hide_from_non_admins( $query ) {
	if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
		return;
	}
	$types = $query->get( 'post_type' );
	if ( 'any' !== $types && ! in_array( 'attachment', (array) $types, true ) ) {
		return;
	}

	$hide     = array(
		'key'     => '_mtl_backup',
		'compare' => 'NOT EXISTS',
	);
	$existing = $query->get( 'meta_query' );
	if ( is_array( $existing ) && $existing ) {
		// Wrapped rather than appended, so a query that ORs its own
		// conditions can't OR this one away.
		$hide = array(
			'relation' => 'AND',
			$existing,
			$hide,
		);
	} else {
		$hide = array( $hide );
	}
	$query->set( 'meta_query', $hide );
}

/**
 * Refuses non-administrators any reading, editing or deleting of a backup,
 * which covers the REST API, attachment pages and the edit screen.
 *
 * @param string[] $caps    Primitive capabilities the check needs.
 * @param string   $cap     Capability being checked.
 * @param int      $user_id User being checked.
 * @param array    $args    The post being acted on, first.
 * @return string[]
 */
function mtl_backup_map_meta_cap( $caps, $cap, $user_id, $args ) {
	if ( ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) ) {
		return $caps;
	}
	$post = get_post( $args[0] );
	if ( ! $post || 'attachment' !== $post->post_type || ! mtl_is_backup( $post->ID ) ) {
		return $caps;
	}
	return user_can( $user_id, 'manage_options' ) ? $caps : array( 'do_not_allow' );
}

/**
 * Points a backup's URL at the administrator-only download handler, so the
 * Media Library's download link works on every server, including the
 * Apache ones whose .htaccess refuses the file's real address.
 *
 * @param string $url           Attachment URL.
 * @param int    $attachment_id Attachment ID.
 * @return string
 */
function mtl_backup_attachment_url( $url, $attachment_id ) {
	if ( ! mtl_is_backup( $attachment_id ) ) {
		return $url;
	}
	return add_query_arg(
		array(
			'action' => 'mtl_download_backup',
			'backup' => (int) $attachment_id,
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Sends a backup file, still encrypted, to an administrator.
 *
 * A download changes nothing, so the capability check is the whole gate; a
 * nonce would only make the Media Library's copied link stop working a day later.
 */
function mtl_backup_download() {
	$attachment_id = isset( $_GET['backup'] ) ? absint( $_GET['backup'] ) : 0;
	if ( ! current_user_can( 'manage_options' ) || ! mtl_is_backup( $attachment_id ) ) {
		wp_die( 'Only administrators can download My Tool Library backups.', 'Not allowed', array( 'response' => 403 ) );
	}
	$path   = (string) get_attached_file( $attachment_id );
	$handle = '' !== $path && is_readable( $path ) ? fopen( $path, 'rb' ) : false;
	if ( ! $handle ) {
		wp_die( 'That backup file is missing from the server.', 'Not found', array( 'response' => 404 ) );
	}

	while ( ob_get_level() ) {
		ob_end_clean();
	}
	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	fpassthru( $handle );
	fclose( $handle );
	exit;
}
