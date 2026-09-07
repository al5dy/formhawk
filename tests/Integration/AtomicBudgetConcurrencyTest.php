<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Infrastructure\Database;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class AtomicBudgetConcurrencyTest extends IsolatedStorageTestCase {
	public function test_eight_independent_database_connections_cannot_oversubscribe_the_budget() {
		global $wpdb;
		$this->assertSame( 37, $this->run_workers( 'rate' ) );
		$this->assertSame( '37', $wpdb->get_var( $wpdb->prepare( 'SELECT used FROM %i WHERE scope_key = %s', Database::budgets_table(), hash( 'sha256', 'concurrent-test' ) ) ) );
	}

	public function test_concurrent_new_dimensions_cannot_oversubscribe_site_cardinality() {
		global $wpdb;
		$accepted = $this->run_workers( 'dimensions' );
		$this->assertGreaterThan( 0, $accepted );
		$this->assertLessThanOrEqual( 7, $accepted );
		$this->assertSame( (string) $accepted, $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE kind = %s', Database::dimensions_table(), 'forms' ) ) );
		$this->assertSame( (string) $accepted, $wpdb->get_var( $wpdb->prepare( 'SELECT used FROM %i WHERE scope_key = %s', Database::budgets_table(), hash( 'sha256', 'dimensions|forms|total' ) ) ) );
	}

	private function run_workers( $mode ) {
		global $wpdb;
		if ( ! function_exists( 'proc_open' ) || ! getenv( 'FORMHAWK_WP_ROOT' ) ) {
			$this->markTestSkipped( 'Requires process workers and an explicit disposable WordPress root.' );
		}
		$workers = array();
		$start   = (string) ( microtime( true ) + 2 );
		for ( $index = 0; $index < 8; ++$index ) {
			$pipes = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Test-only concurrency requires independent processes; availability is checked above.
			$process = proc_open(
				array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/budget-worker.php', $wpdb->prefix, $start, $mode, (string) $index ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			$this->assertIsResource( $process );
			$workers[] = array( $process, $pipes );
		}
		$accepted = 0;
		foreach ( $workers as list( $process, $pipes ) ) {
			$output = stream_get_contents( $pipes[1] );
			$error  = stream_get_contents( $pipes[2] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close a child process pipe, not a filesystem file.
			fclose( $pipes[1] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the child stderr pipe.
			fclose( $pipes[2] );
			$this->assertSame( 0, proc_close( $process ), $error );
			$this->assertMatchesRegularExpression( '/^\d+$/D', $output );
			$accepted += (int) $output;
		}
		return $accepted;
	}
}
