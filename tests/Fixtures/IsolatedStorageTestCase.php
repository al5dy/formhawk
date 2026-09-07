<?php

namespace Formhawk\Tests\Fixtures;

use Formhawk\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

abstract class IsolatedStorageTestCase extends TestCase {
	private $storage_prefix;
	private $storage_version;
	private $storage_tables;

	protected function setUp(): void {
		global $wpdb;
		parent::setUp();
		$this->storage_prefix  = $wpdb->prefix;
		$this->storage_version = get_option( 'formhawk_db_version', '' );
		$wpdb->prefix          = $wpdb->prefix . 'fh_case_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
		$this->storage_tables  = array( Database::forms_table(), Database::daily_table(), Database::fields_table(), Database::placements_table(), Database::placement_daily_table(), Database::budgets_table(), Database::dimensions_table() );
		Database::install();
		$this->assertTrue( Database::schema_is_current() );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->storage_tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- Drop only this test's randomly prefixed analytics tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		$wpdb->prefix = $this->storage_prefix;
		update_option( 'formhawk_db_version', $this->storage_version, false );
		parent::tearDown();
	}
}
