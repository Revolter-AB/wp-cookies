<?php
/**
 * Company details template.
 *
 * @package RevolterCookies
 *
 * @var array $view Variables from Settings_Pages::render_company().
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from Menu::render(), so these are locals.

$rc_s    = $view['settings'];
$rc_name = $view['option'];
?>
<div class="wrap rc-admin">
	<h1><?php esc_html_e( 'Company details', 'revolter-cookies' ); ?></h1>
	<?php if ( $view['updated'] ) : ?>
		<div class="rc-inline-message rc-inline-success" role="status"><?php esc_html_e( 'Saved.', 'revolter-cookies' ); ?></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'These details appear in your cookie declaration.', 'revolter-cookies' ); ?></p>
	<form method="post" action="options.php">
		<?php settings_fields( $view['group'] ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rc-company"><?php esc_html_e( 'Company name', 'revolter-cookies' ); ?></label></th>
				<td><input type="text" id="rc-company" class="regular-text" name="<?php echo esc_attr( $rc_name ); ?>[company_name]" value="<?php echo esc_attr( $rc_s['company_name'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="rc-org"><?php esc_html_e( 'Organisation number', 'revolter-cookies' ); ?></label></th>
				<td><input type="text" id="rc-org" class="regular-text" name="<?php echo esc_attr( $rc_name ); ?>[org_number]" value="<?php echo esc_attr( $rc_s['org_number'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="rc-email"><?php esc_html_e( 'Contact email', 'revolter-cookies' ); ?></label></th>
				<td><input type="email" id="rc-email" class="regular-text" name="<?php echo esc_attr( $rc_name ); ?>[contact_email]" value="<?php echo esc_attr( $rc_s['contact_email'] ); ?>"></td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
