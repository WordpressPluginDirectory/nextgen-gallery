<?php
/**
 * Migration: normalize per-entity display type settings.
 *
 * @package Imagely\NGG\Migrations
 */

namespace Imagely\NGG\Migrations;

use Imagely\NGG\DataMappers\DisplayType as DisplayTypeMapper;
use Imagely\NGG\DisplayType\ControllerFactory;
use Imagely\NGG\Util\Serializable;

/**
 * Removes auto-copied default values from per-gallery / per-album display type settings, leaving
 * only genuine customizations so uncustomized types inherit the current global settings at render.
 *
 * Older versions baked a full snapshot of every display type into each entity; this strips, once
 * per display type, any stored value equal to that type's controller default.
 *
 * Two properties keep this safe on real installs:
 *  - Incremental by type. The completion marker stores the list of display types already normalized.
 *    When the registered controller set grows (typically NextGEN Pro being activated after the run
 *    completed with only lite controllers), only the NEW types are normalized. Already-normalized
 *    types are never re-stripped, because after the first pass a stored default-equal value is a
 *    deliberate user choice written through the REST path, not a baked copy (issue #767).
 *  - Resumable. Work is budgeted per request and each table keeps a durable cursor (a row id, or a
 *    DONE marker once finished) so a large install spreads across admin_init requests without a
 *    finished table being rescanned while the other catches up.
 *
 * It must run after every display-type controller is registered (Pro registers its controllers on
 * `ngg_initialized`), so it is dispatched on `admin_init` and defers while no controllers exist.
 */
class NormalizeDisplayTypeSettings {

	const OPTION               = 'imagely_display_type_settings_normalized';
	const RUN_OPTION           = 'imagely_dts_normalize_run';
	const CURSOR_PREFIX        = 'imagely_dts_normalize_cursor_';
	const BATCH                = 200;
	const MAX_ROWS_PER_REQUEST = 5000;

	const ID_FIELDS = [ 'gid', 'id' ];

	const DONE    = 'done';
	const PARTIAL = 'partial';
	const FAILED  = 'failed';

	/**
	 * Runs the migration. Resumable, idempotent, and incremental by display type.
	 *
	 * @param bool $force Re-normalize every registered type even if already recorded as done.
	 * @return bool True when nothing is left to normalize; false when deferred, incomplete, or failed.
	 */
	public static function migrate( $force = false ) {
		$current = self::registered_type_names();

		// No controllers registered yet (dispatched too early, or Pro still loading). Retry later.
		if ( empty( $current ) ) {
			return false;
		}

		$done_types = self::normalized_types();

		// Only normalize display types not already normalized. A shrinking set (e.g. Pro deactivated)
		// yields an empty diff and is a no-op, never a destructive rescan of deliberate values.
		$todo = $force ? $current : array_values( array_diff( $current, $done_types ) );
		if ( empty( $todo ) ) {
			return true;
		}

		$defaults = self::get_controller_defaults( $todo );
		if ( empty( $defaults ) ) {
			return false;
		}

		// Per-table cursors — including the durable DONE marker — are only valid for the exact type
		// set they were scanned against. If that set changed since a previous partial run (e.g. Pro
		// activated mid-run, growing $todo), discard the stale cursors so an already-"done" table is
		// rescanned for the newly-added types instead of being skipped and left un-normalized.
		$run_signature = md5( implode( ',', $todo ) );
		if ( \get_option( self::RUN_OPTION ) !== $run_signature ) {
			self::clear_cursors();
			\update_option( self::RUN_OPTION, $run_signature );
		}

		global $wpdb;
		$budget       = self::MAX_ROWS_PER_REQUEST;
		$gallery_stat = self::normalize_table( $wpdb->prefix . 'ngg_gallery', 'gid', $defaults, $budget );
		$album_stat   = self::normalize_table( $wpdb->prefix . 'ngg_album', 'id', $defaults, $budget );

		// A DB error leaves the marker unchanged so the run retries; per-table cursors preserve progress.
		if ( self::FAILED === $gallery_stat || self::FAILED === $album_stat ) {
			return false;
		}

		// Record the newly-normalized types (unioned with prior) only once both tables finished. A
		// partial run (budget exhausted) resumes on the next request.
		if ( self::DONE === $gallery_stat && self::DONE === $album_stat ) {
			self::clear_cursors();
			\delete_option( self::RUN_OPTION );
			$union = array_values( array_unique( array_merge( $done_types, $todo ) ) );
			sort( $union );
			\update_option( self::OPTION, \wp_json_encode( $union ) );
			return true;
		}

		return false;
	}

	/**
	 * Sorted list of display type names that currently have a registered controller.
	 *
	 * @return array
	 */
	private static function registered_type_names() {
		$names = [];

		foreach ( DisplayTypeMapper::get_instance()->find_all() as $display_type ) {
			if ( ! empty( $display_type->name ) && ControllerFactory::has_controller( $display_type->name ) ) {
				$names[] = $display_type->name;
			}
		}

		sort( $names );
		return $names;
	}

	/**
	 * The display type names already normalized by a previous completed run.
	 *
	 * @return array
	 */
	private static function normalized_types() {
		$stored = json_decode( (string) \get_option( self::OPTION, '' ), true );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Builds a map of display type name => controller default settings.
	 *
	 * @param array $only_types Restrict to these display type names.
	 * @return array
	 */
	private static function get_controller_defaults( $only_types ) {
		$defaults = [];

		foreach ( DisplayTypeMapper::get_instance()->find_all() as $display_type ) {
			if ( empty( $display_type->name ) || ! ControllerFactory::has_controller( $display_type->name ) ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
			if ( ! in_array( $display_type->name, $only_types, true ) ) {
				continue;
			}

			$controller = ControllerFactory::get_controller( $display_type->name );
			if ( \method_exists( $controller, 'get_default_settings' ) ) {
				$defaults[ $display_type->name ] = (array) $controller->get_default_settings();
			}
		}

		return $defaults;
	}

	/**
	 * Strips default-equal values from one table's display_type_settings column.
	 *
	 * Keyset pagination (WHERE id > cursor) with a durable per-table cursor: an integer row id while in
	 * progress, or self::DONE once finished so the table is not rescanned while the other one catches up.
	 *
	 * @param string $table    Fully-qualified table name.
	 * @param string $id_field Primary key column.
	 * @param array  $defaults Map of display type name => default settings.
	 * @param int    $budget   Remaining rows this request may process, passed by reference.
	 * @return string One of self::DONE, self::PARTIAL, self::FAILED.
	 */
	private static function normalize_table( $table, $id_field, $defaults, &$budget ) {
		global $wpdb;

		$cursor_option = self::CURSOR_PREFIX . $id_field;
		$cursor        = \get_option( $cursor_option, 0 );

		// Already finished this run; don't rescan while the other table catches up.
		if ( self::DONE === $cursor ) {
			return self::DONE;
		}

		if ( $budget <= 0 ) {
			return self::PARTIAL;
		}

		$last_id = (int) $cursor;

		do {
			$limit = (int) min( self::BATCH, $budget );
			if ( $limit <= 0 ) {
				return self::PARTIAL;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT `' . \esc_sql( $id_field ) . '` AS id, `display_type_settings` AS settings FROM `'
					. \esc_sql( $table ) . '` WHERE `display_type_settings` IS NOT NULL AND `display_type_settings` != %s '
					. 'AND `' . \esc_sql( $id_field ) . '` > %d ORDER BY `' . \esc_sql( $id_field ) . '` ASC LIMIT %d',
					'',
					$last_id,
					$limit
				)
			);

			// null is a query error; an empty array is a genuine end-of-table.
			if ( null === $rows ) {
				return self::FAILED;
			}

			$count = count( $rows );

			foreach ( $rows as $row ) {
				$last_id = (int) $row->id;
				--$budget;

				$settings = Serializable::unserialize( $row->settings );
				if ( ! is_array( $settings ) || empty( $settings ) ) {
					continue;
				}

				if ( ! self::strip_defaults( $settings, $defaults ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update(
					$table,
					[ 'display_type_settings' => Serializable::serialize( $settings ) ],
					[ $id_field => $row->id ]
				);

				if ( false === $result ) {
					\update_option( $cursor_option, (string) $last_id );
					return self::FAILED;
				}
			}

			\update_option( $cursor_option, (string) $last_id );
		} while ( $count === $limit && $budget > 0 );

		if ( $count < $limit ) {
			\update_option( $cursor_option, self::DONE );
			return self::DONE;
		}

		return self::PARTIAL;
	}

	/**
	 * Clears every per-table cursor. Called once a run fully completes.
	 *
	 * @return void
	 */
	private static function clear_cursors() {
		foreach ( self::ID_FIELDS as $id_field ) {
			\delete_option( self::CURSOR_PREFIX . $id_field );
		}
	}

	/**
	 * Removes values equal to the controller default from each display type slice.
	 *
	 * @param array $settings Per-entity display type settings, passed by reference.
	 * @param array $defaults Map of display type name => default settings.
	 * @return bool True if anything was removed.
	 */
	private static function strip_defaults( &$settings, $defaults ) {
		$changed = false;

		foreach ( $settings as $type_name => $type_settings ) {
			if ( ! isset( $defaults[ $type_name ] ) || ! is_array( $type_settings ) ) {
				continue;
			}

			$type_defaults = $defaults[ $type_name ];

			foreach ( $type_settings as $key => $value ) {
				if ( ! \array_key_exists( $key, $type_defaults ) ) {
					continue;
				}

				// The old save path baked booleans as ints, so normalize a boolean default the same
				// way before comparing (e.g. false -> 0 so it matches a baked '0').
				$default = $type_defaults[ $key ];
				$default = is_bool( $default ) ? (int) $default : $default;

				if ( (string) $default === (string) $value ) {
					unset( $settings[ $type_name ][ $key ] );
					$changed = true;
				}
			}

			if ( empty( $settings[ $type_name ] ) ) {
				unset( $settings[ $type_name ] );
				$changed = true;
			}
		}

		return $changed;
	}
}
