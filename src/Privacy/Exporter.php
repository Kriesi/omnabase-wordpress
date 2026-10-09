<?php
/**
 * The plugin's part of WordPress's "Export Personal Data".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Privacy;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Lists what the plugin holds for an address, in WordPress's export format (one group "OmnaBase", one item per thing): the user's site id,
 * since when OmnaBase knows them, the message centre's kept words, the outbox's open rows about them and their forum topics' ticket numbers.
 * An address the plugin holds nothing for exports nothing. Everything fits one page.
 */
final class Exporter {

	public const ID = 'omnabase';

	/**
	 * Builds the exporter.
	 *
	 * @param Holdings $holdings What the plugin holds.
	 */
	public function __construct( private Holdings $holdings ) {}

	/**
	 * Adds the exporter to WordPress's list.
	 *
	 * @param mixed $exporters WordPress's exporters.
	 * @return mixed
	 */
	public function add( $exporters ) {
		if ( is_array( $exporters ) ) {
			$exporters[ self::ID ] = array( 'exporter_friendly_name' => __( 'OmnaBase', 'omnabase' ), 'callback' => array( $this, 'export' ) );
		}
		return $exporters;
	}

	/**
	 * The export for an address.
	 *
	 * @param string $email The address.
	 * @param int    $page  The page (everything comes on the first).
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		unset( $page );
		$email   = (string) $email;
		$user_id = $this->holdings->userOf( $email );
		$items   = array_merge(
			$this->userItems( $this->holdings->userMeta( $user_id ) ),
			array_map( array( $this, 'outboxItem' ), $this->holdings->outboxRows( $user_id, $email ) ),
			array_map( array( $this, 'topicItem' ), $this->holdings->topics( $user_id, $email ) )
		);
		return array( 'data' => $items, 'done' => true );
	}

	/**
	 * The user's own item: their site id, since when OmnaBase knows them, the message centre's kept words.
	 *
	 * @param array<string,mixed> $meta The plugin's user meta with a value.
	 * @return array<int,array<string,mixed>>
	 */
	private function userItems( array $meta ): array {
		$pairs = array();
		if ( isset( $meta[ Options::SITE_ID_META ] ) ) {
			$pairs[] = self::pair( __( 'Id at OmnaBase (this site\'s id for you)', 'omnabase' ), (string) $meta[ Options::SITE_ID_META ] );
		}
		if ( isset( $meta[ Options::KNOWN_META ] ) ) {
			$since   = (int) $meta[ Options::KNOWN_META ];
			$pairs[] = self::pair( __( 'Known to OmnaBase', 'omnabase' ), $since > YEAR_IN_SECONDS ? self::date( $since ) : __( 'yes', 'omnabase' ) );
		}
		if ( isset( $meta[ Options::PORTAL_LINKS_META ] ) ) {
			$pairs[] = self::pair( __( 'Confirmation links asked for', 'omnabase' ), implode( ', ', array_map( static fn ( $at ) => self::date( (int) $at ), (array) $meta[ Options::PORTAL_LINKS_META ] ) ) );
		}
		$draft = (array) ( $meta[ Options::PORTAL_DRAFT_META ] ?? array() );
		if ( array() !== $draft ) {
			$pairs[] = self::pair( __( 'Unsent question: subject', 'omnabase' ), (string) ( $draft['subject'] ?? '' ) );
			$pairs[] = self::pair( __( 'Unsent question: message', 'omnabase' ), (string) ( $draft['message'] ?? '' ) );
		}
		return array() === $pairs ? array() : array( self::item( 'omnabase-user', $pairs ) );
	}

	/**
	 * An outbox row about the person.
	 *
	 * @param array<string,mixed> $row The row.
	 * @return array<string,mixed>
	 */
	private function outboxItem( array $row ): array {
		$pairs = array(
			self::pair( __( 'Call waiting to go to OmnaBase', 'omnabase' ), (string) $row['kind'] ),
			self::pair( __( 'Queued', 'omnabase' ), self::date( (int) $row['created_at'] ) ),
		);
		foreach ( Holdings::payloadBody( $row ) as $field => $value ) {
			if ( is_scalar( $value ) ) {
				$pairs[] = self::pair( (string) $field, (string) $value );
			}
		}
		return self::item( 'omnabase-outbox-' . (int) $row['id'], $pairs );
	}

	/**
	 * A forum topic that became a ticket.
	 *
	 * @param array{id:int,title:string,ticket:int} $topic The topic.
	 * @return array<string,mixed>
	 */
	private function topicItem( array $topic ): array {
		return self::item(
			'omnabase-topic-' . $topic['id'],
			array(
				self::pair( __( 'Forum topic', 'omnabase' ), $topic['title'] ),
				self::pair( __( 'OmnaBase ticket number', 'omnabase' ), (string) $topic['ticket'] ),
			)
		);
	}

	/**
	 * One export item in the group "OmnaBase".
	 *
	 * @param string                                   $id    The item's id.
	 * @param array<int,array{name:string,value:string}> $pairs Its names and values.
	 * @return array<string,mixed>
	 */
	private static function item( string $id, array $pairs ): array {
		return array(
			'group_id'          => self::ID,
			'group_label'       => __( 'OmnaBase', 'omnabase' ),
			'group_description' => __( 'What the OmnaBase plugin stores on this site; OmnaBase keeps its own copy of support conversations.', 'omnabase' ),
			'item_id'           => $id,
			'data'              => $pairs,
		);
	}

	/**
	 * A name and its value.
	 *
	 * @param string $name  The name.
	 * @param string $value The value.
	 * @return array{name:string,value:string}
	 */
	private static function pair( string $name, string $value ): array {
		return array( 'name' => $name, 'value' => $value );
	}

	/**
	 * A time as a date ('' for none).
	 *
	 * @param int $time The time.
	 */
	private static function date( int $time ): string {
		return $time > 0 ? (string) wp_date( 'Y-m-d H:i', $time ) : '';
	}
}
