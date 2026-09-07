<?php

namespace Formhawk\ROI;

use Formhawk\CRO\StatisticalEngine;

final class FieldImpactCalculator {
	private $revenue;
	private $binary;

	public function __construct( FieldValueModel $revenue = null, StatisticalEngine $binary = null ) {
		$this->revenue = $revenue ? $revenue : new FieldValueModel();
		$this->binary  = $binary ? $binary : new StatisticalEngine();
	}

	public function calculate( array $control, array $variant ) {
		$c_visitors                               = max( 0, absint( isset( $control['visitors'] ) ? $control['visitors'] : 0 ) );
		$v_visitors                               = max( 0, absint( isset( $variant['visitors'] ) ? $variant['visitors'] : 0 ) );
		$metrics                                  = array(
			'control_visitors'    => $c_visitors,
			'variant_visitors'    => $v_visitors,
			'control_submissions' => absint( isset( $control['submissions'] ) ? $control['submissions'] : 0 ),
			'variant_submissions' => absint( isset( $variant['submissions'] ) ? $variant['submissions'] : 0 ),
			'control_qualified'   => absint( isset( $control['qualified'] ) ? $control['qualified'] : 0 ),
			'variant_qualified'   => absint( isset( $variant['qualified'] ) ? $variant['qualified'] : 0 ),
			'control_won'         => absint( isset( $control['won'] ) ? $control['won'] : 0 ),
			'variant_won'         => absint( isset( $variant['won'] ) ? $variant['won'] : 0 ),
		);
		$metrics['submission_rate_control']       = $this->rate( $metrics['control_submissions'], $c_visitors );
		$metrics['submission_rate_variant']       = $this->rate( $metrics['variant_submissions'], $v_visitors );
		$metrics['qualified_per_visitor_control'] = $this->rate( $metrics['control_qualified'], $c_visitors );
		$metrics['qualified_per_visitor_variant'] = $this->rate( $metrics['variant_qualified'], $v_visitors );
		$metrics['won_per_visitor_control']       = $this->rate( $metrics['control_won'], $c_visitors );
		$metrics['won_per_visitor_variant']       = $this->rate( $metrics['variant_won'], $v_visitors );
		$metrics['submission_impact']             = $this->relative( $metrics['submission_rate_control'], $metrics['submission_rate_variant'] );
		$metrics['qualified_impact']              = $this->relative( $metrics['qualified_per_visitor_control'], $metrics['qualified_per_visitor_variant'] );
		$metrics['won_impact']                    = $this->relative( $metrics['won_per_visitor_control'], $metrics['won_per_visitor_variant'] );
		$metrics['outcome_coverage']              = max( 0, min( 1, (float) ( isset( $variant['coverage'] ) ? $variant['coverage'] : 0 ) ) );
		$metrics['maturing_submissions']          = absint( isset( $control['maturing'] ) ? $control['maturing'] : 0 ) + absint( isset( $variant['maturing'] ) ? $variant['maturing'] : 0 );

		$binary_policy                             = array(
			'prior_alpha'               => 0.5,
			'prior_beta'                => 0.5,
			'minimum_views_per_variant' => 1,
			'minimum_conversions'       => 1,
			'minimum_runtime_days'      => 0,
			'probability_to_be_best'    => 0.95,
			'maximum_expected_loss'     => 1,
			'minimum_absolute_effect'   => 0,
			'maximum_runtime_days'      => 99999,
		);
		$quality                                   = $this->binary->evaluate(
			array(
				'views'       => $c_visitors,
				'conversions' => $metrics['control_qualified'],
			),
			array(
				'views'       => $v_visitors,
				'conversions' => $metrics['variant_qualified'],
			),
			$binary_policy,
			1
		);
		$metrics['quality_probability_beneficial'] = $quality['probability_to_be_best'];

		$c_values                                     = isset( $control['revenue_values'] ) && is_array( $control['revenue_values'] ) ? $control['revenue_values'] : array();
		$v_values                                     = isset( $variant['revenue_values'] ) && is_array( $variant['revenue_values'] ) ? $variant['revenue_values'] : array();
		$metrics['revenue']                           = $this->revenue->compare( $c_values, $c_visitors, $v_values, $v_visitors, absint( isset( $variant['seed'] ) ? $variant['seed'] : 104729 ) );
		$metrics['revenue_impact']                    = $this->relative( $metrics['revenue']['control_rpv_minor'], $metrics['revenue']['variant_rpv_minor'] );
		$metrics['expected_value_contribution_minor'] = $metrics['revenue']['impact_rpv_minor'] * $v_visitors;
		return $metrics;
	}

	private function rate( $successes, $visitors ) {
		return $visitors > 0 ? $successes / $visitors : null; }
	private function relative( $control, $variant ) {
		return null !== $control && null !== $variant && abs( $control ) > 1.0E-12 ? ( $variant - $control ) / $control : null; }
}
