<?php

namespace Formhawk\CRO\Mutations;

interface MutationInterface {
	public function type();

	public function normalize( array $config );

	public function supports( $provider );
}
