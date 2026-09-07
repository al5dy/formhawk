<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Integrations\ElementorForms;
use Formhawk\Tests\Fixtures\ElementorHandlerDouble;
use Formhawk\Tests\Fixtures\ElementorRecordDouble;
use Formhawk\Tests\Fixtures\RecordingEventRecorder;
use PHPUnit\Framework\TestCase;

final class ElementorFormsIntegrationTest extends TestCase {
	protected function tearDown(): void {
		unset( $_POST['post_id'] );
		parent::tearDown();
	}

	public function test_new_record_uses_document_and_widget_identity_once() {
		$_POST['post_id'] = '999';
		$events           = new RecordingEventRecorder();
		$integration      = new ElementorForms( $events );
		$record           = new ElementorRecordDouble( 'widget7', 'Contact Us', 81 );
		$handler          = new ElementorHandlerDouble();

		$integration->new_record( $record, $handler );
		$integration->new_record( $record, $handler );

		$this->assertCount( 1, $events->events );
		$this->assertSame( 'success', $events->events[0]['type'] );
		$this->assertSame( '81:widget7', $events->events[0]['provider_form_id'] );
	}

	public function test_validation_observes_error_keys_without_messages_or_values() {
		$events      = new RecordingEventRecorder();
		$integration = new ElementorForms( $events );
		$record      = new ElementorRecordDouble(
			'widget8',
			'Private form name',
			82,
			array(
				array(
					'custom_id'   => 'email',
					'field_label' => 'Work email',
					'field_type'  => 'email',
				),
				array(
					'custom_id'   => 'phone',
					'field_label' => 'Telephone',
					'field_type'  => 'tel',
				),
				array(
					'custom_id'   => 'context',
					'field_label' => 'Internal context',
					'field_type'  => 'hidden',
				),
			)
		);
		$handler     = new ElementorHandlerDouble(
			array(
				'email'   => 'visitor@example.test is invalid',
				'phone'   => '+1 555 010 2000 is invalid',
				'context' => 'Synthetic hidden value is invalid',
			)
		);

		$integration->validation( $record, $handler );
		$integration->validation( $record, $handler );

		$this->assertCount( 1, $events->events );
		$this->assertSame( array( 'email', 'phone' ), wp_list_pluck( $events->events[0]['fields'], 'key' ) );
		$this->assertSame( array( 'Work email', 'Telephone' ), wp_list_pluck( $events->events[0]['fields'], 'label' ) );
		$this->assertSame( array( 'email', 'tel' ), wp_list_pluck( $events->events[0]['fields'], 'type' ) );
		$this->assertStringNotContainsString( 'visitor@example.test', wp_json_encode( $events->events ) );
		$this->assertStringNotContainsString( '+1 555', wp_json_encode( $events->events ) );
	}

	public function test_mail_sent_is_separate_from_form_success() {
		$events      = new RecordingEventRecorder();
		$integration = new ElementorForms( $events );
		$record      = new ElementorRecordDouble( 'widget9', 'Email action', 83 );

		$integration->mail_sent( array(), $record );
		$this->assertCount( 0, $events->events );
		$integration->new_record( $record, new ElementorHandlerDouble() );
		$integration->mail_sent( array(), $record );
		$integration->new_record( $record, new ElementorHandlerDouble() );

		$this->assertSame( array( 'success', 'mail_success' ), wp_list_pluck( $events->events, 'type' ) );
	}

	public function test_handler_action_error_is_not_success() {
		$events      = new RecordingEventRecorder();
		$integration = new ElementorForms( $events );
		$handler     = new ElementorHandlerDouble(
			array(),
			false,
			array( 'error' => 'Private CRM response for visitor@example.test' )
		);

		$integration->new_record( new ElementorRecordDouble( 'widget10', 'CRM action', 84 ), $handler );

		$this->assertSame( 'failure', $events->events[0]['type'] );
		$this->assertSame( 'action_error', $events->events[0]['code'] );
		$this->assertStringNotContainsString( 'visitor@example.test', wp_json_encode( $events->events ) );
	}

	public function test_mail_hook_does_not_create_false_success_when_email_action_fails() {
		$events      = new RecordingEventRecorder();
		$integration = new ElementorForms( $events );
		$record      = new ElementorRecordDouble( 'widget11', 'Email action', 85 );
		$handler     = new ElementorHandlerDouble(
			array(),
			false,
			array( 'admin_error' => 'wp_mail failed for visitor@example.test' )
		);

		$integration->mail_sent( array( 'email_to' => 'recipient@example.test' ), $record );
		$integration->new_record( $record, $handler );

		$this->assertSame( array( 'failure' ), wp_list_pluck( $events->events, 'type' ) );
		$this->assertStringNotContainsString( 'recipient@example.test', wp_json_encode( $events->events ) );
	}

	public function test_mail_signal_is_compatible_if_a_future_provider_version_fires_it_after_new_record() {
		$events      = new RecordingEventRecorder();
		$integration = new ElementorForms( $events );
		$record      = new ElementorRecordDouble( 'widget12', 'Future lifecycle', 86 );

		$integration->new_record( $record, new ElementorHandlerDouble() );
		$integration->mail_sent( array(), $record );
		$integration->mail_sent( array(), $record );

		$this->assertSame( array( 'success', 'mail_success' ), wp_list_pluck( $events->events, 'type' ) );
	}
}
