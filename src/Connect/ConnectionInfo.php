<?php
/**
 * What the connection is, kept from the exchange.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Connect;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The workspace, the site, the permissions, the projects (with their names once `GET /me` told them) and the events, so the screen names
 * them even while OmnaBase cannot be reached. No key and no secret: those live in the vault.
 */
final class ConnectionInfo {

	/**
	 * Keeps what the exchange answered, with the projects as `GET /me` names them when it could be asked.
	 *
	 * @param array<string,mixed>      $exchange The exchange's answer.
	 * @param array<int,mixed>|null    $projects The projects from `GET /me` (`{ id, slug, name }`), or null.
	 */
	public function save( array $exchange, ?array $projects ): void {
		update_option(
			Options::SITE,
			array(
				'workspace'   => array( 'name' => (string) ( $exchange['workspace']['name'] ?? '' ), 'slug' => (string) ( $exchange['workspace']['slug'] ?? '' ) ),
				'site'        => array( 'name' => (string) ( $exchange['site']['name'] ?? '' ), 'url' => (string) ( $exchange['site']['url'] ?? '' ) ),
				'scopes'      => self::strings( $exchange['scopes'] ?? array() ),
				'projects'    => self::projects( $projects ?? ( $exchange['projects'] ?? array() ) ),
				'events'      => self::strings( $exchange['webhook']['events'] ?? array() ),
				'connectedAt' => time(),
			),
			false
		);
	}

	/**
	 * What is kept, or an empty array.
	 *
	 * @return array<string,mixed>
	 */
	public function read(): array {
		$info = get_option( Options::SITE, array() );
		return is_array( $info ) ? $info : array();
	}

	/** Forgets it (Disconnect). */
	public function clear(): void {
		delete_option( Options::SITE );
	}

	/**
	 * Projects as `{ id, slug, name }` (a bare id from the exchange keeps its id as its name until `GET /me` names it).
	 *
	 * @param mixed $projects The projects as answered.
	 * @return array<int,array{id:string,slug:string,name:string}>
	 */
	public static function projects( $projects ): array {
		$out = array();
		foreach ( is_array( $projects ) ? $projects : array() as $p ) {
			if ( is_string( $p ) ) {
				$out[] = array( 'id' => $p, 'slug' => '', 'name' => $p );
			} elseif ( is_array( $p ) && isset( $p['id'] ) ) {
				$out[] = array( 'id' => (string) $p['id'], 'slug' => (string) ( $p['slug'] ?? '' ), 'name' => (string) ( $p['name'] ?? $p['id'] ) );
			}
		}
		return $out;
	}

	/**
	 * A list of strings.
	 *
	 * @param mixed $list The list as answered.
	 * @return string[]
	 */
	public static function strings( $list ): array {
		return is_array( $list ) ? array_values( array_map( 'strval', array_filter( $list, 'is_string' ) ) ) : array();
	}
}
