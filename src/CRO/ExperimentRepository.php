<?php

namespace Formhawk\CRO;

use Formhawk\CRO\Experiments\ExperimentStatus;
use Formhawk\Infrastructure\Database;
use Formhawk\Support\Sanitizer;

final class ExperimentRepository {
	public function enable( $form_id, array $input = array() ) {
		global $wpdb;

		$form_id = absint( $form_id );
		if ( ! $form_id ) {
			return false;
		}
		$now      = current_time( 'mysql', true );
		$defaults = array(
			'mode'                     => 'approve',
			'state'                    => 'collecting',
			'aggressiveness'           => 'balanced',
			'optimization_objective'   => 'auto',
			'max_experimental_traffic' => 50,
			'min_duration_days'        => 7,
			'min_conversions'          => 50,
			'lead_value'               => null,
			'currency'                 => 'USD',
		);
		$data     = array_merge( $defaults, $this->normalize_settings( $input ) );
		$sql      = $wpdb->prepare(
			'INSERT INTO %i
				(form_id, mode, state, aggressiveness, optimization_objective, max_experimental_traffic, min_duration_days, min_conversions, lead_value, currency, baseline_json, previous_baseline_json, enabled_at_utc, updated_at_utc)
			VALUES (%d, %s, %s, %s, %s, %d, %d, %d, %s, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				mode = VALUES(mode), aggressiveness = VALUES(aggressiveness), optimization_objective = VALUES(optimization_objective),
				max_experimental_traffic = VALUES(max_experimental_traffic),
				min_duration_days = VALUES(min_duration_days), min_conversions = VALUES(min_conversions),
				lead_value = VALUES(lead_value), currency = VALUES(currency), updated_at_utc = VALUES(updated_at_utc)',
			Database::cro_forms_table(),
			$form_id,
			$data['mode'],
			$data['state'],
			$data['aggressiveness'],
			$data['optimization_objective'],
			$data['max_experimental_traffic'],
			$data['min_duration_days'],
			$data['min_conversions'],
			number_format( null === $data['lead_value'] ? 0 : $data['lead_value'], 2, '.', '' ),
			$data['currency'],
			'[]',
			'[]',
			$now,
			$now
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic settings upsert in Formhawk-owned storage.
		return false !== $wpdb->query( $sql );
	}

	public function disable( $form_id ) {
		global $wpdb;
		// Existing experiments/history are intentionally retained for auditability.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Narrow Formhawk settings deletion.
		return false !== $wpdb->delete( Database::cro_forms_table(), array( 'form_id' => absint( $form_id ) ), array( '%d' ) );
	}

	public function settings( $form_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d', Database::cro_forms_table(), absint( $form_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Current per-form Autopilot settings.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['baseline']          = $this->decode_json( $row['baseline_json'] );
		$row['previous_baseline'] = $this->decode_json( $row['previous_baseline_json'] );
		$row['lead_value']        = (float) $row['lead_value'] > 0 ? (float) $row['lead_value'] : null;
		return $row;
	}

	public function enabled_forms( $limit = 100 ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT c.*, f.provider, f.provider_form_id, f.title, f.page_path
			FROM %i c INNER JOIN %i f ON f.id = c.form_id
			ORDER BY COALESCE(c.last_evaluated_at_utc, c.enabled_at_utc) ASC LIMIT %d',
			Database::cro_forms_table(),
			Database::forms_table(),
			max( 1, min( 500, absint( $limit ) ) )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded background work queue.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function create( array $experiment, array $variants ) {
		global $wpdb;
		if ( count( $variants ) !== 2 ) {
			return 0;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Short atomic creation boundary for one experiment and two variants.
		$wpdb->query( 'START TRANSACTION' );
		$lock_sql = $wpdb->prepare( 'SELECT form_id FROM %i WHERE form_id = %d FOR UPDATE', Database::cro_forms_table(), absint( $experiment['form_id'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Serializes experiment creation for one enabled form.
		if ( ! $wpdb->get_var( $lock_sql ) || $this->active_for_form( $experiment['form_id'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- End rejected creation transaction.
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}
		foreach ( $variants as $variant ) {
			if ( strlen( (string) wp_json_encode( $variant['config'] ) ) > 16384 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- End oversized configuration transaction.
				$wpdb->query( 'ROLLBACK' );
				return 0;
			}
		}
		if ( strlen( (string) wp_json_encode( $experiment['policy'] ) ) > 16384 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- End oversized policy transaction.
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactional insert into Formhawk-owned experiment storage; object caching cannot preserve atomicity.
		$inserted      = $wpdb->insert(
			Database::experiments_table(),
			array(
				'form_id'           => absint( $experiment['form_id'] ),
				'type'              => sanitize_key( $experiment['type'] ),
				'status'            => sanitize_key( $experiment['status'] ),
				'hypothesis'        => sanitize_text_field( $experiment['hypothesis'] ),
				'primary_metric'    => sanitize_key( $experiment['primary_metric'] ),
				'evidence_level'    => sanitize_key( $experiment['evidence_level'] ),
				'segment_scope'     => 'all',
				'policy_json'       => wp_json_encode( $experiment['policy'] ),
				'algorithm_version' => StatisticalEngine::ALGORITHM_VERSION,
				'policy_version'    => OptimizationPolicy::VERSION,
				'created_at_utc'    => $now,
				'updated_at_utc'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$experiment_id = $inserted ? (int) $wpdb->insert_id : 0;
		if ( ! $experiment_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back partial experiment creation.
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}

		$variant_weight = min( 50, absint( $experiment['policy']['maximum_experimental_traffic'] ) );
		foreach ( $variants as $index => $variant ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactional insert into Formhawk-owned variant storage; object caching cannot preserve atomicity.
			$ok = $wpdb->insert(
				Database::variants_table(),
				array(
					'experiment_id'   => $experiment_id,
					'name'            => sanitize_text_field( $variant['name'] ),
					'mutation_type'   => sanitize_key( $variant['mutation_type'] ),
					'mutation_config' => wp_json_encode( $variant['config'] ),
					'traffic_weight'  => 0 === $index ? 100 - $variant_weight : $variant_weight,
					'status'          => 'active',
					'created_at_utc'  => $now,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( ! $ok ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back partial experiment creation.
				$wpdb->query( 'ROLLBACK' );
				return 0;
			}
		}
		if ( ! $this->commit_transaction() ) {
			return 0;
		}
		do_action( 'formhawk_cro_experiment_created', $experiment_id, absint( $experiment['form_id'] ) );
		return $experiment_id;
	}

	public function active_for_form( $form_id ) {
		global $wpdb;
		$statuses     = ExperimentStatus::active();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$query        = 'SELECT * FROM %i WHERE form_id = %d AND status IN (' . $placeholders . ') ORDER BY id DESC LIMIT 1';
		$args         = array_merge( array( Database::experiments_table(), absint( $form_id ) ), $statuses );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed placeholder list; values prepared below.
		$sql = $wpdb->prepare( $query, $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic fragment is a fixed placeholder list; all table/status values were prepared above.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $this->hydrate_experiment( $row ) : null;
	}

	public function find( $experiment_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Database::experiments_table(), absint( $experiment_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Primary-key experiment lookup.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $this->hydrate_experiment( $row ) : null;
	}

	public function variants( $experiment_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE experiment_id = %d ORDER BY id ASC', Database::variants_table(), absint( $experiment_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Two-row variant lookup.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['config'] = $this->decode_json( $row['mutation_config'] );
		}
		return $rows;
	}

	public function runtime_for_identity( $provider, $provider_form_id, $page_path ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT id FROM %i WHERE provider = %s AND provider_form_id = %s AND (%s <> %s OR page_path = %s) ORDER BY id DESC LIMIT 1',
			Database::forms_table(),
			sanitize_key( $provider ),
			Sanitizer::identifier( $provider_form_id ),
			sanitize_key( $provider ),
			'html',
			sanitize_text_field( (string) $page_path )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read-only lookup for a bounded public config request.
		$form_id  = absint( $wpdb->get_var( $sql ) );
		$settings = $form_id ? $this->settings( $form_id ) : null;
		if ( ! $form_id || ! $settings ) {
			return null;
		}
		$experiment = $this->active_for_form( $form_id );
		if ( ! $experiment || ! in_array( $experiment['status'], ExperimentStatus::runtime(), true ) ) {
			if ( empty( $settings['baseline'] ) ) {
				return null;
			}
			return array(
				'form_id'    => $form_id,
				'deployment' => true,
				'config'     => array( 'mutations' => $settings['baseline'] ),
			);
		}
		return array(
			'form_id'    => $form_id,
			'experiment' => $experiment,
			'variants'   => $this->variants( $experiment['id'] ),
		);
	}

	public function has_runtime_on_path( $page_path ) {
		global $wpdb;
		$statuses = ExperimentStatus::runtime();
		$sql      = $wpdb->prepare(
			'SELECT p.id FROM %i p
			INNER JOIN %i c ON c.form_id = p.form_id
			LEFT JOIN %i e ON e.form_id = p.form_id AND e.status IN (%s, %s)
			WHERE p.page_path = %s AND (e.id IS NOT NULL OR c.baseline_json <> %s) LIMIT 1',
			Database::placements_table(),
			Database::cro_forms_table(),
			Database::experiments_table(),
			$statuses[0],
			$statuses[1],
			sanitize_text_field( (string) $page_path ),
			'[]'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Indexed current-path runtime gate avoids loading CRO assets elsewhere.
		return (bool) $wpdb->get_var( $sql );
	}

	public function set_status( $experiment_id, $status, array $extra = array() ) {
		global $wpdb;
		if ( ! in_array( $status, ExperimentStatus::all(), true ) ) {
			return false;
		}
		$data    = array_merge(
			array(
				'status'         => $status,
				'updated_at_utc' => current_time( 'mysql', true ),
			),
			$extra
		);
		$formats = array();
		foreach ( array_keys( $data ) as $key ) {
			$formats[] = in_array( $key, array( 'winner_variant_id', 'baseline_experiment_id' ), true ) ? '%d' : '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Narrow experiment state transition.
		return false !== $wpdb->update( Database::experiments_table(), $data, array( 'id' => absint( $experiment_id ) ), $formats, array( '%d' ) );
	}

	public function start( $experiment_id ) {
		global $wpdb;

		$experiment_id = absint( $experiment_id );
		$now           = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Short state transition in Formhawk-owned storage.
		$wpdb->query( 'START TRANSACTION' );
		$sql = $wpdb->prepare( 'SELECT status, policy_json FROM %i WHERE id = %d FOR UPDATE', Database::experiments_table(), $experiment_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Locked experiment controls its own allocation.
		$experiment = $wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $experiment ) || ! in_array( $experiment['status'], array( ExperimentStatus::SUGGESTED, ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::PAUSED_MANUAL ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Abort invalid or duplicate start.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$policy         = $this->decode_json( $experiment['policy_json'] );
		$variant_weight = min( 50, max( 10, absint( $policy['maximum_experimental_traffic'] ?? 50 ) ) );
		$variants       = $this->variants( $experiment_id );
		if ( count( $variants ) !== 2 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Corrupt experiment cannot start.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$traffic_sql = $wpdb->prepare(
			'UPDATE %i SET traffic_weight = CASE WHEN id = %d THEN %d WHEN id = %d THEN %d ELSE 0 END WHERE experiment_id = %d',
			Database::variants_table(),
			absint( $variants[0]['id'] ),
			100 - $variant_weight,
			absint( $variants[1]['id'] ),
			$variant_weight,
			$experiment_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Allocation and status change commit together.
		$traffic_changed = $wpdb->query( $traffic_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked experiment state update.
		$status_changed = $wpdb->update(
			Database::experiments_table(),
			array(
				'status'         => ExperimentStatus::RUNNING,
				'started_at_utc' => $now,
				'updated_at_utc' => $now,
			),
			array( 'id' => $experiment_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $traffic_changed || false === $status_changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No partial resume survives.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( ! $this->commit_transaction() ) {
			return false;
		}
		do_action( 'formhawk_cro_experiment_started', $experiment_id );
		return true;
	}

	public function aggregate( $experiment_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT variant_id,
				SUM(views) AS views, SUM(starts) AS starts, SUM(attempts) AS attempts,
				SUM(confirmed_successes) AS confirmed_successes, SUM(observed_submits) AS observed_submits, SUM(abandonments) AS abandonments,
				SUM(client_validation_failures) AS client_validation_failures,
				SUM(provider_validation_failures) AS provider_validation_failures,
				SUM(provider_failures) AS provider_failures, SUM(mail_failures) AS mail_failures,
				SUM(js_errors) AS js_errors, SUM(latency_total_ms) AS latency_total_ms,
				SUM(latency_samples) AS latency_samples
			FROM %i WHERE experiment_id = %d GROUP BY variant_id ORDER BY variant_id ASC',
			Database::experiment_daily_table(),
			absint( $experiment_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Aggregate decision query over bounded two-arm experiment rows.
		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$indexed = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$indexed[ absint( $row['variant_id'] ) ] = array_map( 'absint', $row );
		}
		return $indexed;
	}

	public function aggregate_since( $experiment_id, $variant_id, $stat_date ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT SUM(views) AS views, SUM(starts) AS starts, SUM(attempts) AS attempts,
				SUM(confirmed_successes) AS confirmed_successes, SUM(observed_submits) AS observed_submits,
				SUM(abandonments) AS abandonments, SUM(client_validation_failures) AS client_validation_failures,
				SUM(provider_validation_failures) AS provider_validation_failures,
				SUM(provider_failures) AS provider_failures, SUM(mail_failures) AS mail_failures,
				SUM(js_errors) AS js_errors, SUM(latency_total_ms) AS latency_total_ms,
				SUM(latency_samples) AS latency_samples
			FROM %i WHERE experiment_id = %d AND variant_id = %d AND stat_date >= %s',
			Database::experiment_daily_table(),
			absint( $experiment_id ),
			absint( $variant_id ),
			sanitize_text_field( $stat_date )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Promotion monitoring aggregate.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? array_map( 'absint', $row ) : array();
	}

	public function aggregate_by_segment( $experiment_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT variant_id, segment, SUM(views) AS views, SUM(starts) AS starts, SUM(confirmed_successes) AS confirmed_successes,
				SUM(observed_submits) AS observed_submits, SUM(provider_validation_failures) AS provider_validation_failures,
				SUM(client_validation_failures) AS client_validation_failures,
				SUM(provider_failures) AS provider_failures, SUM(mail_failures) AS mail_failures, SUM(js_errors) AS js_errors,
				SUM(latency_total_ms) AS latency_total_ms, SUM(latency_samples) AS latency_samples
			FROM %i WHERE experiment_id = %d GROUP BY variant_id, segment',
			Database::experiment_daily_table(),
			absint( $experiment_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Two-variant, two-segment guardrail query.
		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		$output = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$segment = in_array( $row['segment'], array( 'desktop', 'mobile' ), true ) ? $row['segment'] : 'desktop';
			$output[ $segment ][ absint( $row['variant_id'] ) ] = array_map( 'absint', $row );
		}
		return $output;
	}

	public function route_to_variant( $experiment_id, $variant_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'UPDATE %i SET traffic_weight = CASE WHEN id = %d THEN 100 ELSE 0 END WHERE experiment_id = %d',
			Database::variants_table(),
			absint( $variant_id ),
			absint( $experiment_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic two-arm traffic transition.
		return false !== $wpdb->query( $sql );
	}

	public function route_to_control( $experiment_id ) {
		$variants = $this->variants( $experiment_id );
		return isset( $variants[0]['id'] ) ? $this->route_to_variant( $experiment_id, $variants[0]['id'] ) : false;
	}

	public function increment( $experiment_id, $variant_id, $segment, array $increments ) {
		global $wpdb;
		$allowed    = array( 'views', 'starts', 'attempts', 'confirmed_successes', 'observed_submits', 'abandonments', 'client_validation_failures', 'provider_validation_failures', 'provider_failures', 'mail_failures', 'js_errors', 'latency_total_ms', 'latency_samples' );
		$increments = array_intersect_key( $increments, array_flip( $allowed ) );
		if ( ! $increments || ! in_array( $segment, array( 'desktop', 'mobile' ), true ) ) {
			return false;
		}
		$columns = array_keys( $increments );
		$args    = array_merge( array( Database::experiment_daily_table() ), $columns, array( absint( $experiment_id ), absint( $variant_id ), current_time( 'Y-m-d' ), $segment ), array_map( 'absint', array_values( $increments ) ) );
		$updates = array();
		foreach ( $columns as $column ) {
			$updates[] = '%i = %i + VALUES(%i)';
			array_push( $args, $column, $column, $column );
		}
		$query = 'INSERT INTO %i (experiment_id, variant_id, stat_date, segment, ' . implode( ', ', array_fill( 0, count( $columns ), '%i' ) )
			. ') VALUES (%d, %d, %s, %s, ' . implode( ', ', array_fill( 0, count( $columns ), '%d' ) ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed fragments with allowlisted columns; identifiers and values are prepared.
		$sql = $wpdb->prepare( $query, $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic aggregate counter upsert, prepared above.
		return false !== $wpdb->query( $sql );
	}

	public function promote_baseline( $form_id, array $baseline ) {
		global $wpdb;
		$current = $this->settings( $form_id );
		if ( ! $current ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic baseline pointer update; original provider form is untouched.
		return false !== $wpdb->update(
			Database::cro_forms_table(),
			array(
				'previous_baseline_json' => wp_json_encode( $current['baseline'] ),
				'baseline_json'          => wp_json_encode( array_values( $baseline ) ),
				'state'                  => 'monitoring',
				'updated_at_utc'         => current_time( 'mysql', true ),
			),
			array( 'form_id' => absint( $form_id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Atomically deploys a winner without ever editing the provider form. */
	public function promote_experiment( $form_id, $experiment_id, $variant_id, array $baseline ) {
		global $wpdb;

		$form_id       = absint( $form_id );
		$experiment_id = absint( $experiment_id );
		$variant_id    = absint( $variant_id );
		$now           = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Short state-transition transaction over Formhawk-owned rows.
		$wpdb->query( 'START TRANSACTION' );
		$form_sql = $wpdb->prepare( 'SELECT baseline_json FROM %i WHERE form_id = %d FOR UPDATE', Database::cro_forms_table(), $form_id );
		$exp_sql  = $wpdb->prepare( 'SELECT status, winner_variant_id FROM %i WHERE id = %d AND form_id = %d FOR UPDATE', Database::experiments_table(), $experiment_id, $form_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Rows are locked for the atomic promotion transition.
		$current_baseline = $wpdb->get_var( $form_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Rows are locked for the atomic promotion transition.
		$experiment  = $wpdb->get_row( $exp_sql, ARRAY_A );
		$variant_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d AND experiment_id = %d AND status = %s', Database::variants_table(), $variant_id, $experiment_id, 'active' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Verifies the winner belongs to the locked experiment.
		$variant_exists = 1 === absint( $wpdb->get_var( $variant_sql ) );
		if ( null === $current_baseline || ! is_array( $experiment ) || ! $variant_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Abort invalid promotion transition.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( ExperimentStatus::PROMOTED_MONITORING === $experiment['status'] && absint( $experiment['winner_variant_id'] ) === $variant_id ) {
			return $this->commit_transaction();
		}
		if ( ! in_array( $experiment['status'], array( ExperimentStatus::RUNNING, ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::SUGGESTED, ExperimentStatus::PAUSED_MANUAL ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reject illegal state transition.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional state update in Formhawk-owned storage.
		$settings_changed = $wpdb->update(
			Database::cro_forms_table(),
			array(
				'previous_baseline_json' => $current_baseline,
				'baseline_json'          => wp_json_encode( array_values( $baseline ) ),
				'state'                  => 'monitoring',
				'updated_at_utc'         => $now,
			),
			array( 'form_id' => $form_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		$traffic_sql      = $wpdb->prepare( 'UPDATE %i SET traffic_weight = CASE WHEN id = %d THEN 100 ELSE 0 END WHERE experiment_id = %d', Database::variants_table(), $variant_id, $experiment_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Traffic changes inside the promotion transaction.
		$traffic_changed = $wpdb->query( $traffic_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional experiment update in Formhawk-owned storage.
		$experiment_changed = $wpdb->update(
			Database::experiments_table(),
			array(
				'status'            => ExperimentStatus::PROMOTED_MONITORING,
				'winner_variant_id' => $variant_id,
				'ended_at_utc'      => $now,
				'updated_at_utc'    => $now,
			),
			array( 'id' => $experiment_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $settings_changed || false === $traffic_changed || false === $experiment_changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No partial winner deployment survives.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		return $this->commit_transaction();
	}

	public function rollback_baseline( $form_id ) {
		$current = $this->settings( $form_id );
		if ( ! $current ) {
			return false;
		}
		return $this->set_baselines( $form_id, $current['previous_baseline'], $current['baseline'], 'active' );
	}

	/** Atomically restores the prior baseline and closes its promoted experiment. */
	public function rollback_experiment( $form_id, $experiment_id ) {
		global $wpdb;

		$form_id       = absint( $form_id );
		$experiment_id = absint( $experiment_id );
		$now           = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Short rollback transaction over Formhawk-owned rows.
		$wpdb->query( 'START TRANSACTION' );
		$form_sql = $wpdb->prepare( 'SELECT baseline_json, previous_baseline_json FROM %i WHERE form_id = %d FOR UPDATE', Database::cro_forms_table(), $form_id );
		$exp_sql  = $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d AND form_id = %d FOR UPDATE', Database::experiments_table(), $experiment_id, $form_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Locked baseline ancestry for rollback.
		$settings = $wpdb->get_row( $form_sql, ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Locked experiment for rollback.
		$status = $wpdb->get_var( $exp_sql );
		if ( ! is_array( $settings ) || ! $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Abort invalid rollback.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( ExperimentStatus::ROLLED_BACK === $status ) {
			return $this->commit_transaction();
		}
		if ( ! in_array( $status, array( ExperimentStatus::PROMOTED_MONITORING, ExperimentStatus::COMPLETED ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only a monitored promotion has rollback ancestry.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$history_sql = $wpdb->prepare(
			'SELECT previous_baseline_json, resulting_baseline_json FROM %i WHERE experiment_id = %d AND decision IN (%s, %s) ORDER BY id DESC LIMIT 1',
			Database::optimization_history_table(),
			$experiment_id,
			'winner',
			'manual_promote'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Immutable decision ancestry identifies the exact rollback target.
		$history = $wpdb->get_row( $history_sql, ARRAY_A );
		$target  = $settings['previous_baseline_json'];
		if ( is_array( $history ) && $this->decode_json( $history['resulting_baseline_json'] ) === $this->decode_json( $settings['baseline_json'] ) ) {
			$target = $history['previous_baseline_json'];
		} elseif ( ExperimentStatus::COMPLETED === $status ) {
			// A completed decision without matching immutable ancestry is unsafe to infer.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Abort ambiguous historical rollback.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional state update in Formhawk-owned storage.
		$settings_changed = $wpdb->update(
			Database::cro_forms_table(),
			array(
				'baseline_json'          => $target,
				'previous_baseline_json' => $settings['baseline_json'],
				'state'                  => 'active',
				'updated_at_utc'         => $now,
			),
			array( 'form_id' => $form_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional experiment update in Formhawk-owned storage.
		$experiment_changed = $wpdb->update(
			Database::experiments_table(),
			array(
				'status'         => ExperimentStatus::ROLLED_BACK,
				'ended_at_utc'   => $now,
				'updated_at_utc' => $now,
			),
			array( 'id' => $experiment_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $settings_changed || false === $experiment_changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserve currently deployed winner on failed rollback.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		return $this->commit_transaction();
	}

	public function latest_rollback_candidate( $form_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT e.* FROM %i e
			INNER JOIN %i c ON c.form_id = e.form_id
			INNER JOIN %i h ON h.experiment_id = e.id AND h.decision IN (%s, %s) AND h.resulting_baseline_json = c.baseline_json
			LEFT JOIN %i r ON r.experiment_id = e.id AND r.decision IN (%s, %s)
			WHERE e.form_id = %d AND e.winner_variant_id IS NOT NULL AND e.status IN (%s, %s) AND r.id IS NULL
			ORDER BY h.id DESC LIMIT 1',
			Database::experiments_table(),
			Database::cro_forms_table(),
			Database::optimization_history_table(),
			'winner',
			'manual_promote',
			Database::optimization_history_table(),
			'rollback',
			'manual_rollback',
			absint( $form_id ),
			ExperimentStatus::PROMOTED_MONITORING,
			ExperimentStatus::COMPLETED
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One latest deployable baseline ancestry row.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $this->hydrate_experiment( $row ) : null;
	}

	public function history( $form_id, $limit = 50 ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d ORDER BY id DESC LIMIT %d', Database::optimization_history_table(), absint( $form_id ), max( 1, min( 100, absint( $limit ) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded immutable history lookup.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function add_history( array $record ) {
		global $wpdb;
		// History rows are append-only; no update method exists by design.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Immutable Formhawk decision record.
		return false !== $wpdb->insert(
			Database::optimization_history_table(),
			array(
				'form_id'                 => absint( $record['form_id'] ),
				'experiment_id'           => absint( $record['experiment_id'] ),
				'decision'                => sanitize_key( $record['decision'] ),
				'lift'                    => isset( $record['lift'] ) ? $record['lift'] : null,
				'probability'             => isset( $record['probability'] ) ? $record['probability'] : null,
				'expected_loss'           => isset( $record['expected_loss'] ) ? $record['expected_loss'] : null,
				'previous_baseline_json'  => wp_json_encode( isset( $record['previous_baseline'] ) ? $record['previous_baseline'] : array() ),
				'resulting_baseline_json' => wp_json_encode( isset( $record['resulting_baseline'] ) ? $record['resulting_baseline'] : array() ),
				'algorithm_version'       => isset( $record['algorithm_version'] ) ? sanitize_text_field( $record['algorithm_version'] ) : StatisticalEngine::ALGORITHM_VERSION,
				'policy_version'          => isset( $record['policy_version'] ) ? sanitize_text_field( $record['policy_version'] ) : OptimizationPolicy::VERSION,
				'reason'                  => sanitize_text_field( isset( $record['reason'] ) ? $record['reason'] : '' ),
				'created_at_utc'          => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function completed_types( $form_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT DISTINCT type FROM %i WHERE form_id = %d AND status IN (%s, %s, %s)',
			Database::experiments_table(),
			absint( $form_id ),
			ExperimentStatus::COMPLETED,
			ExperimentStatus::REJECTED,
			ExperimentStatus::INCONCLUSIVE
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Small experiment-type lookup.
		$types = $wpdb->get_col( $sql );
		return is_array( $types ) ? array_map( 'sanitize_key', $types ) : array();
	}

	public function completed_opportunities( $form_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT type, policy_json FROM %i WHERE form_id = %d AND status IN (%s, %s, %s, %s) ORDER BY id DESC LIMIT 100',
			Database::experiments_table(),
			absint( $form_id ),
			ExperimentStatus::COMPLETED,
			ExperimentStatus::REJECTED,
			ExperimentStatus::INCONCLUSIVE,
			ExperimentStatus::ROLLED_BACK
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded hypothesis history prevents repeating the same structural opportunity.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$keys = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$policy      = $this->decode_json( $row['policy_json'] );
			$opportunity = isset( $policy['opportunity'] ) && is_array( $policy['opportunity'] ) ? $policy['opportunity'] : array();
			$field_key   = isset( $opportunity['field_key'] ) ? sanitize_text_field( $opportunity['field_key'] ) : '';
			$keys[]      = sanitize_key( $row['type'] ) . '|' . $field_key;
		}
		return array_values( array_unique( $keys ) );
	}

	public function touch_evaluated( $form_id, $state = null ) {
		global $wpdb;
		$data    = array(
			'last_evaluated_at_utc' => current_time( 'mysql', true ),
			'updated_at_utc'        => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s' );
		if ( null !== $state ) {
			$data['state'] = sanitize_key( $state );
			$formats[]     = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Background evaluation heartbeat.
		return false !== $wpdb->update( Database::cro_forms_table(), $data, array( 'form_id' => absint( $form_id ) ), $formats, array( '%d' ) );
	}

	public function diagnostics() {
		global $wpdb;
		if ( ! Database::cro_schema_is_current( true ) ) {
			return array_merge(
				array(
					'schema_ready'          => 0,
					'active_experiments'    => 0,
					'guardrail_triggers'    => 0,
					'orphan_experiments'    => 0,
					'assigned_views'        => 0,
					'application_errors'    => 0,
					'missing_confirmations' => 0,
					'last_evaluated_at_utc' => '',
				),
				array_fill_keys( CRODiagnostics::COUNTERS, 0 )
			);
		}
		$sql = $wpdb->prepare(
			'SELECT
				SUM(CASE WHEN e.status IN (%s, %s) THEN 1 ELSE 0 END) AS active_experiments,
				SUM(CASE WHEN c.form_id IS NULL THEN 1 ELSE 0 END) AS orphan_experiments
			FROM %i e LEFT JOIN %i c ON c.form_id = e.form_id',
			ExperimentStatus::RUNNING,
			ExperimentStatus::PROMOTED_MONITORING,
			Database::experiments_table(),
			Database::cro_forms_table()
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Small aggregate CRO diagnostic query.
		$row           = $wpdb->get_row( $sql, ARRAY_A );
		$row           = is_array( $row ) ? array_map( 'absint', $row ) : array(
			'active_experiments' => 0,
			'orphan_experiments' => 0,
		);
		$guardrail_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE decision = %s', Database::optimization_history_table(), 'stopped_guardrail' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Immutable decision history is the authoritative guardrail count.
		$row['guardrail_triggers'] = absint( $wpdb->get_var( $guardrail_sql ) );
		$evaluation_sql            = $wpdb->prepare( 'SELECT MAX(last_evaluated_at_utc) FROM %i', Database::cro_forms_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One bounded evaluator heartbeat fact.
		$last_evaluated = $wpdb->get_var( $evaluation_sql );
		$event_sql      = $wpdb->prepare(
			'SELECT COALESCE(SUM(views), 0) AS assigned_views, COALESCE(SUM(js_errors), 0) AS application_errors,
				COALESCE(SUM(CASE WHEN attempts > confirmed_successes + provider_validation_failures + provider_failures THEN attempts - confirmed_successes - provider_validation_failures - provider_failures ELSE 0 END), 0) AS missing_confirmations
			FROM %i WHERE stat_date >= %s',
			Database::experiment_daily_table(),
			gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Seven-day aggregate-only CRO diagnostics.
		$events = $wpdb->get_row( $event_sql, ARRAY_A );
		return array_merge(
			array(
				'schema_ready'          => 1,
				'last_evaluated_at_utc' => is_string( $last_evaluated ) ? $last_evaluated : '',
			),
			$row,
			is_array( $events ) ? array_map( 'absint', $events ) : array(
				'assigned_views'        => 0,
				'application_errors'    => 0,
				'missing_confirmations' => 0,
			),
			( new CRODiagnostics() )->today()
		);
	}

	public function acquire_lock( $form_id ) {
		global $wpdb;
		$database = defined( 'DB_NAME' ) ? constant( 'DB_NAME' ) : '';
		$name     = 'formhawk_cro_' . md5( (string) $database . '|' . absint( $form_id ) );
		$sql      = $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Non-blocking MySQL advisory lock prevents concurrent decisions.
		return 1 === (int) $wpdb->get_var( $sql );
	}

	public function release_lock( $form_id ) {
		global $wpdb;
		$database = defined( 'DB_NAME' ) ? constant( 'DB_NAME' ) : '';
		$name     = 'formhawk_cro_' . md5( (string) $database . '|' . absint( $form_id ) );
		$sql      = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Release matching Formhawk advisory lock.
		$wpdb->get_var( $sql );
	}

	private function set_baselines( $form_id, array $baseline, array $previous, $state ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic runtime baseline rollback.
		return false !== $wpdb->update(
			Database::cro_forms_table(),
			array(
				'baseline_json'          => wp_json_encode( array_values( $baseline ) ),
				'previous_baseline_json' => wp_json_encode( array_values( $previous ) ),
				'state'                  => sanitize_key( $state ),
				'updated_at_utc'         => current_time( 'mysql', true ),
			),
			array( 'form_id' => absint( $form_id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private function commit_transaction() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete a short Formhawk-owned state transaction.
		if ( false !== $wpdb->query( 'COMMIT' ) ) {
			return true;
		}
		// A failed commit can leave the connection in a transaction depending on
		// the storage error. Explicit rollback avoids leaking that state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery after failed commit.
		$wpdb->query( 'ROLLBACK' );
		return false;
	}

	private function normalize_settings( array $input ) {
		$output = array();
		if ( isset( $input['mode'] ) && in_array( $input['mode'], array( 'observe', 'approve', 'full' ), true ) ) {
			$output['mode'] = $input['mode'];
		}
		if ( isset( $input['aggressiveness'] ) && in_array( $input['aggressiveness'], array( 'conservative', 'balanced', 'aggressive' ), true ) ) {
			$output['aggressiveness'] = $input['aggressiveness'];
		}
		if ( isset( $input['optimization_objective'] ) && in_array( $input['optimization_objective'], array( 'auto', 'submissions', 'qualified_leads', 'won_leads', 'business_value' ), true ) ) {
			$output['optimization_objective'] = $input['optimization_objective'];
		}
		foreach ( array( 'max_experimental_traffic', 'min_duration_days', 'min_conversions' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$output[ $key ] = absint( $input[ $key ] );
			}
		}
		if ( isset( $output['max_experimental_traffic'] ) ) {
			$output['max_experimental_traffic'] = max( 10, min( 50, $output['max_experimental_traffic'] ) );
		}
		if ( isset( $output['min_duration_days'] ) ) {
			$output['min_duration_days'] = max( 3, min( 60, $output['min_duration_days'] ) );
		}
		if ( isset( $output['min_conversions'] ) ) {
			$output['min_conversions'] = max( 20, min( 10000, $output['min_conversions'] ) );
		}
		if ( array_key_exists( 'lead_value', $input ) ) {
			$output['lead_value'] = '' === $input['lead_value'] ? null : max( 0, min( 1000000000, (float) $input['lead_value'] ) );
		}
		if ( isset( $input['currency'] ) && preg_match( '/^[A-Z]{3}$/', $input['currency'] ) ) {
			$output['currency'] = $input['currency'];
		}
		return $output;
	}

	private function hydrate_experiment( array $row ) {
		$row['policy'] = $this->decode_json( $row['policy_json'] );
		return $row;
	}

	private function decode_json( $json ) {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}
}
