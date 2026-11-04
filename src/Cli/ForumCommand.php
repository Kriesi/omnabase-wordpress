<?php
/**
 * WP-CLI (WordPress's command line on the server): `wp omnabase forum resend <reply id>` (the support user replies round, 2026-11-04).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Cli;

use OmnaBase\WordPress\Forum\Resend;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a forum reply that never reached its ticket to OmnaBase again.
 *
 * Registered only when WP-CLI runs (a page view never loads it). A thin door: the resend service decides, the command prints its one
 * line; a refusal ends with a non-zero exit.
 */
final class ForumCommand {

	/**
	 * Builds the command.
	 *
	 * @param Resend $service The resend service.
	 */
	public function __construct( private Resend $service ) {}

	/**
	 * Registers `wp omnabase forum` while WP-CLI runs.
	 *
	 * @param Resend $service The resend service.
	 */
	public static function register( Resend $service ): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'omnabase forum', new self( $service ) );
		}
	}

	/**
	 * Sends one forum reply to its topic's ticket again.
	 *
	 * A reply that already reached OmnaBase is not sent twice. The reply goes with the next outbox run.
	 * Run `wp cron event run omnabase_outbox` to send it at once. On a network, add `--url=<the site's address>`.
	 *
	 * ## OPTIONS
	 *
	 * <reply-id>
	 * : The reply's id (the number after #post- in the reply's link).
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnabase forum resend 1501325
	 *
	 * @param array<int,string>    $args  The reply id.
	 * @param array<string,string> $assoc No options.
	 */
	public function resend( $args, $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI's signature.
		$out = $this->service->resend( absint( $args[0] ?? 0 ) );
		if ( 'refused' === $out['status'] ) {
			\WP_CLI::error( $out['words'] );
		}
		\WP_CLI::success( $out['words'] );
	}
}
