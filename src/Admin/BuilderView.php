<?php
/**
 * What the shortcode builder's dialog shows.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Forms\Projects;
use OmnaBase\WordPress\Portal\PickedPage;

defined( 'ABSPATH' ) || exit;

/**
 * The dialog's facts: the key's projects (only when it has several, which only a hand-made key can), the picked message centre page's
 * title, a published page per kind that already carries the tag and the person may edit, the tag before any click, and whether the person
 * may publish pages (else "Create the page for me" is left out).
 */
final class BuilderView {

	/**
	 * Builds the view.
	 *
	 * @param Projects       $projects   The key's project slugs.
	 * @param ConnectionInfo $connection The projects' names.
	 * @param PickedPage     $picked     The message centre's page.
	 * @param TaggedPages    $pages      Pages that carry a tag already.
	 */
	public function __construct( private Projects $projects, private ConnectionInfo $connection, private PickedPage $picked, private TaggedPages $pages ) {}

	/**
	 * What the template shows.
	 *
	 * @return array{projects:array<int,array{slug:string,name:string}>,picked:string,offers:array<string,?array{id:int,title:string,url:string}>,tag:string,canPublish:bool}
	 */
	public function build(): array {
		$picked = $this->picked->id();
		return array(
			'projects'   => $this->projects(),
			'picked'     => $picked > 0 && get_post( $picked ) ? get_the_title( $picked ) : '',
			'offers'     => array(
				ShortcodeTag::FORM     => $this->pages->first( ShortcodeTag::nameOf( ShortcodeTag::FORM ) ),
				ShortcodeTag::MESSAGES => $this->pages->first( ShortcodeTag::nameOf( ShortcodeTag::MESSAGES ), $picked ),
			),
			'tag'        => ShortcodeTag::build( array( 'kind' => ShortcodeTag::FORM, 'project' => '', 'subject' => '', 'guest' => '' ) ),
			'canPublish' => current_user_can( 'publish_pages' ),
		);
	}

	/**
	 * The projects to choose from: none for a key with one project.
	 *
	 * @return array<int,array{slug:string,name:string}>
	 */
	private function projects(): array {
		if ( count( $this->projects->slugs() ) < 2 ) {
			return array();
		}
		$named = array_filter( ConnectionInfo::projects( $this->connection->read()['projects'] ?? array() ), static fn ( array $p ) => '' !== $p['slug'] );
		return array_values( array_map( static fn ( array $p ) => array( 'slug' => $p['slug'], 'name' => $p['name'] ), $named ) );
	}
}
