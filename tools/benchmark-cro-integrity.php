<?php
/** Reproducible migration/capacity test using only isolated synthetic CRO tables. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'FORMHAWK_BENCHMARK_DISPOSABLE' ) ) {
	throw new RuntimeException( 'An explicitly disposable WordPress site is required.' );
}

global $wpdb;
$formhawk_bench_prefix = $wpdb->prefix;
$wpdb->prefix         .= 'fh_cro_bench_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$formhawk_bench_tables = array(
	\Formhawk\Infrastructure\Database::cro_forms_table(),
	\Formhawk\Infrastructure\Database::experiments_table(),
	\Formhawk\Infrastructure\Database::variants_table(),
	\Formhawk\Infrastructure\Database::experiment_daily_table(),
	\Formhawk\Infrastructure\Database::optimization_history_table(),
	\Formhawk\Infrastructure\Database::cro_contexts_table(),
);
try {
	// These shipped v5 definitions of experiments/daily are unchanged in schema v6.
	if ( ! \Formhawk\Infrastructure\Migrations\Version5::run() ) {
		throw new RuntimeException( 'Isolated historical schema creation failed.' );
	}
	$formhawk_daily = \Formhawk\Infrastructure\Database::experiment_daily_table();
	for ( $formhawk_offset = 0; $formhawk_offset < 50000; $formhawk_offset += 500 ) {
		$formhawk_values = array();
		$formhawk_args   = array( $formhawk_daily );
		for ( $formhawk_index = $formhawk_offset; $formhawk_index < $formhawk_offset + 500; ++$formhawk_index ) {
			$formhawk_values[] = '(%d, 1, %s, %s, 100, 10)';
			array_push( $formhawk_args, $formhawk_index + 1, '2026-09-01', 'desktop' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic fragment repeats only literal placeholder groups; all synthetic values and the isolated table identifier are prepared.
		if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (experiment_id,variant_id,stat_date,segment,views,confirmed_successes) VALUES ' . implode( ',', $formhawk_values ), $formhawk_args ) ) ) {
			throw new RuntimeException( 'Synthetic daily insert failed.' );
		}
	}
	$formhawk_started = microtime( true );
	if ( ! \Formhawk\Infrastructure\Migrations\Version7::run() ) {
		throw new RuntimeException( 'Migration failed.' );
	}
	$formhawk_migration = microtime( true ) - $formhawk_started;
	$formhawk_started   = microtime( true );
	if ( ! \Formhawk\Infrastructure\Migrations\Version7::run() ) {
		throw new RuntimeException( 'Repeated migration failed.' );
	}
	$formhawk_repeat = microtime( true ) - $formhawk_started;
	$formhawk_totals = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) n,SUM(views) views,SUM(confirmed_successes) successes,SUM(assignments) assignments FROM %i', $formhawk_daily ), ARRAY_A );
	if ( array( 50000, 5000000, 500000, 0 ) !== array_values( array_map( 'intval', $formhawk_totals ) ) ) {
		throw new RuntimeException( 'Historical evidence was changed.' );
	}
	$formhawk_contexts = \Formhawk\Infrastructure\Database::cro_contexts_table();
	// Fill exactly the hard cap with synthetic hashes, not real identifiers.
	if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (context_hash,experiment_id,variant_id,form_id,segment,issued_at_utc,expires_at_utc) SELECT SHA2(CONCAT(%s,id),256),experiment_id,variant_id,1,segment,%s,%s FROM %i', $formhawk_contexts, 'synthetic-capacity-', gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + 7200 ), $formhawk_daily ) ) ) {
		throw new RuntimeException( 'Synthetic capacity fill failed.' );
	}
	$formhawk_signer  = new \Formhawk\CRO\Attribution\ContextSigner();
	$formhawk_context = $formhawk_signer->verify(
		$formhawk_signer->sign(
			array(
				'experiment_id'    => 1,
				'variant_id'       => 1,
				'form_id'          => 1,
				'provider'         => 'cf7',
				'provider_form_id' => '1',
				'segment'          => 'desktop',
			)
		)
	);
	$formhawk_store   = new \Formhawk\CRO\Attribution\ContextStore();
	if ( $formhawk_store->issue( $formhawk_context ) || $formhawk_store->is_issued( $formhawk_context ) ) {
		throw new RuntimeException( 'Context capacity was exceeded.' );
	}
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at_utc=%s', $formhawk_contexts, gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
	$formhawk_started = microtime( true );
	if ( 5000 !== \Formhawk\CRO\Attribution\ContextStore::cleanup() ) {
		throw new RuntimeException( 'Expiry cleanup was not bounded to 5000 rows.' );
	}
	$formhawk_cleanup = microtime( true ) - $formhawk_started;
	if ( ! $formhawk_store->issue( $formhawk_context ) ) {
		throw new RuntimeException( 'Issuance did not recover after expiry cleanup.' );
	}
	for ( $formhawk_index = 0; $formhawk_index < 10; ++$formhawk_index ) {
		\Formhawk\CRO\Attribution\ContextStore::cleanup();
	}
	if ( '1' !== $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $formhawk_contexts ) ) || ! $formhawk_store->is_issued( $formhawk_context ) ) {
		throw new RuntimeException( 'Expired contexts remain or active context was removed.' );
	}
	echo wp_json_encode(
		array(
			'aggregate_rows'                => 50000,
			'context_capacity'              => 50000,
			'migration_seconds'             => round( $formhawk_migration, 3 ),
			'repeat_seconds'                => round( $formhawk_repeat, 3 ),
			'cleanup_5000_seconds'          => round( $formhawk_cleanup, 3 ),
			'historical_evidence_preserved' => true,
			'capacity_and_expiry_verified'  => true,
			'peak_memory_bytes'             => memory_get_peak_usage( true ),
		)
	) . "\n";
} finally {
	foreach ( $formhawk_bench_tables as $formhawk_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $formhawk_table ) );
	}
	$wpdb->prefix = $formhawk_bench_prefix;
}
