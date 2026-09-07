<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Integrations\WPForms;
use Formhawk\Tests\Fixtures\RecordingEventRecorder;
use PHPUnit\Framework\TestCase;

final class WPFormsIntegrationTest extends TestCase {
	public function test_empty_or_other_form_errors_do_not_invent_a_validation_rejection() {
		$events      = new RecordingEventRecorder();
		$integration = new WPForms( $events );
		foreach ( array( array(), array( 55 => array() ), array( 99 => array( 4 => 'Private error' ) ) ) as $errors ) {
			$this->assertSame( $errors, $integration->initial_errors( $errors, $this->form_data() ) );
		}
		$this->assertCount( 0, $events->events );
		$integration->process_complete( array(), array(), $this->form_data(), 0 );
		$this->assertCount( 1, $events->events );
		$this->assertSame( 'success', $events->events[0]['type'] );
	}

	private function form_data() {
		return array(
			'id'       => 55,
			'settings' => array( 'form_title' => 'Request a Quote' ),
			'fields'   => array(
				3 => array(
					'id'    => 3,
					'label' => 'Full name',
					'type'  => 'name',
				),
				4 => array(
					'id'    => 4,
					'label' => 'Work email',
					'type'  => 'email',
				),
			),
		);
	}

	public function test_process_complete_confirms_lite_entry_id_zero_once() {
		$events      = new RecordingEventRecorder();
		$integration = new WPForms( $events );
		$private     = array( 4 => array( 'value' => 'visitor@example.test' ) );

		$integration->process_complete( $private, array( 'fields' => $private ), $this->form_data(), 0 );
		$integration->process_complete( $private, array( 'fields' => $private ), $this->form_data(), 0 );

		$this->assertCount( 1, $events->events );
		$this->assertSame( 'success', $events->events[0]['type'] );
		$this->assertSame( '55', $events->events[0]['provider_form_id'] );
		$this->assertStringNotContainsString( 'visitor@example.test', wp_json_encode( $events->events ) );
	}

	public function test_validation_records_compound_field_keys_once_without_messages() {
		$events      = new RecordingEventRecorder();
		$integration = new WPForms( $events );
		$errors      = array(
			55 => array(
				3 => array(
					'first' => 'Private message',
					'last'  => 'Private message',
				),
				4 => 'Private message',
			),
		);

		$returned = $integration->initial_errors( $errors, $this->form_data() );
		$integration->initial_errors( $errors, $this->form_data() );

		$this->assertSame( $errors, $returned );
		$this->assertCount( 1, $events->events );
		$this->assertSame( array( '3.first', '3.last', '4' ), wp_list_pluck( $events->events[0]['fields'], 'key' ) );
		$this->assertStringNotContainsString( 'Private message', wp_json_encode( $events->events ) );
	}

	public function test_form_level_rejection_is_counted_without_storing_its_message() {
		$events      = new RecordingEventRecorder();
		$integration = new WPForms( $events );
		$errors      = array(
			55 => array(
				'header' => 'Security rejection for visitor@example.test',
			),
		);

		$integration->initial_errors( $errors, $this->form_data() );

		$this->assertCount( 1, $events->events );
		$this->assertSame( 'validation_failure', $events->events[0]['type'] );
		$this->assertSame( array(), $events->events[0]['fields'] );
		$this->assertStringNotContainsString( 'visitor@example.test', wp_json_encode( $events->events ) );
	}

	public function test_invalid_or_non_scalar_form_ids_fail_closed() {
		$events      = new RecordingEventRecorder();
		$integration = new WPForms( $events );

		$integration->process_complete( array(), array(), array( 'id' => 0 ), 0 );
		$integration->process_complete( array(), array(), array( 'id' => array( 55 ) ), 0 );

		$this->assertCount( 0, $events->events );
	}
}
