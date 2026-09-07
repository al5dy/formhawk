<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Analytics\EvidenceMetrics;
use Formhawk\Analytics\HealthEvaluator;
use PHPUnit\Framework\TestCase;

final class EvidenceMetricsTest extends TestCase {
	public function test_generic_confirmations_are_unavailable_and_legacy_submissions_are_ignored() {
		$stats = array(
			'provider'            => 'html',
			'starts'              => 10,
			'submit_attempts'     => 4,
			'submissions'         => 999,
			'confirmed_successes' => 999,
		);
		$this->assertNull( EvidenceMetrics::confirmed_conversion( $stats ) );
		$this->assertSame( 40.0, EvidenceMetrics::observed_attempt_rate( $stats ) );
		$this->assertNull( EvidenceMetrics::validation_rate( $stats ) );
		$this->assertNull( HealthEvaluator::anomaly( $stats, $stats ) );
	}

	public function test_confirmed_conversion_uses_only_confirmations_and_requires_a_coherent_denominator() {
		$stats = array(
			'provider'            => 'wpforms',
			'starts'              => 10,
			'submissions'         => 999,
			'confirmed_successes' => 3,
		);
		$this->assertSame( 30.0, EvidenceMetrics::confirmed_conversion( $stats ) );
		$this->assertNull( EvidenceMetrics::confirmed_conversion( array_merge( $stats, array( 'starts' => 0 ) ) ) );
		$this->assertNull( EvidenceMetrics::confirmed_conversion( array_merge( $stats, array( 'confirmed_successes' => 11 ) ) ) );
	}

	public function test_validation_share_has_its_own_paired_denominator_not_browser_submits() {
		$stats = array(
			'provider'                     => 'cf7',
			'submit_attempts'              => 0,
			'provider_validation_failures' => 5,
			'provider_validation_outcomes' => 10,
			'validation_failures'          => 500,
			'client_validation_failures'   => 500,
		);
		$this->assertSame( 50.0, EvidenceMetrics::validation_rate( $stats ) );
		$this->assertNull( EvidenceMetrics::validation_rate( array_merge( $stats, array( 'provider_validation_outcomes' => 0 ) ) ) );
		$this->assertNull( EvidenceMetrics::validation_rate( array_merge( $stats, array( 'provider_validation_outcomes' => 4 ) ) ) );
	}
}
