<?php

namespace Formhawk\CRO;

use Formhawk\Domain\ProviderCatalog;

/** Client observations remain useful reporting, but are not autonomous decision authority. */
final class DecisionEvidence {
	const SERVER_CONFIRMED = 'server_confirmed';
	const CLIENT_OBSERVED  = 'client_observed';

	public static function allows_autonomy( array $experiment, $provider ) {
		return ProviderCatalog::has_server_success( $provider )
			&& (int) ( $experiment['integrity_version'] ?? 1 ) >= 2
			&& in_array( $experiment['primary_metric'], array( 'confirmed_conversion', 'business_value', 'qualified_leads', 'won_leads' ), true );
	}

	/** Adapt server evidence to the statistical engine's existing sample-size API. Never rewrite stored views. */
	public static function server_sample( array $row ) {
		return array(
			'views'       => absint( $row['assignments'] ?? 0 ),
			'conversions' => absint( $row['confirmed_successes'] ?? 0 ),
		);
	}
}
