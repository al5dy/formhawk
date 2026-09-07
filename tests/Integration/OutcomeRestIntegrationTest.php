<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\Infrastructure\Database;
use Formhawk\Outcomes\ApiKeyRepository;
use Formhawk\Outcomes\Http\OutcomesController;
use Formhawk\Outcomes\OutcomeRepository;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class OutcomeRestIntegrationTest extends IsolatedStorageTestCase {
	private $user_id;

	protected function setUp(): void {
		parent::setUp();
		$this->user_id = wp_create_user( 'fh-roi-admin-' . wp_generate_password( 8, false ), wp_generate_password( 20 ), 'fh-roi@example.test' );
		$user          = new \WP_User( $this->user_id );
		$user->set_role( 'administrator' );
		wp_set_current_user( $this->user_id );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
		wp_delete_user( $this->user_id );
	}

	public function test_strict_rest_schema_and_idempotency() {
		$identity   = ( new FormRepository() )->resolve(
			array(
				'provider'         => 'cf7',
				'provider_form_id' => '88',
				'title'            => 'Lead',
				'page_path'        => '/lead',
			)
		);
		$submission = ( new OutcomeRepository() )->create_submission(
			array(
				'public_id'         => 'fh_EEEEEEEEEEEEEEEEEEEEEE',
				'form_id'           => $identity['form_id'],
				'placement_id'      => $identity['placement_id'],
				'provider'          => 'cf7',
				'provider_form_id'  => '88',
				'provider_entry_id' => '',
				'experiment_id'     => 0,
				'variant_id'        => 0,
				'device_class'      => 'unknown',
			),
			array()
		);
		$controller = new OutcomesController();
		$this->assertTrue( $controller->permission( $this->request( array() ) ) );
		$payload  = array(
			'submission_id'   => $submission['public_id'],
			'status'          => 'qualified',
			'idempotency_key' => 'rest-event-1',
		);
		$response = $controller->record( $this->request( $payload ) );
		$this->assertInstanceOf( '\WP_REST_Response', $response );
		$this->assertFalse( $response->get_data()['duplicate'] );
		$repeat = $controller->record( $this->request( $payload ) );
		$this->assertTrue( $repeat->get_data()['duplicate'] );
		$invalid = $controller->record( $this->request( array_merge( $payload, array( 'metadata' => array( 'email' => 'private@example.test' ) ) ) ) );
		$this->assertInstanceOf( '\WP_Error', $invalid );
		$this->assertSame( 'formhawk_outcome_schema', $invalid->get_error_code() );
	}

	public function test_dedicated_key_stores_only_a_hash_and_can_be_revoked() {
		global $wpdb;
		$keys   = new ApiKeyRepository();
		$secret = $keys->create( 'CRM production', $this->user_id );
		$this->assertIsString( $secret );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1', Database::outcome_api_keys_table() ), ARRAY_A );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $row ) );
		$this->assertStringNotContainsString( substr( $secret, strpos( $secret, '.' ) + 1 ), $row['secret_hash'] );
		$this->assertIsArray( $keys->authenticate( 'Bearer ' . $secret ) );
		$this->assertTrue( $keys->revoke( $row['id'] ) );
		$this->assertNull( $keys->authenticate( 'Bearer ' . $secret ) );
	}

	private function request( array $payload ) {
		$request = new \WP_REST_Request( 'POST', '/formhawk/v1/outcomes' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );
		return $request;
	}
}
