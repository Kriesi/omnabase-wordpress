<?php
/**
 * Settings › OmnaBase: the message-centre section (the page pick); the shortcodes have their own section. Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{picked:int,pages:array<int,array{id:int,title:string}>} $omnabase_portal
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ob-wp-settings__portal">
	<h2><?php esc_html_e( 'Message centre', 'omnabase' ); ?></h2>
	<p class="description"><?php esc_html_e( 'The page where signed-in customers read and answer their tickets; it is never cached.', 'omnabase' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="omnabase_save_portal">
		<?php wp_nonce_field( 'omnabase_save_portal' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="omnabase-portal-page"><?php esc_html_e( 'Message centre page', 'omnabase' ); ?></label></th>
				<td>
					<select id="omnabase-portal-page" name="omnabase_portal[page]">
						<option value="0"><?php esc_html_e( '— No page —', 'omnabase' ); ?></option>
						<?php foreach ( $omnabase_portal['pages'] as $omnabase_page ) : ?>
							<option value="<?php echo esc_attr( (string) $omnabase_page['id'] ); ?>" <?php selected( $omnabase_page['id'], $omnabase_portal['picked'] ); ?>><?php echo esc_html( $omnabase_page['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Put the shortcode [omnabase_messages] on this page.', 'omnabase' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save page', 'omnabase' ), 'secondary', 'omnabase-save-portal', false ); ?>
	</form>
</div>
