<?php

namespace Formhawk\MinimumForm;

use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\CacheCoordinator;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\CRO\Experiments\ExperimentStatus;
use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\CRO\GuardrailEvaluator;
use Formhawk\CRO\HypothesisEngine;
use Formhawk\CRO\OptimizationPolicy;
use Formhawk\CRO\ProviderCROCapabilities;
use Formhawk\CRO\VariantGenerator;
use Formhawk\MinimumForm\Domain\MinimumFormBaseline;

/** Idempotent sequential optimizer; all expensive work runs outside frontend requests. */
final class MinimumFormManager {
	const CRON_HOOK         = 'formhawk_minimum_form_hourly_evaluation';
	const ALGORITHM_VERSION = 'minimum-form-1.0';

	private $repository;
	private $experiments;
	private $forms;
	private $schemas;
	private $capabilities;
	private $candidates;
	private $objectives;
	private $variants;
	private $hypotheses;
	private $policy;
	private $decisions;
	private $guardrails;
	private $regressions;
	private $cache;

	public function __construct( ?MinimumFormRepository $repository = null, ?ExperimentRepository $experiments = null, ?FormRepository $forms = null, ?ProviderSchemaInspector $schemas = null, ?ProviderCapabilityMatrix $capabilities = null, ?MinimumFormCandidateSelector $candidates = null, ?BusinessObjectiveResolver $objectives = null, ?VariantGenerator $variants = null, ?HypothesisEngine $hypotheses = null, ?OptimizationPolicy $policy = null, ?MinimumFormDecisionEngine $decisions = null, ?GuardrailEvaluator $guardrails = null, ?CacheCoordinator $cache = null, ?PromotionRegressionMonitor $regressions = null ) {
		$this->repository   = $repository ? $repository : new MinimumFormRepository();
		$this->experiments  = $experiments ? $experiments : new ExperimentRepository();
		$this->forms        = $forms ? $forms : new FormRepository();
		$this->schemas      = $schemas ? $schemas : new ProviderSchemaInspector();
		$this->capabilities = $capabilities ? $capabilities : new ProviderCapabilityMatrix();
		$this->candidates   = $candidates ? $candidates : new MinimumFormCandidateSelector();
		$this->objectives   = $objectives ? $objectives : new BusinessObjectiveResolver();
		$this->variants     = $variants ? $variants : new VariantGenerator();
		$this->hypotheses   = $hypotheses ? $hypotheses : new HypothesisEngine();
		$this->policy       = $policy ? $policy : new OptimizationPolicy();
		$this->decisions    = $decisions ? $decisions : new MinimumFormDecisionEngine();
		$this->guardrails   = $guardrails ? $guardrails : new GuardrailEvaluator();
		$this->regressions  = $regressions ? $regressions : new PromotionRegressionMonitor();
		$this->cache        = $cache ? $cache : new CacheCoordinator();
	}

	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'evaluate_all' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public function start( $form_id, array $settings = array() ) {
		$form_id = absint( $form_id );
		if ( ! $form_id || ! $this->experiments->acquire_lock( $form_id ) ) {
			return 0;
		}
		try {
			$existing = $this->repository->active_run( $form_id );
			if ( $existing ) {
				return absint( $existing->data()['id'] );
			}
			$form = $this->forms->find( $form_id );
			if ( ! is_array( $form ) ) {
				return 0;
			}
			$schema = $this->schemas->inspect( $form );
			if ( ! $schema ) {
				return 0;
			}
			$current = $this->experiments->settings( $form_id );
			if ( $this->experiments->active_for_form( $form_id ) ) {
				return 0;
			}
			$latest      = $this->repository->latest_run( $form_id );
			$latest_data = $latest ? $latest->data() : array();
			$input       = array_merge(
				array(
					'mode'           => 'approve',
					'aggressiveness' => 'balanced',
					'objective'      => 'auto',
				),
				$settings
			);
			$cro_input   = array(
				'mode'                   => $input['mode'],
				'aggressiveness'         => $input['aggressiveness'],
				'optimization_objective' => $this->cro_objective( $input['objective'] ),
			);
			if ( ! $this->experiments->enable( $form_id, $cro_input ) ) {
				return 0;
			}
			if ( $latest && 'disabled' === $latest_data['status']
				&& hash_equals( (string) $latest_data['schema_fingerprint'], (string) $schema['schema_fingerprint'] )
				&& hash_equals( (string) $latest_data['dependency_hash'], (string) $schema['dependency_hash'] ) ) {
				$baseline = $this->repository->baseline( $latest_data['current_baseline_id'] );
				if ( $baseline && $this->experiments->promote_baseline( $form_id, $baseline->mutations() ) && $this->repository->reactivate( $latest_data['id'] ) ) {
					$this->cache->purge();
					return absint( $latest_data['id'] );
				}
				$this->experiments->promote_baseline( $form_id, array() );
				$this->restore_cro_settings( $form_id, $current );
				return 0;
			}
			$input['initial_mutations']     = $current && isset( $current['baseline'] ) ? $current['baseline'] : array();
			$input['cro_was_enabled']       = (bool) $current;
			$input['previous_cro_settings'] = $current ? $current : array();
			$run_id                         = $this->repository->start( $form_id, $schema, $input );
			if ( $run_id ) {
				$this->cache->purge();
			} else {
				$this->restore_cro_settings( $form_id, $current );
			}
			return $run_id;
		} finally {
			$this->experiments->release_lock( $form_id );
		}
	}

	public function evaluate_all() {
		foreach ( $this->repository->queue( 100 ) as $run ) {
			$this->evaluate_form( absint( $run['form_id'] ) );
		}
	}

	public function evaluate_form( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $this->experiments->acquire_lock( $form_id ) ) {
			return false;
		}
		try {
			return $this->evaluate_locked( $form_id );
		} finally {
			$this->experiments->release_lock( $form_id );
		}
	}

	public function start_experiment( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $this->experiments->acquire_lock( $form_id ) ) {
			return false;
		}
		try {
			$run = $this->repository->active_run( $form_id );
			if ( ! $run ) {
				return false;
			}
			$data       = $run->data();
			$experiment = ! empty( $data['active_experiment_id'] ) ? $this->experiments->find( $data['active_experiment_id'] ) : null;
			if ( ! $experiment || ! in_array( $experiment['status'], array( ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::SUGGESTED, ExperimentStatus::PAUSED_MANUAL ), true ) ) {
				return false;
			}
			$started = $this->experiments->start( $experiment['id'] );
			if ( $started ) {
				$this->repository->set_status( $data['id'], 'optimizing' );
				$this->cache->purge();
				do_action( 'formhawk_minimum_form_experiment_started', absint( $data['id'] ), absint( $experiment['id'] ) );
			}
			return $started;
		} finally {
			$this->experiments->release_lock( $form_id );
		}
	}

	public function pause( $form_id ) {
		return $this->transition( $form_id, 'paused' );
	}

	public function resume( $form_id ) {
		return $this->transition( $form_id, 'optimizing' );
	}

	public function disable( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $this->experiments->acquire_lock( $form_id ) ) {
			return false;
		}
		try {
			$run = $this->repository->active_run( $form_id );
			if ( ! $run ) {
				return false;
			}
			$data = $run->data();
			if ( ! empty( $data['active_experiment_id'] ) ) {
				$this->experiments->route_to_control( $data['active_experiment_id'] );
				$this->experiments->set_status( $data['active_experiment_id'], ExperimentStatus::MANUALLY_STOPPED, array( 'ended_at_utc' => current_time( 'mysql', true ) ) );
			}
			$this->experiments->promote_baseline( $form_id, array() );
			$restored = empty( $data['cro_was_enabled'] )
				? $this->experiments->disable( $form_id )
				: $this->restore_cro_settings( $form_id, $data['previous_cro_settings'] );
			if ( ! $restored || ! $this->repository->set_status( $data['id'], 'disabled', 'manual_disable' ) ) {
				return false;
			}
			$this->cache->purge();
			return true;
		} finally {
			$this->experiments->release_lock( $form_id );
		}
	}

	public function restore_previous( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $this->experiments->acquire_lock( $form_id ) ) {
			return false;
		}
		try {
			$run = $this->repository->active_run( $form_id );
			if ( ! $run ) {
				return false;
			}
			$data         = $run->data();
			$current      = $this->repository->baseline( $data['current_baseline_id'] );
			$current_data = $current ? $current->data() : array();
			$previous     = ! empty( $current_data['parent_baseline_id'] ) ? $this->repository->baseline( $current_data['parent_baseline_id'] ) : null;
			if ( ! $previous ) {
				return false;
			}
			$previous_data = $previous->data();
			$source_id     = absint( $current_data['created_by_experiment_id'] ?? 0 );
			$source        = $source_id ? $this->experiments->find( $source_id ) : null;
			$active_id     = absint( $data['active_experiment_id'] );
			if ( $active_id && $active_id !== $source_id ) {
				$this->experiments->route_to_control( $active_id );
				$this->experiments->set_status( $active_id, ExperimentStatus::MANUALLY_STOPPED, array( 'ended_at_utc' => current_time( 'mysql', true ) ) );
			}
			if ( ! $this->experiments->promote_baseline( $form_id, $previous->mutations() ) ) {
				return false;
			}
			if ( $source ) {
				$this->experiments->route_to_control( $source_id );
				$this->experiments->set_status( $source_id, ExperimentStatus::ROLLED_BACK, array( 'ended_at_utc' => current_time( 'mysql', true ) ) );
			}
			if ( ! $this->repository->restore_pointer( $data['id'], $previous_data['id'], $active_id, $this->field_count( $data['original_field_count'], $previous->mutations() ) ) ) {
				return false;
			}
			if ( $source ) {
				$history_recorded = $this->experiments->add_history(
					array(
						'form_id'            => $form_id,
						'experiment_id'      => $source_id,
						'decision'           => 'manual_rollback',
						'previous_baseline'  => $current->mutations(),
						'resulting_baseline' => $previous->mutations(),
						'reason'             => 'manual_rollback',
					)
				);
				if ( ! $history_recorded || ! $this->record_decision( $data, $current, $source, array( 'reason' => 'manual_rollback' ), 'rollback', $previous_data['id'] ) ) {
					$this->repository->set_status( $data['id'], 'integrity_failure', $history_recorded ? 'decision_storage_failure' : 'history_storage_failure' );
					return false;
				}
			}
			$this->cache->purge();
			do_action( 'formhawk_minimum_form_rollback', absint( $data['id'] ), absint( $current_data['id'] ), absint( $previous_data['id'] ) );
			return true;
		} finally {
			$this->experiments->release_lock( $form_id );
		}
	}

	public function revalidate( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $form_id || ! $this->experiments->acquire_lock( $form_id ) ) {
			return 0;
		}
		try {
			$run  = $this->repository->active_run( $form_id );
			$form = $this->forms->find( $form_id );
			if ( ! $run || ! is_array( $form ) || 'revalidation_required' !== $run->data()['status'] ) {
				return 0;
			}
			$prior  = $run->data();
			$schema = $this->schemas->inspect( $form );
			if ( ! $schema ) {
				return 0;
			}
			if ( ! empty( $prior['active_experiment_id'] ) ) {
				$this->experiments->route_to_control( $prior['active_experiment_id'] );
				$this->experiments->set_status( $prior['active_experiment_id'], ExperimentStatus::MANUALLY_STOPPED, array( 'ended_at_utc' => current_time( 'mysql', true ) ) );
			}
			$this->experiments->promote_baseline( $form_id, array() );
			$this->repository->set_status( $prior['id'], 'disabled', 'schema_revalidation' );
			$new_id = $this->repository->start(
				$form_id,
				$schema,
				array(
					'mode'                  => $prior['mode'],
					'aggressiveness'        => $prior['aggressiveness'],
					'objective'             => $prior['objective'],
					'initial_mutations'     => array(),
					'cro_was_enabled'       => ! empty( $prior['cro_was_enabled'] ),
					'previous_cro_settings' => $prior['previous_cro_settings'],
				)
			);
			if ( ! $new_id ) {
				$this->repository->set_status( $prior['id'], 'revalidation_required', 'schema_drift' );
				return 0;
			}
			$this->repository->supersede( $prior['id'] );
			$this->cache->purge();
			return $new_id;
		} finally {
			$this->experiments->release_lock( $form_id );
		}
	}

	private function transition( $form_id, $status ) {
		if ( ! in_array( $status, array( 'paused', 'optimizing' ), true ) ) {
			return false;
		}
		$form_id = absint( $form_id );
		if ( ! $form_id || ! $this->experiments->acquire_lock( $form_id ) ) {
			return false;
		}
		try {
			$run = $this->repository->active_run( $form_id );
			if ( ! $run ) {
				return false;
			}
			$data = $run->data();
			if ( ! empty( $data['active_experiment_id'] ) ) {
				$experiment = $this->experiments->find( $data['active_experiment_id'] );
				if ( $experiment && 'paused' === $status && ExperimentStatus::RUNNING === $experiment['status'] ) {
					$this->experiments->route_to_control( $experiment['id'] );
					$this->experiments->set_status( $experiment['id'], ExperimentStatus::PAUSED_MANUAL );
					$this->cache->purge();
				} elseif ( $experiment && 'optimizing' === $status && ExperimentStatus::PAUSED_MANUAL === $experiment['status'] ) {
					$started = $this->experiments->start( $experiment['id'] );
					if ( $started ) {
						$this->repository->set_status( $data['id'], 'optimizing' );
						$this->cache->purge();
						do_action( 'formhawk_minimum_form_experiment_started', absint( $data['id'] ), absint( $experiment['id'] ) );
					}
					return $started;
				}
			}
			return $this->repository->set_status( $data['id'], $status, 'paused' === $status ? 'manual_pause' : '' );
		} finally {
			$this->experiments->release_lock( $form_id );
		}
	}

	private function evaluate_locked( $form_id ) {
		$run_entity = $this->repository->active_run( $form_id );
		$form       = $this->forms->find( $form_id );
		if ( ! $run_entity || ! is_array( $form ) ) {
			return false;
		}
		$run = $run_entity->data();
		if ( ! in_array( $run['status'], array( 'collecting', 'optimizing', 'rollback' ), true ) ) {
			return true;
		}
		$schema = $this->schemas->inspect( $form );
		if ( ! $schema || ! hash_equals( (string) $run['schema_fingerprint'], (string) $schema['schema_fingerprint'] ) || ! hash_equals( (string) $run['dependency_hash'], (string) $schema['dependency_hash'] ) ) {
			return $this->schema_drift( $run );
		}
		$baseline = $this->repository->baseline( $run['current_baseline_id'] );
		if ( ! $baseline || ! $baseline->matches_schema( $schema['schema_fingerprint'], $schema['dependency_hash'] ) ) {
			return $this->schema_drift( $run );
		}
		$experiment = ! empty( $run['active_experiment_id'] ) ? $this->experiments->find( $run['active_experiment_id'] ) : null;
		if ( $experiment ) {
			$result = $this->evaluate_experiment( $form, $run, $schema, $baseline, $experiment );
			$this->repository->touch( $run['id'] );
			return $result;
		}
		$result = $this->create_next( $form, $run, $schema, $baseline );
		$this->repository->touch( $run['id'] );
		return $result;
	}

	private function create_next( array $form, array $run, array $schema, MinimumFormBaseline $baseline ) {
		$capabilities = $this->capabilities->for_provider( $form['provider'] );
		if ( empty( $capabilities[ ProviderCapabilityMatrix::CONFIRMED_SUCCESS ] ) || empty( $capabilities[ ProviderCapabilityMatrix::DEPENDENCY_GRAPH ] ) ) {
			$this->repository->set_status( $run['id'], 'paused', 'provider_safety_unsupported' );
			return true;
		}
		$field_rows = $this->repository->fields_for_form( $form['id'], $this->currency() );
		if ( ! $field_rows ) {
			$this->repository->set_status( $run['id'], 'collecting', 'field_roi_learning' );
			return true;
		}
		$fields    = $this->effective_fields( $this->merge_schema( $field_rows, $schema['fields'] ), $baseline->mutations() );
		$history   = $this->repository->decisions( $run['id'] );
		$candidate = $this->candidates->select( $fields, $schema['dependency_graph'], $capabilities, $history, $run['aggressiveness'] );
		if ( ! $candidate ) {
			$status = $this->needs_more_evidence( $fields, $schema['dependency_graph'] ) ? 'collecting' : 'optimized';
			$this->repository->set_status( $run['id'], $status );
			if ( 'optimized' === $status ) {
				do_action( 'formhawk_minimum_form_optimized', absint( $run['id'] ), absint( $form['id'] ) );
			}
			return true;
		}

		$candidate_data = $candidate->data();
		$objective      = $this->objectives->resolve( $form['id'], $run['objective'], $this->currency() );
		$settings       = $this->experiments->settings( $form['id'] );
		if ( ! $settings ) {
			return false;
		}
		$policy                              = $this->policy->for_form( $settings );
		$minimum_sample                      = apply_filters( 'formhawk_minimum_form_min_sample', $policy['minimum_views_per_variant'], $candidate_data, $run );
		$policy['minimum_views_per_variant'] = max( 100, absint( $minimum_sample ) );
		$policy['opportunity']               = array_intersect_key(
			array_merge( $candidate_data, array( 'type' => $candidate_data['mutation_type'] ) ),
			array_flip( array( 'type', 'field_key', 'field_label', 'field_type', 'safety', 'verdict', 'confidence', 'sample_size', 'expected_upside', 'risk_score', 'score' ) )
		);
		$policy['minimum_form']              = array(
			'run_id'             => absint( $run['id'] ),
			'baseline_id'        => absint( $run['current_baseline_id'] ),
			'schema_fingerprint' => $schema['schema_fingerprint'],
			'dependency_hash'    => $schema['dependency_hash'],
			'objective_mode'     => $objective['business_optimized'] ? 'business_value_optimized' : 'conversion_optimized',
		);
		$opportunity                         = array_merge(
			$candidate_data,
			array(
				'type'               => $candidate_data['mutation_type'],
				'business_optimized' => $objective['business_optimized'],
			)
		);
		$variants                            = $this->variants->generate( $form, $opportunity, $baseline->mutations() );
		if ( ! $variants ) {
			$this->repository->set_status( $run['id'], 'paused', 'mutation_unsupported' );
			return true;
		}
		$status = 'observe' === $run['mode'] ? ExperimentStatus::SUGGESTED : ( 'approve' === $run['mode'] ? ExperimentStatus::AWAITING_APPROVAL : ExperimentStatus::RUNNING );
		$id     = $this->experiments->create(
			array(
				'form_id'                  => $form['id'],
				'type'                     => $candidate_data['mutation_type'],
				'status'                   => $status,
				'hypothesis'               => $this->hypotheses->create( $opportunity ),
				'primary_metric'           => $objective['metric'],
				'evidence_level'           => ProviderCROCapabilities::evidence_level( $form['provider'] ),
				'policy'                   => $policy,
				'minimum_form_run_id'      => $run['id'],
				'minimum_form_baseline_id' => $run['current_baseline_id'],
				'field_definition_id'      => $candidate_data['field_definition_id'],
			),
			array( $variants['control'], $variants['variant'] )
		);
		if ( ! $id || ! $this->repository->attach_experiment( $run['id'], $id ) ) {
			if ( $id ) {
				$this->experiments->route_to_control( $id );
				$this->experiments->set_status( $id, ExperimentStatus::REJECTED, array( 'ended_at_utc' => current_time( 'mysql', true ) ) );
			}
			return false;
		}
		do_action( 'formhawk_minimum_form_candidate_selected', $run['id'], $candidate_data );
		if ( ExperimentStatus::RUNNING === $status ) {
			$this->experiments->set_status( $id, ExperimentStatus::RUNNING, array( 'started_at_utc' => current_time( 'mysql', true ) ) );
			do_action( 'formhawk_minimum_form_experiment_started', $run['id'], $id );
			$this->cache->purge();
		}
		return true;
	}

	private function evaluate_experiment( array $form, array $run, array $schema, MinimumFormBaseline $baseline, array $experiment ) {
		if ( ! in_array( absint( $experiment['minimum_form_run_id'] ?? 0 ), array( absint( $run['id'] ) ), true ) ) {
			return $this->integrity_failure( $run, $experiment, 'experiment_run_mismatch' );
		}
		if ( in_array( $experiment['status'], array( ExperimentStatus::SUGGESTED, ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::PAUSED_MANUAL ), true ) ) {
			return true;
		}
		if ( ExperimentStatus::PROMOTED_MONITORING === $experiment['status'] ) {
			return $this->monitor_promotion( $form, $run, $baseline, $experiment );
		}
		if ( ExperimentStatus::RUNNING !== $experiment['status'] ) {
			return $this->repository->release_experiment( $run['id'], $experiment['id'] )
				? true
				: $this->integrity_failure( $run, $experiment, 'experiment_release_failure' );
		}
		$variants = $this->experiments->variants( $experiment['id'] );
		$settings = $this->experiments->settings( $form['id'] );
		$analysis = $this->decisions->evaluate( $experiment, $variants, $this->experiments->aggregate( $experiment['id'] ), $settings ? $settings : array(), $this->runtime_days( $experiment['started_at_utc'] ) );
		if ( 'integrity_failure' === $analysis['decision'] ) {
			return $this->integrity_failure( $run, $experiment, $analysis['reason'] );
		}
		if ( 'winner' === $analysis['decision'] ) {
			return $this->promote( $form, $run, $schema, $baseline, $experiment, $variants, $analysis );
		}
		if ( in_array( $analysis['decision'], array( 'reject', 'inconclusive' ), true ) ) {
			return $this->reject( $form, $run, $baseline, $experiment, $variants, $analysis );
		}
		return true;
	}

	private function promote( array $form, array $run, array $schema, MinimumFormBaseline $baseline, array $experiment, array $variants, array $analysis ) {
		if ( count( $variants ) !== 2 || empty( $variants[1]['config']['mutations'] ) ) {
			return $this->integrity_failure( $run, $experiment, 'corrupt_variant_configuration' );
		}
		$mutations = $variants[1]['config']['mutations'];
		$new       = $this->repository->create_baseline( $run, $baseline, $schema, $mutations, $analysis['metric'] ?? $experiment['primary_metric'], $experiment['id'] );
		if ( ! $new ) {
			return $this->integrity_failure( $run, $experiment, 'baseline_storage_failure' );
		}
		$new_baseline = $this->repository->baseline( $new );
		if ( ! $new_baseline || ! $this->experiments->promote_experiment( $form['id'], $experiment['id'], $variants[1]['id'], $mutations ) ) {
			return $this->integrity_failure( $run, $experiment, 'promotion_storage_failure' );
		}
		$field_count = $this->field_count( $run['original_field_count'], $mutations );
		if ( ! $this->repository->promote_pointer( $run['id'], $new, $experiment['id'], $field_count ) ) {
			$this->experiments->rollback_experiment( $form['id'], $experiment['id'] );
			return $this->integrity_failure( $run, $experiment, 'baseline_pointer_failure' );
		}
		$history_recorded = $this->experiments->add_history(
			array(
				'form_id'            => $form['id'],
				'experiment_id'      => $experiment['id'],
				'decision'           => 'winner',
				'lift'               => $analysis['expected_lift'] ?? null,
				'probability'        => $analysis['probability_to_be_best'] ?? null,
				'expected_loss'      => $analysis['expected_loss'] ?? null,
				'previous_baseline'  => $baseline->mutations(),
				'resulting_baseline' => $mutations,
				'algorithm_version'  => self::ALGORITHM_VERSION,
				'reason'             => $analysis['reason'],
			)
		);
		if ( ! $history_recorded || ! $this->record_decision( $run, $baseline, $experiment, $analysis, 'promote', $new ) ) {
			$this->experiments->rollback_experiment( $form['id'], $experiment['id'] );
			$baseline_data = $baseline->data();
			$this->repository->restore_pointer( $run['id'], $baseline_data['id'], $experiment['id'], $this->field_count( $run['original_field_count'], $baseline->mutations() ) );
			return $this->integrity_failure( $run, $experiment, $history_recorded ? 'decision_storage_failure' : 'history_storage_failure' );
		}
		$this->cache->purge();
		if ( ExperimentType::REMOVE_FIELD === $experiment['type'] ) {
			do_action( 'formhawk_minimum_form_field_removed', $run['id'], $experiment['field_definition_id'], $new );
		} else {
			do_action( 'formhawk_minimum_form_field_retained', $run['id'], $experiment['field_definition_id'], $experiment['type'] );
		}
		do_action( 'formhawk_minimum_form_baseline_promoted', $run['id'], $new, $experiment['id'] );
		return true;
	}

	private function reject( array $form, array $run, MinimumFormBaseline $baseline, array $experiment, array $variants, array $analysis ) {
		$this->experiments->route_to_control( $experiment['id'] );
		$status = 'inconclusive' === $analysis['decision'] ? ExperimentStatus::INCONCLUSIVE : ExperimentStatus::REJECTED;
		$this->experiments->set_status(
			$experiment['id'],
			$status,
			array(
				'ended_at_utc'      => current_time( 'mysql', true ),
				'winner_variant_id' => isset( $variants[0]['id'] ) ? $variants[0]['id'] : null,
			)
		);
		$history_recorded = $this->experiments->add_history(
			array(
				'form_id'            => $form['id'],
				'experiment_id'      => $experiment['id'],
				'decision'           => $analysis['decision'],
				'lift'               => $analysis['expected_lift'] ?? null,
				'probability'        => $analysis['probability_to_be_best'] ?? null,
				'expected_loss'      => $analysis['expected_loss'] ?? null,
				'previous_baseline'  => $baseline->mutations(),
				'resulting_baseline' => $baseline->mutations(),
				'reason'             => $analysis['reason'],
			)
		);
		if ( ! $history_recorded || ! $this->record_decision( $run, $baseline, $experiment, $analysis, $analysis['decision'] ) ) {
			return $this->integrity_failure( $run, $experiment, $history_recorded ? 'decision_storage_failure' : 'history_storage_failure' );
		}
		if ( ! $this->repository->release_experiment( $run['id'], $experiment['id'] ) ) {
			return $this->integrity_failure( $run, $experiment, 'experiment_release_failure' );
		}
		$this->cache->purge();
		do_action( 'formhawk_minimum_form_field_retained', $run['id'], $experiment['field_definition_id'], $analysis['reason'] );
		return true;
	}

	private function monitor_promotion( array $form, array $run, MinimumFormBaseline $baseline, array $experiment ) {
		$variants = $this->experiments->variants( $experiment['id'] );
		if ( count( $variants ) !== 2 || empty( $experiment['winner_variant_id'] ) ) {
			return $this->integrity_failure( $run, $experiment, 'corrupt_promoted_experiment' );
		}
		$current_baseline = $this->repository->baseline_for_experiment( $experiment['id'] );
		if ( $current_baseline ) {
			$current_data = $current_baseline->data();
			if ( absint( $run['current_baseline_id'] ) !== absint( $current_data['id'] ) ) {
				if ( ! $this->repository->promote_pointer( $run['id'], $current_data['id'], $experiment['id'], $this->field_count( $run['original_field_count'], $current_baseline->mutations() ) ) ) {
					return $this->integrity_failure( $run, $experiment, 'baseline_pointer_failure' );
				}
			}
		} else {
			return $this->integrity_failure( $run, $experiment, 'baseline_storage_failure' );
		}
		$days                               = $this->runtime_days( $experiment['ended_at_utc'] );
		$control                            = $this->metrics( $this->experiments->aggregate( $experiment['id'] )[ $variants[0]['id'] ] ?? array() );
		$current                            = $this->metrics( $this->experiments->aggregate_since( $experiment['id'], $experiment['winner_variant_id'], $this->day_after_utc( $experiment['ended_at_utc'] ) ) );
		$policy                             = $experiment['policy'];
		$policy['business_value_objective'] = false;
		$guard                              = $this->guardrails->evaluate( $control, $current, apply_filters( 'formhawk_minimum_form_guardrails', $policy, $experiment ) );
		$settings                           = $this->experiments->settings( $form['id'] );
		$business_guard                     = $this->regressions->evaluate( $experiment, $variants, $policy, $settings ? $settings : array(), $this->day_after_utc( $experiment['ended_at_utc'] ), $days );
		if ( isset( $business_guard['analysis']['decision'] ) && 'integrity_failure' === $business_guard['analysis']['decision'] ) {
			return $this->integrity_failure( $run, $experiment, $business_guard['reason'] );
		}
		if ( $business_guard['triggered'] ) {
			$guard = $business_guard;
		}
		if ( $guard['triggered'] ) {
			if ( ! $this->experiments->rollback_experiment( $form['id'], $experiment['id'] ) ) {
				return false;
			}
			$current_data = $current_baseline->data();
			$parent       = ! empty( $current_data['parent_baseline_id'] ) ? $this->repository->baseline( $current_data['parent_baseline_id'] ) : $baseline;
			$parent_data  = $parent ? $parent->data() : array();
			if ( ! $parent || ! $parent_data || ! $this->repository->restore_pointer( $run['id'], $parent_data['id'], $experiment['id'], $this->field_count( $run['original_field_count'], $parent->mutations() ) ) ) {
				return $this->integrity_failure( $run, $experiment, 'baseline_pointer_failure' );
			}
			$history_recorded = $this->experiments->add_history(
				array(
					'form_id'            => $form['id'],
					'experiment_id'      => $experiment['id'],
					'decision'           => 'rollback',
					'previous_baseline'  => $current_baseline->mutations(),
					'resulting_baseline' => $parent->mutations(),
					'algorithm_version'  => self::ALGORITHM_VERSION,
					'reason'             => 'post_promotion_' . $guard['reason'],
				)
			);
			if ( ! $history_recorded || ! $this->record_decision( $run, $current_baseline, $experiment, array( 'reason' => 'post_promotion_' . $guard['reason'] ), 'rollback', $parent_data['id'] ) ) {
				return $this->integrity_failure( $run, $experiment, $history_recorded ? 'decision_storage_failure' : 'history_storage_failure' );
			}
			$this->cache->purge();
			do_action( 'formhawk_minimum_form_rollback', $run['id'], $current_data['id'] ?? 0, $parent_data['id'] );
			return true;
		}
		$business_metric = in_array( $experiment['primary_metric'], array( 'business_value', 'qualified_leads', 'won_leads' ), true );
		if ( $days >= absint( $experiment['policy']['promotion_monitor_days'] ) && ( ! $business_metric || ! empty( $business_guard['ready'] ) || $days >= absint( $experiment['policy']['maximum_runtime_days'] ) ) ) {
			if ( ! $this->experiments->set_status( $experiment['id'], ExperimentStatus::COMPLETED ) || ! $this->repository->release_experiment( $run['id'], $experiment['id'] ) ) {
				return $this->integrity_failure( $run, $experiment, 'experiment_release_failure' );
			}
			return true;
		}
		return true;
	}

	private function integrity_failure( array $run, array $experiment, $reason ) {
		$this->experiments->route_to_control( $experiment['id'] );
		$this->experiments->set_status( $experiment['id'], ExperimentStatus::PAUSED_GUARDRAIL );
		$this->experiments->integrity_warning( $experiment['id'], 'experiment_math_invalid' );
		$this->repository->set_status( $run['id'], 'integrity_failure', $reason );
		$this->cache->purge();
		return true;
	}

	private function schema_drift( array $run ) {
		if ( ! empty( $run['active_experiment_id'] ) ) {
			$this->experiments->route_to_control( $run['active_experiment_id'] );
			$this->experiments->set_status( $run['active_experiment_id'], ExperimentStatus::PAUSED_GUARDRAIL );
			$this->experiments->integrity_warning( $run['active_experiment_id'], 'schema_drift' );
		}
		$this->experiments->promote_baseline( $run['form_id'], array() );
		$this->repository->set_status( $run['id'], 'revalidation_required', 'schema_drift' );
		$this->cache->purge();
		return true;
	}

	private function record_decision( array $run, MinimumFormBaseline $baseline, array $experiment, array $analysis, $decision, $baseline_after = null ) {
		$probability   = $analysis['probability_to_be_best'] ?? null;
		$confidence    = null === $probability ? 'insufficient' : ( $probability >= 0.97 || $probability <= 0.03 ? 'high' : ( $probability >= 0.90 || $probability <= 0.10 ? 'medium' : 'low' ) );
		$baseline_data = $baseline->data();
		return $this->repository->add_decision(
			array(
				'run_id'              => $run['id'],
				'form_id'             => $run['form_id'],
				'field_definition_id' => $experiment['field_definition_id'],
				'experiment_id'       => $experiment['id'],
				'baseline_before_id'  => $baseline_data['id'],
				'baseline_after_id'   => $baseline_after,
				'mutation_type'       => $experiment['type'],
				'decision'            => $decision,
				'evidence_level'      => $experiment['evidence_level'],
				'primary_metric'      => $analysis['metric'] ?? $experiment['primary_metric'],
				'business_lift'       => $analysis['expected_lift'] ?? null,
				'probability'         => $probability,
				'expected_loss'       => $analysis['expected_loss'] ?? null,
				'control_value'       => $analysis['control_rate'] ?? null,
				'variant_value'       => $analysis['variant_rate'] ?? null,
				'control_visitors'    => $analysis['control_visitors'] ?? 0,
				'variant_visitors'    => $analysis['variant_visitors'] ?? 0,
				'control_confirmed'   => $analysis['control_confirmed'] ?? 0,
				'variant_confirmed'   => $analysis['variant_confirmed'] ?? 0,
				'confidence'          => $confidence,
				'reason'              => $analysis['reason'] ?? $decision,
			)
		);
	}

	private function merge_schema( array $rows, array $schema_fields ) {
		$indexed = array();
		foreach ( $schema_fields as $field ) {
			$indexed[ sanitize_key( $field['normalized_key'] ?? $field['key'] ?? '' ) ] = $field;
		}
		foreach ( $rows as &$row ) {
			$key = sanitize_key( $row['normalized_key'] );
			if ( isset( $indexed[ $key ] ) ) {
				$row = array_merge( $row, $indexed[ $key ], array( 'field_definition_id' => absint( $row['id'] ) ) );
			}
		}
		unset( $row );
		return $rows;
	}

	private function effective_fields( array $fields, array $mutations ) {
		$removed  = array();
		$required = array();
		foreach ( $mutations as $mutation ) {
			if ( ! is_array( $mutation ) || empty( $mutation['config']['field_key'] ) ) {
				continue;
			}
			$key  = sanitize_key( $mutation['config']['field_key'] );
			$type = sanitize_key( $mutation['type'] ?? '' );
			if ( ExperimentType::REMOVE_FIELD === $type ) {
				$removed[ $key ] = true;
			} elseif ( ExperimentType::MAKE_OPTIONAL === $type ) {
				$required[ $key ] = false;
			} elseif ( ExperimentType::MAKE_REQUIRED === $type ) {
				$required[ $key ] = true;
			}
		}
		$output = array();
		foreach ( $fields as $field ) {
			$key = sanitize_key( $field['normalized_key'] ?? $field['key'] ?? '' );
			if ( isset( $removed[ $key ] ) ) {
				continue;
			}
			if ( array_key_exists( $key, $required ) ) {
				$field['required']          = $required[ $key ];
				$field['provider_required'] = $required[ $key ];
			}
			$output[] = $field;
		}
		return $output;
	}

	private function needs_more_evidence( array $fields, FieldDependencyGraph $dependencies ) {
		$classifier = new FieldSafetyClassifier();
		foreach ( $fields as $field ) {
			$safety = $classifier->classify( $field );
			if ( in_array( $safety['safety'], array( FieldSafety::SAFE, FieldSafety::CAUTION ), true )
				&& $dependencies->can_remove( $field['normalized_key'] ?? '' )['safe']
				&& in_array( $field['verdict'] ?? 'unknown', array( 'unknown' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private function field_count( $original, array $mutations ) {
		$removed = array();
		foreach ( $mutations as $mutation ) {
			if ( is_array( $mutation ) && ExperimentType::REMOVE_FIELD === ( $mutation['type'] ?? '' ) && ! empty( $mutation['config']['field_key'] ) ) {
				$removed[] = $mutation['config']['field_key'];
			}
		}
		return max( 0, absint( $original ) - count( array_unique( $removed ) ) );
	}

	private function metrics( array $row ) {
		$row['assignments'] = absint( $row['assignments'] ?? 0 );
		$row['conversions'] = absint( $row['confirmed_successes'] ?? 0 );
		return $row;
	}

	private function runtime_days( $started_at ) {
		$started = $started_at ? strtotime( $started_at . ' UTC' ) : false;
		return $started ? max( 0, (int) floor( ( time() - $started ) / DAY_IN_SECONDS ) ) : 0;
	}

	private function day_after_utc( $timestamp ) {
		$local_date = get_date_from_gmt( (string) $timestamp, 'Y-m-d' );
		$date       = \DateTimeImmutable::createFromFormat( '!Y-m-d', $local_date, wp_timezone() );
		return $date ? $date->modify( '+1 day' )->format( 'Y-m-d' ) : current_time( 'Y-m-d' );
	}

	private function currency() {
		$settings = get_option( 'formhawk_field_roi_settings', array() );
		return is_array( $settings ) && isset( $settings['currency'] ) && preg_match( '/^[A-Z]{3}$/', $settings['currency'] ) ? $settings['currency'] : 'USD';
	}

	private function cro_objective( $objective ) {
		$map = array(
			'confirmed_conversion' => 'submissions',
			'qualified_leads'      => 'qualified_leads',
			'won_leads'            => 'won_leads',
			'revenue_per_visitor'  => 'business_value',
		);
		return isset( $map[ $objective ] ) ? $map[ $objective ] : 'auto';
	}

	private function restore_cro_settings( $form_id, $settings ) {
		if ( ! is_array( $settings ) || ! $settings ) {
			return $this->experiments->disable( $form_id );
		}
		return $this->experiments->restore_settings( $form_id, $settings );
	}
}
