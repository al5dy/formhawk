<?php

namespace Formhawk\Infrastructure\Migrations;

use Formhawk\Infrastructure\Database;

final class Version4 {
	public static function columns() {
		$daily = array( 'client_validation_failures', 'provider_validation_failures', 'provider_validation_outcomes' );
		return array(
			Database::daily_table()           => $daily,
			Database::placement_daily_table() => $daily,
			Database::fields_table()          => array( 'client_validation_errors', 'provider_validation_errors' ),
		);
	}

	public static function run() {
		global $wpdb;
		if ( self::is_current() ) {
			return DimensionBackfill::run();
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collation  = $wpdb->get_charset_collate();
		$budgets    = Database::budgets_table();
		$dimensions = Database::dimensions_table();
		dbDelta(
			"CREATE TABLE {$budgets} (
			scope_key varchar(64) NOT NULL,
			window_id bigint(20) unsigned NOT NULL DEFAULT 0,
			used bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (scope_key)
		) ENGINE=InnoDB {$collation};"
		);
		dbDelta(
			"CREATE TABLE {$dimensions} (
			dimension_key char(64) NOT NULL,
			kind varchar(16) NOT NULL,
			PRIMARY KEY  (dimension_key)
		) ENGINE=InnoDB {$collation};"
		);

		foreach ( self::columns() as $table => $columns ) {
			$additions = array();
			$args      = array( $table );
			foreach ( $columns as $column ) {
				if ( ! self::has_column( $table, $column ) ) {
					$additions[] = 'ADD COLUMN %i bigint(20) unsigned NOT NULL DEFAULT 0';
					$args[]      = $column;
				}
			}
			if ( $additions ) {
				// One additive DDL per table; no row rewrite in PHP or invented historical evidence.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL fragments are fixed and identifiers come exclusively from the internal schema map.
				$sql = $wpdb->prepare( 'ALTER TABLE %i ' . implode( ', ', $additions ), $args );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above: fixed ADD COLUMN fragments and internal schema identifiers, all bound with %i. Additive DDL is verified before advancing the version.
				if ( false === $wpdb->query( $sql ) ) {
					return false;
				}
			}
		}
		return self::is_current() && DimensionBackfill::run();
	}

	/** @phpstan-impure Schema can change between calls during this migration. */
	public static function is_current() {
		$required                                 = self::columns();
		$required[ Database::budgets_table() ]    = array( 'scope_key', 'window_id', 'used' );
		$required[ Database::dimensions_table() ] = array( 'dimension_key', 'kind' );
		foreach ( $required as $table => $columns ) {
			foreach ( $columns as $column ) {
				if ( ! self::has_column( $table, $column ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function has_column( $table, $column ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Versioned schema pre/postcondition, independent of cached options.
		return $column === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like( $column ) ) );
	}
}
