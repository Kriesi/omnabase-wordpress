<?php
/**
 * Settings › OmnaBase › Forums: the Post fields rows (the site-fields round, 2026-10-29). Each row a Field input (a post meta key, with the
 * site's meta keys offered through a datalist: the browser's suggestions under the input) and a Name input (what the team reads). The saved
 * rows plus one empty row, so one more field needs no JavaScript; "Add a field" and the remove buttons are small conveniences of the page's
 * script. Included by the forum card, inside its form.
 *
 * @package OmnaBase
 *
 * @var array{fieldRows:array<int,array{key:string,label:string}>,metaKeys:string[],fieldsMax:int,labelMax:int} $omnabase_forum
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ob-form-row ob-form-row--stack ob-wp-settings__post-fields" data-ob-post-fields data-ob-max="<?php echo esc_attr( (string) $omnabase_forum['fieldsMax'] ); ?>">
	<div class="ob-form-row__text">
		<div class="ob-form-row__label" id="omnabase-post-fields-label"><?php esc_html_e( 'Post fields', 'omnabase' ); ?></div>
		<div class="ob-form-row__help"><?php esc_html_e( 'Fields of a topic or reply (post meta) that travel with the message to OmnaBase and back onto the team\'s replies.', 'omnabase' ); ?></div>
	</div>
	<div class="ob-form-row__control">
		<datalist id="omnabase-forum-meta-keys">
			<?php foreach ( $omnabase_forum['metaKeys'] as $omnabase_meta_key ) : ?>
				<option value="<?php echo esc_attr( $omnabase_meta_key ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<div class="ob-wp-settings__field-rows" role="group" aria-labelledby="omnabase-post-fields-label">
			<?php foreach ( $omnabase_forum['fieldRows'] as $omnabase_i => $omnabase_row ) : ?>
				<div class="ob-wp-settings__field-row" data-ob-field-row>
					<label>
						<span class="ob-wp-settings__field-label"><?php esc_html_e( 'Field', 'omnabase' ); ?></span>
						<input type="text" name="omnabase_forum[fields][<?php echo esc_attr( (string) $omnabase_i ); ?>][key]" value="<?php echo esc_attr( $omnabase_row['key'] ); ?>" list="omnabase-forum-meta-keys" maxlength="100" autocomplete="off" spellcheck="false">
					</label>
					<label>
						<span class="ob-wp-settings__field-label"><?php esc_html_e( 'Name', 'omnabase' ); ?></span>
						<input type="text" name="omnabase_forum[fields][<?php echo esc_attr( (string) $omnabase_i ); ?>][label]" value="<?php echo esc_attr( $omnabase_row['label'] ); ?>" maxlength="<?php echo esc_attr( (string) $omnabase_forum['labelMax'] ); ?>">
					</label>
					<button type="button" class="ob-btn ob-btn--outline ob-btn--sm" data-ob-remove-field hidden><?php esc_html_e( 'Remove', 'omnabase' ); ?></button>
				</div>
			<?php endforeach; ?>
		</div>
		<p><button type="button" class="ob-btn ob-btn--outline ob-btn--sm" data-ob-add-field hidden><?php esc_html_e( 'Add a field', 'omnabase' ); ?></button></p>
	</div>
</div>
