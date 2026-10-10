<?php
/**
 * Settings › OmnaBase: the shortcode builder's dialog (the browser's own `<dialog>`, opened by "Build a shortcode" in the Shortcodes block).
 * It sits after the Forms and messages tab's save form, never inside it: "Create the page for me" is its own form with its own nonce.
 * `assets/admin.js` keeps the tag in step with the choices; the server builds the page from the checked choices alone.
 *
 * @package OmnaBase
 *
 * @var array{projects:array<int,array{slug:string,name:string}>,picked:string,offers:array<string,?array{id:int,title:string,url:string}>,tag:string,canPublish:bool} $omnabase_builder
 */

defined( 'ABSPATH' ) || exit;

$omnabase_link_only = array( 'a' => array( 'href' => array() ) );
/* translators: %s: the picked message centre page's title. */
$omnabase_picked_line = explode( '%s', __( 'It works only on the page picked as the message centre page: %s.', 'omnabase' ), 2 ) + array( '', '' );
$omnabase_offer_words = array(
	/* translators: %s: a page's title, linked to its editor. */
	'form'     => __( '%s already shows the contact form.', 'omnabase' ),
	/* translators: %s: a page's title, linked to its editor. */
	'messages' => __( '%s already shows the message centre.', 'omnabase' ),
);
?>
<dialog id="ob-wp-builder" class="ob-wp-builder" aria-labelledby="ob-wp-builder-title">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-wp-builder__form">
		<h2 id="ob-wp-builder-title"><?php esc_html_e( 'Build a shortcode', 'omnabase' ); ?></h2>
		<input type="hidden" name="action" value="omnabase_create_page">
		<?php wp_nonce_field( 'omnabase_create_page' ); ?>

		<fieldset class="ob-wp-builder__kinds">
			<legend><?php esc_html_e( 'What to show', 'omnabase' ); ?></legend>
			<label class="ob-wp-builder__card">
				<input type="radio" name="omnabase_builder[kind]" value="form" checked>
				<span><?php esc_html_e( 'Contact form: visitors\' messages become tickets', 'omnabase' ); ?></span>
			</label>
			<label class="ob-wp-builder__card">
				<input type="radio" name="omnabase_builder[kind]" value="messages">
				<span><?php esc_html_e( 'Message centre: signed-in customers read and answer their tickets', 'omnabase' ); ?></span>
			</label>
		</fieldset>

		<?php if ( array() !== $omnabase_builder['projects'] ) : ?>
			<p class="ob-wp-builder__row">
				<label for="ob-wp-builder-project"><?php esc_html_e( 'Project', 'omnabase' ); ?></label>
				<select id="ob-wp-builder-project" name="omnabase_builder[project]">
					<option value=""><?php esc_html_e( 'Choose a project', 'omnabase' ); ?></option>
					<?php foreach ( $omnabase_builder['projects'] as $omnabase_project ) : ?>
						<option value="<?php echo esc_attr( $omnabase_project['slug'] ); ?>"><?php echo esc_html( $omnabase_project['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>

		<p class="ob-wp-builder__row" data-for="form">
			<label for="ob-wp-builder-subject"><?php esc_html_e( 'Subject starts with', 'omnabase' ); ?></label>
			<input type="text" id="ob-wp-builder-subject" name="omnabase_builder[subject]" class="regular-text" maxlength="150" autocomplete="off">
		</p>

		<fieldset class="ob-wp-builder__row" data-for="messages" hidden>
			<legend><?php esc_html_e( 'Visitors who are not signed in', 'omnabase' ); ?></legend>
			<label><input type="radio" name="omnabase_builder[guest]" value="" checked> <?php esc_html_e( 'see a sign-in link', 'omnabase' ); ?></label>
			<label><input type="radio" name="omnabase_builder[guest]" value="form"> <?php esc_html_e( 'see the contact form instead', 'omnabase' ); ?></label>
		</fieldset>

		<p class="description" data-for="messages" hidden>
			<?php echo esc_html( $omnabase_picked_line[0] ); ?><strong class="ob-wp-builder__picked" data-none="<?php esc_attr_e( 'none yet', 'omnabase' ); ?>"><?php echo esc_html( '' !== $omnabase_builder['picked'] ? $omnabase_builder['picked'] : __( 'none yet', 'omnabase' ) ); ?></strong><?php echo esc_html( $omnabase_picked_line[1] ); ?>
		</p>

		<p class="ob-wp-builder__row">
			<label for="ob-wp-builder-tag"><?php esc_html_e( 'Your shortcode', 'omnabase' ); ?></label>
			<input type="text" id="ob-wp-builder-tag" class="large-text code ob-wp-builder__tag" value="<?php echo esc_attr( $omnabase_builder['tag'] ); ?>" readonly>
		</p>
		<p class="ob-wp-builder__copy">
			<button type="button" class="button" data-ob-copy><?php esc_html_e( 'Copy', 'omnabase' ); ?></button>
			<span class="ob-wp-builder__copied" role="status" data-copied="<?php esc_attr_e( 'Copied.', 'omnabase' ); ?>"></span>
		</p>
		<p class="description"><?php esc_html_e( 'Paste it into any page or post.', 'omnabase' ); ?></p>

		<?php foreach ( $omnabase_builder['offers'] as $omnabase_kind => $omnabase_offer ) : ?>
			<?php if ( null !== $omnabase_offer ) : ?>
				<div class="ob-wp-builder__existing" data-kind="<?php echo esc_attr( $omnabase_kind ); ?>"<?php echo 'form' === $omnabase_kind ? '' : ' hidden'; ?>>
					<p><?php echo wp_kses( sprintf( esc_html( $omnabase_offer_words[ $omnabase_kind ] ), '<a href="' . esc_url( $omnabase_offer['url'] ) . '">' . esc_html( $omnabase_offer['title'] ) . '</a>' ), $omnabase_link_only ); ?></p>
					<p>
						<?php if ( 'form' === $omnabase_kind ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $omnabase_offer['url'] ); ?>"><?php esc_html_e( 'Use this page', 'omnabase' ); ?></a>
						<?php else : ?>
							<button type="button" class="button button-primary" data-ob-use-page="<?php echo esc_attr( (string) $omnabase_offer['id'] ); ?>" data-title="<?php echo esc_attr( $omnabase_offer['title'] ); ?>"><?php esc_html_e( 'Use this page', 'omnabase' ); ?></button>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>

		<p class="ob-wp-builder__actions">
			<?php if ( $omnabase_builder['canPublish'] ) : ?>
				<button type="submit" class="button button-primary" data-ob-create data-anyway="<?php esc_attr_e( 'Create a new page anyway', 'omnabase' ); ?>"><?php esc_html_e( 'Create the page for me', 'omnabase' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button" data-ob-close><?php esc_html_e( 'Close', 'omnabase' ); ?></button>
		</p>
	</form>
</dialog>
