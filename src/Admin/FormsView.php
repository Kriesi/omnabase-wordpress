<?php
/**
 * The Forms and messages tab's Forms section: the workspace's forms built in OmnaBase, with their shortcodes.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Api\ApiUrl;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Forms\FormList;
use OmnaBase\WordPress\Forms\Projects;

defined( 'ABSPATH' ) || exit;

/**
 * Forms are built in OmnaBase (Settings › Forms there); this section lists the workspace's published forms, each with the shortcode to
 * paste, and links to OmnaBase's Settings › Forms of the workspace. The list is asked afresh on every view of the tab (one call, only for an
 * administrator, kept for the shortcode's next views). Not connected: nothing to list and no workspace to link to. OmnaBase unreachable:
 * the tab still opens, with the link.
 */
final class FormsView {

	/**
	 * Builds the section.
	 *
	 * @param FormList       $list       The workspace's forms.
	 * @param Projects       $projects   Whether the site is connected.
	 * @param ConnectionInfo $connection The workspace's slug.
	 */
	public function __construct( private FormList $list, private Projects $projects, private ConnectionInfo $connection ) {}

	/**
	 * What the template shows.
	 *
	 * @return array{connected:bool,reachable:bool,forms:array<int,array{name:string,tag:string,default:bool}>,link:string}
	 */
	public function build(): array {
		if ( ! $this->projects->connected() ) {
			return array( 'connected' => false, 'reachable' => false, 'forms' => array(), 'link' => '' );
		}
		$forms = $this->list->fresh();
		return array(
			'connected' => true,
			'reachable' => null !== $forms,
			'forms'     => array_map( array( self::class, 'row' ), $forms ?? array() ),
			'link'      => ApiUrl::app() . '/' . rawurlencode( $this->connection->workspaceSlug() ) . '/settings/forms',
		);
	}

	/**
	 * One form's row: its name, its shortcode and whether it is a project's default form.
	 *
	 * @param array{slug:string,name:string,defaultFor:string[]} $form The form.
	 * @return array{name:string,tag:string,default:bool}
	 */
	private static function row( array $form ): array {
		$tag = ShortcodeTag::build( array( 'kind' => ShortcodeTag::FORM, 'form' => $form['slug'], 'project' => '', 'subject' => '', 'guest' => '' ) );
		return array( 'name' => $form['name'], 'tag' => $tag, 'default' => array() !== $form['defaultFor'] );
	}
}
