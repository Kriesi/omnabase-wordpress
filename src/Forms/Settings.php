<?php
/**
 * A project's form settings, asked of OmnaBase and kept for an hour.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /forms/{slug}` answers the products to pick from, whether the form asks for a licence and the workspace's privacy link. The answer
 * is kept an hour (a transient), so a busy contact page costs one call an hour. When OmnaBase cannot be asked the form still shows its four
 * fields: the empty settings are kept five minutes, so an outage does not make every view wait for a timeout. OmnaBase refusing a message
 * as wrong forgets the settings (a product list that changed meanwhile).
 */
final class Settings {

	private const KEEP_SECONDS        = HOUR_IN_SECONDS;
	private const KEEP_FAILED_SECONDS = 5 * MINUTE_IN_SECONDS;
	private const PRODUCTS_MAX        = 100;
	private const PRODUCT_LENGTH_MAX  = 60;

	/**
	 * Builds the settings.
	 *
	 * @param Client $client The API client.
	 */
	public function __construct( private Client $client ) {}

	/**
	 * The project's settings: the products, whether a licence is asked, the privacy address ('' when none).
	 *
	 * @param string $slug The project's slug.
	 * @return array{products:string[],askLicense:bool,privacyUrl:string}
	 */
	public function of( string $slug ): array {
		$kept = get_transient( self::name( $slug ) );
		if ( is_array( $kept ) ) {
			return self::shape( $kept );
		}
		$answer   = $this->client->formSettings( $slug );
		$settings = is_wp_error( $answer ) ? self::shape( array() ) : self::shape( $answer );
		set_transient( self::name( $slug ), $settings, is_wp_error( $answer ) ? self::KEEP_FAILED_SECONDS : self::KEEP_SECONDS );
		return $settings;
	}

	/**
	 * Forgets the kept settings, so the next view asks again.
	 *
	 * @param string $slug The project's slug.
	 */
	public function forget( string $slug ): void {
		delete_transient( self::name( $slug ) );
	}

	/**
	 * The transient's name.
	 *
	 * @param string $slug The project's slug.
	 */
	private static function name( string $slug ): string {
		return Options::FORM_TRANSIENT . 's_' . md5( $slug );
	}

	/**
	 * The settings as the form uses them: a list of product names (as given; escaped on output), a flag, an https address or ''.
	 *
	 * @param array<string,mixed> $raw OmnaBase's answer, or what was kept.
	 * @return array{products:string[],askLicense:bool,privacyUrl:string}
	 */
	private static function shape( array $raw ): array {
		$products = array();
		foreach ( is_array( $raw['products'] ?? null ) ? $raw['products'] : array() as $product ) {
			$product = is_string( $product ) ? trim( $product ) : '';
			if ( '' !== $product && mb_strlen( $product ) <= self::PRODUCT_LENGTH_MAX && count( $products ) < self::PRODUCTS_MAX ) {
				$products[] = $product;
			}
		}
		$privacy = is_string( $raw['privacyUrl'] ?? null ) ? trim( $raw['privacyUrl'] ) : '';
		$privacy = 'https' === wp_parse_url( $privacy, PHP_URL_SCHEME ) ? esc_url_raw( $privacy ) : '';
		return array( 'products' => array_values( array_unique( $products ) ), 'askLicense' => true === ( $raw['askLicense'] ?? false ), 'privacyUrl' => $privacy );
	}
}
