<?php
/**
 * Cookie table only.
 *
 * @package RevolterCookies
 *
 * @var array $rc_data Prepared by Declaration::render_table().
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from Declaration, so these are locals.
?>
<div class="rc-decl" data-no-translation style="<?php echo esc_attr( $rc_data['style'] ); ?>">
<?php echo $rc_data['table_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by templates/cookies-table-inner.php, which escapes every value. ?>
</div>
