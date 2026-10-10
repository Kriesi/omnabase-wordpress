<?php
/**
 * The Connection tab's one status line, from the last "Check connection".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One plain sentence instead of the three-row table: working both ways, one direction broken, still waiting, or not checked yet; with when
 * the check ran ("checked 2 minutes ago"). The rows themselves wait behind "Details".
 */
final class StatusLine {

	/**
	 * The line for a health view.
	 *
	 * @param array{checked:bool,at:int,results:array<string,array{result:string,message:string}>,waiting:bool} $health What `Health::view()` gives.
	 * @return array{tone:string,words:string} `tone` is `ok`, `failed`, `waiting` or `none`.
	 */
	public static function of( array $health ): array {
		if ( ! $health['checked'] ) {
			return array( 'tone' => 'none', 'words' => __( 'Not checked yet: Check connection tries both directions, this site to OmnaBase and back.', 'omnabase' ) );
		}
		if ( $health['waiting'] ) {
			return array( 'tone' => 'waiting', 'words' => __( 'Checking: waiting for OmnaBase to call this site…', 'omnabase' ) );
		}
		/* translators: %s: how long ago, such as "2 minutes". */
		$ago = sprintf( __( 'checked %s ago', 'omnabase' ), human_time_diff( $health['at'], time() ) );
		if ( 'ok' !== ( $health['results']['me']['result'] ?? '' ) ) {
			return array( 'tone' => 'failed', 'words' => self::join( __( 'This site cannot reach OmnaBase', 'omnabase' ), $ago ) );
		}
		if ( 'ok' !== ( $health['results']['ping']['result'] ?? '' ) ) {
			return array( 'tone' => 'failed', 'words' => self::join( __( 'OmnaBase cannot reach this site', 'omnabase' ), $ago ) );
		}
		return array( 'tone' => 'ok', 'words' => self::join( __( 'Working both ways', 'omnabase' ), $ago ) );
	}

	/**
	 * "What · when".
	 *
	 * @param string $what The state.
	 * @param string $when When it was checked.
	 */
	private static function join( string $what, string $when ): string {
		/* translators: 1: the connection's state, 2: when it was checked ("checked 2 minutes ago"). */
		return sprintf( __( '%1$s · %2$s', 'omnabase' ), $what, $when );
	}
}
