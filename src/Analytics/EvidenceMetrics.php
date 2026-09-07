<?php

namespace Formhawk\Analytics;

use Formhawk\Domain\ProviderCatalog;

final class EvidenceMetrics {
	public static function confirmed_conversion( array $stats ) {
		if ( ! ProviderCatalog::has_server_success( isset( $stats['provider'] ) ? $stats['provider'] : '' ) ) {
			return null;
		}
		return self::ratio( $stats['confirmed_successes'] ?? 0, $stats['starts'] ?? 0 );
	}

	public static function observed_attempt_rate( array $stats ) {
		return self::ratio( $stats['submit_attempts'] ?? 0, $stats['starts'] ?? 0 );
	}

	public static function validation_rate( array $stats ) {
		if ( ! ProviderCatalog::has_server_validation( $stats['provider'] ?? '' ) ) {
			return null;
		}
		// Only post-upgrade observed validation rejections + accepted submissions are in this denominator.
		// It is a rejection share within these known provider outcomes, not all attempted submissions.
		return self::ratio( $stats['provider_validation_failures'] ?? 0, $stats['provider_validation_outcomes'] ?? 0 );
	}

	public static function ratio( $numerator, $denominator ) {
		$numerator   = (int) $numerator;
		$denominator = (int) $denominator;
		// Aggregate signals are unlinked and can be lost independently. Do not clamp a mismatch to 100%.
		return $denominator > 0 && $numerator >= 0 && $numerator <= $denominator ? ( $numerator / $denominator ) * 100 : null;
	}
}
