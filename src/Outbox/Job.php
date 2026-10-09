<?php
/**
 * One kind of outbox row: how it is sent, and what follows when OmnaBase accepted it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Outbox;

defined( 'ABSPATH' ) || exit;

/**
 * The body is built when the row is sent, from the user or the post as it is then (the table holds no customer data).
 */
interface Job {

	/** The kind it sends (`user_link`, `licence_refresh`, `forum_topic`, `forum_reply`). */
	public function kind(): string;

	/** The kind in words, for the settings screen. */
	public function label(): string;

	/**
	 * What the row is about, in words, for the settings screen (a user's name, a topic's title).
	 *
	 * @param array<string,mixed> $row The row.
	 */
	public function subject( array $row ): string;

	/**
	 * What to do with a row whose turn came: the call, a wait, a skip, or the administrator.
	 *
	 * @param array<string,mixed> $row The row.
	 */
	public function request( array $row ): Step;

	/**
	 * What follows once OmnaBase accepted the call.
	 *
	 * @param array<string,mixed>      $row    The row.
	 * @param array<string,mixed>|null $answer OmnaBase's answer (its JSON), when it sent one.
	 */
	public function sent( array $row, ?array $answer ): void;
}
