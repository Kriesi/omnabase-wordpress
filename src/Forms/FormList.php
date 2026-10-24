<?php
/**
 * The workspace's forms, asked of OmnaBase and kept for an hour.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /forms` answers the workspace's published forms (slug, name, the key's projects whose default form each is). The answer is kept an
 * hour (a transient), so a busy contact page costs one call an hour; when OmnaBase cannot be asked, that is kept five minutes, so an outage
 * does not make every view wait. A form or a default that the kept list lacks asks again once the list is a minute old (a form published a
 * moment ago shows at once). The settings screen always asks afresh. A new connection forgets the list: it may be another workspace's.
 */
final class FormList {

	private const KEEP_SECONDS        = HOUR_IN_SECONDS;
	private const KEEP_FAILED_SECONDS = 5 * MINUTE_IN_SECONDS;
	private const STALE_AFTER_SECONDS = MINUTE_IN_SECONDS;

	/**
	 * Builds the list.
	 *
	 * @param Client $client The API client.
	 */
	public function __construct( private Client $client ) {}

	/** Forgets the kept list when the site connects again. */
	public function register(): void {
		add_action( 'omnabase_connected', array( $this, 'forget' ) );
	}

	/**
	 * The forms as kept (or asked now): `[ [ 'slug', 'name', 'defaultFor' => string[] ] ]`, or null when OmnaBase could not be asked.
	 *
	 * @return array<int,array{slug:string,name:string,defaultFor:string[]}>|null
	 */
	public function all(): ?array {
		$kept = get_transient( self::name() );
		return is_array( $kept ) ? self::formsOf( $kept ) : $this->fresh();
	}

	/**
	 * The forms asked afresh (kept for the next views), or null when OmnaBase could not be asked.
	 *
	 * @return array<int,array{slug:string,name:string,defaultFor:string[]}>|null
	 */
	public function fresh(): ?array {
		$answer = $this->client->forms();
		$forms  = is_wp_error( $answer ) ? null : self::shape( $answer['data'] ?? null );
		set_transient( self::name(), array( 'at' => time(), 'forms' => $forms ), null === $forms ? self::KEEP_FAILED_SECONDS : self::KEEP_SECONDS );
		return $forms;
	}

	/**
	 * Whether the workspace has this published form (null: OmnaBase could not be asked).
	 *
	 * @param string $slug The form's slug.
	 */
	public function has( string $slug ): ?bool {
		$found = $this->lookUp( static fn ( array $forms ) => in_array( $slug, array_column( $forms, 'slug' ), true ) ? $slug : '' );
		return null === $found ? null : '' !== $found;
	}

	/**
	 * The slug of a project's default form ('' when it has none, null when OmnaBase could not be asked).
	 *
	 * @param string $project The project's slug.
	 */
	public function defaultFor( string $project ): ?string {
		return $this->lookUp(
			static function ( array $forms ) use ( $project ): string {
				foreach ( $forms as $form ) {
					if ( in_array( $project, $form['defaultFor'], true ) ) {
						return $form['slug'];
					}
				}
				return '';
			}
		);
	}

	/** Forgets the kept list, so the next view asks again. */
	public function forget(): void {
		delete_transient( self::name() );
	}

	/**
	 * A finding in the kept list; a miss asks again when the list is over a minute old.
	 *
	 * @param callable(array):string $find What to find ('' for nothing).
	 */
	private function lookUp( callable $find ): ?string {
		$kept  = get_transient( self::name() );
		$forms = is_array( $kept ) ? self::formsOf( $kept ) : $this->fresh();
		$found = null === $forms ? null : $find( $forms );
		if ( '' === $found && is_array( $kept ) && time() - (int) ( $kept['at'] ?? 0 ) > self::STALE_AFTER_SECONDS ) {
			$forms = $this->fresh();
			$found = null === $forms ? null : $find( $forms );
		}
		return $found;
	}

	/** The transient's name, under the form's prefix (uninstall sweeps it). */
	private static function name(): string {
		return Options::FORM_TRANSIENT . 'list';
	}

	/**
	 * The forms in what was kept (null: the failure was kept).
	 *
	 * @param array<string,mixed> $kept The transient's value.
	 * @return array<int,array{slug:string,name:string,defaultFor:string[]}>|null
	 */
	private static function formsOf( array $kept ): ?array {
		return is_array( $kept['forms'] ?? null ) ? $kept['forms'] : null;
	}

	/**
	 * OmnaBase's list as the plugin keeps it: forms with a slug, their names, the project slugs they are the default of.
	 *
	 * @param mixed $data The answer's `data`.
	 * @return array<int,array{slug:string,name:string,defaultFor:string[]}>|null
	 */
	private static function shape( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$out = array();
		foreach ( $data as $form ) {
			$slug = is_array( $form ) && is_string( $form['slug'] ?? null ) ? $form['slug'] : '';
			if ( '' !== $slug ) {
				$defaults = is_array( $form['defaultFor'] ?? null ) ? array_values( array_filter( $form['defaultFor'], 'is_string' ) ) : array();
				$out[]    = array( 'slug' => $slug, 'name' => is_string( $form['name'] ?? null ) ? $form['name'] : $slug, 'defaultFor' => $defaults );
			}
		}
		return $out;
	}
}
