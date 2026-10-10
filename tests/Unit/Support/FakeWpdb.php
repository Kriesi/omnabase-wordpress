<?php
/**
 * Fixing round "sites in a subfolder" (the red team): the fake network's `$wpdb` (see FakeNetwork).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit\Support;

/**
 * The fake `$wpdb`: the current site's prefix and options table, prepared statements filled in, and the two kinds of query uninstall runs
 * (dropping a table; deleting options by a LIKE pattern) applied to the fake network.
 */
final class FakeWpdb {

	public string $prefix      = 'wp_';
	public string $base_prefix = 'wp_';
	public string $options     = 'wp_options';
	public string $usermeta    = 'wp_usermeta';
	public string $postmeta    = 'wp_postmeta';
	public int $blogid         = 1;

	/** @var string[] every query run */
	public array $queries = array();

	public function __construct( private FakeNetwork $network ) {
		$this->follow();
	}

	/** Follows the network's current site. */
	public function follow(): void {
		$this->blogid   = $this->network->blog;
		$this->prefix   = $this->network->prefix( $this->network->blog );
		$this->options  = $this->prefix . 'options';
		$this->postmeta = $this->prefix . 'postmeta';
	}

	/** A site's prefix (WordPress's own method). */
	public function get_blog_prefix( $blog = null ): string {
		return $this->network->prefix( null === $blog ? $this->network->blog : (int) $blog );
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$i    = 0;
		return preg_replace_callback(
			'/%[sdfi]/',
			static function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ] ?? '';
				return match ( $m[0] ) {
					'%d'    => (string) (int) $value,
					'%f'    => (string) (float) $value,
					'%i'    => '`' . $value . '`',
					default => "'" . addslashes( (string) $value ) . "'",
				};
			},
			(string) $query
		);
	}

	public function query( $sql ) {
		$sql             = (string) $sql;
		$this->queries[] = $sql;
		if ( preg_match( '/DROP TABLE IF EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m ) ) {
			unset( $this->network->tables[ $m[1] ] );
			return true;
		}
		if ( preg_match( "/DELETE FROM\s+`?([A-Za-z0-9_]+)`?\s+WHERE\s+option_name\s+LIKE\s+'((?:[^'\\\\]|\\\\.)*)'/i", $sql, $m ) ) {
			$blog = $this->blogOfTable( $m[1], 'options' );
			$rx   = self::likeToRegex( stripslashes( $m[2] ) );
			$n    = 0;
			foreach ( array_keys( $this->network->options[ $blog ] ?? array() ) as $name ) {
				if ( preg_match( $rx, $name ) ) {
					unset( $this->network->options[ $blog ][ $name ] );
					++$n;
				}
			}
			return $n;
		}
		return 0;
	}

	public function get_var( $sql = null ) {
		$this->queries[] = (string) $sql;
		return null;
	}

	public function get_results( $sql = null, $output = null ) {
		$this->queries[] = (string) $sql;
		return array();
	}

	public function get_col( $sql = null ) {
		$this->queries[] = (string) $sql;
		return array();
	}

	/** A LIKE pattern as a regular expression: `%` any run, `_` one character, a backslash makes the next character literal. */
	private static function likeToRegex( string $like ): string {
		$rx  = '';
		$len = strlen( $like );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $like[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$rx .= preg_quote( $like[ ++$i ], '/' );
			} elseif ( '%' === $c ) {
				$rx .= '.*';
			} elseif ( '_' === $c ) {
				$rx .= '.';
			} else {
				$rx .= preg_quote( $c, '/' );
			}
		}
		return '/^' . $rx . '$/s';
	}

	/** Which site a table name belongs to (`wp_options` → 1, `wp_2_options` → 2). */
	private function blogOfTable( string $table, string $suffix ): int {
		foreach ( $this->network->sites as $blog ) {
			if ( $this->network->prefix( $blog ) . $suffix === $table ) {
				return $blog;
			}
		}
		return $this->network->blog;
	}
}
