<?php
/**
 * Who a facts call is about.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

defined( 'ABSPATH' ) || exit;

/**
 * A WordPress user (found by the site id OmnaBase sent, or by the address), or a guest known only by the address: the sources then look for
 * records billed to it (WooCommerce orders, an EDD customer with that address among its own).
 */
final class Person {

	/**
	 * Builds the person.
	 *
	 * @param int|null $userId     The WordPress user, or null for a guest.
	 * @param string   $email      The user's own address, or the guest's.
	 * @param bool     $byExternal Whether OmnaBase named the user by their site id.
	 */
	public function __construct( public readonly ?int $userId, public readonly string $email, public readonly bool $byExternal ) {}

	/** Whether the person has an account. */
	public function isUser(): bool {
		return null !== $this->userId;
	}
}
