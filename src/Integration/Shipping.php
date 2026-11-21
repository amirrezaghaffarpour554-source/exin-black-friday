<?php
/**
 * Bounded campaign shipping subsidy through standard WooCommerce rate filters.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Integration;

use Exin\BlackFriday\Domain\CurrencyDisplay;
use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Domain\ShippingSubsidy;
use Exin\BlackFriday\Infrastructure\CampaignRepository;

/** Shares one cart-wide subsidy between qualifying, authorized flat-rate packages. */
final class Shipping {
	/**
	 * Shared price-ownership eligibility service.
	 *
	 * @var Eligibility
	 */
	private Eligibility $eligibility;

	/**
	 * Server-generated package allocations for the current PHP request only.
	 *
	 * @var array
	 */
	private static array $contexts = array();

	/**
	 * Original rates behind our clones, for idempotent repeated filter calls.
	 *
	 * @var \WeakMap|null
	 */
	private static ?\WeakMap $adjusted = null;

	/**
	 * Receive the campaign repository.
	 *
	 * @param CampaignRepository $repository Campaign state repository.
	 */
	public function __construct( private CampaignRepository $repository ) {
		$this->eligibility = new Eligibility( $repository );
	}

	/**
	 * Register standard cart-package and rate integration hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'packages' ), 100 );
		add_filter( 'woocommerce_package_rates', array( $this, 'rates' ), 100, 2 );
	}

	/**
	 * Allocate the cart-wide cap once, before WooCommerce calculates any rates.
	 *
	 * @param array $packages All current WooCommerce cart shipping packages.
	 * @return array Packages with nonpublic cache-version and allocation context.
	 */
	public function packages( array $packages ): array {
		$campaign = $this->select_campaign( $packages );
		$stamp    = $this->stamp( $campaign );
		$entries  = array();
		try {
			if ( CampaignContext::is_live( $campaign ) && ! empty( $campaign['settings']['free_shipping'] ) ) {
				$config = $this->configuration( $campaign );
				$keys   = array();
				foreach ( $packages as $key => $package ) {
					if ( $this->qualifies( $campaign, $package, $config['threshold'], $config['precision'] ) ) {
						$keys[] = $key;
					}
				}
				$budgets = ShippingSubsidy::allocate( $config['cap'], $config['precision'], $keys );
				foreach ( $packages as $key => $package ) {
					if ( isset( $budgets[ $key ] ) ) {
						$entries[ (string) $key ] = array(
							'fingerprint' => $this->fingerprint( $package ),
							'budget'      => $budgets[ $key ],
						);
					}
				}
			}
		} catch ( \Throwable $error ) {
			$this->record_warning( $campaign, 'configuration_invalid' );
		}
		ksort( $entries, SORT_STRING );
		// One package's share can change when another package becomes eligible.
		// Include the complete allocation in Woo's per-package cache hash so an
		// old full-cap rate cannot coexist with a newly calculated shared rate.
		$allocation               = hash( 'sha256', wp_json_encode( array( $stamp, $entries ) ) );
		self::$contexts[ $stamp ] = array(
			'campaign_id' => $campaign ? (int) $campaign['id'] : 0,
			'allocation'  => $allocation,
			'entries'     => $entries,
		);
		foreach ( $packages as $key => &$package ) {
			$package['exin_bf_rate_version']       = $stamp;
			$package['exin_bf_package_key']        = (string) $key;
			$package['exin_bf_allocation_version'] = $allocation;
		}
		unset( $package );
		return $packages;
	}

	/**
	 * Rebate only reliably calculated, explicitly selected flat-rate instances.
	 *
	 * @param array $rates WooCommerce rates indexed by rate identifier.
	 * @param array $package One package from the complete cart-package filter.
	 * @return array Rates with bounded ex-tax remainders and standard Woo taxes.
	 */
	public function rates( array $rates, array $package ): array {
		$rates    = $this->original_rates( $rates );
		$stamp    = (string) ( $package['exin_bf_rate_version'] ?? '' );
		$context  = self::$contexts[ $stamp ] ?? null;
		$campaign = is_array( $context ) && ! empty( $context['campaign_id'] )
			? $this->repository->get( (int) $context['campaign_id'] )
			: $this->select_campaign( array( $package ) );
		if ( ! CampaignContext::is_live( $campaign ) || empty( $campaign['settings']['free_shipping'] ) ) {
			return $rates;
		}
		try {
			$config  = $this->configuration( $campaign );
			$stamp   = $this->stamp( $campaign );
			$key     = (string) ( $package['exin_bf_package_key'] ?? '' );
			$context = self::$contexts[ $stamp ] ?? null;
			if ( ( $package['exin_bf_rate_version'] ?? '' ) !== $stamp || ! is_array( $context ) || ( $package['exin_bf_allocation_version'] ?? '' ) !== $context['allocation'] ) {
				$this->record_warning( $campaign, 'package_context_missing' );
				return $rates;
			}
			if ( ! $this->qualifies( $campaign, $package, $config['threshold'], $config['precision'] ) ) {
				return $rates;
			}
			$entry = $context['entries'][ $key ] ?? null;
			if ( ! is_array( $entry ) || ! hash_equals( $entry['fingerprint'], $this->fingerprint( $package ) ) ) {
				$this->record_warning( $campaign, 'package_context_missing' );
				return $rates;
			}
			$budget = $entry['budget'];
			if ( Money::parse( $budget, $config['precision'] ) > Money::parse( $config['cap'], $config['precision'] ) ) {
				return $rates;
			}
		} catch ( \Throwable $error ) {
			$this->record_warning( $campaign, 'configuration_invalid' );
			return $rates;
		}
		foreach ( $rates as $rate_key => $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate || ! in_array( (int) $rate->get_instance_id(), $config['allowed'], true ) ) {
				continue;
			}
			if ( 'flat_rate' !== $rate->get_method_id() ) {
				$this->record_warning( $campaign, 'method_unsupported' );
				continue;
			}
			if ( null !== self::$adjusted && isset( self::$adjusted[ $rate ] ) ) {
				// Another filter modified our prior clone; its new base is no longer provable.
				$this->record_warning( $campaign, 'base_or_tax_unsupported' );
				continue;
			}
			try {
				$base      = (string) $rate->get_cost();
				$remaining = ShippingSubsidy::remaining( $base, $budget, $config['precision'] );
				if ( Money::parse( $base, $config['precision'] ) === Money::parse( $remaining, $config['precision'] ) ) {
					continue;
				}
				$taxes = $this->remaining_taxes( $rate, $base, $remaining, $config['precision'] );
				// Only clone this response's rate; never update methods, zones or saved settings.
				$rebated = clone $rate;
				$rebated->set_cost( $remaining );
				$rebated->set_taxes( $taxes );
				self::$adjusted           ??= new \WeakMap();
				self::$adjusted[ $rebated ] = array(
					'original' => $rate,
					'cost'     => $rebated->get_cost(),
					'taxes'    => $rebated->get_taxes(),
				);
				$rates[ $rate_key ]         = $rebated;
			} catch ( \Throwable $error ) {
				$this->record_warning( $campaign, 'base_or_tax_unsupported' );
			}
		}
		return $rates;
	}

	/**
	 * Return clear administrative warnings without exposing customer information.
	 *
	 * @param int|null $campaign_id Optional selected campaign ID.
	 * @return string[] Localized warnings for the authenticated diagnostics screen.
	 */
	public function warnings( ?int $campaign_id = null ): array {
		$campaigns = null === $campaign_id ? $this->repository->live_campaigns() : array( $this->repository->get( $campaign_id ) );
		if ( null === $campaign_id ) {
			$current = $this->repository->current();
			if ( $current ) {
				$campaigns[] = $current;
			}
		}
		$warnings = array();
		$seen     = array();
		foreach ( $campaigns as $campaign ) {
			if ( ! $campaign || isset( $seen[ $campaign['id'] ] ) || empty( $campaign['settings']['free_shipping'] ) ) {
				continue;
			}
			$seen[ $campaign['id'] ] = true;
			$warnings                = array_merge( $warnings, $this->campaign_warnings( $campaign ) );
		}
		return array_values( array_unique( $warnings ) );
	}

	/**
	 * Read policy warnings for one explicitly selected campaign.
	 *
	 * @param array $campaign Server-loaded campaign.
	 * @return string[] Localized warning messages.
	 */
	private function campaign_warnings( array $campaign ): array {
		if ( ! $campaign || empty( $campaign['settings']['free_shipping'] ) ) {
			return array();
		}
		$codes = array();
		try {
			$config = $this->configuration( $campaign );
			foreach ( $config['allowed'] as $instance_id ) {
				$method = \WC_Shipping_Zones::get_shipping_method( $instance_id );
				if ( ! $method || 'flat_rate' !== $method->id || 'yes' !== $method->enabled ) {
					$codes['method_unsupported'] = true;
				}
			}
		} catch ( \Throwable $error ) {
			$codes['configuration_invalid'] = true;
		}
		$stored = get_transient( $this->warning_key( $campaign ) );
		foreach ( is_array( $stored ) ? $stored : array() as $code ) {
			$codes[ $code ] = true;
		}
		$messages = array(
			'configuration_invalid'   => __( 'یارانه ارسال اعمال نمی‌شود: سقف، حدنصاب، واحد پول یا روش مجاز را در تنظیمات بررسی کنید. برای ارزهای غیر ریال/تومان، سقف باید صریحاً در واحد فروشگاه ثبت شود.', 'exin-black-friday' ),
			'method_unsupported'      => __( 'یک روش ارسال انتخاب‌شده حذف، غیرفعال یا ناسازگار است. یارانه فقط روی روش نرخ ثابت مجاز اعمال می‌شود؛ سایر نرخ‌ها بدون تغییر می‌مانند.', 'exin-black-friday' ),
			'base_or_tax_unsupported' => __( 'هزینه پایه یا مالیات یک نرخ ارسال با دقت معتبر قابل محاسبه نبود؛ آن نرخ بدون تغییر باقی ماند. فرمول روش و افزونه‌های تغییر نرخ یا مالیات را بررسی کنید.', 'exin-black-friday' ),
			'package_context_missing' => __( 'یک بسته ارسال خارج از مسیر استاندارد بسته‌بندی ووکامرس محاسبه شد؛ برای جلوگیری از عبور از سقف کل سبد، یارانه آن بسته اعمال نشد.', 'exin-black-friday' ),
		);
		$warnings = array();
		foreach ( array_keys( $codes ) as $code ) {
			if ( isset( $messages[ $code ] ) ) {
				$warnings[] = $messages[ $code ];
			}
		}
		return $warnings;
	}

	/**
	 * Provide the same warning data to administrative health integrations.
	 *
	 * @return array Diagnostic data containing no customer or credential fields.
	 */
	public function diagnostics(): array {
		return array(
			'warnings' => $this->warnings(),
			'policy'   => 'cart_cap_equal_package_shares_ex_tax',
		);
	}

	/**
	 * Select one qualifying policy for the entire cart, never one cap per campaign.
	 *
	 * @param array $packages All current server-owned cart packages.
	 * @return array|null Highest-priority eligible shipping campaign.
	 */
	private function select_campaign( array $packages ): ?array {
		foreach ( $this->repository->live_campaigns() as $campaign ) {
			if ( empty( $campaign['settings']['free_shipping'] ) ) {
				continue;
			}
			try {
				$config = $this->configuration( $campaign );
				foreach ( $packages as $package ) {
					if ( is_array( $package ) && $this->qualifies( $campaign, $package, $config['threshold'], $config['precision'] ) ) {
						return $campaign;
					}
				}
			} catch ( \Throwable $error ) {
				$this->record_warning( $campaign, 'configuration_invalid' );
			}
		}
		return null;
	}

	/**
	 * Validate authoritative store-unit settings before any shipping adjustment.
	 *
	 * @param array $campaign Server-loaded campaign configuration.
	 * @return array Validated canonical shipping policy.
	 * @throws \DomainException When required configuration or currency is invalid.
	 */
	private function configuration( array $campaign ): array {
		$precision = wc_get_price_decimals();
		$settings  = $campaign['settings'];
		if ( isset( $settings['currency'] ) && ( get_woocommerce_currency() !== $settings['currency'] || (int) ( $settings['precision'] ?? -1 ) !== $precision ) ) {
			throw new \DomainException( 'shipping_currency_changed' );
		}
		$cap = $settings['shipping_subsidy_cap'] ?? CurrencyDisplay::default_subsidy_cap( get_woocommerce_currency(), $precision );
		if ( ! is_string( $cap ) ) {
			throw new \DomainException( 'shipping_cap_required' );
		}
		$threshold = Money::parse( (string) ( $settings['shipping_threshold'] ?? '0' ), $precision );
		$cap_minor = Money::parse( $cap, $precision );
		$requested = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $settings['shipping_instances'] ?? array() ) ) ) ) );
		$allowed   = array();
		foreach ( $requested as $instance_id ) {
			$method = \WC_Shipping_Zones::get_shipping_method( $instance_id );
			if ( ! $method || 'flat_rate' !== $method->id || 'yes' !== $method->enabled ) {
				$this->record_warning( $campaign, 'method_unsupported' );
				continue;
			}
			$allowed[] = $instance_id;
		}
		if ( $threshold < 0 || $cap_minor < 0 || ! $allowed ) {
			throw new \DomainException( 'shipping_configuration_invalid' );
		}
		return array(
			'precision' => $precision,
			'threshold' => $threshold,
			'cap'       => Money::format( $cap_minor, $precision ),
			'allowed'   => $allowed,
		);
	}

	/**
	 * Test campaign-only, post-coupon, ex-tax totals for one package.
	 *
	 * @param array $campaign Server-loaded campaign.
	 * @param array $package Shipping package.
	 * @param int   $threshold Minimum eligible minor-unit subtotal, including zero.
	 * @param int   $precision Currency precision.
	 * @return bool Whether at least one real campaign line meets the threshold.
	 */
	private function qualifies( array $campaign, array $package, int $threshold, int $precision ): bool {
		$eligible = 0;
		$present  = false;
		foreach ( (array) ( $package['contents'] ?? array() ) as $line ) {
			$product_id = ! empty( $line['variation_id'] ) ? (int) $line['variation_id'] : (int) ( $line['product_id'] ?? 0 );
			$product    = isset( $line['data'] ) && $line['data'] instanceof \WC_Product ? $line['data'] : null;
			if ( (int) ( $line['quantity'] ?? 0 ) <= 0 || ! $this->eligibility->owns( $campaign, $product_id, $product ) ) {
				continue;
			}
			$line_total = Money::parse( ShippingSubsidy::round_amount( (string) ( $line['line_total'] ?? '0' ), $precision ), $precision );
			if ( $line_total < 0 || $line_total > PHP_INT_MAX - $eligible ) {
				return false;
			}
			$present   = true;
			$eligible += $line_total;
		}
		return $present && $eligible >= $threshold;
	}

	/**
	 * Recalculate tax with WooCommerce only when its original tax IDs and sums agree.
	 *
	 * @param \WC_Shipping_Rate $rate Original, unmodified rate.
	 * @param string            $base Validated canonical ex-tax base.
	 * @param string            $remaining Customer-paid ex-tax remainder.
	 * @param int               $precision Currency precision.
	 * @return array Remaining WooCommerce shipping taxes.
	 * @throws \DomainException When original taxes cannot be reproduced safely.
	 */
	private function remaining_taxes( \WC_Shipping_Rate $rate, string $base, string $remaining, int $precision ): array {
		$original = $rate->get_taxes();
		if ( 0 === Money::parse( $remaining, $precision ) ) {
			return array_map( static fn() => 0, $original );
		}
		if ( ! $original ) {
			return array();
		}
		$tax_rates = \WC_Tax::get_shipping_tax_rates();
		$old_ids   = array_map( 'strval', array_keys( $original ) );
		$new_ids   = array_map( 'strval', array_keys( $tax_rates ) );
		sort( $old_ids, SORT_STRING );
		sort( $new_ids, SORT_STRING );
		if ( $old_ids !== $new_ids ) {
			throw new \DomainException( 'shipping_tax_ids_changed' );
		}
		$expected = \WC_Tax::calc_shipping_tax( $base, $tax_rates );
		foreach ( $original as $id => $tax ) {
			if ( Money::parse( ShippingSubsidy::round_amount( (string) $tax, $precision ), $precision ) !== Money::parse( ShippingSubsidy::round_amount( (string) ( $expected[ $id ] ?? '-1' ), $precision ), $precision ) ) {
				throw new \DomainException( 'shipping_tax_base_unverified' );
			}
		}
		$taxes = \WC_Tax::calc_shipping_tax( $remaining, $tax_rates );
		foreach ( $taxes as $id => $tax ) {
			$minor     = Money::parse( ShippingSubsidy::round_amount( (string) $tax, $precision ), $precision );
			$old_minor = Money::parse( ShippingSubsidy::round_amount( (string) ( $original[ $id ] ?? '-1' ), $precision ), $precision );
			if ( $minor > $old_minor ) {
				throw new \DomainException( 'shipping_tax_increased' );
			}
		}
		return $taxes;
	}

	/**
	 * Restore our original rate before a repeated calculation, avoiding double rebates.
	 *
	 * @param array $rates Existing response rates.
	 * @return array Original rates where the clone has not been externally modified.
	 */
	private function original_rates( array $rates ): array {
		if ( null === self::$adjusted ) {
			return $rates;
		}
		foreach ( $rates as $key => $rate ) {
			if ( $rate instanceof \WC_Shipping_Rate && isset( self::$adjusted[ $rate ] ) ) {
				$known = self::$adjusted[ $rate ];
				if ( $rate->get_cost() === $known['cost'] && $rate->get_taxes() === $known['taxes'] ) {
					$rates[ $key ] = $known['original'];
				}
			}
		}
		return $rates;
	}

	/**
	 * Hash only data that determines eligibility and the final package context.
	 *
	 * @param array $package Shipping package.
	 * @return string Private hash; no raw destination or cart data is persisted.
	 */
	private function fingerprint( array $package ): string {
		$lines = array();
		foreach ( (array) ( $package['contents'] ?? array() ) as $key => $line ) {
			$lines[ (string) $key ] = array( (int) ( $line['product_id'] ?? 0 ), (int) ( $line['variation_id'] ?? 0 ), (int) ( $line['quantity'] ?? 0 ), (string) ( $line['line_total'] ?? '0' ) );
		}
		ksort( $lines, SORT_STRING );
		return hash( 'sha256', wp_json_encode( array( $lines, $package['destination'] ?? array(), $package['contents_cost'] ?? null ) ) );
	}

	/**
	 * Version Woo's package-rate cache when policy, ownership, price or lifecycle changes.
	 *
	 * @param array|null $campaign Current campaign.
	 * @return string Cache and allocation context token.
	 */
	private function stamp( ?array $campaign ): string {
		return $campaign ? hash( 'sha256', wp_json_encode( array( $campaign['id'], $campaign['status'], $campaign['version'], $campaign['settings']['free_shipping'] ?? false, $campaign['settings']['shipping_threshold'] ?? '', $campaign['settings']['shipping_subsidy_cap'] ?? null, $campaign['settings']['shipping_instances'] ?? array(), get_woocommerce_currency(), wc_get_price_decimals(), get_option( 'exin_bf_cache_version', '1' ), CampaignContext::is_live( $campaign ) ) ) ) : 'none';
	}

	/**
	 * Select a bounded, campaign-version-specific diagnostic transient key.
	 *
	 * @param array $campaign Campaign record.
	 * @return string Plugin-owned transient key.
	 */
	private function warning_key( array $campaign ): string {
		return 'exin_bf_ship_warning_' . $campaign['id'] . '_' . $campaign['version'];
	}

	/**
	 * Record only a bounded diagnostic code, once, without customer data.
	 *
	 * @param array|null $campaign Campaign if available.
	 * @param string     $code Allowlisted operational warning code.
	 * @return void
	 */
	private function record_warning( ?array $campaign, string $code ): void {
		if ( ! $campaign ) {
			return;
		}
		$key    = $this->warning_key( $campaign );
		$stored = get_transient( $key );
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! in_array( $code, $stored, true ) && count( $stored ) < 4 ) {
			$stored[] = $code;
			set_transient( $key, $stored, DAY_IN_SECONDS );
		}
	}
}
