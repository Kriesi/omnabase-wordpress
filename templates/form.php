<?php
/**
 * The shortcode form `[omnabase_form]`. Rendered by `Forms\Shortcode`; everything printed is escaped. The page never holds a token: the
 * form's script fetches one when the page loads and only then enables Send (without JavaScript the form says so and sends nothing).
 *
 * @package OmnaBase
 *
 * @var array{project:string,subject:string,name:string,email:string,products:string[],askLicense:bool,privacyUrl:string,tokenUrl:string,submitUrl:string,nonce:string,honeypot:string} $omnabase_form
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ob-wp ob-wp-form-wrap">
	<form class="ob-wp-form" method="post" action="" novalidate
		data-project="<?php echo esc_attr( $omnabase_form['project'] ); ?>"
		data-token-url="<?php echo esc_url( $omnabase_form['tokenUrl'] ); ?>"
		data-submit-url="<?php echo esc_url( $omnabase_form['submitUrl'] ); ?>"
		<?php if ( '' !== $omnabase_form['nonce'] ) : ?>
			data-nonce="<?php echo esc_attr( $omnabase_form['nonce'] ); ?>"
		<?php endif; ?>
	>
		<input type="hidden" name="project" value="<?php echo esc_attr( $omnabase_form['project'] ); ?>">
		<input type="hidden" name="token" value="">

		<p class="ob-wp-form__field">
			<label>
				<span class="ob-wp-form__label"><?php esc_html_e( 'Name', 'omnabase' ); ?></span>
				<input class="ob-wp-form__input" type="text" name="name" value="<?php echo esc_attr( $omnabase_form['name'] ); ?>" maxlength="120" autocomplete="name" required>
			</label>
			<span class="ob-wp-form__error" data-for="name" hidden></span>
		</p>
		<p class="ob-wp-form__field">
			<label>
				<span class="ob-wp-form__label"><?php esc_html_e( 'Email', 'omnabase' ); ?></span>
				<input class="ob-wp-form__input" type="email" name="email" value="<?php echo esc_attr( $omnabase_form['email'] ); ?>" maxlength="254" autocomplete="email" required>
			</label>
			<span class="ob-wp-form__error" data-for="email" hidden></span>
		</p>
		<?php if ( array() !== $omnabase_form['products'] ) : ?>
			<p class="ob-wp-form__field">
				<label>
					<span class="ob-wp-form__label"><?php esc_html_e( 'Product', 'omnabase' ); ?></span>
					<select class="ob-wp-form__input" name="product">
						<option value=""><?php esc_html_e( 'Choose a product (optional)', 'omnabase' ); ?></option>
						<?php foreach ( $omnabase_form['products'] as $omnabase_product ) : ?>
							<option value="<?php echo esc_attr( $omnabase_product ); ?>"><?php echo esc_html( $omnabase_product ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<span class="ob-wp-form__error" data-for="product" hidden></span>
			</p>
		<?php endif; ?>
		<?php if ( $omnabase_form['askLicense'] ) : ?>
			<p class="ob-wp-form__field">
				<label>
					<span class="ob-wp-form__label"><?php esc_html_e( 'Licence key (optional)', 'omnabase' ); ?></span>
					<input class="ob-wp-form__input" type="text" name="licence" value="" maxlength="200" autocomplete="off">
				</label>
				<span class="ob-wp-form__error" data-for="licence" hidden></span>
			</p>
		<?php endif; ?>
		<p class="ob-wp-form__field">
			<label>
				<span class="ob-wp-form__label"><?php esc_html_e( 'Subject', 'omnabase' ); ?></span>
				<input class="ob-wp-form__input" type="text" name="subject" value="<?php echo esc_attr( $omnabase_form['subject'] ); ?>" maxlength="200" required>
			</label>
			<span class="ob-wp-form__error" data-for="subject" hidden></span>
		</p>
		<p class="ob-wp-form__field">
			<label>
				<span class="ob-wp-form__label"><?php esc_html_e( 'Message', 'omnabase' ); ?></span>
				<textarea class="ob-wp-form__input ob-wp-form__message" name="message" rows="6" maxlength="20000" required></textarea>
			</label>
			<span class="ob-wp-form__error" data-for="message" hidden></span>
		</p>

		<div class="ob-wp-form__trap" aria-hidden="true">
			<label>
				<?php esc_html_e( 'Leave this field empty', 'omnabase' ); ?>
				<input type="text" name="<?php echo esc_attr( $omnabase_form['honeypot'] ); ?>" value="" tabindex="-1" autocomplete="off">
			</label>
		</div>

		<noscript>
			<p class="ob-wp-form__note"><?php esc_html_e( 'This form needs JavaScript to send. Please turn it on in your browser and reload the page.', 'omnabase' ); ?></p>
		</noscript>

		<p class="ob-wp-form__actions">
			<button class="ob-wp-form__submit" type="submit" disabled><?php esc_html_e( 'Send', 'omnabase' ); ?></button>
		</p>
		<p class="ob-wp-form__status" role="status" aria-live="polite"></p>

		<?php if ( '' !== $omnabase_form['privacyUrl'] ) : ?>
			<p class="ob-wp-form__privacy">
				<?php esc_html_e( 'We use your details only to answer your message.', 'omnabase' ); ?>
				<a href="<?php echo esc_url( $omnabase_form['privacyUrl'] ); ?>"><?php esc_html_e( 'Privacy policy', 'omnabase' ); ?></a>
			</p>
		<?php endif; ?>
	</form>
</div>
