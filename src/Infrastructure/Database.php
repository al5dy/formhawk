<?php

namespace Formhawk\Infrastructure;

use Formhawk\Infrastructure\Migrations\Version4;

final class Database {
	public static function dimension_lock_name() {
		return 'formhawk_' . hash( 'sha1', constant( 'DB_NAME' ) . '|' . self::dimensions_table() );
	}

	public static function ingestion_ready() {
		return version_compare( (string) get_option( 'formhawk_db_version', '' ), FORMHAWK_DB_VERSION, '>=' );
	}

	public static function budgets_table() {
		global $wpdb;
		return $wpdb->prefix . 'formhawk_ingestion_budgets';
	}

	public static function dimensions_table() {
		global $wpdb;
		return $wpdb->prefix . 'formhawk_dimensions';
	}

	public static function forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'formhawk_forms';
	}

	public static function daily_table() {
		global $wpdb;
		return $wpdb->prefix . 'formhawk_daily';
	}

	public static function fields_table() {
		global $wpdb;
		return $wpdb->prefix . 'formhawk_field_daily';
	}

	public static function placements_table() {
		global $wpdb;
		return $wpdb->prefix . 'formhawk_placements';
	}

	public static function placement_daily_table() {
		global $wpdb;
		return $wpdb->prefix . 'formhawk_placement_daily';
	}

	public static function install() {
		self::install_core_schema();
		self::install_placement_schema();
		$migrated = Version4::run();

		if ( $migrated && self::tables_exist() && self::schema_is_current() ) {
			update_option( 'formhawk_db_version', FORMHAWK_DB_VERSION, false );
		}
	}

	public static function maybe_upgrade() {
		$installed_version = (string) get_option( 'formhawk_db_version', '' );
		if ( version_compare( $installed_version, FORMHAWK_DB_VERSION, '>=' ) ) {
			return;
		}

		if ( '' === $installed_version && ! self::base_tables_exist() ) {
			self::install();
			return;
		}

		// Version 2 adds only zero-defaulted counters/metadata. Existing submissions
		// retain their historical meaning and are never relabelled as attempts or confirmations.
		if ( version_compare( $installed_version ? $installed_version : '1', '2', '<' ) ) {
			self::install_core_schema();
			if ( ! self::schema_is_version_2() ) {
				return;
			}
			update_option( 'formhawk_db_version', '2', false );
			$installed_version = '2';
		}

		// Version 3 introduces aggregate-only placement tables. Historical form
		// totals remain untouched because a placement cannot be inferred reliably.
		if ( version_compare( $installed_version, '3', '<' ) ) {
			self::install_placement_schema();
			if ( ! self::placement_schema_is_current() ) {
				return;
			}
			update_option( 'formhawk_db_version', '3', false );
		}
		if ( version_compare( $installed_version, '4', '<' ) && Version4::run() ) {
			update_option( 'formhawk_db_version', '4', false );
		}
	}

	private static function install_core_schema() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$forms           = self::forms_table();
		$daily           = self::daily_table();
		$fields          = self::fields_table();

		$sql_forms = "CREATE TABLE {$forms} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_key varchar(191) NOT NULL,
			provider varchar(32) NOT NULL DEFAULT 'html',
			provider_form_id varchar(191) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL DEFAULT '',
			page_path varchar(500) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			last_success_at datetime DEFAULT NULL,
			last_failure_at datetime DEFAULT NULL,
			last_mail_success_at datetime DEFAULT NULL,
			last_mail_failure_at datetime DEFAULT NULL,
			last_failure_code varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY form_key (form_key),
			KEY provider_form (provider, provider_form_id),
			KEY last_seen (last_seen)
		) {$charset_collate};";

		$sql_daily = "CREATE TABLE {$daily} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			stat_date date NOT NULL,
			views bigint(20) unsigned NOT NULL DEFAULT 0,
			starts bigint(20) unsigned NOT NULL DEFAULT 0,
			submit_attempts bigint(20) unsigned NOT NULL DEFAULT 0,
			submissions bigint(20) unsigned NOT NULL DEFAULT 0,
			confirmed_successes bigint(20) unsigned NOT NULL DEFAULT 0,
			abandons bigint(20) unsigned NOT NULL DEFAULT 0,
			validation_failures bigint(20) unsigned NOT NULL DEFAULT 0,
			failures bigint(20) unsigned NOT NULL DEFAULT 0,
			mail_successes bigint(20) unsigned NOT NULL DEFAULT 0,
			mail_failures bigint(20) unsigned NOT NULL DEFAULT 0,
			duration_total_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			duration_samples bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY form_date (form_id, stat_date),
			KEY stat_date (stat_date)
		) {$charset_collate};";

		$sql_fields = "CREATE TABLE {$fields} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			stat_date date NOT NULL,
			field_key varchar(191) NOT NULL,
			field_label varchar(191) NOT NULL DEFAULT '',
			field_type varchar(32) NOT NULL DEFAULT '',
			interactions bigint(20) unsigned NOT NULL DEFAULT 0,
			abandonments bigint(20) unsigned NOT NULL DEFAULT 0,
			validation_errors bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY form_date_field (form_id, stat_date, field_key),
			KEY stat_date (stat_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_forms );
		dbDelta( $sql_daily );
		dbDelta( $sql_fields );
	}

	private static function install_placement_schema() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$placements      = self::placements_table();
		$placement_daily = self::placement_daily_table();

		$sql_placements = "CREATE TABLE {$placements} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			placement_key varchar(191) NOT NULL,
			page_path varchar(500) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY placement_key (placement_key),
			KEY form_id (form_id),
			KEY last_seen (last_seen)
		) {$charset_collate};";

		$sql_placement_daily = "CREATE TABLE {$placement_daily} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			placement_id bigint(20) unsigned NOT NULL,
			stat_date date NOT NULL,
			views bigint(20) unsigned NOT NULL DEFAULT 0,
			starts bigint(20) unsigned NOT NULL DEFAULT 0,
			submit_attempts bigint(20) unsigned NOT NULL DEFAULT 0,
			submissions bigint(20) unsigned NOT NULL DEFAULT 0,
			confirmed_successes bigint(20) unsigned NOT NULL DEFAULT 0,
			abandons bigint(20) unsigned NOT NULL DEFAULT 0,
			validation_failures bigint(20) unsigned NOT NULL DEFAULT 0,
			failures bigint(20) unsigned NOT NULL DEFAULT 0,
			mail_successes bigint(20) unsigned NOT NULL DEFAULT 0,
			mail_failures bigint(20) unsigned NOT NULL DEFAULT 0,
			duration_total_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			duration_samples bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY placement_date (placement_id, stat_date),
			KEY stat_date (stat_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_placements );
		dbDelta( $sql_placement_daily );
	}

	public static function tables_exist() {
		global $wpdb;
		foreach ( array( self::forms_table(), self::daily_table(), self::fields_table(), self::placements_table(), self::placement_daily_table(), self::budgets_table(), self::dimensions_table() ) as $table ) {
			$like = is_callable( array( $wpdb, 'esc_like' ) ) ? $wpdb->esc_like( $table ) : $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Runtime schema diagnostic for Formhawk-owned tables.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	private static function base_tables_exist() {
		global $wpdb;
		foreach ( array( self::forms_table(), self::daily_table(), self::fields_table() ) as $table ) {
			$like = is_callable( array( $wpdb, 'esc_like' ) ) ? $wpdb->esc_like( $table ) : $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Version-detection query for Formhawk-owned tables.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	public static function schema_is_current() {
		return self::schema_is_version_2() && self::placement_schema_is_current() && Version4::is_current();
	}

	private static function schema_is_version_2() {
		$required = array(
			self::forms_table()  => array( 'last_mail_success_at' ),
			self::daily_table()  => array( 'submit_attempts', 'validation_failures', 'mail_successes' ),
			self::fields_table() => array( 'field_type' ),
		);

		return self::tables_have_columns( $required );
	}

	private static function placement_schema_is_current() {
		$required = array(
			self::placements_table()      => array( 'placement_key', 'page_path' ),
			self::placement_daily_table() => array( 'placement_id', 'submit_attempts', 'validation_failures' ),
		);

		return self::tables_have_columns( $required );
	}

	private static function tables_have_columns( array $required ) {

		global $wpdb;
		foreach ( $required as $table => $columns ) {
			foreach ( $columns as $column ) {
				$sql = $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Versioned schema verification for Formhawk-owned custom tables.
				if ( $column !== $wpdb->get_var( $sql ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
