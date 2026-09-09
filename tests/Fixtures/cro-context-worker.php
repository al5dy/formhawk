<?php
/** Test-only parallel REST ingestion with an independent database connection. */

$formhawk_test_root = getenv( 'FORMHAWK_WP_ROOT' );
if ( ! $formhawk_test_root || count( $argv ) !== 4 ) {
	exit( 2 );
}
require_once rtrim( $formhawk_test_root, '/' ) . '/wp-load.php';
global $wpdb;
if ( ! preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'fh_case_[a-z0-9]{8}_$/D', $argv[1] ) ) {
	exit( 3 );
}
$wpdb->prefix     = $argv[1];
$formhawk_request = new WP_REST_Request( 'POST', '/formhawk/v1/cro/events' );
$formhawk_request->set_header( 'content-type', 'application/json' );
$formhawk_request->set_header( 'origin', home_url( '/' ) );
$formhawk_request->set_body(
	wp_json_encode(
		array(
			'context' => $argv[3],
			'type'    => 'view',
		)
	)
);
while ( microtime( true ) < (float) $argv[2] ) {
	usleep( 1000 );
}
$formhawk_response = ( new \Formhawk\CRO\Http\CROEventsController() )->ingest( $formhawk_request );
if ( is_wp_error( $formhawk_response ) ) {
	exit( 4 );
}
// Never print a token or JTI, even on failure.
echo (int) $formhawk_response->get_data()['accepted'];
