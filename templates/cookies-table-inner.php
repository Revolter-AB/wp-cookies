<?php
/**
 * Declaration heading and tables.
 *
 * @package RevolterCookies
 *
 * @var array $rc_data Prepared by Declaration::table_html().
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from Declaration, so these are locals.

$rc_texts = $rc_data['texts'];
?>
<div class="rc-declaration">
	<h2 class="rc-decl-title"><?php echo esc_html( $rc_texts['title'] ); ?></h2>
	<?php if ( '' !== $rc_data['updated'] ) : ?>
		<p class="rc-updated"><?php echo esc_html( $rc_data['updated'] ); ?></p>
	<?php endif; ?>
	<?php foreach ( $rc_data['groups'] as $rc_group ) : ?>
		<h3 class="rc-group-title"><?php echo esc_html( $rc_group['label'] ); ?> <span class="rc-group-count"><?php echo esc_html( \RevolterCookies\Texts::category_count( $rc_group['count'] ) ); ?></span></h3>
		<p class="rc-group-desc"><?php echo esc_html( $rc_group['description'] ); ?></p>
		<table class="rc-table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( $rc_texts['name'] ); ?></th>
					<th scope="col"><?php echo esc_html( $rc_texts['provider'] ); ?></th>
					<th scope="col"><?php echo esc_html( $rc_texts['purpose'] ); ?></th>
					<th scope="col"><?php echo esc_html( $rc_texts['duration'] ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rc_group['rows'] as $rc_row ) : ?>
				<tr>
					<td data-label="<?php echo esc_attr( $rc_texts['name'] ); ?>"><code><?php echo esc_html( $rc_row['name'] ); ?></code></td>
					<td data-label="<?php echo esc_attr( $rc_texts['provider'] ); ?>"><?php echo esc_html( $rc_row['provider'] ); ?></td>
					<td data-label="<?php echo esc_attr( $rc_texts['purpose'] ); ?>"><?php echo esc_html( $rc_row['purpose'] ); ?></td>
					<td data-label="<?php echo esc_attr( $rc_texts['duration'] ); ?>"><?php echo esc_html( $rc_row['duration'] ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>
	<?php if ( '' !== $rc_data['settings_link'] ) : ?>
		<p class="rc-settings-link"><a href="#cookie-settings" class="rc-open-settings"><?php echo esc_html( $rc_data['settings_link'] ); ?></a></p>
	<?php endif; ?>
</div>
