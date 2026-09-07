<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Infrastructure\Cleanup;
use PHPUnit\Framework\TestCase;

final class CleanupIntegrationTest extends TestCase {
	private $table;

	protected function setUp(): void {
		global $wpdb;

		parent::setUp();
		$this->table = $wpdb->prefix . 'formhawk_cleanup_test_' . strtolower( wp_generate_password( 6, false, false ) );
		$sql         = $wpdb->prepare( 'CREATE TABLE %i (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, stat_date date NOT NULL, PRIMARY KEY (id))', $this->table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Creates one randomly named, test-only table.
		$wpdb->query( $sql );
	}

	protected function tearDown(): void {
		global $wpdb;

		$sql = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Removes only the randomly named test table.
		$wpdb->query( $sql );
		parent::tearDown();
	}

	public function test_cleanup_delete_is_bounded_and_preserves_recent_rows() {
		global $wpdb;

		$wpdb->insert( $this->table, array( 'stat_date' => '2020-01-01' ), array( '%s' ) );
		$wpdb->insert( $this->table, array( 'stat_date' => '2020-01-02' ), array( '%s' ) );
		$wpdb->insert( $this->table, array( 'stat_date' => '2030-01-01' ), array( '%s' ) );

		$method = new \ReflectionMethod( Cleanup::class, 'delete_batch' );
		$method->setAccessible( true );
		$deleted = $method->invoke( new Cleanup(), $this->table, '2025-01-01' );
		$query   = $wpdb->last_query;

		$this->assertSame( 2, $deleted );
		$this->assertStringContainsString( 'LIMIT ' . Cleanup::BATCH_SIZE, $query );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table ) ) );
	}
}
