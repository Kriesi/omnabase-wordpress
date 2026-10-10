<?php
/**
 * The setup line at the top of the Connection tab, and its Dismiss.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forms\Display;
use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * "Set up: Connect ✓ · Customer data · Forums · Add the form to a page", each step a link to its tab. A step is done when its setting is
 * stored: Connect while the site holds a key OmnaBase has not refused, Customer data and Forums once their tab was saved, the form step once
 * a tag first showed on the public site (the first-display mark). Forums is left out where bbPress is not active. The line is gone once
 * every step is done or the administrator dismissed it (a nonce and `manage_options`, as every action on the screen).
 */
final class SetupLine {

	public const DISMISS = 'omnabase_dismiss_setup';

	/**
	 * Builds the line.
	 *
	 * @param Vault   $vault   Whether the site is connected.
	 * @param Display $display The first-display marks.
	 * @param Flash   $flash   The message for the next view.
	 */
	public function __construct( private Vault $vault, private Display $display, private Flash $flash ) {}

	/** Registers the Dismiss action. */
	public function register(): void {
		add_action( 'admin_post_' . self::DISMISS, array( $this, 'dismiss' ) );
	}

	/** Dismiss: the line stays away for good. */
	public function dismiss(): void {
		Guard::check( self::DISMISS );
		update_option( Options::SETUP, array( 'dismissed' => time() ), false );
		$this->flash->set( get_current_user_id(), 'info', __( 'The setup line is hidden. Every step stays on its tab.', 'omnabase' ) );
		Guard::backToScreen( Tabs::CONNECTION );
	}

	/**
	 * What the template shows: the steps, or none when the line is not shown.
	 *
	 * @return array{steps:array<int,array{step:string,label:string,url:string,done:bool}>}
	 */
	public function build(): array {
		$steps = $this->steps();
		$open  = array_filter( $steps, static fn ( array $s ) => ! $s['done'] );
		return array( 'steps' => array() === $open || $this->dismissed() ? array() : $steps );
	}

	/**
	 * Every step in order, each with whether it is done.
	 *
	 * @return array<int,array{step:string,label:string,url:string,done:bool}>
	 */
	private function steps(): array {
		$steps = array(
			self::step( 'connect', __( 'Connect', 'omnabase' ), Tabs::CONNECTION, Vault::OK === $this->vault->state() && ! $this->vault->isRevoked() ),
			self::step( 'data', __( 'Customer data', 'omnabase' ), Tabs::DATA, false !== get_option( Options::FACTS ) ),
		);
		if ( function_exists( 'bbp_get_forum_post_type' ) ) {
			$steps[] = self::step( 'forums', __( 'Forums', 'omnabase' ), Tabs::FORUMS, false !== get_option( Options::FORUM ) );
		}
		$steps[] = self::step( 'form', __( 'Add the form to a page', 'omnabase' ), Tabs::PAGES, array() !== $this->display->firstShown() );
		return $steps;
	}

	/**
	 * One step.
	 *
	 * @param string $step  Its name.
	 * @param string $label Its words.
	 * @param string $tab   The tab its link opens.
	 * @param bool   $done  Whether it is done.
	 * @return array{step:string,label:string,url:string,done:bool}
	 */
	private static function step( string $step, string $label, string $tab, bool $done ): array {
		return array( 'step' => $step, 'label' => $label, 'url' => SettingsScreen::url( $tab ), 'done' => $done );
	}

	/** Whether the administrator dismissed the line. */
	private function dismissed(): bool {
		$stored = get_option( Options::SETUP, array() );
		return is_array( $stored ) && ! empty( $stored['dismissed'] );
	}
}
