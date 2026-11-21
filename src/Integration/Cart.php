<?php
/**
 * Campaign cart refresh and optional coupon protection.
 *
 * @package ExinBlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Application\PriceState;
use Exin\BlackFriday\Domain\IntegerMath;
use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Infrastructure\CampaignRepository;

/** Never writes product, order, payment or cashback amounts. */
final class Cart {
	/**
	 * Receive the campaign repository.
	 *
	 * @param CampaignRepository $repo Repo.
	 */
	public function __construct( private CampaignRepository $repo ) {}

	/**
	 * Register the WooCommerce integration hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'refresh' ), 20 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'coupon_items' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate' ), 30 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_errors' ), 30, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'blocks_errors' ), 30, 2 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'totals' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'totals' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'blocks' ) );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->blocks(); }
	}

	/**
	 * Refresh campaign cart lines from current WooCommerce prices.
	 *
	 * @param mixed $cart Cart.
	 */
	public function refresh( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return; }
		foreach ( $cart->get_cart() as $key => $line ) {
			$product = wc_get_product( ! empty( $line['variation_id'] ) ? $line['variation_id'] : $line['product_id'] );
			if ( ! $product || PriceState::dynamic( $product ) ) {
				continue; }
			$campaign = $this->repo->campaign_for_product( $product->get_id() );
			if ( ! $campaign && empty( $line['exin_bf_seen_campaign'] ) ) {
				continue; }
			if ( $campaign && ! ( new Eligibility( $this->repo ) )->owns( $campaign, $product->get_id(), $product ) ) {
				continue; }
			$now                                 = PriceState::money( $product->get_price() );
			$old                                 = $line['exin_bf_seen_price'] ?? PriceState::money( $line['data']->get_price() );
			$cart->cart_contents[ $key ]['data'] = clone $product;
			$cart->cart_contents[ $key ]['exin_bf_seen_price']    = $now;
			$cart->cart_contents[ $key ]['exin_bf_seen_campaign'] = $campaign ? $campaign['id'] : $line['exin_bf_seen_campaign'];
			if ( $old !== $now ) {
				// translators: 1: product name, 2: previous price, 3: current price.
				$message = sprintf( __( 'قیمت «%1$s» از %2$s به %3$s به‌روز شد؛ مبلغ جدید سبد را پیش از پرداخت بررسی کنید.', 'exin-black-friday' ), $product->get_name(), html_entity_decode( wp_strip_all_tags( wc_price( $old ) ), ENT_QUOTES, 'UTF-8' ), html_entity_decode( wp_strip_all_tags( wc_price( $now ) ), ENT_QUOTES, 'UTF-8' ) );
				if ( WC()->session ) {
					WC()->session->set(
						'exin_bf_price_notice',
						array(
							'token'   => wp_generate_uuid4(),
							'message' => $message,
						)
					); }
				if ( function_exists( 'wc_add_notice' ) && ! wc_has_notice( esc_html( $message ), 'notice' ) ) {
					wc_add_notice( esc_html( $message ), 'notice' ); }
			}
		}
	}

	/**
	 * Exclude protected cart lines from WooCommerce coupon distribution.
	 *
	 * @param array $items Items.
	 */
	public function coupon_items( array $items ): array {
		return array_filter(
			$items,
			function ( $entry ): bool {
				// Order recalculation is deliberately excluded: saved orders are immutable here.
				if ( ! isset( $entry->object ) || ! is_array( $entry->object ) || ! isset( $entry->product ) ) {
					return true; }
				return null === $this->protected_item( $entry->product->get_id() );
			}
		);
	}

	/**
	 * Identify a current owned product with coupon floor protection enabled.
	 *
	 * @param int $product_id Product id.
	 */
	private function protected_item( int $product_id ): ?array {
		$campaign = $this->repo->campaign_for_product( $product_id );
		if ( ! $campaign || 'protect' !== ( $campaign['settings']['coupon_floor_policy'] ?? 'allow' ) || ! ( new Eligibility( $this->repo ) )->owns( $campaign, $product_id ) ) {
			return null; }
		return $this->repo->item( $campaign['id'], $product_id );
	}

	/**
	 * Check final protected cart line amounts against product floors.
	 *
	 * @param mixed $cart Cart.
	 */
	public function floor_errors( $cart ): array {
		$messages = array();
		if ( ! $cart instanceof \WC_Cart ) {
			return $messages; }
		foreach ( $cart->get_cart() as $line ) {
			$item = $this->protected_item( (int) ( ! empty( $line['variation_id'] ) ? $line['variation_id'] : $line['product_id'] ) );
			if ( ! $item || ! isset( $line['line_total'] ) ) {
				continue; }
			$precision                   = wc_get_price_decimals();
			$quantity                    = Money::parse( wc_format_decimal( $line['quantity'], 6 ), 6 );
			list( $minimum, $remainder ) = IntegerMath::divide( IntegerMath::multiply( (string) Money::parse( wc_format_decimal( wc_get_price_excluding_tax( $line['data'], array( 'price' => $item['floor_price'] ) ), $precision ), $precision ), (string) $quantity ), '1000000' );
			$required                    = (int) $minimum + ( '0' === $remainder ? 0 : 1 );
			if ( Money::parse( wc_format_decimal( $line['line_total'], $precision ), $precision ) < $required ) {
				$messages[] = __( 'تخفیف اضافی، مبلغ یکی از اقلام جشنواره را از کف مجاز پایین‌تر برده است؛ کوپن یا تخفیف اضافی را حذف کنید.', 'exin-black-friday' ); }
		}
		return array_values( array_unique( $messages ) );
	}

	/**
	 * Expose classic cart floor validation errors.
	 */
	public function validate(): void {
		foreach ( $this->floor_errors( WC()->cart ) as $message ) {
			if ( ! wc_has_notice( $message, 'error' ) ) {
				wc_add_notice( $message, 'error' ); }
		}
	}

	/**
	 * Add protected floor errors to classic checkout validation.
	 *
	 * @param mixed $data Data.
	 * @param mixed $errors Errors.
	 */
	public function checkout_errors( $data, $errors ): void {
		$this->blocks_errors( $errors, WC()->cart ); }

	/**
	 * Add protected floor errors to WooCommerce Blocks validation.
	 *
	 * @param mixed $errors Errors.
	 * @param mixed $cart Cart.
	 */
	public function blocks_errors( $errors, $cart ): void {
		foreach ( $this->floor_errors( $cart ) as $message ) {
			$errors->add( 'exin_bf_coupon_floor', $message ); }
	}

	/**
	 * Customer savings reflect real line totals, without calculating cashback.
	 *
	 * @throws \OverflowException When a monetary display exceeds the integer range.
	 */
	public function data(): array {
		$total     = 0;
		$cashback  = '';
		$precision = wc_get_price_decimals();
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $line ) {
				$pid      = (int) ( ! empty( $line['variation_id'] ) ? $line['variation_id'] : $line['product_id'] );
				$campaign = $this->repo->campaign_for_product( $pid );
				if ( ! $campaign ) {
					continue; }
				$item = $this->repo->item( $campaign['id'], $pid );
				if ( ! $item || ! ( new Eligibility( $this->repo ) )->owns( $campaign, $pid ) ) {
					continue; }
				list( $basis ) = IntegerMath::divide( IntegerMath::multiply( (string) Money::parse( wc_format_decimal( wc_get_price_excluding_tax( $line['data'], array( 'price' => $item['base_price'] ) ), $precision ), $precision ), (string) Money::parse( wc_format_decimal( $line['quantity'], 6 ), 6 ) ), '1000000' );
				if ( IntegerMath::compare( $basis, (string) PHP_INT_MAX ) > 0 ) {
					throw new \OverflowException( 'cart_savings_overflow' ); }
				$net    = Money::parse( wc_format_decimal( $line['line_total'] ?? 0, $precision ), $precision );
				$saving = max( 0, (int) $basis - $net );
				if ( $saving > PHP_INT_MAX - $total ) {
					throw new \OverflowException( 'cart_savings_overflow' ); }
				$total += $saving;
				if ( ! empty( $campaign['settings']['cashback_message_enabled'] ) ) {
					$cashback = (string) ( $campaign['settings']['cashback_message'] ?? '' ); }
			}
		}
		if ( IntegerMath::compare( $total, (string) PHP_INT_MAX ) > 0 ) {
			throw new \OverflowException( 'cart_savings_overflow' ); }
		$notice = WC()->session ? WC()->session->get( 'exin_bf_price_notice', array() ) : array();
		// translators: %s: formatted real savings amount.
		return array(
			'price_change_token'   => (string) ( $notice['token'] ?? '' ),
			'price_change_message' => (string) ( $notice['message'] ?? '' ),
			'savings'              => Money::format( (int) $total, $precision ),
			// translators: %s: formatted real savings amount.
			'savings_label'        => sprintf( __( 'صرفه‌جویی واقعی اقلام جشنواره با تخفیف‌ها: %s', 'exin-black-friday' ), html_entity_decode( wp_strip_all_tags( wc_price( Money::format( (int) $total, $precision ) ) ), ENT_QUOTES, 'UTF-8' ) ),
			'cashback_message'     => $cashback,
		);
	}

	/**
	 * Render real savings and the independent promotional message.
	 */
	public function totals(): void {
		$data = $this->data();
		if ( Money::parse( $data['savings'], wc_get_price_decimals() ) > 0 ) {
			echo '<tr class="exin-bf-savings"><th>' . esc_html__( 'صرفه‌جویی اقلام جشنواره', 'exin-black-friday' ) . '</th><td>' . wp_kses_post( wc_price( $data['savings'] ) ) . '</td></tr>'; }
		if ( $data['cashback_message'] ) {
			echo '<tr class="exin-bf-cashback"><td colspan="2">' . esc_html( $data['cashback_message'] ) . '</td></tr>'; }
	}

	/**
	 * Load cart display assets on cart and checkout pages.
	 */
	public function assets(): void {
		if ( is_cart() || is_checkout() ) {
			wp_enqueue_script( 'exin-bf-cart', EXIN_BF_URL . 'assets/cart.js', array( 'wp-data' ), EXIN_BF_VERSION, true ); }
	}

	/**
	 * Register read-only WooCommerce Store API cart extension data.
	 */
	public function blocks(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return; }
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'exin-bf',
				'data_callback'   => array( $this, 'data' ),
				'schema_callback' => static function (): array {
					$schema = array();
					foreach ( array( 'price_change_token', 'price_change_message', 'savings', 'savings_label', 'cashback_message' ) as $key ) {
						$schema[ $key ] = array(
							'type'     => 'string',
							'readonly' => true,
						);
					} return $schema;
				},
				'schema_type'     => ARRAY_A,
			)
		);
	}
}
