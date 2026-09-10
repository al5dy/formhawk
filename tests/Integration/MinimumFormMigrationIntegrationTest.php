<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\Migrations\Version8;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class MinimumFormMigrationIntegrationTest extends IsolatedStorageTestCase {
	public function test_version_seven_data_survives_restart_safe_minimum_form_migration() {
		global $wpdb;

		$wpdb->insert(
			Database::experiments_table(),
			array(
				'form_id'           => 41,
				'type'              => 'submit_button',
				'status'            => 'completed',
				'hypothesis'        => 'Historical experiment',
				'primary_metric'    => 'confirmed_conversion',
				'evidence_level'    => 'provider_confirmed',
				'policy_json'       => '{}',
				'algorithm_version' => 'beta-binomial-1.0',
				'policy_version'    => '2026-09-01',
				'integrity_version' => 2,
				'created_at_utc'    => '2026-09-01 10:00:00',
				'updated_at_utc'    => '2026-09-01 11:00:00',
			)
		);
		$experiment_id = absint( $wpdb->insert_id );
		$this->strip_version_eight_schema();
		// Reproduce an early CRO table that was marked current without the
		// abandonment guardrail column used by the aggregate repository.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Deliberately reproduces the incomplete historical schema in isolated storage.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN abandonments', Database::experiment_daily_table() ) );
		update_option( 'formhawk_db_version', '7', false );

		Database::maybe_upgrade();

		$this->assertSame( '8', (string) get_option( 'formhawk_db_version' ) );
		$this->assertTrue( Version8::is_current() );
		$this->assertSame( 'completed', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id=%d', Database::experiments_table(), $experiment_id ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT minimum_form_run_id FROM %i WHERE id=%d', Database::experiments_table(), $experiment_id ) ) );
		$this->assertSame( 'abandonments', $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', Database::experiment_daily_table(), 'abandonments' ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::minimum_form_runs_table() ) ) );

		$this->assertTrue( Version8::run() );
		$this->assertTrue( Version8::run() );
		$this->assertSame( 'completed', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id=%d', Database::experiments_table(), $experiment_id ) ) );
	}

	public function test_partial_version_eight_migration_is_completed_without_resetting_cro_history() {
		global $wpdb;

		$this->strip_version_eight_schema();
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN provider_success tinyint(1) unsigned NOT NULL DEFAULT 0', Database::cro_contexts_table() ) );
		update_option( 'formhawk_db_version', '7', false );

		$this->assertTrue( Version8::run() );
		$this->assertTrue( Version8::is_current() );
		$this->assertSame( 'provider_success', $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', Database::cro_contexts_table(), 'provider_success' ) ) );
	}

	private function strip_version_eight_schema() {
		global $wpdb;

		foreach ( array( Database::minimum_form_decisions_table(), Database::minimum_form_baselines_table(), Database::minimum_form_runs_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Removes only randomly prefixed test tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		$columns = array(
			Database::cro_contexts_table() => array( 'provider_success' ),
			Database::experiments_table()  => array( 'minimum_form_run_id', 'minimum_form_baseline_id', 'field_definition_id' ),
			Database::submissions_table()  => array( 'minimum_form_baseline_id' ),
		);
		foreach ( array(
			array( Database::experiments_table(), 'minimum_form_run' ),
			array( Database::experiments_table(), 'minimum_form_field' ),
			array( Database::submissions_table(), 'minimum_form_baseline' ),
		) as $index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Reproduces version seven before its columns are removed.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $index[0], $index[1] ) );
		}
		foreach ( $columns as $table => $names ) {
			foreach ( $names as $column ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Reproduces the shipped version-seven schema in isolated storage.
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, $column ) );
			}
		}
	}
}
