<?php

/**
 * Removes duplicate (galleryid, filename) rows from the pictures table, keeping the lowest
 * pid of each group. Required before adding a UNIQUE KEY on those columns, since ALTER TABLE
 * ADD UNIQUE INDEX fails on a table that already contains duplicates.
 *
 * @param string $nggpictures Fully prefixed table name.
 */
function nggallery_dedupe_pictures_table( $nggpictures ) {
	global $wpdb;

	// A prior run already added the UNIQUE KEY that dbDelta() adds further down in
	// nggallery_install() -- once that's true the table can no longer contain duplicates, so
	// skip re-scanning it on every request instead of unconditionally re-running this every
	// time nggallery_install() executes.
	$unique_key_exists = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
			$nggpictures,
			'unique_gallery_filename'
		)
	);

	if ( $unique_key_exists ) {
		return;
	}

	// $wpdb->prepare() has no placeholder for table/column identifiers, and $nggpictures is a
	// fully-prefixed table name built by the caller, never user input.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// The self-join below matches rows on (galleryid, filename), but nothing indexes those
	// columns yet at this point -- without one, MySQL/MariaDB has to fall back to an unindexed
	// nested-loop scan whose cost grows with the square of the row count, which is what turns
	// this into a multi-minute, lock-heavy operation on tables with tens of thousands of rows.
	// A plain (non-unique) index lets the join use an index lookup instead; it's dropped again
	// once the dedupe is done, since the real UNIQUE KEY covering the same columns is added by
	// dbDelta() right after this function returns.
	//
	// filename is VARCHAR(255); at utf8mb4 (4 bytes/char) that alone is 1020 bytes, already over
	// MyISAM's 1000-byte max key length before galleryid (8 bytes) is even added. A plain index
	// has no workaround for that on MyISAM (unlike a UNIQUE key, where MariaDB transparently
	// falls back to a hash index for over-length keys -- which is why the real
	// unique_gallery_filename key dbDelta() adds afterward is unaffected by this).
	//
	// Named here, rather than inlined as a bare literal, because this exact line has already
	// been rewritten three times in three weeks (#781, #934, and this fix) -- the next edit
	// should only need to preserve the byte-budget margin below, not re-derive it from the
	// column's charset and MyISAM's key-length limit from scratch.
	$dedupe_tmp_idx_filename_prefix = 100; // 100 * 4 bytes (utf8mb4) + 8 bytes (galleryid) = 408 bytes, safely under MyISAM's 1000-byte limit; real filenames are far shorter than 100 characters, so the prefix still discriminates rows the same as a full-column index would.
	$index_added                    = $wpdb->query( "ALTER TABLE `{$nggpictures}` ADD INDEX `ngg_dedupe_tmp_idx` (galleryid, filename({$dedupe_tmp_idx_filename_prefix}))" );

	if ( false === $index_added ) {
		// A request that died before the DROP INDEX further down ran leaves ngg_dedupe_tmp_idx
		// behind; the next pass's ADD INDEX then fails with "duplicate key name" even though the
		// index this dedupe needs already exists. Treat "already present" as success instead of
		// bailing out and permanently skipping the dedupe.
		$tmp_index_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$nggpictures,
				'ngg_dedupe_tmp_idx'
			)
		);

		if ( ! $tmp_index_exists ) {
			// Without the index, the self-join below falls back to an unindexed nested-loop scan
			// -- exactly the multi-minute, lock-heavy operation this function exists to avoid.
			// Surface the failure via ngg_upgrade_error (not ngg_init_check -- that option is
			// unconditionally cleared by Installer::set_role_caps() on every successful run,
			// which would wipe this before an admin ever sees it) and skip the dedupe rather
			// than silently running it the slow way.
			update_option( 'ngg_upgrade_error', sprintf( 'NextGEN Gallery: could not add a temporary index before deduplicating the pictures table: %s', $wpdb->last_error ) );
			return;
		}
	}

	$deleted = $wpdb->query(
		"DELETE p1 FROM `{$nggpictures}` p1
		INNER JOIN `{$nggpictures}` p2
			ON p1.galleryid = p2.galleryid
			AND p1.filename = p2.filename
			AND p1.pid > p2.pid"
	);

	// $wpdb->query() resets $wpdb->last_error at the start of every call, so the DROP INDEX
	// below would otherwise wipe out a DELETE failure's error before the check after it runs.
	$delete_error = $wpdb->last_error;

	$wpdb->query( "ALTER TABLE `{$nggpictures}` DROP INDEX `ngg_dedupe_tmp_idx`" );
	// phpcs:enable

	// If the dedupe query itself failed, the caller's ALTER TABLE ADD UNIQUE INDEX can fail the
	// same silent way #781 did -- surface it via ngg_upgrade_error, not ngg_init_check (that
	// option is unconditionally cleared by Installer::set_role_caps() on every successful run,
	// which would wipe this before an admin ever sees it), instead of letting the upgrade
	// proceed as if dedupe had succeeded. false === $deleted is the failure signal on its own;
	// a dropped connection or reconnect mid-statement can return false with last_error left
	// empty, so the error string is only optional detail, not a precondition for reporting the
	// failure at all.
	if ( false === $deleted ) {
		$message = 'NextGEN Gallery: could not deduplicate the pictures table before adding a unique index.';
		if ( ! empty( $delete_error ) ) {
			$message .= ' ' . $delete_error;
		}
		update_option( 'ngg_upgrade_error', $message );
	}
}

/**
 * Creates all tables for the gallery called during register_activation hook
 */
function nggallery_install( $installer ) {
	global $wpdb;

	$nggpictures = $wpdb->prefix . 'ngg_pictures';
	$nggallery   = $wpdb->prefix . 'ngg_gallery';
	$nggalbum    = $wpdb->prefix . 'ngg_album';

	// A UNIQUE KEY on (galleryid, filename) below rejects duplicates going forward, but dbDelta's
	// ALTER TABLE ADD UNIQUE INDEX silently fails on sites that already have duplicate rows
	// (see issue #781). Dedupe before the schema upgrade runs so the index actually gets created.
	nggallery_dedupe_pictures_table( $nggpictures );

	// Create pictures table.
	$sql = 'CREATE TABLE ' . $nggpictures . " (
        pid BIGINT(20) NOT NULL AUTO_INCREMENT ,
        image_slug VARCHAR(255) NOT NULL ,
        post_id BIGINT(20) DEFAULT '0' NOT NULL ,
        galleryid BIGINT(20) DEFAULT '0' NOT NULL ,
        filename VARCHAR(255) NOT NULL ,
        description MEDIUMTEXT NULL ,
        alttext MEDIUMTEXT NULL ,
        imagedate DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        exclude TINYINT NULL DEFAULT '0' ,
        sortorder BIGINT(20) DEFAULT '0' NOT NULL ,
        meta_data LONGTEXT,
        extras_post_id BIGINT(20) DEFAULT '0' NOT NULL,
        PRIMARY KEY  (pid),
        KEY extras_post_id_key (extras_post_id),
        UNIQUE KEY unique_gallery_filename (galleryid,filename)
	);";
	$installer->upgrade_schema( $sql );

	// Create gallery table.
	$sql = 'CREATE TABLE ' . $nggallery . " (
        gid BIGINT(20) NOT NULL AUTO_INCREMENT ,
        name VARCHAR(255) NOT NULL ,
        slug VARCHAR(255) NOT NULL ,
        path MEDIUMTEXT NULL ,
        title MEDIUMTEXT NULL ,
        galdesc MEDIUMTEXT NULL ,
        pageid BIGINT(20) DEFAULT '0' NOT NULL ,
        previewpic BIGINT(20) DEFAULT '0' NOT NULL ,
        author BIGINT(20) DEFAULT '0' NOT NULL  ,
        extras_post_id BIGINT(20) DEFAULT '0' NOT NULL,
        date_created DATETIME NULL,
        date_modified DATETIME NULL,
        PRIMARY KEY  (gid),
        KEY extras_post_id_key (extras_post_id)
	)";
	$installer->upgrade_schema( $sql );

	// Create albums table.
	$sql = 'CREATE TABLE ' . $nggalbum . " (
        id BIGINT(20) NOT NULL AUTO_INCREMENT ,
        name VARCHAR(255) NOT NULL ,
        slug VARCHAR(255) NOT NULL ,
        previewpic BIGINT(20) DEFAULT '0' NOT NULL ,
        albumdesc MEDIUMTEXT NULL ,
        sortorder LONGTEXT NOT NULL,
        pageid BIGINT(20) DEFAULT '0' NOT NULL,
        extras_post_id BIGINT(20) DEFAULT '0' NOT NULL,
        date_created DATETIME NULL,
        date_modified DATETIME NULL,
        PRIMARY KEY  (id),
        KEY extras_post_id_key (extras_post_id)
	)";
	$installer->upgrade_schema( $sql );

	// check one table again, to be sure.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', [ $wpdb->esc_like( $nggpictures ) ] ) ) ) {
		update_option( 'ngg_init_check', __( 'NextGEN Gallery : Tables could not created, please check your database settings', 'nggallery' ) );
	}
}

/**
 * Removes a capability from classic roles.
 *
 * @param string $capability name of the capability which should be de-registered
 */
function ngg_remove_capability( $capability ) {
	// this function remove the $capability only from the classic roles.
	$check_order = [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ];

	foreach ( $check_order as $role ) {
		$role = get_role( $role );
		if ( ! is_null( $role ) ) {
			$role->remove_cap( $capability );
		}
	}
}
