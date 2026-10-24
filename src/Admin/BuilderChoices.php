<?php
/**
 * The shortcode builder's posted choices, checked.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forms\FormList;
use OmnaBase\WordPress\Forms\Projects;

defined( 'ABSPATH' ) || exit;

/**
 * Only known values pass: the kind one of two, the guest choice empty or `form`, the project one of the key's slugs (and needed when the key
 * has several; left out when it has one, so a renamed project never breaks the page), the form one the workspace's list names (or none: the
 * project's default form), the subject cleaned. Anything else is refused with a
 * reason for the owner, never dropped silently (the coordinator's ruling, DECISIONS "M14 round two"). Options of the other kind are ignored.
 */
final class BuilderChoices {

	/**
	 * Builds the reader.
	 *
	 * @param Projects $projects The key's projects.
	 * @param FormList $forms    The workspace's forms.
	 */
	public function __construct( private Projects $projects, private FormList $forms ) {}

	/**
	 * The checked choices, or why they are refused.
	 *
	 * @param mixed $posted The builder's fields as posted (unslashed).
	 * @return array{choices:?array{kind:string,form:string,project:string,subject:string,guest:string},problem:string}
	 */
	public function read( $posted ): array {
		$fields  = is_array( $posted ) ? $posted : array();
		$kind    = self::field( $fields, 'kind' );
		$guest   = self::field( $fields, 'guest' );
		$project = self::field( $fields, 'project' );
		if ( ! in_array( $kind, ShortcodeTag::KINDS, true ) || ! in_array( $guest, ShortcodeTag::GUESTS, true ) || null === $project ) {
			return self::refused( self::unknown() );
		}
		$form    = ShortcodeTag::FORM === $kind;
		$slug    = $form ? (string) self::field( $fields, 'form' ) : '';
		$problem = $this->projectProblem( $project );
		$problem = '' !== $problem ? $problem : $this->formProblem( $slug );
		if ( '' !== $problem ) {
			return self::refused( $problem );
		}
		return array(
			'choices' => array(
				'kind'    => $kind,
				'form'    => $slug,
				'project' => count( $this->projects->slugs() ) > 1 ? $project : '',
				'subject' => $form ? ShortcodeTag::cleanSubject( (string) self::field( $fields, 'subject' ) ) : '',
				'guest'   => $form ? '' : $guest,
			),
			'problem' => '',
		);
	}

	/**
	 * Why a project cannot be used ('' when it can): one the key lacks, or none while the key has several.
	 *
	 * @param string $project The posted slug ('' for none).
	 */
	private function projectProblem( string $project ): string {
		$slugs = $this->projects->slugs();
		if ( '' !== $project && ! in_array( $project, $slugs, true ) ) {
			return self::unknown();
		}
		if ( '' === $project && count( $slugs ) > 1 ) {
			/* translators: %s: the site's projects, such as "enfold, omnalingo". */
			return sprintf( __( 'This site has several projects (%s): choose one in the builder.', 'omnabase' ), implode( ', ', $slugs ) );
		}
		return '';
	}

	/**
	 * Why a form cannot be used ('' when it can): one the workspace's list lacks, or any while OmnaBase cannot be asked.
	 *
	 * @param string $slug The posted form ('' for the project's default form).
	 */
	private function formProblem( string $slug ): string {
		if ( '' === $slug ) {
			return '';
		}
		$known = $this->forms->has( $slug );
		if ( null === $known ) {
			return __( 'OmnaBase could not be asked for your forms: try again in a moment.', 'omnabase' );
		}
		return $known ? '' : self::unknown();
	}

	/**
	 * One posted field as text ('' when missing, null when it is not plain text).
	 *
	 * @param array<string,mixed> $fields The fields.
	 * @param string              $name   The field.
	 */
	private static function field( array $fields, string $name ): ?string {
		$value = $fields[ $name ] ?? '';
		return is_scalar( $value ) ? (string) $value : null;
	}

	/** The refusal of a value the builder never offers. */
	private static function unknown(): string {
		return __( 'The builder sent something this site does not know: try again.', 'omnabase' );
	}

	/**
	 * A refusal.
	 *
	 * @param string $problem Why.
	 * @return array{choices:null,problem:string}
	 */
	private static function refused( string $problem ): array {
		return array( 'choices' => null, 'problem' => $problem );
	}
}
