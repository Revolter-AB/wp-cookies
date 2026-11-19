<?php
/**
 * Banner, preferences modal and settings button. Hidden until consent.js reveals them.
 *
 * @package RevolterCookies
 *
 * @var array $rc_data Prepared by Banner::render().
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from Banner::render(), so these are locals.

$rc_texts = $rc_data['texts'];
?>
<div id="rc-root" class="rc-root" data-no-translation style="<?php echo esc_attr( $rc_data['style'] ); ?>">
<div id="rc-banner" class="rc-banner" role="region" aria-label="<?php echo esc_attr( $rc_texts['title'] ); ?>" hidden>
	<div class="rc-card">
		<div class="rc-banner-body">
			<div class="rc-banner-text">
				<h2 class="rc-title"><?php echo esc_html( $rc_texts['title'] ); ?></h2>
				<p class="rc-text"><?php echo $rc_data['policy_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped parts, then wp_kses. ?></p>
			</div>
			<div class="rc-actions">
				<div class="rc-actions-row">
					<button type="button" class="rc-btn rc-btn-primary" data-rc-action="accept-all"><?php echo esc_html( $rc_texts['accept_all'] ); ?></button>
					<button type="button" class="rc-btn rc-btn-primary" data-rc-action="reject-all"><?php echo esc_html( $rc_texts['reject_all'] ); ?></button>
				</div>
				<button type="button" class="rc-btn rc-btn-outline" data-rc-action="customize"><?php echo esc_html( $rc_texts['customize'] ); ?></button>
			</div>
		</div>
		<?php echo $rc_data['credit_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped text and a bundled SVG. ?>
	</div>
</div>
<div id="rc-modal" class="rc-modal" role="dialog" aria-modal="true" aria-labelledby="rc-modal-title" hidden>
	<div class="rc-dialog">
		<div class="rc-modal-head">
			<h2 id="rc-modal-title" class="rc-title"><?php echo esc_html( $rc_texts['modal_title'] ); ?></h2>
			<button type="button" class="rc-close" data-rc-action="close" aria-label="<?php echo esc_attr( $rc_texts['close'] ); ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
		</div>
		<p class="rc-intro"><?php echo esc_html( $rc_texts['modal_intro'] ); ?></p>
		<div class="rc-cats">
			<?php foreach ( $rc_data['rows'] as $rc_row ) : ?>
				<?php $rc_id = 'rc-cat-' . $rc_row['key']; ?>
			<div class="rc-cat<?php echo $rc_row['locked'] ? ' rc-cat-locked' : ''; ?>">
				<div class="rc-cat-text">
					<div class="rc-cat-head"><label for="<?php echo esc_attr( $rc_id ); ?>" class="rc-cat-name"><?php echo esc_html( $rc_row['label'] ); ?></label><?php echo '' !== $rc_row['count'] ? ' <span class="rc-cat-count">' . esc_html( $rc_row['count'] ) . '</span>' : ''; ?></div>
					<p id="<?php echo esc_attr( $rc_id . '-desc' ); ?>" class="rc-cat-desc"><?php echo esc_html( $rc_row['description'] ); ?></p>
				</div>
				<span class="rc-switch"><input type="checkbox" id="<?php echo esc_attr( $rc_id ); ?>" class="rc-switch-input" data-rc-category="<?php echo esc_attr( $rc_row['key'] ); ?>" aria-describedby="<?php echo esc_attr( $rc_id . '-desc' ); ?>"<?php echo $rc_row['locked'] ? ' checked disabled' : ''; ?>><span class="rc-switch-track" aria-hidden="true"></span></span>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="rc-modal-actions">
			<button type="button" class="rc-btn rc-btn-outline" data-rc-action="save"><?php echo esc_html( $rc_texts['save'] ); ?></button>
			<button type="button" class="rc-btn rc-btn-primary" data-rc-action="reject-all"><?php echo esc_html( $rc_texts['reject_all'] ); ?></button>
			<button type="button" class="rc-btn rc-btn-primary" data-rc-action="accept-all"><?php echo esc_html( $rc_texts['accept_all'] ); ?></button>
		</div>
		<?php echo $rc_data['credit_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped text and a bundled SVG. ?>
	</div>
</div>
<button type="button" id="rc-settings-button" class="rc-settings-button rc-pos-<?php echo esc_attr( $rc_data['position'] ); ?>" aria-label="<?php echo esc_attr( $rc_texts['settings_button'] ); ?>" hidden><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12.3A9 9 0 1 1 11.7 3a3 3 0 0 0 3.8 3.8 3 3 0 0 0 3.7 3.7 3 3 0 0 0 1.8 1.8Z"/><circle cx="8.5" cy="10.5" r="1" fill="currentColor"/><circle cx="12" cy="15.5" r="1" fill="currentColor"/><circle cx="16" cy="13" r="1" fill="currentColor"/><circle cx="9" cy="16" r="0.6" fill="currentColor"/></svg></button>
</div>
