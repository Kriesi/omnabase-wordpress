<?php
/**
 * The `[omnabase_messages]` shortcode.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

use OmnaBase\WordPress\Forms\Projects;
use OmnaBase\WordPress\Forms\Shortcode as FormShortcode;
use OmnaBase\WordPress\Look\FrontStyles;
use OmnaBase\WordPress\PluginInfo;

defined( 'ABSPATH' ) || exit;

/**
 * `[omnabase_messages]` (optional `guest="form"`, `project="<slug>"`) shows a signed-in customer their message centre, only on the page the
 * administrator picked (that page alone is kept out of caches); on any other page, or while the site is not connected, an administrator
 * reads a note and everyone else nothing. A visitor who is not signed in gets a login link that brings them back, or with `guest="form"`
 * round four's contact form. Each render past the two notes announces itself (`Forms\FirstShown::EVENT`), so the first one on the public site is
 * noted.
 */
final class Shortcode {

	public const TAG = 'omnabase_messages';

	/**
	 * Builds the shortcode.
	 *
	 * @param PluginInfo $info     The plugin's folder and version.
	 * @param PickedPage $page     The picked page.
	 * @param Projects   $projects Whether the site is connected.
	 * @param Centre     $centre   The signed-in view's model.
	 * @param Views       $views    The output.
	 * @param FrontStyles $styles   The stylesheets and the wrapper's marks for the chosen look.
	 */
	public function __construct( private PluginInfo $info, private PickedPage $page, private Projects $projects, private Centre $centre, private Views $views, private FrontStyles $styles ) {}

	/** Registers the shortcode. */
	public function register(): void {
		add_shortcode( 'omnabase_messages', array( $this, 'render' ) );
	}

	/**
	 * The shortcode's output.
	 *
	 * @param array<string,string>|string $atts The attributes as written.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts( array( 'guest' => '', 'project' => '' ), is_array( $atts ) ? $atts : array(), self::TAG );
		if ( ! $this->page->isCurrent() ) {
			return Views::adminNote( __( 'OmnaBase message centre: it works only on the page picked under Settings › OmnaBase, which is kept out of caches.', 'omnabase' ) );
		}
		if ( ! $this->projects->connected() ) {
			return Views::adminNote( __( 'OmnaBase message centre: this site is not connected yet. Connect it under Settings › OmnaBase.', 'omnabase' ) );
		}
		do_action( 'omnabase_shown', 'messages' ); // FirstShown::EVENT
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return $this->guest( (string) $atts['guest'], (string) $atts['project'] );
		}
		$this->enqueue();
		return $this->views->centre( array( 'wrapper' => $this->styles->marks() ) + $this->centre->model( $user, sanitize_key( (string) $atts['project'] ) ) );
	}

	/**
	 * What a visitor who is not signed in gets: with `guest="form"` the project's form built in OmnaBase, else (or when no form can be shown,
	 * for instance a project without a default form) the sign-in link.
	 *
	 * @param string $guest   The `guest` attribute.
	 * @param string $project The `project` attribute.
	 */
	private function guest( string $guest, string $project ): string {
		$form = 'form' === $guest ? do_shortcode( '[' . FormShortcode::TAG . ( '' === $project ? '' : ' project="' . esc_attr( sanitize_key( $project ) ) . '"' ) . ']' ) : '';
		if ( '' !== $form ) {
			return $form;
		}
		$this->styles->enqueue();
		return $this->views->loggedOut( $this->page->url(), $this->styles->marks() );
	}

	/** Queues the message centre's small script and the look's stylesheets it shares with the form (the same switch and filter keep them away). */
	private function enqueue(): void {
		wp_enqueue_script( 'omnabase-messages', plugins_url( 'assets/messages.js', $this->info->mainFile() ), array(), $this->info->version(), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		$this->styles->enqueue();
	}
}
