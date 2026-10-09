<?php
/**
 * The settings screen's actions: Connect, Disconnect, Check connection, and the way back from OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Api\ApiUrl;
use OmnaBase\WordPress\Connect\Disconnect;
use OmnaBase\WordPress\Connect\Flow;

defined( 'ABSPATH' ) || exit;

/**
 * Thin handlers: each checks, calls a service, keeps a message for the screen and redirects. The three buttons post to `admin-post.php`
 * with a nonce (a short-lived form token) of their own name and need `manage_options`. The way back arrives on the settings page itself
 * (its load hook, before any output); its state is its protection against forged returns, checked by the flow for this administrator.
 */
final class Actions {

	/**
	 * Builds the handlers.
	 *
	 * @param Flow       $flow       Connect.
	 * @param Disconnect $disconnect Disconnect.
	 * @param Health     $health     Check connection.
	 * @param Flash      $flash      The message for the next view.
	 */
	public function __construct( private Flow $flow, private Disconnect $disconnect, private Health $health, private Flash $flash ) {}

	/** Registers the three admin-post actions. */
	public function register(): void {
		add_action( 'admin_post_omnabase_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_omnabase_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_omnabase_check', array( $this, 'check' ) );
	}

	/** Connect: off to OmnaBase's connect page, or back with the reason the site cannot connect. */
	public function connect(): void {
		$this->guard( 'omnabase_connect' );
		$start = $this->flow->start( wp_get_current_user() );
		if ( null === $start['link'] ) {
			$this->flash->set( get_current_user_id(), 'error', (string) $start['problem'] );
			$this->backToScreen();
		}
		$host = (string) wp_parse_url( ApiUrl::app(), PHP_URL_HOST );
		add_filter( 'allowed_redirect_hosts', static fn ( $hosts ) => array_merge( (array) $hosts, array( $host ) ) );
		wp_safe_redirect( (string) $start['link'] );
		exit;
	}

	/** Disconnect: revoke and forget. */
	public function disconnect(): void {
		$this->guard( 'omnabase_disconnect' );
		[ $type, $message ] = $this->disconnect->run();
		$this->flash->set( get_current_user_id(), $type, $message );
		$this->backToScreen();
	}

	/** Check connection: both directions. */
	public function check(): void {
		$this->guard( 'omnabase_check' );
		$this->health->run();
		$this->backToScreen();
	}

	/** The way back from OmnaBase (`code` + `state`, or `error` + `state`), at the settings page's load hook. */
	public function wayBack(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the way back from OmnaBase carries no nonce; its state is checked against this administrator's pending connect.
		if ( ! isset( $_GET['state'] ) || ( ! isset( $_GET['code'] ) && ! isset( $_GET['error'] ) ) ) {
			return;
		}
		if ( ! current_user_can( SettingsScreen::CAPABILITY ) ) {
			return;
		}
		$state = sanitize_text_field( wp_unslash( (string) $_GET['state'] ) );
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : null;
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( (string) $_GET['error'] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		[ $type, $message ] = $this->flow->arrive( get_current_user_id(), $state, $code, $error );
		$this->flash->set( get_current_user_id(), $type, $message );
		$this->backToScreen();
	}

	/**
	 * The form's nonce and the administrator's permission, or WordPress's own refusal.
	 *
	 * @param string $action The action, also the nonce's name.
	 */
	private function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( SettingsScreen::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OmnaBase.', 'omnabase' ), 403 );
		}
	}

	/** To the clean settings address. */
	private function backToScreen(): void {
		wp_safe_redirect( SettingsScreen::url() );
		exit;
	}
}
