<?php
/**
 * The last webhook deliveries the site received.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Rest;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The last 20 deliveries, newest first, for the settings screen: when, which event, the delivery id, the ticket number and what the plugin
 * did (`handled`, `repeat`, `failed`). Never content: no customer name or address, no message text.
 */
final class DeliveryLog {

	public const KEEP = 20;

	/**
	 * Adds a row at the top.
	 *
	 * @param string   $event    The event type (`ping`, `message.sent` …).
	 * @param string   $delivery The delivery id.
	 * @param int|null $ticket   The ticket number the event is about, if any.
	 * @param string   $outcome  What the plugin did: `handled`, `repeat` or `failed`.
	 */
	public function add( string $event, string $delivery, ?int $ticket, string $outcome ): void {
		$row  = array(
			'at'       => time(),
			'event'    => $event,
			'delivery' => $delivery,
			'ticket'   => $ticket,
			'outcome'  => $outcome,
		);
		$rows = array_slice( array_merge( array( $row ), $this->recent() ), 0, self::KEEP );
		update_option( Options::DELIVERIES, $rows, false );
	}

	/**
	 * The rows, newest first, at most 20.
	 *
	 * @return array<int,array{at:int,event:string,delivery:string,ticket:?int,outcome:string}>
	 */
	public function recent(): array {
		$rows = get_option( Options::DELIVERIES, array() );
		return is_array( $rows ) ? array_slice( array_values( array_filter( $rows, 'is_array' ) ), 0, self::KEEP ) : array();
	}
}
