<?php

namespace Formhawk\CRO;

use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\Experiments\ExperimentStatus;
use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\Domain\ProviderCatalog;

final class AutopilotManager {
	const CRON_HOOK = 'formhawk_cro_hourly_evaluation';
	private $experiments;
	private $forms;
	private $opportunities;
	private $hypotheses;
	private $variants;
	private $policy;
	private $guardrails;
	private $winners;
	private $cache;
	private $business_winners;

	public function __construct( ExperimentRepository $experiments = null, FormRepository $forms = null, OpportunityDetector $opportunities = null, HypothesisEngine $hypotheses = null, VariantGenerator $variants = null, OptimizationPolicy $policy = null, GuardrailEvaluator $guardrails = null, WinnerSelector $winners = null, CacheCoordinator $cache = null, BusinessValueWinnerSelector $business_winners = null ) {
		$this->experiments      = $experiments ? $experiments : new ExperimentRepository();
		$this->forms            = $forms ? $forms : new FormRepository();
		$this->opportunities    = $opportunities ? $opportunities : new OpportunityDetector();
		$this->hypotheses       = $hypotheses ? $hypotheses : new HypothesisEngine();
		$this->variants         = $variants ? $variants : new VariantGenerator();
		$this->policy           = $policy ? $policy : new OptimizationPolicy();
		$this->guardrails       = $guardrails ? $guardrails : new GuardrailEvaluator();
		$this->winners          = $winners ? $winners : new WinnerSelector();
		$this->cache            = $cache ? $cache : new CacheCoordinator();
		$this->business_winners = $business_winners ? $business_winners : new BusinessValueWinnerSelector();
	}

	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'evaluate_all' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public function evaluate_all() {
		foreach ( $this->experiments->enabled_forms( 100 ) as $row ) {
			$this->evaluate_form( absint( $row['form_id'] ) );
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

	private function evaluate_locked( $form_id ) {
		$settings   = $this->experiments->settings( $form_id );
		$form       = $this->forms->find( $form_id );
		$experiment = $this->experiments->active_for_form( $form_id );
		if ( ! $settings || ! $form ) {
			return false;
		}
		if ( ! $experiment ) {
			$result = $this->create_next( $form, $settings );
			$this->experiments->touch_evaluated( $form_id, $result ? 'experiment_ready' : 'collecting' );
			return $result;
		}
		if ( ExperimentStatus::RUNNING === $experiment['status'] ) {
			$result = $this->evaluate_running( $form, $settings, $experiment );
			$this->experiments->touch_evaluated( $form_id );
			return $result;
		}
		if ( ExperimentStatus::PROMOTED_MONITORING === $experiment['status'] ) {
			$result = $this->evaluate_promotion( $form, $settings, $experiment );
			$this->experiments->touch_evaluated( $form_id );
			return $result;
		}
		if ( in_array( $experiment['status'], array( ExperimentStatus::AWAITING_APPROVAL, ExperimentStatus::SUGGESTED ), true ) && 'full' === $settings['mode'] ) {
			$started = $this->experiments->start( $experiment['id'] );
			if ( $started ) {
				// A cached page may predate this experiment and therefore omit the runtime entirely.
				$this->cache->purge();
			}
			return $started;
		}
		$this->experiments->touch_evaluated( $form_id );
		return true;
	}

	private function create_next( array $form, array $settings ) {
		$excluded    = array_merge( $this->experiments->completed_opportunities( $form['id'] ), $this->baseline_exclusions( $settings['baseline'] ) );
		$opportunity = $this->opportunities->detect( $form, array_values( array_unique( $excluded ) ) );
		if ( ! $opportunity ) {
			return false;
		}
		$policy                = $this->policy->for_form( $settings );
		$policy['opportunity'] = array_intersect_key(
			$opportunity,
			array_flip( array( 'type', 'field_key', 'field_label', 'impact_score', 'confidence_score', 'risk_score', 'sample_size', 'estimated_upside' ) )
		);
		$variants              = $this->variants->generate( $form, $opportunity, $settings['baseline'] );
		if ( ! $variants ) {
			return false;
		}
		$status = 'observe' === $settings['mode'] ? ExperimentStatus::SUGGESTED : ( 'approve' === $settings['mode'] ? ExperimentStatus::AWAITING_APPROVAL : ExperimentStatus::RUNNING );
		$id     = $this->experiments->create(
			array(
				'form_id'        => $form['id'],
				'type'           => $opportunity['type'],
				'status'         => $status,
				'hypothesis'     => $this->hypotheses->create( $opportunity ),
				'primary_metric' => $this->primary_metric( $form, $settings ),
				'evidence_level' => ProviderCROCapabilities::evidence_level( $form['provider'] ),
				'policy'         => $policy,
			),
			array( $variants['control'], $variants['variant'] )
		);
		if ( $id && ExperimentStatus::RUNNING === $status ) {
			$this->experiments->set_status( $id, ExperimentStatus::RUNNING, array( 'started_at_utc' => current_time( 'mysql', true ) ) );
			do_action( 'formhawk_cro_experiment_started', $id );
			$this->cache->purge();
		}
		return (bool) $id;
	}

	private function baseline_exclusions( array $baseline ) {
		$types = array();
		foreach ( $baseline as $mutation ) {
			if ( is_array( $mutation ) && isset( $mutation['type'] ) ) {
				$types[] = sanitize_key( $mutation['type'] );
			}
		}
		if ( array_intersect( $types, array( ExperimentType::PROGRESSIVE_DISCLOSURE, ExperimentType::MULTI_STEP ) ) ) {
			return array( ExperimentType::FIELD_ORDER, ExperimentType::PROGRESSIVE_DISCLOSURE, ExperimentType::MULTI_STEP );
		}
		return array();
	}

	private function evaluate_running( array $form, array $settings, array $experiment ) {
		$variants = $this->experiments->variants( $experiment['id'] );
		$totals   = $this->experiments->aggregate( $experiment['id'] );
		if ( count( $variants ) !== 2 ) {
			$this->experiments->route_to_control( $experiment['id'] );
			return $this->finish( $form, $settings, $experiment, 'stopped_guardrail', 'corrupt_variant_configuration' );
		}
		$control      = $this->metrics( $totals[ $variants[0]['id'] ] ?? array(), $experiment['primary_metric'] );
		$variant      = $this->metrics( $totals[ $variants[1]['id'] ] ?? array(), $experiment['primary_metric'] );
		$guard_policy = $this->guardrail_policy( $experiment );
		$guard        = $this->guardrails->evaluate( $control, $variant, $guard_policy );
		if ( $guard['triggered'] ) {
			$this->experiments->route_to_control( $experiment['id'] );
			$this->experiments->set_status( $experiment['id'], ExperimentStatus::PAUSED_GUARDRAIL );
			$this->cache->purge();
			do_action( 'formhawk_cro_variant_rejected', $experiment['id'], $variants[1]['id'], $guard['reason'] );
			return $this->finish( $form, $settings, $experiment, 'stopped_guardrail', $guard['reason'], null, $variants[0]['id'] );
		}
		foreach ( $this->experiments->aggregate_by_segment( $experiment['id'] ) as $segment => $segment_totals ) {
			if ( ! isset( $segment_totals[ $variants[0]['id'] ], $segment_totals[ $variants[1]['id'] ] ) ) {
				continue;
			}
			$segment_control = $this->metrics( $segment_totals[ $variants[0]['id'] ], $experiment['primary_metric'] );
			$segment_variant = $this->metrics( $segment_totals[ $variants[1]['id'] ], $experiment['primary_metric'] );
			if ( $segment_control['views'] < $experiment['policy']['guardrail_minimum_views'] || $segment_variant['views'] < $experiment['policy']['guardrail_minimum_views'] ) {
				continue;
			}
			$segment_guard = $this->guardrails->evaluate( $segment_control, $segment_variant, $guard_policy );
			if ( $segment_guard['triggered'] ) {
				$this->experiments->route_to_control( $experiment['id'] );
				$this->cache->purge();
				do_action( 'formhawk_cro_variant_rejected', $experiment['id'], $variants[1]['id'], 'segment_' . $segment . '_' . $segment_guard['reason'] );
				return $this->finish( $form, $settings, $experiment, 'stopped_guardrail', 'segment_' . $segment . '_' . $segment_guard['reason'], null, $variants[0]['id'] );
			}
		}
		$days     = $this->runtime_days( $experiment['started_at_utc'] );
		$analysis = in_array( $experiment['primary_metric'], array( 'business_value', 'qualified_leads', 'won_leads' ), true )
			? $this->business_winners->select( $experiment, $variants, $experiment['policy'], $settings, $days )
			: $this->winners->select( $control, $variant, $experiment['policy'], $days );
		if ( 'winner' === $analysis['decision'] ) {
			$baseline = isset( $variants[1]['config']['mutations'] ) && is_array( $variants[1]['config']['mutations'] ) ? $variants[1]['config']['mutations'] : array();
			if ( ! $this->experiments->promote_experiment( $form['id'], $experiment['id'], $variants[1]['id'], $baseline ) ) {
				$this->experiments->route_to_control( $experiment['id'] );
				return $this->finish( $form, $settings, $experiment, 'stopped_guardrail', 'promotion_storage_failure' );
			}
			$this->cache->purge();
			$this->history( $form, $experiment, 'winner', $analysis, $settings['baseline'], $baseline );
			do_action( 'formhawk_cro_winner_selected', $experiment['id'], $variants[1]['id'], $analysis );
			do_action( 'formhawk_cro_winner_promoted', $experiment['id'], $variants[1]['id'] );
			return true;
		}
		if ( 'reject' === $analysis['decision'] || 'inconclusive' === $analysis['decision'] ) {
			return $this->finish( $form, $settings, $experiment, $analysis['decision'], $analysis['reason'], $analysis, $variants[0]['id'] );
		}
		return true;
	}

	private function evaluate_promotion( array $form, array $settings, array $experiment ) {
		$monitor_days = $this->runtime_days( $experiment['ended_at_utc'] );
		if ( $monitor_days < 1 ) {
			return true;
		}
		$variants = $this->experiments->variants( $experiment['id'] );
		$totals   = $this->experiments->aggregate( $experiment['id'] );
		if ( count( $variants ) !== 2 || ! $experiment['winner_variant_id'] ) {
			return false;
		}
		$control = $this->metrics( $totals[ $variants[0]['id'] ] ?? array(), $experiment['primary_metric'] );
		$since   = $this->day_after_utc( $experiment['ended_at_utc'] );
		$current = $this->metrics( $this->experiments->aggregate_since( $experiment['id'], $experiment['winner_variant_id'], $since ), $experiment['primary_metric'] );
		$guard   = $this->guardrails->evaluate( $control, $current, $this->guardrail_policy( $experiment ) );
		if ( $guard['triggered'] ) {
			if ( ! $this->experiments->rollback_experiment( $form['id'], $experiment['id'] ) ) {
				return false;
			}
			$this->cache->purge();
			$this->history( $form, $experiment, 'rollback', array( 'reason' => 'post_promotion_' . $guard['reason'] ), $settings['baseline'], $settings['previous_baseline'] );
			do_action( 'formhawk_cro_rollback', $experiment['id'], $experiment['winner_variant_id'] );
			return true;
		}
		if ( ! $this->is_business_metric( $experiment['primary_metric'] ) && $current['views'] >= $experiment['policy']['minimum_views_per_variant'] && $control['views'] >= $experiment['policy']['minimum_views_per_variant'] ) {
			$analysis     = $this->winners->select( $control, $current, array_merge( $experiment['policy'], array( 'minimum_runtime_days' => 1 ) ), $monitor_days );
			$relative     = $control['conversions'] / max( 1, $control['views'] );
			$current_rate = $current['conversions'] / max( 1, $current['views'] );
			if ( $relative > 0 && $current_rate < $relative * ( 1 - $experiment['policy']['rollback_relative_drop'] ) && ( 1 - $analysis['probability_to_be_best'] ) >= $experiment['policy']['probability_to_be_best'] ) {
				if ( ! $this->experiments->rollback_experiment( $form['id'], $experiment['id'] ) ) {
					return false;
				}
				$this->cache->purge();
				$this->history( $form, $experiment, 'rollback', $analysis, $settings['baseline'], $settings['previous_baseline'] );
				do_action( 'formhawk_cro_rollback', $experiment['id'], $experiment['winner_variant_id'] );
				return true;
			}
		}
		if ( $monitor_days >= $experiment['policy']['promotion_monitor_days'] ) {
			$this->experiments->set_status( $experiment['id'], ExperimentStatus::COMPLETED );
			$this->experiments->touch_evaluated( $form['id'], 'active' );
			return true;
		}
		return true;
	}

	private function finish( array $form, array $settings, array $experiment, $decision, $reason, $analysis = null, $winner_variant_id = null ) {
		$status = 'inconclusive' === $decision ? ExperimentStatus::INCONCLUSIVE : ExperimentStatus::REJECTED;
		$this->experiments->set_status(
			$experiment['id'],
			$status,
			array(
				'ended_at_utc'      => current_time( 'mysql', true ),
				'winner_variant_id' => $winner_variant_id,
			)
		);
		$record = is_array( $analysis ) ? $analysis : array();
		if ( ! isset( $record['reason'] ) ) {
			$record['reason'] = $reason;
		}
		$this->history( $form, $experiment, $decision, $record, $settings['baseline'], $settings['baseline'] );
		return true;
	}

	private function history( array $form, array $experiment, $decision, array $analysis, array $previous, array $resulting ) {
		$this->experiments->add_history(
			array(
				'form_id'            => $form['id'],
				'experiment_id'      => $experiment['id'],
				'decision'           => $decision,
				'lift'               => $analysis['expected_lift'] ?? null,
				'probability'        => $analysis['probability_to_be_best'] ?? null,
				'expected_loss'      => $analysis['expected_loss'] ?? null,
				'previous_baseline'  => $previous,
				'resulting_baseline' => $resulting,
				'algorithm_version'  => $experiment['algorithm_version'],
				'policy_version'     => $experiment['policy_version'],
				'reason'             => $analysis['reason'] ?? $decision,
			)
		);
	}

	private function metrics( array $row, $primary_metric ) {
		$row['views']       = absint( $row['views'] ?? 0 );
		$row['conversions'] = 'observed_submit_rate' === $primary_metric ? absint( $row['observed_submits'] ?? 0 ) : absint( $row['confirmed_successes'] ?? 0 );
		return $row;
	}

	private function primary_metric( array $form, array $settings ) {
		if ( ! ProviderCatalog::has_server_success( $form['provider'] ) ) {
			return 'observed_submit_rate'; }
		$objective = isset( $settings['optimization_objective'] ) ? $settings['optimization_objective'] : 'auto';
		$field_roi = get_option( 'formhawk_field_roi_settings', array() );
		if ( 'auto' === $objective && is_array( $field_roi ) && ! empty( $field_roi['enabled'] ) ) {
			$objective = isset( $field_roi['objective'] ) ? $field_roi['objective'] : 'business_value';
			$objective = 'qualified' === $objective ? 'qualified_leads' : ( 'won' === $objective ? 'won_leads' : $objective );
		}
		return in_array( $objective, array( 'business_value', 'qualified_leads', 'won_leads' ), true ) ? $objective : 'confirmed_conversion';
	}

	private function guardrail_policy( array $experiment ) {
		$policy                             = $experiment['policy'];
		$policy['business_value_objective'] = $this->is_business_metric( $experiment['primary_metric'] );
		return $policy;
	}

	private function is_business_metric( $metric ) {
		return in_array( $metric, array( 'business_value', 'qualified_leads', 'won_leads' ), true );
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
}
