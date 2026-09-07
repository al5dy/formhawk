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

	public function test_validation_friction_requires_a_minimum_sample() {
		$small = HealthEvaluator::evaluate(
			array(
				'provider'            => 'html',
				'views'               => 3,
				'starts'              => 3,
				'submit_attempts'     => 2,
				'submissions'         => 2,
				'validation_failures' => 2,
			)
		);
		$large = HealthEvaluator::evaluate(
			array(
				'provider'            => 'html',
				'views'               => 20,
				'starts'              => 10,
				'submit_attempts'     => 6,
				'submissions'         => 6,
				'validation_failures' => 5,
			)
		);
		$this->assertSame( 'healthy', $small['status'] );
		$this->assertSame( 'warning', $large['status'] );
	}
}
