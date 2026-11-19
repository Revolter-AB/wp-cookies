<?php
/**
 * Consent log template.
 *
 * @package RevolterCookies
 *
 * @var array $view Variables from Log_Page::render().
 */

use RevolterCookies\Admin\Log_Page;
use RevolterCookies\Admin\Menu;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from Menu::render(), so these are locals.
?>
<div class="wrap rc-admin">
	<h1><?php esc_html_e( 'Consent log', 'revolter-cookies' ); ?></h1>

	<form method="get" class="rc-search">
		<input type="hidden" name="page" value="<?php echo esc_attr( Menu::LOG ); ?>">
		<label for="rc-log-search"><?php esc_html_e( 'Consent ID', 'revolter-cookies' ); ?></label>
		<input type="search" id="rc-log-search" name="s" value="<?php echo esc_attr( $view['search'] ); ?>" placeholder="<?php esc_attr_e( 'Start of the consent ID', 'revolter-cookies' ); ?>" pattern="[0-9a-fA-F\-]*">
		<button type="submit" class="button"><?php esc_html_e( 'Search', 'revolter-cookies' ); ?></button>
	</form>

	<table class="widefat striped rc-log">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Consent ID', 'revolter-cookies' ); ?></th>
				<th><?php esc_html_e( 'Date (UTC)', 'revolter-cookies' ); ?></th>
				<th><?php esc_html_e( 'Categories', 'revolter-cookies' ); ?></th>
				<th><?php esc_html_e( 'Policy version', 'revolter-cookies' ); ?></th>
				<th><?php esc_html_e( 'Action', 'revolter-cookies' ); ?></th>
				<th><?php esc_html_e( 'Language', 'revolter-cookies' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Export', 'revolter-cookies' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $view['rows'] ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'No entries.', 'revolter-cookies' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $view['rows'] as $rc_row ) : ?>
			<tr>
				<td><code><?php echo esc_html( $rc_row['consent_id'] ); ?></code></td>
				<td><?php echo esc_html( $rc_row['created_at'] ); ?></td>
				<td><?php echo esc_html( $rc_row['categories'] ); ?></td>
				<td><?php echo esc_html( (string) $rc_row['policy_version'] ); ?></td>
				<td><?php echo esc_html( $rc_row['action'] ); ?></td>
				<td><?php echo esc_html( $rc_row['language'] ); ?></td>
				<td><a href="<?php echo esc_url( Log_Page::record_url( (int) $rc_row['id'] ) ); ?>" aria-label="<?php esc_attr_e( 'Export this entry as CSV', 'revolter-cookies' ); ?>"><?php esc_html_e( 'CSV', 'revolter-cookies' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<div class="card">
		<h2><?php esc_html_e( 'Export as CSV', 'revolter-cookies' ); ?></h2>
		<?php if ( ! empty( $view['truncated'] ) ) : ?>
			<div class="rc-inline-message rc-inline-error" role="alert">
				<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %1$s: maximum number of rows in one export. */
						__( 'This range has more than %1$s entries. Choose a shorter range, or download the first %1$s.', 'revolter-cookies' ),
						number_format_i18n( (int) $view['limit'] )
					)
				);
				?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="revolter_cookies_export_log">
					<input type="hidden" name="truncate" value="1">
					<input type="hidden" name="from" value="<?php echo esc_attr( $view['from'] ); ?>">
					<input type="hidden" name="to" value="<?php echo esc_attr( $view['to'] ); ?>">
					<?php wp_nonce_field( 'revolter_cookies_export_log', '_wpnonce', true, true ); ?>
					<button type="submit" class="button">
					<?php
					/* translators: %s: maximum number of rows in one export. */
					echo esc_html( sprintf( __( 'Download the first %s', 'revolter-cookies' ), number_format_i18n( (int) $view['limit'] ) ) );
					?>
					</button>
				</form>
			</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="revolter_cookies_export_log">
			<?php wp_nonce_field( 'revolter_cookies_export_log', '_wpnonce', true ); ?>
			<label for="rc-from"><?php esc_html_e( 'From', 'revolter-cookies' ); ?></label>
			<input type="date" id="rc-from" name="from" value="<?php echo esc_attr( $view['from'] ); ?>">
			<label for="rc-to"><?php esc_html_e( 'To', 'revolter-cookies' ); ?></label>
			<input type="date" id="rc-to" name="to" value="<?php echo esc_attr( $view['to'] ); ?>">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Download CSV', 'revolter-cookies' ); ?></button>
		</form>
	</div>
</div>
