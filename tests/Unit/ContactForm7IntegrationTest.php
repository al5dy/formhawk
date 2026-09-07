<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Integrations\ContactForm7;
use Formhawk\Tests\Fixtures\ContactForm7Double;
use Formhawk\Tests\Fixtures\RecordingEventRecorder;
use PHPUnit\Framework\TestCase;

final class ContactForm7IntegrationTest extends TestCase {
	public function test_mail_and_submit_hooks_produce_one_success() {
		$events      = new RecordingEventRecorder();
		$integration = new ContactForm7( $events );
		$form        = new ContactForm7Double( 12, 'Contact' );

		$integration->mail_sent( $form );
		$integration->submitted( $form, array( 'status' => 'mail_sent' ) );

		$this->assertCount( 1, $events->events );
		$this->assertSame( 'success', $events->events[0]['type'] );
		$this->assertTrue( $events->events[0]['context']['mail_success'] );
	}

	public function test_validation_fields_are_recorded_without_messages() {
		$events      = new RecordingEventRecorder();
		$integration = new ContactForm7( $events );

		$integration->submitted(
			new ContactForm7Double( 13, 'Support' ),
			array(
				'status'         => 'validation_failed',
				'invalid_fields' => array(
					'your-email' => array( 'reason' => 'visitor@example.test is invalid' ),
					'message'    => array( 'reason' => 'Synthetic private contents' ),
				),
			)
		);

		$encoded = wp_json_encode( $events->events );
		$this->assertSame( array( 'your-email', 'message' ), wp_list_pluck( $events->events[0]['fields'], 'key' ) );
		$this->assertStringNotContainsString( 'visitor@example.test', $encoded );
		$this->assertStringNotContainsString( 'Synthetic private contents', $encoded );
	}
	public function test_repeated_validation_hook_records_one_provider_failure() {
		$recorder    = new RecordingEventRecorder();
		$integration = new ContactForm7( $recorder );
		$form        = new ContactForm7Double( 72, 'Validation' );
		$result      = array(
			'status'         => 'validation_failed',
			'invalid_fields' => array( 'email' => array() ),
		);
		$integration->submitted( $form, $result );
		$integration->submitted( $form, $result );
		$this->assertCount( 1, $recorder->events );
	}

	public function test_success_uses_form_tag_structure_without_submitted_values() {
		$tag    = new class() {
			public $name     = 'phone';
			public $basetype = 'tel';
			public function is_required() {
				return true; }
		};
		$events = new RecordingEventRecorder();
		( new ContactForm7( $events ) )->mail_sent( new ContactForm7Double( 81, 'Lead', array( $tag ) ) );
		$this->assertSame( array( 'phone' ), wp_list_pluck( $events->events[0]['context']['fields'], 'key' ) );
		$this->assertTrue( $events->events[0]['context']['fields'][0]['required'] );
	}
}
