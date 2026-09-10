<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\CRO\VariantGenerator;
use PHPUnit\Framework\TestCase;

final class VariantGeneratorSafetyTest extends TestCase {
	private $filter = null;

	protected function tearDown(): void {
		if ( $this->filter ) {
			remove_filter( 'formhawk_cro_variant', $this->filter );
		}
		parent::tearDown();
	}

	public function test_semantic_config_is_revalidated_after_extension_filters() {
		$generator   = new VariantGenerator();
		$form        = array(
			'provider' => 'wpforms',
			'title'    => 'Lead form',
		);
		$opportunity = array(
			'type'                => ExperimentType::REMOVE_FIELD,
			'field_key'           => 'company',
			'safety'              => 'caution',
			'dependency_verified' => true,
		);
		$this->assertNotNull( $generator->generate( $form, $opportunity, array() ) );

		$this->filter = static function ( $candidate ) {
			unset( $candidate['config']['dependency_verified'] );
			return $candidate;
		};
		add_filter( 'formhawk_cro_variant', $this->filter );
		$this->assertNull( $generator->generate( $form, $opportunity, array() ) );
	}

	public function test_extension_filter_cannot_change_the_selected_mutation_type() {
		$generator    = new VariantGenerator();
		$form         = array(
			'provider' => 'wpforms',
			'title'    => 'Lead form',
		);
		$opportunity  = array(
			'type'                => ExperimentType::REMOVE_FIELD,
			'field_key'           => 'company',
			'safety'              => 'safe',
			'dependency_verified' => true,
		);
		$this->filter = static function ( $candidate ) {
			$candidate['type'] = ExperimentType::SUBMIT_BUTTON;
			return $candidate;
		};
		add_filter( 'formhawk_cro_variant', $this->filter );
		$this->assertNull( $generator->generate( $form, $opportunity, array() ) );
	}
}
