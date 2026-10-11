<?php
/**
 * Settings › OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Api\ApiUrl;
use OmnaBase\WordPress\PluginInfo;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's one admin screen (`options-general.php?page=omnabase`), for administrators only, in five tabs (`Tabs`): Connection (the
 * setup line, the connection and its status), Customer data, Forums, Forms and messages (the message centre's page and
 * the shortcodes with the builder's dialog), Activity (what goes to OmnaBase and what OmnaBase sent). Only the open tab's sections are built and printed. The menu
 * entry stays "OmnaBase", with WordPress's bubble after it while outbox rows need attention. The way back from OmnaBase's connect page
 * arrives here too, handled at the page's load hook before anything is printed.
 */
final class SettingsScreen {

	public const SLUG       = 'omnabase';
	public const CAPABILITY = 'manage_options';

	/** The sections each tab builds, by name (the keys of `$sections`). */
	private const TAB_SECTIONS = array(
		Tabs::CONNECTION => array( 'setup', 'connection', 'health', 'healthNotes' ),
		Tabs::DATA       => array( 'facts' ),
		Tabs::FORUMS     => array( 'forum' ),
		Tabs::PAGES      => array( 'portal', 'shortcodes', 'builder' ),
		Tabs::ACTIVITY   => array( 'outbox', 'deliveries' ),
	);

	/** @var callable():int How many outbox rows need attention. */
	private $attention;

	/**
	 * Builds the screen.
	 *
	 * @param PluginInfo                     $info      The header fields (the version shown in the footer line).
	 * @param Flash                          $flash     The message from the last action.
	 * @param Actions                        $actions   The way back's handler.
	 * @param array<string,callable():array> $sections  Each section's view by name (see `TAB_SECTIONS`).
	 * @param callable():int                 $attention How many outbox rows need attention (one small count).
	 */
	public function __construct( private PluginInfo $info, private Flash $flash, private Actions $actions, private array $sections, callable $attention ) {
		$this->attention = $attention;
	}

	/**
	 * The screen's address, on a tab when one is named: https on an https site (OmnaBase's way back must be https), whatever the current
	 * request's scheme.
	 *
	 * @param string $tab A tab's slug, or '' for the bare address.
	 */
	public static function url( string $tab = '' ): string {
		$path = 'options-general.php?page=' . self::SLUG . ( '' !== $tab ? '&tab=' . rawurlencode( Tabs::valid( $tab ) ) : '' );
		return admin_url( $path, 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ? 'https' : 'admin' );
	}

	/** Registers the menu entry. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
	}

	/** Adds Settings › OmnaBase (with the bubble while rows need attention) and the way back at its load hook. */
	public function addMenu(): void {
		$hook = add_options_page(
			__( 'OmnaBase', 'omnabase' ),
			esc_html__( 'OmnaBase', 'omnabase' ) . self::bubble( ( $this->attention )() ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this->actions, 'wayBack' ) );
		}
	}

	/** Renders the open tab. */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OmnaBase.', 'omnabase' ) );
		}
		$tab  = Tabs::current();
		$view = array(
			'version' => $this->info->version(),
			'apiUrl'  => ApiUrl::override(),
			'appUrl'  => ApiUrl::appOverride(),
			'notice'  => $this->flash->take( get_current_user_id() ),
			'tab'     => $tab,
			'tabs'    => Tabs::row( $tab, ( $this->attention )() ),
		) + $this->build( self::TAB_SECTIONS[ $tab ] );
		include $this->info->dir() . 'templates/admin/settings.php';
	}

	/**
	 * The named sections' views.
	 *
	 * @param string[] $names The sections.
	 * @return array<string,array>
	 */
	private function build( array $names ): array {
		$out = array();
		foreach ( $names as $name ) {
			$out[ $name ] = ( $this->sections[ $name ] )();
		}
		return $out;
	}

	/**
	 * WordPress's count bubble after the menu title, or nothing when no row needs attention.
	 *
	 * @param int $count Rows needing attention.
	 */
	private static function bubble( int $count ): string {
		if ( $count < 1 ) {
			return '';
		}
		/* translators: %d: how many calls to OmnaBase need the administrator. */
		$words = sprintf( _n( '%d call needs attention', '%d calls need attention', $count, 'omnabase' ), $count );
		return sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>', $count, esc_html( $words ) );
	}
}
