<?php

namespace Formhawk\Infrastructure\Migrations;

use Formhawk\Infrastructure\Database;

/**
 * Installs aggregate-only Autopilot CRO storage.
 *
 * No legacy analytics are copied: pre-CRO traffic has no experiment assignment,
 * so attributing it to a control would fabricate historical evidence.
 */
final class Version5 {
	public static function run() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$cro_forms       = Database::cro_forms_table();
		$experiments     = Database::experiments_table();
		$variants        = Database::variants_table();
		$daily           = Database::experiment_daily_table();
		$history         = Database::optimization_history_table();

		$sql_cro_forms = "CREATE TABLE {$cro_forms} (
			form_id bigint(20) unsigned NOT NULL,
			mode varchar(16) NOT NULL DEFAULT 'approve',
			state varchar(24) NOT NULL DEFAULT 'collecting',
			aggressiveness varchar(16) NOT NULL DEFAULT 'balanced',
			max_experimental_traffic smallint(5) unsigned NOT NULL DEFAULT 50,
			min_duration_days smallint(5) unsigned NOT NULL DEFAULT 7,
			min_conversions int(10) unsigned NOT NULL DEFAULT 50,
			lead_value decimal(18,2) DEFAULT NULL,
			currency varchar(3) NOT NULL DEFAULT 'USD',
			baseline_json longtext NOT NULL,
			previous_baseline_json longtext NOT NULL,
			enabled_at_utc datetime NOT NULL,
			last_evaluated_at_utc datetime DEFAULT NULL,
			updated_at_utc datetime NOT NULL,
			PRIMARY KEY  (form_id),
			KEY mode_state (mode, state),
			KEY last_evaluated (last_evaluated_at_utc)
		) {$charset_collate};";

		$sql_experiments = "CREATE TABLE {$experiments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			type varchar(48) NOT NULL,
			status varchar(32) NOT NULL,
			hypothesis varchar(500) NOT NULL,
			primary_metric varchar(32) NOT NULL,
			evidence_level varchar(24) NOT NULL,
			segment_scope varchar(16) NOT NULL DEFAULT 'all',
			started_at_utc datetime DEFAULT NULL,
			ended_at_utc datetime DEFAULT NULL,
			winner_variant_id bigint(20) unsigned DEFAULT NULL,
			baseline_experiment_id bigint(20) unsigned DEFAULT NULL,
			policy_json longtext NOT NULL,
			algorithm_version varchar(32) NOT NULL,
			policy_version varchar(32) NOT NULL,
			created_at_utc datetime NOT NULL,
			updated_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_status (form_id, status),
			KEY status_started (status, started_at_utc),
			KEY form_created (form_id, created_at_utc)
		) {$charset_collate};";

		$sql_variants = "CREATE TABLE {$variants} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			experiment_id bigint(20) unsigned NOT NULL,
			name varchar(100) NOT NULL,
			mutation_type varchar(48) NOT NULL,
			mutation_config longtext NOT NULL,
			traffic_weight smallint(5) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL,
			created_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY experiment_status (experiment_id, status)
		) {$charset_collate};";

		$sql_daily = "CREATE TABLE {$daily} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			experiment_id bigint(20) unsigned NOT NULL,
			variant_id bigint(20) unsigned NOT NULL,
			stat_date date NOT NULL,
			segment varchar(16) NOT NULL DEFAULT 'desktop',
			views bigint(20) unsigned NOT NULL DEFAULT 0,
			starts bigint(20) unsigned NOT NULL DEFAULT 0,
			attempts bigint(20) unsigned NOT NULL DEFAULT 0,
			confirmed_successes bigint(20) unsigned NOT NULL DEFAULT 0,
			observed_submits bigint(20) unsigned NOT NULL DEFAULT 0,
			abandonments bigint(20) unsigned NOT NULL DEFAULT 0,
			client_validation_failures bigint(20) unsigned NOT NULL DEFAULT 0,
			provider_validation_failures bigint(20) unsigned NOT NULL DEFAULT 0,
			provider_failures bigint(20) unsigned NOT NULL DEFAULT 0,
			mail_failures bigint(20) unsigned NOT NULL DEFAULT 0,
			js_errors bigint(20) unsigned NOT NULL DEFAULT 0,
			latency_total_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			latency_samples bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY experiment_variant_date_segment (experiment_id, variant_id, stat_date, segment),
			KEY experiment_date (experiment_id, stat_date),
			KEY variant_date (variant_id, stat_date)
		) {$charset_collate};";

		$sql_history = "CREATE TABLE {$history} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			experiment_id bigint(20) unsigned NOT NULL,
			decision varchar(32) NOT NULL,
			lift decimal(12,6) DEFAULT NULL,
			probability decimal(12,8) DEFAULT NULL,
			expected_loss decimal(12,8) DEFAULT NULL,
			previous_baseline_json longtext NOT NULL,
			resulting_baseline_json longtext NOT NULL,
			algorithm_version varchar(32) NOT NULL,
			policy_version varchar(32) NOT NULL,
			reason varchar(500) NOT NULL,
			created_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_created (form_id, created_at_utc),
			KEY experiment_id (experiment_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_cro_forms );
		dbDelta( $sql_experiments );
		dbDelta( $sql_variants );
		dbDelta( $sql_daily );
		dbDelta( $sql_history );

		return self::is_current();
	}

	public static function is_current() {
		global $wpdb;

		$required = array(
			Database::cro_forms_table()            => array( 'baseline_json', 'mode', 'lead_value' ),
			Database::experiments_table()          => array( 'algorithm_version', 'winner_variant_id', 'evidence_level' ),
			Database::variants_table()             => array( 'mutation_config', 'traffic_weight' ),
			Database::experiment_daily_table()     => array( 'confirmed_successes', 'observed_submits', 'abandonments', 'js_errors' ),
			Database::optimization_history_table() => array( 'resulting_baseline_json', 'policy_version' ),
		);

		foreach ( $required as $table => $columns ) {
			foreach ( $columns as $column ) {
				$sql = $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Versioned Formhawk-owned schema verification.
				if ( $column !== $wpdb->get_var( $sql ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
