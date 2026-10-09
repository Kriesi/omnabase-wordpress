<?php
/**
 * Every sentence the message centre shows a customer after something happened, or instead of their tickets.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

defined( 'ABSPATH' ) || exit;

/**
 * The words live here, so they stay the brief's and never name the site owner's plan, the workspace, a status code or OmnaBase's own text.
 * A form post redirects back with one of the codes (`omnabase_done=<code>`); an unknown code shows nothing. A refusal never claims success.
 */
final class Notices {

	public const CREATED     = 'created';
	public const REPLIED     = 'replied';
	public const LINK_SENT   = 'link';
	public const LINKS_USED  = 'links_used';
	public const LINK_LATER  = 'link_later';
	public const LATER       = 'later';
	public const UNAVAILABLE = 'unavailable';
	public const FAILED      = 'failed';
	public const FIELDS      = 'fields';
	public const NO_ANSWER   = 'no_answer';
	public const NOT_FOUND   = 'not_found';
	public const EXPIRED     = 'expired';
	public const DOWN        = 'down';

	/** How long OmnaBase's confirmation link works (its documented 24 hours). */
	private const LINK_HOURS = 24;

	/**
	 * The sentence for a code, and whether it is good news; null for an unknown code.
	 *
	 * @param string $code   One of the constants.
	 * @param string $masked The visitor's address, partly hidden (for the link's sentence).
	 * @return array{ok:bool,message:string}|null
	 */
	public static function of( string $code, string $masked = '' ): ?array {
		$message = match ( $code ) {
			self::CREATED     => __( 'Thanks, your question was sent. We answer by email and here.', 'omnabase' ),
			self::REPLIED     => __( 'Your answer was sent.', 'omnabase' ),
			/* translators: 1: the visitor's address, partly hidden, 2: hours. */
			self::LINK_SENT   => sprintf( __( 'We sent a link to %1$s. Open it within %2$d hours.', 'omnabase' ), $masked, self::LINK_HOURS ),
			self::LINKS_USED  => __( 'You asked for three links today. Please open the newest one in your inbox, or ask again tomorrow.', 'omnabase' ),
			self::LINK_LATER  => __( 'No link could be sent just now. Please ask again later.', 'omnabase' ),
			self::LATER       => __( 'Your message could not be sent just now. Please try again later.', 'omnabase' ),
			self::UNAVAILABLE => __( 'New questions cannot be sent here right now. Please write to us by email.', 'omnabase' ),
			self::FAILED      => __( 'Your message could not be sent. Please check it and try again, or write to us by email.', 'omnabase' ),
			self::FIELDS      => __( 'Please write a subject and a message.', 'omnabase' ),
			self::NO_ANSWER   => __( 'Please write your answer.', 'omnabase' ),
			self::NOT_FOUND   => __( 'This conversation was not found.', 'omnabase' ),
			self::EXPIRED     => __( 'This form has expired. Please send it again.', 'omnabase' ),
			self::DOWN        => __( 'Your messages cannot be shown right now. Please try again later.', 'omnabase' ),
			default           => null,
		};
		return null === $message ? null : array( 'ok' => in_array( $code, array( self::CREATED, self::REPLIED, self::LINK_SENT ), true ), 'message' => $message );
	}

	/**
	 * Instead of the tickets: OmnaBase refused the user (the brief's two sentences), or could not be asked.
	 *
	 * @param string $problem One of `Visitor`'s constants.
	 */
	public static function refusal( string $problem ): string {
		return match ( $problem ) {
			Visitor::ADDRESS_TAKEN => __( 'This email address belongs to another account in our support. Please write to us by email.', 'omnabase' ),
			Visitor::FORGOTTEN     => __( 'Your support history was deleted at your request. Write to us to start again.', 'omnabase' ),
			default                => __( 'Your messages cannot be shown right now. Please try again later.', 'omnabase' ),
		};
	}

	/**
	 * An address partly hidden: its first character and its domain ("a…@example.com").
	 *
	 * @param string $email The address.
	 */
	public static function mask( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '…';
		}
		return mb_substr( $email, 0, 1 ) . '…' . substr( $email, $at );
	}
}
