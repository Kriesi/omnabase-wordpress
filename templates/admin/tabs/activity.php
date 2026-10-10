<?php
/**
 * The Activity tab: what is on its way to OmnaBase, and what OmnaBase sent here. Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{outbox:array<string,mixed>,deliveries:array{rows:array<int,array<string,mixed>>}} $view
 */

defined( 'ABSPATH' ) || exit;

$omnabase_outbox     = $view['outbox'];
$omnabase_deliveries = $view['deliveries']['rows'];
require dirname( __DIR__ ) . '/partials/outbox.php';
require dirname( __DIR__ ) . '/partials/deliveries.php';
