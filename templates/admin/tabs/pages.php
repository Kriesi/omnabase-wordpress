<?php
/**
 * The Forms and messages tab: one save form holding the message centre's page and the Shortcodes block. Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{portal:array<string,mixed>,shortcodes:array<string,mixed>} $view
 */

defined( 'ABSPATH' ) || exit;

$omnabase_portal     = $view['portal'];
$omnabase_shortcodes = $view['shortcodes'];
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-wp-settings__pages">
	<input type="hidden" name="action" value="omnabase_save_pages">
	<?php wp_nonce_field( 'omnabase_save_pages' ); ?>
	<?php
	require dirname( __DIR__ ) . '/partials/portal.php';
	require dirname( __DIR__ ) . '/partials/shortcodes.php';
	submit_button( __( 'Save', 'omnabase' ), 'primary', 'submit', true );
	?>
</form>
