<?php
/**
 * The settings screen's five tabs: the fixed list, the open one, and the tab row.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The tabs in WordPress's own style (a row of `nav-tab` links under the title), the open one named in the address (`&tab=…`). Only these
 * five slugs exist; no tab, or any other value, opens Connection. Activity's label carries the number of outbox rows needing attention.
 */
final class Tabs {

	public const CONNECTION = 'connection';
	public const DATA       = 'data';
	public const FORUMS     = 'forums';
	public const PAGES      = 'pages';
	public const ACTIVITY   = 'activity';

	/** The slugs, in the row's order. */
	public const ALL = array( self::CONNECTION, self::DATA, self::FORUMS, self::PAGES, self::ACTIVITY );

	/**
	 * A slug from the fixed list: the given one when it is on it, else Connection.
	 *
	 * @param mixed $tab A tab from an address or a caller.
	 */
	public static function valid( $tab ): string {
		return is_string( $tab ) && in_array( $tab, self::ALL, true ) ? $tab : self::CONNECTION;
	}

	/** The open tab, from the address. */
	public static function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show; nothing is changed.
		return self::valid( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '' );
	}

	/**
	 * The tab row: each tab's label, address and whether it is open.
	 *
	 * @param string $open      The open tab.
	 * @param int    $attention Outbox rows needing attention (Activity's count).
	 * @return array<int,array{slug:string,label:string,url:string,active:bool}>
	 */
	public static function row( string $open, int $attention ): array {
		$labels = array(
			self::CONNECTION => __( 'Connection', 'omnabase' ),
			self::DATA       => __( 'Customer data', 'omnabase' ),
			self::FORUMS     => __( 'Forums', 'omnabase' ),
			self::PAGES      => __( 'Forms and messages', 'omnabase' ),
			/* translators: %d: how many calls to OmnaBase need the administrator. */
			self::ACTIVITY   => $attention > 0 ? sprintf( __( 'Activity · %d', 'omnabase' ), $attention ) : __( 'Activity', 'omnabase' ),
		);
		$row = array();
		foreach ( self::ALL as $slug ) {
			$row[] = array( 'slug' => $slug, 'label' => $labels[ $slug ], 'url' => SettingsScreen::url( $slug ), 'active' => $slug === $open );
		}
		return $row;
	}
}
