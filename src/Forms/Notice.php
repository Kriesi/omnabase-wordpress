<?php
/**
 * The administrator's note where the form shortcode cannot show a form.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * One line for whoever may change the settings (`manage_options`), saying what to fix; nothing for anyone else, so visitors never meet a
 * broken box. Every word is translated and escaped; what the shortcode named is shown as text.
 */
final class Notice {

	/**
	 * The note when the form cannot tell its project.
	 *
	 * @param string   $problem   Which problem (`Projects`' constants).
	 * @param string   $attribute The project the shortcode named.
	 * @param string[] $slugs     The site's projects.
	 */
	public static function project( string $problem, string $attribute, array $slugs ): string {
		$list  = implode( ', ', $slugs );
		$words = match ( $problem ) {
			/* translators: 1: the project the shortcode named, 2: the site's projects. */
			Projects::UNKNOWN   => sprintf( __( 'OmnaBase form: the project "%1$s" is not one of this site\'s projects (%2$s).', 'omnabase' ), $attribute, $list ),
			/* translators: %s: the site's projects. */
			Projects::AMBIGUOUS => sprintf( __( 'OmnaBase form: this site has several projects (%s); name one, as in [omnabase_form project="slug"].', 'omnabase' ), $list ),
			default             => __( 'OmnaBase form: this site is not connected, so the form has no project yet. Connect it under Settings › OmnaBase.', 'omnabase' ),
		};
		return self::line( $words );
	}

	/**
	 * The note when the shortcode's form cannot be shown.
	 *
	 * @param string $problem Which problem (`FormPick`'s constants).
	 * @param string $id      The form the shortcode named.
	 * @param string $project The form's project.
	 */
	public static function form( string $problem, string $id, string $project ): string {
		$words = match ( $problem ) {
			/* translators: %s: the form the shortcode named. */
			FormPick::UNKNOWN_FORM => sprintf( __( 'OmnaBase form: your workspace has no published form "%s". Check its address under Settings › Forms in OmnaBase.', 'omnabase' ), $id ),
			/* translators: %s: the project's slug. */
			FormPick::NO_DEFAULT   => sprintf( __( 'OmnaBase form: the project "%s" has no default form. Name a form, as in [omnabase_form id="contact"], or choose one in OmnaBase.', 'omnabase' ), $project ),
			default                => __( 'OmnaBase form: OmnaBase could not be asked for the project\'s default form. Name a form, as in [omnabase_form id="contact"].', 'omnabase' ),
		};
		return self::line( $words );
	}

	/**
	 * The note's markup for an administrator ('' for anyone else).
	 *
	 * @param string $words What to fix.
	 */
	private static function line( string $words ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return '<p class="ob-wp ob-wp-form-notice">' . esc_html( $words . ' ' . __( 'Only administrators see this note.', 'omnabase' ) ) . '</p>';
	}
}
