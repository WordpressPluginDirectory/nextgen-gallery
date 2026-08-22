<?php

namespace Imagely\NGG\Util;

use Imagely\NGG\Settings\Settings;
use Imagely\NGG\Settings\GlobalSettings;

/**
 * Installer utility class.
 */
class Installer {

	/**
	 * Installers array.
	 *
	 * @var array
	 */
	protected static $_installers = [];

	/**
	 * Each product and module will register its own handler (a class, with an install() and uninstall() method)
	 * to be used for install/uninstall routines
	 *
	 * @param string        $name
	 * @param string|object $handler
	 */
	public static function add_handler( $name, $handler ) {
		self::$_installers[ $name ] = $handler;
	}

	/**
	 * Gets an instance of an installation handler
	 *
	 * @param $name
	 * @return mixed
	 */
	public static function get_handler_instance( $name ) {
		if ( isset( self::$_installers[ $name ] ) ) {
			$klass = self::$_installers[ $name ];
			return new $klass();
		} else {
			return null;
		}
	}

	/**
	 * Gets all registered installation handlers.
	 *
	 * @return array
	 */
	protected static function get_all_handlers() {
		return self::$_installers;
	}

	/**
	 * Uninstalls a product
	 *
	 * @param string $product
	 * @param bool   $hard
	 * @return bool
	 */
	public static function uninstall( $product, $hard = false ) {
		$handler = self::get_handler_instance( $product );

		if ( $handler && \method_exists( $handler, 'uninstall' ) ) {
			return $handler->uninstall( $hard );
		}

		if ( $handler && $hard ) {
			Settings::get_instance()->destroy();
			GlobalSettings::get_instance()->destroy();
		}

		return true;
	}

	public static function can_do_upgrade() {
		global $wpdb;

		if ( self::_insert_upgrade_lock() ) {
			return true;
		}

		// Another request already holds the lock. Reclaim it only if it's left over from a run
		// that never finished (crashed, or is still running past a generous timeout). Doing the
		// staleness check as a get_option()-then-update_option() pair would reopen the same race
		// the INSERT above closes -- two requests could both read it as stale before either
		// writes -- so instead this DELETE's WHERE clause encodes staleness itself and MySQL
		// evaluates it atomically in one statement. Only the request whose DELETE actually removes
		// the row gets to reclaim the lock; a losing request's DELETE matches zero rows.
		//
		// $wpdb->prepare() has no placeholder for table identifiers; {$wpdb->options} is WordPress
		// core's own table-name property, never user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reclaimed_rows = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name = 'ngg_doing_upgrade'
					AND ( option_value = '1' OR ( option_value REGEXP '^[0-9]+$' AND CAST( option_value AS UNSIGNED ) < %d ) )",
				\time() - 120
			)
		);
		// phpcs:enable

		if ( false === $reclaimed_rows ) {
			// A DB-level failure here (dropped connection, restricted privilege) is otherwise
			// indistinguishable from "another request holds a fresh lock" -- surface it via
			// ngg_upgrade_error (a separate option from ngg_init_check, which set_role_caps()
			// below clears unconditionally on every successful run and would otherwise wipe
			// this before an admin ever sees it) instead of leaving the site stuck on
			// can_do_upgrade() === false with no clue why.
			\update_option( 'ngg_upgrade_error', \sprintf( 'NextGEN Gallery: could not check the upgrade lock: %s', $wpdb->last_error ) );
			return false;
		}

		if ( ! $reclaimed_rows ) {
			return false;
		}

		// The row is gone -- invalidate both the per-option cache key and the alloptions blob the
		// raw DELETE above bypassed (delete_option() clears both; a raw DELETE clears neither),
		// then reclaim the lock exactly like the first-time case.
		\wp_cache_delete( 'ngg_doing_upgrade', 'options' );
		\wp_cache_delete( 'alloptions', 'options' );

		return self::_insert_upgrade_lock();
	}

	/**
	 * Atomically takes the ngg_doing_upgrade lock with a bare INSERT.
	 *
	 * Core's add_option() ends in INSERT ... ON DUPLICATE KEY UPDATE, so the option_name UNIQUE
	 * KEY never rejects a second writer -- it upserts the row and reports success to both callers.
	 * A plain INSERT has no such fallback: MySQL rejects the second writer at the database level
	 * with a duplicate-key error, which is the only atomic way to hand the lock to exactly one
	 * request. Errors are suppressed because that duplicate-key hit is the expected "someone else
	 * holds the lock" outcome here, not a problem to log -- but it's the ONLY expected failure;
	 * anything else (dropped connection, a write-privilege restriction, a lock-wait timeout) is
	 * reported below rather than silently collapsed into the same "someone else has it" return.
	 *
	 * @return bool
	 */
	private static function _insert_upgrade_lock() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$suppress   = $wpdb->suppress_errors( true );
		$inserted   = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ( 'ngg_doing_upgrade', %s, 'no' )",
				\time()
			)
		);
		$last_error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		// phpcs:enable

		if ( false === $inserted ) {
			// A duplicate-key error on option_name IS the lock being held -- that's this
			// function's whole mechanism, not a failure. Anything else means no lock was taken
			// by anyone, and without this check that was indistinguishable from normal
			// contention: can_do_upgrade() would return false forever with nothing to explain why.
			if ( false === \stripos( $last_error, 'duplicate' ) ) {
				\update_option( 'ngg_upgrade_error', \sprintf( 'NextGEN Gallery: could not take the upgrade lock: %s', $last_error ) );
			}
			return false;
		}

		// The raw INSERT bypassed add_option()'s own cache writes: for an autoload='no' option
		// (this one), add_option() would set the per-option cache directly and clear any
		// negative "notoptions" entry left by an earlier get_option() call finding no row.
		// alloptions is cleared too in case a pre-this-PR stale lock (autoloaded, from the old
		// update_option() call) is still cached from before this option existed as autoload='no'.
		\wp_cache_delete( 'alloptions', 'options' );
		\wp_cache_delete( 'ngg_doing_upgrade', 'options' );
		\wp_cache_delete( 'notoptions', 'options' );

		return true;
	}

	public static function done_upgrade() {
		\delete_option( 'ngg_doing_upgrade' );
	}

	public static function update( $reset = false ) {
		$local_settings  = Settings::get_instance();
		$global_settings = GlobalSettings::get_instance();

		$do_upgrade = false;

		// TODO: remove this when POPE v1 compatibility is reached in Pro.
		if ( \C_NextGEN_Bootstrap::get_pro_api_version() < 4.0 ) {
			// Get last module list and current module list. Compare...
			$last_module_list    = self::_get_last_module_list( $reset );
			$current_module_list = self::_generate_module_info();

			$diff       = \array_diff( $current_module_list, $last_module_list );
			$do_upgrade = ( \count( $diff ) > 0 || \count( $last_module_list ) != \count( $current_module_list ) );
		}

		$ngg_version_setting = $local_settings->get( 'ngg_plugin_version', 0 );
		if ( ! $ngg_version_setting || $ngg_version_setting !== NGG_PLUGIN_VERSION ) {
			$do_upgrade = true;
		}

		// Allow NextGEN extensions to trigger this process.
		$do_upgrade = \apply_filters( 'ngg_do_install_or_setup_process', $do_upgrade );

		$can_upgrade = $do_upgrade && self::can_do_upgrade();

		if ( $can_upgrade && $do_upgrade ) {
			// Clear any notice a previous attempt left behind before trying again -- if whatever
			// failed last time (a DB privilege, a leftover temp index) has since been resolved,
			// nothing below will rewrite this, so the notice won't outlive the problem it reported.
			// Only the request that actually won the upgrade lock clears it: during #934's
			// concurrency storm, every request evaluates $do_upgrade as true until the version
			// bumps, so clearing on $do_upgrade alone let a request that lost the lock race erase
			// a notice a still-running winner (or the handler loop below, for this same request)
			// was about to write -- permanently, since a losing request does nothing else.
			\delete_option( 'ngg_upgrade_error' );

			// Clear APC cache.
			if ( \function_exists( 'apc_clear_cache' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@\apc_clear_cache( 'opcode' );
				\apc_clear_cache();
			}

			// Attempt to reset the opcache. NextGEN 3.50+ and Pro 3.30+ moved, renamed, and deleted several files
			// and purging the opcache should help prevent fatal errors due to cached instructions.
			if ( \function_exists( 'opcache_reset' ) ) {
				\opcache_reset();
			}

			// Clear all of our transients.
			\wp_cache_flush();
			Transient::flush();

			// Remove all NGG created cron jobs.
			self::refresh_cron();

			// Other Pope applications might be loaded, and therefore all singletons should be destroyed, so that they
			// can be adapted as necessary. For now, we'll just assume that the factory is the only singleton that will
			// be used by other Pope applications.
			if ( class_exists( '\C_Component_Factory' ) ) {
				\C_Component_Factory::$_instances = [];
			}

			foreach ( self::get_all_handlers() as $handler_name => $handler_class ) {
				$handler = new $handler_class();
				if ( \method_exists( $handler, 'install' ) ) {
					$handler->install( $reset );
				}
			}

			// Record the current version; changes to this and setting are how updates are triggered.
			$local_settings->set( 'ngg_plugin_version', NGG_PLUGIN_VERSION );

			$global_settings->save();
			$local_settings->save();

			self::set_role_caps();
			\do_action( 'ngg_did_install_or_setup_process' );
		}

		// Update the module list, and remove the update flag.
		if ( $can_upgrade ) {
			if ( isset( $current_module_list ) ) {
				\update_option( 'pope_module_list', $current_module_list );
			}
			self::done_upgrade();
		}
	}

	public static function _get_last_module_list( $reset = false ) {
		if ( $reset ) {
			return [];
		}

		// First try getting the list from a single WP option, "pope_module_list".
		$retval = \get_option( 'pope_module_list', [] );
		if ( ! $retval ) {
			$local_settings = Settings::get_instance();
			$retval         = $local_settings->get( 'pope_module_list', [] );
			$local_settings->delete( 'pope_module_list' );
		}

		return $retval;
	}

	protected static function _generate_module_info() {
		$retval   = [];
		$registry = \C_Component_Registry::get_instance();
		$products = [ 'photocrati-nextgen' ];
		foreach ( $registry->get_product_list() as $product_id ) {
			if ( $product_id != 'photocrati-nextgen' ) {
				$products[] = $product_id;
			}
		}

		foreach ( $products as $product_id ) {
			foreach ( $registry->get_module_list( $product_id ) as $module_id ) {
				$module = $registry->get_module( $module_id );
				if ( $module ) {
					$module_version = $module->module_version;
					$module_string  = "{$module_id}|{$module_version}";
					// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
					if ( ! \in_array( $module_string, $retval ) ) {
						$retval[] = $module_string;
					}
				}
			}
		}

		return $retval;
	}

	public static function refresh_cron() {
		if ( ! \extension_loaded( 'suhosin' ) ) {
			\wp_raise_memory_limit();
		}

		// Remove all cron jobs created by NextGEN Gallery.
		$cron = \_get_cron_array();
		if ( \is_array( $cron ) ) {
			foreach ( $cron as $timestamp => $job ) {
				if ( \is_array( $job ) ) {
					unset( $cron[ $timestamp ]['ngg_delete_expired_transients'] );
					if ( empty( $cron[ $timestamp ] ) ) {
						unset( $cron[ $timestamp ] );
					}
				}
			}
		}

		\_set_cron_array( $cron );
	}

	public static function set_role_caps() {
		// Set the capabilities for the administrator.
		$role = \get_role( 'administrator' );

		if ( ! $role ) {
			if ( ! class_exists( 'WP_Roles' ) ) {
				include_once ABSPATH . '/wp-includes/class-wp-roles.php';
			}
			$roles = new \WP_Roles();
			$roles->init_roles();
		}

		// We need this role, no other chance.
		$role = \get_role( 'administrator' );
		if ( ! $role ) {
			\update_option( 'ngg_init_check', __( 'Sorry, NextGEN Gallery works only with a role called administrator', 'nggallery' ) );
			return;
		}

		delete_option( 'ngg_init_check' );

		$capabilities = [
			'NextGEN Attach Interface',
			'NextGEN Change options',
			'NextGEN Change style',
			'NextGEN Edit album',
			'NextGEN Gallery overview',
			'NextGEN Manage gallery',
			'NextGEN Manage others gallery',
			'NextGEN Manage tags',
			'NextGEN Upload images',
			'NextGEN Use TinyMCE',
		];

		foreach ( $capabilities as $capability ) {
			$role->add_cap( $capability );
		}
	}
}
