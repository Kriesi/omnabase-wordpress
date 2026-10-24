<?php
/**
 * The `[omnabase_form]` shortcode.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Look\FrontStyles;
use OmnaBase\WordPress\PluginInfo;

defined( 'ABSPATH' ) || exit;

/**
 * `[omnabase_form id="<form slug>" project="<slug>" subject="…"]` shows a form built in OmnaBase (Settings › Forms there), drawn on the
 * page by OmnaBase's embed: the plugin prints the container inside the look's wrapper and queues the embed's script once. Without `id`, the
 * project's default form. The project is the shortcode's, else the key's single project; it places the messages. `subject` pre-words the
 * form's field keyed "subject" (the person may change it). A signed-in visitor's container carries OmnaBase's prefill token, so the details
 * the site vouches for arrive verified; a visitor's page is the same for everyone. When the form cannot be shown, the administrator reads a
 * note and everyone else nothing. A real render announces itself (`FirstShown::EVENT`), so the first one on the public site is noted.
 */
final class Shortcode {

	public const TAG = 'omnabase_form';

	/**
	 * Builds the shortcode.
	 *
	 * @param PluginInfo     $info       The plugin's folder.
	 * @param Projects       $projects   Which project.
	 * @param FormPick       $pick       Which form.
	 * @param PrefillToken   $prefill    The signed-in visitor's token.
	 * @param FrontStyles    $styles     The stylesheets and the wrapper's marks for the chosen look.
	 * @param ConnectionInfo $connection The workspace's slug.
	 */
	public function __construct( private PluginInfo $info, private Projects $projects, private FormPick $pick, private PrefillToken $prefill, private FrontStyles $styles, private ConnectionInfo $connection ) {}

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
		$atts    = shortcode_atts( array( 'id' => '', 'project' => '', 'subject' => '' ), is_array( $atts ) ? $atts : array(), self::TAG );
		$named   = trim( (string) $atts['project'] );
		$project = $this->projects->resolve( $named );
		if ( '' !== $project['problem'] ) {
			return Notice::project( $project['problem'], $named, $this->projects->slugs() );
		}
		$id   = strtolower( trim( (string) $atts['id'] ) );
		$form = $this->pick->pick( $id, $project['slug'] );
		if ( '' !== $form['problem'] ) {
			return Notice::form( $form['problem'], $id, $project['slug'] );
		}
		( new EmbedScript() )->enqueue();
		$this->styles->enqueue();
		do_action( 'omnabase_shown', 'form' ); // FirstShown::EVENT
		return $this->view( $this->model( $form['slug'], $project['slug'], (string) $atts['subject'] ) );
	}

	/**
	 * What the template shows.
	 *
	 * @param string $form    The form's slug.
	 * @param string $project The project's slug.
	 * @param string $subject The pre-worded subject.
	 * @return array{wrapper:array<string,string>,form:string,project:string,subject:string,token:string,privacyUrl:string}
	 */
	private function model( string $form, string $project, string $subject ): array {
		return array(
			'wrapper'    => $this->styles->marks(),
			'form'       => $this->connection->workspaceSlug() . '/' . $form,
			'project'    => $project,
			'subject'    => sanitize_text_field( $subject ),
			'token'      => $this->prefill->forCurrentVisitor( $form, $project ),
			'privacyUrl' => (string) get_privacy_policy_url(),
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
}
