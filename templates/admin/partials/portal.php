<?php
/**
 * Settings › OmnaBase: the message-centre section (the page pick), inside the Forms and messages tab's one form.
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
</div>
