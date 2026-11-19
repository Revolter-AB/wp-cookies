<?php
/**
 * Banner settings template.
 *
 * @package RevolterCookies
 *
 * @var array $view Variables from Settings_Pages::render_banner().
 */

use RevolterCookies\Texts;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from Menu::render(), so these are locals.

$rc_s    = $view['settings'];
$rc_name = $view['option'];
?>
<div class="wrap rc-admin">
	<h1><?php esc_html_e( 'Banner', 'revolter-cookies' ); ?></h1>
	<?php if ( $view['updated'] ) : ?>
		<div class="rc-inline-message rc-inline-success" role="status"><?php esc_html_e( 'Saved.', 'revolter-cookies' ); ?></div>
	<?php endif; ?>
	<form method="post" action="options.php">
		<?php settings_fields( $view['group'] ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rc-accent"><?php esc_html_e( 'Accent colour', 'revolter-cookies' ); ?></label></th>
				<td><input type="text" id="rc-accent" class="rc-color-field" name="<?php echo esc_attr( $rc_name ); ?>[accent_color]" value="<?php echo esc_attr( $rc_s['accent_color'] ); ?>" data-default-color="#2F4BC7"></td>
			</tr>
			<tr>
				<th scope="row"><label for="rc-position"><?php esc_html_e( 'Settings button position', 'revolter-cookies' ); ?></label></th>
				<td>
					<select id="rc-position" name="<?php echo esc_attr( $rc_name ); ?>[button_position]">
						<option value="left" <?php selected( $rc_s['button_position'], 'left' ); ?>><?php esc_html_e( 'Left', 'revolter-cookies' ); ?></option>
						<option value="right" <?php selected( $rc_s['button_position'], 'right' ); ?>><?php esc_html_e( 'Right', 'revolter-cookies' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rc-policy"><?php esc_html_e( 'Cookie policy page', 'revolter-cookies' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_pages(
						array(
							'name'              => esc_attr( $rc_name ) . '[policy_page_id]',
							'id'                => 'rc-policy',
							'selected'          => (int) $rc_s['policy_page_id'],
							'show_option_none'  => esc_html__( 'Detect automatically', 'revolter-cookies' ),
							'option_none_value' => '0',
						)
					);
					?>
					<?php if ( '' !== $view['detected'] ) : ?>
						<p class="description">
							<?php
							/* translators: %s: page title. */
							echo esc_html( sprintf( __( 'Auto-detected: %s', 'revolter-cookies' ), $view['detected'] ) );
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<details class="rc-advanced">
			<summary><?php esc_html_e( 'Advanced', 'revolter-cookies' ); ?></summary>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rc-gtm"><?php esc_html_e( 'Google Tag Manager category', 'revolter-cookies' ); ?></label></th>
					<td>
						<select id="rc-gtm" name="<?php echo esc_attr( $rc_name ); ?>[gtm_category]">
							<?php foreach ( $view['gtm_cats'] as $rc_cat ) : ?>
								<option value="<?php echo esc_attr( $rc_cat ); ?>" <?php selected( $rc_s['gtm_category'], $rc_cat ); ?>><?php echo esc_html( Texts::category_label( $rc_cat ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rc-rules"><?php esc_html_e( 'Manual blocking rules', 'revolter-cookies' ); ?></label></th>
					<td>
						<textarea id="rc-rules" name="<?php echo esc_attr( $rc_name ); ?>[manual_rules]" rows="6" class="large-text code"><?php echo esc_textarea( $view['rules'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One rule per line: pattern | category. Example: hotjar.com | statistics', 'revolter-cookies' ); ?></p>
					</td>
				</tr>
			</table>
		</details>
		<?php submit_button(); ?>
	</form>
</div>
