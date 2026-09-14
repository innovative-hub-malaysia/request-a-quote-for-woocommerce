<?php
/**
 * The `raq_quote` custom post type + its custom statuses.
 *
 * Quotes are our own data - deliberately NOT WooCommerce orders, so we carry
 * no stock / payment / order-email baggage. The CPT is admin-only (no public
 * front-end single view) and managed by shop managers via WooCommerce caps.
 *
 * NOTE: the WP post ID is an internal key. The customer-facing reference
 * (RAQ-0001) is separate post meta added in Stage 5 - do not assume the post
 * ID is the reference number.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_CPT
 */
class RAQ_CPT {

	const POST_TYPE = 'raq_quote';

	const STATUS_NEW    = 'raq-new';
	const STATUS_QUOTED = 'raq-quoted';
	const STATUS_WON    = 'raq-won';
	const STATUS_LOST   = 'raq-lost';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
	}

	/**
	 * Register the quote post type.
	 *
	 * Kept public so the activator can call it directly before flushing rewrite
	 * rules.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'Quotes', 'post type general name', 'request-a-quote-for-woocommerce' ),
			'singular_name'      => _x( 'Quote', 'post type singular name', 'request-a-quote-for-woocommerce' ),
			'menu_name'          => _x( 'Quotes', 'admin menu', 'request-a-quote-for-woocommerce' ),
			'add_new'            => __( 'Add New', 'request-a-quote-for-woocommerce' ),
			'add_new_item'       => __( 'Add New Quote', 'request-a-quote-for-woocommerce' ),
			'edit_item'          => __( 'Edit Quote', 'request-a-quote-for-woocommerce' ),
			'new_item'           => __( 'New Quote', 'request-a-quote-for-woocommerce' ),
			'view_item'          => __( 'View Quote', 'request-a-quote-for-woocommerce' ),
			'search_items'       => __( 'Search Quotes', 'request-a-quote-for-woocommerce' ),
			'not_found'          => __( 'No quotes found', 'request-a-quote-for-woocommerce' ),
			'not_found_in_trash' => __( 'No quotes found in Trash', 'request-a-quote-for-woocommerce' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false, // No public catalogue / single view for quote records.
			'show_ui'             => true,  // But manageable in wp-admin.
			'show_in_menu'        => true,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'menu_icon'           => 'dashicons-media-spreadsheet',
			'menu_position'       => 56, // Just below WooCommerce.
			'hierarchical'        => false,
			'supports'            => array( 'title' ), // Line items / customer data live in meta, not the editor.
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'exclude_from_search' => true,
			// Map to WooCommerce caps so shop managers can manage quotes in Stage 5.
			'capability_type'     => array( 'raq_quote', 'raq_quotes' ),
			'map_meta_cap'        => true,
			'capabilities'        => self::capabilities(),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Capability map. We reuse the WooCommerce `shop_order` capabilities so any
	 * role that can manage orders (shop_manager, administrator) can manage
	 * quotes, without inventing a new permission set.
	 *
	 * @return array
	 */
	public static function capabilities() {
		return array(
			'edit_post'              => 'edit_shop_order',
			'read_post'              => 'read_shop_order',
			'delete_post'            => 'delete_shop_order',
			'edit_posts'             => 'edit_shop_orders',
			'edit_others_posts'      => 'edit_others_shop_orders',
			'delete_posts'           => 'delete_shop_orders',
			'publish_posts'          => 'publish_shop_orders',
			'read_private_posts'     => 'read_private_shop_orders',
			'delete_private_posts'   => 'delete_private_shop_orders',
			'delete_published_posts' => 'delete_published_shop_orders',
			'delete_others_posts'    => 'delete_others_shop_orders',
			'edit_private_posts'     => 'edit_private_shop_orders',
			'edit_published_posts'   => 'edit_published_shop_orders',
			// Quotes are created by customers on the front-end only - block the
			// admin "Add New" entirely (front-end wp_insert_post is unaffected).
			'create_posts'           => 'do_not_allow',
		);
	}

	/**
	 * Register the four quote statuses.
	 */
	public static function register_statuses() {
		$statuses = array(
			self::STATUS_NEW    => _x( 'New', 'quote status', 'request-a-quote-for-woocommerce' ),
			self::STATUS_QUOTED => _x( 'Quoted', 'quote status', 'request-a-quote-for-woocommerce' ),
			self::STATUS_WON    => _x( 'Won', 'quote status', 'request-a-quote-for-woocommerce' ),
			self::STATUS_LOST   => _x( 'Lost', 'quote status', 'request-a-quote-for-woocommerce' ),
		);

		foreach ( $statuses as $status => $label ) {
			// The three flags below are load-bearing - each wrong value made
			// quotes invisible in a different place:
			// - 'internal' => true  : excluded everywhere (v0.1.5 bug). Never set.
			// - missing 'protected' : the admin "All" list only includes public
			//   or protected statuses, so quotes stayed hidden (v0.1.6 bug).
			// - 'exclude_from_search' => true : post_status 'any' queries skip
			//   such statuses (analytics/CSV saw nothing). The CPT itself is
			//   already excluded from front-end search, so false is safe.
			register_post_status(
				$status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'protected'                 => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of quotes. */
					'label_count'               => _n_noop(
						$label . ' <span class="count">(%s)</span>',
						$label . ' <span class="count">(%s)</span>',
						'request-a-quote-for-woocommerce'
					),
				)
			);
		}
	}

	/**
	 * All quote statuses as a slug => label map.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			self::STATUS_NEW    => __( 'New', 'request-a-quote-for-woocommerce' ),
			self::STATUS_QUOTED => __( 'Quoted', 'request-a-quote-for-woocommerce' ),
			self::STATUS_WON    => __( 'Won', 'request-a-quote-for-woocommerce' ),
			self::STATUS_LOST   => __( 'Lost', 'request-a-quote-for-woocommerce' ),
		);
	}
}
