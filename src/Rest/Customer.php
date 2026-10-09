<?php
/**
 * The customer receiver: `POST /wp-json/omnabase/v1/customer`, where OmnaBase asks the site about a customer (facts v2).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Rest;

use OmnaBase\WordPress\Facts\Responder;

defined( 'ABSPATH' ) || exit;

/**
 * Signed with the facts secret (its own, never the webhook secret), checked before the handler runs. The handler only reads the call and
 * hands it to the responder: the switched-on sources' facts, shaped to OmnaBase's contract, or `{}` (never `[]`).
 */
final class Customer {

	public const ROUTE = '/customer';

	/**
	 * Builds the receiver.
	 *
	 * @param SignedCall $door      The signature check.
	 * @param Responder  $responder The answer.
	 */
	public function __construct( private SignedCall $door, private Responder $responder ) {}

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

	/**
	 * The answer to a signed call.
	 *
	 * @param \WP_REST_Request $request The call.
	 */
	public function answer( \WP_REST_Request $request ): \WP_REST_Response {
		$call = json_decode( (string) $request->get_body(), true );
		return new \WP_REST_Response( $this->responder->respond( is_array( $call ) ? $call : array() ), 200 );
	}
}
