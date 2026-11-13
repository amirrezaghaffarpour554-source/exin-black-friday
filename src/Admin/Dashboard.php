<?php
/**
 * Focused RTL administrative workspace.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Admin;

use Exin\BlackFriday\Application\ProductSelectionService;
use Exin\BlackFriday\Application\Scheduler;
use Exin\BlackFriday\Infrastructure\CampaignRepository;
use Exin\BlackFriday\Infrastructure\Logger;

/** Renders the management shell and loads assets only for its own admin page. */
final class Dashboard {

	/**
	 * Screen hook returned by WordPress when the menu is registered.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Accepts the composition root services without performing financial writes.
	 *
	 * @param CampaignRepository      $repository Campaign persistence service.
	 * @param ProductSelectionService $selection  Validated product enrollment service.
	 * @param Scheduler               $scheduler  Campaign lifecycle and queue service.
	 * @param Logger                  $logger     Operational audit service.
	 */
	public function __construct( CampaignRepository $repository, ProductSelectionService $selection, Scheduler $scheduler, Logger $logger ) {
		// Application services stay at the REST boundary; this view has no financial writes.
	}

	/**
	 * Registers the menu and its screen-scoped assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'admin_menu',
			function (): void {
				$this->hook = add_menu_page( __( 'بلک فرایدی اکسین', 'exin-black-friday' ), __( 'بلک فرایدی اکسین', 'exin-black-friday' ), 'manage_woocommerce', 'exin-black-friday', array( $this, 'render' ), 'dashicons-tickets-alt', 56 );
			}
		);
		add_action(
			'admin_enqueue_scripts',
			function ( string $hook ): void {
				if ( $hook !== $this->hook ) {
					return;
				}
				$base = plugins_url( '../../assets/', __FILE__ );
				wp_enqueue_style( 'exin-bf-admin', $base . 'admin.css', array(), '1.0.0' );
				wp_enqueue_script( 'exin-bf-admin', $base . 'admin.js', array( 'wp-i18n' ), '1.0.0', true );
				wp_localize_script(
					'exin-bf-admin',
					'exinBFAdmin',
					array(
						'root'      => esc_url_raw( rest_url( 'exin-bf/v1/admin/' ) ),
						'nonce'     => wp_create_nonce( 'wp_rest' ),
						'currency'  => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
						'precision' => wc_get_price_decimals(),
					)
				);
				wp_set_script_translations( 'exin-bf-admin', 'exin-black-friday' );
			}
		);
	}

	/**
	 * Outputs the escaped management shell for authorized store managers.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="exin-bf" id="exin-bf-admin" dir="rtl">
			<header class="exin-bf-header">
				<div class="exin-bf-brand"><span class="exin-bf-monogram" aria-hidden="true">EX</span><div><span class="exin-bf-kicker">EXIN · BLACK FRIDAY</span><h1><?php esc_html_e( 'بلک فرایدی اکسین', 'exin-black-friday' ); ?></h1></div></div>
				<button type="button" class="exin-bf-button exin-bf-primary" data-command="add"><?php esc_html_e( 'افزودن محصول', 'exin-black-friday' ); ?> <span aria-hidden="true">＋</span></button>
			</header>
			<nav class="exin-bf-nav" aria-label="<?php esc_attr_e( 'بخش‌های مدیریت کمپین', 'exin-black-friday' ); ?>">
				<button type="button" data-view="dashboard" aria-current="page"><?php esc_html_e( 'نمای کلی', 'exin-black-friday' ); ?></button>
				<button type="button" data-view="products"><?php esc_html_e( 'محصولات', 'exin-black-friday' ); ?></button>
				<button type="button" data-view="settings"><?php esc_html_e( 'تنظیمات و راه‌اندازی', 'exin-black-friday' ); ?></button>
				<button type="button" data-view="diagnostics"><?php esc_html_e( 'گزارش و عیب‌یابی', 'exin-black-friday' ); ?></button>
			</nav>
			<div id="exin-bf-message" class="exin-bf-message" role="status" aria-live="polite" hidden></div>
			<main id="exin-bf-content" aria-busy="true"><p><?php esc_html_e( 'در حال دریافت وضعیت کمپین…', 'exin-black-friday' ); ?></p></main>
			<footer class="exin-bf-footer"><?php esc_html_e( 'کف قیمت مربوط به موتور کمپین است. کوپن‌ها و تخفیف‌های بیرونی ممکن است مبلغ پرداختی را کاهش دهند.', 'exin-black-friday' ); ?></footer>
			<dialog id="exin-bf-dialog" class="exin-bf-dialog" aria-labelledby="exin-bf-dialog-title">
				<div class="exin-bf-dialog-head"><h2 id="exin-bf-dialog-title"><?php esc_html_e( 'افزودن محصول', 'exin-black-friday' ); ?></h2><button type="button" class="exin-bf-icon" data-command="close-dialog" aria-label="<?php esc_attr_e( 'بستن', 'exin-black-friday' ); ?>">×</button></div>
				<div id="exin-bf-dialog-content"></div>
			</dialog>
		</div>
		<?php
	}
}
