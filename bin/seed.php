<?php
/**
 * The local WordPress's fixtures (brief, "Fixtures"). Run inside wp-env by bin/seed.sh (`wp eval-file`); safe to run again: whatever
 * exists already is kept. Paid add-ons are not installed (WooCommerce Subscriptions, EDD Software Licensing, EDD Recurring): their parts
 * are skipped with a line saying so, and the rounds that need them fake the functions the plugin calls.
 *
 * Seeded logins (test values for this local site only; every seeded user's password is `test-password`, the administrator stays
 * wp-env's `admin` / `password`):
 *   ada        ada@example.com            customer: a WooCommerce order, an EDD purchase (two addresses on her EDD customer), a licence key
 *   ben        ben@example.com            customer: the topic in "Support"
 *   mike       mike@example.test          bbPress moderator who is a member of Team Kriesi in OmnaBase's seed (same address)
 *   nina       nina@example.com           bbPress moderator who is not an OmnaBase member
 *   support    support@wp.omnabase.test   the support user who posts OmnaBase's replies in the forum (round three)
 *
 * @package OmnaBase
 */

// phpcs:ignoreFile -- a WP-CLI script for the local wp-env only; never shipped (bin/ is not in the zip).

const OMNABASE_SEED_PASSWORD = 'test-password';
const OMNABASE_SEED_META     = '_omnabase_seed';

/** One line of the seed's report. */
function omnabase_seed_say( string $line ): void {
	WP_CLI::log( $line );
}

/** Pretty permalinks: the REST API at /wp-json/ and the site's receiving addresses need them. */
function omnabase_seed_permalinks(): void {
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	flush_rewrite_rules( false );
	update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
	omnabase_seed_say( 'Permalinks: /%postname%/' );
}

/**
 * The seeded users, by login.
 *
 * @return array<string,int>
 */
function omnabase_seed_users(): array {
	$people = array(
		'ada'     => array( 'ada@example.com', 'Ada Lovelace', 'customer' ),
		'ben'     => array( 'ben@example.com', 'Ben Weber', 'customer' ),
		'mike'    => array( 'mike@example.test', 'Mike Moderator', 'subscriber' ),
		'nina'    => array( 'nina@example.com', 'Nina Moderator', 'subscriber' ),
		'support' => array( 'support@wp.omnabase.test', 'Support', 'subscriber' ),
	);
	$ids    = array();
	foreach ( $people as $login => [ $email, $name, $role ] ) {
		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			$role  = get_role( $role ) ? $role : 'subscriber'; // `customer` exists only with WooCommerce
			$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'display_name' => $name, 'first_name' => strtok( $name, ' ' ), 'user_pass' => OMNABASE_SEED_PASSWORD, 'role' => $role ) );
			$user  = get_user_by( 'id', $id );
			omnabase_seed_say( "User: {$login} <{$email}> made" );
		} else {
			omnabase_seed_say( "User: {$login} <{$email}> exists" );
		}
		$ids[ $login ] = (int) $user->ID;
	}
	return $ids;
}

/**
 * WooCommerce: a product, Ada's completed order and a guest's.
 *
 * @param array<string,int> $users The seeded users.
 * @return int|null The product's id.
 */
function omnabase_seed_woocommerce( array $users ): ?int {
	if ( ! function_exists( 'wc_create_order' ) ) {
		omnabase_seed_say( 'WooCommerce: not active, skipped' );
		return null;
	}
	$found = wc_get_products( array( 'limit' => 1, 'meta_key' => OMNABASE_SEED_META, 'meta_value' => 'product', 'return' => 'ids' ) );
	if ( $found ) {
		$product_id = (int) $found[0];
	} else {
		$product = new WC_Product_Simple();
		$product->set_name( 'Enfold Theme' );
		$product->set_regular_price( '59.00' );
		$product->set_virtual( true );
		$product->update_meta_data( OMNABASE_SEED_META, 'product' );
		$product_id = $product->save();
	}
	$orders = array(
		'ada-order'   => array( 'customer_id' => $users['ada'], 'email' => 'ada@example.com', 'first' => 'Ada', 'last' => 'Lovelace' ),
		'guest-order' => array( 'customer_id' => 0, 'email' => 'guest@example.com', 'first' => 'Grace', 'last' => 'Guest' ),
	);
	foreach ( $orders as $tag => $o ) {
		if ( wc_get_orders( array( 'limit' => 1, 'meta_key' => OMNABASE_SEED_META, 'meta_value' => $tag, 'return' => 'ids' ) ) ) {
			omnabase_seed_say( "WooCommerce: {$tag} exists" );
			continue;
		}
		$order = wc_create_order( array( 'customer_id' => $o['customer_id'] ) );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_address( array( 'first_name' => $o['first'], 'last_name' => $o['last'], 'email' => $o['email'], 'country' => 'AT' ), 'billing' );
		$order->update_meta_data( OMNABASE_SEED_META, $tag );
		$order->calculate_totals();
		$order->update_status( 'completed', 'OmnaBase seed' );
		omnabase_seed_say( "WooCommerce: {$tag} #{$order->get_order_number()} for {$o['email']} made" );
	}
	return $product_id;
}

/**
 * License Manager for WooCommerce: one licence key on Ada's order.
 *
 * @param array<string,int> $users      The seeded users.
 * @param int|null          $product_id The WooCommerce product.
 */
function omnabase_seed_license_manager( array $users, ?int $product_id ): void {
	if ( ! function_exists( 'lmfwc_add_license' ) || null === $product_id ) {
		omnabase_seed_say( 'License Manager for WooCommerce: not active (or no WooCommerce product), skipped' );
		return;
	}
	$key = 'OMNA-SEED-0000-7Q2X'; // a made-up licence key for this local site
	if ( function_exists( 'lmfwc_get_license' ) && lmfwc_get_license( $key ) ) {
		omnabase_seed_say( 'License Manager for WooCommerce: Ada\'s licence exists' );
		return;
	}
	$order_ids = wc_get_orders( array( 'limit' => 1, 'meta_key' => OMNABASE_SEED_META, 'meta_value' => 'ada-order', 'return' => 'ids' ) );
	$made      = lmfwc_add_license( $key, array( 'order_id' => $order_ids[0] ?? null, 'product_id' => $product_id, 'user_id' => $users['ada'], 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ), 'times_activated_max' => 3, 'status' => 2 /* delivered */ ) );
	omnabase_seed_say( $made ? 'License Manager for WooCommerce: Ada\'s licence made' : 'License Manager for WooCommerce: the licence could not be made (its API changed?)' );
}

/**
 * Easy Digital Downloads: a download, Ada as an EDD customer with two addresses, her completed order.
 *
 * @param array<string,int> $users The seeded users.
 */
function omnabase_seed_edd( array $users ): void {
	if ( ! function_exists( 'edd_add_order' ) ) {
		omnabase_seed_say( 'Easy Digital Downloads: not active (or older than 3.0), skipped' );
		return;
	}
	$found = get_posts( array( 'post_type' => 'download', 'meta_key' => OMNABASE_SEED_META, 'meta_value' => 'download', 'fields' => 'ids', 'numberposts' => 1 ) );
	$download_id = $found ? (int) $found[0] : (int) wp_insert_post( array( 'post_type' => 'download', 'post_status' => 'publish', 'post_title' => 'Omnalingo Pro', 'meta_input' => array( 'edd_price' => '39.00', OMNABASE_SEED_META => 'download' ) ) );

	$customer = edd_get_customer_by( 'email', 'ada@example.com' );
	$customer_id = $customer ? (int) $customer->id : (int) edd_add_customer( array( 'email' => 'ada@example.com', 'name' => 'Ada Lovelace', 'user_id' => $users['ada'] ) );
	if ( ! edd_get_customer_by( 'email', 'ada.work@example.com' ) ) {
		edd_add_customer_email_address( array( 'customer_id' => $customer_id, 'email' => 'ada.work@example.com', 'type' => 'secondary' ) );
	}
	if ( edd_get_orders( array( 'customer_id' => $customer_id, 'number' => 1 ) ) ) {
		omnabase_seed_say( 'Easy Digital Downloads: Ada\'s order exists' );
	} else {
		$order_id = edd_add_order( array( 'user_id' => $users['ada'], 'customer_id' => $customer_id, 'email' => 'ada@example.com', 'status' => 'complete', 'type' => 'sale', 'gateway' => 'manual', 'currency' => 'EUR', 'subtotal' => 39, 'total' => 39, 'date_completed' => gmdate( 'Y-m-d H:i:s' ) ) );
		edd_add_order_item( array( 'order_id' => $order_id, 'product_id' => $download_id, 'product_name' => 'Omnalingo Pro', 'status' => 'complete', 'quantity' => 1, 'amount' => 39, 'subtotal' => 39, 'total' => 39 ) );
		omnabase_seed_say( "Easy Digital Downloads: Ada's order #{$order_id} made (customer {$customer_id}, addresses ada@ and ada.work@example.com)" );
	}
	if ( ! class_exists( 'EDD_Software_Licensing' ) ) {
		omnabase_seed_say( 'EDD Software Licensing: not installed (a paid add-on), skipped; Ada\'s EDD licence is faked in round two (drop the zip into paid/ to use the real one)' );
	}
	if ( ! class_exists( 'EDD_Recurring' ) ) {
		omnabase_seed_say( 'EDD Recurring: not installed (a paid add-on), skipped' );
	}
	if ( ! class_exists( 'WC_Subscriptions' ) ) {
		omnabase_seed_say( 'WooCommerce Subscriptions: not installed (a paid add-on), skipped' );
	}
}

/**
 * bbPress: the moderators, the support user's role, three forums and Ben's topic.
 *
 * @param array<string,int> $users The seeded users.
 */
function omnabase_seed_bbpress( array $users ): void {
	if ( ! function_exists( 'bbp_insert_forum' ) ) {
		omnabase_seed_say( 'bbPress: not active, skipped' );
		return;
	}
	bbp_set_user_role( $users['mike'], bbp_get_moderator_role() );
	bbp_set_user_role( $users['nina'], bbp_get_moderator_role() );
	bbp_set_user_role( $users['support'], bbp_get_moderator_role() );
	foreach ( array( 'ada', 'ben' ) as $login ) {
		bbp_set_user_role( $users[ $login ], bbp_get_participant_role() );
	}
	$forums = array( 'Support' => 'public', 'Members' => 'private', 'Staff' => 'hidden' );
	$ids    = array();
	foreach ( $forums as $title => $visibility ) {
		$found = get_posts( array( 'post_type' => bbp_get_forum_post_type(), 'title' => $title, 'post_status' => array( bbp_get_public_status_id(), bbp_get_private_status_id(), bbp_get_hidden_status_id() ), 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'numberposts' => 1 ) );
		if ( $found ) {
			$ids[ $title ] = (int) $found[0];
			omnabase_seed_say( "bbPress: forum {$title} ({$visibility}) exists" );
			continue;
		}
		$ids[ $title ] = (int) bbp_insert_forum( array( 'post_title' => $title, 'post_content' => "The {$title} forum." ) );
		if ( 'private' === $visibility ) {
			bbp_privatize_forum( $ids[ $title ] );
		} elseif ( 'hidden' === $visibility ) {
			bbp_hide_forum( $ids[ $title ] );
		}
		omnabase_seed_say( "bbPress: forum {$title} ({$visibility}) made" );
	}
	$topic = get_posts( array( 'post_type' => bbp_get_topic_post_type(), 'meta_key' => OMNABASE_SEED_META, 'meta_value' => 'ben-topic', 'fields' => 'ids', 'numberposts' => 1 ) );
	if ( $topic ) {
		omnabase_seed_say( 'bbPress: Ben\'s topic exists' );
		return;
	}
	$topic_id = bbp_insert_topic(
		array( 'post_parent' => $ids['Support'], 'post_author' => $users['ben'], 'post_title' => 'The language switcher disappears on mobile', 'post_content' => 'Since the last update the language switcher is gone on phones. Any idea?' ),
		array( 'forum_id' => $ids['Support'] )
	);
	update_post_meta( $topic_id, OMNABASE_SEED_META, 'ben-topic' );
	omnabase_seed_say( "bbPress: Ben's topic #{$topic_id} in Support made" );
}

wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) ); // as the administrator: private and hidden forums are found
add_filter( 'pre_wp_mail', '__return_true' ); // wp-env has no mail server: the shops' order mails are dropped, never attempted
omnabase_seed_permalinks();
$omnabase_seed_users = omnabase_seed_users();
$omnabase_seed_product = omnabase_seed_woocommerce( $omnabase_seed_users );
omnabase_seed_license_manager( $omnabase_seed_users, $omnabase_seed_product );
omnabase_seed_edd( $omnabase_seed_users );
omnabase_seed_bbpress( $omnabase_seed_users );
WP_CLI::success( 'Seed done.' );
