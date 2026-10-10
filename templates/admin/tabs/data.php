<?php
/**
 * The Customer data tab. Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{facts:array<string,mixed>} $view
 */

defined( 'ABSPATH' ) || exit;

$omnabase_facts = $view['facts'];
require dirname( __DIR__ ) . '/partials/facts.php';
