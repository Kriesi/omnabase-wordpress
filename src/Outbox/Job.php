<?php
/**
 * One kind of outbox row: how it is sent, and what follows when OmnaBase accepted it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Outbox;

defined( 'ABSPATH' ) || exit;

/**
 * The body is built when the row is sent, from the user as they are then (the table holds no customer data).
 */
interface Job {

	/** The kind it sends (`user_link`, `licence_refresh`). */
	public function kind(): string;

	/** The kind in words, for the settings screen. */
	public function label(): string;

	/**
	 * The call for a row, or null when it can no longer be made (the user is gone).
	 *
	 * @param array<string,mixed> $row The row.
	 * @return array{method:string,path:string,body:mixed,timeout:int}|null
	 */
	public function request( array $row ): ?array;

	/**
	 * What follows once OmnaBase accepted the call.
	 *
	 * @param array<string,mixed> $row The row.
	 */
	public function sent( array $row ): void;
}
