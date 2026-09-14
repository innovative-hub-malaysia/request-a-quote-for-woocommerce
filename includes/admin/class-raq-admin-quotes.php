<?php
/**
 * Backend quote management: list table, detail meta boxes, status workflow,
 * internal notes, CSV export, and secure attachment download.
 *
 * Loaded in wp-admin regardless of the Master Switch, so quotes stay
 * manageable even if the store is toggled back to selling.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Admin_Quotes
 */
class RAQ_Admin_Quotes {

	const CAP = 'edit_shop_orders';

	/**
	 * Hook registration.
	 */
	public static function init() {
		$pt = RAQ_CPT::POST_TYPE;

		add_filter( "manage_{$pt}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'export_button' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( "save_post_{$pt}", array( __CLASS__, 'save_quote' ), 10, 2 );

		add_action( 'admin_post_raq_set_status', array( __CLASS__, 'quick_set_status' ) );
		add_action( 'admin_post_raq_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_raq_download_attachment', array( __CLASS__, 'download_attachment' ) );
	}

	/* ------------------------------------------------------------------ *
	 * List table
	 * ------------------------------------------------------------------ */

	/**
	 * Columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function columns( $cols ) {
		$new = array(
			'cb'           => isset( $cols['cb'] ) ? $cols['cb'] : '',
			'title'        => __( 'Reference', 'request-a-quote-for-woocommerce' ),
			'raq_customer' => __( 'Customer', 'request-a-quote-for-woocommerce' ),
			'raq_company'  => __( 'Company', 'request-a-quote-for-woocommerce' ),
			'raq_items'    => __( 'Items', 'request-a-quote-for-woocommerce' ),
			'raq_status'   => __( 'Status', 'request-a-quote-for-woocommerce' ),
			'date'         => isset( $cols['date'] ) ? $cols['date'] : __( 'Date', 'request-a-quote-for-woocommerce' ),
		);
		return $new;
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $col     Column key.
	 * @param int    $post_id Post id.
	 */
	public static function render_column( $col, $post_id ) {
		switch ( $col ) {
			case 'raq_customer':
				$customer = (array) get_post_meta( $post_id, '_raq_customer', true );
				$name     = RAQ_Email_Helper::field_value( $customer, 'name' );
				$email    = RAQ_Email_Helper::field_value( $customer, 'email' );
				echo esc_html( $name );
				if ( $email ) {
					echo '<br><small><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></small>';
				}
				break;

			case 'raq_company':
				$customer = (array) get_post_meta( $post_id, '_raq_customer', true );
				echo esc_html( RAQ_Email_Helper::field_value( $customer, 'company' ) );
				break;

			case 'raq_items':
				$items = array_filter( (array) get_post_meta( $post_id, '_raq_items', true ), 'is_array' );
				$count = 0;
				foreach ( $items as $item ) {
					$count += isset( $item['qty'] ) ? absint( $item['qty'] ) : 0;
				}
				echo esc_html( sprintf( /* translators: 1: line count, 2: total qty. */ _n( '%1$d line, %2$d unit', '%1$d lines, %2$d units', count( $items ), 'request-a-quote-for-woocommerce' ), count( $items ), $count ) );
				break;

			case 'raq_status':
				$status = get_post_meta( $post_id, '_raq_status', true );
				$status = $status ? $status : get_post_status( $post_id );
				$labels = RAQ_CPT::statuses();
				$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
				echo '<span class="raq-status-badge raq-status-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
				break;
		}
	}

	/**
	 * Quick status-change links in the row actions.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( RAQ_CPT::POST_TYPE !== $post->post_type || ! current_user_can( self::CAP ) ) {
			return $actions;
		}
		$current = get_post_meta( $post->ID, '_raq_status', true );
		foreach ( RAQ_CPT::statuses() as $status => $label ) {
			if ( $status === $current ) {
				continue;
			}
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=raq_set_status&post=' . $post->ID . '&status=' . $status ),
				'raq_set_status_' . $post->ID
			);
			/* translators: %s: status label. */
			$actions[ 'raq_' . $status ] = '<a href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Mark %s', 'request-a-quote-for-woocommerce' ), $label ) ) . '</a>';
		}
		return $actions;
	}

	/**
	 * "Export CSV" button in the list-table filter bar.
	 *
	 * @param string $post_type Current post type.
	 */
	public static function export_button( $post_type ) {
		if ( RAQ_CPT::POST_TYPE !== $post_type ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=raq_export_csv' ), 'raq_export_csv' );
		echo '<a href="' . esc_url( $url ) . '" class="button">' . esc_html__( 'Export CSV', 'request-a-quote-for-woocommerce' ) . '</a>';
	}

	/* ------------------------------------------------------------------ *
	 * Meta boxes
	 * ------------------------------------------------------------------ */

	/**
	 * Register meta boxes.
	 */
	public static function meta_boxes() {
		$pt = RAQ_CPT::POST_TYPE;

		// Drop the native Publish box: publish/draft/visibility/schedule are
		// meaningless for a quote, and it chokes on our custom statuses. Our
		// Status & history box carries its own Update button + trash link.
		remove_meta_box( 'submitdiv', $pt, 'side' );

		add_meta_box( 'raq_details', __( 'Quote details', 'request-a-quote-for-woocommerce' ), array( __CLASS__, 'box_details' ), $pt, 'normal', 'high' );
		add_meta_box( 'raq_notes', __( 'Internal notes', 'request-a-quote-for-woocommerce' ), array( __CLASS__, 'box_notes' ), $pt, 'normal', 'default' );
		add_meta_box( 'raq_status', __( 'Status & history', 'request-a-quote-for-woocommerce' ), array( __CLASS__, 'box_status' ), $pt, 'side', 'high' );
	}

	/**
	 * Details meta box: line items + customer + attachment.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_details( $post ) {
		$items      = (array) get_post_meta( $post->ID, '_raq_items', true );
		$customer   = (array) get_post_meta( $post->ID, '_raq_customer', true );
		$attachment = (array) get_post_meta( $post->ID, '_raq_attachment', true );
		$source     = get_post_meta( $post->ID, '_raq_source_url', true );

		echo '<h4>' . esc_html__( 'Requested products', 'request-a-quote-for-woocommerce' ) . '</h4>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'request-a-quote-for-woocommerce' ) . '</th><th>' . esc_html__( 'SKU', 'request-a-quote-for-woocommerce' ) . '</th><th>' . esc_html__( 'Qty', 'request-a-quote-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$vlabel = RAQ_Email_Helper::variation_label( isset( $item['variation'] ) ? $item['variation'] : array() );
			echo '<tr><td>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' );
			if ( $vlabel ) {
				echo '<br><small>' . esc_html( $vlabel ) . '</small>';
			}
			echo '</td><td>' . esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ) . '</td><td>' . esc_html( $item['qty'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h4>' . esc_html__( 'Customer', 'request-a-quote-for-woocommerce' ) . '</h4>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $customer as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['value'] ) || ! is_scalar( $field['value'] ) || '' === trim( (string) $field['value'] ) ) {
				continue;
			}
			$flabel = isset( $field['label'] ) && is_scalar( $field['label'] ) ? $field['label'] : '';
			echo '<tr><th style="width:30%">' . esc_html( $flabel ) . '</th><td>' . nl2br( esc_html( $field['value'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( ! empty( $attachment['file'] ) ) {
			$dl = wp_nonce_url(
				admin_url( 'admin-post.php?action=raq_download_attachment&post=' . $post->ID ),
				'raq_download_' . $post->ID
			);
			echo '<p><strong>' . esc_html__( 'Attachment:', 'request-a-quote-for-woocommerce' ) . '</strong> ';
			echo '<a href="' . esc_url( $dl ) . '">' . esc_html( isset( $attachment['name'] ) ? $attachment['name'] : __( 'Download', 'request-a-quote-for-woocommerce' ) ) . '</a></p>';
		}

		if ( $source ) {
			echo '<p><small>' . esc_html__( 'Submitted from:', 'request-a-quote-for-woocommerce' ) . ' <a href="' . esc_url( $source ) . '">' . esc_html( $source ) . '</a></small></p>';
		}
	}

	/**
	 * Notes meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_notes( $post ) {
		wp_nonce_field( 'raq_save_notes', 'raq_notes_nonce' );
		// Filter to arrays: empty meta casts to array('') and a malformed entry
		// would fatal on ['time'] access under PHP 8.
		$notes = array_filter( (array) get_post_meta( $post->ID, '_raq_notes', true ), 'is_array' );

		echo '<p><textarea name="raq_note_new" rows="2" style="width:100%" placeholder="' . esc_attr__( 'Add an internal note...', 'request-a-quote-for-woocommerce' ) . '"></textarea></p>';

		if ( $notes ) {
			echo '<ul class="raq-notes">';
			foreach ( array_reverse( $notes ) as $note ) {
				$time = isset( $note['time'] ) ? $note['time'] : '';
				$user = isset( $note['user'] ) ? $note['user'] : '';
				$text = isset( $note['text'] ) ? $note['text'] : '';
				echo '<li><small>' . esc_html( $time ) . ' - ' . esc_html( $user ) . '</small><br>' . nl2br( esc_html( $text ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Status + history meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_status( $post ) {
		wp_nonce_field( 'raq_save_status', 'raq_status_nonce' );
		$current = get_post_meta( $post->ID, '_raq_status', true );
		$current = $current ? $current : RAQ_CPT::STATUS_NEW;

		echo '<p><label for="raq_status_field"><strong>' . esc_html__( 'Status', 'request-a-quote-for-woocommerce' ) . '</strong></label><br>';
		echo '<select name="raq_status_field" id="raq_status_field" style="width:100%">';
		foreach ( RAQ_CPT::statuses() as $status => $label ) {
			echo '<option value="' . esc_attr( $status ) . '" ' . selected( $current, $status, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		echo '<p><label><input type="checkbox" name="raq_notify_customer" value="1"> ' . esc_html__( 'Email the customer about this status change', 'request-a-quote-for-woocommerce' ) . '</label></p>';

		// Our own save/trash controls (the native Publish box is removed).
		echo '<div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid #dcdcde;padding-top:12px;margin-top:4px">';
		echo '<a href="' . esc_url( get_delete_post_link( $post->ID ) ) . '" style="color:#b32d2e">' . esc_html__( 'Move to Trash', 'request-a-quote-for-woocommerce' ) . '</a>';
		echo '<button type="submit" name="save" value="save" class="button button-primary button-large">' . esc_html__( 'Update', 'request-a-quote-for-woocommerce' ) . '</button>';
		echo '</div>';

		$history = array_filter( (array) get_post_meta( $post->ID, '_raq_history', true ), 'is_array' );
		if ( $history ) {
			echo '<h4>' . esc_html__( 'History', 'request-a-quote-for-woocommerce' ) . '</h4><ul class="raq-history">';
			foreach ( array_reverse( $history ) as $h ) {
				$time = isset( $h['time'] ) ? $h['time'] : '';
				$user = isset( $h['user'] ) ? $h['user'] : '';
				$text = isset( $h['text'] ) ? $h['text'] : '';
				echo '<li><small>' . esc_html( $time ) . ' - ' . esc_html( $user ) . '</small><br>' . esc_html( $text );
				if ( ! empty( $h['note'] ) ) {
					echo ' <em>(' . esc_html( $h['note'] ) . ')</em>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Saving
	 * ------------------------------------------------------------------ */

	/**
	 * Save handler for the editor (status + notes).
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 */
	public static function save_quote( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// Status (also re-asserts our custom status if core stomped it to publish).
		if ( isset( $_POST['raq_status_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['raq_status_nonce'] ) ), 'raq_save_status' ) ) {
			$new    = isset( $_POST['raq_status_field'] ) ? sanitize_key( wp_unslash( $_POST['raq_status_field'] ) ) : '';
			$notify = ! empty( $_POST['raq_notify_customer'] );
			if ( array_key_exists( $new, RAQ_CPT::statuses() ) ) {
				self::set_status( $post_id, $new, __( 'Updated from the quote screen', 'request-a-quote-for-woocommerce' ), $notify );
			}
		}

		// New internal note.
		if ( isset( $_POST['raq_notes_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['raq_notes_nonce'] ) ), 'raq_save_notes' ) ) {
			$note = isset( $_POST['raq_note_new'] ) ? sanitize_textarea_field( wp_unslash( $_POST['raq_note_new'] ) ) : '';
			if ( '' !== trim( $note ) ) {
				self::add_note( $post_id, $note );
			}
		}
	}

	/**
	 * Quick status change from the row action.
	 */
	public static function quick_set_status() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'request-a-quote-for-woocommerce' ) );
		}
		check_admin_referer( 'raq_set_status_' . $post_id );

		if ( $post_id && array_key_exists( $status, RAQ_CPT::statuses() ) ) {
			self::set_status( $post_id, $status, __( 'Quick change from the list', 'request-a-quote-for-woocommerce' ), false );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . RAQ_CPT::POST_TYPE ) );
		exit;
	}

	/**
	 * Set a quote's status, recording history + firing analytics/notify hooks.
	 *
	 * @param int    $post_id Post id.
	 * @param string $status  New status slug.
	 * @param string $note    Reason note.
	 * @param bool   $notify  Whether to email the customer.
	 */
	protected static function set_status( $post_id, $status, $note = '', $notify = false ) {
		$prev = get_post_meta( $post_id, '_raq_status', true );

		// Update post_status without re-triggering this save handler.
		remove_action( 'save_post_' . RAQ_CPT::POST_TYPE, array( __CLASS__, 'save_quote' ), 10 );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			)
		);
		add_action( 'save_post_' . RAQ_CPT::POST_TYPE, array( __CLASS__, 'save_quote' ), 10, 2 );

		update_post_meta( $post_id, '_raq_status', $status );

		if ( $prev && $prev !== $status ) {
			$labels = RAQ_CPT::statuses();
			self::add_history(
				$post_id,
				sprintf(
					/* translators: 1: old status, 2: new status. */
					__( 'Status: %1$s -> %2$s', 'request-a-quote-for-woocommerce' ),
					isset( $labels[ $prev ] ) ? $labels[ $prev ] : $prev,
					isset( $labels[ $status ] ) ? $labels[ $status ] : $status
				),
				$note
			);

			/**
			 * Fires when a quote changes status (Stage 6 analytics reads this).
			 *
			 * @param int    $post_id Quote id.
			 * @param string $prev    Old status.
			 * @param string $status  New status.
			 */
			do_action( 'raq_quote_status_changed', $post_id, $prev, $status );

			if ( $notify ) {
				self::notify_customer( $post_id, $status );
			}
		}
	}

	/**
	 * Append a history entry.
	 *
	 * @param int    $post_id Post id.
	 * @param string $text    Entry text.
	 * @param string $note    Optional note.
	 */
	protected static function add_history( $post_id, $text, $note = '' ) {
		$history   = array_filter( (array) get_post_meta( $post_id, '_raq_history', true ), 'is_array' );
		$history[] = array(
			'time' => current_time( 'mysql' ),
			'user' => self::current_user_name(),
			'text' => $text,
			'note' => $note,
		);
		update_post_meta( $post_id, '_raq_history', $history );
	}

	/**
	 * Append an internal note.
	 *
	 * @param int    $post_id Post id.
	 * @param string $text    Note text.
	 */
	protected static function add_note( $post_id, $text ) {
		$notes   = array_filter( (array) get_post_meta( $post_id, '_raq_notes', true ), 'is_array' );
		$notes[] = array(
			'time' => current_time( 'mysql' ),
			'user' => self::current_user_name(),
			'text' => $text,
		);
		update_post_meta( $post_id, '_raq_notes', $notes );
	}

	/**
	 * Current user's display name.
	 *
	 * @return string
	 */
	protected static function current_user_name() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? $user->display_name : __( 'System', 'request-a-quote-for-woocommerce' );
	}

	/**
	 * A short status-change email to the customer.
	 *
	 * @param int    $post_id Post id.
	 * @param string $status  New status.
	 */
	protected static function notify_customer( $post_id, $status ) {
		$customer = (array) get_post_meta( $post_id, '_raq_customer', true );
		$email    = RAQ_Email_Helper::customer_email( $customer );
		if ( ! is_email( $email ) ) {
			return;
		}
		$labels    = RAQ_CPT::statuses();
		$reference = get_post_meta( $post_id, '_raq_reference', true );
		$subject   = sprintf(
			/* translators: 1: site, 2: reference. */
			__( '[%1$s] Update on your quote %2$s', 'request-a-quote-for-woocommerce' ),
			get_bloginfo( 'name' ),
			$reference
		);
		$body = sprintf(
			/* translators: 1: reference, 2: status label. */
			__( "Hello,\n\nYour quote %1\$s has been updated to: %2\$s.\n\nWe will be in touch with the next steps.", 'request-a-quote-for-woocommerce' ),
			$reference,
			isset( $labels[ $status ] ) ? $labels[ $status ] : $status
		);
		wp_mail( $email, $subject, $body );
	}

	/* ------------------------------------------------------------------ *
	 * CSV export
	 * ------------------------------------------------------------------ */

	/**
	 * Stream all quotes as CSV.
	 */
	public static function export_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'request-a-quote-for-woocommerce' ) );
		}
		check_admin_referer( 'raq_export_csv' );

		$quotes = get_posts(
			array(
				'post_type'   => RAQ_CPT::POST_TYPE,
				// Explicit statuses, never 'any' (see RAQ_CPT::register_statuses).
				'post_status' => array_keys( RAQ_CPT::statuses() ),
				'numberposts' => -1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=quotes-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out    = fopen( 'php://output', 'w' );
		$labels = RAQ_CPT::statuses();

		// Neutralise CSV formula injection: customer-supplied cells starting
		// with = + - @ or a tab would execute as formulas in Excel/Sheets.
		$safe = static function ( $value ) {
			$value = (string) $value;
			if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$value = "'" . $value;
			}
			return $value;
		};

		fputcsv(
			$out,
			array( 'Reference', 'Date', 'Status', 'Name', 'Company', 'Email', 'Phone', 'Country', 'Products', 'Source' )
		);

		foreach ( $quotes as $quote ) {
			$customer = (array) get_post_meta( $quote->ID, '_raq_customer', true );
			$items    = array_filter( (array) get_post_meta( $quote->ID, '_raq_items', true ), 'is_array' );
			$status   = get_post_meta( $quote->ID, '_raq_status', true );

			$products = array();
			foreach ( $items as $item ) {
				$products[] = ( isset( $item['name'] ) ? $item['name'] : '' ) . ' x ' . ( isset( $item['qty'] ) ? $item['qty'] : 1 );
			}

			fputcsv(
				$out,
				array(
					$safe( get_post_meta( $quote->ID, '_raq_reference', true ) ),
					get_the_date( 'Y-m-d H:i', $quote ),
					isset( $labels[ $status ] ) ? $labels[ $status ] : $status,
					$safe( RAQ_Email_Helper::field_value( $customer, 'name' ) ),
					$safe( RAQ_Email_Helper::field_value( $customer, 'company' ) ),
					$safe( RAQ_Email_Helper::field_value( $customer, 'email' ) ),
					$safe( RAQ_Email_Helper::field_value( $customer, 'phone' ) ),
					$safe( RAQ_Email_Helper::field_value( $customer, 'country' ) ),
					$safe( implode( '; ', $products ) ),
					$safe( get_post_meta( $quote->ID, '_raq_source_url', true ) ),
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- writing to php://output.
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Secure attachment download
	 * ------------------------------------------------------------------ */

	/**
	 * Stream a quote attachment to an authorised user, guarding against path
	 * traversal (the file must live inside uploads/raq-quotes).
	 */
	public static function download_attachment() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'request-a-quote-for-woocommerce' ) );
		}
		check_admin_referer( 'raq_download_' . $post_id );

		$attachment = (array) get_post_meta( $post_id, '_raq_attachment', true );
		if ( empty( $attachment['file'] ) ) {
			wp_die( esc_html__( 'File not found.', 'request-a-quote-for-woocommerce' ) );
		}

		$uploads = wp_get_upload_dir();
		$base    = realpath( trailingslashit( $uploads['basedir'] ) . 'raq-quotes' );
		$real    = realpath( $attachment['file'] );

		if ( ! $real || ! $base || 0 !== strpos( $real, $base ) || ! is_file( $real ) ) {
			wp_die( esc_html__( 'File not found.', 'request-a-quote-for-woocommerce' ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( ! empty( $attachment['type'] ) ? $attachment['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( ! empty( $attachment['name'] ) ? $attachment['name'] : basename( $real ) ) . '"' );
		header( 'Content-Length: ' . filesize( $real ) );
		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a validated local file.
		exit;
	}
}
