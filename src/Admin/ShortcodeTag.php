<?php
/**
 * The shortcode builder's tag, made from checked choices.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forms\Shortcode as FormShortcode;
use OmnaBase\WordPress\Portal\Shortcode as MessagesShortcode;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules, the same as `assets/admin.js` follows in the browser: the two kinds, the subject without `"`, `[` and `]` (so it can never
 * close the tag early), the attributes in a fixed order (`id` and `subject` only for the form, `guest` only for the message centre,
 * `project` and `id` only when one is chosen), and the page's content: the tag alone, in the block editor's shortcode block. The form's `id`
 * is a form slug the workspace's list offered (checked by `BuilderChoices`).
 */
final class ShortcodeTag {

	public const FORM     = 'form';
	public const MESSAGES = 'messages';
	public const KINDS    = array( self::FORM, self::MESSAGES );
	public const GUESTS   = array( '', 'form' );

	private const SUBJECT_MAX = 150;

	/**
	 * A subject as the tag may carry it: plain text without quotes or square brackets, one line, at most 150 characters.
	 *
	 * @param string $subject The words as typed.
	 */
	public static function cleanSubject( string $subject ): string {
		$plain = sanitize_text_field( str_replace( array( '"', '[', ']' ), '', $subject ) );
		return trim( mb_substr( $plain, 0, self::SUBJECT_MAX ) );
	}

	/**
	 * The tag for checked choices.
	 *
	 * @param array{kind:string,form?:string,project:string,subject:string,guest:string} $choices The choices, already checked.
	 */
	public static function build( array $choices ): string {
		$atts = self::FORM === $choices['kind']
			? array( 'id' => $choices['form'] ?? '', 'project' => $choices['project'], 'subject' => $choices['subject'] )
			: array( 'guest' => $choices['guest'], 'project' => $choices['project'] );
		$name = self::nameOf( $choices['kind'] );
		$tag  = '[' . $name;
		foreach ( array_filter( $atts, static fn ( string $v ) => '' !== $v ) as $att => $value ) {
			$tag .= ' ' . $att . '="' . $value . '"';
		}
		return $tag . ']';
	}

	/**
	 * A page's content holding the tag alone (a shortcode block, which the classic editor shows as the bare tag).
	 *
	 * @param string $tag The tag.
	 */
	public static function content( string $tag ): string {
		return "<!-- wp:shortcode -->\n" . $tag . "\n<!-- /wp:shortcode -->";
	}

	/**
	 * The shortcode's name for a kind.
	 *
	 * @param string $kind `form` or `messages`.
	 */
	public static function nameOf( string $kind ): string {
		return self::FORM === $kind ? FormShortcode::TAG : MessagesShortcode::TAG;
	}
}
