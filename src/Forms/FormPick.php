<?php
/**
 * Which form a shortcode shows.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * The form the shortcode names (`id`, a form's slug as OmnaBase shows it), else the project's default form as the workspace's list names it.
 * A named form the list lacks (mistyped, a draft, archived) or a project without a default form is a problem only the administrator is told
 * about. When OmnaBase cannot be asked, a named form still shows (the embed asks OmnaBase itself); a bare shortcode cannot know its form.
 */
final class FormPick {

	public const UNKNOWN_FORM = 'unknown_form';
	public const NO_DEFAULT   = 'no_default';
	public const UNREACHABLE  = 'unreachable';

	/**
	 * Builds the picker.
	 *
	 * @param FormList $list The workspace's forms.
	 */
	public function __construct( private FormList $list ) {}

	/**
	 * The form: `[ 'slug' => <slug>, 'problem' => '' ]`, or an empty slug and the problem.
	 *
	 * @param string $id      The shortcode's `id` ('' when not given).
	 * @param string $project The form's project (its default form when no id is given).
	 * @return array{slug:string,problem:string}
	 */
	public function pick( string $id, string $project ): array {
		if ( '' !== $id ) {
			$known = self::isSlug( $id ) ? $this->list->has( $id ) : false;
			return false === $known ? self::problem( self::UNKNOWN_FORM ) : array( 'slug' => $id, 'problem' => '' );
		}
		$default = $this->list->defaultFor( $project );
		if ( null === $default ) {
			return self::problem( self::UNREACHABLE );
		}
		return '' === $default ? self::problem( self::NO_DEFAULT ) : array( 'slug' => $default, 'problem' => '' );
	}

	/**
	 * Whether some text has a form slug's shape: lower-case letters and digits, single hyphens inside, at most 40 characters.
	 *
	 * @param string $id The text.
	 */
	public static function isSlug( string $id ): bool {
		return strlen( $id ) <= 40 && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
	}

	/**
	 * A problem.
	 *
	 * @param string $problem Which.
	 * @return array{slug:string,problem:string}
	 */
	private static function problem( string $problem ): array {
		return array( 'slug' => '', 'problem' => $problem );
	}
}
