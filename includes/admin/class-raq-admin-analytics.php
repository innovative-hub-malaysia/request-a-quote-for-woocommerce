<?php
/**
 * Backend analytics dashboard for quotes.
 *
 * Designed top-down around the questions a sales lead actually asks:
 *   1. How are we doing?      -> KPI tiles (30d volume vs previous, win rate,
 *                                awaiting action, in progress)
 *   2. What needs me NOW?     -> stale "New" quotes with direct links
 *   3. Is demand growing?     -> 12-week trend
 *   4. Are we converting?     -> status funnel with shares
 *   5. What / where?          -> top products, categories, source pages
 *
 * Dependency-free: one query, folded in PHP, rendered with inline-CSS bars.
 * One data accent colour; status colours are semantic and consistent with the
 * list-table badges. Numbers use tabular figures.
 *
 * Note: aggregates in-request. Fine for quote-store volumes; move to a summary
 * table if a client ever accumulates tens of thousands of quotes.
 *
 * @package RequestAQuoteForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RAQ_Admin_Analytics
 */
class RAQ_Admin_Analytics {

	const CAP        = 'edit_shop_orders';
	const WEEKS      = 12;
	const STALE_DAYS = 3;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 70 );
	}

	/**
	 * Add the Analytics submenu under Quotes.
	 */
	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . RAQ_CPT::POST_TYPE,
			__( 'Quote Analytics', 'request-a-quote-for-woocommerce' ),
			__( 'Analytics', 'request-a-quote-for-woocommerce' ),
			self::CAP,
			'raq-analytics',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Query all quotes once and fold them into every metric on the page.
	 *
	 * @return array
	 */
	protected static function gather() {
		$quotes = get_posts(
			array(
				'post_type'   => RAQ_CPT::POST_TYPE,
				'post_status' => array_keys( RAQ_CPT::statuses() ),
				'numberposts' => -1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		// True epoch on both sides: time() vs get_post_time('U', true) - mixing
		// current_time('timestamp') with GMT post times skews windows by the
		// site's UTC offset.
		$now      = time();
		$d30      = $now - 30 * DAY_IN_SECONDS;
		$d60      = $now - 60 * DAY_IN_SECONDS;
		$statuses = array_fill_keys( array_keys( RAQ_CPT::statuses() ), 0 );

		$data = array(
			'total'     => count( $quotes ),
			'last30'    => 0,
			'prev30'    => 0,
			'statuses'  => $statuses,
			'by_week'   => array(),
			'stale'     => array(),
			'products'  => array(),
			'cats'      => array(),
			'sources'   => array(),
		);

		// Seed the last N weeks so empty weeks still plot.
		for ( $i = self::WEEKS - 1; $i >= 0; $i-- ) {
			$key                     = gmdate( 'Y-m-d', strtotime( "-{$i} weeks", strtotime( 'monday this week', $now ) ) );
			$data['by_week'][ $key ] = 0;
		}

		foreach ( $quotes as $quote ) {
			$ts     = get_post_time( 'U', true, $quote );
			$status = get_post_meta( $quote->ID, '_raq_status', true );
			$status = $status ? $status : $quote->post_status;

			if ( isset( $data['statuses'][ $status ] ) ) {
				$data['statuses'][ $status ]++;
			}

			if ( $ts >= $d30 ) {
				$data['last30']++;
			} elseif ( $ts >= $d60 ) {
				$data['prev30']++;
			}

			$week_key = gmdate( 'Y-m-d', strtotime( 'monday this week', $ts ) );
			if ( isset( $data['by_week'][ $week_key ] ) ) {
				$data['by_week'][ $week_key ]++;
			}

			// Stale: still New after STALE_DAYS.
			if ( RAQ_CPT::STATUS_NEW === $status && ( $now - $ts ) > self::STALE_DAYS * DAY_IN_SECONDS && count( $data['stale'] ) < 8 ) {
				$customer        = (array) get_post_meta( $quote->ID, '_raq_customer', true );
				$data['stale'][] = array(
					'id'    => $quote->ID,
					'ref'   => get_post_meta( $quote->ID, '_raq_reference', true ),
					'title' => $quote->post_title,
					'who'   => RAQ_Email_Helper::field_value( $customer, 'company' ) ? RAQ_Email_Helper::field_value( $customer, 'company' ) : RAQ_Email_Helper::field_value( $customer, 'name' ),
					'days'  => (int) floor( ( $now - $ts ) / DAY_IN_SECONDS ),
				);
			}

			foreach ( array_filter( (array) get_post_meta( $quote->ID, '_raq_items', true ), 'is_array' ) as $item ) {
				$name = isset( $item['name'] ) ? $item['name'] : '';
				if ( '' === $name ) {
					continue;
				}
				$qty                        = isset( $item['qty'] ) ? absint( $item['qty'] ) : 1;
				$data['products'][ $name ] = ( isset( $data['products'][ $name ] ) ? $data['products'][ $name ] : 0 ) + $qty;

				if ( ! empty( $item['product_id'] ) ) {
					$terms = get_the_terms( $item['product_id'], 'product_cat' );
					if ( $terms && ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$data['cats'][ $term->name ] = ( isset( $data['cats'][ $term->name ] ) ? $data['cats'][ $term->name ] : 0 ) + $qty;
						}
					}
				}
			}

			$source = get_post_meta( $quote->ID, '_raq_source_url', true );
			if ( $source ) {
				$path                      = wp_parse_url( $source, PHP_URL_PATH );
				$path                      = $path ? $path : '/';
				$data['sources'][ $path ] = ( isset( $data['sources'][ $path ] ) ? $data['sources'][ $path ] : 0 ) + 1;
			}
		}

		arsort( $data['products'] );
		arsort( $data['cats'] );
		arsort( $data['sources'] );
		$data['products'] = array_slice( $data['products'], 0, 8, true );
		$data['cats']     = array_slice( $data['cats'], 0, 8, true );
		$data['sources']  = array_slice( $data['sources'], 0, 8, true );

		return $data;
	}

	/**
	 * A ranked horizontal-bar list (direct-labeled, no legend needed).
	 *
	 * @param array $rows label => count.
	 */
	protected static function bar_list( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p class="raq-an-empty">' . esc_html__( 'No data yet.', 'request-a-quote-for-woocommerce' ) . '</p>';
			return;
		}
		$max = max( 1, max( $rows ) );
		foreach ( $rows as $label => $count ) {
			$pct = round( $count / $max * 100 );
			echo '<div class="raq-an-row">';
			echo '<span class="lbl" title="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</span>';
			echo '<span class="bar"><span style="width:' . esc_attr( $pct ) . '%"></span></span>';
			echo '<span class="val">' . (int) $count . '</span>';
			echo '</div>';
		}
	}

	/**
	 * Render the dashboard.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this.', 'request-a-quote-for-woocommerce' ) );
		}

		$d      = self::gather();
		$labels = RAQ_CPT::statuses();

		$won     = $d['statuses'][ RAQ_CPT::STATUS_WON ];
		$lost    = $d['statuses'][ RAQ_CPT::STATUS_LOST ];
		$decided = $won + $lost;
		$winrate = $decided > 0 ? round( $won / $decided * 100 ) : null;

		$delta      = $d['last30'] - $d['prev30'];
		$delta_txt  = ( $delta > 0 ? '+' : '' ) . $delta;
		$delta_mod  = $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : 'flat' );
		$list_base  = admin_url( 'edit.php?post_type=' . RAQ_CPT::POST_TYPE );
		?>
		<div class="wrap raq-analytics">
			<h1><?php esc_html_e( 'Quote Analytics', 'request-a-quote-for-woocommerce' ); ?></h1>

			<style>
				.raq-analytics{--acc:#2271b1;--ink:#1d2327;--mut:#646970;--line:#e2e4e7}
				.raq-an-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin:16px 0 4px}
				.raq-an-kpi{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px 16px}
				.raq-an-kpi .k{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--mut)}
				.raq-an-kpi .v{font-size:30px;font-weight:700;line-height:1.2;color:var(--ink);font-variant-numeric:tabular-nums}
				.raq-an-kpi .s{font-size:12px;color:var(--mut)}
				.raq-an-kpi .s.up{color:#00844a}.raq-an-kpi .s.down{color:#b32d2e}
				.raq-an-kpi .v a{text-decoration:none}
				.raq-an-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-top:14px}
				.raq-an-card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px 16px}
				.raq-an-card h2{font-size:14px;margin:0 0 4px;color:var(--ink)}
				.raq-an-card .sub{font-size:12px;color:var(--mut);margin:0 0 12px}
				.raq-an-card--wide{grid-column:1/-1}
				.raq-an-empty{color:var(--mut)}
				.raq-an-row{display:flex;align-items:center;gap:10px;margin:7px 0;font-size:13px}
				.raq-an-row .lbl{width:42%;flex:none;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
				.raq-an-row .bar{flex:1;background:#f0f0f1;border-radius:4px;height:14px;overflow:hidden}
				.raq-an-row .bar>span{display:block;height:100%;background:var(--acc)}
				.raq-an-row .val{width:46px;flex:none;text-align:right;font-variant-numeric:tabular-nums;color:var(--ink)}
				.raq-an-row--new .bar>span{background:#996800}
				.raq-an-row--quoted .bar>span{background:#2271b1}
				.raq-an-row--won .bar>span{background:#00844a}
				.raq-an-row--lost .bar>span{background:#b32d2e}
				.raq-an-week{display:flex;align-items:flex-end;gap:5px;height:130px;margin-top:6px}
				.raq-an-week .col{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px;min-width:0}
				.raq-an-week .col>.c{font-size:11px;color:var(--mut);font-variant-numeric:tabular-nums}
				.raq-an-week .col>.b{width:100%;background:var(--acc);border-radius:3px 3px 0 0;min-height:2px}
				.raq-an-week .col>.d{font-size:10px;color:var(--mut);white-space:nowrap}
				.raq-an-attn{border-left:4px solid #996800}
				.raq-an-attn table{width:100%;border-collapse:collapse;font-size:13px}
				.raq-an-attn td,.raq-an-attn th{text-align:left;padding:6px 4px;border-bottom:1px solid #f0f0f1}
				.raq-an-attn td:last-child{text-align:right}
				.raq-an-attn .age{color:#996800;font-weight:600;font-variant-numeric:tabular-nums}
			</style>

			<?php // 1. KPI row - the answer at a glance. ?>
			<div class="raq-an-kpis">
				<div class="raq-an-kpi">
					<div class="k"><?php esc_html_e( 'Quotes - last 30 days', 'request-a-quote-for-woocommerce' ); ?></div>
					<div class="v"><?php echo (int) $d['last30']; ?></div>
					<div class="s <?php echo esc_attr( $delta_mod ); ?>"><?php echo esc_html( $delta_txt ); ?> <?php esc_html_e( 'vs previous 30 days', 'request-a-quote-for-woocommerce' ); ?></div>
				</div>
				<div class="raq-an-kpi">
					<div class="k"><?php esc_html_e( 'Win rate', 'request-a-quote-for-woocommerce' ); ?></div>
					<div class="v"><?php echo null === $winrate ? '&mdash;' : (int) $winrate . '%'; ?></div>
					<div class="s"><?php echo esc_html( sprintf( /* translators: 1: won, 2: lost. */ __( '%1$d won / %2$d lost', 'request-a-quote-for-woocommerce' ), $won, $lost ) ); ?></div>
				</div>
				<div class="raq-an-kpi">
					<div class="k"><?php esc_html_e( 'Awaiting action (New)', 'request-a-quote-for-woocommerce' ); ?></div>
					<div class="v"><a href="<?php echo esc_url( add_query_arg( 'post_status', RAQ_CPT::STATUS_NEW, $list_base ) ); ?>"><?php echo (int) $d['statuses'][ RAQ_CPT::STATUS_NEW ]; ?></a></div>
					<div class="s"><?php esc_html_e( 'not yet quoted', 'request-a-quote-for-woocommerce' ); ?></div>
				</div>
				<div class="raq-an-kpi">
					<div class="k"><?php esc_html_e( 'In progress (Quoted)', 'request-a-quote-for-woocommerce' ); ?></div>
					<div class="v"><a href="<?php echo esc_url( add_query_arg( 'post_status', RAQ_CPT::STATUS_QUOTED, $list_base ) ); ?>"><?php echo (int) $d['statuses'][ RAQ_CPT::STATUS_QUOTED ]; ?></a></div>
					<div class="s"><?php esc_html_e( 'awaiting customer decision', 'request-a-quote-for-woocommerce' ); ?></div>
				</div>
			</div>

			<?php // 2. Needs attention - stale New quotes with direct links. ?>
			<?php if ( $d['stale'] ) : ?>
				<div class="raq-an-grid">
					<div class="raq-an-card raq-an-card--wide raq-an-attn">
						<h2><?php esc_html_e( 'Needs attention', 'request-a-quote-for-woocommerce' ); ?></h2>
						<p class="sub"><?php echo esc_html( sprintf( /* translators: %d: days. */ __( 'Still "New" after %d days - customers are waiting on these.', 'request-a-quote-for-woocommerce' ), self::STALE_DAYS ) ); ?></p>
						<table>
							<tbody>
							<?php foreach ( $d['stale'] as $row ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $row['id'] . '&action=edit' ) ); ?>"><strong><?php echo esc_html( $row['ref'] ? $row['ref'] : $row['title'] ); ?></strong></a></td>
									<td><?php echo esc_html( $row['who'] ); ?></td>
									<td class="age"><?php echo esc_html( sprintf( /* translators: %d: days. */ _n( '%d day waiting', '%d days waiting', $row['days'], 'request-a-quote-for-woocommerce' ), $row['days'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>

			<div class="raq-an-grid">

				<?php // 3. Trend. ?>
				<div class="raq-an-card raq-an-card--wide">
					<h2><?php echo esc_html( sprintf( /* translators: %d: weeks. */ __( 'Quotes per week - last %d weeks', 'request-a-quote-for-woocommerce' ), self::WEEKS ) ); ?></h2>
					<p class="sub"><?php echo esc_html( sprintf( /* translators: %d: total. */ __( '%d quotes all time', 'request-a-quote-for-woocommerce' ), $d['total'] ) ); ?></p>
					<?php $wmax = max( 1, max( $d['by_week'] ) ); ?>
					<div class="raq-an-week">
						<?php foreach ( $d['by_week'] as $week => $count ) : ?>
							<div class="col">
								<span class="c"><?php echo (int) $count; ?></span>
								<span class="b" style="height:<?php echo esc_attr( round( $count / $wmax * 100 ) ); ?>%"></span>
								<span class="d"><?php echo esc_html( gmdate( 'j M', strtotime( $week ) ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<?php // 4. Funnel. ?>
				<div class="raq-an-card">
					<h2><?php esc_html_e( 'Status funnel', 'request-a-quote-for-woocommerce' ); ?></h2>
					<p class="sub"><?php esc_html_e( 'Share of all quotes per stage', 'request-a-quote-for-woocommerce' ); ?></p>
					<?php
					$fmax = max( 1, max( $d['statuses'] ) );
					foreach ( $d['statuses'] as $slug => $count ) :
						$pct   = round( $count / $fmax * 100 );
						$share = $d['total'] > 0 ? round( $count / $d['total'] * 100 ) : 0;
						$mod   = str_replace( 'raq-', '', $slug );
						?>
						<div class="raq-an-row raq-an-row--<?php echo esc_attr( $mod ); ?>">
							<span class="lbl"><?php echo esc_html( ( isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug ) . ' (' . $share . '%)' ); ?></span>
							<span class="bar"><span style="width:<?php echo esc_attr( $pct ); ?>%"></span></span>
							<span class="val"><?php echo (int) $count; ?></span>
						</div>
					<?php endforeach; ?>
				</div>

				<?php // 5. Where demand comes from + what it wants. ?>
				<div class="raq-an-card">
					<h2><?php esc_html_e( 'Top source pages', 'request-a-quote-for-woocommerce' ); ?></h2>
					<p class="sub"><?php esc_html_e( 'Where quote requests are submitted from', 'request-a-quote-for-woocommerce' ); ?></p>
					<?php self::bar_list( $d['sources'] ); ?>
				</div>

				<div class="raq-an-card">
					<h2><?php esc_html_e( 'Top requested products', 'request-a-quote-for-woocommerce' ); ?></h2>
					<p class="sub"><?php esc_html_e( 'By total units requested', 'request-a-quote-for-woocommerce' ); ?></p>
					<?php self::bar_list( $d['products'] ); ?>
				</div>

				<div class="raq-an-card">
					<h2><?php esc_html_e( 'By category', 'request-a-quote-for-woocommerce' ); ?></h2>
					<p class="sub"><?php esc_html_e( 'By total units requested', 'request-a-quote-for-woocommerce' ); ?></p>
					<?php self::bar_list( $d['cats'] ); ?>
				</div>

			</div>
		</div>
		<?php
	}
}
