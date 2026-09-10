<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\HypothesisEngine;
use PHPUnit\Framework\TestCase;

final class HypothesisEngineTest extends TestCase {
	public function test_minimum_form_remove_hypothesis_matches_the_available_evidence() {
		$engine = new HypothesisEngine();

		$conversion = $engine->create(
			array(
				'type'               => 'remove_field',
				'field_label'        => 'Company',
				'business_optimized' => false,
			)
		);
		$business   = $engine->create(
			array(
				'type'               => 'remove_field',
				'field_label'        => 'Company',
				'business_optimized' => true,
			)
		);

		$this->assertStringContainsString( 'provider-confirmed conversion', $conversion );
		$this->assertStringNotContainsString( 'business value per visitor', $conversion );
		$this->assertStringContainsString( 'business value per visitor', $business );
	}
}
