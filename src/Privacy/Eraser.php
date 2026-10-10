<?php
/**
 * The plugin's part of WordPress's "Erase Personal Data".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Privacy;

use OmnaBase\WordPress\Outbox\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Removes what the plugin keeps for the address's user (the site id, the "known to OmnaBase" mark, the message centre's fingerprint, link
 * times and kept words) and gives up the outbox's open rows about the person, their payload emptied. Forum topics keep their ticket numbers
 * (a number is no personal data once the topic's author is erased by WordPress or bbPress). OmnaBase's own copy is not touched: the message
 * says so, since deleting it is the workspace's step in OmnaBase.
 */
final class Eraser {

	public const ID = 'omnabase';

	/**
	 * Builds the eraser.
	 *
	 * @param Holdings $holdings What the plugin holds.
	 * @param Table    $table    The outbox's table.
	 */
	public function __construct( private Holdings $holdings, private Table $table ) {}

	/**
	 * Adds the eraser to WordPress's list.
	 *
	 * @param mixed $erasers WordPress's erasers.
	 * @return mixed
	 */
	public function add( $erasers ) {
		if ( is_array( $erasers ) ) {
			$erasers[ self::ID ] = array( 'eraser_friendly_name' => __( 'OmnaBase', 'omnabase' ), 'callback' => array( $this, 'erase' ) );
		}
		return $erasers;
	}

	/**
	 * Erases what the plugin holds for an address.
	 *
	 * @param string $email The address.
	 * @param int    $page  The page (everything goes on the first).
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$email   = (string) $email;
		$user_id = $this->holdings->userOf( $email );
		$removed = false;
		foreach ( array_keys( $this->holdings->userMeta( $user_id ) ) as $key ) {
			$removed = Holdings::forgetNote( $user_id, $key ) || $removed;
		}
		foreach ( $this->holdings->outboxRows( $user_id, $email ) as $row ) {
			$removed = $this->table->anonymise( (int) $row['id'], __( 'Discarded: the person\'s data was erased on this site.', 'omnabase' ), time() ) || $removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $removed ? array( __( 'OmnaBase keeps its own copy of this person\'s support conversations; delete the customer in OmnaBase too.', 'omnabase' ) ) : array(),
			'done'           => true,
		);
	}
}
