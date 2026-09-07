<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Analytics\HealthEvaluator;
use PHPUnit\Framework\TestCase;

final class HealthEvaluatorTest extends TestCase {
	public function test_warns_when_provider_attempts_have_no_confirmed_success() {
		$health = HealthEvaluator::evaluate(
			array(
				'provider'            => 'wpforms',
				'views'               => 20,
				'starts'              => 10,
				'submit_attempts'     => 5,
				'submissions'         => 0,
				'confirmed_successes' => 0,
			)
		);
		$this->assertSame( 'warning', $health['status'] );
		$this->assertStringContainsString( 'provider', strtolower( $health['reason'] ) );
	}

	public function test_validation_uses_known_provider_outcomes_and_a_minimum_sample() {
		$base  = array(
			'provider'                   => 'wpforms',
			'starts'                     => 10,
			'submit_attempts'            => 0,
			'confirmed_successes'        => 5,
			'client_validation_failures' => 1000,
			'validation_failures'        => 1000,
		);
		$small = HealthEvaluator::evaluate(
			array_merge(
				$base,
				array(
					'provider_validation_failures' => 4,
					'provider_validation_outcomes' => 9,
				)
			)
		);
		$large = HealthEvaluator::evaluate(
			array_merge(
				$base,
				array(
					'provider_validation_failures' => 5,
					'provider_validation_outcomes' => 10,
				)
			)
		);
		$this->assertSame( 'healthy', $small['status'] );
		$this->assertSame( 'warning', $large['status'] );
	}

	public function test_generic_observations_do_not_claim_confirmed_health() {
		$health = HealthEvaluator::evaluate(
			array(
				'provider'        => 'html',
				'starts'          => 10,
				'submit_attempts' => 10,
				'submissions'     => 100,
			)
		);
		$this->assertSame( 'collecting', $health['status'] );
		$this->assertSame( 'Observed', $health['label'] );
	}
}
