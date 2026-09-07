<?php

namespace Formhawk\Tests\Fixtures;

use Formhawk\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

abstract class IsolatedStorageTestCase extends TestCase {
	private $storage_prefix;
	private $storage_version;
	private $storage_tables;
	private $storage_options = array();

	protected function setUp(): void {
		global $wpdb;
		parent::setUp();
		$this->storage_prefix  = $wpdb->prefix;
		$this->storage_version = get_option( 'formhawk_db_version', '' );
		foreach ( array( 'formhawk_field_roi_settings', 'formhawk_field_roi_cursor', 'formhawk_field_roi_outcome_cursor', 'formhawk_field_roi_dirty', 'formhawk_field_roi_last_evaluated', 'formhawk_field_roi_lock' ) as $option ) {
			$value                            = get_option( $option, null );
			$this->storage_options[ $option ] = array(
				'exists' => null !== $value,
				'value'  => $value,
			);
		}
		$wpdb->prefix         = $wpdb->prefix . 'fh_case_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
		$this->storage_tables = Database::all_tables();
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
		foreach ( $this->storage_options as $option => $snapshot ) {
			if ( $snapshot['exists'] ) {
				update_option( $option, $snapshot['value'], false );
			} else {
				delete_option( $option );
			}
		}
		parent::tearDown();
	}
}
