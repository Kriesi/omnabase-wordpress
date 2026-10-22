<?php
/**
 * The Appearance tab (2026-10-22): one card in one save form with the front end's look (the theme, the accent, the font) and "Theme
 * styles only". The radio groups are labelled by their row's label (`role="radiogroup"`). Included by settings.php.
 *
 * @package OmnaBase
 *
 * @var array{appearance:array{theme:string,own:bool,colour:string,font:string,themeOnly:bool}} $view
 */

defined( 'ABSPATH' ) || exit;

$omnabase_look   = $view['appearance'];
$omnabase_choice = static function ( string $key, string $value, string $words, bool $picked ): void {
	?>
	<label class="ob-wp-settings__choice"><input type="radio" name="<?php echo esc_attr( 'omnabase_appearance[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $picked ); ?>> <?php echo esc_html( $words ); ?></label>
	<?php
};
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-wp-settings__stack">
	<input type="hidden" name="action" value="omnabase_save_appearance">
	<?php wp_nonce_field( 'omnabase_save_appearance' ); ?>
	<section class="ob-form__section ob-wp-settings__appearance">
		<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Look on your site', 'omnabase' ); ?></h2></div>
		<div class="ob-form__intro"><?php esc_html_e( 'How the contact form and the message centre look to visitors; a cached page shows a change once its cache is cleared.', 'omnabase' ); ?></div>

		<div class="ob-form-row">
			<div class="ob-form-row__text">
				<span class="ob-form-row__label" id="omnabase-appearance-theme"><?php esc_html_e( 'Theme', 'omnabase' ); ?></span>
				<div class="ob-form-row__help"><?php esc_html_e( 'The visitor\'s system means light or dark as the visitor\'s own device is set.', 'omnabase' ); ?></div>
			</div>
			<div class="ob-form-row__control ob-wp-settings__choices" role="radiogroup" aria-labelledby="omnabase-appearance-theme">
				<?php
				$omnabase_choice( 'theme', 'light', __( 'Light', 'omnabase' ), 'light' === $omnabase_look['theme'] );
				$omnabase_choice( 'theme', 'dark', __( 'Dark', 'omnabase' ), 'dark' === $omnabase_look['theme'] );
				$omnabase_choice( 'theme', 'system', __( 'Follow the visitor\'s system', 'omnabase' ), 'system' === $omnabase_look['theme'] );
				?>
			</div>
		</div>

		<div class="ob-form-row">
			<div class="ob-form-row__text">
				<span class="ob-form-row__label" id="omnabase-appearance-accent"><?php esc_html_e( 'Accent', 'omnabase' ); ?></span>
				<div class="ob-form-row__help"><?php esc_html_e( 'The colour of buttons, focus rings and the success card\'s check; the words on it turn white or near-black, whichever reads better.', 'omnabase' ); ?></div>
			</div>
			<div class="ob-form-row__control ob-wp-settings__choices" role="radiogroup" aria-labelledby="omnabase-appearance-accent">
				<?php $omnabase_choice( 'accent', 'blue', __( 'OmnaBase\'s blue', 'omnabase' ), ! $omnabase_look['own'] ); ?>
				<span class="ob-wp-settings__choice-line">
					<?php $omnabase_choice( 'accent', 'own', __( 'Your own colour', 'omnabase' ), $omnabase_look['own'] ); ?>
					<input type="color" class="ob-wp-settings__colour" name="omnabase_appearance[colour]" value="<?php echo esc_attr( $omnabase_look['colour'] ); ?>" aria-label="<?php esc_attr_e( 'Your colour', 'omnabase' ); ?>">
				</span>
			</div>
		</div>

		<div class="ob-form-row">
			<div class="ob-form-row__text">
				<span class="ob-form-row__label" id="omnabase-appearance-font"><?php esc_html_e( 'Font', 'omnabase' ); ?></span>
				<div class="ob-form-row__help"><?php esc_html_e( 'OmnaBase\'s font comes from this site\'s own files and loads only when chosen.', 'omnabase' ); ?></div>
			</div>
			<div class="ob-form-row__control ob-wp-settings__choices" role="radiogroup" aria-labelledby="omnabase-appearance-font">
				<?php
				$omnabase_choice( 'font', 'inherit', __( 'Inherit the theme\'s font', 'omnabase' ), 'load' !== $omnabase_look['font'] );
				$omnabase_choice( 'font', 'load', __( 'Load OmnaBase\'s font', 'omnabase' ), 'load' === $omnabase_look['font'] );
				?>
			</div>
		</div>

		<div class="ob-form-row">
			<div class="ob-form-row__text">
				<label class="ob-form-row__label" for="omnabase-theme-only"><?php esc_html_e( 'Theme styles only', 'omnabase' ); ?></label>
				<div class="ob-form-row__help"><?php esc_html_e( 'The form and the message centre load no OmnaBase stylesheet or font: your theme styles them alone.', 'omnabase' ); ?></div>
			</div>
			<div class="ob-form-row__control">
				<input type="checkbox" role="switch" class="ob-switch" id="omnabase-theme-only" name="omnabase_appearance[theme_only]" value="1" <?php checked( $omnabase_look['themeOnly'] ); ?>>
			</div>
		</div>
	</section>
	<p class="ob-wp-settings__actions"><button type="submit" class="ob-btn ob-btn--primary"><?php esc_html_e( 'Save', 'omnabase' ); ?></button></p>
</form>
