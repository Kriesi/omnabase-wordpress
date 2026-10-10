<?php
/**
 * The screen's WordPress notices, under the tab strip and above the cards: the development addresses (when a constant points the site at a
 * development OmnaBase) and the last action's message. They keep WordPress's own notice look. Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{apiUrl:?string,appUrl:?string,notice:?array{type:string,message:string,link:?array{url:string,label:string}}} $view
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( null !== $view['apiUrl'] ) : ?>
	<div class="notice notice-warning inline ob-wp-settings__dev">
		<p>
			<?php
			/* translators: %s: the development address of OmnaBase. */
			echo esc_html( sprintf( __( 'Development: this site talks to %s instead of app.omnabase.com.', 'omnabase' ), $view['apiUrl'] ) );
			?>
		</p>
	</div>
<?php endif; ?>
<?php if ( null !== $view['appUrl'] ) : ?>
	<div class="notice notice-warning inline ob-wp-settings__dev">
		<p>
			<?php
			/* translators: %s: the development address of OmnaBase's connect page. */
			echo esc_html( sprintf( __( 'Development: Connect opens %s instead of app.omnabase.com.', 'omnabase' ), $view['appUrl'] ) );
			?>
		</p>
	</div>
<?php endif; ?>
<?php if ( null !== $view['notice'] ) : ?>
	<div class="notice notice-<?php echo esc_attr( $view['notice']['type'] ); ?> inline ob-wp-settings__notice">
		<p>
			<?php echo esc_html( $view['notice']['message'] ); ?>
			<?php if ( null !== $view['notice']['link'] ) : ?>
				<a href="<?php echo esc_url( $view['notice']['link']['url'] ); ?>"><?php echo esc_html( $view['notice']['link']['label'] ); ?></a>
			<?php endif; ?>
		</p>
	</div>
<?php endif; ?>
