<?php
/**
 * Tabbed settings page (wp-admin).
 *
 * Lives under the Quotes menu. One options store, sane defaults, everything
 * client-specific configured here. Save is guarded by a nonce + the
 * `manage_woocommerce` capability and every value is sanitised on the way in.
 *
 * STAGE 0 ships the framework + the scalar tabs (General / Anti-spam /
 * Analytics / Advanced) fully working. The Fields editor (add/remove/reorder)
 * lands in Stage 3 and the Emails tab links out to WooCommerce email settings
 * once the WC_Email classes are registered in Stage 4.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Settings_Page
 */
class RAQ_Settings_Page {

	const CAP        = 'manage_woocommerce';
	const NONCE_ACT  = 'raq_save_settings';
	const NONCE_NAME = 'raq_settings_nonce';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
		add_action( 'admin_post_raq_save_settings', array( __CLASS__, 'handle_save' ) );
		add_filter( 'plugin_action_links_' . RAQ_PLUGIN_BASENAME, array( __CLASS__, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the field-editor script on our settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( isset( $_GET['page'] ) && 'raq-settings' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page routing only.
			wp_enqueue_script(
				'raq-admin',
				RAQ_PLUGIN_URL . 'assets/js/raq-admin.js',
				array( 'jquery', 'jquery-ui-sortable' ),
				RAQ_VERSION,
				true
			);
		}
	}

	/**
	 * The field types an admin can choose.
	 *
	 * @return array slug => label
	 */
	public static function field_types() {
		return array(
			'text'     => __( 'Text', 'request-a-quote-for-woocommerce' ),
			'email'    => __( 'Email', 'request-a-quote-for-woocommerce' ),
			'tel'      => __( 'Phone (with country code)', 'request-a-quote-for-woocommerce' ),
			'country'  => __( 'Country', 'request-a-quote-for-woocommerce' ),
			'textarea' => __( 'Paragraph', 'request-a-quote-for-woocommerce' ),
			'number'   => __( 'Number', 'request-a-quote-for-woocommerce' ),
		);
	}

	/**
	 * Add a "Settings" link to the plugin row on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url      = admin_url( 'edit.php?post_type=' . RAQ_CPT::POST_TYPE . '&page=raq-settings' );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'request-a-quote-for-woocommerce' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Add the settings submenu under the Quotes CPT.
	 */
	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . RAQ_CPT::POST_TYPE,
			__( 'Quote Settings', 'request-a-quote-for-woocommerce' ),
			__( 'Settings', 'request-a-quote-for-woocommerce' ),
			self::CAP,
			'raq-settings',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The tabs.
	 *
	 * @return array
	 */
	protected static function tabs() {
		// One tab per concern: setup+appearance / everything about the form /
		// notifications / tracking / rarely-touched.
		return array(
			'general'   => __( 'General', 'request-a-quote-for-woocommerce' ),
			'form'      => __( 'Form', 'request-a-quote-for-woocommerce' ),
			'emails'    => __( 'Emails', 'request-a-quote-for-woocommerce' ),
			'analytics' => __( 'Analytics', 'request-a-quote-for-woocommerce' ),
			'advanced'  => __( 'Advanced', 'request-a-quote-for-woocommerce' ),
		);
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'request-a-quote-for-woocommerce' ) );
		}

		$tabs       = self::tabs();
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab is display-only navigation.
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'general';
		}

		$s        = RAQ_Settings::all();
		$base_url = admin_url( 'edit.php?post_type=' . RAQ_CPT::POST_TYPE . '&page=raq-settings' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Request a Quote', 'request-a-quote-for-woocommerce' ); ?></h1>

			<?php if ( isset( $_GET['raq_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'request-a-quote-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base_url ) ); ?>"
						class="nav-tab <?php echo $active_tab === $key ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="raq_save_settings">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>">
				<?php wp_nonce_field( self::NONCE_ACT, self::NONCE_NAME ); ?>

				<?php
				switch ( $active_tab ) {
					case 'general':
						self::tab_general( $s );
						break;
					case 'form':
						self::tab_form( $s );
						break;
					case 'emails':
						self::tab_emails( $s );
						break;
					case 'analytics':
						self::tab_analytics( $s );
						break;
					case 'advanced':
						self::tab_advanced( $s );
						break;
				}
				?>

				<?php submit_button( __( 'Save changes', 'request-a-quote-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Tab renderers
	 * ------------------------------------------------------------------ */

	/**
	 * General tab.
	 *
	 * @param array $s Settings.
	 */
	protected static function tab_general( $s ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Master Switch', 'request-a-quote-for-woocommerce' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="master_switch" value="1" <?php checked( ! empty( $s['master_switch'] ) ); ?>>
						<?php esc_html_e( 'Convert this store from selling to quoting', 'request-a-quote-for-woocommerce' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Non-destructive. Turn off (or deactivate the plugin) and the store returns exactly as it was.', 'request-a-quote-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="raq_add_to_quote_label"><?php esc_html_e( 'Button label', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td><input type="text" id="raq_add_to_quote_label" class="regular-text" name="add_to_quote_label" value="<?php echo esc_attr( $s['add_to_quote_label'] ); ?>"></td>
			</tr>
			<tr class="raq-when-page">
				<th scope="row"><?php esc_html_e( 'Quote Page', 'request-a-quote-for-woocommerce' ); ?></th>
				<td>
					<p class="description">
						<?php echo esc_html( sprintf( /* translators: %s: shortcode. */ __( 'Create a page and add the %s shortcode to it. The plugin detects it automatically - no page selection needed.', 'request-a-quote-for-woocommerce' ), '[raq_quote_form]' ) ); ?>
						<?php
						$detected = (int) get_option( 'raq_quote_page_id', 0 );
						if ( $detected && get_post( $detected ) ) {
							echo '<br>' . esc_html__( 'Detected:', 'request-a-quote-for-woocommerce' ) . ' <a href="' . esc_url( get_permalink( $detected ) ) . '" target="_blank">' . esc_html( get_the_title( $detected ) ) . '</a>';
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Submission', 'request-a-quote-for-woocommerce' ); ?></th>
				<td>
					<label><input type="radio" name="submit_mode" value="page" <?php checked( 'page', $s['submit_mode'] ); ?>> <?php esc_html_e( 'Route to the Quote Page (full form)', 'request-a-quote-for-woocommerce' ); ?></label><br>
					<label><input type="radio" name="submit_mode" value="drawer" <?php checked( 'drawer', $s['submit_mode'] ); ?>> <?php esc_html_e( 'Submit inside the drawer (short form)', 'request-a-quote-for-woocommerce' ); ?></label>
				</td>
			</tr>
			<tr class="raq-when-page">
				<th scope="row"><label for="raq_page_title"><?php esc_html_e( 'Quote Page title', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td><input type="text" id="raq_page_title" class="regular-text" name="page_title" value="<?php echo esc_attr( $s['page_title'] ); ?>"></td>
			</tr>
			<tr class="raq-when-page">
				<th scope="row"><label for="raq_page_intro"><?php esc_html_e( 'Quote Page description', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td><textarea id="raq_page_intro" class="large-text" rows="3" name="page_intro"><?php echo esc_textarea( $s['page_intro'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Shown under the title on the Quote Page. Leave blank to hide.', 'request-a-quote-for-woocommerce' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Login', 'request-a-quote-for-woocommerce' ); ?></th>
				<td>
					<label><input type="checkbox" name="require_login" value="1" <?php checked( ! empty( $s['require_login'] ) ); ?>> <?php esc_html_e( 'Require login to request a quote', 'request-a-quote-for-woocommerce' ); ?></label>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Appearance', 'request-a-quote-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Product button', 'request-a-quote-for-woocommerce' ); ?></th>
				<td>
					<label><input type="checkbox" name="auto_button" value="1" <?php checked( ! empty( $s['auto_button'] ) ); ?>> <?php esc_html_e( 'Show the Add-to-Quote button automatically on product pages', 'request-a-quote-for-woocommerce' ); ?></label>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: shortcode. */ __( 'Turn off to place the button yourself with the %s shortcode (e.g. inside a Divi module) for exact positioning.', 'request-a-quote-for-woocommerce' ), '[raq_add_to_quote]' ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Quantity selector', 'request-a-quote-for-woocommerce' ); ?></th>
				<td><label><input type="checkbox" name="qty_selector" value="1" <?php checked( ! empty( $s['qty_selector'] ) ); ?>> <?php esc_html_e( 'Show the quantity selector on product pages', 'request-a-quote-for-woocommerce' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Button colours', 'request-a-quote-for-woocommerce' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Background', 'request-a-quote-for-woocommerce' ); ?> <input type="color" name="btn_bg" value="<?php echo esc_attr( $s['btn_bg'] ); ?>"></label>
					&nbsp;&nbsp;
					<label><?php esc_html_e( 'Text', 'request-a-quote-for-woocommerce' ); ?> <input type="color" name="btn_text" value="<?php echo esc_attr( $s['btn_text'] ); ?>"></label>
					<p class="description"><?php esc_html_e( 'Applies to the Add-to-Quote buttons and the submit button. Default: black background, white text.', 'request-a-quote-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="raq_btn_size"><?php esc_html_e( 'Button size', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td>
					<select id="raq_btn_size" name="btn_size">
						<?php
						$sizes = array(
							'small'  => __( 'Small', 'request-a-quote-for-woocommerce' ),
							'medium' => __( 'Medium', 'request-a-quote-for-woocommerce' ),
							'large'  => __( 'Large', 'request-a-quote-for-woocommerce' ),
						);
						foreach ( $sizes as $val => $lab ) {
							echo '<option value="' . esc_attr( $val ) . '" ' . selected( $s['btn_size'], $val, false ) . '>' . esc_html( $lab ) . '</option>';
						}
						?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="raq_fab_position"><?php esc_html_e( 'Floating quote button', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td>
					<select id="raq_fab_position" name="fab_position">
						<?php
						$positions = array(
							'bottom-right' => __( 'Bottom right', 'request-a-quote-for-woocommerce' ),
							'bottom-left'  => __( 'Bottom left', 'request-a-quote-for-woocommerce' ),
							'top-right'    => __( 'Top right', 'request-a-quote-for-woocommerce' ),
							'top-left'     => __( 'Top left', 'request-a-quote-for-woocommerce' ),
							'hidden'       => __( 'Hidden (use the header/menu button instead)', 'request-a-quote-for-woocommerce' ),
						);
						foreach ( $positions as $val => $lab ) {
							echo '<option value="' . esc_attr( $val ) . '" ' . selected( $s['fab_position'], $val, false ) . '>' . esc_html( $lab ) . '</option>';
						}
						?>
					</select>
					<p class="description"><?php esc_html_e( 'Move or hide the floating quote button so it does not overlap other floating icons.', 'request-a-quote-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="raq_custom_css"><?php esc_html_e( 'Custom CSS', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td>
					<textarea id="raq_custom_css" name="custom_css" rows="6" class="large-text code" placeholder=".raq-add-to-quote { ... }"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Added to the front-end. Useful selectors: .raq-add-to-quote, .single_add_to_cart_button, .raq-drawer, .raq-quote-form-full, .raq-fab.', 'request-a-quote-for-woocommerce' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Form tab: everything about the request form in one place -
	 * fields editor + attachments + anti-spam.
	 *
	 * @param array $s Settings.
	 */
	protected static function tab_form( $s ) {
		self::tab_fields( $s );
		?>
		<h2 class="title"><?php esc_html_e( 'Attachments', 'request-a-quote-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'File upload', 'request-a-quote-for-woocommerce' ); ?></th>
				<td>
					<label><input type="checkbox" name="attachments_enabled" value="1" <?php checked( ! empty( $s['attachments_enabled'] ) ); ?>> <?php esc_html_e( 'Allow file attachments on the quote form', 'request-a-quote-for-woocommerce' ); ?></label>
					<p>
						<label><?php esc_html_e( 'Allowed types', 'request-a-quote-for-woocommerce' ); ?> <input type="text" class="regular-text" name="attachments_types" value="<?php echo esc_attr( $s['attachments_types'] ); ?>"></label>
					</p>
					<p>
						<label><?php esc_html_e( 'Max size (MB)', 'request-a-quote-for-woocommerce' ); ?> <input type="number" min="1" class="small-text" name="attachments_max_mb" value="<?php echo esc_attr( $s['attachments_max_mb'] ); ?>"></label>
					</p>
				</td>
			</tr>
		</table>
		<?php
		self::tab_antispam( $s );
	}

	/**
	 * The fields editor (part of the Form tab).
	 *
	 * @param array $s Settings.
	 */
	protected static function tab_fields( $s ) {
		?>
		<h2 class="title"><?php esc_html_e( 'Form fields', 'request-a-quote-for-woocommerce' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Build the quote request form. Drag the handle to reorder, edit labels, mark fields required, add or remove fields. The key is auto-generated from the label if left blank.', 'request-a-quote-for-woocommerce' ); ?></p>

		<table class="widefat raq-fields" id="raq-fields" style="max-width:820px">
			<thead>
				<tr>
					<th style="width:24px"></th>
					<th><?php esc_html_e( 'Label', 'request-a-quote-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Key', 'request-a-quote-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Type', 'request-a-quote-for-woocommerce' ); ?></th>
					<th style="width:80px"><?php esc_html_e( 'Required', 'request-a-quote-for-woocommerce' ); ?></th>
					<th style="width:60px"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$i = 0;
				foreach ( (array) $s['fields'] as $field ) {
					echo self::field_row( (string) $i, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method.
					$i++;
				}
				?>
			</tbody>
		</table>

		<p><button type="button" class="button" id="raq-add-field"><?php esc_html_e( '+ Add field', 'request-a-quote-for-woocommerce' ); ?></button></p>

		<script type="text/html" id="raq-field-tpl">
			<?php echo self::field_row( '__i__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method. ?>
		</script>
		<?php
	}

	/**
	 * Render a single editable field row (used for existing rows + the JS
	 * template, hence the string index).
	 *
	 * @param string $i     Row index (or the '__i__' template placeholder).
	 * @param array  $field Field config (empty for a new row).
	 * @return string
	 */
	protected static function field_row( $i, $field ) {
		$label    = isset( $field['label'] ) ? $field['label'] : '';
		$key      = isset( $field['key'] ) ? $field['key'] : '';
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';
		$required = ! empty( $field['required'] );
		$name     = 'raq_fields[' . $i . ']';

		$types = '';
		foreach ( self::field_types() as $slug => $tlabel ) {
			$types .= '<option value="' . esc_attr( $slug ) . '" ' . selected( $type, $slug, false ) . '>' . esc_html( $tlabel ) . '</option>';
		}

		return sprintf(
			'<tr>
				<td class="raq-fh" style="cursor:move;text-align:center;color:#a7aaad">&#8942;&#8942;</td>
				<td><input type="text" name="%1$s[label]" value="%2$s" class="regular-text"></td>
				<td><input type="text" name="%1$s[key]" value="%3$s" placeholder="auto"></td>
				<td><select name="%1$s[type]">%4$s</select></td>
				<td style="text-align:center"><input type="checkbox" name="%1$s[required]" value="1" %5$s></td>
				<td><a href="#" class="raq-remove-field" style="color:#b32d2e">%6$s</a></td>
			</tr>',
			esc_attr( $name ),
			esc_attr( $label ),
			esc_attr( $key ),
			$types, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr/esc_html above.
			checked( $required, true, false ),
			esc_html__( 'Remove', 'request-a-quote-for-woocommerce' )
		);
	}

	/**
	 * Emails tab: live status of both quote emails + the default sales
	 * recipient. Content editing stays in the WooCommerce email framework
	 * (subject/heading/template), one click away per email.
	 *
	 * @param array $s Settings.
	 */
	protected static function tab_emails( $s ) {
		$rows = array();
		if ( function_exists( 'WC' ) && is_callable( array( WC(), 'mailer' ) ) ) {
			$emails = WC()->mailer()->get_emails();
			foreach ( array( 'RAQ_Email_Customer', 'RAQ_Email_Admin' ) as $class ) {
				if ( isset( $emails[ $class ] ) ) {
					$email  = $emails[ $class ];
					$rows[] = array(
						'title'     => $email->get_title(),
						'desc'      => $email->get_description(),
						'enabled'   => $email->is_enabled(),
						'recipient' => 'RAQ_Email_Admin' === $class ? $email->get_recipient() : __( 'The customer', 'request-a-quote-for-woocommerce' ),
						'subject'   => $email->get_subject(),
						'edit'      => admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( $class ) ),
					);
				}
			}
		}
		?>
		<h2 class="title"><?php esc_html_e( 'Quote notifications', 'request-a-quote-for-woocommerce' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Sent automatically when a quote is submitted. Subject, heading and template are editable in the WooCommerce email framework.', 'request-a-quote-for-woocommerce' ); ?></p>

		<?php if ( ! $rows ) : ?>
			<div class="notice notice-warning inline" style="margin-top:10px"><p><?php esc_html_e( 'The quote emails register when the Master Switch is ON (General tab). Turn it on to see and edit them here.', 'request-a-quote-for-woocommerce' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $rows ) : ?>
			<table class="widefat striped" style="max-width:900px;margin-top:10px">
				<thead><tr>
					<th><?php esc_html_e( 'Email', 'request-a-quote-for-woocommerce' ); ?></th>
					<th style="width:90px"><?php esc_html_e( 'Status', 'request-a-quote-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Recipient', 'request-a-quote-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'request-a-quote-for-woocommerce' ); ?></th>
					<th style="width:90px"></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $row['title'] ); ?></strong><br><small><?php echo esc_html( $row['desc'] ); ?></small></td>
						<td>
							<?php if ( $row['enabled'] ) : ?>
								<span style="color:#00844a;font-weight:600"><?php esc_html_e( 'Enabled', 'request-a-quote-for-woocommerce' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;font-weight:600"><?php esc_html_e( 'Disabled', 'request-a-quote-for-woocommerce' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['recipient'] ); ?></td>
						<td><?php echo esc_html( $row['subject'] ); ?></td>
						<td><a class="button" href="<?php echo esc_url( $row['edit'] ); ?>"><?php esc_html_e( 'Edit', 'request-a-quote-for-woocommerce' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 class="title"><?php esc_html_e( 'Sales recipient', 'request-a-quote-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="raq_admin_recipients"><?php esc_html_e( 'Default recipient(s)', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td><input type="text" id="raq_admin_recipients" class="regular-text" name="admin_recipients" value="<?php echo esc_attr( $s['admin_recipients'] ); ?>" placeholder="sales@example.com, ...">
				<p class="description"><?php esc_html_e( 'Comma-separated. Used for the sales notification when its own Recipient field is empty. Leave blank to use the site admin email.', 'request-a-quote-for-woocommerce' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Anti-spam section (part of the Form tab).
	 *
	 * @param array $s Settings.
	 */
	protected static function tab_antispam( $s ) {
		?>
		<h2 class="title"><?php esc_html_e( 'Anti-spam', 'request-a-quote-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Honeypot', 'request-a-quote-for-woocommerce' ); ?></th>
				<td><?php esc_html_e( 'Always on. A hidden decoy field silently rejects bot submissions - no configuration needed.', 'request-a-quote-for-woocommerce' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="raq_recaptcha_site"><?php esc_html_e( 'reCAPTCHA v3 site key', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td><input type="text" id="raq_recaptcha_site" class="regular-text" name="recaptcha_site_key" value="<?php echo esc_attr( $s['recaptcha_site_key'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="raq_recaptcha_secret"><?php esc_html_e( 'reCAPTCHA v3 secret key', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td><input type="text" id="raq_recaptcha_secret" class="regular-text" name="recaptcha_secret_key" value="<?php echo esc_attr( $s['recaptcha_secret_key'] ); ?>">
				<p class="description"><?php esc_html_e( 'reCAPTCHA only runs when both keys are set. The honeypot runs either way.', 'request-a-quote-for-woocommerce' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Analytics tab.
	 *
	 * @param array $s Settings.
	 */
	protected static function tab_analytics( $s ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'GA4 tracking', 'request-a-quote-for-woocommerce' ); ?></th>
				<td><label><input type="checkbox" name="ga4_enabled" value="1" <?php checked( ! empty( $s['ga4_enabled'] ) ); ?>> <?php esc_html_e( 'Push add_to_quote / quote_submitted events (mapped to generate_lead)', 'request-a-quote-for-woocommerce' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="raq_ga4_id"><?php esc_html_e( 'GA4 Measurement ID', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td><input type="text" id="raq_ga4_id" class="regular-text" name="ga4_measurement_id" value="<?php echo esc_attr( $s['ga4_measurement_id'] ); ?>" placeholder="G-XXXXXXXXXX">
				<p class="description"><?php esc_html_e( 'Used only when no GTM dataLayer is detected on the page.', 'request-a-quote-for-woocommerce' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Advanced tab.
	 *
	 * @param array $s Settings.
	 */
	protected static function tab_advanced( $s ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Quote numbering', 'request-a-quote-for-woocommerce' ); ?></th>
				<td><label><input type="checkbox" name="auto_numbering" value="1" <?php checked( ! empty( $s['auto_numbering'] ) ); ?>> <?php esc_html_e( 'Auto-number quotes for a customer-facing reference', 'request-a-quote-for-woocommerce' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="raq_number_prefix"><?php esc_html_e( 'Reference prefix', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td><input type="text" id="raq_number_prefix" class="small-text" name="number_prefix" value="<?php echo esc_attr( $s['number_prefix'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'On uninstall', 'request-a-quote-for-woocommerce' ); ?></th>
				<td><label><input type="checkbox" name="purge_on_uninstall" value="1" <?php checked( ! empty( $s['purge_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Delete all quotes and settings when the plugin is deleted', 'request-a-quote-for-woocommerce' ); ?></label>
				<p class="description"><?php esc_html_e( 'Off by default. Your data is kept unless you opt in here.', 'request-a-quote-for-woocommerce' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Save
	 * ------------------------------------------------------------------ */

	/**
	 * Handle the settings POST. Merges only the submitted tab's fields onto the
	 * existing settings, so saving one tab never wipes another.
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'request-a-quote-for-woocommerce' ) );
		}

		check_admin_referer( self::NONCE_ACT, self::NONCE_NAME );

		$tab      = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		$settings = RAQ_Settings::all();

		switch ( $tab ) {
			case 'general':
				$settings['master_switch']      = ! empty( $_POST['master_switch'] );
				$settings['add_to_quote_label'] = isset( $_POST['add_to_quote_label'] ) ? sanitize_text_field( wp_unslash( $_POST['add_to_quote_label'] ) ) : '';
				$settings['submit_mode']        = ( isset( $_POST['submit_mode'] ) && 'drawer' === $_POST['submit_mode'] ) ? 'drawer' : 'page';
				$settings['require_login']      = ! empty( $_POST['require_login'] );
				$settings['page_title']         = isset( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : '';
				$settings['page_intro']         = isset( $_POST['page_intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['page_intro'] ) ) : '';

				// Appearance.
				$settings['auto_button']  = ! empty( $_POST['auto_button'] );
				$settings['qty_selector'] = ! empty( $_POST['qty_selector'] );
				$settings['btn_bg']       = isset( $_POST['btn_bg'] ) ? self::sanitize_color( wp_unslash( $_POST['btn_bg'] ), '#1c1a17' ) : '#1c1a17';
				$settings['btn_text']     = isset( $_POST['btn_text'] ) ? self::sanitize_color( wp_unslash( $_POST['btn_text'] ), '#ffffff' ) : '#ffffff';
				$size                     = isset( $_POST['btn_size'] ) ? sanitize_key( wp_unslash( $_POST['btn_size'] ) ) : 'medium';
				$settings['btn_size']     = in_array( $size, array( 'small', 'medium', 'large' ), true ) ? $size : 'medium';
				$fab                      = isset( $_POST['fab_position'] ) ? sanitize_key( wp_unslash( $_POST['fab_position'] ) ) : 'bottom-right';
				$settings['fab_position'] = in_array( $fab, array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'hidden' ), true ) ? $fab : 'bottom-right';
				$settings['custom_css']   = isset( $_POST['custom_css'] ) ? self::sanitize_css( wp_unslash( $_POST['custom_css'] ) ) : '';
				break;

			case 'form':
				$settings['fields']               = self::sanitize_fields( isset( $_POST['raq_fields'] ) ? wp_unslash( $_POST['raq_fields'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per element in sanitize_fields().
				$settings['attachments_enabled']  = ! empty( $_POST['attachments_enabled'] );
				$settings['attachments_types']    = isset( $_POST['attachments_types'] ) ? sanitize_text_field( wp_unslash( $_POST['attachments_types'] ) ) : '';
				$settings['attachments_max_mb']   = isset( $_POST['attachments_max_mb'] ) ? absint( $_POST['attachments_max_mb'] ) : 5;
				$settings['recaptcha_site_key']   = isset( $_POST['recaptcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_site_key'] ) ) : '';
				$settings['recaptcha_secret_key'] = isset( $_POST['recaptcha_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_secret_key'] ) ) : '';
				break;

			case 'emails':
				$settings['admin_recipients'] = isset( $_POST['admin_recipients'] ) ? self::sanitize_emails( wp_unslash( $_POST['admin_recipients'] ) ) : '';
				break;

			case 'analytics':
				$settings['ga4_enabled']        = ! empty( $_POST['ga4_enabled'] );
				$settings['ga4_measurement_id'] = isset( $_POST['ga4_measurement_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_measurement_id'] ) ) : '';
				break;

			case 'advanced':
				$settings['auto_numbering']     = ! empty( $_POST['auto_numbering'] );
				$settings['number_prefix']      = isset( $_POST['number_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['number_prefix'] ) ) : 'RAQ-';
				$settings['purge_on_uninstall'] = ! empty( $_POST['purge_on_uninstall'] );
				break;
		}

		RAQ_Settings::save( $settings );

		$redirect = add_query_arg(
			array(
				'post_type' => RAQ_CPT::POST_TYPE,
				'page'      => 'raq-settings',
				'tab'       => $tab,
				'raq_saved' => 1,
			),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Sanitise the submitted field rows into a clean, unique-keyed field set.
	 *
	 * @param array $rows Raw submitted rows.
	 * @return array
	 */
	protected static function sanitize_fields( $rows ) {
		$types  = array_keys( self::field_types() );
		$fields = array();
		$seen   = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			if ( '' === trim( $label ) ) {
				continue; // A row with no label is a blank/removed row.
			}

			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'text';
			if ( ! in_array( $type, $types, true ) ) {
				$type = 'text';
			}

			$key = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			if ( '' === $key ) {
				$key = sanitize_key( $label );
			}
			if ( '' === $key ) {
				continue;
			}

			// Guarantee unique keys.
			$base = $key;
			$n    = 2;
			while ( in_array( $key, $seen, true ) ) {
				$key = $base . '_' . $n;
				$n++;
			}
			$seen[] = $key;

			$fields[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $row['required'] ),
			);
		}

		// Never let the form end up with zero fields.
		if ( empty( $fields ) ) {
			$fields = RAQ_Settings::default_fields();
		}

		return $fields;
	}

	/**
	 * Sanitise a hex colour, falling back to a default.
	 *
	 * @param string $value    Raw value.
	 * @param string $fallback Fallback colour.
	 * @return string
	 */
	protected static function sanitize_color( $value, $fallback ) {
		$color = sanitize_hex_color( $value );
		return $color ? $color : $fallback;
	}

	/**
	 * Sanitise a Custom CSS blob - strip anything that could break out of the
	 * <style> tag it is printed inside.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	protected static function sanitize_css( $css ) {
		$css = (string) $css;
		$css = str_replace( array( '<', '>' ), '', $css ); // No tags in CSS.
		return trim( wp_strip_all_tags( $css ) );
	}

	/**
	 * Sanitise a comma-separated list of emails.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	protected static function sanitize_emails( $raw ) {
		$parts = array_map( 'trim', explode( ',', (string) $raw ) );
		$valid = array();
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$email = sanitize_email( $part );
			if ( is_email( $email ) ) {
				$valid[] = $email;
			}
		}
		return implode( ', ', $valid );
	}
}
