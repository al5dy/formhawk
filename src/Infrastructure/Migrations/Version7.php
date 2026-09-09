<?php

namespace Formhawk\Infrastructure\Migrations;

use Formhawk\Infrastructure\Database;

/** Additive CRO replay protection; historical browser views are never exposures. */
final class Version7 {
	public static function run() {
		global $wpdb;
		$database = defined( 'DB_NAME' ) ? constant( 'DB_NAME' ) : '';
		$lock     = 'fh_cro_schema_' . substr( hash( 'sha256', $database . ':' . Database::cro_contexts_table() ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialize additive DDL across concurrent upgrade requests.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return false;
		}
		try {
			return self::migrate();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fixed per-site migration lock, not visitor state.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function migrate() {
		global $wpdb;
		$table   = Database::cro_contexts_table();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
			context_hash char(64) NOT NULL,
			experiment_id bigint(20) unsigned NOT NULL,
			variant_id bigint(20) unsigned NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			segment varchar(16) NOT NULL,
			issued_at_utc datetime NOT NULL,
			expires_at_utc datetime NOT NULL,
			viewed tinyint(1) unsigned NOT NULL DEFAULT 0,
			started tinyint(1) unsigned NOT NULL DEFAULT 0,
			client_validation tinyint(1) unsigned NOT NULL DEFAULT 0,
			abandoned tinyint(1) unsigned NOT NULL DEFAULT 0,
			js_error tinyint(1) unsigned NOT NULL DEFAULT 0,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			terminal_attempt smallint(5) unsigned NOT NULL DEFAULT 0,
			observed_attempt smallint(5) unsigned NOT NULL DEFAULT 0,
			latency_attempt smallint(5) unsigned NOT NULL DEFAULT 0,
			resumed_attempt smallint(5) unsigned NOT NULL DEFAULT 0,
			last_success tinyint(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (context_hash),
			KEY expires_at_utc (expires_at_utc)
		) ENGINE=InnoDB {$charset};"
		);
		$columns = array(
			Database::experiment_daily_table() => array( 'assignments' ),
			Database::experiments_table()      => array(
				'integrity_version',
				'integrity_warning',
			),
		);
		foreach ( $columns as $target => $names ) {
			foreach ( $names as $column ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Idempotent additive migration on Formhawk-owned tables.
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $target, $wpdb->esc_like( $column ) ) ) ) {
					if ( ! self::add_column( $target, $column ) ) {
						return false;
					}
				}
			}
		}
		// Both sides of replay admission must roll back together, including on older sites.
		foreach ( array( $table, Database::experiment_daily_table() ) as $target ) {
			if ( 'InnoDB' !== self::engine( $target ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Transactional engine is required for atomic replay protection.
				if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $target ) ) ) {
					return false;
				}
			}
		}
		return self::is_current();
	}

	private static function add_column( $table, $column ) {
		global $wpdb;
		switch ( $column ) {
			case 'assignments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Additive schema change; historical views are not copied into assignments.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN assignments bigint(20) unsigned NOT NULL DEFAULT 0', $table ) );
			case 'integrity_version':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Existing experiments deliberately default to manual-safe legacy evidence.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN integrity_version smallint(5) unsigned NOT NULL DEFAULT 1', $table ) );
			case 'integrity_warning':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One bounded review flag per experiment; no event log or visitor data.
				return false !== $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN integrity_warning varchar(64) NOT NULL DEFAULT ''", $table ) );
		}
		return false;
	}

	/** @phpstan-impure Schema can change between migration passes. */
	public static function is_current() {
		global $wpdb;
		if ( ! Database::tables_have_columns(
			array(
				Database::cro_contexts_table()     => array( 'context_hash', 'experiment_id', 'variant_id', 'form_id', 'segment', 'issued_at_utc', 'expires_at_utc', 'viewed', 'started', 'client_validation', 'abandoned', 'js_error', 'attempts', 'terminal_attempt', 'observed_attempt', 'latency_attempt', 'resumed_attempt', 'last_success' ),
				Database::experiment_daily_table() => array( 'assignments' ),
				Database::experiments_table()      => array( 'integrity_version', 'integrity_warning' ),
			)
		) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify the index supporting bounded TTL cleanup.
		$index = $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s', Database::cro_contexts_table(), 'expires_at_utc' ), ARRAY_A );
		return $index && 'expires_at_utc' === $index['Column_name'] && 'InnoDB' === self::engine( Database::cro_contexts_table() ) && 'InnoDB' === self::engine( Database::experiment_daily_table() );
	}

	private static function engine( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema metadata only; no request or visitor data.
		return $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	}
}
