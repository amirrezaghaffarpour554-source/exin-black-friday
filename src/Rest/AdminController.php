<?php
/**
 * Administrative REST boundary.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Rest;

use DateTimeImmutable;
use DateTimeZone;
use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Domain\Money;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;
use Exin\BlackFriday\Infrastructure\LockManager;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

/** Validates privileged campaign requests before calling application services. */
final class AdminController {

	/**
	 * Campaign and enrollment persistence.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $repository;

	/**
	 * Validated product selection and conflict resolution.
	 *
	 * @var ProductSelectionService
	 */
	private ProductSelectionService $selection;

	/**
	 * Campaign lifecycle and background queue coordinator.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Operational audit service without customer details.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Connects the boundary to the application services.
	 *
	 * @param CampaignRepository      $repository Campaign persistence service.
	 * @param ProductSelectionService $selection  Product enrollment service.
	 * @param Scheduler               $scheduler  Lifecycle and queue service.
	 * @param Logger                  $logger     Operational audit service.
	 */
	public function __construct( CampaignRepository $repository, ProductSelectionService $selection, Scheduler $scheduler, Logger $logger ) {
		$this->repository = $repository;
		$this->selection  = $selection;
		$this->scheduler  = $scheduler;
		$this->logger     = $logger;
	}

	/**
	 * Registers authenticated routes with a shared permission and error boundary.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'rest_api_init',
			function (): void {
				$routes = array(
					'/state'                        => array( 'GET', 'state' ),
					'/products'                     => array( 'GET', 'products' ),
					'/campaign'                     => array( 'POST', 'campaign' ),
					'/campaign/(?P<id>\d+)/action'  => array( 'POST', 'action' ),
					'/campaign/(?P<id>\d+)/preview' => array( 'POST', 'preview' ),
					'/campaign/(?P<id>\d+)/items'   => array( 'POST', 'items' ),
					'/campaign/(?P<id>\d+)/bulk'    => array( 'POST', 'bulk' ),
					'/campaign/(?P<id>\d+)/item/(?P<product>\d+)' => array( 'POST', 'item' ),
				);
				foreach ( $routes as $route => $definition ) {
					register_rest_route(
						'exin-bf/v1',
						'/admin' . $route,
						array(
							'methods'             => $definition[0],
							'permission_callback' => array( $this, 'permission' ),
							'callback'            => function ( WP_REST_Request $request ) use ( $definition ) {
								try {
									return call_user_func( array( $this, $definition[1] ), $request );
								} catch ( InvalidArgumentException | \DomainException | \OverflowException $error ) {
									return new WP_Error( 'exin_bf_invalid', $this->domain_message( $error->getMessage() ), array( 'status' => 400 ) );
								} catch ( \Throwable $error ) {
									try {
										$this->logger->log( 'error', 'admin_request_failed', array( 'error_code' => 'admin_request_failed' ) );
									} catch ( \Throwable $audit_error ) {
										// Preserve the sanitized response even when diagnostic storage is unavailable.
										update_option( 'exin_bf_boot_error', 'admin_and_audit_failed', false );
									}
									return new WP_Error( 'exin_bf_operation_failed', __( 'عملیات انجام نشد. جزئیات ایمن را در عیب‌یابی بررسی کنید.', 'exin-black-friday' ), array( 'status' => 409 ) );
								}
							},
						)
					);
				}
			}
		);
	}

	/**
	 * Requires both WooCommerce management capability and a REST nonce.
	 *
	 * @param WP_REST_Request $request Incoming administrative request.
	 * @return true|WP_Error Whether the request may access campaign data.
	 */
	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'exin_bf_forbidden', __( 'دسترسی مدیریت ووکامرس لازم است.', 'exin-black-friday' ), array( 'status' => 403 ) );
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'exin_bf_nonce', __( 'نشست معتبر نیست؛ صفحه را دوباره بارگذاری کنید.', 'exin-black-friday' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Reads a bounded enrollment page and optional private dashboard analytics.
	 *
	 * @param WP_REST_Request $request Request containing page and view selectors.
	 * @return array Private campaign state and diagnostics.
	 */
	public function state( WP_REST_Request $request ): array {
		$campaign = $this->repository->current();
		$page     = $this->integer( $request->get_param( 'page' ) ?? 1, 1, 100000 );
		$items    = $campaign ? $this->repository->items( (int) $campaign['id'], 25, ( $page - 1 ) * 25 ) : array();
		foreach ( $items as &$item ) {
			$product         = wc_get_product( (int) $item['product_id'] );
			$image           = $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : false;
			$item['product'] = $product ? array(
				'name'          => $product->get_name(),
				'sku'           => $product->get_sku(),
				'price'         => $product->get_price(),
				'regular_price' => $product->get_regular_price(),
				'stock'         => $product->get_stock_quantity(),
				'stock_status'  => $product->get_stock_status(),
				'image'         => false !== $image ? $image : '',
				'type'          => $product->get_type(),
				'edit_url'      => get_edit_post_link( $product->get_id(), 'raw' ),
			) : null;
		}
		unset( $item );
		$analytics = array();
		if ( $campaign && 'dashboard' === $request->get_param( 'view' ) && class_exists( '\Exin\BlackFriday\Integration\Orders' ) ) {
			$orders    = new \Exin\BlackFriday\Integration\Orders( $this->repository );
			$analytics = $orders->analytics( (int) $campaign['id'], $this->integer( $request->get_param( 'report_page' ) ?? 1, 1, 100000 ) );
		}
		return array(
			'campaign'    => $campaign,
			'items'       => $items,
			'count'       => $campaign ? $this->repository->count_items( (int) $campaign['id'] ) : 0,
			'page'        => $page,
			'has_more'    => count( $items ) === 25,
			'analytics'   => $analytics,
			'server_time' => time(),
			'health'      => $this->health(),
			'logs'        => $this->logger->recent( 30 ),
		);
	}

	/**
	 * Searches a bounded page of sellable products by a validated term.
	 *
	 * @param WP_REST_Request $request Product search and page parameters.
	 * @return array Matching products and pagination information.
	 * @throws InvalidArgumentException When the search term is outside its limits.
	 */
	public function products( WP_REST_Request $request ): array {
		$term = sanitize_text_field( $this->text( $request->get_param( 'search' ) ?? '' ) );
		if ( strlen( $term ) < 2 || strlen( $term ) > 100 ) {
			throw new InvalidArgumentException( esc_html__( 'حداقل ۲ و حداکثر ۱۰۰ نویسه وارد کنید.', 'exin-black-friday' ) );
		}
		return $this->selection->search( $term, $this->integer( $request->get_param( 'page' ) ?? 1, 1, 10000 ) );
	}

	/**
	 * Serializes campaign creation and editing against concurrent lifecycle jobs.
	 *
	 * @param WP_REST_Request $request Validated JSON campaign request.
	 * @return array Saved campaign state.
	 */
	public function campaign( WP_REST_Request $request ): array {
		$locks = new LockManager();
		return $locks->run(
			'campaign-create',
			function () use ( $request, $locks ): array {
				$data    = $this->body( $request );
				$id      = isset( $data['id'] ) ? $this->integer( $data['id'], 1, PHP_INT_MAX ) : 0;
				$current = $this->repository->current();
				$lock_id = $id ? $id : ( $current ? (int) $current['id'] : 0 );
				if ( $lock_id ) {
					return $locks->run( 'campaign:' . $lock_id, fn(): array => $this->save_campaign( $request ) );
				}
				return $this->save_campaign( $request );
			}
		);
	}

	/**
	 * Saves a draft while preserving pinned currency and existing schedules.
	 *
	 * @param WP_REST_Request $request Campaign JSON and optional existing ID.
	 * @return array Saved campaign state.
	 * @throws InvalidArgumentException When prior ownership or financial settings conflict.
	 */
	private function save_campaign( WP_REST_Request $request ): array {
		$data     = $this->body( $request );
		$id       = isset( $data['id'] ) ? $this->integer( $data['id'], 1, PHP_INT_MAX ) : 0;
		$existing = $id ? $this->required_campaign( $id ) : null;
		if ( $existing && 'draft' !== $existing['status'] ) {
			throw new InvalidArgumentException( esc_html__( 'تنظیمات کمپین شروع‌شده قابل تغییر نیست. برای کمپین بعدی پیش‌نویس بسازید.', 'exin-black-friday' ) );
		}
		if ( ! $existing ) {
			$current = $this->repository->current();
			if ( $current && ! in_array( $current['status'], array( 'completed', 'draft' ), true ) ) {
				throw new InvalidArgumentException( esc_html__( 'ابتدا کمپین جاری را پایان دهید و بازیابی قیمت‌ها را بررسی کنید.', 'exin-black-friday' ) );
			}
			if ( $current && 'draft' === $current['status'] ) {
				throw new InvalidArgumentException( esc_html__( 'یک پیش‌نویس موجود است؛ همان را ویرایش کنید.', 'exin-black-friday' ) );
			}
		}
		if ( ! $existing && $current ) {
			$offset = 0;
			do {
				$previous_items = $this->repository->items( (int) $current['id'], 100, $offset );
				foreach ( $previous_items as $previous_item ) {
					if ( ! empty( $previous_item['expected'] ) || ! empty( $previous_item['intent'] ) || 'conflict' === $previous_item['status'] ) {
						throw new InvalidArgumentException( esc_html__( 'بازیابی کمپین قبلی کامل نیست. ابتدا مالکیت قیمت‌ها و تعارض‌ها را بررسی کنید.', 'exin-black-friday' ) );
					}
				}
				$offset        += 100;
				$previous_count = count( $previous_items );
			} while ( 100 === $previous_count );
		}
		$valid = $this->validate_campaign( $data );
		if ( $existing ) {
			$valid['settings']['currency']  = $existing['settings']['currency'] ?? get_woocommerce_currency();
			$valid['settings']['precision'] = $existing['settings']['precision'] ?? wc_get_price_decimals();
			if ( get_woocommerce_currency() !== $valid['settings']['currency'] || wc_get_price_decimals() !== (int) $valid['settings']['precision'] ) {
				throw new InvalidArgumentException( esc_html__( 'واحد پول یا دقت فروشگاه تغییر کرده است؛ پیش از ادامه آن را بررسی کنید.', 'exin-black-friday' ) );
			}
			$valid['version'] = (int) $existing['version'] + 1;
		}
		if ( $id ) {
			// Existing product schedules must remain consistent with immutable snapshots.
			if ( $this->repository->count_items( $id ) > 0 ) {
				foreach ( array( 'start_utc', 'days', 'timezone', 'strategy', 'power', 'rounding' ) as $field ) {
					if ( (string) $valid[ $field ] !== (string) $existing[ $field ] ) {
						throw new InvalidArgumentException( esc_html__( 'برای تغییر برنامه مالی، ابتدا محصولات پیش‌نویس را حذف کنید و پس از ذخیره دوباره اضافه کنید.', 'exin-black-friday' ) );
					}
				}
			}
			$this->repository->save( $id, $valid );
		} else {
			$id = $this->repository->create( $valid );
		}
		update_option( 'exin_bf_cache_version', wp_generate_uuid4(), false );
		$this->logger->log( 'info', 'campaign_saved', array( 'campaign_id' => $id ) );
		return array( 'campaign' => $this->repository->get( $id ) );
	}

	/**
	 * Applies an allowed lifecycle action with explicit confirmation for restore.
	 *
	 * @param WP_REST_Request $request Campaign ID, action and confirmation.
	 * @return array Updated campaign state.
	 * @throws InvalidArgumentException When destructive confirmation is absent.
	 */
	public function action( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		$data   = $this->body( $request );
		$action = $this->choice( $data['action'] ?? '', array( 'schedule', 'start', 'pause', 'resume', 'restore', 'recover' ) );
		if ( 'restore' === $action && true !== ( $data['confirm_restore'] ?? false ) ) {
			throw new InvalidArgumentException( esc_html__( 'بازیابی قیمت‌ها به تأیید صریح نیاز دارد.', 'exin-black-friday' ) );
		}
		if ( 'recover' === $action ) {
			$this->scheduler->recover();
		} else {
			$this->scheduler->transition( $id, $action );
		}
		return array( 'campaign' => $this->repository->get( $id ) );
	}

	/**
	 * Calculates a private schedule preview without persisting an enrollment.
	 *
	 * @param WP_REST_Request $request Campaign, product and floor input.
	 * @return array Validated schedule preview.
	 */
	public function preview( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		$data = $this->body( $request );
		return $this->selection->preview( $id, $this->integer( $data['product_id'] ?? 0, 1, PHP_INT_MAX ), $this->decimal( $data['floor_price'] ?? '' ) );
	}

	/**
	 * Locks the campaign before enrolling a single product.
	 *
	 * @param WP_REST_Request $request Enrollment request.
	 * @return array Saved enrollment.
	 */
	public function items( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return ( new LockManager() )->run( 'campaign:' . $id, fn(): array => $this->add_item( $request ) );
	}

	/**
	 * Enrolls a product using only the permitted financial and display fields.
	 *
	 * @param WP_REST_Request $request Enrollment JSON for an editable campaign.
	 * @return array Saved enrollment.
	 */
	private function add_item( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->editable_campaign( $id );
		$data = $this->body( $request );
		return $this->selection->add(
			$id,
			$this->integer( $data['product_id'] ?? 0, 1, PHP_INT_MAX ),
			$this->decimal( $data['floor_price'] ?? '' ),
			array(
				'priority' => $this->integer( $data['priority'] ?? 0, 0, 999999 ),
				'featured' => $this->boolean( $data['featured'] ?? false ),
			)
		);
	}

	/**
	 * Applies a validated operation to an enrollment owned by the campaign.
	 *
	 * @param WP_REST_Request $request Campaign/product IDs and operation fields.
	 * @return array Updated enrollment state.
	 * @throws InvalidArgumentException When membership or explicit confirmation is missing.
	 */
	public function item( WP_REST_Request $request ): array {
		$id      = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$product = $this->integer( $request['product'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		if ( ! $this->repository->item( $id, $product ) ) {
			throw new InvalidArgumentException( esc_html__( 'محصول عضو این کمپین نیست.', 'exin-black-friday' ) );
		}
		$data   = $this->body( $request );
		$action = $this->choice( $data['action'] ?? 'update', array( 'update', 'remove', 'retry', 'keep_external', 'pause', 'resume' ) );
		if ( 'remove' === $action ) {
			if ( true !== ( $data['confirm_remove'] ?? false ) ) {
				throw new InvalidArgumentException( esc_html__( 'حذف و بازیابی قیمت محصول را تأیید کنید.', 'exin-black-friday' ) );
			}
			$this->selection->remove( $id, $product );
		} elseif ( in_array( $action, array( 'pause', 'resume' ), true ) ) {
			$this->selection->update( $id, $product, array( 'status' => 'pause' === $action ? 'paused' : 'included' ) );
		} elseif ( in_array( $action, array( 'retry', 'keep_external' ), true ) ) {
			if ( 'keep_external' === $action && true !== ( $data['confirm_release'] ?? false ) ) {
				throw new InvalidArgumentException( esc_html__( 'حفظ قیمت بیرونی و واگذاری کنترل را تأیید کنید.', 'exin-black-friday' ) );
			}
			if ( ! method_exists( $this->selection, 'resolve' ) ) {
				throw new InvalidArgumentException( esc_html__( 'برای بررسی تعارض، بازیابی ایمن کمپین را اجرا کنید.', 'exin-black-friday' ) );
			}
			$this->selection->resolve( $id, $product, $action );
		} else {
			$changes = array();
			if ( isset( $data['floor_price'] ) ) {
				$this->editable_campaign( $id );
				$changes['floor_price'] = $this->decimal( $data['floor_price'] );
			}
			if ( isset( $data['priority'] ) ) {
				$changes['priority'] = $this->integer( $data['priority'], 0, 999999 );
			}
			if ( isset( $data['featured'] ) ) {
				$changes['featured'] = $this->boolean( $data['featured'] );
			}
			if ( ! $changes ) {
				throw new InvalidArgumentException( esc_html__( 'تغییری انتخاب نشده است.', 'exin-black-friday' ) );
			}
			$this->selection->update( $id, $product, $changes );
		}
		return array( 'item' => $this->repository->item( $id, $product ) );
	}

	/**
	 * Serializes a bounded group of enrollment operations for one campaign.
	 *
	 * @param WP_REST_Request $request Bulk enrollment request.
	 * @return array Per-product results.
	 */
	public function bulk( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		return ( new LockManager() )->run( 'campaign:' . $id, fn(): array => $this->bulk_items( $request ) );
	}

	/**
	 * Processes at most twenty-five additions or explicitly confirmed removals.
	 *
	 * @param WP_REST_Request $request Allowed bulk action and product rows.
	 * @return array Independent per-product success results.
	 * @throws InvalidArgumentException When the batch or removal confirmation is invalid.
	 */
	private function bulk_items( WP_REST_Request $request ): array {
		$id = $this->integer( $request['id'], 1, PHP_INT_MAX );
		$this->required_campaign( $id );
		$data   = $this->body( $request );
		$action = $this->choice( $data['action'] ?? '', array( 'add', 'remove' ) );
		$rows   = $data['items'] ?? null;
		if ( ! is_array( $rows ) || count( $rows ) < 1 || count( $rows ) > 25 ) {
			throw new InvalidArgumentException( esc_html__( 'هر نوبت بین ۱ تا ۲۵ محصول مجاز است.', 'exin-black-friday' ) );
		}
		if ( 'add' === $action ) {
			$this->editable_campaign( $id );
		} elseif ( true !== ( $data['confirm_remove'] ?? false ) ) {
			throw new InvalidArgumentException( esc_html__( 'حذف گروهی و بازیابی قیمت را تأیید کنید.', 'exin-black-friday' ) );
		}
		$results = array();
		foreach ( $rows as $row ) {
			$product = 0;
			try {
				if ( ! is_array( $row ) ) {
					throw new InvalidArgumentException( esc_html__( 'ساختار محصول معتبر نیست.', 'exin-black-friday' ) );
				}
				$product = $this->integer( $row['product_id'] ?? 0, 1, PHP_INT_MAX );
				if ( 'add' === $action ) {
					$this->selection->add( $id, $product, $this->decimal( $row['floor_price'] ?? '' ) );
				} else {
					if ( ! $this->repository->item( $id, $product ) ) {
						throw new InvalidArgumentException( esc_html__( 'محصول عضو کمپین نیست.', 'exin-black-friday' ) );
					}
					$this->selection->remove( $id, $product );
				}
				$results[] = array(
					'product_id' => $product,
					'ok'         => true,
				);
			} catch ( \Throwable $error ) {
				$results[] = array(
					'product_id' => $product,
					'ok'         => false,
					'error'      => __( 'عملیات محصول انجام نشد؛ قیمت و وضعیت تعارض را بررسی کنید.', 'exin-black-friday' ),
				);
			}
		}
		return array( 'results' => $results );
	}

	/**
	 * Validates and allowlists campaign settings, dates and shipping instances.
	 *
	 * @param array $data Submitted JSON object.
	 * @return array Normalized campaign data without client-selected status or version.
	 * @throws InvalidArgumentException When dates, settings or monetary inputs are invalid.
	 */
	private function validate_campaign( array $data ): array {
		$title = sanitize_text_field( $this->text( $data['title'] ?? '' ) );
		if ( '' === $title || strlen( $title ) > 200 ) {
			throw new InvalidArgumentException( esc_html__( 'عنوان کمپین معتبر نیست.', 'exin-black-friday' ) );
		}
		$timezone = $this->text( $data['timezone'] ?? 'Asia/Tehran' );
		if ( ! in_array( $timezone, DateTimeZone::listIdentifiers(), true ) && 'UTC' !== $timezone ) {
			throw new InvalidArgumentException( esc_html__( 'منطقه زمانی معتبر نیست.', 'exin-black-friday' ) );
		}
		$local  = $this->text( $data['start_local'] ?? '' );
		$zone   = new DateTimeZone( $timezone );
		$start  = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $local, $zone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $start || ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $start->format( 'Y-m-d\TH:i' ) !== $local ) {
			throw new InvalidArgumentException( esc_html__( 'زمان شروع معتبر نیست.', 'exin-black-friday' ) );
		}
		$days     = $this->integer( $data['days'] ?? 7, 1, 366 );
		$settings = $data['settings'] ?? array();
		if ( ! is_array( $settings ) ) {
			throw new InvalidArgumentException( esc_html__( 'تنظیمات معتبر نیست.', 'exin-black-friday' ) );
		}
		$instances = $settings['shipping_instances'] ?? array();
		if ( ! is_array( $instances ) || count( $instances ) > 100 ) {
			throw new InvalidArgumentException( esc_html__( 'روش ارسال معتبر نیست.', 'exin-black-friday' ) );
		}
		$instances = array_values( array_unique( array_map( fn( $value ) => $this->integer( $value, 1, PHP_INT_MAX ), $instances ) ) );
		$threshold = $this->decimal( $settings['shipping_threshold'] ?? '0' );
		$free      = $this->boolean( $settings['free_shipping'] ?? false );
		if ( $free && ( ! $instances || Money::parse( $threshold, wc_get_price_decimals() ) < 1 ) ) {
			throw new InvalidArgumentException( esc_html__( 'برای ارسال رایگان، حدنصاب مثبت و حداقل یک روش نرخ ثابت لازم است.', 'exin-black-friday' ) );
		}
		$allowed_instances = array_column( $this->shipping_methods(), 'id' );
		foreach ( $instances as $instance ) {
			if ( ! in_array( $instance, $allowed_instances, true ) ) {
				throw new InvalidArgumentException( esc_html__( 'روش ارسال انتخابی نرخ ثابت فعال نیست.', 'exin-black-friday' ) );
			}
		}
		$statuses = $settings['order_statuses'] ?? array( 'processing', 'completed' );
		if ( ! is_array( $statuses ) || ! $statuses ) {
			throw new InvalidArgumentException( esc_html__( 'حداقل یک وضعیت مجاز گزارش لازم است.', 'exin-black-friday' ) );
		}
		$allowed_statuses = array_map( fn( $value ) => str_replace( 'wc-', '', $value ), array_keys( wc_get_order_statuses() ) );
		$statuses         = array_values( array_unique( array_map( fn( $value ) => $this->choice( $value, $allowed_statuses ), $statuses ) ) );
		if ( array_intersect( $statuses, array( 'failed', 'cancelled', 'checkout-draft', 'pending' ) ) ) {
			throw new InvalidArgumentException( esc_html__( 'سفارش پرداخت‌نشده، ناموفق یا لغوشده در آمار فروش مجاز نیست.', 'exin-black-friday' ) );
		}
		return array(
			'title'     => $title,
			'timezone'  => $timezone,
			'days'      => $days,
			'start_utc' => $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'end_utc'   => $start->modify( '+' . $days . ' days' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'strategy'  => $this->choice( $data['strategy'] ?? 'linear', array( 'linear', 'progressive' ) ),
			'power'     => $this->integer( $data['power'] ?? 2, 1, 8 ),
			'rounding'  => $this->choice( $data['rounding'] ?? 'half_up', array( 'half_up', 'floor', 'ceil' ) ),
			'settings'  => array(
				'currency'           => get_woocommerce_currency(),
				'precision'          => wc_get_price_decimals(),
				'free_shipping'      => $free,
				'shipping_threshold' => $threshold,
				'shipping_instances' => $instances,
				'show_out_of_stock'  => $this->boolean( $settings['show_out_of_stock'] ?? true ),
				'inactive_output'    => $this->choice( $settings['inactive_output'] ?? 'message', array( 'message', 'empty' ) ),
				'order_statuses'     => $statuses,
				'target_orders'      => $this->integer( $settings['target_orders'] ?? 500, 1, 10000000 ),
				'retention_days'     => $this->integer( $settings['retention_days'] ?? 90, 7, 365 ),
			),
		);
	}

	/**
	 * Reads environment diagnostics without changing WordPress or shipping setup.
	 *
	 * @return array Runtime versions, scheduler state and available configuration options.
	 */
	private function health(): array {
		return array(
			'php'              => PHP_VERSION,
			'wordpress'        => get_bloginfo( 'version' ),
			'woocommerce'      => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'action_scheduler' => function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ),
			'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'object_cache'     => wp_using_ext_object_cache(),
			'timezone'         => wp_timezone_string(),
			'elementor'        => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'next_watchdog'    => function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( 'exin_bf_tick', array(), 'exin-black-friday' ) : null,
			'shipping_methods' => $this->shipping_methods(),
			'order_statuses'   => wc_get_order_statuses(),
		);
	}

	/**
	 * Lists enabled flat-rate instances across configured zones and the fallback zone.
	 *
	 * @return array Flat-rate instance IDs and escaped-at-display labels.
	 */
	private function shipping_methods(): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}
		$result  = array();
		$zones   = \WC_Shipping_Zones::get_zones();
		$zones[] = array(
			'zone_id'   => 0,
			'zone_name' => __( 'سایر مناطق', 'exin-black-friday' ),
		);
		foreach ( $zones as $zone ) {
			$object = new \WC_Shipping_Zone( (int) $zone['zone_id'] );
			foreach ( $object->get_shipping_methods( true ) as $method ) {
				if ( 'flat_rate' === $method->id ) {
					$result[] = array(
						'id'    => (int) $method->instance_id,
						'label' => $zone['zone_name'] . ' — ' . $method->get_title(),
					);
				}
			}
		}
		return $result;
	}

	/**
	 * Loads a campaign or rejects the requested resource ID.
	 *
	 * @param int $id Campaign ID.
	 * @return array Existing campaign data.
	 * @throws InvalidArgumentException When no campaign matches the ID.
	 */
	private function required_campaign( int $id ): array {
		$campaign = $this->repository->get( $id );
		if ( ! $campaign ) {
			throw new InvalidArgumentException( esc_html__( 'کمپین پیدا نشد.', 'exin-black-friday' ) );
		}
		return $campaign;
	}

	/**
	 * Requires draft status before any financial enrollment change.
	 *
	 * @param int $id Campaign ID.
	 * @return array Editable draft campaign.
	 * @throws InvalidArgumentException When the campaign has left draft status.
	 */
	private function editable_campaign( int $id ): array {
		$campaign = $this->required_campaign( $id );
		if ( 'draft' !== $campaign['status'] ) {
			throw new InvalidArgumentException( esc_html__( 'تغییر قیمت مبنا و کف پس از شروع کمپین مجاز نیست.', 'exin-black-friday' ) );
		}
		return $campaign;
	}


	/**
	 * Converts known domain failures to store-manager messages without internal details.
	 *
	 * @param string $code Static domain code or locally authored validation message.
	 * @return string Localized administrative message.
	 */
	private function domain_message( string $code ): string {
		$messages = array(
			'Amount must be a plain decimal string.'      => __( 'مبلغ را با رقم و نقطهٔ اعشار وارد کنید؛ نماد علمی یا جداکنندهٔ هزارگان مجاز نیست.', 'exin-black-friday' ),
			'Amount exceeds currency precision.'          => __( 'تعداد رقم اعشار مبلغ از دقت مجاز ارز فروشگاه بیشتر است.', 'exin-black-friday' ),
			'Amount exceeds the supported integer range.' => __( 'مبلغ از محدودهٔ امن محاسبات بیشتر است؛ مقدار کوچک‌تری وارد کنید.', 'exin-black-friday' ),
			'Currency precision must be between zero and eight.' => __( 'دقت ارز فروشگاه باید بین صفر تا هشت رقم اعشار باشد.', 'exin-black-friday' ),
			'Base and floor must be positive and floor must not exceed base.' => __( 'قیمت پایه و کف باید مثبت باشند و کف نباید از قیمت پایه بیشتر باشد.', 'exin-black-friday' ),
			'Campaign days must be between one and 366.'  => __( 'مدت کمپین باید بین یک تا ۳۶۶ روز باشد.', 'exin-black-friday' ),
			'Unknown pricing strategy.'                   => __( 'الگوی قیمت‌گذاری معتبر نیست؛ خطی یا تصاعدی را انتخاب کنید.', 'exin-black-friday' ),
			'Progressive power must be an integer between one and eight.' => __( 'توان الگوی تصاعدی باید عدد صحیح یک تا هشت باشد.', 'exin-black-friday' ),
			'Unknown rounding policy.'                    => __( 'روش گردکردن معتبر نیست؛ یکی از روش‌های مجاز را انتخاب کنید.', 'exin-black-friday' ),
			'campaign_not_found'                          => __( 'کمپین پیدا نشد؛ صفحه را تازه کنید.', 'exin-black-friday' ),
			'campaign_not_editable'                       => __( 'کمپین در وضعیت قابل ویرایش نیست؛ تنظیمات مالی فقط در پیش‌نویس قابل تغییرند.', 'exin-black-friday' ),
			'campaign_cannot_schedule'                    => __( 'زمان‌بندی در وضعیت فعلی کمپین مجاز نیست.', 'exin-black-friday' ),
			'campaign_cannot_start'                       => __( 'شروع در وضعیت فعلی کمپین مجاز نیست.', 'exin-black-friday' ),
			'campaign_start_is_in_future'                 => __( 'زمان شروع کمپین هنوز نرسیده است؛ آن را زمان‌بندی کنید.', 'exin-black-friday' ),
			'campaign_already_ended'                      => __( 'بازهٔ زمانی کمپین پایان یافته است؛ نتیجهٔ بازیابی را بررسی کنید.', 'exin-black-friday' ),
			'campaign_cannot_pause'                       => __( 'فقط کمپین فعال یا زمان‌بندی‌شده را می‌توان متوقف کرد.', 'exin-black-friday' ),
			'campaign_cannot_resume'                      => __( 'ادامه در وضعیت فعلی کمپین مجاز نیست.', 'exin-black-friday' ),
			'invalid_campaign_transition'                 => __( 'تغییر وضعیت درخواست‌شده برای کمپین مجاز نیست.', 'exin-black-friday' ),
			'item_not_found'                              => __( 'محصول عضو این کمپین نیست یا حذف شده است.', 'exin-black-friday' ),
			'item_not_in_conflict'                        => __( 'محصول تعارض ثبت‌شده‌ای برای حل‌کردن ندارد؛ وضعیت را تازه کنید.', 'exin-black-friday' ),
			'invalid_item_transition'                     => __( 'تغییر وضعیت درخواست‌شده برای محصول مجاز نیست.', 'exin-black-friday' ),
			'invalid_resolution'                          => __( 'روش انتخاب‌شده برای حل تعارض مجاز نیست.', 'exin-black-friday' ),
			'existing_or_scheduled_sale_conflict'         => __( 'محصول تخفیف فعال یا زمان‌بندی‌شده دارد؛ ابتدا تداخل را خارج از کمپین بررسی کنید.', 'exin-black-friday' ),
			'dynamic_price_conflict'                      => __( 'قیمت پویا شناسایی شد؛ کنترل قیمت این محصول با کمپین سازگار نیست.', 'exin-black-friday' ),
			'regular_price_conflict'                      => __( 'قیمت جاری با قیمت اصلی معتبر یکسان نیست؛ محصول وارد کمپین نشد.', 'exin-black-friday' ),
			'product_already_included'                    => __( 'این محصول قبلاً به کمپین اضافه شده است.', 'exin-black-friday' ),
			'product_owned_by_another_campaign'           => __( 'قیمت این محصول هنوز تحت کنترل کمپین دیگری است.', 'exin-black-friday' ),
			'store_currency_or_precision_changed'         => __( 'واحد پول یا دقت قیمت فروشگاه تغییر کرده است.', 'exin-black-friday' ),
			'unsupported_or_missing_product'              => __( 'محصول پیدا نشد یا نوع آن پشتیبانی نمی‌شود؛ یک محصول ساده یا تنوع انتخاب کنید.', 'exin-black-friday' ),
			'product_not_published'                       => __( 'محصول و والد تنوع باید منتشر شده باشند.', 'exin-black-friday' ),
			'financial_settings_frozen'                   => __( 'پس از شروع کمپین، تغییر شرایط مالی مجاز نیست.', 'exin-black-friday' ),
			'restore_conflict_requires_resolution'        => __( 'قیمت بیرونی تغییر کرده است؛ پیش از حذف، تعارض را بررسی کنید.', 'exin-black-friday' ),
			'no_owned_price_to_restore'                   => __( 'قیمت تحت کنترلی برای بازیابی وجود ندارد.', 'exin-black-friday' ),
			'restore_in_progress'                         => __( 'بازیابی در حال اجراست؛ نتیجه را در عیب‌یابی بررسی کنید.', 'exin-black-friday' ),
			'campaign_has_no_products'                    => __( 'ابتدا حداقل یک محصول به کمپین اضافه کنید.', 'exin-black-friday' ),
		);
		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}
		// Validation messages authored at this boundary contain no runtime details.
		if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $code ) ) {
			return sanitize_text_field( $code );
		}
		return __( 'ورودی یا وضعیت عملیات معتبر نیست؛ کف قیمت، زمان‌بندی و تعارض محصول را بررسی کنید.', 'exin-black-friday' );
	}

	/**
	 * Requires a JSON object or array before extracting allowed request fields.
	 *
	 * @param WP_REST_Request $request Incoming JSON request.
	 * @return array Parsed JSON structure.
	 * @throws InvalidArgumentException When the JSON body is missing or malformed.
	 */
	private function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			throw new InvalidArgumentException( esc_html__( 'درخواست JSON معتبر لازم است.', 'exin-black-friday' ) );
		}
		return $body;
	}

	/**
	 * Normalizes a plain decimal using the store precision and checked integer units.
	 *
	 * @param mixed $value Submitted monetary value.
	 * @return string Canonical decimal amount.
	 */
	private function decimal( $value ): string {
		$value = $this->text( $value );
		return Money::format( Money::parse( $value, wc_get_price_decimals() ), wc_get_price_decimals() );
	}

	/**
	 * Accepts only textual or integer scalar input without implicit array coercion.
	 *
	 * @param mixed $value Submitted scalar.
	 * @return string Scalar string representation.
	 * @throws InvalidArgumentException When the value is not a string or integer.
	 */
	private function text( $value ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			throw new InvalidArgumentException( esc_html__( 'مقدار ورودی معتبر نیست.', 'exin-black-friday' ) );
		}
		return (string) $value;
	}

	/**
	 * Validates a decimal integer and its inclusive bounds without overflow coercion.
	 *
	 * @param mixed $value   Submitted integer value.
	 * @param int   $minimum Minimum permitted value.
	 * @param int   $maximum Maximum permitted value.
	 * @return int Checked integer.
	 * @throws InvalidArgumentException When the value is malformed or outside bounds.
	 */
	private function integer( $value, int $minimum, int $maximum ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/^\d+$/D', $value ) ) ) {
			throw new InvalidArgumentException( esc_html__( 'عدد صحیح معتبر لازم است.', 'exin-black-friday' ) );
		}
		$filtered = filter_var( $value, FILTER_VALIDATE_INT );
		if ( false === $filtered || $filtered < $minimum || $filtered > $maximum ) {
			throw new InvalidArgumentException( esc_html__( 'عدد خارج از بازه مجاز است.', 'exin-black-friday' ) );
		}
		return $filtered;
	}

	/**
	 * Requires an actual JSON boolean rather than a truthy string.
	 *
	 * @param mixed $value Submitted option value.
	 * @return bool Validated boolean.
	 * @throws InvalidArgumentException When the value is not boolean.
	 */
	private function boolean( $value ): bool {
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( esc_html__( 'گزینه باید روشن یا خاموش باشد.', 'exin-black-friday' ) );
		}
		return $value;
	}

	/**
	 * Restricts a scalar choice to an explicit allowlist.
	 *
	 * @param mixed $value   Submitted option.
	 * @param array $allowed Permitted exact string values.
	 * @return string Allowed value.
	 * @throws InvalidArgumentException When the submitted value is not listed.
	 */
	private function choice( $value, array $allowed ): string {
		$value = $this->text( $value );
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( esc_html__( 'گزینه انتخابی معتبر نیست.', 'exin-black-friday' ) );
		}
		return $value;
	}
}
