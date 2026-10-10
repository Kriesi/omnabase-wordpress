<?php
/**
 * M14 ROUND FOUR, THE RED TEAM: ADJUSTED, NOT WEAKENED (comment only). The tab links are now the kit's line tabs (`ob-tabs__item`, the open
 * one `ob-tabs__item--active`; see `RoundOneTestCase`'s readers), not WordPress's `nav-tab`; the words and shapes below describe round one's
 * markup and stay as its record. No code in this file changed.
 *
 * M14 round one, the red team's helpers for the tabbed settings screen (inside wp-env's tests WordPress, every run).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 1: "Five tabs on the one screen, WordPress's own tab style, the open
 * tab in the address (`?page=omnabase&tab=connection|data|forums|pages|activity`; `connection` opens by default; the server renders only the
 * open tab …)". The words: *tab*, one of several named panels on a settings page, only one open at a time; *nav-tab*, WordPress's own look
 * for a row of tabs under a page title (the classes `nav-tab` and, on the open one, `nav-tab-active`); *outbox*, the plugin's lasting list of
 * calls to OmnaBase still to make; *delivery*, one call OmnaBase made to the site (a reply was sent, a ticket changed …).
 *
 * Shapes the red team pins here (chosen; the builder may rename and tell the red team): each tab is a link with the class `nav-tab` whose
 * address carries `page=omnabase` and `tab=<slug>`; the open one also has `nav-tab-active`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

abstract class TabsTestCase extends RoundOneTestCase {

	/** Each tab's sections by their kept classes (the brief's "What moves into them"; the classes the older tests pin). */
	protected const SECTIONS = array(
		'connection' => array( 'ob-wp-settings__connection', 'ob-wp-settings__health' ),
		'data'       => array( 'ob-wp-settings__facts' ),
		'forums'     => array( 'ob-wp-settings__forum' ),
		'pages'      => array( 'ob-wp-settings__portal', 'ob-wp-settings__shortcodes' ),
		'activity'   => array( 'ob-wp-settings__outbox', 'ob-wp-settings__deliveries' ),
	);

	/** The save action of each tab that has settings (the plain run: Forums has no form without bbPress). */
	protected const SAVES = array(
		'data'   => 'omnabase_save_facts',
		'forums' => 'omnabase_save_forum',
		'pages'  => 'omnabase_save_pages',
	);

	/**
	 * The site in full use: connected; three deliveries received from OmnaBase (a reply, a ticket change, a resolve); a "Check connection"
	 * that found OmnaBase unable to reach the site (OmnaBase's call met a 401 from the site's receiver: the site's side, not a revoked key);
	 * two outbox rows needing attention and one waiting; a user field the customer-data tab can offer.
	 */
	protected function busySite(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->receiveDeliveries( false );
		$this->answers = array(
			'GET /api/public/v1/me'       => self::answer( 200, self::meAnswer() ),
			'POST /api/public/v1/me/ping' => self::answer( 200, array( 'ok' => false, 'status' => 401 ) ),
		);
		$location = $this->adminPost( 'omnabase_check' );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$this->plantOutboxRow( true );
		$this->plantOutboxRow( true );
		$this->plantOutboxRow( false );
		update_user_meta( $this->admin, 'billing_phone', '+43 1 2345' );
		$this->asAdminOnTheScreen( $this->admin );
	}

	/**
	 * Signed deliveries from OmnaBase, as its webhooks send them: a reply, a ticket change, a resolve, and (when asked) a test ping.
	 *
	 * @param bool $with_ping Whether a test ping arrives too (it would turn a waiting check green).
	 */
	protected function receiveDeliveries( bool $with_ping ): void {
		$types = array( 'message.sent', 'ticket.updated', 'ticket.resolved' );
		if ( $with_ping ) {
			$types[] = 'ping';
		}
		foreach ( $types as $i => $type ) {
			$this->deliverEvent( $type, 'del-m14-' . $i . '-' . wp_generate_password( 6, false ), 'ping' === $type ? array() : array( 'ticket' => array( 'number' => 1230 + $i ), 'status' => 'resolved' ) );
		}
		$this->asAdminOnTheScreen( $this->admin );
	}
}
