<?php
/**
 * What the plugin itself stores about one email address.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Privacy;

use OmnaBase\WordPress\Forms\FormJob;
use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Outbox\Table;
use OmnaBase\WordPress\Sync\LicenceRefresh;
use OmnaBase\WordPress\Sync\UserLink;
use OmnaBase\WordPress\UserNotes;

defined( 'ABSPATH' ) || exit;

/**
 * Read for WordPress's "Export Personal Data" and "Erase Personal Data" tools: the user with that address and what the plugin keeps for them
 * (the site id OmnaBase knows them by, shared by a network's sites, and this site's own notes: the "known to OmnaBase" mark, the message
 * centre's fingerprint, link times and kept words), the outbox's open rows about them (their account's calls, form messages sent from that
 * address), and the ticket numbers of their forum topics. Nothing here asks OmnaBase: what OmnaBase holds is the workspace's to export or
 * delete.
 */
final class Holdings {

	/**
	 * The user meta the plugin keeps about a customer: the shared site id and four of this site's notes (an administrator's pending connect
	 * is no customer data).
	 */
	public const USER_META = array(
		Options::SITE_ID_META,
		Options::KNOWN_META,
		Options::PORTAL_FINGERPRINT_META,
		Options::PORTAL_LINKS_META,
		Options::PORTAL_DRAFT_META,
	);

	/** The outbox kinds whose subject is a user. */
	private const USER_KINDS = array( UserLink::KIND, LicenceRefresh::KIND );

	/** The most forum topics looked at per address. */
	private const MAX_TOPICS = 200;

	/**
	 * Builds the reader.
	 *
	 * @param Table $table The outbox's table.
	 */
	public function __construct( private Table $table ) {}

	/**
	 * The user with the address, or 0.
	 *
	 * @param string $email The address.
	 */
	public function userOf( string $email ): int {
		$user = get_user_by( 'email', $email );
		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}

	/**
	 * The plugin's user meta for a user that holds a value, by its plain name: the shared site id, and this site's own notes.
	 *
	 * @param int $user_id The user (0: none).
	 * @return array<string,mixed>
	 */
	public function userMeta( int $user_id ): array {
		$out = array();
		foreach ( self::USER_META as $key ) {
			$value = self::noteOf( $user_id, $key );
			if ( '' !== $value && array() !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Forgets one of the plugin's user meta for a user (this site's note, or the shared site id); whether it held one.
	 *
	 * @param int    $user_id The user.
	 * @param string $key     The plain name.
	 */
	public static function forgetNote( int $user_id, string $key ): bool {
		return UserNotes::isPerSite( $key ) ? UserNotes::delete( $user_id, $key ) : delete_user_meta( $user_id, $key );
	}

	/**
	 * One of the plugin's user meta for a user (this site's note, or the shared site id), or ''.
	 *
	 * @param int    $user_id The user (0: none).
	 * @param string $key     The plain name.
	 * @return mixed
	 */
	private static function noteOf( int $user_id, string $key ) {
		if ( $user_id <= 0 ) {
			return '';
		}
		return UserNotes::isPerSite( $key ) ? UserNotes::get( $user_id, $key ) : get_user_meta( $user_id, $key, true );
	}

	/**
	 * The outbox's open rows about the person: their account's calls, and the form messages sent from the address.
	 *
	 * @param int    $user_id The user (0: none).
	 * @param string $email   The address.
	 * @return array<int,array<string,mixed>>
	 */
	public function outboxRows( int $user_id, string $email ): array {
		$rows = array();
		foreach ( $user_id > 0 ? self::USER_KINDS : array() as $kind ) {
			$rows = array_merge( $rows, $this->table->openOf( $kind, $user_id ) );
		}
		foreach ( $this->table->openOf( FormJob::KIND, 0 ) as $row ) {
			if ( 0 === strcasecmp( (string) ( self::payloadBody( $row )['email'] ?? '' ), $email ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * The person's forum topics that became tickets (as a member or as a guest with that address): id, title, ticket number.
	 *
	 * @param int    $user_id The user (0: none).
	 * @param string $email   The address.
	 * @return array<int,array{id:int,title:string,ticket:int}>
	 */
	public function topics( int $user_id, string $email ): array {
		if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
			return array();
		}
		$ids = $user_id > 0 ? $this->topicIds( array( 'author' => $user_id ) ) : array();
		$ids = array_merge( $ids, $this->topicIds( array( 'meta_query' => array( array( 'key' => '_bbp_anonymous_email', 'value' => $email ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- an erasure or export request, one address.
		return array_map(
			static fn ( int $id ) => array( 'id' => $id, 'title' => get_the_title( $id ), 'ticket' => (int) get_post_meta( $id, Options::TICKET_META, true ) ),
			array_values( array_unique( $ids ) )
		);
	}

	/**
	 * A form row's body as stored.
	 *
	 * @param array<string,mixed> $row The row.
	 * @return array<string,mixed>
	 */
	public static function payloadBody( array $row ): array {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		return is_array( $payload ) && is_array( $payload['body'] ?? null ) ? $payload['body'] : array();
	}

	/**
	 * The ids of topics with a ticket number, narrowed by the given query arguments.
	 *
	 * @param array<string,mixed> $narrow The author or the guest's address.
	 * @return int[]
	 */
	private function topicIds( array $narrow ): array {
		$base  = array(
			'post_type'      => bbp_get_topic_post_type(),
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => self::MAX_TOPICS,
			'no_found_rows'  => true,
		);
		$query = new \WP_Query( $narrow + $base + array( 'meta_key' => Options::TICKET_META ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- see topics().
		return array_map( 'intval', $query->posts );
	}
}
