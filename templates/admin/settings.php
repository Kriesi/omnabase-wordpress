<?php
/**
 * Settings › OmnaBase: the screen's frame (the title, the development notices, the last action's message, the tab row) and the open tab.
 * Rendered by SettingsScreen::render() with `$view`; each tab's template reads its own sections from it.
 *
 * @package OmnaBase
 *
 * @var array{version:string,apiUrl:?string,appUrl:?string,notice:?array{type:string,message:string},tab:string,tabs:array<int,array{slug:string,label:string,url:string,active:bool}>} $view
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap ob-wp-settings">
	<h1><?php esc_html_e( 'OmnaBase', 'omnabase' ); ?></h1>

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
			<p><?php echo esc_html( $view['notice']['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper ob-wp-settings__tabs" aria-label="<?php esc_attr_e( 'OmnaBase settings', 'omnabase' ); ?>">
		<?php foreach ( $view['tabs'] as $omnabase_tab ) : ?>
			<a href="<?php echo esc_url( $omnabase_tab['url'] ); ?>" class="nav-tab<?php echo $omnabase_tab['active'] ? ' nav-tab-active' : ''; ?>"<?php echo $omnabase_tab['active'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $omnabase_tab['label'] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php require __DIR__ . '/tabs/' . $view['tab'] . '.php'; ?>

	<p class="description ob-wp-settings__version">
		<?php
		/* translators: %s: the plugin's version. */
		echo esc_html( sprintf( __( 'OmnaBase for WordPress %s', 'omnabase' ), $view['version'] ) );
		?>
	</p>
</div>
