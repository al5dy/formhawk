<?php
/** Run via WP-CLI on an explicitly disposable site; creates only random temporary benchmark tables. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'FORMHAWK_BENCHMARK_DISPOSABLE' ) ) {
	throw new RuntimeException( 'This benchmark requires an explicitly disposable WordPress site.' );
}

global $wpdb;
$formhawk_bench_suffix  = strtolower( wp_generate_password( 8, false, false ) );
$formhawk_bench_base    = $wpdb->prefix . 'fh_roi_bench_' . $formhawk_bench_suffix;
$formhawk_bench_tables  = array(
	'submissions'       => $formhawk_bench_base . '_submissions',
	'outcomes'          => $formhawk_bench_base . '_outcomes',
	'submission_fields' => $formhawk_bench_base . '_submission_fields',
	'field_daily'       => $formhawk_bench_base . '_field_daily',
);
$formhawk_bench_results = array();

try {
	$formhawk_collate = $wpdb->get_charset_collate();
	$formhawk_schema  = array(
		"CREATE TABLE {$formhawk_bench_tables['submissions']} (id bigint unsigned NOT NULL AUTO_INCREMENT, public_id varchar(64) NOT NULL, form_id bigint unsigned NOT NULL, experiment_id bigint unsigned NOT NULL, variant_id bigint unsigned NOT NULL, status varchar(24) NOT NULL, stat_date date NOT NULL, submitted_at_utc datetime NOT NULL, mature_after_utc datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY public_id(public_id), KEY form_submitted(form_id,stat_date,submitted_at_utc), KEY experiment_variant(experiment_id,variant_id,stat_date), KEY stat_date(stat_date,id)) ENGINE=InnoDB {$formhawk_collate}",
		"CREATE TABLE {$formhawk_bench_tables['outcomes']} (id bigint unsigned NOT NULL AUTO_INCREMENT, submission_id bigint unsigned NOT NULL, outcome_type varchar(24) NOT NULL, value_minor bigint DEFAULT NULL, currency char(3) DEFAULT NULL, idempotency_hash char(64) NOT NULL, occurred_at_utc datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY idempotency_hash(idempotency_hash), KEY submission_occurred(submission_id,occurred_at_utc,id)) ENGINE=InnoDB {$formhawk_collate}",
		"CREATE TABLE {$formhawk_bench_tables['submission_fields']} (submission_id bigint unsigned NOT NULL, field_definition_id bigint unsigned NOT NULL, PRIMARY KEY(submission_id,field_definition_id), KEY field_submission(field_definition_id,submission_id)) ENGINE=InnoDB {$formhawk_collate}",
		"CREATE TABLE {$formhawk_bench_tables['field_daily']} (id bigint unsigned NOT NULL AUTO_INCREMENT, field_definition_id bigint unsigned NOT NULL, stat_date date NOT NULL, cohort varchar(24) NOT NULL, experiment_id bigint unsigned NOT NULL, variant_id bigint unsigned NOT NULL, currency char(3) NOT NULL, submissions bigint unsigned NOT NULL, qualified bigint unsigned NOT NULL, won bigint unsigned NOT NULL, unknown_outcomes bigint unsigned NOT NULL, revenue_minor bigint NOT NULL, revenue_samples bigint unsigned NOT NULL, PRIMARY KEY(id), UNIQUE KEY dimensions(field_definition_id,stat_date,cohort,experiment_id,variant_id,currency), KEY date_field(stat_date,field_definition_id)) ENGINE=InnoDB {$formhawk_collate}",
	);
	foreach ( $formhawk_schema as $formhawk_statement ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Explicit randomly named disposable benchmark tables.
		if ( false === $wpdb->query( $formhawk_statement ) ) {
			throw new RuntimeException( 'Could not create Field ROI benchmark schema.' );
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Connection-scoped disposable sequence table.
	$wpdb->query( 'CREATE TEMPORARY TABLE formhawk_roi_bench_sequence (n int unsigned NOT NULL PRIMARY KEY)' );
	$formhawk_values = array();
	for ( $formhawk_index = 0; $formhawk_index < 1000; ++$formhawk_index ) {
		$formhawk_values[] = '(' . $formhawk_index . ')';
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Values are generated integers from a fixed local loop.
	$wpdb->query( 'INSERT INTO formhawk_roi_bench_sequence (n) VALUES ' . implode( ',', $formhawk_values ) );

	$formhawk_current = 0;
	foreach ( array( 10000, 100000, 1000000 ) as $formhawk_target ) {
		$formhawk_insert_started = microtime( true );
		$formhawk_insert_sql     = $wpdb->prepare(
			"INSERT INTO %i (public_id,form_id,experiment_id,variant_id,status,stat_date,submitted_at_utc,mature_after_utc)
			SELECT CONCAT('fh_bench_',LPAD(x.n,10,'0')),1,1,IF(MOD(x.n,2)=0,1,2),IF(MOD(x.n,20)=0,'won',IF(MOD(x.n,3)=0,'qualified','lost')),DATE_SUB(UTC_DATE(),INTERVAL MOD(x.n,90) DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL MOD(x.n,90) DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)
			FROM (SELECT a.n + 1000*b.n n FROM formhawk_roi_bench_sequence a CROSS JOIN formhawk_roi_bench_sequence b) x WHERE x.n >= %d AND x.n < %d",
			$formhawk_bench_tables['submissions'],
			$formhawk_current,
			$formhawk_target
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared isolated synthetic insert.
		if ( false === $wpdb->query( $formhawk_insert_sql ) ) {
			throw new RuntimeException( 'Synthetic submission insert failed.' );
		}
		$formhawk_outcome_sql = $wpdb->prepare(
			"INSERT INTO %i (submission_id,outcome_type,value_minor,currency,idempotency_hash,occurred_at_utc)
			SELECT id,status,IF(status='won',10000,NULL),IF(status='won','USD',NULL),LPAD(id,64,'0'),submitted_at_utc FROM %i WHERE id > %d AND id <= %d",
			$formhawk_bench_tables['outcomes'],
			$formhawk_bench_tables['submissions'],
			$formhawk_current,
			$formhawk_target
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared isolated synthetic insert.
		$wpdb->query( $formhawk_outcome_sql );
		$formhawk_fields_sql = $wpdb->prepare( 'INSERT INTO %i (submission_id,field_definition_id) SELECT id,1 FROM %i WHERE id > %d AND id <= %d', $formhawk_bench_tables['submission_fields'], $formhawk_bench_tables['submissions'], $formhawk_current, $formhawk_target );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared isolated synthetic insert.
		$wpdb->query( $formhawk_fields_sql );
		$formhawk_insert_seconds = microtime( true ) - $formhawk_insert_started;

		$formhawk_aggregate_started = microtime( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared isolated projection reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $formhawk_bench_tables['field_daily'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Connection-local benchmark work table.
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS formhawk_roi_bench_outcome_work' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Connection-local benchmark work table.
		$wpdb->query( 'CREATE TEMPORARY TABLE formhawk_roi_bench_outcome_work (submission_id bigint unsigned NOT NULL,currency char(3) DEFAULT NULL,qualified tinyint unsigned NOT NULL,known tinyint unsigned NOT NULL,revenue_minor bigint NOT NULL,revenue_samples tinyint unsigned NOT NULL,PRIMARY KEY(submission_id)) ENGINE=InnoDB' );
		$formhawk_work_sql = $wpdb->prepare(
			"INSERT INTO formhawk_roi_bench_outcome_work (submission_id,currency,qualified,known,revenue_minor,revenue_samples)
			SELECT s.id,MAX(o.currency),MAX(o.outcome_type='qualified'),MAX(o.outcome_type IN ('qualified','unqualified','won','lost','spam','duplicate')),SUM(CASE WHEN o.outcome_type IN ('won','value_adjustment') THEN COALESCE(o.value_minor,0) ELSE 0 END),MAX(CASE WHEN o.outcome_type IN ('won','value_adjustment') AND o.value_minor IS NOT NULL THEN 1 ELSE 0 END)
			FROM %i s FORCE INDEX (stat_date) LEFT JOIN %i o ON o.submission_id=s.id WHERE s.stat_date=UTC_DATE() GROUP BY s.id",
			$formhawk_bench_tables['submissions'],
			$formhawk_bench_tables['outcomes']
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared isolated daily outcome projection.
		$wpdb->query( $formhawk_work_sql );
		$formhawk_aggregate_sql = $wpdb->prepare(
			"INSERT INTO %i (field_definition_id,stat_date,cohort,experiment_id,variant_id,currency,submissions,qualified,won,unknown_outcomes,revenue_minor,revenue_samples)
			SELECT sf.field_definition_id,s.stat_date,'present',s.experiment_id,s.variant_id,COALESCE(oa.currency,'XXX'),COUNT(*),SUM(oa.qualified),SUM(s.status='won'),SUM(oa.known=0),SUM(oa.revenue_minor),SUM(oa.revenue_samples)
			FROM %i s FORCE INDEX (stat_date) STRAIGHT_JOIN %i sf ON sf.submission_id=s.id LEFT JOIN formhawk_roi_bench_outcome_work oa ON oa.submission_id=s.id
			WHERE s.stat_date=UTC_DATE() GROUP BY sf.field_definition_id,s.stat_date,s.experiment_id,s.variant_id,COALESCE(oa.currency,'XXX')",
			$formhawk_bench_tables['field_daily'],
			$formhawk_bench_tables['submissions'],
			$formhawk_bench_tables['submission_fields']
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared isolated aggregate benchmark.
		if ( false === $wpdb->query( $formhawk_aggregate_sql ) ) {
			throw new RuntimeException( 'Synthetic Field ROI aggregation failed.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes only the connection-local benchmark table.
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS formhawk_roi_bench_outcome_work' );
		$formhawk_aggregate_seconds = microtime( true ) - $formhawk_aggregate_started;
		$formhawk_dashboard_started = microtime( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Isolated indexed dashboard-equivalent query.
		$formhawk_dashboard         = $wpdb->get_results( $wpdb->prepare( 'SELECT field_definition_id,SUM(submissions),SUM(qualified),SUM(won),SUM(revenue_minor) FROM %i WHERE stat_date BETWEEN DATE_SUB(UTC_DATE(),INTERVAL 89 DAY) AND UTC_DATE() GROUP BY field_definition_id LIMIT 200', $formhawk_bench_tables['field_daily'] ), ARRAY_A );
		$formhawk_dashboard_seconds = microtime( true ) - $formhawk_dashboard_started;
		$formhawk_bench_results[]   = array(
			'rows'                          => $formhawk_target,
			'incremental_insert_seconds'    => round( $formhawk_insert_seconds, 3 ),
			'daily_shard_aggregate_seconds' => round( $formhawk_aggregate_seconds, 3 ),
			'dashboard_seconds'             => round( $formhawk_dashboard_seconds, 6 ),
			'dashboard_rows'                => count( $formhawk_dashboard ),
		);
		$formhawk_current           = $formhawk_target;
	}
	echo wp_json_encode(
		array(
			'benchmarks'        => $formhawk_bench_results,
			'peak_memory_bytes' => memory_get_peak_usage( true ),
			'privacy_values'    => false,
		)
	) . "\n";
} finally {
	foreach ( array_reverse( $formhawk_bench_tables ) as $formhawk_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Removes only explicit randomly named benchmark tables.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $formhawk_table ) );
	}
}
