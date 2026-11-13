<?php
/**
 * Shortcodes and Elementor use the same presentation service.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Infrastructure\CampaignRepository;

/**
 * Shortcodes and Elementor use the same presentation service.
 */
final class Storefront {
	/**
	 * Shared query service.
	 *
	 * @var PublicProductQuery
	 */
	private PublicProductQuery $query;

	/**
	 * Receive the campaign repository.
	 *
	 * @param CampaignRepository $repository Campaign state repository.
	 */
	public function __construct( private CampaignRepository $repository ) {
		$this->query = new PublicProductQuery( $repository );
	}

	/**
	 * Register the WordPress and WooCommerce integration hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'exin_bf_products', fn( $atts ) => $this->render( 'products', (array) $atts ) );
		add_shortcode( 'exin_bf_featured', fn( $atts ) => $this->render( 'featured', (array) $atts ) );
		add_shortcode( 'exin_bf_countdown', fn( $atts ) => $this->render( 'countdown', (array) $atts ) );
		add_shortcode( 'exin_bf_categories', fn( $atts ) => $this->render( 'categories', (array) $atts ) );
		add_shortcode( 'exin_bf_status', fn( $atts ) => $this->render( 'status', (array) $atts ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'elementor/widgets/register', array( $this, 'widgets' ) );
	}

	/**
	 * Register the public server-time endpoint with a strict output allowlist.
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			'exin-bf/v1',
			'/time',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$campaign = $this->repository->current();
					$live = CampaignContext::is_live( $campaign );
					$response = new \WP_REST_Response(
						array(
							'server_time' => time(),
							'active'      => $live,
							'campaign_id' => $live ? (int) $campaign['id'] : null,
							'ends_at'     => $live ? strtotime( $campaign['end_utc'] . ' UTC' ) : null,
						)
					);
					$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
					return $response;
				},
			)
		);
	}

	/**
	 * Register the six optional Elementor widgets.
	 *
	 * @param mixed $manager Elementor widget manager.
	 * @return void
	 */
	public function widgets( $manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		Elementor\CampaignWidget::use_storefront( $this );
		foreach ( array( Elementor\ProductsWidget::class, Elementor\FeaturedWidget::class, Elementor\CountdownWidget::class, Elementor\CategoriesWidget::class, Elementor\StatusWidget::class, Elementor\BadgeWidget::class ) as $class ) {
			$manager->register( new $class() );
		}
	}

	/**
	 * Render escaped presentation output while containing internal failures.
	 *
	 * @param string $kind Presentation component identifier.
	 * @param array  $attributes Shortcode or widget presentation options.
	 * @return string Escaped presentation markup or component metadata.
	 */
	public function render( string $kind, array $attributes = array() ): string {
		try {
			return $this->render_output( $kind, $attributes );
		} catch ( \Throwable $error ) {
			// Internal errors must not leak product state or server details to customers.
			do_action( 'exin_bf_presentation_error', 'render_failed' );
			return '';
		}
	}

	/**
	 * Dispatch a presentation component using the shared campaign policy.
	 *
	 * @param string $kind Presentation component identifier.
	 * @param array  $attributes Shortcode or widget presentation options.
	 * @return string Escaped presentation markup or component metadata.
	 */
	private function render_output( string $kind, array $attributes ): string {
		$campaign = $this->repository->current();
		if ( ! $campaign ) {
			return '';
		}
		$this->assets();
		if ( 'status' === $kind ) {
			return $this->status( $campaign );
		}
		if ( ! CampaignContext::is_live( $campaign ) ) {
			return 'empty' === ( $campaign['settings']['inactive_output'] ?? 'message' ) ? '' : '<p class="exin-bf-message">' . esc_html__( 'کمپین در حال حاضر فعال نیست.', 'exin-black-friday' ) . '</p>';
		}
		if ( 'countdown' === $kind ) {
			return '<div class="exin-bf-countdown" role="timer" aria-label="' . esc_attr__( 'زمان باقی‌مانده کمپین', 'exin-black-friday' ) . '" data-exin-bf-countdown data-campaign="' . esc_attr( (string) $campaign['id'] ) . '"><span>' . esc_html__( 'در حال دریافت زمان سرور…', 'exin-black-friday' ) . '</span></div>';
		}
		if ( 'categories' === $kind ) {
			return $this->categories( $campaign );
		}
		if ( 'badge' === $kind ) {
			return $this->badge( $campaign, absint( $attributes['product_id'] ?? get_the_ID() ) );
		}
		return $this->products( $campaign, $attributes, 'featured' === $kind );
	}

	/**
	 * Enqueue presentation assets only when a campaign component is rendered.
	 *
	 * @return void
	 */
	private function assets(): void {
		if ( ! wp_style_is( 'exin-bf-public', 'registered' ) ) {
			wp_register_style( 'exin-bf-public', EXIN_BF_URL . 'assets/public.css', array(), EXIN_BF_VERSION );
			wp_register_script( 'exin-bf-public', EXIN_BF_URL . 'assets/public.js', array(), EXIN_BF_VERSION, true );
			wp_localize_script(
				'exin-bf-public',
				'exinBFPublic',
				array(
					'timeUrl'     => esc_url_raw( rest_url( 'exin-bf/v1/time' ) ),
					'ended'       => __( 'کمپین پایان یافته است.', 'exin-black-friday' ),
					'unavailable' => __( 'زمان سرور در دسترس نیست.', 'exin-black-friday' ),
					'days'        => __( 'روز', 'exin-black-friday' ),
				)
			);
		}
		wp_enqueue_style( 'exin-bf-public' );
		wp_enqueue_script( 'exin-bf-public' );
	}

	/**
	 * Render a bounded product grid using WooCommerce templates.
	 *
	 * @param array $campaign Campaign configuration loaded from the repository.
	 * @param array $attributes Shortcode or widget presentation options.
	 * @param bool  $featured Whether to restrict selection to featured items.
	 * @return string Escaped presentation markup or component metadata.
	 */
	private function products( array $campaign, array $attributes, bool $featured ): string {
		$atts     = shortcode_atts(
			array(
				'columns'           => 4,
				'tablet_columns'    => 2,
				'mobile_columns'    => 1,
				'limit'             => 12,
				'orderby'           => 'priority',
				'category'          => 0,
				'show_out_of_stock' => ! empty( $campaign['settings']['show_out_of_stock'] ),
				'pagination'        => 'yes',
			),
			$attributes
		);
		$page     = isset( $_GET['exin_bf_page'] ) ? absint( wp_unslash( $_GET['exin_bf_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$category = ! empty( $atts['category'] ) ? absint( $atts['category'] ) : ( isset( $_GET['exin_bf_category'] ) ? absint( wp_unslash( $_GET['exin_bf_category'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result   = $this->query->query(
			$campaign,
			array_merge(
				$atts,
				array(
					'featured' => $featured,
					'page'     => $page,
					'category' => $category,
				)
			)
		);
		if ( ! $result['products'] ) {
			return '<p class="exin-bf-message">' . esc_html__( 'محصولی برای نمایش وجود ندارد.', 'exin-black-friday' ) . '</p>';
		}
		$cols        = max( 1, min( 6, (int) $atts['columns'] ) );
		$tablet      = max( 1, min( 4, (int) $atts['tablet_columns'] ) );
		$mobile      = max( 1, min( 2, (int) $atts['mobile_columns'] ) );
		$old_product = $GLOBALS['product'] ?? null;
		$old_post    = $GLOBALS['post'] ?? null;
		$badge_hook  = function () {
			global $product;
			if ( $product instanceof \WC_Product ) {
				echo $this->product_badge( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		};
		$stock_hook  = static function () {
			global $product;
			if ( $product instanceof \WC_Product ) {
				echo wp_kses_post( wc_get_stock_html( $product ) );
			}
		};
		add_action( 'woocommerce_before_shop_loop_item_title', $badge_hook, 9 );
		add_action( 'woocommerce_after_shop_loop_item_title', $stock_hook, 11 );
		$buffer_level = ob_get_level();
		ob_start();
		try {
			echo '<div class="exin-bf-products woocommerce" style="--exin-bf-columns:' . esc_attr( (string) $cols ) . ';--exin-bf-tablet:' . esc_attr( (string) $tablet ) . ';--exin-bf-mobile:' . esc_attr( (string) $mobile ) . '"><ul class="products exin-bf-grid">';
			foreach ( $result['products'] as $product ) {
				$GLOBALS['product'] = $product;
				$GLOBALS['post']    = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Woo template loop context; restored in finally below.
				setup_postdata( $GLOBALS['post'] );
				if ( $product->is_type( 'simple' ) ) {
					wc_get_template_part( 'content', 'product' );
				} else {
					$this->variation_card( $product );
				}
			}
			echo '</ul>';
			if ( 'no' !== $atts['pagination'] && $result['pages'] > 1 ) {
				echo '<nav class="exin-bf-pagination" aria-label="' . esc_attr__( 'صفحات محصولات کمپین', 'exin-black-friday' ) . '">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'exin_bf_page', '%#%' ),
							'format'    => '',
							'current'   => $result['page'],
							'total'     => $result['pages'],
							'prev_text' => __( 'قبلی', 'exin-black-friday' ),
							'next_text' => __( 'بعدی', 'exin-black-friday' ),
						)
					)
				);
				echo '</nav>';
			}
			echo '</div>';
			return (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			remove_action( 'woocommerce_before_shop_loop_item_title', $badge_hook, 9 );
			remove_action( 'woocommerce_after_shop_loop_item_title', $stock_hook, 11 );
			wp_reset_postdata();
			$GLOBALS['product'] = $old_product;
			$GLOBALS['post']    = $old_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the caller's original template context.
		}
	}

	/**
	 * Render one selected variation with WooCommerce pricing and stock.
	 *
	 * @param \WC_Product $product Optional WooCommerce product object.
	 * @return void
	 */
	private function variation_card( \WC_Product $product ): void {
		echo '<li class="product exin-bf-variation"><a href="' . esc_url( $product->get_permalink() ) . '" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">';
		echo $this->product_badge( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) );
		echo '<h2 class="woocommerce-loop-product__title">' . esc_html( $product->get_name() ) . '</h2><span class="price">' . wp_kses_post( $product->get_price_html() ) . '</span></a>';
		echo wp_kses_post( wc_get_stock_html( $product ) );
		if ( $product->is_purchasable() && $product->is_in_stock() ) {
			echo '<a class="button" href="' . esc_url( $product->add_to_cart_url() ) . '" aria-label="' . esc_attr( $product->add_to_cart_description() ) . '">' . esc_html( $product->add_to_cart_text() ) . '</a>';
		}
		echo '</li>';
	}

	/**
	 * Retrieve categories belonging to selected products and variation parents.
	 *
	 * @param array $campaign Campaign configuration loaded from the repository.
	 * @return string Escaped presentation markup or component metadata.
	 */
	private function categories( array $campaign ): string {
		$html  = '<nav class="exin-bf-categories" aria-label="' . esc_attr__( 'دسته‌بندی محصولات کمپین', 'exin-black-friday' ) . '">';
		$html .= '<a href="' . esc_url( remove_query_arg( array( 'exin_bf_category', 'exin_bf_page' ) ) ) . '">' . esc_html__( 'همه', 'exin-black-friday' ) . '</a>';
		foreach ( $this->query->categories( $campaign ) as $term ) {
			$html .= '<a href="' . esc_url(
				add_query_arg(
					array(
						'exin_bf_category' => $term->term_id,
						'exin_bf_page'     => false,
					)
				)
			) . '">' . esc_html( $term->name ) . '</a>';
		}
		return $html . '</nav>';
	}

	/**
	 * Render the current campaign lifecycle message.
	 *
	 * @param array $campaign Campaign configuration loaded from the repository.
	 * @return string Escaped presentation markup or component metadata.
	 */
	private function status( array $campaign ): string {
		$labels = array(
			'draft'     => __( 'کمپین در حال آماده‌سازی است.', 'exin-black-friday' ),
			'scheduled' => __( 'کمپین به‌زودی آغاز می‌شود.', 'exin-black-friday' ),
			'active'    => __( 'کمپین فعال است.', 'exin-black-friday' ),
			'paused'    => __( 'تغییر قیمت‌ها متوقف شده است.', 'exin-black-friday' ),
			'completed' => __( 'کمپین پایان یافته است.', 'exin-black-friday' ),
			'failed'    => __( 'کمپین در حال حاضر فعال نیست.', 'exin-black-friday' ),
		);
		$label  = $labels[ $campaign['status'] ] ?? $labels['failed'];
		if ( in_array( $campaign['status'], array( 'active', 'paused' ), true ) && ! CampaignContext::is_live( $campaign ) ) {
			$label = $labels['completed'];
		}
		return '<p class="exin-bf-status">' . esc_html( $label ) . '</p>';
	}

	/**
	 * Render a badge only for a selected product.
	 *
	 * @param array $campaign Campaign configuration loaded from the repository.
	 * @param int   $product_id Sellable simple-product or variation identifier.
	 * @return string Escaped presentation markup or component metadata.
	 */
	private function badge( array $campaign, int $product_id ): string {
		if ( ! CampaignContext::eligible_item( $this->repository->item( (int) $campaign['id'], $product_id ) ) ) {
			return '';
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '';
		}
		return $this->product_badge( $product );
	}

	/**
	 * Render the current discount percentage for one WooCommerce product.
	 *
	 * @param \WC_Product $product Optional WooCommerce product object.
	 * @return string Escaped presentation markup or component metadata.
	 */
	private function product_badge( \WC_Product $product ): string {
		$label   = __( 'بلک فرایدی EXIN', 'exin-black-friday' );
		$regular = $product->get_regular_price();
		$current = $product->get_price();
		if ( '' !== $regular && '' !== $current && (float) $regular > 0 && (float) $current < (float) $regular ) {
			// Display only; WooCommerce remains the monetary source of truth.
			$label .= ' · ' . number_format_i18n( floor( ( 1 - (float) $current / (float) $regular ) * 100 ) ) . '%';
		}
		return '<span class="exin-bf-badge">' . esc_html( $label ) . '</span>';
	}
}
