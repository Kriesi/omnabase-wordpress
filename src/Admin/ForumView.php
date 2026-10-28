<?php
/**
 * The settings screen's forum section: a tick per forum, the support user, the closing line, guests, closing on resolve.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forum\PostFields;
use OmnaBase\WordPress\Forum\Posts;
use OmnaBase\WordPress\Forum\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every forum of the site (public, private and hidden ones, at most 200), each with its visibility; the support user picker offers only
 * users with bbPress's moderator rights. Without bbPress the section says so and offers nothing. The site-fields round: the Post fields rows
 * (the saved ones plus one empty row) and the auto-complete's meta keys.
 */
final class ForumView {

	private const MAX_FORUMS     = 200;
	private const MAX_MODERATORS = 200;

	/**
	 * Builds the section.
	 *
	 * @param Settings   $settings The forum settings.
	 * @param PostFields $fields   The declared post fields (the auto-complete's keys).
	 */
	public function __construct( private Settings $settings, private PostFields $fields ) {}

	/**
	 * What the template shows.
	 *
	 * @return array{active:bool,forums:array<int,array{id:int,title:string,visibility:string,public:bool,marked:bool}>,moderators:array<int,array{id:int,name:string}>,supportUser:int,closingLine:string,guests:bool,closeResolved:bool,fieldRows:array<int,array{key:string,label:string}>,metaKeys:string[],fieldsMax:int,labelMax:int}
	 */
	public function build(): array {
		$active = function_exists( 'bbp_get_forum_post_type' );
		return array(
			'active'        => $active,
			'forums'        => $active ? $this->forums() : array(),
			'moderators'    => $active ? $this->moderators() : array(),
			'supportUser'   => $this->settings->supportUser(),
			'closingLine'   => $this->settings->closingLine(),
			'guests'        => $this->settings->guests(),
			'closeResolved' => $this->settings->closeResolved(),
			'fieldRows'     => $this->fieldRows(),
			'metaKeys'      => $active ? $this->fields->metaKeys() : array(),
			'fieldsMax'     => PostFields::MAX,
			'labelMax'      => PostFields::LABEL_MAX,
		);
	}

	/** The saved post fields as rows, plus one empty row while fewer than ten are saved. @return array<int,array{key:string,label:string}> */
	private function fieldRows(): array {
		$rows = array();
		foreach ( $this->settings->fields() as $key => $label ) {
			$rows[] = array( 'key' => (string) $key, 'label' => (string) $label );
		}
		if ( count( $rows ) < PostFields::MAX ) {
			$rows[] = array( 'key' => '', 'label' => '' );
		}
		return $rows;
	}

	/** Every forum with its visibility and tick. @return array<int,array{id:int,title:string,visibility:string,public:bool,marked:bool}> */
	private function forums(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => bbp_get_forum_post_type(),
				'post_status'    => array( bbp_get_public_status_id(), bbp_get_private_status_id(), bbp_get_hidden_status_id() ),
				'orderby'        => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_FORUMS,
				'no_found_rows'  => true,
			)
		);
		$ids   = $query->posts;
		$out = array();
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$out[] = array(
				'id'         => $id,
				'title'      => get_the_title( $id ),
				'visibility' => (string) bbp_get_forum_visibility( $id ),
				'public'     => Posts::isPublicForum( $id ),
				'marked'     => $this->settings->isMarked( $id ),
			);
		}
		return $out;
	}

	/** The users with moderator rights, by name. @return array<int,array{id:int,name:string}> */
	private function moderators(): array {
		$users = get_users(
			array(
				'role__in' => array( bbp_get_keymaster_role(), bbp_get_moderator_role() ),
				'orderby'  => 'display_name',
				'number'   => self::MAX_MODERATORS,
			)
		);
		$out = array();
		foreach ( $users as $user ) {
			if ( Settings::canModerate( (int) $user->ID ) ) {
				$out[] = array( 'id' => (int) $user->ID, 'name' => (string) $user->display_name );
			}
		}
		return $out;
	}
}
