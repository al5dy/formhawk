<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\FormOptimizationScore;
use PHPUnit\Framework\TestCase;

final class FormOptimizationScoreTest extends TestCase {
	public function test_generic_score_uses_observed_attempts_and_started_validation_denominator() {
		$score = ( new FormOptimizationScore() )->calculate(
			array(
				'views'                      => 100,
				'starts'                     => 20,
				'submit_attempts'            => 10,
				'submissions'                => 0,
				'client_validation_failures' => 5,
			),
			array(),
			'html'
		);

		$this->assertSame( 67.0, $score['conversion'] );
		$this->assertSame( 75.0, $score['validation'] );
	}

	public function test_confirmed_provider_does_not_use_browser_attempts_as_conversion() {
		$score = ( new FormOptimizationScore() )->calculate(
			array(
				'views'               => 100,
				'starts'              => 80,
				'submit_attempts'     => 60,
				'confirmed_successes' => 0,
			),
			array(),
			'cf7'
		);

		$this->assertSame( 0.0, $score['conversion'] );
	}
}
