<?php

namespace Formhawk\Infrastructure\Migrations;

use Formhawk\Infrastructure\Database;

/** Installs privacy-safe submission/outcome linkage and aggregate Field ROI storage. */
final class Version6 {
	public static function run() {
		global $wpdb;

		$collate           = $wpdb->get_charset_collate();
		$submissions       = Database::submissions_table();
		$outcomes          = Database::outcomes_table();
		$submission_fields = Database::submission_fields_table();
		$field_definitions = Database::field_definitions_table();
		$form_versions     = Database::form_versions_table();
		$field_daily       = Database::field_value_daily_table();
		$results           = Database::field_roi_results_table();
		$history           = Database::field_roi_history_table();
		$api_keys          = Database::outcome_api_keys_table();
		$audit             = Database::business_audit_table();
		if ( Database::tables_have_columns( array( $results => array( 'field_definition_id', 'currency' ) ) ) && ! self::results_primary_key_is_current() ) {
			self::normalize_results_primary_key();
		}

		$sql   = array();
		$sql[] = "CREATE TABLE {$field_definitions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			provider_field_id varchar(191) NOT NULL,
			normalized_key varchar(191) NOT NULL,
			label varchar(191) NOT NULL DEFAULT '',
			field_type varchar(32) NOT NULL DEFAULT '',
			required tinyint(1) unsigned NOT NULL DEFAULT 0,
			position smallint(5) unsigned NOT NULL DEFAULT 0,
			first_seen_utc datetime NOT NULL,
			last_seen_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY form_provider_field (form_id, provider_field_id),
			KEY form_position (form_id, position),
			KEY last_seen (last_seen_utc)
		) ENGINE=InnoDB {$collate};";
		$sql[] = "CREATE TABLE {$form_versions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			fingerprint char(64) NOT NULL,
			schema_json longtext NOT NULL,
			created_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY form_fingerprint (form_id, fingerprint),
			KEY form_created (form_id, created_at_utc)
		) ENGINE=InnoDB {$collate};";
		$sql[] = "CREATE TABLE {$submissions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id varchar(64) NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
			provider varchar(32) NOT NULL,
			provider_form_id varchar(191) NOT NULL,
			provider_entry_id varchar(191) DEFAULT NULL,
			form_version_id bigint(20) unsigned DEFAULT NULL,
			experiment_id bigint(20) unsigned DEFAULT NULL,
			variant_id bigint(20) unsigned DEFAULT NULL,
			device_class varchar(16) NOT NULL DEFAULT 'unknown',
			status varchar(24) NOT NULL DEFAULT 'submitted',
			submitted_at_utc datetime NOT NULL,
			stat_date date NOT NULL,
			mature_after_utc datetime NOT NULL,
			attribution_expires_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY form_submitted (form_id, stat_date, submitted_at_utc),
			KEY placement_submitted (placement_id, submitted_at_utc),
			KEY provider_entry (provider, provider_form_id, provider_entry_id),
			KEY experiment_variant (experiment_id, variant_id, stat_date),
			KEY stat_date (stat_date, id),
			KEY submitted_at (submitted_at_utc, id),
			KEY expiry (attribution_expires_at_utc),
			KEY maturity_status (mature_after_utc, status)
		) ENGINE=InnoDB {$collate};";
		$sql[] = "CREATE TABLE {$outcomes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			outcome_type varchar(24) NOT NULL,
			value_minor bigint(20) DEFAULT NULL,
			currency char(3) DEFAULT NULL,
			source varchar(32) NOT NULL,
			external_reference_hash char(64) DEFAULT NULL,
			idempotency_hash char(64) NOT NULL,
			terminal_value_key char(64) DEFAULT NULL,
			occurred_at_utc datetime NOT NULL,
			created_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_hash (idempotency_hash),
			UNIQUE KEY terminal_value_key (terminal_value_key),
			KEY submission_occurred (submission_id, occurred_at_utc, id),
			KEY type_occurred (outcome_type, occurred_at_utc),
			KEY currency_occurred (currency, occurred_at_utc)
		) ENGINE=InnoDB {$collate};";
		$sql[] = "CREATE TABLE {$submission_fields} (
			submission_id bigint(20) unsigned NOT NULL,
			field_definition_id bigint(20) unsigned NOT NULL,
			was_present tinyint(1) unsigned NOT NULL DEFAULT 1,
			was_required tinyint(1) unsigned NOT NULL DEFAULT 0,
			had_validation_failure tinyint(1) unsigned NOT NULL DEFAULT 0,
			correction_count smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (submission_id, field_definition_id),
			KEY field_submission (field_definition_id, submission_id)
		) ENGINE=InnoDB {$collate};";
		$sql[] = "CREATE TABLE {$field_daily} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			field_definition_id bigint(20) unsigned NOT NULL,
			stat_date date NOT NULL,
			cohort varchar(24) NOT NULL DEFAULT 'present',
			placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
			device_class varchar(16) NOT NULL DEFAULT 'unknown',
			form_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
			experiment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'XXX',
			submissions bigint(20) unsigned NOT NULL DEFAULT 0,
			qualified bigint(20) unsigned NOT NULL DEFAULT 0,
			won bigint(20) unsigned NOT NULL DEFAULT 0,
			spam bigint(20) unsigned NOT NULL DEFAULT 0,
			duplicates bigint(20) unsigned NOT NULL DEFAULT 0,
			unknown_outcomes bigint(20) unsigned NOT NULL DEFAULT 0,
			revenue_minor bigint(20) NOT NULL DEFAULT 0,
			revenue_samples bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY field_cohort_dimensions (field_definition_id, stat_date, cohort, placement_id, device_class, form_version_id, experiment_id, variant_id, currency),
			KEY date_field (stat_date, field_definition_id),
			KEY experiment_variant (experiment_id, variant_id, stat_date)
		) ENGINE=InnoDB {$collate};";
		$sql[] = "CREATE TABLE {$results} (
			field_definition_id bigint(20) unsigned NOT NULL,
			period_start date NOT NULL,
			period_end date NOT NULL,
			currency char(3) NOT NULL DEFAULT 'XXX',
			evidence_level varchar(32) NOT NULL,
			confidence varchar(16) NOT NULL,
			verdict varchar(32) NOT NULL,
			recommendation varchar(32) NOT NULL,
			score smallint(5) unsigned NOT NULL DEFAULT 0,
			metrics_json longtext NOT NULL,
			model_version varchar(32) NOT NULL,
			evaluated_at_utc datetime NOT NULL,
			data_through_utc datetime NOT NULL,
			PRIMARY KEY  (field_definition_id, currency),
			KEY verdict_confidence (verdict, confidence),
			KEY evaluated (evaluated_at_utc)
		) ENGINE=InnoDB {$collate};";
		$sql[] = "CREATE TABLE {$history} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			field_definition_id bigint(20) unsigned NOT NULL,
			result_hash char(64) NOT NULL,
			before_metrics_json longtext NOT NULL,
			after_metrics_json longtext NOT NULL,
			decision varchar(32) NOT NULL,
			confidence varchar(16) NOT NULL,
			evidence_level varchar(32) NOT NULL,
			currency char(3) NOT NULL DEFAULT 'XXX',
			model_version varchar(32) NOT NULL,
			created_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY field_result (field_definition_id, result_hash),
			KEY field_created (field_definition_id, created_at_utc)
		) ENGINE=InnoDB {$collate};";
		$sql[] = "CREATE TABLE {$api_keys} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_id varchar(24) NOT NULL,
			name varchar(100) NOT NULL,
			secret_hash varchar(255) NOT NULL,
			capabilities varchar(191) NOT NULL DEFAULT 'record_outcomes',
			created_by bigint(20) unsigned NOT NULL,
			created_at_utc datetime NOT NULL,
			last_used_at_utc datetime DEFAULT NULL,
			revoked_at_utc datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_id (key_id),
			KEY revoked (revoked_at_utc)
		) ENGINE=InnoDB {$collate};";
		$sql[] = "CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(48) NOT NULL,
			object_type varchar(32) NOT NULL,
			object_id varchar(64) NOT NULL,
			details_json longtext NOT NULL,
			created_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object_created (object_type, object_id, created_at_utc),
			KEY event_created (event_type, created_at_utc),
			KEY created (created_at_utc)
		) ENGINE=InnoDB {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		self::normalize_submission_indexes();
		if ( ! self::results_primary_key_is_current() ) {
			self::normalize_results_primary_key();
		}
		if ( ! Database::tables_have_columns( array( Database::cro_forms_table() => array( 'optimization_objective' ) ) ) ) {
			$alter = $wpdb->prepare( "ALTER TABLE %i ADD optimization_objective varchar(24) NOT NULL DEFAULT 'auto' AFTER aggressiveness", Database::cro_forms_table() );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Versioned, guarded, restart-safe schema addition; schema state must not be cached.
			$wpdb->query( $alter );
		}

		return self::is_current();
	}

	public static function is_current() {
		$required = array(
			Database::submissions_table()       => array( 'public_id', 'stat_date', 'mature_after_utc', 'attribution_expires_at_utc' ),
			Database::outcomes_table()          => array( 'value_minor', 'idempotency_hash', 'terminal_value_key', 'occurred_at_utc' ),
			Database::submission_fields_table() => array( 'was_present', 'was_required' ),
			Database::field_definitions_table() => array( 'provider_field_id', 'normalized_key' ),
			Database::form_versions_table()     => array( 'fingerprint', 'schema_json' ),
			Database::field_value_daily_table() => array( 'stat_date', 'revenue_minor', 'unknown_outcomes' ),
			Database::field_roi_results_table() => array( 'currency', 'evidence_level', 'metrics_json' ),
			Database::field_roi_history_table() => array( 'result_hash', 'currency', 'model_version' ),
			Database::outcome_api_keys_table()  => array( 'secret_hash', 'revoked_at_utc' ),
			Database::business_audit_table()    => array( 'event_type', 'details_json', 'created_at_utc' ),
			Database::cro_forms_table()         => array( 'optimization_objective' ),
		);
		return Database::tables_have_columns( $required )
			&& self::results_primary_key_is_current()
			&& array( 'form_id', 'stat_date', 'submitted_at_utc' ) === self::index_columns( Database::submissions_table(), 'form_submitted' )
			&& array( 'experiment_id', 'variant_id', 'stat_date' ) === self::index_columns( Database::submissions_table(), 'experiment_variant' );
	}

	private static function results_primary_key_is_current() {
		return array( 'field_definition_id', 'currency' ) === self::index_columns( Database::field_roi_results_table(), 'PRIMARY' );
	}

	private static function normalize_results_primary_key() {
		global $wpdb;
		$alter = $wpdb->prepare( 'ALTER TABLE %i DROP PRIMARY KEY, ADD PRIMARY KEY (field_definition_id,currency)', Database::field_roi_results_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Guarded normalization of a partially installed v6 projection identity; schema state must not be cached.
		$wpdb->query( $alter );
	}

	private static function normalize_submission_indexes() {
		global $wpdb;
		$table   = Database::submissions_table();
		$current = self::index_columns( $table, 'form_submitted' );
		if ( $current && array( 'form_id', 'stat_date', 'submitted_at_utc' ) !== $current ) {
			$sql = $wpdb->prepare( 'ALTER TABLE %i DROP INDEX form_submitted, ADD KEY form_submitted (form_id,stat_date,submitted_at_utc)', $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Guarded correction of a partially installed v6 query index; schema state must not be cached.
			$wpdb->query( $sql );
		}
		$current = self::index_columns( $table, 'experiment_variant' );
		if ( $current && array( 'experiment_id', 'variant_id', 'stat_date' ) !== $current ) {
			$sql = $wpdb->prepare( 'ALTER TABLE %i DROP INDEX experiment_variant, ADD KEY experiment_variant (experiment_id,variant_id,stat_date)', $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Guarded correction of a partially installed v6 experiment index; schema state must not be cached.
			$wpdb->query( $sql );
		}
	}

	private static function index_columns( $table, $key_name ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SHOW INDEX FROM %i', $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Migration verifies exact internal index definitions.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $key_name ) {
					return isset( $row['Key_name'] ) && $key_name === $row['Key_name'];
				}
			)
		);
		usort(
			$rows,
			static function ( $left, $right ) {
				return absint( $left['Seq_in_index'] ) <=> absint( $right['Seq_in_index'] );
			}
		);
		return array_map(
			static function ( $row ) {
				return $row['Column_name'];
			},
			$rows
		);
	}
}
