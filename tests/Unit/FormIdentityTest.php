<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Domain\FormIdentity;
use PHPUnit\Framework\TestCase;

final class FormIdentityTest extends TestCase {
	public function test_preserves_contact_form_7_key_across_paths() {
		$expected = 'cf7:' . sha1( '123' );
		$this->assertSame( $expected, FormIdentity::key( 'cf7', '123', '/one' ) );
		$this->assertSame( $expected, FormIdentity::key( 'cf7', '123', '/two' ) );
	}

	public function test_preserves_generic_placement_scoped_key() {
		$this->assertSame( 'html:' . sha1( 'checkout|/one' ), FormIdentity::key( 'html', 'checkout', '/one' ) );
		$this->assertNotSame( FormIdentity::key( 'html', 'checkout', '/one' ), FormIdentity::key( 'html', 'checkout', '/two' ) );
	}

	public function test_first_class_provider_identity_is_stable_across_paths() {
		$this->assertSame( FormIdentity::key( 'wpforms', '123', '/one' ), FormIdentity::key( 'wpforms', '123', '/two' ) );
		$this->assertSame( FormIdentity::key( 'elementor', '81:abc123', '/one' ), FormIdentity::key( 'elementor', '81:abc123', '/two' ) );
	}

	public function test_provider_namespaces_do_not_collide() {
		$keys = array(
			FormIdentity::key( 'cf7', '123', '/' ),
			FormIdentity::key( 'wpforms', '123', '/' ),
			FormIdentity::key( 'elementor', '123', '/' ),
			FormIdentity::key( 'html', '123', '/' ),
		);
		$this->assertCount( 4, array_unique( $keys ) );
	}
}
