<?php
/**
 * The shortcode form `[omnabase_form]`. Rendered by `Forms\Shortcode`; everything printed is escaped. The page never holds a token: the
 * form's script fetches one when the page loads and only then enables Send (without JavaScript the form says so and sends nothing).
 * The wrapper carries the chosen look's marks (`data-theme`, the owner's accent); the fields are the kit's (`ob-input`, `ob-textarea`,
 * `ob-select`, `ob-btn--primary`), each with its error line under it, named by the field's `aria-describedby`. The success card waits hidden
 * beside the form, its words translated here; the script fills in the reference and shows it in the form's place.
 *
 * @package OmnaBase
 *
 * @var array{id:string,wrapper:array<string,string>,project:string,subject:string,name:string,email:string,products:string[],askLicense:bool,privacyUrl:string,tokenUrl:string,submitUrl:string,nonce:string,honeypot:string} $omnabase_form
 */

defined( 'ABSPATH' ) || exit;

$omnabase_id    = $omnabase_form['id'];
$omnabase_error = static function ( string $name ) use ( $omnabase_id ): void {
	?>
	<span class="ob-wp-form__error" id="<?php echo esc_attr( $omnabase_id . '-' . $name . '-error' ); ?>" data-for="<?php echo esc_attr( $name ); ?>" hidden>
		<svg viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path d="M479.98-280q14.02 0 23.52-9.48t9.5-23.5q0-14.02-9.48-23.52t-23.5-9.5q-14.02 0-23.52 9.48t-9.5 23.5q0 14.02 9.48 23.52t23.5 9.5ZM453-433h60v-253h-60v253Zm27.27 353q-82.74 0-155.5-31.5Q252-143 197.5-197.5t-86-127.34Q80-397.68 80-480.5t31.5-155.66Q143-709 197.5-763t127.34-85.5Q397.68-880 480.5-880t155.66 31.5Q709-817 763-763t85.5 127Q880-563 880-480.27q0 82.74-31.5 155.5Q817-252 763-197.68q-54 54.31-127 86Q563-80 480.27-80Zm.23-60Q622-140 721-239.5t99-241Q820-622 721.19-721T480-820q-141 0-240.5 98.81T140-480q0 141 99.5 240.5t241 99.5Zm-.5-340Z"/></svg>
		<span class="ob-wp-form__error-text"></span>
	</span>
	<?php
};
/* translators: %d: the ticket's number (the success card shows it in the form's place). */
$omnabase_sent      = __( 'Thanks. Your reference is #%d', 'omnabase' );
$omnabase_described = static fn ( string $name ): string => $omnabase_id . '-' . $name . '-error';
?>
<div class="ob-wp ob-wp-form-wrap"<?php foreach ( $omnabase_form['wrapper'] as $omnabase_attr => $omnabase_value ) : ?> <?php echo esc_attr( $omnabase_attr ); ?>="<?php echo esc_attr( $omnabase_value ); ?>"<?php endforeach; ?>>
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

		<div class="ob-wp-form__field">
			<label class="ob-wp-form__label" for="<?php echo esc_attr( $omnabase_id . '-name' ); ?>"><?php esc_html_e( 'Name', 'omnabase' ); ?></label>
			<span class="ob-input ob-input--text"><input class="ob-input__field" id="<?php echo esc_attr( $omnabase_id . '-name' ); ?>" type="text" name="name" value="<?php echo esc_attr( $omnabase_form['name'] ); ?>" maxlength="120" autocomplete="name" required aria-describedby="<?php echo esc_attr( $omnabase_described( 'name' ) ); ?>"></span>
			<?php $omnabase_error( 'name' ); ?>
		</div>
		<div class="ob-wp-form__field">
			<label class="ob-wp-form__label" for="<?php echo esc_attr( $omnabase_id . '-email' ); ?>"><?php esc_html_e( 'Email', 'omnabase' ); ?></label>
			<span class="ob-input ob-input--text"><input class="ob-input__field" id="<?php echo esc_attr( $omnabase_id . '-email' ); ?>" type="email" name="email" value="<?php echo esc_attr( $omnabase_form['email'] ); ?>" maxlength="254" autocomplete="email" required aria-describedby="<?php echo esc_attr( $omnabase_described( 'email' ) ); ?>"></span>
			<?php $omnabase_error( 'email' ); ?>
		</div>
		<?php if ( array() !== $omnabase_form['products'] ) : ?>
			<div class="ob-wp-form__field">
				<label class="ob-wp-form__label" for="<?php echo esc_attr( $omnabase_id . '-product' ); ?>"><?php esc_html_e( 'Product', 'omnabase' ); ?></label>
				<select class="ob-select" id="<?php echo esc_attr( $omnabase_id . '-product' ); ?>" name="product" aria-describedby="<?php echo esc_attr( $omnabase_described( 'product' ) ); ?>">
					<option value=""><?php esc_html_e( 'Choose a product (optional)', 'omnabase' ); ?></option>
					<?php foreach ( $omnabase_form['products'] as $omnabase_product ) : ?>
						<option value="<?php echo esc_attr( $omnabase_product ); ?>"><?php echo esc_html( $omnabase_product ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php $omnabase_error( 'product' ); ?>
			</div>
		<?php endif; ?>
		<?php if ( $omnabase_form['askLicense'] ) : ?>
			<div class="ob-wp-form__field">
				<label class="ob-wp-form__label" for="<?php echo esc_attr( $omnabase_id . '-licence' ); ?>"><?php esc_html_e( 'Licence key (optional)', 'omnabase' ); ?></label>
				<span class="ob-input ob-input--text"><input class="ob-input__field" id="<?php echo esc_attr( $omnabase_id . '-licence' ); ?>" type="text" name="licence" value="" maxlength="200" autocomplete="off" aria-describedby="<?php echo esc_attr( $omnabase_described( 'licence' ) ); ?>"></span>
				<?php $omnabase_error( 'licence' ); ?>
			</div>
		<?php endif; ?>
		<div class="ob-wp-form__field">
			<label class="ob-wp-form__label" for="<?php echo esc_attr( $omnabase_id . '-subject' ); ?>"><?php esc_html_e( 'Subject', 'omnabase' ); ?></label>
			<span class="ob-input ob-input--text"><input class="ob-input__field" id="<?php echo esc_attr( $omnabase_id . '-subject' ); ?>" type="text" name="subject" value="<?php echo esc_attr( $omnabase_form['subject'] ); ?>" maxlength="200" required aria-describedby="<?php echo esc_attr( $omnabase_described( 'subject' ) ); ?>"></span>
			<?php $omnabase_error( 'subject' ); ?>
		</div>
		<div class="ob-wp-form__field">
			<label class="ob-wp-form__label" for="<?php echo esc_attr( $omnabase_id . '-message' ); ?>"><?php esc_html_e( 'Message', 'omnabase' ); ?></label>
			<textarea class="ob-textarea" id="<?php echo esc_attr( $omnabase_id . '-message' ); ?>" name="message" rows="6" maxlength="20000" required aria-describedby="<?php echo esc_attr( $omnabase_described( 'message' ) ); ?>"></textarea>
			<?php $omnabase_error( 'message' ); ?>
		</div>

		<div class="ob-wp-form__trap" aria-hidden="true">
			<label>
				<?php esc_html_e( 'Leave this field empty', 'omnabase' ); ?>
				<input type="text" name="<?php echo esc_attr( $omnabase_form['honeypot'] ); ?>" value="" tabindex="-1" autocomplete="off">
			</label>
		</div>

		<noscript>
			<p class="ob-wp-form__note"><?php esc_html_e( 'This form needs JavaScript to send. Please turn it on in your browser and reload the page.', 'omnabase' ); ?></p>
		</noscript>

		<p class="ob-wp-form__status" role="status" aria-live="polite"></p>
		<p class="ob-wp-form__actions">
			<button class="ob-btn ob-btn--primary ob-wp-form__submit" type="submit" disabled><?php esc_html_e( 'Send', 'omnabase' ); ?></button>
		</p>

		<?php if ( '' !== $omnabase_form['privacyUrl'] ) : ?>
			<p class="ob-wp-form__privacy">
				<?php esc_html_e( 'We use your details only to answer your message.', 'omnabase' ); ?>
				<a href="<?php echo esc_url( $omnabase_form['privacyUrl'] ); ?>"><?php esc_html_e( 'Privacy policy', 'omnabase' ); ?></a>
			</p>
		<?php endif; ?>
	</form>

	<div class="ob-wp-form__done" role="status" aria-live="polite" hidden
		data-sent="<?php echo esc_attr( $omnabase_sent ); ?>"
		data-queued="<?php echo esc_attr( __( 'Thanks, your message is on its way.', 'omnabase' ) ); ?>"
		data-taken="<?php echo esc_attr( __( 'Thanks. Your message was sent.', 'omnabase' ) ); ?>"
	>
		<span class="ob-wp-form__done-icon"><svg viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path d="M378-246 154-470l43-43 181 181 384-384 43 43-427 427Z"/></svg></span>
		<p class="ob-wp-form__done-title" tabindex="-1"></p>
		<p class="ob-wp-form__done-line"><?php esc_html_e( 'We answer by email.', 'omnabase' ); ?></p>
		<button type="button" class="ob-btn ob-btn--outline ob-wp-form__again"><?php esc_html_e( 'Send another', 'omnabase' ); ?></button>
	</div>
</div>
