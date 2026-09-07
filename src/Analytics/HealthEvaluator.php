<?php

namespace Formhawk\Analytics;

use Formhawk\Domain\ProviderCatalog;

final class HealthEvaluator {
	public static function evaluate( array $row ) {
		$last_success      = self::timestamp( isset( $row['last_success_at'] ) ? $row['last_success_at'] : null );
		$last_failure      = self::timestamp( isset( $row['last_failure_at'] ) ? $row['last_failure_at'] : null );
		$starts            = isset( $row['starts'] ) ? absint( $row['starts'] ) : 0;
		$provider_outcomes = isset( $row['provider_validation_outcomes'] ) ? absint( $row['provider_validation_outcomes'] ) : 0;
		$attempts          = isset( $row['submit_attempts'] ) ? absint( $row['submit_attempts'] ) : 0;
		$confirmed         = isset( $row['confirmed_successes'] ) ? absint( $row['confirmed_successes'] ) : 0;
		$validation        = isset( $row['provider_validation_failures'] ) ? absint( $row['provider_validation_failures'] ) : 0;
		$views             = isset( $row['views'] ) ? absint( $row['views'] ) : 0;
		$provider          = isset( $row['provider'] ) ? (string) $row['provider'] : ProviderCatalog::GENERIC;

		if ( $last_failure && ( ! $last_success || $last_failure > $last_success ) ) {
			return array(
				'status' => 'critical',
				'label'  => __( 'Critical', 'formhawk' ),
				'reason' => __( 'A submission or mail failure occurred after the last confirmed success.', 'formhawk' ),
			);
		}
		if ( $starts >= 5 && 0 === $attempts && 0 === $confirmed ) {
			if ( ProviderCatalog::has_server_success( $provider ) ) {
				return array(
					'status' => 'warning',
					'label'  => __( 'Warning', 'formhawk' ),
					'reason' => __( 'Visitors are starting this form, but no provider-confirmed success is recorded in this period.', 'formhawk' ),
				);
			}
			return array(
				'status' => 'warning',
				'label'  => __( 'Warning', 'formhawk' ),
				'reason' => __( 'Visitors are starting this form, but no browser submit attempts were observed in this period.', 'formhawk' ),
			);
		}
		if ( ProviderCatalog::has_server_success( $provider ) && $attempts >= 5 && 0 === $confirmed ) {
			return array(
				'status' => 'warning',
				'label'  => __( 'Warning', 'formhawk' ),
				'reason' => __( 'Submit attempts are being observed, but the provider has not confirmed a successful submission in this period.', 'formhawk' ),
			);
		}
		$validation_rate = EvidenceMetrics::validation_rate( $row );
		if ( $provider_outcomes >= 10 && $validation >= 5 && null !== $validation_rate && $validation_rate >= 50 ) {
			return array(
				'status' => 'warning',
				'label'  => __( 'Warning', 'formhawk' ),
				'reason' => __( 'At least half of the observed provider validation rejections and accepted submissions were validation rejections. Other or unknown outcomes are excluded.', 'formhawk' ),
			);
		}
		if ( $views >= 20 && 0 === $starts ) {
			return array(
				'status' => 'warning',
				'label'  => __( 'Warning', 'formhawk' ),
				'reason' => __( 'The form is being viewed but nobody has started it.', 'formhawk' ),
			);
		}
		if ( ProviderCatalog::has_server_success( $provider ) && $confirmed > 0 ) {
			return array(
				'status' => 'healthy',
				'label'  => __( 'Healthy', 'formhawk' ),
				'reason' => __( 'Provider-confirmed successes are being observed and no newer failure is recorded.', 'formhawk' ),
			);
		}
		if ( ! ProviderCatalog::has_server_success( $provider ) && $attempts > 0 ) {
			return array(
				'status' => 'collecting',
				'label'  => __( 'Observed', 'formhawk' ),
				'reason' => __( 'Browser submit attempts are observed. Backend success and form health cannot be confirmed for this form.', 'formhawk' ),
			);
		}
		return array(
			'status' => 'collecting',
			'label'  => __( 'Collecting', 'formhawk' ),
			'reason' => __( 'Not enough activity yet to judge form health.', 'formhawk' ),
		);
	}

	public static function conversion_rate( array $stats ) {
		return EvidenceMetrics::confirmed_conversion( $stats );
	}

	public static function abandonment_rate( array $stats ) {
		$starts = isset( $stats['starts'] ) ? (int) $stats['starts'] : 0;
		return $starts > 0 ? ( (int) $stats['abandons'] / $starts ) * 100 : 0.0;
	}

	public static function anomaly( array $current, array $previous ) {
		$current_starts  = isset( $current['starts'] ) ? (int) $current['starts'] : 0;
		$previous_starts = isset( $previous['starts'] ) ? (int) $previous['starts'] : 0;
		if ( $current_starts < 5 || $previous_starts < 5 ) {
			return null;
		}
		$current_rate  = self::conversion_rate( $current );
		$previous_rate = self::conversion_rate( $previous );
		if ( null === $current_rate || null === $previous_rate || $previous_rate <= 0 ) {
			return null;
		}
		$drop = ( ( $previous_rate - $current_rate ) / $previous_rate ) * 100;
		if ( $drop < 25 ) {
			return null;
		}
		return array(
			'drop'          => $drop,
			'current_rate'  => $current_rate,
			'previous_rate' => $previous_rate,
		);
	}

	private static function timestamp( $value ) {
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}
		$datetime = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $value, wp_timezone() );
		return $datetime ? $datetime->getTimestamp() : 0;
	}
}
