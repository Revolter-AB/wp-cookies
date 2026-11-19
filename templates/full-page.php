<?php
/**
 * Full cookie policy page.
 *
 * @package RevolterCookies
 *
 * @var array $rc_data Prepared by Declaration::render_full_page().
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from Declaration, so these are locals.

$rc_show    = $rc_data['show'];
$rc_consent = $rc_data['consent'];
?>
<div class="rc-decl rc-decl-full" data-no-translation style="<?php echo esc_attr( $rc_data['style'] ); ?>">
<?php foreach ( array( 'what', 'how' ) as $rc_key ) : ?>
	<?php if ( $rc_show[ $rc_key ] ) : ?>
	<section class="rc-section rc-section-<?php echo esc_attr( $rc_key ); ?>">
		<h2 class="rc-section-title"><?php echo esc_html( $rc_data['sections'][ $rc_key ]['title'] ); ?></h2>
		<p><?php echo $rc_data['bodies'][ $rc_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped, then wp_kses. ?></p>
	</section>
	<?php endif; ?>
<?php endforeach; ?>
	<section class="rc-section rc-section-declaration">
<?php echo $rc_data['table_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by templates/cookies-table-inner.php, which escapes every value. ?>
	</section>
<?php foreach ( array( 'manage', 'legal', 'rights' ) as $rc_key ) : ?>
	<?php if ( $rc_show[ $rc_key ] ) : ?>
	<section class="rc-section rc-section-<?php echo esc_attr( $rc_key ); ?>">
		<h2 class="rc-section-title"><?php echo esc_html( $rc_data['sections'][ $rc_key ]['title'] ); ?></h2>
		<p><?php echo $rc_data['bodies'][ $rc_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped, then wp_kses. ?></p>
	</section>
	<?php endif; ?>
<?php endforeach; ?>
<?php if ( $rc_show['consent'] ) : ?>
	<div class="rc-consent-box" data-rc-consent-status>
		<h2 class="rc-section-title"><?php echo esc_html( $rc_consent['title'] ); ?></h2>
		<p class="rc-consent-text" data-rc-consent-text></p>
		<div class="rc-consent-actions">
			<button type="button" class="rc-btn rc-btn-outline" data-rc-action="withdraw"><?php echo esc_html( $rc_consent['withdraw'] ); ?></button>
			<button type="button" class="rc-btn rc-btn-primary" data-rc-action="change"><?php echo esc_html( $rc_consent['change'] ); ?></button>
		</div>
	</div>
<?php endif; ?>
</div>
