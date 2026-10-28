<?php
/**
 * The forum's post fields: post meta values that travel with a topic or reply to OmnaBase and back onto the team's replies.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The site-fields round (2026-10-29; OmnaBase's brief `docs/briefs/site-fields-round.md`, rule 5). A *post field* is a post meta (a named
 * extra value WordPress keeps beside a post) the administrator declared under Settings › OmnaBase › Forums › Post fields, as a key and the
 * name the team reads (kriesi.at: `_av_supp_private_info`, "Private data"); a developer adds more with the filter `omnabase_forum_fields`.
 * One place for the rules: at most 10 fields, names at most 60 characters, keys `a-z 0-9 _ -` (a key with capitals is refused, never
 * lower-cased: WordPress reads meta by its exact name), never the site's own keys (bbPress's `_bbp_`, the plugin's `_omnabase_`, WordPress's
 * `_wp_` and `_edit_`), values as plain text of at most 10,000 characters. OmnaBase only ever writes a key declared at the moment it posts.
 */
final class PostFields {

	public const MAX       = 10;
	public const LABEL_MAX = 60;
	public const VALUE_MAX = 10000;

	/** The transient (a value WordPress keeps for a while) holding the auto-complete's meta keys, for an hour; dropped on save. */
	public const META_KEYS_TRANSIENT = Options::FORUM_META_KEYS_TRANSIENT;

	/** The keys a site never lets OmnaBase read or write. */
	private const FORBIDDEN = array( '_bbp_', '_omnabase_', '_wp_', '_edit_' );

	/** How many of the newest topics and replies the auto-complete reads, and how many keys it offers. */
	private const NEWEST_POSTS = 500;
	private const META_KEYS_MAX = 100;

	/**
	 * Builds the fields.
	 *
	 * @param Settings $settings The forum settings (the declared rows).
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * The rows as posted from the Forums tab, cleaned: key ⇒ name in order, and the rows dropped (named by their key, else their name). A row
	 * with both inputs empty is skipped silently (the empty row the screen always offers).
	 *
	 * @param mixed $rows The posted rows: `[ [ 'key' => …, 'label' => … ], … ]`.
	 * @return array{fields:array<string,string>,dropped:string[]}
	 */
	public static function cleanRows( $rows ): array {
		$fields  = array();
		$dropped = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key   = trim( is_scalar( $row['key'] ?? null ) ? (string) $row['key'] : '' );
			$label = trim( sanitize_text_field( is_scalar( $row['label'] ?? null ) ? (string) $row['label'] : '' ) );
			if ( '' === $key && '' === $label ) {
				continue;
			}
			if ( ! self::isUsable( $key, $label ) || isset( $fields[ $key ] ) || count( $fields ) >= self::MAX ) {
				$dropped[] = '' !== $key ? $key : $label;
				continue;
			}
			$fields[ $key ] = $label;
		}
		return array( 'fields' => $fields, 'dropped' => $dropped );
	}

	/**
	 * A post meta value as OmnaBase takes it: plain lines (paragraphs and line breaks as new lines, tags and scripts gone, entities decoded),
	 * trimmed, at most 10,000 characters.
	 *
	 * @param string $stored The stored value.
	 */
	public static function value( string $stored ): string {
		return PlainText::of( $stored, self::VALUE_MAX );
	}

	/**
	 * Writes the fields of OmnaBase's reply on the posted forum reply: only keys declared now, each value as text without tags (line breaks
	 * and `%2F`-style sequences kept, so a login address still works), then the action `omnabase_forum_field_saved`. Any other key writes
	 * nothing.
	 *
	 * @param int                  $reply_id The forum reply.
	 * @param mixed                $fields   The message's `fields` as received.
	 * @param array<string,string> $declared The declared fields, key ⇒ name.
	 */
	public static function write( int $reply_id, $fields, array $declared ): void {
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			$key   = is_array( $field ) && is_string( $field['key'] ?? null ) ? $field['key'] : '';
			$value = is_array( $field ) && is_string( $field['value'] ?? null ) ? trim( wp_kses( $field['value'], array() ) ) : '';
			if ( '' === $key || '' === $value || ! isset( $declared[ $key ] ) ) {
				continue;
			}
			update_post_meta( $reply_id, $key, wp_slash( $value ) );
			do_action( 'omnabase_forum_field_saved', $reply_id, $key, $value );
		}
	}

	/** The declared fields: the setting's rows, then the filter `omnabase_forum_fields`, under the same rules. @return array<string,string> */
	public function declared(): array {
		$saved = $this->settings->fields();
		$all   = apply_filters( 'omnabase_forum_fields', $saved );
		$rows  = array();
		foreach ( is_array( $all ) ? $all : array() as $key => $label ) {
			$rows[] = array( 'key' => (string) $key, 'label' => is_scalar( $label ) ? (string) $label : '' );
		}
		return self::cleanRows( $rows )['fields'];
	}

	/** The reply fields OmnaBase is told with a topic: every declared field. @return array<int,array{key:string,label:string}> */
	public function replyFields(): array {
		$out = array();
		foreach ( $this->declared() as $key => $label ) {
			$out[] = array( 'key' => $key, 'label' => $label );
		}
		return $out;
	}

	/**
	 * A post's fields as OmnaBase takes them: each declared field whose value (the post meta, through the filter `omnabase_forum_field_value`)
	 * is not empty once made plain.
	 *
	 * @param int $post_id The topic or reply.
	 * @return array<int,array{key:string,label:string,value:string}>
	 */
	public function valuesOf( int $post_id ): array {
		$out = array();
		foreach ( $this->declared() as $key => $label ) {
			$meta  = get_post_meta( $post_id, $key, true );
			$given = apply_filters( 'omnabase_forum_field_value', is_scalar( $meta ) ? (string) $meta : '', $key, $post_id );
			$value = self::value( is_scalar( $given ) ? (string) $given : '' );
			if ( '' !== $value ) {
				$out[] = array( 'key' => $key, 'label' => $label, 'value' => $value );
			}
		}
		return $out;
	}

	/**
	 * The auto-complete's keys: the meta keys of the 500 newest topics and replies (bbPress's own `_bbp_` keys and the refused ones left out,
	 * at most 100), plus the declared ones; one bounded query, kept for an hour.
	 *
	 * @return string[]
	 */
	public function metaKeys(): array {
		$cached = get_transient( self::META_KEYS_TRANSIENT );
		$keys   = is_array( $cached ) ? $cached : $this->readMetaKeys();
		if ( ! is_array( $cached ) ) {
			set_transient( self::META_KEYS_TRANSIENT, $keys, HOUR_IN_SECONDS );
		}
		$all = array_values( array_unique( array_merge( array_map( 'strval', $keys ), array_keys( $this->settings->fields() ) ) ) );
		sort( $all );
		return $all;
	}

	/** Forgets the auto-complete's keys (the forum settings were saved). */
	public static function forgetMetaKeys(): void {
		delete_transient( self::META_KEYS_TRANSIENT );
	}

	/** The site's meta keys, read once. @return string[] */
	private function readMetaKeys(): array {
		global $wpdb;
		if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
			return array();
		}
		// MySQL refuses LIMIT inside IN ( … ), hence the derived table of the newest posts.
		$keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one bounded read of distinct meta keys, no API for that; cached in a transient above.
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm WHERE pm.post_id IN ( SELECT ID FROM ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( %s, %s ) ORDER BY ID DESC LIMIT %d ) newest ) AND pm.meta_key NOT LIKE %s ORDER BY pm.meta_key LIMIT %d",
				bbp_get_topic_post_type(),
				bbp_get_reply_post_type(),
				self::NEWEST_POSTS,
				$wpdb->esc_like( '_bbp_' ) . '%',
				self::META_KEYS_MAX
			)
		);
		return array_values( array_filter( array_map( 'strval', (array) $keys ), static fn ( string $k ) => ! self::isForbidden( $k ) ) );
	}

	/**
	 * Whether a row may be kept: a key of `a-z 0-9 _ -` (1 to 100), not one of the site's own, and a name of 1 to 60 characters.
	 *
	 * @param string $key   The meta key.
	 * @param string $label The name.
	 */
	private static function isUsable( string $key, string $label ): bool {
		return 1 === preg_match( '/^[a-z0-9_-]{1,100}$/', $key ) && ! self::isForbidden( $key ) && '' !== $label && mb_strlen( $label ) <= self::LABEL_MAX;
	}

	/**
	 * Whether a key is one of the site's own (bbPress's, the plugin's, WordPress's).
	 *
	 * @param string $key The meta key.
	 */
	private static function isForbidden( string $key ): bool {
		foreach ( self::FORBIDDEN as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
