<?php

namespace Formhawk\Tests\Fixtures;

use Formhawk\Contracts\EventRecorderInterface;

final class RecordingEventRecorder implements EventRecorderInterface {
	public $events = array();

	public function record_success( $provider, $provider_form_id, $title, $page_path, array $context = array() ) {
		return $this->record( 'success', compact( 'provider', 'provider_form_id', 'title', 'page_path', 'context' ) );
	}

	public function record_failure( $provider, $provider_form_id, $title, $page_path, $code ) {
		return $this->record( 'failure', compact( 'provider', 'provider_form_id', 'title', 'page_path', 'code' ) );
	}

	public function record_validation_failure( $provider, $provider_form_id, $title, $page_path, array $fields = array() ) {
		return $this->record( 'validation_failure', compact( 'provider', 'provider_form_id', 'title', 'page_path', 'fields' ) );
	}

	public function record_mail_success( $provider, $provider_form_id, $title, $page_path ) {
		return $this->record( 'mail_success', compact( 'provider', 'provider_form_id', 'title', 'page_path' ) );
	}

	public function record_mail_failure( $provider, $provider_form_id, $title, $page_path ) {
		return $this->record( 'mail_failure', compact( 'provider', 'provider_form_id', 'title', 'page_path' ) );
	}

	private function record( $type, array $event ) {
		$event['type']  = $type;
		$this->events[] = $event;
		return true;
	}
}
