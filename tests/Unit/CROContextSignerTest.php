<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\Attribution\ContextSigner;
use Formhawk\CRO\Attribution\RequestContext;
use PHPUnit\Framework\TestCase;

final class CROContextSignerTest extends TestCase {
	public function test_signed_context_contains_only_structural_attribution() {
		$signer = new ContextSigner();
		$token  = $signer->sign(
			array(
				'experiment_id'    => 7,
				'variant_id'       => 9,
				'form_id'          => 3,
				'provider'         => 'cf7',
				'provider_form_id' => '42',
				'segment'          => 'mobile',
			)
		);
		$this->assertSame(
			array(
				'experiment_id'    => 7,
				'variant_id'       => 9,
				'form_id'          => 3,
				'provider'         => 'cf7',
				'provider_form_id' => '42',
				'segment'          => 'mobile',
			),
			$signer->verify( $token )
		);
		$this->assertNull( $signer->verify( $token . 'tampered' ) );
		$this->assertStringNotContainsString( 'email', $token );
	}

	public function test_request_marker_is_removed_before_provider_processing() {
		$signer                    = new ContextSigner();
		$token                     = $signer->sign(
			array(
				'experiment_id'    => 1,
				'variant_id'       => 2,
				'form_id'          => 3,
				'provider'         => 'wpforms',
				'provider_form_id' => '4',
				'segment'          => 'desktop',
			)
		);
		$_POST['_formhawk_cro']    = $token;
		$_REQUEST['_formhawk_cro'] = $token;
		$context                   = new RequestContext( $signer );
		$context->capture();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Verifies public signed-context removal rather than an admin action.
		$this->assertArrayNotHasKey( '_formhawk_cro', $_POST );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Verifies public signed-context removal rather than an admin action.
		$this->assertArrayNotHasKey( '_formhawk_cro', $_REQUEST );
		$this->assertSame( 2, $context->get( 'wpforms', '4' )['variant_id'] );
		$this->assertNull( $context->get( 'cf7', '4' ) );
	}
}
