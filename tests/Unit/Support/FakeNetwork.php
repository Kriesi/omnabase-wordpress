<?php
/**
 * Fixing round "sites in a subfolder" (the red team): a small fake WordPress network for the unit layer, so the multisite rules can be
 * checked without a multisite WordPress (wp-env's tests WordPress is a single site).
 *
 * A *network* (WordPress multisite) is several sites sharing one WordPress: each site has its own options, posts, scheduled jobs and table
 * prefix (`wp_` for the main site, `wp_2_`, `wp_3_` … for the others), while the users and their user meta are shared by all. The fake keeps
 * exactly that split: options, post meta, scheduled jobs and tables per site; user meta once for the whole network. `switch_to_blog()` and
 * `restore_current_blog()` move between sites the way WordPress does (a stack), and `$wpdb->prefix` follows. `get_sites()` answers like
 * WordPress's: at most 100 sites unless `number` says otherwise (`0`: all), from `offset`, ids with `fields => ids`, else site objects with
 * `blog_id`.
 *
 * User options are WordPress's per-site user meta: `update_user_option( $user, $name, $value )` stores `<site prefix><name>` (`wp_2_…`),
 * `get_user_option()` reads the site's key first, then the bare name, as WordPress does. The fake implements both, so the builder may keep a
 * per-site user mark either way (a user option, or user meta under a key that carries the site).
 *
 * Calls to OmnaBase (`wp_remote_request`) are recorded with the site they were made from and answered 204.
 *
 * Fixing round "the vault's key file" (2026-10-11), the builder: the vault now keeps its key in a file under wp-content, one per network, so
 * the fake gets a small file system too: wp-content is the unit layer's stand-in folder (`WP_CONTENT_DIR`, emptied of the key folder at
 * every install so each test starts without a key file), the uploads folder beside it, and the few WordPress file helpers the key file
 * calls. `traces()` now also reports a key folder left behind, so "nothing of the plugin is left" covers the key file as well.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit\Support;

use Brain\Monkey\Functions;

final class FakeNetwork {

	/** @var int the current site */
	public int $blog = 1;

	/** @var int[] the switch stack */
	public array $stack = array();

	/** @var int[] the network's sites */
	public array $sites = array( 1 );

	/** @var bool whether WordPress runs as a network */
	public bool $multisite = true;

	/** @var array<int,array<string,mixed>> options per site */
	public array $options = array();

	/** @var array<int,array<string,mixed>> user meta, shared by every site: user id => key => value */
	public array $usermeta = array();

	/** @var array<int,array<int,array<string,mixed>>> post meta per site: site => post => key => value */
	public array $postmeta = array();

	/** @var array<int,array<string,bool>> scheduled jobs per site: site => hook => true */
	public array $cron = array();

	/** @var array<string,bool> the tables that exist, by full name */
	public array $tables = array();

	/** @var array<int,array{blog:int,method:string,url:string,key:string}> calls made to OmnaBase */
	public array $requests = array();

	/** @var int how often `get_sites()` was asked */
	public int $sitesAsked = 0;

	public FakeWpdb $wpdb;

	/**
	 * A network of the given sites (the first is the main site and the current one).
	 *
	 * @param int[] $sites     The sites' ids.
	 * @param bool  $multisite Whether WordPress runs as a network.
	 */
	public function __construct( array $sites = array( 1, 2, 3 ), bool $multisite = true ) {
		self::defineConstants();
		$this->sites     = array_values( $sites );
		$this->multisite = $multisite;
		$this->blog      = $this->sites[0];
		$this->wpdb      = new FakeWpdb( $this );
	}

	/** WordPress's time and query-result constants, which the plugin's classes use. */
	public static function defineConstants(): void {
		foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800, 'YEAR_IN_SECONDS' => 31536000, 'ARRAY_A' => 'ARRAY_A', 'OBJECT' => 'OBJECT' ) as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
	}

	/** A site's table prefix, as WordPress makes it. */
	public function prefix( int $blog ): string {
		return ( ! $this->multisite || 1 === $blog ) ? 'wp_' : 'wp_' . $blog . '_';
	}

	/** Runs a callable on a site and comes back (without touching WordPress's own stack, for the test's arranging and reading). */
	public function on( int $blog, callable $fn ) {
		$was        = $this->blog;
		$this->blog = $blog;
		$this->wpdb->follow();
		try {
			return $fn();
		} finally {
			$this->blog = $was;
			$this->wpdb->follow();
		}
	}

	/** The plugin's traces anywhere: option names, user meta keys, post meta keys, tables and jobs that hold "omnabase". */
	public function traces(): array {
		$found = array();
		foreach ( $this->options as $blog => $options ) {
			foreach ( array_keys( $options ) as $name ) {
				if ( str_contains( $name, 'omnabase' ) ) {
					$found[] = "site {$blog} option {$name}";
				}
			}
		}
		foreach ( $this->usermeta as $user => $meta ) {
			foreach ( array_keys( $meta ) as $key ) {
				if ( str_contains( (string) $key, 'omnabase' ) ) {
					$found[] = "user {$user} meta {$key}";
				}
			}
		}
		foreach ( $this->postmeta as $blog => $posts ) {
			foreach ( $posts as $post => $meta ) {
				foreach ( array_keys( $meta ) as $key ) {
					if ( str_contains( (string) $key, 'omnabase' ) ) {
						$found[] = "site {$blog} post {$post} meta {$key}";
					}
				}
			}
		}
		foreach ( $this->cron as $blog => $hooks ) {
			foreach ( array_keys( $hooks ) as $hook ) {
				if ( str_contains( $hook, 'omnabase' ) ) {
					$found[] = "site {$blog} job {$hook}";
				}
			}
		}
		foreach ( array_keys( $this->tables ) as $table ) {
			if ( str_contains( $table, 'omnabase' ) ) {
				$found[] = "table {$table}";
			}
		}
		foreach ( array( WP_CONTENT_DIR . '/omnabase', WP_CONTENT_DIR . '/uploads/omnabase' ) as $folder ) {
			if ( file_exists( $folder ) ) {
				$found[] = "folder {$folder}";
			}
		}
		return $found;
	}

	/** Registers the fake WordPress functions (brain/monkey must be set up). */
	public function install(): void {
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->ensureUpgradeFile();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		$this->installSites();
		$this->installOptions();
		$this->installUserMeta();
		$this->installPostMeta();
		$this->installCron();
		$this->installHttp();
		$this->installFiles();
		Functions\when( 'wp_salt' )->justReturn( 'fake-network-salt' );
		Functions\when( 'wp_generate_password' )->alias( static fn ( $length = 12 ) => substr( str_repeat( bin2hex( random_bytes( 32 ) ), 2 ), 0, (int) $length ) );
		Functions\when( 'dbDelta' )->alias(
			function ( $sql ) {
				if ( preg_match( '/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', (string) $sql, $m ) ) {
					$this->tables[ $m[1] ] = true;
				}
				return array();
			}
		);
	}

	/** Table creation requires WordPress's upgrade functions from ABSPATH (the unit layer's stand-in folder): an empty stand-in. */
	private function ensureUpgradeFile(): void {
		$file = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! is_file( $file ) ) {
			@mkdir( dirname( $file ), 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a test's stand-in folder.
			file_put_contents( $file, "<?php\n// The unit layer's stand-in for WordPress's upgrade functions (dbDelta is faked by the test).\n" );
		}
	}

	private function installSites(): void {
		Functions\when( 'is_multisite' )->alias( fn () => $this->multisite );
		Functions\when( 'get_current_blog_id' )->alias( fn () => $this->blog );
		Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) {
				$this->stack[] = $this->blog;
				$this->blog    = (int) $id;
				$this->wpdb->follow();
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function () {
				if ( array() === $this->stack ) {
					return false;
				}
				$this->blog = (int) array_pop( $this->stack );
				$this->wpdb->follow();
				return true;
			}
		);
		Functions\when( 'get_sites' )->alias(
			function ( $args = array() ) {
				++$this->sitesAsked;
				$args   = is_array( $args ) ? $args : array();
				$number = array_key_exists( 'number', $args ) ? absint( $args['number'] ) : 100;
				$offset = absint( $args['offset'] ?? 0 );
				$ids    = array_slice( $this->sites, $offset, 0 === $number ? null : $number );
				if ( 'ids' === ( $args['fields'] ?? '' ) ) {
					return $ids;
				}
				return array_map( static fn ( int $id ) => (object) array( 'blog_id' => (string) $id, 'id' => $id ), $ids );
			}
		);
	}

	private function installOptions(): void {
		Functions\when( 'get_option' )->alias( fn ( $name, $default = false ) => array_key_exists( $name, $this->options[ $this->blog ] ?? array() ) ? $this->options[ $this->blog ][ $name ] : $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $this->blog ][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				if ( array_key_exists( $name, $this->options[ $this->blog ] ?? array() ) ) {
					return false;
				}
				$this->options[ $this->blog ][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				$had = array_key_exists( $name, $this->options[ $this->blog ] ?? array() );
				unset( $this->options[ $this->blog ][ $name ] );
				return $had;
			}
		);
		Functions\when( 'get_blog_option' )->alias( fn ( $blog, $name, $default = false ) => $this->on( (int) ( $blog ?: $this->blog ), static fn () => get_option( $name, $default ) ) );
	}

	private function installUserMeta(): void {
		Functions\when( 'get_userdata' )->alias( static fn ( $id ) => $id > 0 ? (object) array( 'ID' => (int) $id ) : false );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user, $key = '', $single = false ) {
				$meta = $this->usermeta[ (int) $user ] ?? array();
				if ( '' === $key ) {
					return array_map( static fn ( $v ) => array( $v ), $meta );
				}
				if ( ! array_key_exists( $key, $meta ) ) {
					return $single ? '' : array();
				}
				return $single ? $meta[ $key ] : array( $meta[ $key ] );
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user, $key, $value, $prev = '' ) {
				$this->usermeta[ (int) $user ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'add_user_meta' )->alias(
			function ( $user, $key, $value, $unique = false ) {
				if ( $unique && array_key_exists( $key, $this->usermeta[ (int) $user ] ?? array() ) ) {
					return false;
				}
				$this->usermeta[ (int) $user ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( $user, $key, $value = '' ) {
				$had = array_key_exists( $key, $this->usermeta[ (int) $user ] ?? array() );
				unset( $this->usermeta[ (int) $user ][ $key ] );
				return $had;
			}
		);
		Functions\when( 'get_user_option' )->alias(
			function ( $name, $user = 0 ) {
				$meta = $this->usermeta[ (int) $user ] ?? array();
				$site = $this->prefix( $this->blog ) . $name;
				if ( array_key_exists( $site, $meta ) ) {
					return $meta[ $site ];
				}
				return array_key_exists( $name, $meta ) ? $meta[ $name ] : false;
			}
		);
		Functions\when( 'update_user_option' )->alias(
			function ( $user, $name, $value, $global = false ) {
				$this->usermeta[ (int) $user ][ ( $global ? '' : $this->prefix( $this->blog ) ) . $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_option' )->alias(
			function ( $user, $name, $global = false ) {
				$key = ( $global ? '' : $this->prefix( $this->blog ) ) . $name;
				$had = array_key_exists( $key, $this->usermeta[ (int) $user ] ?? array() );
				unset( $this->usermeta[ (int) $user ][ $key ] );
				return $had;
			}
		);
		Functions\when( 'delete_metadata' )->alias(
			function ( $type, $id, $key, $value = '', $all = false ) {
				if ( 'user' === $type ) {
					foreach ( array_keys( $this->usermeta ) as $user ) {
						if ( $all || (int) $id === $user ) {
							unset( $this->usermeta[ $user ][ $key ] );
						}
					}
				} elseif ( 'post' === $type ) {
					foreach ( array_keys( $this->postmeta[ $this->blog ] ?? array() ) as $post ) {
						if ( $all || (int) $id === $post ) {
							unset( $this->postmeta[ $this->blog ][ $post ][ $key ] );
						}
					}
				}
				return true;
			}
		);
	}

	private function installPostMeta(): void {
		Functions\when( 'delete_post_meta_by_key' )->alias(
			function ( $key ) {
				foreach ( array_keys( $this->postmeta[ $this->blog ] ?? array() ) as $post ) {
					unset( $this->postmeta[ $this->blog ][ $post ][ $key ] );
				}
				return true;
			}
		);
	}

	private function installCron(): void {
		$clear = function ( $hook ) {
			$had = isset( $this->cron[ $this->blog ][ $hook ] );
			unset( $this->cron[ $this->blog ][ $hook ] );
			return $had ? 1 : 0;
		};
		Functions\when( 'wp_clear_scheduled_hook' )->alias( $clear );
		Functions\when( 'wp_unschedule_hook' )->alias( $clear );
		Functions\when( 'wp_next_scheduled' )->alias( fn ( $hook ) => isset( $this->cron[ $this->blog ][ $hook ] ) ? time() + 60 : false );
	}

	/** The stand-in wp-content (without a key folder) and the WordPress file helpers the vault's key file calls. */
	private function installFiles(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
		}
		@mkdir( WP_CONTENT_DIR . '/uploads', 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a test's stand-in folder.
		foreach ( array( WP_CONTENT_DIR . '/omnabase', WP_CONTENT_DIR . '/uploads/omnabase' ) as $folder ) {
			foreach ( glob( $folder . '/{,.}*', GLOB_BRACE ) ?: array() as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a test's stand-in folder.
		}
		Functions\when( 'get_main_site_id' )->justReturn( $this->sites[0] );
		Functions\when( 'wp_upload_dir' )->alias( fn () => array( 'basedir' => WP_CONTENT_DIR . '/uploads' . ( $this->multisite && $this->blog !== $this->sites[0] ? '/sites/' . $this->blog : '' ), 'error' => false ) );
		Functions\when( 'wp_mkdir_p' )->alias( static fn ( $dir ) => is_dir( $dir ) || @mkdir( $dir, 0755, true ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		Functions\when( 'wp_is_writable' )->alias( static fn ( $path ) => is_writable( $path ) );
		Functions\when( 'wp_delete_file' )->alias( static fn ( $path ) => is_file( $path ) && unlink( $path ) );
		Functions\when( 'untrailingslashit' )->alias( static fn ( $s ) => rtrim( (string) $s, '/\\' ) );
	}

	private function installHttp(): void {
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args = array() ) {
				$auth             = (string) ( $args['headers']['Authorization'] ?? '' );
				$this->requests[] = array( 'blog' => $this->blog, 'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ), 'url' => (string) $url, 'key' => (string) preg_replace( '/^Bearer\s+/', '', $auth ) );
				return array( 'response' => array( 'code' => 204 ), 'body' => '', 'headers' => array() );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $r ) => is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '' );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn ( $r ) => is_array( $r ) ? (array) ( $r['headers'] ?? array() ) : array() );
	}
}
