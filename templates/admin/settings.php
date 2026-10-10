<?php
/**
 * Settings › OmnaBase: the screen's frame in OmnaBase's settings look (the page head, the kit's line tabs, the development notices, the last
 * action's message) and the open tab's cards. Rendered by SettingsScreen::render() with `$view`; each tab's template reads its own sections
 * from it. Every rule of `assets/admin.css` lives under the wrapper's class `ob-wp-settings`.
 *
 * @package OmnaBase
 *
 * @var array{version:string,apiUrl:?string,appUrl:?string,notice:?array{type:string,message:string,link:?array{url:string,label:string}},tab:string,tabs:array<int,array{slug:string,label:string,url:string,active:bool,count:int}>} $view
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap ob-wp-settings">
	<div class="ob-page__head ob-wp-settings__head">
		<div>
			<h1 class="ob-page__title"><?php esc_html_e( 'OmnaBase', 'omnabase' ); ?></h1>
			<p class="ob-page__subtitle"><?php esc_html_e( 'Connects this site to your OmnaBase workspace: customer data for tickets, forum topics, the contact form and the message centre.', 'omnabase' ); ?></p>
		</div>
	</div>
	<hr class="wp-header-end">

	<nav class="ob-tabs ob-tabs--line ob-wp-settings__tabs" aria-label="<?php esc_attr_e( 'OmnaBase settings', 'omnabase' ); ?>">
		<?php foreach ( $view['tabs'] as $omnabase_tab ) : ?>
			<a href="<?php echo esc_url( $omnabase_tab['url'] ); ?>" class="ob-tabs__item<?php echo $omnabase_tab['active'] ? ' ob-tabs__item--active' : ''; ?>"<?php echo $omnabase_tab['active'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $omnabase_tab['label'] ); ?><?php if ( $omnabase_tab['count'] > 0 ) : ?><span class="ob-tabs__count"><?php echo esc_html( (string) $omnabase_tab['count'] ); ?></span><?php endif; ?></a>
		<?php endforeach; ?>
	</nav>

	<?php require __DIR__ . '/partials/notices.php'; ?>

	<div class="ob-settings__cards ob-wp-settings__cards ob-wp-settings__cards--<?php echo esc_attr( $view['tab'] ); ?>">
		<?php require __DIR__ . '/tabs/' . $view['tab'] . '.php'; ?>
	</div>

	<p class="ob-wp-settings__version">
		<?php
		/* translators: %s: the plugin's version. */
		echo esc_html( sprintf( __( 'OmnaBase for WordPress %s', 'omnabase' ), $view['version'] ) );
		?>
	</p>
</div>
