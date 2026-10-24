<?php
/**
 * OmnaBase's form embed (`forms.js`) on the page.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Api\ApiUrl;

defined( 'ABSPATH' ) || exit;

/**
 * One script for every form on a page, from OmnaBase's app address (the visitor's browser reaches it; a development site's
 * `OMNABASE_APP_URL` points it at the local app), never the API address WordPress itself uses (on a development site that one may only be
 * reachable from the server). The address carries no version: it is OmnaBase's to cache, and a fix reaches every site the same day.
 */
final class EmbedScript {

	public const HANDLE = 'omnabase-forms';

	/** Queues the embed in the footer, once per page however many forms it holds. */
	public function enqueue(): void {
		wp_enqueue_script( self::HANDLE, self::address(), array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- OmnaBase's own script, cached by OmnaBase.
	}

	/** The embed's address. */
	public static function address(): string {
		return ApiUrl::app() . '/forms.js';
	}
}
