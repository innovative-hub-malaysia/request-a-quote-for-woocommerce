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
		add_action( 'admin_post_raq_create_thankyou', array( __CLASS__, 'handle_create_thankyou' ) );
		add_filter( 'plugin_action_links_' . RAQ_PLUGIN_BASENAME, array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 20, 2 );
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
	 * Add a "FAQ" link to the plugin row meta (the "Version | By | View
	 * details | Check for updates" line), leading to the Guide tab.
	 *
	 * @param array  $links Existing meta links.
	 * @param string $file  Plugin basename the row is for.
	 * @return array
	 */
	public static function row_meta( $links, $file ) {
		if ( RAQ_PLUGIN_BASENAME !== $file ) {
			return $links;
		}
		$url     = admin_url( 'edit.php?post_type=' . RAQ_CPT::POST_TYPE . '&page=raq-settings&tab=guide' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'FAQ', 'request-a-quote-for-woocommerce' ) . '</a>';
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
			'guide'     => __( 'Guide', 'request-a-quote-for-woocommerce' ),
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
			<?php if ( isset( $_GET['raq_thankyou'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash flag. ?>
				<?php if ( 'created' === $_GET['raq_thankyou'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Thank-you page created and selected. Edit its wording under Pages whenever you like.', 'request-a-quote-for-woocommerce' ); ?></p></div>
				<?php elseif ( 'reselected' === $_GET['raq_thankyou'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Your existing Thank-you page is still published, so it was selected again rather than created twice.', 'request-a-quote-for-woocommerce' ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The Thank-you page could not be created.', 'request-a-quote-for-woocommerce' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base_url ) ); ?>"
						class="nav-tab <?php echo $active_tab === $key ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'guide' === $active_tab ) : ?>
				<?php self::tab_guide(); ?>
			</div>
			<?php return; ?>
			<?php endif; ?>

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
			<tr>
				<th scope="row"><?php esc_html_e( 'After submit', 'request-a-quote-for-woocommerce' ); ?></th>
				<td>
					<?php
					$after      = isset( $s['after_submit'] ) ? $s['after_submit'] : 'countdown';
					$create_url = wp_nonce_url( admin_url( 'admin-post.php?action=raq_create_thankyou' ), 'raq_create_thankyou' );
					?>
					<label><input type="radio" name="after_submit" value="countdown" <?php checked( 'countdown', $after ); ?>> <?php esc_html_e( 'Show a thank-you message, then return to the homepage', 'request-a-quote-for-woocommerce' ); ?></label><br>
					<label><input type="radio" name="after_submit" value="page" <?php checked( 'page', $after ); ?>> <?php esc_html_e( 'Redirect to a page on this site', 'request-a-quote-for-woocommerce' ); ?></label><br>
					<label><input type="radio" name="after_submit" value="url" <?php checked( 'url', $after ); ?>> <?php esc_html_e( 'Redirect to a custom URL', 'request-a-quote-for-woocommerce' ); ?></label>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: event name. */ __( 'Applies to both the Quote Page form and the drawer form. The %s analytics event is sent before the redirect in every mode.', 'request-a-quote-for-woocommerce' ), 'generate_lead' ) ); ?></p>
				</td>
			</tr>
			<tr class="raq-when-after-countdown">
				<th scope="row"><label for="raq_redirect_delay"><?php esc_html_e( 'Return after', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td>
					<input type="number" id="raq_redirect_delay" class="small-text" name="redirect_delay" min="0" max="60" step="1" value="<?php echo esc_attr( (int) $s['redirect_delay'] ); ?>"> <?php esc_html_e( 'seconds', 'request-a-quote-for-woocommerce' ); ?>
					<p class="description"><?php esc_html_e( '0 = stay on the thank-you message, no automatic return.', 'request-a-quote-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr class="raq-when-after-page">
				<th scope="row"><label for="raq_redirect_page_id"><?php esc_html_e( 'Thank-you page', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_pages(
						array(
							'name'              => 'redirect_page_id',
							'id'                => 'raq_redirect_page_id',
							'selected'          => (int) $s['redirect_page_id'],
							'show_option_none'  => __( '- Select a page -', 'request-a-quote-for-woocommerce' ),
							'option_none_value' => '0',
							'post_status'       => 'publish', // Anything else would degrade to the countdown silently.
						)
					);
					?>
					<a href="<?php echo esc_url( $create_url ); ?>" class="button raq-create-thankyou" data-confirm="<?php echo esc_attr__( 'This creates a new "Quote Submitted" page and selects it. Unsaved changes on this screen are discarded. Continue?', 'request-a-quote-for-woocommerce' ); ?>"><?php esc_html_e( 'Create a Thank-you page for me', 'request-a-quote-for-woocommerce' ); ?></a>
					<p class="description"><?php echo esc_html( sprintf( /* translators: 1: shortcode, 2: query parameter. */ __( 'Any page works. Add the %1$s shortcode to show the confirmation panel and the quote reference (passed as %2$s).', 'request-a-quote-for-woocommerce' ), '[raq_thank_you]', '?raq_ref=' ) ); ?></p>
				</td>
			</tr>
			<tr class="raq-when-after-url">
				<th scope="row"><label for="raq_redirect_url"><?php esc_html_e( 'Redirect URL', 'request-a-quote-for-woocommerce' ); ?></label></th>
				<td>
					<input type="url" id="raq_redirect_url" class="regular-text" name="redirect_url" value="<?php echo esc_attr( $s['redirect_url'] ); ?>" placeholder="https://">
					<p class="description"><?php esc_html_e( 'Full URL, https recommended. With JavaScript off, only a URL on this site is followed; anything else falls back to the homepage.', 'request-a-quote-for-woocommerce' ); ?></p>
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
				<p class="description"><?php esc_html_e( 'Leave empty if the site already runs Google Tag Manager - the plugin then pushes the events into the GTM dataLayer instead (see the Guide tab). With an ID set, the plugin loads GA4 itself.', 'request-a-quote-for-woocommerce' ); ?></p></td>
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

		<h2 class="title"><?php esc_html_e( 'Updates', 'request-a-quote-for-woocommerce' ); ?></h2>
		<p class="description"><?php esc_html_e( 'New versions are published as GitHub Releases and offered on the Plugins screen like any other plugin.', 'request-a-quote-for-woocommerce' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic updates', 'request-a-quote-for-woocommerce' ); ?></th>
				<td>
					<?php if ( defined( 'RAQ_DISABLE_AUTO_UPDATE' ) ) : // The constant is an ops override; show the state, do not offer a switch that would not work. ?>
						<p><?php echo esc_html( RAQ_DISABLE_AUTO_UPDATE ? __( 'Disabled by RAQ_DISABLE_AUTO_UPDATE in wp-config.php. Remove that line to control it here.', 'request-a-quote-for-woocommerce' ) : __( 'Forced on by RAQ_DISABLE_AUTO_UPDATE in wp-config.php. Remove that line to control it here.', 'request-a-quote-for-woocommerce' ) ); ?></p>
					<?php else : ?>
						<label><input type="checkbox" name="auto_update" value="1" <?php checked( ! empty( $s['auto_update'] ) ); ?>> <?php esc_html_e( 'Install new versions automatically (recommended)', 'request-a-quote-for-woocommerce' ); ?></label>
						<p class="description"><?php esc_html_e( 'When off, updates are still offered on the Plugins screen for you to install by hand.', 'request-a-quote-for-woocommerce' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Guide (FAQ)
	 * ------------------------------------------------------------------ */

	/**
	 * A read-only FAQ. Written for the person who runs the site - a
	 * colleague setting it up or the client who inherits it - not for us:
	 * plain language, one answer per question, no form. Keep it accurate to
	 * the code; a wrong guide costs more than no guide.
	 */
	protected static function tab_guide() {
		$base      = admin_url( 'edit.php?post_type=' . RAQ_CPT::POST_TYPE );
		$settings  = $base . '&page=raq-settings';
		$quotes    = $base;
		$analytics = $base . '&page=raq-analytics';
		$tab       = function ( $t ) use ( $settings ) {
			return $settings . '&tab=' . $t;
		};

		$sections = array(
			array(
				'title' => __( 'What this plugin does', 'request-a-quote-for-woocommerce' ),
				'items' => array(
					array(
						__( 'Why is it installed?', 'request-a-quote-for-woocommerce' ),
						__( 'It turns a WooCommerce store into a B2B catalogue where customers request a quote instead of paying at a checkout. Prices are hidden, every Add to Cart becomes Add to Quote, and the cart and checkout pages send visitors to the Quote Page. Each request is saved as a quote in WP Admin for the sales team to price and follow up.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Is anything permanently changed in the store?', 'request-a-quote-for-woocommerce' ),
						__( 'No. Everything is switched on by the Master Switch and off again the same way. Turn it off, or deactivate the plugin, and the store sells exactly as before - prices, cart and checkout all return. Quotes already collected stay in the database.', 'request-a-quote-for-woocommerce' ),
					),
				),
			),
			array(
				'title' => __( 'Setting it up', 'request-a-quote-for-woocommerce' ),
				'items' => array(
					array(
						__( 'What is the minimum setup?', 'request-a-quote-for-woocommerce' ),
						sprintf(
							/* translators: 1: link to the General tab, 2: shortcode. */
							__( 'Three things on the %1$s tab: turn the Master Switch on, create a page with the %2$s shortcode on it (the plugin finds the page by itself - there is no page picker), and choose where the form is submitted. Then place the quote button in the header with the shortcode below, or leave the floating button on.', 'request-a-quote-for-woocommerce' ),
							'<a href="' . esc_url( $tab( 'general' ) ) . '">' . esc_html__( 'General', 'request-a-quote-for-woocommerce' ) . '</a>',
							'<code>[raq_quote_form]</code>'
						),
					),
					array(
						__( 'How do visitors open their quote list?', 'request-a-quote-for-woocommerce' ),
						__( 'Two ways, both showing a count badge: a floating button in a corner of every page (position or hide it under General > Appearance), and the [raq_quote_button] shortcode for a header, menu or footer. Either opens the drawer where they change quantities, remove lines and, in drawer mode, send the request.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Quote Page form or drawer form - which should I pick?', 'request-a-quote-for-woocommerce' ),
						__( '"Route to the Quote Page" shows the full form on its own page with a summary of the items - best when the form has many fields or attachments. "Submit inside the drawer" lets the visitor send the request without leaving the product page - fewer steps, best for short forms. It is one setting under General > Submission; the fields are the same in both.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'What happens after a visitor submits?', 'request-a-quote-for-woocommerce' ),
						sprintf(
							/* translators: %s: shortcode. */
							__( 'Your choice under General > After submit. Default: a thank-you message, then back to the homepage after the number of seconds you set (0 keeps them on the message). Or redirect to a page on this site - click "Create a Thank-you page for me" and the plugin makes a published "Quote Submitted" page carrying the %s shortcode, which shows the confirmation and the quote reference. Or redirect to any URL you type. The analytics event is sent before the redirect in every mode (it needs JavaScript, like all browser analytics).', 'request-a-quote-for-woocommerce' ),
							'<code>[raq_thank_you]</code>'
						),
					),
					array(
						__( 'Can I change the fields on the form?', 'request-a-quote-for-woocommerce' ),
						sprintf(
							/* translators: %s: link to the Form tab. */
							__( 'Yes, on the %s tab: add, remove, reorder and mark fields required. Name, company, email, phone and a message field are there by default; the phone field has a searchable country-code picker, and a Country field type with the WooCommerce country list is available. Attachments (allowed types and size limit) are switched on there too.', 'request-a-quote-for-woocommerce' ),
							'<a href="' . esc_url( $tab( 'form' ) ) . '">' . esc_html__( 'Form', 'request-a-quote-for-woocommerce' ) . '</a>'
						),
					),
					array(
						__( 'Can I require visitors to log in first?', 'request-a-quote-for-woocommerce' ),
						__( 'Yes - General > Login > "Require login to request a quote". A guest who clicks Add to Quote then sees a "Please log in" message instead, and a submitted form is refused. Off by default: most B2B sites want the request first and the account later.', 'request-a-quote-for-woocommerce' ),
					),
				),
			),
			array(
				'title' => __( 'Tracking (GA4)', 'request-a-quote-for-woocommerce' ),
				'items' => array(
					array(
						__( 'What does the plugin track?', 'request-a-quote-for-woocommerce' ),
						__( 'Two GA4 events, sent by the browser the moment each thing happens: add_to_quote when a product is added (parameter items, one entry with item_id = the product or variation id and quantity), and generate_lead when a quote request is submitted successfully (parameters lead_source = request_a_quote and method = quote_form). No value is sent with either, because prices are hidden and there is nothing honest to send. Nothing is tracked for a failed or spam submission.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Do I need Google Tag Manager?', 'request-a-quote-for-woocommerce' ),
						sprintf(
							/* translators: %s: link to the Analytics tab. */
							__( 'No. On the %s tab, paste the GA4 Measurement ID (G-XXXXXXXXXX) and the plugin loads GA4 itself and sends the events straight to it - no GTM, no extra code. If the site already runs GTM, leave the Measurement ID empty: the plugin then pushes the same events into the GTM dataLayer and you wire them up in GTM (next question). Set one or the other, never both, or events are counted twice.', 'request-a-quote-for-woocommerce' ),
							'<a href="' . esc_url( $tab( 'analytics' ) ) . '">' . esc_html__( 'Analytics', 'request-a-quote-for-woocommerce' ) . '</a>'
						),
					),
					array(
						__( 'The site uses GTM - how do I pass the events to GA4?', 'request-a-quote-for-woocommerce' ),
						__( 'In GTM: create a Custom Event trigger with the event name generate_lead, then a "Google Analytics: GA4 Event" tag with the event name generate_lead that fires on that trigger. Repeat for add_to_quote if you want it. Optional: Data Layer Variables named lead_source and method can be added as event parameters. Publish the container. Until it is published, nothing reaches GA4.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'How do I turn a quote request into a conversion (Key Event)?', 'request-a-quote-for-woocommerce' ),
						__( 'In GA4: Admin > Data display > Events, find generate_lead and switch on "Mark as key event" (it appears in the list after the first submission arrives; to set it up before that, Admin > Data display > Key events > New key event > type generate_lead). Once marked, every quote request counts as a conversion in reports and can be imported into Google Ads as a conversion action.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'How do I check the events are really arriving?', 'request-a-quote-for-woocommerce' ),
						__( 'Open GA4 > Admin > Data display > DebugView, then on the site (with the Google Analytics Debugger browser extension on, or GTM Preview mode) add a product to the quote and submit a request. add_to_quote and generate_lead should appear in DebugView within seconds, with their parameters. If they do not, see the troubleshooting question below.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Will a redirect after submit lose the event?', 'request-a-quote-for-woocommerce' ),
						__( 'No. The plugin sends generate_lead first, waits for GA4 or GTM to confirm it has been handed off (or 300 ms at most), and only then leaves the page. The visitor sees a "sent, taking you to the next page" panel while that happens.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Can I use the thank-you page itself as a goal?', 'request-a-quote-for-woocommerce' ),
						__( 'Yes: with "Redirect to a page" the thank-you page is a URL of its own, so it can be a Google Ads page-load conversion, or a GA4 key event of its own (Admin > Data display > Events > Create event, condition page_location contains the thank-you path, then mark that event). Do not also count that page view as generate_lead, or each request is counted twice. The event is the reliable signal (it fires in every mode); the page is a convenience.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Events are not showing up - what do I check?', 'request-a-quote-for-woocommerce' ),
						__( 'In this order: GA4 tracking is ticked on the Analytics tab; the Measurement ID has no typo (or, on a GTM site, the container is published and the trigger name is exactly generate_lead); an ad blocker or a consent banner in "denied" state is not blocking the tag in your own browser; and you are looking at DebugView, not the standard reports, which lag by a day or two.', 'request-a-quote-for-woocommerce' ),
					),
				),
			),
			array(
				'title' => __( 'Working the quotes', 'request-a-quote-for-woocommerce' ),
				'items' => array(
					array(
						__( 'Where do the requests go?', 'request-a-quote-for-woocommerce' ),
						sprintf(
							/* translators: 1: link to the Quotes list, 2: link to Analytics. */
							__( '%1$s in WP Admin: one record per request with the customer details, the items and quantities, any attachment, and the page the request came from. Each quote has a status - New, Quoted, Won, Lost - which you move as the deal progresses; the %2$s screen reads those statuses back as a funnel, a 12-week trend, win rate, and the New quotes that have gone stale.', 'request-a-quote-for-woocommerce' ),
							'<a href="' . esc_url( $quotes ) . '">' . esc_html__( 'Quotes', 'request-a-quote-for-woocommerce' ) . '</a>',
							'<a href="' . esc_url( $analytics ) . '">' . esc_html__( 'Analytics', 'request-a-quote-for-woocommerce' ) . '</a>'
						),
					),
					array(
						__( 'What is the RAQ-0001 number?', 'request-a-quote-for-woocommerce' ),
						sprintf(
							/* translators: %s: link to the Advanced tab. */
							__( 'The customer-facing reference, numbered automatically. It is on the quote, in the emails, and on the thank-you page. Change the prefix (or switch numbering off) on the %s tab. Keep the prefix to letters, digits and dashes.', 'request-a-quote-for-woocommerce' ),
							'<a href="' . esc_url( $tab( 'advanced' ) ) . '">' . esc_html__( 'Advanced', 'request-a-quote-for-woocommerce' ) . '</a>'
						),
					),
					array(
						__( 'Can I get the quotes out as a spreadsheet?', 'request-a-quote-for-woocommerce' ),
						__( 'Yes - the Export CSV button above the Quotes list downloads every quote, all statuses, with name, company, email, phone, country, the items and the source page.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Who gets emailed?', 'request-a-quote-for-woocommerce' ),
						sprintf(
							/* translators: %s: link to the Emails tab. */
							__( 'Two emails per request: one to the sales team (the addresses under %s > Sales recipient, or the site admin email if empty) with every detail, and a confirmation to the customer. Both are WooCommerce emails, so their subject, heading and template are edited under WooCommerce > Settings > Emails like any other - and a Recipient typed into the admin email\'s own settings there takes precedence over Sales recipient.', 'request-a-quote-for-woocommerce' ),
							'<a href="' . esc_url( $tab( 'emails' ) ) . '">' . esc_html__( 'Emails', 'request-a-quote-for-woocommerce' ) . '</a>'
						),
					),
					array(
						__( 'How is spam kept out?', 'request-a-quote-for-woocommerce' ),
						__( 'A honeypot field is always on: bots that fill it are rejected quietly. For more, add Google reCAPTCHA v3 site and secret keys under Form > Anti-spam; submissions scoring under 0.5 are rejected. reCAPTCHA v3 is invisible - there is no checkbox for real visitors.', 'request-a-quote-for-woocommerce' ),
					),
				),
			),
			array(
				'title' => __( 'Updates', 'request-a-quote-for-woocommerce' ),
				'items' => array(
					array(
						__( 'Where do updates come from?', 'request-a-quote-for-woocommerce' ),
						__( 'From the plugin\'s GitHub releases, published by Innovative Hub - not from wordpress.org. The site checks every 12 hours and a new version appears on the Plugins screen like any other update ("Check for updates" on the plugin row checks right now). Automatic installation is on by default.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Can I stop it updating on its own?', 'request-a-quote-for-woocommerce' ),
						sprintf(
							/* translators: %s: link to the Advanced tab. */
							__( 'Yes - untick "Automatic updates" under %s > Updates. New versions are then still offered on the Plugins screen for you to install by hand. A developer can also force it from wp-config.php with define( \'RAQ_DISABLE_AUTO_UPDATE\', true ), which overrides the setting.', 'request-a-quote-for-woocommerce' ),
							'<a href="' . esc_url( $tab( 'advanced' ) ) . '">' . esc_html__( 'Advanced', 'request-a-quote-for-woocommerce' ) . '</a>'
						),
					),
					array(
						__( 'The Plugins screen never offers an update on an old install', 'request-a-quote-for-woocommerce' ),
						__( 'Versions before 1.0.5 had no update checker at all. Upload the latest zip once (Plugins > Add New > Upload Plugin > "Replace current with uploaded"); from then on the site updates itself.', 'request-a-quote-for-woocommerce' ),
					),
				),
			),
			array(
				'title' => __( 'Troubleshooting', 'request-a-quote-for-woocommerce' ),
				'items' => array(
					array(
						__( 'Prices or Add to Cart buttons are still showing', 'request-a-quote-for-woocommerce' ),
						__( 'Check the Master Switch is on, then clear the site\'s page cache (caching plugin, host cache, CDN) - the old page is usually a cached copy. A theme that prints prices with its own code, bypassing WooCommerce\'s price functions, needs a small theme fix.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'The drawer shows someone else\'s items', 'request-a-quote-for-woocommerce' ),
						__( 'A page cache served another visitor\'s copy of the page. Versions from 1.0.3 print an empty drawer into the page and fill it per visitor after load, so update the plugin; if it persists, exclude the Quote Page from the cache.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Real visitors say the form will not submit', 'request-a-quote-for-woocommerce' ),
						__( 'Most often a reCAPTCHA key pair from a different site or type (it must be v3), or a browser auto-fill writing into the hidden honeypot field. Remove the reCAPTCHA keys to confirm, then fix the keys.', 'request-a-quote-for-woocommerce' ),
					),
					array(
						__( 'Where do I report a bug or ask for a feature?', 'request-a-quote-for-woocommerce' ),
						__( 'To Innovative Hub - note the site, what you did, what you expected and what happened, plus the plugin version from the Plugins screen. Feature requests are collected the same way and decided together.', 'request-a-quote-for-woocommerce' ),
					),
				),
			),
		);

		?>
		<style>
			.raq-guide{max-width:820px}
			.raq-guide h2{font-size:15px;margin:26px 0 6px}
			.raq-guide details{border-bottom:1px solid #dcdcde;padding:9px 0}
			.raq-guide summary{cursor:pointer;font-weight:600;color:#1d2327}
			.raq-guide summary:hover{color:#2271b1}
			.raq-guide details p{margin:8px 0 2px;color:#3c434a;line-height:1.55}
			.raq-guide code{font-size:12px}
		</style>
		<div class="raq-guide">
			<p class="description"><?php esc_html_e( 'How this plugin works and how to get the most from it. Click a question to open the answer.', 'request-a-quote-for-woocommerce' ); ?></p>
			<?php foreach ( $sections as $section ) : ?>
				<h2><?php echo esc_html( $section['title'] ); ?></h2>
				<?php foreach ( $section['items'] as $qa ) : ?>
					<details>
						<summary><?php echo esc_html( $qa[0] ); ?></summary>
						<p><?php echo wp_kses( $qa[1], array( 'a' => array( 'href' => array() ), 'code' => array() ) ); ?></p>
					</details>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>
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
				$after                          = isset( $_POST['after_submit'] ) ? sanitize_key( wp_unslash( $_POST['after_submit'] ) ) : 'countdown';
				$settings['after_submit']       = in_array( $after, array( 'countdown', 'page', 'url' ), true ) ? $after : 'countdown';
				$settings['redirect_page_id']   = isset( $_POST['redirect_page_id'] ) ? absint( $_POST['redirect_page_id'] ) : 0;
				$settings['redirect_url']       = isset( $_POST['redirect_url'] ) ? self::sanitize_redirect_url( wp_unslash( $_POST['redirect_url'] ) ) : '';
				$settings['redirect_delay']     = isset( $_POST['redirect_delay'] ) ? min( 60, absint( $_POST['redirect_delay'] ) ) : 5;
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
				if ( ! defined( 'RAQ_DISABLE_AUTO_UPDATE' ) ) { // The switch is not rendered when the constant rules; keep the saved value.
					$settings['auto_update'] = ! empty( $_POST['auto_update'] );
				}
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
	 * "Create a Thank-you page for me": insert a published page carrying the
	 * `[raq_thank_you]` shortcode, point the After-submit setting at it, and
	 * come back to the General tab. Never creates a second one - if the page
	 * from an earlier click is still published it is simply re-selected (a
	 * draft/trashed one would silently degrade the redirect, so it is replaced).
	 */
	public static function handle_create_thankyou() {
		if ( ! current_user_can( self::CAP ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'request-a-quote-for-woocommerce' ) );
		}
		check_admin_referer( 'raq_create_thankyou' );

		$settings = RAQ_Settings::all();
		$page_id  = (int) get_option( 'raq_thankyou_page_id', 0 );
		$outcome  = 'reselected';

		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			$outcome = 'created';
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Quote Submitted', 'request-a-quote-for-woocommerce' ),
					'post_name'    => 'quote-submitted',
					'post_content' => '[raq_thank_you]',
				),
				true
			);
			if ( is_wp_error( $page_id ) || ! $page_id ) {
				$page_id = 0;
			} else {
				update_option( 'raq_thankyou_page_id', (int) $page_id );
			}
		}

		if ( $page_id ) {
			$settings['after_submit']     = 'page';
			$settings['redirect_page_id'] = (int) $page_id;
			RAQ_Settings::save( $settings );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'    => RAQ_CPT::POST_TYPE,
					'page'         => 'raq-settings',
					'tab'          => 'general',
					'raq_thankyou' => $page_id ? $outcome : 'failed',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * A redirect URL: http(s) only, otherwise empty (the front end then falls
	 * back to the homepage rather than following javascript:/data: etc.).
	 *
	 * @param string $raw Submitted value.
	 * @return string
	 */
	protected static function sanitize_redirect_url( $raw ) {
		$url = esc_url_raw( trim( (string) $raw ), array( 'http', 'https' ) );
		return $url ? $url : '';
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
