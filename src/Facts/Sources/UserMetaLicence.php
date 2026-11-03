<?php
/**
 * A licence kept in a user field (usermeta), such as the Envato purchase code kriesi.at's forum stores at registration.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\Field;
use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\Person;
use OmnaBase\WordPress\Facts\Switches;

defined( 'ABSPATH' ) || exit;

/**
 * The licence-sources round (2026-11-03). Needs no other plugin. One field, named on the Customer data tab with the product's name: a
 * user whose field holds text (or a number) has one licence, status "active", the key masked here already so the value never leaves this
 * class. The three answers mean different things to OmnaBase: a guest, or no field named yet, is null (nothing to say, nothing revoked);
 * a user with an empty field, or a list-shaped one (old forum rows), is an empty list ("no licence now").
 */
final class UserMetaLicence implements FactsSource {

	public const ID = 'user_meta_licence';

	/**
	 * Builds the source.
	 *
	 * @param Switches $switches The licence field's settings.
	 */
	public function __construct( private Switches $switches ) {}

	/** {@inheritDoc} */
	public function id(): string {
		return self::ID;
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Licence in a user field', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function section(): string {
		return 'licences';
	}

	/** {@inheritDoc} */
	public function isAvailable(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function collect( Person $person ): ?array {
		$settings = $this->switches->licenceMeta();
		if ( ! $person->isUser() || '' === $settings['key'] ) {
			return null;
		}
		$key = Field::maskedKey( self::text( get_user_meta( (int) $person->userId, $settings['key'], true ) ) );
		return null === $key ? array() : array( self::licence( $key, $settings['product'] ) );
	}

	/**
	 * The stored value as text, or null for anything that is no readable code (a list, an object, true/false, nothing).
	 *
	 * @param mixed $value The stored value.
	 */
	private static function text( $value ): ?string {
		if ( is_int( $value ) || is_string( $value ) ) {
			return (string) $value;
		}
		return null;
	}

	/**
	 * The one licence in the contract's words.
	 *
	 * @param string $masked  The masked key.
	 * @param string $product The product's name, or '' for the stand-in.
	 * @return array<string,string>
	 */
	private static function licence( string $masked, string $product ): array {
		return array(
			'product'   => '' === $product ? __( 'Licence', 'omnabase' ) : $product,
			'keyMasked' => $masked,
			'status'    => 'active',
		);
	}
}
