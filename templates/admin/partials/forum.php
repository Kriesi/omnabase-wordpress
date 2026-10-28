<?php
/**
 * Settings › OmnaBase: the forum card (a tick per forum, who answers on the forum, the line under each answer, who may send topics, closing
 * on resolve), each setting a row of the kit's. Included by the Forums tab.
 *
 * @package OmnaBase
 *
 * @var array{active:bool,forums:array<int,array{id:int,title:string,visibility:string,public:bool,marked:bool}>,moderators:array<int,array{id:int,name:string}>,supportUser:int,closingLine:string,guests:bool,closeResolved:bool} $omnabase_forum
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( ! $omnabase_forum['active'] ) : ?>
	<section class="ob-form__section ob-wp-settings__forum">
		<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Forums', 'omnabase' ); ?></h2></div>
		<p class="description"><?php esc_html_e( 'Install and activate bbPress to send forum topics to OmnaBase.', 'omnabase' ); ?></p>
	</section>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-wp-settings__stack">
		<input type="hidden" name="action" value="omnabase_save_forum">
		<?php wp_nonce_field( 'omnabase_save_forum' ); ?>
		<section class="ob-form__section ob-wp-settings__forum">
			<div class="ob-form__head"><h2 class="ob-form__title"><?php esc_html_e( 'Forums', 'omnabase' ); ?></h2></div>
			<div class="ob-form__intro"><?php esc_html_e( 'Topics in the ticked forums become tickets. Your team\'s answers appear under each member\'s own moderator account, else under the support user below.', 'omnabase' ); ?></div>
			<ul class="ob-help-list ob-wp-settings__forum-rules">
				<li class="ob-help-list__item"><?php esc_html_e( 'Topics in hidden forums are not public; every other forum is public to OmnaBase.', 'omnabase' ); ?></li>
				<li class="ob-help-list__item"><?php esc_html_e( 'Replies by the topic\'s author and by moderators are sent.', 'omnabase' ); ?></li>
				<li class="ob-help-list__item"><?php esc_html_e( 'Edits and deletions on the forum are not sent.', 'omnabase' ); ?></li>
			</ul>
			<?php require __DIR__ . '/forum-rows.php'; ?>
			<?php require __DIR__ . '/forum-fields.php'; ?>
			<?php if ( 0 === $omnabase_forum['supportUser'] ) : ?>
				<p class="ob-wp-settings__forum-warning"><?php esc_html_e( 'Choose a support user: until then the AI\'s answers, and answers from team members without a moderator account here, wait at OmnaBase.', 'omnabase' ); ?></p>
			<?php endif; ?>
		</section>
		<p class="ob-wp-settings__actions"><button type="submit" class="ob-btn ob-btn--primary" name="omnabase_forum_submit"><?php esc_html_e( 'Save', 'omnabase' ); ?></button></p>
	</form>
<?php endif; ?>
