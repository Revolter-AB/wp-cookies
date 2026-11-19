<?php
/**
 * Overview admin template.
 *
 * @package RevolterCookies
 *
 * @var array $view Variables from Overview_Page::render().
 */

use RevolterCookies\Admin\Menu;
use RevolterCookies\Duration;
use RevolterCookies\Item_Repository;
use RevolterCookies\Texts;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from Menu::render(), so these are locals.

$rc_action   = admin_url( 'admin-post.php' );
$rc_form_cat = static function ( string $name, string $selected, string $form = '', bool $with_unclassified = true ): void {
	$attr = '' !== $form ? ' form="' . esc_attr( $form ) . '"' : '';
	echo '<select name="' . esc_attr( $name ) . '"' . $attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	foreach ( Item_Repository::CATEGORIES as $rc_cat ) {
		if ( ! $with_unclassified && 'unclassified' === $rc_cat ) {
			continue;
		}
		echo '<option value="' . esc_attr( $rc_cat ) . '"' . selected( $selected, $rc_cat, false ) . '>' . esc_html( Texts::category_label( $rc_cat ) ) . '</option>';
	}
	echo '</select>';
};
?>
<div class="wrap rc-admin">
	<h1><?php esc_html_e( 'Cookies', 'revolter-cookies' ); ?></h1>

	<?php if ( empty( $view['library_ok'] ) ) : ?>
		<div class="rc-inline-message rc-inline-error" role="alert"><?php esc_html_e( 'Cookie library could not be loaded; blocking of known services is disabled.', 'revolter-cookies' ); ?></div>
	<?php endif; ?>
	<?php if ( $view['updated'] ) : ?>
		<div class="rc-inline-message rc-inline-success" role="status"><?php esc_html_e( 'Saved.', 'revolter-cookies' ); ?></div>
	<?php endif; ?>
	<?php if ( 'exists' === $view['error'] ) : ?>
		<div class="rc-inline-message rc-inline-error" role="alert"><?php esc_html_e( 'An item with that name already exists.', 'revolter-cookies' ); ?></div>
	<?php endif; ?>

	<?php if ( ! $view['has_scan'] ) : ?>
		<div class="card rc-first-run">
			<h2><?php esc_html_e( 'Scan your website', 'revolter-cookies' ); ?></h2>
			<p><?php esc_html_e( 'The scan visits your pages, finds the cookies and scripts they use and builds your cookie declaration.', 'revolter-cookies' ); ?></p>
			<p><button type="button" class="button button-primary button-hero" data-rc-scan><?php esc_html_e( 'Scan your website', 'revolter-cookies' ); ?></button></p>
			<div id="rc-scan-progress" hidden></div>
		</div>
	<?php else : ?>
		<div class="rc-header">
			<button type="button" class="button button-primary" data-rc-scan><?php esc_html_e( 'Rescan website', 'revolter-cookies' ); ?></button>
			<span class="rc-last-scan">
				<?php
				if ( $view['scan_failed'] ) {
					esc_html_e( 'Last scan failed', 'revolter-cookies' );
				} else {
					/* translators: 1: date and time, 2: number of pages. */
					echo esc_html( sprintf( _n( 'Last scan: %1$s · %2$d page', 'Last scan: %1$s · %2$d pages', $view['pages'], 'revolter-cookies' ), $view['when'], $view['pages'] ) );
					if ( $view['failed'] > 0 ) {
						/* translators: %d: number of pages that failed. */
						echo esc_html( ' · ' . sprintf( __( '%d failed', 'revolter-cookies' ), $view['failed'] ) );
					}
				}
				?>
			</span>
		</div>
		<div id="rc-scan-progress" class="card" hidden></div>

		<?php if ( $view['rescan'] ) : ?>
			<p class="rc-rescan"><?php esc_html_e( 'Rescan recommended: plugins or the theme have changed since the last scan.', 'revolter-cookies' ); ?></p>
		<?php endif; ?>

		<?php if ( ! $view['scan_failed'] ) : ?>
			<div class="rc-tiles">
				<div class="card rc-tile"><span class="rc-tile-n"><?php echo esc_html( (string) $view['declared'] ); ?></span><span><?php esc_html_e( 'Cookies declared', 'revolter-cookies' ); ?></span></div>
				<div class="card rc-tile"><span class="rc-tile-n"><?php echo esc_html( (string) $view['needs_review'] ); ?></span><span><?php esc_html_e( 'Needs review', 'revolter-cookies' ); ?></span></div>
				<div class="card rc-tile"><span class="rc-tile-n"><?php echo esc_html( (string) count( $view['blocked'] ) ); ?></span><span><?php esc_html_e( 'Scripts blocked until consent', 'revolter-cookies' ); ?></span></div>
				<div class="card rc-tile"><span class="rc-tile-n"><?php echo esc_html( (string) count( $view['leaks'] ) ); ?></span><span><?php esc_html_e( 'Set before consent', 'revolter-cookies' ); ?></span></div>
			</div>
		<?php endif; ?>

		<?php if ( $view['needs_review'] > 0 ) : ?>
			<div class="notice-warning notice inline rc-review-notice">
				<p>
					<?php
					/* translators: %d: number of items that need review. */
					echo esc_html( sprintf( _n( '%d item needs your review. Choose a category for it below.', '%d items need your review. Choose a category for each below.', $view['needs_review'], 'revolter-cookies' ), $view['needs_review'] ) );
					?>
				</p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $view['items'] ) : ?>
		<h2><?php esc_html_e( 'Cookies and services', 'revolter-cookies' ); ?></h2>
		<table class="widefat striped rc-items">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'revolter-cookies' ); ?></th>
					<th><?php esc_html_e( 'Provider', 'revolter-cookies' ); ?></th>
					<th><?php esc_html_e( 'Category', 'revolter-cookies' ); ?></th>
					<th><?php esc_html_e( 'Purpose', 'revolter-cookies' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'revolter-cookies' ); ?></th>
					<th><?php esc_html_e( 'Source', 'revolter-cookies' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $view['items'] as $rc_row ) :
				$rc_form    = 'rc-item-' . (int) $rc_row['id'];
				$rc_purpose = is_array( $rc_row['purpose_override'] ) ? $rc_row['purpose_override'] : array();
				$rc_pages   = is_array( $rc_row['pages'] ) ? $rc_row['pages'] : array();
				$rc_leak    = isset( $view['leaks'][ $rc_row['name'] ] ) && 'domain' !== $rc_row['type'];
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $rc_row['name'] ); ?></strong>
						<?php if ( 'cookie' !== $rc_row['type'] ) : ?>
							<span class="rc-type"><?php echo esc_html( 'domain' === $rc_row['type'] ? __( 'Domain', 'revolter-cookies' ) : __( 'Storage', 'revolter-cookies' ) ); ?></span>
						<?php endif; ?>
						<?php if ( $rc_leak ) : ?>
							<span class="dashicons dashicons-warning rc-leak" title="<?php esc_attr_e( 'Set before consent', 'revolter-cookies' ); ?>"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Set before consent', 'revolter-cookies' ); ?></span>
							<?php if ( ! empty( $view['leak_page'][ $rc_row['name'] ] ) ) : ?>
								<br><small><?php echo esc_html( $view['leak_page'][ $rc_row['name'] ] ); ?></small>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( 'domain' === $rc_row['type'] && $rc_pages ) : ?>
							<br><small>
								<?php
								/* translators: %s: list of page URLs. */
								echo esc_html( sprintf( __( 'Found on: %s', 'revolter-cookies' ), implode( ', ', array_slice( $rc_pages, 0, 3 ) ) ) );
								?>
							</small>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $rc_row['provider'] ); ?></td>
					<td>
						<form id="<?php echo esc_attr( $rc_form ); ?>" method="post" action="<?php echo esc_url( $rc_action ); ?>" class="rc-inline-form">
							<input type="hidden" name="action" value="revolter_cookies_save_item">
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) $rc_row['id'] ); ?>">
							<?php wp_nonce_field( 'revolter_cookies_save_item', '_wpnonce', true ); ?>
							<?php $rc_form_cat( 'category', (string) $rc_row['category'] ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Save', 'revolter-cookies' ); ?></button>
						</form>
					</td>
					<td>
						<?php if ( 'domain' !== $rc_row['type'] ) : ?>
							<details>
								<summary><?php esc_html_e( 'Edit purpose', 'revolter-cookies' ); ?></summary>
								<p><label><?php esc_html_e( 'Purpose (Swedish)', 'revolter-cookies' ); ?><br>
									<textarea name="purpose_sv" form="<?php echo esc_attr( $rc_form ); ?>" rows="2" class="large-text"><?php echo esc_textarea( isset( $rc_purpose['sv'] ) ? $rc_purpose['sv'] : '' ); ?></textarea></label></p>
								<p><label><?php esc_html_e( 'Purpose (English)', 'revolter-cookies' ); ?><br>
									<textarea name="purpose_en" form="<?php echo esc_attr( $rc_form ); ?>" rows="2" class="large-text"><?php echo esc_textarea( isset( $rc_purpose['en'] ) ? $rc_purpose['en'] : '' ); ?></textarea></label></p>
								<p class="description"><?php esc_html_e( 'Leave both empty to use the standard description.', 'revolter-cookies' ); ?></p>
							</details>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( Duration::label( Duration::decode( $rc_row['duration'] ) ) ); ?></td>
					<td>
						<?php echo esc_html( ucfirst( (string) $rc_row['source'] ) ); ?>
						<?php if ( 'manual' === $rc_row['source'] ) : ?>
							<form method="post" action="<?php echo esc_url( $rc_action ); ?>" class="rc-inline-form">
								<input type="hidden" name="action" value="revolter_cookies_delete_item">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $rc_row['id'] ); ?>">
								<?php wp_nonce_field( 'revolter_cookies_delete_item', '_wpnonce', true ); ?>
								<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'revolter-cookies' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<div class="rc-columns">
		<div class="card">
			<h2><?php esc_html_e( 'Add cookie manually', 'revolter-cookies' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $rc_action ); ?>">
				<input type="hidden" name="action" value="revolter_cookies_add_item">
				<?php wp_nonce_field( 'revolter_cookies_add_item', '_wpnonce', true ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="rc-add-name"><?php esc_html_e( 'Name', 'revolter-cookies' ); ?></label></th>
						<td><input type="text" id="rc-add-name" name="name" class="regular-text" required></td></tr>
					<tr><th scope="row"><label for="rc-add-type"><?php esc_html_e( 'Type', 'revolter-cookies' ); ?></label></th>
						<td><select id="rc-add-type" name="type">
							<option value="cookie"><?php esc_html_e( 'Cookie', 'revolter-cookies' ); ?></option>
							<option value="storage"><?php esc_html_e( 'Storage', 'revolter-cookies' ); ?></option>
						</select></td></tr>
					<tr><th scope="row"><label for="rc-add-provider"><?php esc_html_e( 'Provider', 'revolter-cookies' ); ?></label></th>
						<td><input type="text" id="rc-add-provider" name="provider" class="regular-text"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Category', 'revolter-cookies' ); ?></th>
						<td><?php $rc_form_cat( 'category', 'necessary', '', false ); ?></td></tr>
					<tr><th scope="row"><label for="rc-add-duration-n"><?php esc_html_e( 'Duration', 'revolter-cookies' ); ?></label></th>
						<td>
							<input type="number" id="rc-add-duration-n" name="duration_n" min="1" class="small-text">
							<select name="duration_unit" aria-label="<?php esc_attr_e( 'Duration unit', 'revolter-cookies' ); ?>">
								<option value="session"><?php esc_html_e( 'Session', 'revolter-cookies' ); ?></option>
								<option value="persistent"><?php esc_html_e( 'Persistent', 'revolter-cookies' ); ?></option>
								<option value="minute"><?php esc_html_e( 'minutes', 'revolter-cookies' ); ?></option>
								<option value="hour"><?php esc_html_e( 'hours', 'revolter-cookies' ); ?></option>
								<option value="day"><?php esc_html_e( 'days', 'revolter-cookies' ); ?></option>
								<option value="month"><?php esc_html_e( 'months', 'revolter-cookies' ); ?></option>
								<option value="year"><?php esc_html_e( 'years', 'revolter-cookies' ); ?></option>
							</select>
						</td></tr>
					<tr><th scope="row"><label for="rc-add-sv"><?php esc_html_e( 'Purpose (Swedish)', 'revolter-cookies' ); ?></label></th>
						<td><textarea id="rc-add-sv" name="purpose_sv" rows="2" class="large-text"></textarea></td></tr>
					<tr><th scope="row"><label for="rc-add-en"><?php esc_html_e( 'Purpose (English)', 'revolter-cookies' ); ?></label></th>
						<td><textarea id="rc-add-en" name="purpose_en" rows="2" class="large-text"></textarea></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Third party', 'revolter-cookies' ); ?></th>
						<td><label><input type="checkbox" name="third_party" value="1"> <?php esc_html_e( 'Set by a third party', 'revolter-cookies' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Add cookie', 'revolter-cookies' ) ); ?>
			</form>
		</div>

		<div class="rc-side">
			<div class="card">
				<h2><?php esc_html_e( 'Blocked until consent', 'revolter-cookies' ); ?></h2>
				<?php if ( $view['blocked'] ) : ?>
					<ul class="rc-list">
						<?php foreach ( $view['blocked'] as $rc_label ) : ?>
							<li><?php echo esc_html( $rc_label ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php esc_html_e( 'Nothing is blocked yet.', 'revolter-cookies' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="card">
				<h2><?php esc_html_e( 'Languages', 'revolter-cookies' ); ?></h2>
				<p>
					<?php
					/* translators: %s: name of the multilingual system, for example wpml. */
					echo esc_html( sprintf( __( 'Language source: %s', 'revolter-cookies' ), $view['lang_source'] ) );
					?>
				</p>
				<ul class="rc-list">
					<?php foreach ( $view['languages'] as $rc_code => $rc_name ) : ?>
						<li><?php echo esc_html( $rc_name . ' (' . $rc_code . ')' ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</div>
</div>
