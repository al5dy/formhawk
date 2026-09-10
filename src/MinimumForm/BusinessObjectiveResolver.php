<?php

namespace Formhawk\MinimumForm;

use Formhawk\ROI\FieldROIRepository;

/** Chooses the strongest sufficiently covered per-visitor business objective. */
final class BusinessObjectiveResolver {
	private $roi;

	public function __construct( ?FieldROIRepository $roi = null ) {
		$this->roi = $roi ? $roi : new FieldROIRepository();
	}

	public function resolve( $form_id, $requested = 'auto', $currency = 'USD' ) {
		$end       = current_time( 'Y-m-d' );
		$start     = wp_date( 'Y-m-d', time() - 89 * DAY_IN_SECONDS, wp_timezone() );
		$summary   = $this->roi->form_summary( absint( $form_id ), $start, $end, $currency );
		$known     = absint( $summary['known'] ?? 0 );
		$submitted = absint( $summary['submissions'] ?? 0 );
		$coverage  = $submitted ? min( 1, $known / $submitted ) : 0;
		$metric    = 'confirmed_conversion';
		$reason    = 'business_outcomes_unavailable';
		if ( $coverage >= 0.60 && absint( $summary['revenue_samples'] ?? 0 ) >= 20 && in_array( $requested, array( 'auto', 'revenue_per_visitor' ), true ) ) {
			$metric = 'business_value';
			$reason = 'revenue_coverage_sufficient';
		} elseif ( $coverage >= 0.60 && absint( $summary['won'] ?? 0 ) >= 20 && in_array( $requested, array( 'auto', 'won_leads' ), true ) ) {
			$metric = 'won_leads';
			$reason = 'won_outcome_coverage_sufficient';
		} elseif ( $coverage >= 0.60 && absint( $summary['qualified'] ?? 0 ) >= 20 && in_array( $requested, array( 'auto', 'qualified_leads', 'won_leads', 'revenue_per_visitor' ), true ) ) {
			$metric = 'qualified_leads';
			$reason = 'qualified_outcome_coverage_sufficient';
		}
		/**
		 * @param string $metric    Selected CRO storage metric.
		 * @param array  $summary   Mature form outcome summary.
		 * @param string $requested Requested objective.
		 */
		$filtered = apply_filters( 'formhawk_minimum_form_primary_metric', $metric, $summary, $requested );
		$allowed  = array( 'business_value', 'qualified_leads', 'won_leads', 'confirmed_conversion' );
		$metric   = in_array( $filtered, $allowed, true ) ? $filtered : $metric;
		return array(
			'metric'             => $metric,
			'business_optimized' => 'confirmed_conversion' !== $metric,
			'coverage'           => $coverage,
			'summary'            => $summary,
			'reason'             => $reason,
		);
	}
}
