<?php

namespace Formhawk\Infrastructure\Migrations;

use Formhawk\Infrastructure\Database;

/**
 * Adds immutable Minimum Form baselines and server-confirmed CRO replay safety.
 *
 * Existing CRO baselines and evidence are not reinterpreted. Minimum Form is
 * opt-in, so an upgrade creates no run and changes no provider form at runtime.
 */
final class Version8 {
	public static function run() {
		global $wpdb;

		$database = defined( 'DB_NAME' ) ? constant( 'DB_NAME' ) : '';
		$lock     = 'fh_min_schema_' . substr( hash( 'sha256', $database . ':' . Database::minimum_form_runs_table() ), 0, 46 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialize restart-safe additive DDL.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return false;
		}
		try {
			return self::migrate();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the fixed per-site migration lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function migrate() {
		global $wpdb;

		$collate   = $wpdb->get_charset_collate();
		$runs      = Database::minimum_form_runs_table();
		$baselines = Database::minimum_form_baselines_table();
		$decisions = Database::minimum_form_decisions_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$runs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'collecting',
			objective varchar(32) NOT NULL DEFAULT 'auto',
			mode varchar(16) NOT NULL DEFAULT 'approve',
			aggressiveness varchar(16) NOT NULL DEFAULT 'balanced',
			original_baseline_id bigint(20) unsigned DEFAULT NULL,
			current_baseline_id bigint(20) unsigned DEFAULT NULL,
			active_experiment_id bigint(20) unsigned DEFAULT NULL,
			schema_fingerprint char(64) NOT NULL,
			dependency_hash char(64) NOT NULL,
			original_field_count smallint(5) unsigned NOT NULL DEFAULT 0,
			current_field_count smallint(5) unsigned NOT NULL DEFAULT 0,
			paused_reason varchar(64) NOT NULL DEFAULT '',
			cro_was_enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
			previous_cro_settings_json longtext NOT NULL,
			started_at_utc datetime NOT NULL,
			completed_at_utc datetime DEFAULT NULL,
			last_evaluated_at_utc datetime DEFAULT NULL,
			updated_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_status (form_id, status),
			KEY status_evaluated (status, last_evaluated_at_utc),
			KEY active_experiment (active_experiment_id)
		) ENGINE=InnoDB {$collate};"
		);
		dbDelta(
			"CREATE TABLE {$baselines} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			version smallint(5) unsigned NOT NULL,
			parent_baseline_id bigint(20) unsigned DEFAULT NULL,
			created_by_experiment_id bigint(20) unsigned DEFAULT NULL,
			schema_fingerprint char(64) NOT NULL,
			dependency_hash char(64) NOT NULL,
			genome_json longtext NOT NULL,
			mutation_stack_json longtext NOT NULL,
			business_value_metric varchar(32) NOT NULL DEFAULT 'confirmed_conversion',
			validation_status varchar(24) NOT NULL DEFAULT 'valid',
			created_at_utc datetime NOT NULL,
			validated_at_utc datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY run_version (run_id, version),
			UNIQUE KEY created_by_experiment (created_by_experiment_id),
			KEY form_created (form_id, created_at_utc),
			KEY parent_baseline (parent_baseline_id)
		) ENGINE=InnoDB {$collate};"
		);
		dbDelta(
			"CREATE TABLE {$decisions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			field_definition_id bigint(20) unsigned NOT NULL,
			experiment_id bigint(20) unsigned DEFAULT NULL,
			baseline_before_id bigint(20) unsigned NOT NULL,
			baseline_after_id bigint(20) unsigned DEFAULT NULL,
			mutation_type varchar(48) NOT NULL,
			decision varchar(32) NOT NULL,
			evidence_level varchar(32) NOT NULL,
			primary_metric varchar(32) NOT NULL,
			business_lift decimal(14,8) DEFAULT NULL,
			probability decimal(12,8) DEFAULT NULL,
			expected_loss decimal(14,8) DEFAULT NULL,
			control_value decimal(20,6) DEFAULT NULL,
			variant_value decimal(20,6) DEFAULT NULL,
			control_visitors bigint(20) unsigned NOT NULL DEFAULT 0,
			variant_visitors bigint(20) unsigned NOT NULL DEFAULT 0,
			control_confirmed bigint(20) unsigned NOT NULL DEFAULT 0,
			variant_confirmed bigint(20) unsigned NOT NULL DEFAULT 0,
			confidence varchar(16) NOT NULL,
			reason varchar(500) NOT NULL,
			minimum_form_algorithm_version varchar(32) NOT NULL,
			statistical_policy_version varchar(32) NOT NULL,
			field_roi_version varchar(64) NOT NULL,
			created_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY experiment_decision (experiment_id, decision),
			KEY run_created (run_id, created_at_utc),
			KEY form_field (form_id, field_definition_id),
			KEY baseline_after (baseline_after_id)
		) ENGINE=InnoDB {$collate};"
		);

		$columns = array(
			Database::cro_contexts_table()     => array( 'provider_success' ),
			Database::experiments_table()      => array( 'minimum_form_run_id', 'minimum_form_baseline_id', 'field_definition_id' ),
			Database::experiment_daily_table() => array( 'abandonments' ),
			Database::submissions_table()      => array( 'minimum_form_baseline_id' ),
		);
		foreach ( $columns as $table => $names ) {
			foreach ( $names as $column ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Idempotent schema inspection for a Formhawk-owned table.
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like( $column ) ) ) && ! self::add_column( $table, $column ) ) {
					return false;
				}
			}
		}
		if ( ! self::ensure_index( Database::experiments_table(), 'minimum_form_run' )
			|| ! self::ensure_index( Database::experiments_table(), 'minimum_form_field' )
			|| ! self::ensure_index( Database::submissions_table(), 'minimum_form_baseline' ) ) {
			return false;
		}

		return self::is_current();
	}

	private static function add_column( $table, $column ) {
		global $wpdb;
		switch ( $column ) {
			case 'provider_success':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Additive replay-admission bit; historical contexts remain unconsumed.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN provider_success tinyint(1) unsigned NOT NULL DEFAULT 0', $table ) );
			case 'minimum_form_run_id':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Nullable ownership preserves every existing CRO experiment.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN minimum_form_run_id bigint(20) unsigned DEFAULT NULL', $table ) );
			case 'minimum_form_baseline_id':
				if ( Database::submissions_table() === $table ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Aggregate attribution link; no visitor value is added.
					return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN minimum_form_baseline_id bigint(20) unsigned DEFAULT NULL', $table ) );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Nullable immutable-baseline link for new semantic experiments only.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN minimum_form_baseline_id bigint(20) unsigned DEFAULT NULL', $table ) );
			case 'field_definition_id':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Structural field reference contains no submitted value.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN field_definition_id bigint(20) unsigned DEFAULT NULL', $table ) );
			case 'abandonments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Repairs early CRO schemas before Minimum Form reads guardrail totals; zero preserves unknown historical abandonment evidence.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN abandonments bigint(20) unsigned NOT NULL DEFAULT 0 AFTER observed_submits', $table ) );
		}
		return false;
	}

	private static function ensure_index( $table, $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Restart-safe inspection of a fixed migration index.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s', $table, $name ) ) ) {
			return true;
		}
		switch ( $name ) {
			case 'minimum_form_run':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Indexed run ownership for active experiment queries.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY minimum_form_run (minimum_form_run_id)', $table ) );
			case 'minimum_form_field':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Indexed field ancestry for history views.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY minimum_form_field (field_definition_id)', $table ) );
			case 'minimum_form_baseline':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Baseline/date index supports bounded regression cohorts.
				return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY minimum_form_baseline (minimum_form_baseline_id, stat_date)', $table ) );
		}
		return false;
	}

	/** @phpstan-impure Schema can change between migration passes. */
	public static function is_current() {
		if ( ! Database::tables_have_columns(
			array(
				Database::minimum_form_runs_table()      => array( 'form_id', 'status', 'current_baseline_id', 'schema_fingerprint', 'dependency_hash', 'cro_was_enabled', 'previous_cro_settings_json' ),
				Database::minimum_form_baselines_table() => array( 'run_id', 'version', 'created_by_experiment_id', 'genome_json', 'mutation_stack_json', 'validation_status' ),
				Database::minimum_form_decisions_table() => array( 'run_id', 'field_definition_id', 'experiment_id', 'decision', 'control_visitors', 'variant_visitors', 'control_confirmed', 'variant_confirmed', 'minimum_form_algorithm_version' ),
				Database::cro_contexts_table()           => array( 'provider_success' ),
				Database::experiments_table()            => array( 'minimum_form_run_id', 'minimum_form_baseline_id', 'field_definition_id' ),
				Database::experiment_daily_table()       => array( 'abandonments' ),
				Database::submissions_table()            => array( 'minimum_form_baseline_id' ),
			)
		) ) {
			return false;
		}
		return self::has_index( Database::experiments_table(), 'minimum_form_run' )
			&& self::has_index( Database::experiments_table(), 'minimum_form_field' )
			&& self::has_index( Database::submissions_table(), 'minimum_form_baseline' );
	}

	private static function has_index( $table, $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Versioned schema verification for a fixed Formhawk index.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s', $table, $name ) );
	}
}
