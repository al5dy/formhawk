<?php

namespace Formhawk\CRO;

use Formhawk\Domain\ProviderCatalog;

final class FormOptimizationScore {
	public function calculate( array $stats, array $fields, $provider = '' ) {
		$views     = max( 1, absint( $stats['views'] ?? 0 ) );
		$starts    = absint( $stats['starts'] ?? 0 );
		$confirmed = ProviderCatalog::has_server_success( $provider );
		$attempts  = absint( $stats['submit_attempts'] ?? 0 );
		$successes = $confirmed ? absint( $stats['confirmed_successes'] ?? 0 ) : $attempts;
		$valid_den = $confirmed ? $successes + absint( $stats['provider_validation_failures'] ?? 0 ) : $starts;
		// Fifteen percent is the versioned normalization ceiling, not an invented
		// conversion count. Raw evidence remains visible separately in the UI.
		$conversion          = min( 100, ( $successes / $views ) / 0.15 * 100 );
		$completion          = min( 100, 100 * $attempts / max( 1, $starts ) );
		$validation_failures = $confirmed ? absint( $stats['provider_validation_failures'] ?? 0 ) : min( $starts, absint( $stats['client_validation_failures'] ?? 0 ) );
		$validation          = $valid_den ? max( 0, 100 * ( 1 - $validation_failures / $valid_den ) ) : 50;
		$friction_events     = 0;
		$interactions        = 0;
		foreach ( $fields as $field ) {
			$validation_errors = $confirmed ? absint( $field['provider_validation_errors'] ?? 0 ) : absint( $field['client_validation_errors'] ?? 0 );
			$friction_events  += absint( $field['abandonments'] ?? 0 ) + $validation_errors;
			$interactions     += absint( $field['interactions'] ?? 0 );
		}
		$friction   = $interactions ? max( 0, 100 * ( 1 - $friction_events / $interactions ) ) : 50;
		$confidence = $views >= 1000 ? 'high' : ( $views >= 250 ? 'medium' : 'low' );
		// Components are normalized evidence ratios; weights are versioned product
		// policy, not randomly generated values.
		$total = round( 0.40 * $conversion + 0.25 * $completion + 0.20 * $validation + 0.15 * $friction );
		return array(
			'score'          => max( 0, min( 100, (int) $total ) ),
			'conversion'     => round( $conversion ),
			'field_friction' => round( $friction ),
			'validation'     => round( $validation ),
			'completion'     => round( $completion ),
			'confidence'     => $confidence,
			'version'        => '1.0',
		);
	}
}
