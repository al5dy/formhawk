<?php
/** Run via WP-CLI on an explicitly disposable site; see docs/HARDENING.md. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'FORMHAWK_BENCHMARK_DISPOSABLE' ) ) {
	throw new RuntimeException( 'This benchmark requires an explicitly disposable WordPress site.' );
}

global $wpdb;
$formhawk_bench_prefix = $wpdb->prefix;
$wpdb->prefix .= 'fh_bench_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$formhawk_bench_tables = array(
	'forms' => \Formhawk\Infrastructure\Database::forms_table(),
	'daily' => \Formhawk\Infrastructure\Database::daily_table(),
	'fields' => \Formhawk\Infrastructure\Database::fields_table(),
	'placements' => \Formhawk\Infrastructure\Database::placements_table(),
	'placement_daily' => \Formhawk\Infrastructure\Database::placement_daily_table(),
);
$formhawk_bench_rows = 36500;
try {
	$formhawk_replacements = array( '{charset_collate}' => $wpdb->get_charset_collate() );
	foreach ( $formhawk_bench_tables as $formhawk_key => $formhawk_table ) {
		$formhawk_replacements[ '{' . $formhawk_key . '}' ] = $formhawk_table;
	}
	$formhawk_schema = strtr( file_get_contents( dirname( __DIR__ ) . '/tests/Fixtures/schema-v3.sql' ), $formhawk_replacements );
	foreach ( explode( ';', $formhawk_schema ) as $formhawk_query ) {
		if ( trim( $formhawk_query ) && false === $wpdb->query( $formhawk_query ) ) {
			throw new RuntimeException( 'Could not create the isolated benchmark schema.' );
		}
	}
	for ( $formhawk_index = 1; $formhawk_index <= 100; ++$formhawk_index ) {
		( new \Formhawk\Analytics\FormRepository() )->resolve( array( 'provider' => 'cf7', 'provider_form_id' => (string) $formhawk_index, 'page_path' => '/benchmark' ) );
	}
	// Bound synthetic inserts to 500 rows; the benchmark never copies customer analytics.
	for ( $formhawk_offset = 0; $formhawk_offset < $formhawk_bench_rows; $formhawk_offset += 500 ) {
		$formhawk_values = array();
		$formhawk_args = array();
		for ( $formhawk_index = $formhawk_offset; $formhawk_index < min( $formhawk_offset + 500, $formhawk_bench_rows ); ++$formhawk_index ) {
			$formhawk_values[] = '(%d, %s, 7, 3)';
			array_push( $formhawk_args, 1 + (int) floor( $formhawk_index / 365 ), gmdate( 'Y-m-d', strtotime( '2025-01-01 UTC' ) + ( $formhawk_index % 365 ) * DAY_IN_SECONDS ) );
		}
		foreach ( array( 'daily' => 'form_id', 'placement_daily' => 'placement_id' ) as $formhawk_kind => $formhawk_identity ) {
			$formhawk_query = 'INSERT INTO %i (%i, stat_date, submissions, validation_failures) VALUES ' . implode( ',', $formhawk_values );
			if ( false === $wpdb->query( $wpdb->prepare( $formhawk_query, array_merge( array( $formhawk_bench_tables[ $formhawk_kind ], $formhawk_identity ), $formhawk_args ) ) ) ) {
				throw new RuntimeException( 'Synthetic aggregate insert failed.' );
			}
		}
	}
	$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (form_id, stat_date, field_key, validation_errors) SELECT form_id, stat_date, %s, 4 FROM %i', $formhawk_bench_tables['fields'], 'email', $formhawk_bench_tables['daily'] ) );
	$formhawk_started = microtime( true );
	$formhawk_passes = 0;
	do {
		$formhawk_complete = \Formhawk\Infrastructure\Migrations\Version4::run();
		if ( ++$formhawk_passes > 1000 ) {
			throw new RuntimeException( 'Migration failed to complete bounded passes.' );
		}
	} while ( ! $formhawk_complete );
	$formhawk_elapsed = microtime( true ) - $formhawk_started;
	$formhawk_started = microtime( true );
	if ( ! \Formhawk\Infrastructure\Migrations\Version4::run() ) {
		throw new RuntimeException( 'Repeated migration failed verification.' );
	}
	$formhawk_repeat = microtime( true ) - $formhawk_started;
	$formhawk_totals = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS rows_count, SUM(submissions) AS legacy, SUM(provider_validation_outcomes) AS new_outcomes FROM %i', $formhawk_bench_tables['daily'] ), ARRAY_A );
	if ( (int) $formhawk_totals['rows_count'] !== $formhawk_bench_rows || (int) $formhawk_totals['legacy'] !== 7 * $formhawk_bench_rows || (int) $formhawk_totals['new_outcomes'] !== 0 ) {
		throw new RuntimeException( 'Historical aggregate invariant failed.' );
	}
	echo wp_json_encode( array( 'rows_per_aggregate_table' => $formhawk_bench_rows, 'total_rows' => $formhawk_bench_rows * 3, 'migration_seconds' => round( $formhawk_elapsed, 3 ), 'repeat_seconds' => round( $formhawk_repeat, 3 ), 'peak_memory_bytes' => memory_get_peak_usage( true ), 'legacy_preserved' => true ) ) . "\n";
} finally {
	$formhawk_bench_tables[] = \Formhawk\Infrastructure\Database::budgets_table();
	$formhawk_bench_tables[] = \Formhawk\Infrastructure\Database::dimensions_table();
	foreach ( $formhawk_bench_tables as $formhawk_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $formhawk_table ) );
	}
	$wpdb->prefix = $formhawk_bench_prefix;
}
