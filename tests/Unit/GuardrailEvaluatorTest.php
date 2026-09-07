<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\GuardrailEvaluator;
use Formhawk\CRO\OptimizationPolicy;
use PHPUnit\Framework\TestCase;

final class GuardrailEvaluatorTest extends TestCase {
	public function test_clearly_harmful_variant_is_stopped() {
		$policy = ( new OptimizationPolicy() )->for_form( array( 'aggressiveness' => 'balanced' ) );
		$result = ( new GuardrailEvaluator() )->evaluate(
			array(
				'views'               => 2000,
				'conversions'         => 200,
				'confirmed_successes' => 200,
			),
			array(
				'views'               => 2000,
				'conversions'         => 80,
				'confirmed_successes' => 80,
			),
			$policy
		);
		$this->assertTrue( $result['triggered'] );
		$this->assertSame( 'confirmed_conversion_harm', $result['reason'] );
	}

	public function test_provider_failure_and_js_error_guardrails_are_separate() {
		$policy  = ( new OptimizationPolicy() )->for_form( array() );
		$control = array(
			'views'       => 500,
			'conversions' => 50,
		);
		$result  = ( new GuardrailEvaluator() )->evaluate(
			$control,
			array(
				'views'             => 500,
				'conversions'       => 50,
				'provider_failures' => 30,
			),
			$policy
		);
		$this->assertSame( 'provider_failure_rate', $result['reason'] );
		$result = ( new GuardrailEvaluator() )->evaluate(
			$control,
			array(
				'views'       => 500,
				'conversions' => 50,
				'js_errors'   => 20,
			),
			$policy
		);
		$this->assertSame( 'js_error_rate', $result['reason'] );
	}

	public function test_provider_validation_uses_mathematically_compatible_outcomes() {
		$policy = ( new OptimizationPolicy() )->for_form( array() );
		$result = ( new GuardrailEvaluator() )->evaluate(
			array(
				'views'                        => 500,
				'conversions'                  => 90,
				'confirmed_successes'          => 90,
				'provider_validation_failures' => 10,
			),
			array(
				'views'                        => 500,
				'conversions'                  => 60,
				'confirmed_successes'          => 60,
				'provider_validation_failures' => 90,
			),
			$policy
		);
		$this->assertTrue( $result['triggered'] );
		$this->assertSame( 'provider_validation_explosion', $result['reason'] );
	}

	public function test_client_validation_uses_started_lifecycles_without_submit_attempts() {
		$policy = ( new OptimizationPolicy() )->for_form( array() );
		$result = ( new GuardrailEvaluator() )->evaluate(
			array(
				'views'                      => 200,
				'starts'                     => 100,
				'conversions'                => 20,
				'client_validation_failures' => 5,
			),
			array(
				'views'                      => 200,
				'starts'                     => 100,
				'conversions'                => 20,
				'client_validation_failures' => 40,
			),
			$policy
		);
		$this->assertTrue( $result['triggered'] );
		$this->assertSame( 'client_validation_explosion', $result['reason'] );
	}
}
