<?php
/**
 * The message centre's trap field against bots.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

defined( 'ABSPATH' ) || exit;

/**
 * The New question form carries a field people never see and bots fill (`omnabase_hp`): filled, the question goes nowhere. Kept from round
 * four's contact form, whose own protections left with it (the plugin's forms round).
 */
final class Trap {

	public const FIELD = 'omnabase_hp';

	/**
	 * Whether the trap field was filled.
	 *
	 * @param mixed $value What the form sent in the trap field.
	 */
	public function sprung( $value ): bool {
		return ! is_string( $value ) || '' !== trim( $value );
	}
}
