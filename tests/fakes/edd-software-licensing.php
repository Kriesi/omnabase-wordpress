<?php
/**
 * A fake of EDD Software Licensing (a paid add-on) for the tests, never shipped: the few names the plugin reads, shaped like the real
 * add-on's public API. Inert when the real add-on is loaded (the founder's zip in `paid/`).
 *
 * The real names it stands in for: the main class `EDD_Software_Licensing` (detection) and `edd_software_licensing()`; its
 * `licenses_db->get_licenses( $args )` (`user_id`, `customer_id`, `number`, `orderby`, `order`) and `get_license( $id_or_key )`; a licence
 * (`EDD_SL_License`) with its public `ID`, `key`, `status` ("active", "inactive", "expired", "disabled"), `expiration` (a unix time, 0 when
 * lifetime), `is_lifetime`, `download_id`, `user_id`, `customer_id`, `sites` (the activated sites' addresses), `get_download()` (an
 * `EDD_SL_Download` with `get_name()` and `get_version()`, the version from the download's `_edd_sl_version` meta, as the real add-on keeps
 * it) and `get_name()`; the actions `edd_sl_post_set_status( $license_id, $new_status )` and `edd_sl_activate_license( $license_id,
 * $download_id )`.
 *
 * Its records live in the option `omnabase_fake_edd_sl` (a list of rows: id, user_id, customer_id, download_id, key, status, expiration,
 * sites).
 *
 * @package OmnaBase
 */

// phpcs:ignoreFile -- a test fake named after the real add-on's classes and functions.

if ( class_exists( 'EDD_Software_Licensing' ) ) {
	return;
}

final class EDD_SL_Download {
	public function __construct( private int $id ) {}

	public function get_ID(): int {
		return $this->id;
	}

	public function get_name(): string {
		return (string) get_the_title( $this->id );
	}

	public function get_version(): string {
		return (string) get_post_meta( $this->id, '_edd_sl_version', true );
	}
}

final class EDD_SL_License {
	public $ID          = 0;
	public $id          = 0;
	public $key         = '';
	public $license_key = '';
	public $status      = 'inactive';
	public $expiration  = 0;
	public $is_lifetime = false;
	public $download_id = 0;
	public $user_id     = 0;
	public $customer_id = 0;
	public $sites       = array();

	public function __construct( array $row ) {
		$this->ID          = $this->id = (int) ( $row['id'] ?? 0 );
		$this->key         = $this->license_key = (string) ( $row['key'] ?? '' );
		$this->status      = (string) ( $row['status'] ?? 'inactive' );
		$this->expiration  = (int) ( $row['expiration'] ?? 0 );
		$this->is_lifetime = 0 === $this->expiration;
		$this->download_id = (int) ( $row['download_id'] ?? 0 );
		$this->user_id     = (int) ( $row['user_id'] ?? 0 );
		$this->customer_id = (int) ( $row['customer_id'] ?? 0 );
		$this->sites       = array_values( (array) ( $row['sites'] ?? array() ) );
	}

	public function get_download(): EDD_SL_Download {
		return new EDD_SL_Download( $this->download_id );
	}

	public function get_name(): string {
		return $this->get_download()->get_name();
	}
}

final class EDD_SL_License_DB {
	/** @return EDD_SL_License[] */
	public function get_licenses( $args = array() ) {
		$rows = array_values(
			array_filter(
				(array) get_option( 'omnabase_fake_edd_sl', array() ),
				static function ( $row ) use ( $args ) {
					foreach ( array( 'user_id', 'customer_id', 'download_id' ) as $field ) {
						if ( isset( $args[ $field ] ) && (int) ( $row[ $field ] ?? 0 ) !== (int) $args[ $field ] ) {
							return false;
						}
					}
					return true;
				}
			)
		);
		usort( $rows, static fn ( $a, $b ) => ( 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 1 : -1 ) * ( (int) $a['id'] <=> (int) $b['id'] ) );
		$number = (int) ( $args['number'] ?? 20 );
		return array_map( static fn ( $row ) => new EDD_SL_License( $row ), $number > 0 ? array_slice( $rows, 0, $number ) : $rows );
	}
}

final class EDD_Software_Licensing {
	private static ?self $instance = null;
	public EDD_SL_License_DB $licenses_db;

	private function __construct() {
		$this->licenses_db = new EDD_SL_License_DB();
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** @return EDD_SL_License|false */
	public function get_license( $id_or_key, $by_key = false ) {
		foreach ( (array) get_option( 'omnabase_fake_edd_sl', array() ) as $row ) {
			if ( ( $by_key && (string) $row['key'] === (string) $id_or_key ) || ( ! $by_key && (int) $row['id'] === (int) $id_or_key ) ) {
				return new EDD_SL_License( $row );
			}
		}
		return false;
	}
}

function edd_software_licensing() {
	return EDD_Software_Licensing::instance();
}
