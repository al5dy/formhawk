<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\OpportunityDetector;
use PHPUnit\Framework\TestCase;

final class OpportunityDetectorTest extends TestCase {
	public function test_only_structurally_safe_field_metadata_can_become_an_automatic_target() {
		$detector = new OpportunityDetector();
		$this->assertTrue(
			$detector->field_is_safe_candidate(
				array(
					'field_key'   => 'company',
					'field_label' => 'Company',
					'field_type'  => 'text',
				)
			)
		);
		$this->assertFalse(
			$detector->field_is_safe_candidate(
				array(
					'field_key'   => 'privacy',
					'field_label' => 'I agree',
					'field_type'  => 'checkbox',
				)
			)
		);
		$this->assertFalse(
			$detector->field_is_safe_candidate(
				array(
					'field_key'   => 'attachment',
					'field_label' => 'Document',
					'field_type'  => 'file',
				)
			)
		);
		$this->assertFalse(
			$detector->field_is_safe_candidate(
				array(
					'field_key'   => 'secure_code',
					'field_label' => 'OTP',
					'field_type'  => 'text',
				)
			)
		);
	}
}
