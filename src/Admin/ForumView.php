<?php
/**
 * The settings screen's forum section: a tick per forum, the support user, the closing line, guests, closing on resolve.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forum\Posts;
use OmnaBase\WordPress\Forum\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every forum of the site (public, private and hidden ones, at most 200), each with its visibility; the support user picker offers only
 * users with bbPress's moderator rights. Without bbPress the section says so and offers nothing.
 */
final class ForumView {

	private const MAX_FORUMS     = 200;
	private const MAX_MODERATORS = 200;

	/**
	 * Builds the section.
	 *
	 * @param Settings $settings The forum settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * What the template shows.
	 *
	 * @return array{active:bool,forums:array<int,array{id:int,title:string,visibility:string,public:bool,marked:bool}>,moderators:array<int,array{id:int,name:string}>,supportUser:int,closingLine:string,guests:bool,closeResolved:bool}
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
		);
	}

	/** Every forum with its visibility and tick. @return array<int,array{id:int,title:string,visibility:string,public:bool,marked:bool}> */
	private function forums(): array {
		$ids = get_posts(
			array(
				'post_type'        => bbp_get_forum_post_type(),
				'post_status'      => array( bbp_get_public_status_id(), bbp_get_private_status_id(), bbp_get_hidden_status_id() ),
				'orderby'          => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
				'fields'           => 'ids',
				'numberposts'      => self::MAX_FORUMS,
				'suppress_filters' => true,
			)
		);
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
