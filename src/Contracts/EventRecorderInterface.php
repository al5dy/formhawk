<?php

namespace Formhawk\Contracts;

interface EventRecorderInterface {
	public function record_success( $provider, $provider_form_id, $title, $page_path, array $context = array() );

	public function record_failure( $provider, $provider_form_id, $title, $page_path, $code );

	public function record_validation_failure( $provider, $provider_form_id, $title, $page_path, array $fields = array() );

	public function record_mail_success( $provider, $provider_form_id, $title, $page_path );

	public function record_mail_failure( $provider, $provider_form_id, $title, $page_path );
}
