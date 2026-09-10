<?php

namespace Formhawk\Contracts;

interface CROContextStoreInterface {
	/** Register one signed assignment and its exposure in the same transaction. */
	public function issue( array $context );
	/** A valid signature is insufficient without a matching unexpired issuance. */
	public function is_issued( array $context );
	/** Atomically admit an event and increment its aggregate; returns a fixed result code. */
	public function consume( array $context, $type, $attempt = 0, $latency = null, $successful = false );
	/** Atomically admit at most one provider-confirmed binary conversion. */
	public function consume_provider_success( array $context );
}
