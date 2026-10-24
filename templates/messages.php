<?php
/**
 * The message centre `[omnabase_messages]`. Rendered by `Portal\Views`; everything printed is escaped, a message's words as text. The views:
 * `login` (a visitor not signed in), `refused` (OmnaBase refused the user), `down` (OmnaBase could not be asked), `list` (the tickets, the
 * confirm prompt, New ticket), `thread` (one ticket and Reply), `missing` (no such ticket of this visitor's). Every form posts back to the
 * page with the nonce; the page is never cached, so the New ticket form's token may be printed in it, and so may the words of a refused New
 * ticket (round six: the same user's own, escaped). The look is the app's inbox (2026-10-22): the kit's notice, ticket rows with a status
 * pill, the thread with the visitor's own messages told apart from the team's, the reply box as the composer, the prompt as the app's
 * `.ob-portal-confirm` card; the wrapper carries the chosen look's marks.
 *
 * @package OmnaBase
 *
 * @var array<string,mixed> $omnabase_messages What `Portal\Views` hands over.
 */

defined( 'ABSPATH' ) || exit;

$omnabase_view = (string) $omnabase_messages['view'];
$omnabase_pill = static function ( array $ticket ): void {
	?>
	<span class="ob-pill ob-pill--<?php echo esc_attr( $ticket['status'] ); ?>"><?php echo esc_html( $ticket['statusWord'] ); ?></span>
	<?php
};
?>
<div class="ob-wp ob-wp-messages"<?php foreach ( (array) ( $omnabase_messages['wrapper'] ?? array() ) as $omnabase_attr => $omnabase_value ) : ?> <?php echo esc_attr( $omnabase_attr ); ?>="<?php echo esc_attr( $omnabase_value ); ?>"<?php endforeach; ?>>
	<?php if ( is_array( $omnabase_messages['notice'] ) ) : ?>
		<p class="ob-notice ob-wp-messages__notice <?php echo esc_attr( $omnabase_messages['notice']['ok'] ? 'ob-notice--ok' : 'ob-notice--error' ); ?>" role="status">
			<?php if ( $omnabase_messages['notice']['ok'] ) : ?>
				<svg viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path d="M378-246 154-470l43-43 181 181 384-384 43 43-427 427Z"/></svg>
			<?php else : ?>
				<svg viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path d="M453-280h60v-240h-60v240Zm26.98-314q14.02 0 23.52-9.2T513-626q0-14.45-9.48-24.22-9.48-9.78-23.5-9.78t-23.52 9.78Q447-640.45 447-626q0 13.6 9.48 22.8 9.48 9.2 23.5 9.2Zm.29 514q-82.74 0-155.5-31.5Q252-143 197.5-197.5t-86-127.34Q80-397.68 80-480.5t31.5-155.66Q143-709 197.5-763t127.34-85.5Q397.68-880 480.5-880t155.66 31.5Q709-817 763-763t85.5 127Q880-563 880-480.27q0 82.74-31.5 155.5Q817-252 763-197.68q-54 54.31-127 86Q563-80 480.27-80Zm.23-60Q622-140 721-239.5t99-241Q820-622 721.19-721T480-820q-141 0-240.5 98.81T140-480q0 141 99.5 240.5t241 99.5Zm-.5-340Z"/></svg>
			<?php endif; ?>
			<span class="ob-notice__text"><?php echo esc_html( $omnabase_messages['notice']['message'] ); ?></span>
		</p>
	<?php endif; ?>

	<?php if ( 'login' === $omnabase_view ) : ?>
		<p class="ob-wp-messages__login">
			<a class="ob-btn ob-btn--primary" href="<?php echo esc_url( $omnabase_messages['loginUrl'] ); ?>"><?php esc_html_e( 'Sign in', 'omnabase' ); ?></a>
			<?php esc_html_e( 'to read and answer your support conversations.', 'omnabase' ); ?>
		</p>

	<?php elseif ( 'refused' === $omnabase_view || 'down' === $omnabase_view ) : ?>
		<p class="ob-wp-messages__refusal"><?php echo esc_html( $omnabase_messages['refusal'] ); ?></p>
		<?php if ( '' !== ( $omnabase_messages['inboxUrl'] ?? '' ) ) : ?>
			<p class="ob-wp-messages__back"><a class="ob-btn ob-btn--primary" href="<?php echo esc_url( $omnabase_messages['inboxUrl'] ); ?>"><?php esc_html_e( 'Open the OmnaBase inbox', 'omnabase' ); ?></a></p>
		<?php endif; ?>

	<?php elseif ( 'missing' === $omnabase_view ) : ?>
		<p class="ob-wp-messages__refusal"><?php esc_html_e( 'This conversation was not found.', 'omnabase' ); ?></p>
		<p class="ob-wp-messages__back"><a class="ob-btn ob-btn--ghost" href="<?php echo esc_url( $omnabase_messages['back'] ); ?>"><?php esc_html_e( '← All conversations', 'omnabase' ); ?></a></p>

	<?php elseif ( 'thread' === $omnabase_view ) : ?>
		<?php $omnabase_ticket = $omnabase_messages['ticket']; ?>
		<p class="ob-wp-messages__back"><a class="ob-btn ob-btn--ghost" href="<?php echo esc_url( $omnabase_messages['back'] ); ?>"><?php esc_html_e( '← All conversations', 'omnabase' ); ?></a></p>
		<div class="ob-wp-messages__section">
			<h2 class="ob-wp-messages__title"><?php echo esc_html( $omnabase_ticket['subject'] ); ?></h2>
			<p class="ob-wp-messages__meta">
				<?php $omnabase_pill( $omnabase_ticket ); ?>
				<span class="ob-wp-messages__number">#<?php echo absint( $omnabase_ticket['number'] ); ?></span>
			</p>
		</div>
		<ol class="ob-wp-messages__thread">
			<?php foreach ( $omnabase_messages['messages'] as $omnabase_message ) : ?>
				<li class="ob-wp-messages__message ob-wp-messages__message--<?php echo esc_attr( $omnabase_message['kind'] ); ?>">
					<p class="ob-wp-messages__author">
						<strong><?php echo esc_html( $omnabase_message['name'] ); ?></strong>
						<span class="ob-wp-messages__when"><?php echo esc_html( $omnabase_message['when'] ); ?></span>
					</p>
					<div class="ob-wp-messages__body"><?php echo esc_html( $omnabase_message['text'] ); ?></div>
				</li>
			<?php endforeach; ?>
		</ol>
		<form class="ob-wp-messages__form ob-wp-messages__reply" method="post" action="<?php echo esc_url( add_query_arg( 'omnabase_ticket', absint( $omnabase_ticket['number'] ), $omnabase_messages['action'] ) ); ?>">
			<input type="hidden" name="omnabase_do" value="reply">
			<input type="hidden" name="omnabase_ticket" value="<?php echo absint( $omnabase_ticket['number'] ); ?>">
			<input type="hidden" name="omnabase_nonce" value="<?php echo esc_attr( $omnabase_messages['nonce'] ); ?>">
			<label class="ob-wp-messages__label" for="ob-wp-messages-reply"><?php esc_html_e( 'Your answer', 'omnabase' ); ?></label>
			<div class="ob-wp-messages__composer">
				<textarea class="ob-textarea" id="ob-wp-messages-reply" name="message" rows="5" maxlength="50000" required></textarea>
				<div class="ob-wp-messages__composer-foot">
					<button class="ob-btn ob-btn--primary" type="submit"><?php esc_html_e( 'Send answer', 'omnabase' ); ?></button>
				</div>
			</div>
		</form>

	<?php else : ?>
		<?php if ( $omnabase_messages['prompt'] ) : ?>
			<section class="ob-portal-confirm ob-wp-messages__confirm">
				<svg class="ob-portal-confirm__icon" viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path d="M140-160q-24 0-42-18t-18-42v-520q0-24 18-42t42-18h680q24 0 42 18t18 42v520q0 24-18 42t-42 18H140Zm340-302L140-685v465h680v-465L480-462Zm0-60 336-218H145l335 218ZM140-685v-55 520-465Z"/></svg>
				<div class="ob-portal-confirm__text">
					<h3 class="ob-portal-confirm__title"><?php esc_html_e( 'Confirm your email to see your earlier conversations', 'omnabase' ); ?></h3>
					<p class="ob-portal-confirm__line">
						<?php
						/* translators: %s: the visitor's address, partly hidden. */
						echo esc_html( sprintf( __( 'Your earlier email conversations and forum topics show here once you confirm %s. The tickets you wrote here show already.', 'omnabase' ), $omnabase_messages['masked'] ) );
						?>
					</p>
				</div>
				<form class="ob-portal-confirm__form" method="post" action="<?php echo esc_url( $omnabase_messages['action'] ); ?>">
					<input type="hidden" name="omnabase_do" value="confirm">
					<input type="hidden" name="omnabase_nonce" value="<?php echo esc_attr( $omnabase_messages['nonce'] ); ?>">
					<button class="ob-btn ob-btn--primary" type="submit"><?php esc_html_e( 'Show my email conversations', 'omnabase' ); ?></button>
				</form>
			</section>
		<?php endif; ?>

		<div class="ob-wp-messages__section">
			<h2 class="ob-wp-messages__title"><?php esc_html_e( 'Your conversations', 'omnabase' ); ?></h2>
			<?php if ( array() === $omnabase_messages['rows'] ) : ?>
				<p class="ob-wp-messages__empty"><?php esc_html_e( 'No conversations yet.', 'omnabase' ); ?></p>
			<?php else : ?>
				<ul class="ob-wp-messages__list">
					<?php foreach ( $omnabase_messages['rows'] as $omnabase_row ) : ?>
						<li class="ob-wp-messages__row">
							<a class="ob-wp-messages__subject" href="<?php echo esc_url( $omnabase_row['url'] ); ?>"><?php echo esc_html( $omnabase_row['subject'] ); ?></a>
							<span class="ob-wp-messages__row-meta">
								<?php $omnabase_pill( $omnabase_row ); ?>
								<span class="ob-wp-messages__when"><?php echo esc_html( $omnabase_row['when'] ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( '' !== $omnabase_messages['first'] || '' !== $omnabase_messages['next'] ) : ?>
				<p class="ob-wp-messages__paging">
					<?php if ( '' !== $omnabase_messages['first'] ) : ?>
						<a class="ob-btn ob-btn--outline" href="<?php echo esc_url( $omnabase_messages['first'] ); ?>"><?php esc_html_e( '← Newest', 'omnabase' ); ?></a>
					<?php endif; ?>
					<?php if ( '' !== $omnabase_messages['next'] ) : ?>
						<a class="ob-btn ob-btn--outline" href="<?php echo esc_url( $omnabase_messages['next'] ); ?>"><?php esc_html_e( 'Older conversations →', 'omnabase' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

		<div class="ob-wp-messages__section">
			<h2 class="ob-wp-messages__title"><?php esc_html_e( 'New question', 'omnabase' ); ?></h2>
			<form class="ob-wp-messages__form ob-wp-messages__new" method="post" action="<?php echo esc_url( $omnabase_messages['action'] ); ?>">
				<input type="hidden" name="omnabase_do" value="new_ticket">
				<input type="hidden" name="omnabase_token" value="<?php echo esc_attr( $omnabase_messages['token'] ); ?>">
				<input type="hidden" name="omnabase_nonce" value="<?php echo esc_attr( $omnabase_messages['nonce'] ); ?>">
				<?php if ( '' !== $omnabase_messages['project'] ) : ?>
					<input type="hidden" name="project" value="<?php echo esc_attr( $omnabase_messages['project'] ); ?>">
				<?php endif; ?>
				<div class="ob-wp-messages__field">
					<label class="ob-wp-messages__label" for="ob-wp-messages-subject"><?php esc_html_e( 'Subject', 'omnabase' ); ?></label>
					<span class="ob-input ob-input--text"><input class="ob-input__field" id="ob-wp-messages-subject" type="text" name="subject" value="<?php echo esc_attr( $omnabase_messages['draft']['subject'] ); ?>" maxlength="200" required></span>
				</div>
				<div class="ob-wp-messages__field">
					<label class="ob-wp-messages__label" for="ob-wp-messages-message"><?php esc_html_e( 'Message', 'omnabase' ); ?></label>
					<textarea class="ob-textarea" id="ob-wp-messages-message" name="message" rows="6" maxlength="50000" required><?php echo esc_textarea( $omnabase_messages['draft']['message'] ); ?></textarea>
				</div>
				<div class="ob-wp-form__trap" aria-hidden="true">
					<label>
						<?php esc_html_e( 'Leave this field empty', 'omnabase' ); ?>
						<input type="text" name="<?php echo esc_attr( $omnabase_messages['honeypot'] ); ?>" value="" tabindex="-1" autocomplete="off">
					</label>
				</div>
				<p class="ob-wp-messages__actions"><button class="ob-btn ob-btn--primary" type="submit"><?php esc_html_e( 'Send', 'omnabase' ); ?></button></p>
			</form>
		</div>
	<?php endif; ?>
</div>
