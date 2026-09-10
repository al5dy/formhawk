<?php

namespace Formhawk\Tests\Integration;

use Formhawk\CRO\Attribution\ContextCleanup;
use Formhawk\CRO\Attribution\ContextStore;
use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\Migrations\Version5;
use Formhawk\Infrastructure\Migrations\Version6;
use Formhawk\Infrastructure\Migrations\Version7;
use Formhawk\Infrastructure\Migrations\Version8;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class CROContextMigrationTest extends IsolatedStorageTestCase {
	public function test_shipped_v6_schema_upgrades_without_fabricating_exposures_or_rewriting_history() {
		global $wpdb;
		$this->version_six();
		$this->seed_history();
		// An interrupted previous process already added one of the target columns.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN assignments bigint(20) unsigned NOT NULL DEFAULT 0', Database::experiment_daily_table() ) );
		Database::maybe_upgrade();
		$this->assertSame( '8', (string) get_option( 'formhawk_db_version' ) );
		$this->assertTrue( Version7::is_current() );
		$this->assertTrue( Version8::is_current() );
		$this->assertTrue( Version7::run() );
		$this->assertTrue( Version7::run() );
		$totals = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE experiment_id=1', Database::experiment_daily_table() ), ARRAY_A );
		$this->assertSame( '100', $totals['views'] );
		$this->assertSame( '10', $totals['confirmed_successes'] );
		$this->assertSame( '0', $totals['assignments'] );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT integrity_version FROM %i WHERE id=1', Database::experiments_table() ) ) );
		$this->assertSame( 'running', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id=1', Database::experiments_table() ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::cro_contexts_table() ) ) );
		$this->assertSame( 'expires_at_utc', $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s', Database::cro_contexts_table(), 'expires_at_utc' ), ARRAY_A )['Column_name'] );
	}

	public function test_failed_ddl_does_not_advance_version_and_recovers_without_resetting_data() {
		global $wpdb;
		$this->version_six();
		$this->seed_history();
		$fail     = static function ( $query ) {
			return 0 === strpos( $query, 'ALTER TABLE `' . Database::experiment_daily_table() . '` ADD COLUMN' ) ? 'SELECT formhawk_test_deliberate_missing_column' : $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			Database::maybe_upgrade();
			$this->assertSame( '6', (string) get_option( 'formhawk_db_version' ) );
			$this->assertFalse( Database::cro_schema_is_current() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
		Database::maybe_upgrade();
		$this->assertSame( '8', (string) get_option( 'formhawk_db_version' ) );
		$this->assertSame( '100', $wpdb->get_var( $wpdb->prepare( 'SELECT views FROM %i WHERE experiment_id=1', Database::experiment_daily_table() ) ) );
	}

	public function test_nontransactional_legacy_aggregate_engine_is_upgraded_for_atomic_admission() {
		global $wpdb;
		$this->version_six();
		$this->seed_history();
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', Database::experiment_daily_table() ) );
		Database::maybe_upgrade();
		$this->assertTrue( Version7::is_current() );
		$this->assertSame( '100', $wpdb->get_var( $wpdb->prepare( 'SELECT views FROM %i WHERE experiment_id=1', Database::experiment_daily_table() ) ) );
	}

	public function test_context_cleanup_is_expiry_indexed_bounded_and_regularly_scheduled() {
		global $wpdb;
		$now = time();
		foreach ( array(
			'expired-a' => -1,
			'expired-b' => -100,
			'active'    => 7200,
		) as $name => $offset ) {
			$wpdb->insert(
				Database::cro_contexts_table(),
				array(
					'context_hash'   => hash( 'sha256', $name ),
					'experiment_id'  => 1,
					'variant_id'     => 1,
					'form_id'        => 1,
					'segment'        => 'desktop',
					'issued_at_utc'  => gmdate( 'Y-m-d H:i:s', $now - 7200 ),
					'expires_at_utc' => gmdate( 'Y-m-d H:i:s', $now + $offset ),
				)
			);
		}
		$this->assertSame( 1, ContextStore::cleanup( 1 ) );
		$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::cro_contexts_table() ) ) );
		$cleanup = new ContextCleanup();
		$cleanup->run();
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::cro_contexts_table() ) ) );
		$this->assertNotFalse( wp_next_scheduled( ContextCleanup::HOOK ) );
		$this->assertSame( 900, ContextCleanup::schedules( array() )['formhawk_fifteen_minutes']['interval'] );
	}

	private function version_six() {
		global $wpdb;
		// Recreate the actual, unchanged shipped CRO DDL, not a guessed subset of its columns.
		foreach ( array( Database::cro_contexts_table(), Database::experiment_daily_table(), Database::experiments_table() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
		}
		$this->assertTrue( Version5::run() );
		$this->assertTrue( Version6::run() );
		update_option( 'formhawk_db_version', '6', false );
		$this->assertFalse( Version7::is_current() );
	}

	private function seed_history() {
		global $wpdb;
		$wpdb->insert(
			Database::experiments_table(),
			array(
				'id'                => 1,
				'form_id'           => 1,
				'type'              => 'submit_button',
				'status'            => 'running',
				'hypothesis'        => 'Historical experiment',
				'primary_metric'    => 'confirmed_conversion',
				'evidence_level'    => 'provider_confirmed',
				'policy_json'       => '{}',
				'algorithm_version' => 'beta-binomial-1.0',
				'policy_version'    => '2026-09-01',
				'created_at_utc'    => '2026-09-01 12:00:00',
				'updated_at_utc'    => '2026-09-01 12:00:00',
			)
		);
		$wpdb->insert(
			Database::experiment_daily_table(),
			array(
				'experiment_id'       => 1,
				'variant_id'          => 1,
				'stat_date'           => '2026-09-01',
				'segment'             => 'desktop',
				'views'               => 100,
				'confirmed_successes' => 10,
			)
		);
	}
}
