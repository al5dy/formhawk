<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\Migrations\Version4;
use Formhawk\Analytics\CardinalityGuard;
use Formhawk\Analytics\FormRepository;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class HardeningMigrationIntegrationTest extends IsolatedStorageTestCase {
	public function test_backfill_is_bounded_resumable_and_keeps_ingestion_paused_until_complete() {
		global $wpdb;
		$forms = new FormRepository();
		for ( $index = 0; $index < 501; ++$index ) {
			$forms->resolve(
				array(
					'provider'         => 'cf7',
					'provider_form_id' => (string) ( $index + 1 ),
					'page_path'        => '/existing',
				)
			);
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::budgets_table() ) );
		update_option( 'formhawk_db_version', '3', false );
		Database::maybe_upgrade();
		$this->assertSame( '3', get_option( 'formhawk_db_version' ) );
		$this->assertFalse( Database::ingestion_ready() );
		$this->assertSame( '501', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::dimensions_table() ) ) );
		for ( $pass = 0; $pass < 5; ++$pass ) {
			if ( Database::ingestion_ready() ) {
				break;
			}
			Database::maybe_upgrade();
		}
		$this->assertTrue( Database::ingestion_ready() );
		$this->assertSame( '501', $wpdb->get_var( $wpdb->prepare( 'SELECT used FROM %i WHERE scope_key = %s', Database::budgets_table(), hash( 'sha256', 'dimensions|forms|total' ) ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT used FROM %i WHERE scope_key = %s', Database::budgets_table(), hash( 'sha256', 'dimensions|forms|day' ) ) ) );
	}

	public function test_existing_dimensions_survive_full_new_dimension_budgets_after_upgrade() {
		global $wpdb;
		$event    = array(
			'provider'         => 'cf7',
			'provider_form_id' => '71',
			'page_path'        => '/existing',
		);
		$identity = ( new FormRepository() )->resolve( $event );
		$wpdb->insert(
			Database::fields_table(),
			array(
				'form_id'   => $identity['form_id'],
				'stat_date' => '2026-01-01',
				'field_key' => 'email',
			)
		);
		// Simulate a v3 installation with existing inventory and no admission registry.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::budgets_table() ) );
		$this->assertTrue( Version4::run() );
		$this->assertSame( '4', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::dimensions_table() ) ) );
		$limits = static function ( $defaults ) {
			return array_merge(
				$defaults,
				array(
					'forms_total'         => 1,
					'fields_per_form'     => 1,
					'placements_per_form' => 1,
				)
			);
		};
		add_filter( 'formhawk_ingestion_limits', $limits );
		try {
			$event['fields'] = array( array( 'key' => 'email' ) );
			$guard           = new CardinalityGuard();
			$this->assertSame( 'accepted', $guard->admit( $event ) );
			$event['fields'] = array( array( 'key' => 'new-field' ) );
			$this->assertSame( 'cardinality', $guard->admit( $event ) );
			$event['provider_form_id'] = '72';
			$this->assertSame( 'cardinality', $guard->admit( $event ) );
		} finally {
			remove_filter( 'formhawk_ingestion_limits', $limits );
		}
	}

	public function test_actual_version_three_schema_preserves_mixed_history_and_recovers_after_partial_ddl() {
		global $wpdb;
		$tables = array(
			'forms'           => Database::forms_table(),
			'daily'           => Database::daily_table(),
			'fields'          => Database::fields_table(),
			'placements'      => Database::placements_table(),
			'placement_daily' => Database::placement_daily_table(),
		);
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- Replace only this test's randomly prefixed schema with the committed v3 snapshot.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
		}
		$replacements = array( '{charset_collate}' => $wpdb->get_charset_collate() );
		foreach ( $tables as $key => $table ) {
			$replacements[ '{' . $key . '}' ] = $table;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a local, committed SQL fixture; no remote URL.
		$schema = strtr( file_get_contents( dirname( __DIR__ ) . '/Fixtures/schema-v3.sql' ), $replacements );
		foreach ( explode( ';', $schema ) as $query ) {
			if ( trim( $query ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Trusted historical DDL fixture with only internally generated table names substituted.
				$this->assertNotFalse( $wpdb->query( $query ) );
			}
		}
		update_option( 'formhawk_db_version', '3', false );
		$wpdb->insert(
			Database::daily_table(),
			array(
				'form_id'             => 1,
				'stat_date'           => '2026-01-01',
				'submissions'         => 17,
				'submit_attempts'     => 11,
				'confirmed_successes' => 3,
				'validation_failures' => 9,
			)
		);
		$wpdb->insert(
			Database::fields_table(),
			array(
				'form_id'           => 1,
				'stat_date'         => '2026-01-01',
				'field_key'         => 'email',
				'validation_errors' => 8,
			)
		);
		// Simulate one completed DDL before a process stops. The next invocation must finish safely.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulated partial migration on isolated test storage.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN client_validation_failures bigint(20) unsigned NOT NULL DEFAULT 0', Database::daily_table() ) );
		$this->assertFalse( Version4::is_current() );
		Database::maybe_upgrade();
		Database::maybe_upgrade();
		$this->assertTrue( Version4::is_current() );
		$this->assertSame( '6', (string) get_option( 'formhawk_db_version' ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1', Database::daily_table() ), ARRAY_A );
		$this->assertSame( '17', $row['submissions'] );
		$this->assertSame( '11', $row['submit_attempts'] );
		$this->assertSame( '3', $row['confirmed_successes'] );
		$this->assertSame( '9', $row['validation_failures'] );
		$this->assertSame( '0', $row['provider_validation_failures'] );
		$this->assertSame( '0', $row['provider_validation_outcomes'] );
		$this->assertSame( '0', $row['client_validation_failures'] );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1', Database::fields_table() ), ARRAY_A );
		$this->assertSame( '8', $row['validation_errors'] );
		$this->assertSame( '0', $row['provider_validation_errors'] );
		$this->assertSame( '0', $row['client_validation_errors'] );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::dimensions_table() ) ) );
	}
}
