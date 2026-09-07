<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Analytics\EventNormalizer;
use PHPUnit\Framework\TestCase;

final class EventNormalizerTest extends TestCase {
	public function test_normalizes_supported_provider_and_field_metadata() {
		$normalizer = new EventNormalizer();
		$event      = $normalizer->client(
			array(
				'type'             => 'field_interaction',
				'provider'         => 'wpforms',
				'provider_form_id' => '42',
				'title'            => 'Request a Quote',
				'page_path'        => 'https://example.test/quote?campaign=private',
				'field'            => array(
					'key'   => '3.first',
					'label' => 'Full name',
					'type'  => 'name',
				),
			)
		);

		$this->assertSame( 'wpforms', $event['provider'] );
		$this->assertSame( '/quote', $event['page_path'] );
		$this->assertSame(
			array(
				'key'   => '3.first',
				'label' => 'Full name',
				'type'  => 'name',
			),
			$event['field']
		);
	}

	public function test_rejects_server_only_client_events() {
		$normalizer = new EventNormalizer();
		$this->assertNull(
			$normalizer->client(
				array(
					'type'     => 'form_success',
					'provider' => 'wpforms',
				)
			)
		);
		$this->assertNull(
			$normalizer->client(
				array(
					'type'     => 'form_failure',
					'provider' => 'elementor',
				)
			)
		);
	}

	public function test_discards_submitted_values_and_unknown_payload_keys() {
		$normalizer = new EventNormalizer();
		$event      = $normalizer->client(
			array(
				'type'             => 'field_interaction',
				'provider'         => 'wpforms',
				'provider_form_id' => '7',
				'title'            => 'Lead form',
				'page_path'        => '/lead',
				'field'            => array(
					'key'   => '2',
					'label' => 'Email',
					'type'  => 'email',
					'value' => 'visitor@example.test',
				),
				'fields_raw'       => array(
					'name'  => 'John Smith',
					'phone' => '+1 555 010 2000',
				),
				'message'          => 'Synthetic private message contents',
			)
		);
		$encoded    = wp_json_encode( $event );

		$this->assertStringNotContainsString( 'visitor@example.test', $encoded );
		$this->assertStringNotContainsString( 'John Smith', $encoded );
		$this->assertStringNotContainsString( '+1 555', $encoded );
		$this->assertStringNotContainsString( 'Synthetic private message', $encoded );
		$this->assertArrayNotHasKey( 'value', $event['field'] );
	}

	public function test_redacts_personal_data_accidentally_supplied_as_metadata() {
		$normalizer = new EventNormalizer();
		$event      = $normalizer->client(
			array(
				'type'             => 'form_view',
				'provider'         => 'html',
				'provider_form_id' => 'lead',
				'title'            => 'Lead for visitor@example.test +1 555 010 2000',
				'page_path'        => '/lead',
			)
		);

		$this->assertSame( 'Lead for [redacted] [redacted]', $event['title'] );
	}

	public function test_rejects_unknown_provider_missing_identity_and_non_scalar_metadata() {
		$normalizer = new EventNormalizer();

		$this->assertNull(
			$normalizer->client(
				array(
					'type'             => 'form_view',
					'provider'         => 'invented-provider',
					'provider_form_id' => '7',
				)
			)
		);
		$this->assertNull(
			$normalizer->client(
				array(
					'type'             => 'form_view',
					'provider'         => 'wpforms',
					'provider_form_id' => array( '7' ),
				)
			)
		);

		$event = $normalizer->client(
			array(
				'type'             => 'form_view',
				'provider'         => 'html',
				'provider_form_id' => 'safe',
				'title'            => array( 'visitor@example.test' ),
				'page_path'        => array( '/private' ),
				'duration_ms'      => array( 10 ),
			)
		);

		$this->assertSame( '', $event['title'] );
		$this->assertSame( '/', $event['page_path'] );
		$this->assertSame( 0, $event['duration_ms'] );
	}
}
