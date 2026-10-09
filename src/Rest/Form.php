<?php
/**
 * The shortcode form's two routes: `GET /wp-json/omnabase/v1/form-token` and `POST /wp-json/omnabase/v1/form`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Rest;

use OmnaBase\WordPress\Forms\Answer;
use OmnaBase\WordPress\Forms\Guard;
use OmnaBase\WordPress\Forms\Intake;
use OmnaBase\WordPress\Forms\Projects;
use OmnaBase\WordPress\Forms\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Both are called by visitors' browsers, so neither needs a signed-in user; each has its own check as its permission (the brief's named
 * exceptions): the token route the per-address limit on tokens, the form route a connected site. The token route hands a token only for a
 * project of the site's key; the form route hands the fields to the intake. Every answer tells caches not to keep it.
 */
final class Form {

	public const NAMESPACE = 'omnabase/v1';

	/**
	 * Builds the routes.
	 *
	 * @param Projects $projects The key's projects.
	 * @param Guard    $guard    The limits.
	 * @param Token    $token    The time tokens.
	 * @param Intake   $intake   One sent form.
	 */
	public function __construct( private Projects $projects, private Guard $guard, private Token $token, private Intake $intake ) {}

	/** Registers the routes at `rest_api_init`. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/** The routes. */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/form-token',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'token' ),
				'permission_callback' => array( $this, 'mayHaveToken' ),
				'args'                => array( 'project' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/form',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => array( $this, 'mayPost' ),
			)
		);
	}

	/** The token route's check: this address has not fetched too many tokens lately. */
	public function mayHaveToken(): bool|\WP_Error {
		return $this->guard->allowToken() ? true : new \WP_Error( 'omnabase_form_limit', Answer::later()->message, array( 'status' => 429 ) );
	}

	/** The form route's check: the site is connected (otherwise no form can send). */
	public function mayPost(): bool|\WP_Error {
		return $this->projects->connected() ? true : new \WP_Error( 'omnabase_form_unavailable', Answer::unavailable()->message, array( 'status' => 503 ) );
	}

	/**
	 * A fresh token for a project of the site's key.
	 *
	 * @param \WP_REST_Request $request The request (`project`).
	 */
	public function token( \WP_REST_Request $request ): \WP_REST_Response {
		$slug     = (string) $request->get_param( 'project' );
		$response = $this->projects->allows( $slug )
			? new \WP_REST_Response( array( 'token' => $this->token->issue( $slug ) ), 200 )
			: new \WP_REST_Response( array( 'message' => Answer::unavailable()->message ), 404 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/**
	 * A sent form.
	 *
	 * @param \WP_REST_Request $request The form's fields (JSON).
	 */
	public function submit( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->intake->handle( (array) $request->get_params() )->toResponse();
	}
}
