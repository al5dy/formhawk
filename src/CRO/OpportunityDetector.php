<?php

namespace Formhawk\CRO;

use Formhawk\Analytics\AnalyticsRepository;
use Formhawk\CRO\Experiments\ExperimentType;

final class OpportunityDetector {
	private $analytics;

	public function __construct( AnalyticsRepository $analytics = null ) {
		$this->analytics = $analytics ? $analytics : new AnalyticsRepository();
	}

	public function detect( array $form, array $excluded_opportunities = array() ) {
		$form_id = absint( isset( $form['id'] ) ? $form['id'] : 0 );
		$stats   = $this->analytics->form_stats( $form_id, 30 );
		$fields  = $this->analytics->field_stats( $form_id, 30 );
		$starts  = absint( isset( $stats['starts'] ) ? $stats['starts'] : 0 );
		$views   = absint( isset( $stats['views'] ) ? $stats['views'] : 0 );
		if ( $views < 200 || $starts < 100 ) {
			return null;
		}

		$opportunities = array();
		foreach ( $fields as $field ) {
			if ( ! $this->field_is_safe_candidate( $field ) ) {
				continue;
			}
			$interactions = max( 1, absint( $field['interactions'] ) );
			$friction     = absint( $field['abandonments'] ) + absint( $field['provider_validation_errors'] ) + ( 0.35 * absint( $field['client_validation_errors'] ) );
			if ( $interactions >= 50 && $friction / $interactions >= 0.15 ) {
				$opportunities[] = array(
					'type'             => ExperimentType::FIELD_ORDER,
					'field_key'        => $field['field_key'],
					'field_label'      => $field['field_label'] ? $field['field_label'] : $field['field_key'],
					'impact_score'     => min( 100, round( 100 * $friction / $interactions ) ),
					'confidence_score' => min( 100, round( 100 * min( 1, $interactions / 300 ) ) ),
					'risk_score'       => 25,
					'sample_size'      => $interactions,
					'estimated_upside' => min( 0.25, $friction / max( 1, $starts ) ),
				);
			}
			if ( $interactions >= 30 && $interactions < $starts * 0.45 && absint( $field['abandonments'] ) >= 10 ) {
				$opportunities[] = array(
					'type'             => ExperimentType::PROGRESSIVE_DISCLOSURE,
					'field_key'        => $field['field_key'],
					'field_label'      => $field['field_label'] ? $field['field_label'] : $field['field_key'],
					'impact_score'     => min( 100, (int) round( 100 * absint( $field['abandonments'] ) / $interactions ) ),
					'confidence_score' => min( 100, (int) round( $interactions / 3 ) ),
					'risk_score'       => 15,
					'sample_size'      => $interactions,
					'estimated_upside' => min( 0.15, absint( $field['abandonments'] ) / max( 1, $starts ) ),
				);
			}
		}

		$attempts = absint( isset( $stats['submit_attempts'] ) ? $stats['submit_attempts'] : 0 );
		if ( $starts >= 100 && $attempts / max( 1, $starts ) < 0.65 ) {
			$opportunities[] = array(
				'type'             => ExperimentType::SUBMIT_BUTTON,
				'impact_score'     => (int) round( 100 * ( 1 - $attempts / max( 1, $starts ) ) ),
				'confidence_score' => min( 100, (int) round( $starts / 5 ) ),
				'risk_score'       => 5,
				'sample_size'      => $starts,
				'estimated_upside' => min( 0.15, ( 1 - $attempts / max( 1, $starts ) ) * 0.15 ),
			);
		}

		if ( count( $fields ) >= 8 && count( array_filter( $fields, array( $this, 'field_is_safe_candidate' ) ) ) === count( $fields ) ) {
			$opportunities[] = array(
				'type'             => ExperimentType::MULTI_STEP,
				'impact_score'     => min( 100, count( $fields ) * 7 ),
				'confidence_score' => min( 100, (int) round( $starts / 5 ) ),
				'risk_score'       => 45,
				'sample_size'      => $starts,
				'estimated_upside' => 0.10,
			);
		}

		$opportunities = array_values(
			array_filter(
				$opportunities,
				static function ( $opportunity ) use ( $excluded_opportunities, $form ) {
					$key = $opportunity['type'] . '|' . ( isset( $opportunity['field_key'] ) ? $opportunity['field_key'] : '' );
					return ! in_array( $opportunity['type'], $excluded_opportunities, true )
						&& ! in_array( $key, $excluded_opportunities, true )
						&& ProviderCROCapabilities::supports( $form['provider'], $opportunity['type'] );
				}
			)
		);
		usort(
			$opportunities,
			static function ( $left, $right ) {
				$left_score  = $left['impact_score'] * $left['confidence_score'] * ( 100 - $left['risk_score'] );
				$right_score = $right['impact_score'] * $right['confidence_score'] * ( 100 - $right['risk_score'] );
				return $right_score <=> $left_score;
			}
		);

		/**
		 * @param array $opportunities Ranked structural opportunities.
		 * @param array $form Form metadata without visitor data.
		 */
		$opportunities = apply_filters( 'formhawk_cro_opportunities', $opportunities, $form );
		return is_array( $opportunities ) && $opportunities ? reset( $opportunities ) : null;
	}

	public function field_is_safe_candidate( array $field ) {
		$type        = strtolower( (string) ( $field['field_type'] ?? '' ) );
		$description = strtolower( (string) ( $field['field_key'] ?? '' ) . ' ' . (string) ( $field['field_label'] ?? '' ) . ' ' . $type );
		if ( preg_match( '/(?:password|passcode|payment|card|cvv|cvc|otp|captcha|turnstile|nonce|csrf|terms|privacy|gdpr|consent|agreement|signature|auth|login|security|file)/i', $description ) ) {
			return false;
		}
		return ! in_array( $type, array( 'password', 'file', 'hidden', 'checkbox', 'radio', 'acceptance', 'signature', 'captcha', 'recaptcha', 'turnstile' ), true );
	}
}
