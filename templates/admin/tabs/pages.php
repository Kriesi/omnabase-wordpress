<?php
/**
 * The Forms and messages tab: one save form holding the message centre's card and the Shortcodes card, then the shortcode builder's dialog
 * (outside the save form: it has its own form). Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{portal:array<string,mixed>,shortcodes:array<string,mixed>,builder:array<string,mixed>} $view
 */

defined( 'ABSPATH' ) || exit;

$omnabase_portal     = $view['portal'];
$omnabase_shortcodes = $view['shortcodes'];
$omnabase_builder    = $view['builder'];
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-wp-settings__pages ob-wp-settings__stack">
	<input type="hidden" name="action" value="omnabase_save_pages">
	<?php wp_nonce_field( 'omnabase_save_pages' ); ?>
	<?php
	require dirname( __DIR__ ) . '/partials/portal.php';
	require dirname( __DIR__ ) . '/partials/shortcodes.php';
	?>
	<p class="ob-wp-settings__actions"><button type="submit" class="ob-btn ob-btn--primary"><?php esc_html_e( 'Save', 'omnabase' ); ?></button></p>
</form>
<?php
require dirname( __DIR__ ) . '/partials/builder.php';
