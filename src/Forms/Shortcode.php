<?php
/**
 * The `[omnabase_form]` shortcode.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\PluginInfo;

defined( 'ABSPATH' ) || exit;

/**
 * `[omnabase_form project="<slug>" subject="…"]` shows name, email, subject and message, the project's product list and licence field when
 * its settings ask for them, and the privacy link (the project's, else the site's privacy page, else none). A signed-in user finds their
 * name and address filled in. The page holds no token and nothing secret: the form's script fetches its token when the page loads, so two
 * views are the same HTML and a page cache may keep it. When the form cannot tell its project, the administrator reads a notice and
 * everyone else nothing. Projects, settings and the script's assets each come from their own class. A real render announces itself
 * (`FirstShown::EVENT`), so the first one on the public site is noted.
 */
final class Shortcode {

	public const TAG = 'omnabase_form';

	/**
	 * Builds the shortcode.
	 *
	 * @param PluginInfo $info     The plugin's folder and version.
	 * @param Projects   $projects Which project.
	 * @param Settings   $settings The project's form settings.
	 * @param Display    $display  The "theme styles only" switch.
	 */
	public function __construct( private PluginInfo $info, private Projects $projects, private Settings $settings, private Display $display ) {}

	/** Registers the shortcode. */
	public function register(): void {
		add_shortcode( 'omnabase_form', array( $this, 'render' ) );
	}

	/**
	 * The shortcode's output.
	 *
	 * @param array<string,string>|string $atts The attributes as written.
	 */
	public function render( $atts ): string {
		$atts    = shortcode_atts( array( 'project' => '', 'subject' => '' ), is_array( $atts ) ? $atts : array(), self::TAG );
		$project = $this->projects->resolve( trim( (string) $atts['project'] ) );
		if ( '' !== $project['problem'] ) {
			return $this->notice( $project['problem'], trim( (string) $atts['project'] ) );
		}
		$this->enqueue();
		do_action( 'omnabase_shown', 'form' ); // FirstShown::EVENT
		return $this->view( $this->model( $project['slug'], (string) $atts['subject'] ) );
	}

	/**
	 * What the template shows.
	 *
	 * @param string $slug    The project.
	 * @param string $subject The pre-worded subject.
	 * @return array<string,mixed>
	 */
	private function model( string $slug, string $subject ): array {
		$settings = $this->settings->of( $slug );
		$user     = wp_get_current_user();
		$signed   = $user->exists();
		$privacy  = '' !== $settings['privacyUrl'] ? $settings['privacyUrl'] : (string) get_privacy_policy_url();
		return array(
			'project'    => $slug,
			'subject'    => $subject,
			'name'       => $signed ? (string) $user->display_name : '',
			'email'      => $signed ? (string) $user->user_email : '',
			'products'   => $settings['products'],
			'askLicense' => $settings['askLicense'],
			'privacyUrl' => $privacy,
			'tokenUrl'   => rest_url( 'omnabase/v1/form-token' ),
			'submitUrl'  => rest_url( 'omnabase/v1/form' ),
			// WordPress's token for signed-in REST calls, only for a signed-in user (a visitor's page stays the same for everyone).
			'nonce'      => $signed ? wp_create_nonce( 'wp_rest' ) : '',
			'honeypot'   => Guard::HONEYPOT,
		);
	}

	/**
	 * The template's output.
	 *
	 * @param array<string,mixed> $omnabase_form What it shows.
	 */
	private function view( array $omnabase_form ): string {
		ob_start();
		include $this->info->dir() . 'templates/form.php';
		return (string) ob_get_clean();
	}

	/** Queues the form's script and stylesheet (printed in the footer; the stylesheet stays away with "theme styles only" or a filter). */
	private function enqueue(): void {
		$version = $this->info->version();
		wp_enqueue_script( 'omnabase-form', plugins_url( 'assets/form.js', $this->info->mainFile() ), array(), $version, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		if ( $this->display->wantsStylesheet() ) {
			wp_enqueue_style( 'omnabase-form', plugins_url( 'assets/form.css', $this->info->mainFile() ), array(), $version );
		}
	}

	/**
	 * The administrator's notice when the form cannot tell its project; nothing for anyone else.
	 *
	 * @param string $problem   Which problem.
	 * @param string $attribute The project the shortcode named.
	 */
	private function notice( string $problem, string $attribute ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$slugs = implode( ', ', $this->projects->slugs() );
		$words = match ( $problem ) {
			/* translators: 1: the project the shortcode named, 2: the site's projects. */
			Projects::UNKNOWN   => sprintf( __( 'OmnaBase form: the project "%1$s" is not one of this site\'s projects (%2$s).', 'omnabase' ), $attribute, $slugs ),
			/* translators: %s: the site's projects. */
			Projects::AMBIGUOUS => sprintf( __( 'OmnaBase form: this site has several projects (%s); name one, as in [omnabase_form project="slug"].', 'omnabase' ), $slugs ),
			default             => __( 'OmnaBase form: this site is not connected, so the form has no project yet. Connect it under Settings › OmnaBase.', 'omnabase' ),
		};
		return '<p class="ob-wp ob-wp-form-notice">' . esc_html( $words . ' ' . __( 'Only administrators see this note.', 'omnabase' ) ) . '</p>';
	}
}
