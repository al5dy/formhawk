<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\Attribution\ContextSigner;
use Formhawk\CRO\Attribution\RequestContext;
use Formhawk\Tests\Fixtures\CROToken;
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
			array_diff_key( $signer->verify( $token ), array_flip( array( 'jti', 'iat', 'exp' ) ) )
		);
		$verified = $signer->verify( $token );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{22}$/D', $verified['jti'] );
		$this->assertSame( 7200, $verified['exp'] - $verified['iat'] );
		$this->assertSame( 2, CROToken::claims( $token )['v'] );
		$this->assertNotSame( $verified['jti'], $signer->verify( $signer->sign( $verified ) )['jti'] );
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
		$this->assertNull( $context->get( 'wpforms', '4' ), 'A valid signature without server issuance is not attribution authority.' );
		$this->assertNull( $context->get( 'cf7', '4' ) );
	}

	public function test_authenticated_but_expired_malformed_or_legacy_claims_are_rejected() {
		$signer = new ContextSigner();
		$claims = CROToken::claims(
			$signer->sign(
				array(
					'experiment_id'    => 1,
					'variant_id'       => 2,
					'form_id'          => 3,
					'provider'         => 'cf7',
					'provider_form_id' => '42',
					'segment'          => 'desktop',
				)
			)
		);
		foreach ( array(
			array( 'jti' => null ),
			array( 'jti' => '' ),
			array( 'jti' => 'not-random' ),
			array( 'jti' => str_repeat( 'A', 21 ) . 'B' ),
			array( 'v' => 1 ),
			array( 'iat' => time() + 60 ),
			array( 'exp' => $claims['iat'] + DAY_IN_SECONDS + 1 ),
			array( 'iat' => (string) $claims['iat'] ),
		) as $invalid ) {
			$this->assertNull( $signer->verify( CROToken::sign( array_merge( $claims, $invalid ) ) ) );
		}
		$missing = $claims;
		unset( $missing['jti'] );
		$this->assertNull( $signer->verify( CROToken::sign( $missing ) ) );
		$expired = array_merge(
			$claims,
			array(
				'iat' => time() - 7201,
				'exp' => time() - 1,
			)
		);
		$this->assertNull( $signer->verify( CROToken::sign( $expired ) ) );
		$this->assertSame( 'expired_context', $signer->last_failure() );
	}

	public function test_body_signature_and_site_salt_are_bound() {
		$signer = new ContextSigner();
		$token  = $signer->sign(
			array(
				'experiment_id'    => 1,
				'variant_id'       => 2,
				'form_id'          => 3,
				'provider'         => 'cf7',
				'provider_form_id' => '42',
				'segment'          => 'desktop',
			)
		);
		$this->assertNull( $signer->verify( ( 'A' === $token[0] ? 'B' : 'A' ) . substr( $token, 1 ) ) );
		list( $body, $signature ) = explode( '.', $token );
		$this->assertNull( $signer->verify( $body . '.' . ( 'A' === $signature[0] ? 'B' : 'A' ) . substr( $signature, 1 ) ) );
		$other_salt = static function () {
			return 'different-test-site-salt';
		};
		add_filter( 'salt', $other_salt );
		try {
			$this->assertNull( $signer->verify( $token ) );
		} finally {
			remove_filter( 'salt', $other_salt );
		}
		$this->assertNotNull( $signer->verify( $token ) );
	}
}
