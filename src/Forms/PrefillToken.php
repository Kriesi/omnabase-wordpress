<?php
/**
 * The signed-in visitor's prefill token: OmnaBase's note that the site vouches for their details.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Only for a signed-in visitor, never for anyone else: the site's server (with the key, which never reaches the page) asks OmnaBase
 * `POST /forms/prefill` for the form, the project and what the site vouches for (`Vouched`), and the container carries the token OmnaBase
 * answers. The token is kept per person, form, project and values for an hour (it lives two hours at OmnaBase), so the person's next views
 * cost nothing; a refusal or an outage is kept five minutes and the form shows unverified. A view that carries a token is kept out of page
 * caches (`DONOTCACHEPAGE`, LiteSpeed's no-cache action, as the message centre does); a visitor's page stays the same for everyone.
 */
final class PrefillToken {

	private const KEEP_SECONDS        = HOUR_IN_SECONDS;
	private const KEEP_FAILED_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Builds the token's source.
	 *
	 * @param Client  $client  The API client.
	 * @param Vouched $vouched What the site vouches for.
	 */
	public function __construct( private Client $client, private Vouched $vouched = new Vouched() ) {}

	/**
	 * The current visitor's token for a form ('' for a visitor who is not signed in, or when OmnaBase hands none).
	 *
	 * @param string $form    The form's slug.
	 * @param string $project The project's slug.
	 */
	public function forCurrentVisitor( string $form, string $project ): string {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return '';
		}
		self::keepOutOfCaches();
		$body = array( 'form' => $form, 'project' => $project, 'values' => $this->vouched->of( $user ) );
		$name = Options::FORM_TRANSIENT . 'p_' . md5( $user->ID . '|' . (string) wp_json_encode( $body ) );
		$kept = get_transient( $name );
		if ( is_string( $kept ) ) {
			return $kept;
		}
		$token = $this->ask( $body );
		set_transient( $name, $token, '' === $token ? self::KEEP_FAILED_SECONDS : self::KEEP_SECONDS );
		return $token;
	}

	/**
	 * OmnaBase's token for these details ('' when it hands none).
	 *
	 * @param array{form:string,project:string,values:array<string,string>} $body What is asked.
	 */
	private function ask( array $body ): string {
		$answer = $this->client->prefillToken( $body );
		$token  = is_array( $answer ) && is_string( $answer['token'] ?? null ) ? $answer['token'] : '';
		return strlen( $token ) <= 8192 ? $token : '';
	}

	/** Tells the page caches not to keep this view: it is one person's. */
	private static function keepOutOfCaches(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page caches' shared constant.
		}
		do_action( 'litespeed_control_set_nocache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
	}
}
