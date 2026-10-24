<?php
/**
 * The suggested privacy-policy text.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Privacy;

use OmnaBase\WordPress\Api\ApiUrl;

defined( 'ABSPATH' ) || exit;

/**
 * Hands WordPress a paragraph for the site owner's privacy policy (Settings › Privacy shows it to copy): OmnaBase, a service of Kriesi Media
 * GmbH, as the processor; what each piece sends (forum topics and replies, form messages, the message centre, customer data and licence
 * changes); what comes back; and OmnaBase's own privacy policy. WordPress takes it only during `admin_init`.
 */
final class PolicyText {

	/** Registers the text at `admin_init`. */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add' ) );
	}

	/** Gives WordPress the text (when its privacy tools are there). */
	public function add(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'OmnaBase', 'omnabase' ), self::text() );
		}
	}

	/** The text: a note for the owner, then the suggested paragraphs. */
	public static function text(): string {
		$sentences = array(
			__( 'We answer support questions with OmnaBase, a service of Kriesi Media GmbH, which acts as our processor and handles this data on our behalf.', 'omnabase' ),
			__( 'When you post in our support forum, your forum topics and replies are sent to OmnaBase (app.omnabase.com) with your name and email address.', 'omnabase' ),
			__( 'When you write to us through our contact form, your browser loads the form from OmnaBase and sends what you enter there; when you are signed in, this site also tells OmnaBase your email address, name, newest order number and licence key so they show as checked.', 'omnabase' ),
			__( 'When you are signed in, our message centre shows your support conversations; your name, email address and an id for your account are sent to OmnaBase.', 'omnabase' ),
			__( 'When our team looks at a support question, OmnaBase asks this site for customer data such as your account, orders, subscriptions and licences; licence changes are sent when they happen.', 'omnabase' ),
			__( 'This site receives the replies and status changes of its own tickets from OmnaBase.', 'omnabase' ),
			__( 'OmnaBase keeps your support conversations; to see or delete them, ask us and we do so in OmnaBase.', 'omnabase' ),
		);
		$html  = '<p class="privacy-policy-tutorial">' . esc_html__( 'This site uses OmnaBase for customer support. Keep the sentences for the features you turned on.', 'omnabase' ) . '</p>';
		$html .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'omnabase' ) . ' </strong>' . esc_html( implode( ' ', $sentences ) ) . '</p>';
		/* translators: %s: the address of OmnaBase's privacy policy. */
		$html .= '<p>' . sprintf( esc_html__( 'OmnaBase\'s privacy policy: %s', 'omnabase' ), '<a href="' . esc_url( ApiUrl::PRIVACY ) . '">' . esc_html( ApiUrl::PRIVACY ) . '</a>' ) . '</p>';
		return $html;
	}
}
