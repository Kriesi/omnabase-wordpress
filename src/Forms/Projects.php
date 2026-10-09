<?php
/**
 * Which project a shortcode form belongs to.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * The form sends to one of the site key's projects (their slugs as `GET /me` named them at Connect): the shortcode's `project`
 * attribute, else the key's single project. Anything else is a problem only the administrator is told about: the site is not connected,
 * the attribute names a project the key lacks, or the key has several projects and the attribute names none.
 */
final class Projects {

	public const NOT_CONNECTED = 'not_connected';
	public const UNKNOWN       = 'unknown';
	public const AMBIGUOUS     = 'ambiguous';

	/**
	 * Builds the resolver.
	 *
	 * @param ConnectionInfo $connection The connection's projects.
	 * @param Vault          $vault      Whether a key is stored and readable.
	 */
	public function __construct( private ConnectionInfo $connection, private Vault $vault ) {}

	/** Whether the site is connected with a readable key and at least one project. */
	public function connected(): bool {
		return Vault::OK === $this->vault->state() && array() !== $this->slugs();
	}

	/**
	 * Whether a form may send to this project.
	 *
	 * @param string $slug The project's slug.
	 */
	public function allows( string $slug ): bool {
		return '' !== $slug && $this->connected() && in_array( $slug, $this->slugs(), true );
	}

	/**
	 * The form's project: `[ 'slug' => <slug>, 'problem' => '' ]`, or an empty slug and the problem.
	 *
	 * @param string $attribute The shortcode's `project` attribute ('' when not given).
	 * @return array{slug:string,problem:string}
	 */
	public function resolve( string $attribute ): array {
		if ( ! $this->connected() ) {
			return array( 'slug' => '', 'problem' => self::NOT_CONNECTED );
		}
		if ( '' !== $attribute ) {
			return $this->allows( $attribute ) ? array( 'slug' => $attribute, 'problem' => '' ) : array( 'slug' => '', 'problem' => self::UNKNOWN );
		}
		$slugs = $this->slugs();
		return 1 === count( $slugs ) ? array( 'slug' => $slugs[0], 'problem' => '' ) : array( 'slug' => '', 'problem' => self::AMBIGUOUS );
	}

	/**
	 * The key's project slugs.
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		$projects = ConnectionInfo::projects( $this->connection->read()['projects'] ?? array() );
		return array_values( array_filter( array_map( static fn ( array $p ) => $p['slug'], $projects ), static fn ( string $s ) => '' !== $s ) );
	}
}
