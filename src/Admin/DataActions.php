<?php
/**
 * The settings screen's customer-data and outbox actions: save the switches, Retry and Discard a row.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\Switches;
use OmnaBase\WordPress\Outbox\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Thin handlers at `admin-post.php`: each checks its nonce and `manage_options`, calls the switches or the outbox's table, keeps a message
 * and goes back to the screen.
 */
final class DataActions {

	public const SAVE_FACTS = 'omnabase_save_facts';
	public const RETRY      = 'omnabase_outbox_retry';
	public const DISCARD    = 'omnabase_outbox_discard';

	/**
	 * Builds the handlers.
	 *
	 * @param Switches      $switches The switches.
	 * @param FactsSource[] $sources  Every source.
	 * @param Table         $table    The outbox's table.
	 * @param Flash         $flash    The message for the next view.
	 */
	public function __construct( private Switches $switches, private array $sources, private Table $table, private Flash $flash ) {}

	/** Registers the three admin-post actions. */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_FACTS, array( $this, 'saveFacts' ) );
		add_action( 'admin_post_' . self::RETRY, array( $this, 'retry' ) );
		add_action( 'admin_post_' . self::DISCARD, array( $this, 'discard' ) );
	}

	/** Stores exactly the ticked sources and usermeta. */
	public function saveFacts(): void {
		Guard::check( self::SAVE_FACTS );
		$posted = isset( $_POST['omnabase_facts'] ) && is_array( $_POST['omnabase_facts'] ) ? wp_unslash( $_POST['omnabase_facts'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- the nonce is checked by Guard::check() above; each list is sanitised below.
		$this->switches->save(
			array_map( static fn ( FactsSource $s ) => $s->id(), $this->sources ),
			self::keys( $posted['sources'] ?? array() ),
			self::keys( $posted['meta'] ?? array() )
		);
		$this->flash->set( get_current_user_id(), 'success', __( 'Customer data settings saved.', 'omnabase' ) );
		Guard::backToScreen();
	}

	/** A row needing attention waits again, due now. */
	public function retry(): void {
		Guard::check( self::RETRY );
		$done = $this->table->retry( self::row(), time() );
		$this->flash->set( get_current_user_id(), $done ? 'success' : 'info', $done ? __( 'The call will be sent again within a minute.', 'omnabase' ) : __( 'That call no longer needs attention.', 'omnabase' ) );
		Guard::backToScreen();
	}

	/** A row is given up. */
	public function discard(): void {
		Guard::check( self::DISCARD );
		$done = $this->table->discard( self::row(), time() );
		$this->flash->set( get_current_user_id(), $done ? 'success' : 'info', $done ? __( 'The call was discarded: it will not be sent.', 'omnabase' ) : __( 'That call is no longer in the outbox.', 'omnabase' ) );
		Guard::backToScreen();
	}

	/** The posted row id (the nonce was checked by the caller). */
	private static function row(): int {
		return isset( $_POST['row'] ) ? absint( wp_unslash( $_POST['row'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by Guard::check() in the calling handler.
	}

	/**
	 * A posted list of names, as plain strings.
	 *
	 * @param mixed $list The posted list.
	 * @return string[]
	 */
	private static function keys( $list ): array {
		return array_values( array_map( static fn ( $v ) => sanitize_text_field( (string) $v ), array_filter( is_array( $list ) ? $list : array(), 'is_scalar' ) ) );
	}
}
