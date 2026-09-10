<?php

namespace Formhawk\MinimumForm;

use Formhawk\Infrastructure\Database;
use Formhawk\MinimumForm\Domain\MinimumFormBaseline;
use Formhawk\MinimumForm\Domain\MinimumFormOptimizationRun;
use Formhawk\MinimumForm\Domain\MinimumFormProfile;
use Formhawk\ROI\FieldROIEngine;
use Formhawk\ROI\FieldValueModel;

/** Transactional state and append-only evidence storage for Minimum Form. */
final class MinimumFormRepository {
	public function start( $form_id, array $schema, array $settings ) {
		global $wpdb;
		$form_id = absint( $form_id );
		if ( ! $form_id || empty( $schema['schema_fingerprint'] ) || empty( $schema['dependency_hash'] ) || empty( $schema['fields'] ) ) {
			return 0;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One run and its original immutable baseline are created atomically.
		$wpdb->query( 'START TRANSACTION' );
		$active_sql = $wpdb->prepare(
			"SELECT id FROM %i WHERE form_id=%d AND status IN ('collecting','optimizing','paused','optimized','revalidation_required','integrity_failure','rollback') ORDER BY id DESC LIMIT 1 FOR UPDATE",
			Database::minimum_form_runs_table(),
			$form_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Locked active-run check prevents duplicate starts.
		$existing = absint( $wpdb->get_var( $active_sql ) );
		if ( $existing ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- End rejected transaction.
			return $existing;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transactional Formhawk-owned run insert.
		$created           = $wpdb->insert(
			Database::minimum_form_runs_table(),
			array(
				'form_id'                    => $form_id,
				'status'                     => 'collecting',
				'objective'                  => $this->objective( $settings['objective'] ?? 'auto' ),
				'mode'                       => $this->mode( $settings['mode'] ?? 'approve' ),
				'aggressiveness'             => $this->aggressiveness( $settings['aggressiveness'] ?? 'balanced' ),
				'schema_fingerprint'         => $schema['schema_fingerprint'],
				'dependency_hash'            => $schema['dependency_hash'],
				'original_field_count'       => count( $schema['fields'] ),
				'current_field_count'        => count( $schema['fields'] ),
				'cro_was_enabled'            => ! empty( $settings['cro_was_enabled'] ),
				'previous_cro_settings_json' => wp_json_encode( isset( $settings['previous_cro_settings'] ) && is_array( $settings['previous_cro_settings'] ) ? $settings['previous_cro_settings'] : array() ),
				'started_at_utc'             => $now,
				'updated_at_utc'             => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
		$run_id            = $created ? absint( $wpdb->insert_id ) : 0;
		$initial_mutations = isset( $settings['initial_mutations'] ) && is_array( $settings['initial_mutations'] ) ? array_values( $settings['initial_mutations'] ) : array();
		$baseline_id       = $run_id ? $this->insert_baseline(
			array(
				'run_id'                   => $run_id,
				'form_id'                  => $form_id,
				'version'                  => 1,
				'parent_baseline_id'       => null,
				'created_by_experiment_id' => null,
				'schema_fingerprint'       => $schema['schema_fingerprint'],
				'dependency_hash'          => $schema['dependency_hash'],
				'genome'                   => array(
					'fields'                 => $this->genome_fields( $schema['fields'] ),
					'mutations'              => $initial_mutations,
					'provider_schema_source' => $schema['provider_schema_source'] ?? 'unknown',
				),
				'mutations'                => $initial_mutations,
				'business_value_metric'    => 'confirmed_conversion',
			)
		) : 0;
		if ( ! $run_id || ! $baseline_id ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No partial run survives.
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Publish both baseline pointers in the same transaction.
		$updated = $wpdb->update(
			Database::minimum_form_runs_table(),
			array(
				'original_baseline_id' => $baseline_id,
				'current_baseline_id'  => $baseline_id,
			),
			array( 'id' => $run_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
		if ( false === $updated || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Complete atomic run creation.
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Recover a failed commit.
			return 0;
		}
		do_action( 'formhawk_minimum_form_run_started', $run_id, $form_id );
		return $run_id;
	}

	public function active_run( $form_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT * FROM %i WHERE form_id=%d AND status NOT IN ('disabled','superseded') ORDER BY id DESC LIMIT 1",
			Database::minimum_form_runs_table(),
			absint( $form_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- One current run lookup.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( is_array( $row ) ) {
			$row['previous_cro_settings'] = $this->decode( $row['previous_cro_settings_json'] );
			return new MinimumFormOptimizationRun( $row );
		}
		return null;
	}

	public function latest_run( $form_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE form_id=%d ORDER BY id DESC LIMIT 1', Database::minimum_form_runs_table(), absint( $form_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Indexed latest-run lookup used only for an explicit restart.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( is_array( $row ) ) {
			$row['previous_cro_settings'] = $this->decode( $row['previous_cro_settings_json'] );
			return new MinimumFormOptimizationRun( $row );
		}
		return null;
	}

	public function reactivate( $run_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'UPDATE %i SET status=%s,paused_reason=%s,completed_at_utc=NULL,updated_at_utc=%s WHERE id=%d AND status=%s',
			Database::minimum_form_runs_table(),
			'collecting',
			'',
			current_time( 'mysql', true ),
			absint( $run_id ),
			'disabled'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Conditional explicit restart preserves immutable history and baseline ancestry.
		return 1 === (int) $wpdb->query( $sql );
	}

	public function supersede( $run_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Explicit schema revalidation closes only the selected prior run.
		return false !== $wpdb->update(
			Database::minimum_form_runs_table(),
			array(
				'status'         => 'superseded',
				'updated_at_utc' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $run_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function find_run( $run_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Database::minimum_form_runs_table(), absint( $run_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Primary-key run lookup.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( is_array( $row ) ) {
			$row['previous_cro_settings'] = $this->decode( $row['previous_cro_settings_json'] );
			return new MinimumFormOptimizationRun( $row );
		}
		return null;
	}

	public function queue( $limit = 100 ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT * FROM %i WHERE status IN ('collecting','optimizing','rollback') ORDER BY COALESCE(last_evaluated_at_utc,started_at_utc) ASC LIMIT %d",
			Database::minimum_form_runs_table(),
			max( 1, min( 500, absint( $limit ) ) )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded background queue.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function baseline( $baseline_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Database::minimum_form_baselines_table(), absint( $baseline_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Immutable primary-key baseline lookup.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['genome']    = $this->decode( $row['genome_json'] );
		$row['mutations'] = $this->decode( $row['mutation_stack_json'] );
		return new MinimumFormBaseline( $row );
	}

	public function baseline_for_experiment( $experiment_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT id FROM %i WHERE created_by_experiment_id=%d', Database::minimum_form_baselines_table(), absint( $experiment_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Unique immutable experiment ancestry lookup.
		$id = absint( $wpdb->get_var( $sql ) );
		return $id ? $this->baseline( $id ) : null;
	}

	public function create_baseline( array $run, MinimumFormBaseline $parent_baseline, array $schema, array $mutations, $metric, $experiment_id ) {
		$existing = $this->baseline_for_experiment( $experiment_id );
		if ( $existing ) {
			return absint( $existing->data()['id'] );
		}
		$parent_data = $parent_baseline->data();
		return $this->insert_baseline(
			array(
				'run_id'                   => absint( $run['id'] ),
				'form_id'                  => absint( $run['form_id'] ),
				'version'                  => absint( $parent_data['version'] ) + 1,
				'parent_baseline_id'       => absint( $parent_data['id'] ),
				'created_by_experiment_id' => absint( $experiment_id ),
				'schema_fingerprint'       => $schema['schema_fingerprint'],
				'dependency_hash'          => $schema['dependency_hash'],
				'genome'                   => array(
					'fields'    => $this->genome_fields( $schema['fields'] ),
					'mutations' => array_values( $mutations ),
				),
				'mutations'                => array_values( $mutations ),
				'business_value_metric'    => sanitize_key( $metric ),
			)
		);
	}

	public function promote_pointer( $run_id, $baseline_id, $experiment_id, $field_count ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic pointer switch; immutable baseline content is never updated.
		$changed = $wpdb->update(
			Database::minimum_form_runs_table(),
			array(
				'current_baseline_id'  => absint( $baseline_id ),
				'active_experiment_id' => absint( $experiment_id ),
				'current_field_count'  => absint( $field_count ),
				'status'               => 'optimizing',
				'updated_at_utc'       => current_time( 'mysql', true ),
			),
			array(
				'id'                   => absint( $run_id ),
				'active_experiment_id' => absint( $experiment_id ),
			),
			array( '%d', '%d', '%d', '%s', '%s' ),
			array( '%d', '%d' )
		);
		return 1 === (int) $changed;
	}

	public function restore_pointer( $run_id, $baseline_id, $experiment_id, $field_count ) {
		global $wpdb;
		$where = array( 'id' => absint( $run_id ) );
		if ( absint( $experiment_id ) ) {
			$where['active_experiment_id'] = absint( $experiment_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Rollback is a pointer switch; immutable history is retained.
		$changed = $wpdb->update(
			Database::minimum_form_runs_table(),
			array(
				'current_baseline_id'  => absint( $baseline_id ),
				'active_experiment_id' => null,
				'current_field_count'  => absint( $field_count ),
				'status'               => 'optimizing',
				'updated_at_utc'       => current_time( 'mysql', true ),
			),
			$where
		);
		return 1 === (int) $changed;
	}

	public function attach_experiment( $run_id, $experiment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional ownership prevents two concurrent semantic experiments per run.
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET active_experiment_id=%d,status=%s,updated_at_utc=%s WHERE id=%d AND active_experiment_id IS NULL',
				Database::minimum_form_runs_table(),
				absint( $experiment_id ),
				'optimizing',
				current_time( 'mysql', true ),
				absint( $run_id )
			)
		);
		return 1 === (int) $changed;
	}

	public function release_experiment( $run_id, $experiment_id, $status = 'optimizing' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Idempotent conditional release of the current experiment.
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET active_experiment_id=NULL,status=%s,updated_at_utc=%s WHERE id=%d AND active_experiment_id=%d',
				Database::minimum_form_runs_table(),
				sanitize_key( $status ),
				current_time( 'mysql', true ),
				absint( $run_id ),
				absint( $experiment_id )
			)
		);
	}

	public function set_status( $run_id, $status, $reason = '' ) {
		global $wpdb;
		$allowed = array( 'collecting', 'optimizing', 'paused', 'optimized', 'revalidation_required', 'integrity_failure', 'rollback', 'disabled', 'superseded' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}
		$data = array(
			'status'         => $status,
			'paused_reason'  => sanitize_key( $reason ),
			'updated_at_utc' => current_time( 'mysql', true ),
		);
		if ( 'optimized' === $status ) {
			$data['completed_at_utc'] = current_time( 'mysql', true );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Narrow run-state transition.
		return false !== $wpdb->update( Database::minimum_form_runs_table(), $data, array( 'id' => absint( $run_id ) ) );
	}

	public function touch( $run_id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Background-job heartbeat.
		return false !== $wpdb->update(
			Database::minimum_form_runs_table(),
			array(
				'last_evaluated_at_utc' => $now,
				'updated_at_utc'        => $now,
			),
			array( 'id' => absint( $run_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function decisions( $run_id, $limit = 100 ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT d.*,fd.label,fd.normalized_key,fd.field_type FROM %i d LEFT JOIN %i fd ON fd.id=d.field_definition_id WHERE d.run_id=%d ORDER BY d.id DESC LIMIT %d',
			Database::minimum_form_decisions_table(),
			Database::field_definitions_table(),
			absint( $run_id ),
			max( 1, min( 200, absint( $limit ) ) )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded immutable run history.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function add_decision( array $record ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Append-only, experiment-idempotent decision record.
		$inserted = $wpdb->insert(
			Database::minimum_form_decisions_table(),
			array(
				'run_id'                         => absint( $record['run_id'] ),
				'form_id'                        => absint( $record['form_id'] ),
				'field_definition_id'            => absint( $record['field_definition_id'] ),
				'experiment_id'                  => absint( $record['experiment_id'] ),
				'baseline_before_id'             => absint( $record['baseline_before_id'] ),
				'baseline_after_id'              => ! empty( $record['baseline_after_id'] ) ? absint( $record['baseline_after_id'] ) : null,
				'mutation_type'                  => sanitize_key( $record['mutation_type'] ),
				'decision'                       => sanitize_key( $record['decision'] ),
				'evidence_level'                 => sanitize_key( $record['evidence_level'] ?? 'provider_confirmed' ),
				'primary_metric'                 => sanitize_key( $record['primary_metric'] ),
				'business_lift'                  => isset( $record['business_lift'] ) ? (float) $record['business_lift'] : null,
				'probability'                    => isset( $record['probability'] ) ? (float) $record['probability'] : null,
				'expected_loss'                  => isset( $record['expected_loss'] ) ? (float) $record['expected_loss'] : null,
				'control_value'                  => isset( $record['control_value'] ) ? (float) $record['control_value'] : null,
				'variant_value'                  => isset( $record['variant_value'] ) ? (float) $record['variant_value'] : null,
				'control_visitors'               => absint( $record['control_visitors'] ?? 0 ),
				'variant_visitors'               => absint( $record['variant_visitors'] ?? 0 ),
				'control_confirmed'              => absint( $record['control_confirmed'] ?? 0 ),
				'variant_confirmed'              => absint( $record['variant_confirmed'] ?? 0 ),
				'confidence'                     => sanitize_key( $record['confidence'] ?? 'insufficient' ),
				'reason'                         => sanitize_text_field( $record['reason'] ?? '' ),
				'minimum_form_algorithm_version' => MinimumFormManager::ALGORITHM_VERSION,
				'statistical_policy_version'     => \Formhawk\CRO\OptimizationPolicy::VERSION,
				'field_roi_version'              => FieldROIEngine::MODEL_VERSION . '+' . FieldValueModel::MODEL_VERSION,
				'created_at_utc'                 => current_time( 'mysql', true ),
			)
		);
		if ( $inserted ) {
			return true;
		}
		$sql = $wpdb->prepare( 'SELECT id FROM %i WHERE experiment_id=%d AND decision=%s', Database::minimum_form_decisions_table(), absint( $record['experiment_id'] ), sanitize_key( $record['decision'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Duplicate worker retry succeeds only if its immutable decision already exists.
		return (bool) $wpdb->get_var( $sql );
	}

	public function fields_for_form( $form_id, $currency = 'USD' ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT fd.*,r.field_definition_id,r.evidence_level,r.confidence,r.verdict,r.recommendation,r.score,r.metrics_json,r.model_version FROM %i fd LEFT JOIN %i r ON r.field_definition_id=fd.id AND r.currency=%s WHERE fd.form_id=%d ORDER BY fd.position ASC LIMIT 100',
			Database::field_definitions_table(),
			Database::field_roi_results_table(),
			strtoupper( sanitize_text_field( $currency ) ),
			absint( $form_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded structural/ROI projection for one form.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['field_definition_id'] = absint( $row['id'] );
			$row['metrics']             = $this->decode( $row['metrics_json'] ?? '' );
			$row['verdict']             = $row['verdict'] ? $row['verdict'] : 'unknown';
			$row['confidence']          = $row['confidence'] ? $row['confidence'] : 'insufficient';
		}
		unset( $row );
		return $rows;
	}

	public function profile( $form_id ) {
		$run = $this->active_run( $form_id );
		if ( ! $run ) {
			return new MinimumFormProfile( array( 'status' => 'off' ) );
		}
		$data                        = $run->data();
		$data['decisions']           = $this->decisions( $data['id'] );
		$data['decided_field_count'] = count( array_unique( array_column( $data['decisions'], 'field_definition_id' ) ) );
		$data['current_baseline']    = $this->baseline( $data['current_baseline_id'] );
		$data['original_baseline']   = $this->baseline( $data['original_baseline_id'] );
		return new MinimumFormProfile( $data );
	}

	public function attributable_baseline_id( $form_id, array $context = array() ) {
		global $wpdb;
		$run = $this->active_run( $form_id );
		if ( ! $run ) {
			return 0;
		}
		$data = $run->data();
		if ( ! in_array( $data['status'], array( 'collecting', 'optimizing', 'paused', 'optimized', 'rollback' ), true ) ) {
			return 0;
		}
		if ( empty( $context['experiment_id'] ) || empty( $context['variant_id'] ) ) {
			return absint( $data['current_baseline_id'] );
		}
		$sql = $wpdb->prepare(
			'SELECT minimum_form_run_id,minimum_form_baseline_id,winner_variant_id,status FROM %i WHERE id=%d AND form_id=%d',
			Database::experiments_table(),
			absint( $context['experiment_id'] ),
			absint( $form_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Verified CRO context is resolved to its immutable baseline ancestry.
		$experiment = $wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $experiment ) || absint( $experiment['minimum_form_run_id'] ) !== absint( $data['id'] ) ) {
			return 0;
		}
		if ( absint( $experiment['winner_variant_id'] ) === absint( $context['variant_id'] ) && in_array( $experiment['status'], array( 'promoted_monitoring', 'completed' ), true ) ) {
			$winner = $this->baseline_for_experiment( $context['experiment_id'] );
			return $winner ? absint( $winner->data()['id'] ) : 0;
		}
		return absint( $experiment['minimum_form_baseline_id'] );
	}

	private function insert_baseline( array $record ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Immutable baseline insert in Formhawk-owned storage.
		$inserted = $wpdb->insert(
			Database::minimum_form_baselines_table(),
			array(
				'run_id'                   => absint( $record['run_id'] ),
				'form_id'                  => absint( $record['form_id'] ),
				'version'                  => absint( $record['version'] ),
				'parent_baseline_id'       => ! empty( $record['parent_baseline_id'] ) ? absint( $record['parent_baseline_id'] ) : null,
				'created_by_experiment_id' => ! empty( $record['created_by_experiment_id'] ) ? absint( $record['created_by_experiment_id'] ) : null,
				'schema_fingerprint'       => $record['schema_fingerprint'],
				'dependency_hash'          => $record['dependency_hash'],
				'genome_json'              => wp_json_encode( $record['genome'] ),
				'mutation_stack_json'      => wp_json_encode( array_values( $record['mutations'] ) ),
				'business_value_metric'    => sanitize_key( $record['business_value_metric'] ),
				'validation_status'        => 'valid',
				'created_at_utc'           => current_time( 'mysql', true ),
				'validated_at_utc'         => current_time( 'mysql', true ),
			)
		);
		if ( $inserted ) {
			return absint( $wpdb->insert_id );
		}
		$sql = $wpdb->prepare( 'SELECT id FROM %i WHERE run_id=%d AND version=%d', Database::minimum_form_baselines_table(), absint( $record['run_id'] ), absint( $record['version'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Restart-safe lookup for an already-created immutable version.
		return absint( $wpdb->get_var( $sql ) );
	}

	private function genome_fields( array $fields ) {
		$output = array();
		foreach ( $fields as $field ) {
			$output[] = array_intersect_key( $field, array_flip( array( 'key', 'normalized_key', 'label', 'type', 'field_type', 'required', 'position' ) ) );
		}
		return $output;
	}

	private function mode( $value ) {
		return in_array( $value, array( 'observe', 'approve', 'full' ), true ) ? $value : 'approve';
	}

	private function aggressiveness( $value ) {
		return in_array( $value, array( 'conservative', 'balanced', 'aggressive' ), true ) ? $value : 'balanced';
	}

	private function objective( $value ) {
		return in_array( $value, array( 'auto', 'confirmed_conversion', 'qualified_leads', 'won_leads', 'revenue_per_visitor' ), true ) ? $value : 'auto';
	}

	private function decode( $json ) {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}
}
