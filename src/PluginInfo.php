<?php
/**
 * The plugin's header fields, read from omnabase.php.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the header block the way WordPress's get_file_data() does (the first 8 KB, one `Name: value` per line), without needing
 * WordPress loaded, so tests and the build read the same values WordPress shows on the Plugins screen.
 */
final class PluginInfo {

	/**
	 * The header fields WordPress reads, by our names.
	 */
	public const FIELDS = array(
		'name'         => 'Plugin Name',
		'version'      => 'Version',
		'requiresWp'   => 'Requires at least',
		'requiresPhp'  => 'Requires PHP',
		'textDomain'   => 'Text Domain',
		'domainPath'   => 'Domain Path',
		'license'      => 'License',
		'author'       => 'Author',
		'description'  => 'Description',
	);

	/**
	 * Builds the info from already-read fields.
	 *
	 * @param string               $main_file The absolute path of omnabase.php.
	 * @param array<string,string> $fields    The header fields by our names.
	 */
	public function __construct( private string $main_file, private array $fields ) {}

	/**
	 * Reads the header of a plugin file.
	 *
	 * @param string $main_file The absolute path of the plugin's main file.
	 */
	public static function fromFile( string $main_file ): self {
		$head = (string) file_get_contents( $main_file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file, read before WordPress's filesystem API exists.
		return new self( $main_file, self::parseHeader( $head ) );
	}

	/**
	 * Parses `Name: value` header lines; a field missing from the text is an empty string.
	 *
	 * @param string $text The start of the file.
	 * @return array<string,string>
	 */
	public static function parseHeader( string $text ): array {
		$text   = str_replace( "\r", "\n", $text );
		$fields = array();
		foreach ( self::FIELDS as $key => $label ) {
			$found          = preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $text, $match );
			$fields[ $key ] = $found ? trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) ) : '';
		}
		return $fields;
	}

	/** The main file's absolute path. */
	public function mainFile(): string {
		return $this->main_file;
	}

	/** The plugin's folder, with a trailing slash. */
	public function dir(): string {
		return dirname( $this->main_file ) . '/';
	}

	/** The `Version` header. */
	public function version(): string {
		return $this->fields['version'] ?? '';
	}

	/** The `Requires PHP` header. */
	public function requiresPhp(): string {
		return $this->fields['requiresPhp'] ?? '';
	}

	/** The `Requires at least` header (the WordPress version). */
	public function requiresWp(): string {
		return $this->fields['requiresWp'] ?? '';
	}

	/** The `Text Domain` header. */
	public function textDomain(): string {
		return $this->fields['textDomain'] ?? '';
	}

	/**
	 * Any header field by our name (see FIELDS).
	 *
	 * @param string $key Our name of the field.
	 */
	public function field( string $key ): string {
		return $this->fields[ $key ] ?? '';
	}
}
