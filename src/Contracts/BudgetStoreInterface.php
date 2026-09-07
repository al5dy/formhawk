<?php

namespace Formhawk\Contracts;

interface BudgetStoreInterface {
	/** Reserve a positive cost atomically. False includes storage failure. */
	public function reserve( $scope, $cost, $limit, $window_seconds );
	/** Read-only preflight; callers must serialize multi-budget admissions. */
	public function can_reserve( $scope, $cost, $limit, $window_seconds );

	/** Empty on success; otherwise limit or storage. */
	public function last_failure();
}
