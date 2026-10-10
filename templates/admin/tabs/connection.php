<?php
/**
 * The Connection tab: the setup line, the connection box, the status block and the "Advanced" block. Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{setup:array,connection:array<string,mixed>,health:array<string,mixed>,healthNotes:array{factsAt:int,cronOff:bool}} $view
 */

defined( 'ABSPATH' ) || exit;

$omnabase_setup      = $view['setup'];
$omnabase_connection = $view['connection'];
$omnabase_health     = $view['health'] + $view['healthNotes'];

if ( array() !== $omnabase_setup['steps'] ) {
	require dirname( __DIR__ ) . '/partials/setup.php';
}
require dirname( __DIR__ ) . '/partials/connection.php';
if ( in_array( $omnabase_connection['state'], array( 'connected', 'reconnect' ), true ) ) {
	require dirname( __DIR__ ) . '/partials/health.php';
}
require dirname( __DIR__ ) . '/partials/advanced.php';
