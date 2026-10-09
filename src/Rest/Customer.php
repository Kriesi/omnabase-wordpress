<?php
/**
 * The customer receiver: `POST /wp-json/omnabase/v1/customer`, where OmnaBase asks the site about a customer (facts v2).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Signed with the facts secret (its own, never the webhook secret). Round one answers an empty object: OmnaBase's card then shows nothing
 * from the site, without an error. Round two answers the switched-on sources' facts.
 */
final class Customer {

	public const ROUTE = '/customer';

	/**
	 * Builds the receiver.
	 *
	 * @param SignedCall $door The signature check.
	 */
	public function __construct( private SignedCall $door ) {}

	/** Registers the route at `rest_api_init`. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoute' ) );
	}

	/** The route. */
	public function registerRoute(): void {
		register_rest_route(
			Events::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'answer' ),
				'permission_callback' => fn ( \WP_REST_Request $request ) => $this->door->check( $request, 'facts' ),
			)
		);
	}

	/** An empty facts object (`{}`, not `[]`). */
	public function answer(): \WP_REST_Response {
		return new \WP_REST_Response( new \stdClass(), 200 );
	}
}
