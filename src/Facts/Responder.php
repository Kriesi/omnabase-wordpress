<?php
/**
 * The answer to OmnaBase's question about a customer (facts v2).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

use OmnaBase\WordPress\Sync\UserLink;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing is shared until a source is switched on (`{}`). Then: who the call is about (the site id first, else the address), each
 * switched-on source's records, shaped to the contract, the log line. A user OmnaBase named by site id counts as known; a user found only by
 * address gets the link queued (once), so later calls and the licence refreshes carry the site's id.
 */
final class Responder {

	/**
	 * Builds the responder.
	 *
	 * @param Switches      $switches The switches.
	 * @param Identity      $identity Who a call is about.
	 * @param FactsSource[] $sources  Every source.
	 * @param Shaper        $shaper   The shaper.
	 * @param UserLink      $links    The known users and the link.
	 * @param FactsLog      $log      The log line.
	 */
	public function __construct( private Switches $switches, private Identity $identity, private array $sources, private Shaper $shaper, private UserLink $links, private FactsLog $log ) {}

	/**
	 * The answer to send.
	 *
	 * @param array<string,mixed> $call The call as OmnaBase sent it: `{ v, project, customer { email, external }, ticket, sentAt }`.
	 */
	public function respond( array $call ): object {
		if ( ! $this->switches->anyOn() ) {
			return new \stdClass();
		}
		$customer = is_array( $call['customer'] ?? null ) ? $call['customer'] : array();
		$person   = $this->identity->resolve( is_string( $customer['email'] ?? null ) ? $customer['email'] : '', is_string( $customer['external'] ?? null ) ? $customer['external'] : null );
		$facts    = null === $person ? array() : $this->collect( $person );
		if ( null !== $person && $person->isUser() ) {
			$this->links->met( (int) $person->userId, $person->byExternal );
		}
		$shaped = $this->shaper->shape( $facts );
		$this->log->add( null === $person ? 'nobody' : ( $person->isUser() ? 'user' : ( array() === $facts ? 'nobody' : 'guest' ) ), array_keys( $facts ), $shaped['dropped'] );
		return $shaped['answer'];
	}

	/**
	 * Each switched-on source's records, by section.
	 *
	 * @param Person $person Who the call is about.
	 * @return array<string,mixed>
	 */
	private function collect( Person $person ): array {
		$facts = array();
		foreach ( $this->sources as $source ) {
			if ( ! $this->switches->isOn( $source->id() ) || ! $source->isAvailable() ) {
				continue;
			}
			$found = $source->collect( $person );
			if ( null === $found ) {
				continue;
			}
			$section           = $source->section();
			$facts[ $section ] = 'profile' === $section ? $found : array_merge( $facts[ $section ] ?? array(), $found );
		}
		if ( isset( $facts['orders'] ) ) {
			usort( $facts['orders'], static fn ( $a, $b ) => (string) ( $b['date'] ?? '' ) <=> (string) ( $a['date'] ?? '' ) );
		}
		return $facts;
	}
}
