<?php
/**
 * The Forums tab. Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{forum:array<string,mixed>} $view
 */

defined( 'ABSPATH' ) || exit;

$omnabase_forum = $view['forum'];
require dirname( __DIR__ ) . '/partials/forum.php';
