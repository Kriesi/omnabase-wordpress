<?php
/**
 * The message centre `[omnabase_messages]`. Rendered by `Portal\Views`; everything printed is escaped, a message's words as text. The views:
 * `login` (a visitor not signed in), `refused` (OmnaBase refused the user), `down` (OmnaBase could not be asked), `list` (the tickets, the
 * confirm prompt, New ticket), `thread` (one ticket and Reply), `missing` (no such ticket of this visitor's). Every form posts back to the
 * page with the nonce; the page is never cached, so the New ticket form's token may be printed in it, and so may the words of a refused New
 * ticket (round six: the same user's own, escaped).
 *
 * @package OmnaBase
 *
 * @var array<string,mixed> $omnabase_messages What `Portal\Views` hands over.
 */

defined( 'ABSPATH' ) || exit;

$omnabase_view = (string) $omnabase_messages['view'];
?>
<div class="ob-wp ob-wp-messages">
	<?php if ( is_array( $omnabase_messages['notice'] ) ) : ?>
		<p class="ob-wp-messages__notice <?php echo esc_attr( $omnabase_messages['notice']['ok'] ? 'ob-wp-messages__notice--ok' : 'ob-wp-messages__notice--error' ); ?>" role="status">
			<?php echo esc_html( $omnabase_messages['notice']['message'] ); ?>
		</p>
	<?php endif; ?>

	<?php if ( 'login' === $omnabase_view ) : ?>
		<p class="ob-wp-messages__login">
			<a class="ob-wp-messages__button" href="<?php echo esc_url( $omnabase_messages['loginUrl'] ); ?>"><?php esc_html_e( 'Sign in', 'omnabase' ); ?></a>
			<?php esc_html_e( 'to read and answer your support conversations.', 'omnabase' ); ?>
		</p>

	<?php elseif ( 'refused' === $omnabase_view || 'down' === $omnabase_view ) : ?>
		<p class="ob-wp-messages__refusal"><?php echo esc_html( $omnabase_messages['refusal'] ); ?></p>

	<?php elseif ( 'missing' === $omnabase_view ) : ?>
		<p class="ob-wp-messages__refusal"><?php esc_html_e( 'This conversation was not found.', 'omnabase' ); ?></p>
		<p><a href="<?php echo esc_url( $omnabase_messages['back'] ); ?>"><?php esc_html_e( '← All conversations', 'omnabase' ); ?></a></p>

	<?php elseif ( 'thread' === $omnabase_view ) : ?>
		<?php $omnabase_ticket = $omnabase_messages['ticket']; ?>
		<p class="ob-wp-messages__back"><a href="<?php echo esc_url( $omnabase_messages['back'] ); ?>"><?php esc_html_e( '← All conversations', 'omnabase' ); ?></a></p>
		<h2 class="ob-wp-messages__title"><?php echo esc_html( $omnabase_ticket['subject'] ); ?></h2>
		<p class="ob-wp-messages__meta">
			<span class="ob-wp-messages__status ob-wp-messages__status--<?php echo esc_attr( $omnabase_ticket['status'] ); ?>"><?php echo esc_html( $omnabase_ticket['statusWord'] ); ?></span>
			<span class="ob-wp-messages__number">#<?php echo absint( $omnabase_ticket['number'] ); ?></span>
		</p>
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
			<p class="ob-wp-messages__field">
				<label>
					<span class="ob-wp-messages__label"><?php esc_html_e( 'Your answer', 'omnabase' ); ?></span>
					<textarea class="ob-wp-messages__input ob-wp-messages__text" name="message" rows="5" maxlength="50000" required></textarea>
				</label>
			</p>
			<p class="ob-wp-messages__actions"><button class="ob-wp-messages__button" type="submit"><?php esc_html_e( 'Send answer', 'omnabase' ); ?></button></p>
		</form>

	<?php else : ?>
		<?php if ( $omnabase_messages['prompt'] ) : ?>
			<section class="ob-wp-messages__confirm">
				<div class="ob-wp-messages__confirm-text">
					<h3 class="ob-wp-messages__confirm-title"><?php esc_html_e( 'Confirm your email to see your earlier conversations', 'omnabase' ); ?></h3>
					<p class="ob-wp-messages__confirm-line">
						<?php
						/* translators: %s: the visitor's address, partly hidden. */
						echo esc_html( sprintf( __( 'Your earlier email conversations and forum topics show here once you confirm %s. The tickets you wrote here show already.', 'omnabase' ), $omnabase_messages['masked'] ) );
						?>
					</p>
				</div>
				<form class="ob-wp-messages__confirm-form" method="post" action="<?php echo esc_url( $omnabase_messages['action'] ); ?>">
					<input type="hidden" name="omnabase_do" value="confirm">
					<input type="hidden" name="omnabase_nonce" value="<?php echo esc_attr( $omnabase_messages['nonce'] ); ?>">
					<button class="ob-wp-messages__button" type="submit"><?php esc_html_e( 'Show my email conversations', 'omnabase' ); ?></button>
				</form>
			</section>
		<?php endif; ?>

		<h2 class="ob-wp-messages__title"><?php esc_html_e( 'Your conversations', 'omnabase' ); ?></h2>
		<?php if ( array() === $omnabase_messages['rows'] ) : ?>
			<p class="ob-wp-messages__empty"><?php esc_html_e( 'No conversations yet.', 'omnabase' ); ?></p>
		<?php else : ?>
			<ul class="ob-wp-messages__list">
				<?php foreach ( $omnabase_messages['rows'] as $omnabase_row ) : ?>
					<li class="ob-wp-messages__row">
						<a class="ob-wp-messages__subject" href="<?php echo esc_url( $omnabase_row['url'] ); ?>"><?php echo esc_html( $omnabase_row['subject'] ); ?></a>
						<span class="ob-wp-messages__status ob-wp-messages__status--<?php echo esc_attr( $omnabase_row['status'] ); ?>"><?php echo esc_html( $omnabase_row['statusWord'] ); ?></span>
						<span class="ob-wp-messages__when"><?php echo esc_html( $omnabase_row['when'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( '' !== $omnabase_messages['first'] || '' !== $omnabase_messages['next'] ) : ?>
			<p class="ob-wp-messages__paging">
				<?php if ( '' !== $omnabase_messages['first'] ) : ?>
					<a href="<?php echo esc_url( $omnabase_messages['first'] ); ?>"><?php esc_html_e( '← Newest', 'omnabase' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $omnabase_messages['next'] ) : ?>
					<a href="<?php echo esc_url( $omnabase_messages['next'] ); ?>"><?php esc_html_e( 'Older conversations →', 'omnabase' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<h2 class="ob-wp-messages__title"><?php esc_html_e( 'New question', 'omnabase' ); ?></h2>
		<form class="ob-wp-messages__form ob-wp-messages__new" method="post" action="<?php echo esc_url( $omnabase_messages['action'] ); ?>">
			<input type="hidden" name="omnabase_do" value="new_ticket">
			<input type="hidden" name="omnabase_token" value="<?php echo esc_attr( $omnabase_messages['token'] ); ?>">
			<input type="hidden" name="omnabase_nonce" value="<?php echo esc_attr( $omnabase_messages['nonce'] ); ?>">
			<?php if ( '' !== $omnabase_messages['project'] ) : ?>
				<input type="hidden" name="project" value="<?php echo esc_attr( $omnabase_messages['project'] ); ?>">
			<?php endif; ?>
			<p class="ob-wp-messages__field">
				<label>
					<span class="ob-wp-messages__label"><?php esc_html_e( 'Subject', 'omnabase' ); ?></span>
					<input class="ob-wp-messages__input" type="text" name="subject" value="<?php echo esc_attr( $omnabase_messages['draft']['subject'] ); ?>" maxlength="200" required>
				</label>
			</p>
			<p class="ob-wp-messages__field">
				<label>
					<span class="ob-wp-messages__label"><?php esc_html_e( 'Message', 'omnabase' ); ?></span>
					<textarea class="ob-wp-messages__input ob-wp-messages__text" name="message" rows="6" maxlength="50000" required><?php echo esc_textarea( $omnabase_messages['draft']['message'] ); ?></textarea>
				</label>
			</p>
			<div class="ob-wp-form__trap" aria-hidden="true">
				<label>
					<?php esc_html_e( 'Leave this field empty', 'omnabase' ); ?>
					<input type="text" name="<?php echo esc_attr( $omnabase_messages['honeypot'] ); ?>" value="" tabindex="-1" autocomplete="off">
				</label>
			</div>
			<p class="ob-wp-messages__actions"><button class="ob-wp-messages__button" type="submit"><?php esc_html_e( 'Send', 'omnabase' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
