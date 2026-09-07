<?php

namespace Formhawk\Integrations;

use Formhawk\Domain\ProviderCatalog;

final class ClientFormIdentityValidator {
	/**
	 * Cheap structural checks only; never load entries or submitted field values.
	 * Post types verified in CF7 6.1.7 and WPForms Lite 2.0.1.1 installed source.
	 */
	public function validate( array $event ) {
		$post_types = array(
			ProviderCatalog::CF7     => 'wpcf7_contact_form',
			ProviderCatalog::WPFORMS => 'wpforms',
		);
		$provider   = $event['provider'];
		if ( ! isset( $post_types[ $provider ] ) ) {
			// Elementor widget identities require document traversal; their growth is budgeted instead.
			return true;
		}
		$id = $event['provider_form_id'];
		if ( ! preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) ) {
			return false;
		}
		$post = get_post( (int) $id );
		return $post && $post_types[ $provider ] === $post->post_type && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true );
	}
}
