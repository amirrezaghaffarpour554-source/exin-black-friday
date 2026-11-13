<?php
/**
 * Loaded only when Elementor has its base widget class.
 *
 * @package Exin\BlackFriday
 */

namespace Exin\BlackFriday\Integration\Elementor;

use Exin\BlackFriday\Integration\Storefront;

/**
 * Loaded only when Elementor has its base widget class.
 */
abstract class CampaignWidget extends \Elementor\Widget_Base {
	/**
	 * Presentation component identifier.
	 *
	 * @var string
	 */
	protected string $kind = 'products';
	/**
	 * Shared storefront service.
	 *
	 * @var Storefront|null
	 */
	private static ?Storefront $storefront = null;

	/**
	 * Share the presentation service across reconstructed Elementor widget instances.
	 *
	 * @param Storefront $storefront Shared escaped presentation service.
	 * @return void
	 */
	public static function use_storefront( Storefront $storefront ): void {
		self::$storefront = $storefront;
	}

	/**
	 * Return the stable Elementor widget identifier.
	 *
	 * @return string Escaped presentation markup or component metadata.
	 */
	public function get_name(): string {
		return 'exin-bf-' . $this->kind; }
	/**
	 * Return the widget title shown in Elementor.
	 *
	 * @return string Escaped presentation markup or component metadata.
	 */
	public function get_title(): string {
		$titles = array(
			'products'   => 'EXIN BF Products',
			'featured'   => 'EXIN BF Featured Products',
			'countdown'  => 'EXIN BF Countdown',
			'categories' => 'EXIN BF Categories',
			'status'     => 'EXIN BF Campaign Status',
			'badge'      => 'EXIN BF Product Badge',
		);
		return $titles[ $this->kind ] ?? 'EXIN Black Friday';
	}
	/**
	 * Return the Elementor panel icon.
	 *
	 * @return string Escaped presentation markup or component metadata.
	 */
	public function get_icon(): string {
		return 'eicon-woocommerce'; }
	/**
	 * Return Elementor panel categories.
	 *
	 * @return array Requested records or presentation data.
	 */
	public function get_categories(): array {
		return array( 'general' ); }
	/**
	 * Return Elementor widget search terms.
	 *
	 * @return array Requested records or presentation data.
	 */
	public function get_keywords(): array {
		return array( 'EXIN', 'black friday', 'campaign', 'بلک فرایدی' ); }

	/**
	 * Register content and appearance controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'campaign', array( 'label' => __( 'نمایش کمپین', 'exin-black-friday' ) ) );
		if ( in_array( $this->kind, array( 'products', 'featured' ), true ) ) {
			$this->add_control(
				'limit',
				array(
					'label'   => __( 'تعداد محصول در صفحه', 'exin-black-friday' ),
					'type'    => \Elementor\Controls_Manager::NUMBER,
					'default' => 12,
					'min'     => 1,
					'max'     => 48,
				)
			);
			$this->add_control(
				'columns',
				array(
					'label'   => __( 'ستون دسکتاپ', 'exin-black-friday' ),
					'type'    => \Elementor\Controls_Manager::NUMBER,
					'default' => 4,
					'min'     => 1,
					'max'     => 6,
				)
			);
			$this->add_control(
				'tablet_columns',
				array(
					'label'   => __( 'ستون تبلت', 'exin-black-friday' ),
					'type'    => \Elementor\Controls_Manager::NUMBER,
					'default' => 2,
					'min'     => 1,
					'max'     => 4,
				)
			);
			$this->add_control(
				'mobile_columns',
				array(
					'label'   => __( 'ستون موبایل', 'exin-black-friday' ),
					'type'    => \Elementor\Controls_Manager::NUMBER,
					'default' => 1,
					'min'     => 1,
					'max'     => 2,
				)
			);
			$this->add_control(
				'orderby',
				array(
					'label'   => __( 'ترتیب', 'exin-black-friday' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => 'priority',
					'options' => array(
						'priority'   => __( 'اولویت کمپین', 'exin-black-friday' ),
						'title'      => __( 'نام', 'exin-black-friday' ),
						'newest'     => __( 'جدیدترین', 'exin-black-friday' ),
						'price'      => __( 'قیمت صعودی', 'exin-black-friday' ),
						'price-desc' => __( 'قیمت نزولی', 'exin-black-friday' ),
					),
				)
			);
			$this->add_control(
				'category',
				array(
					'label' => __( 'شناسه دسته‌بندی (اختیاری)', 'exin-black-friday' ),
					'type'  => \Elementor\Controls_Manager::NUMBER,
					'min'   => 0,
				)
			);
			$this->add_control(
				'show_out_of_stock',
				array(
					'label'        => __( 'نمایش ناموجود', 'exin-black-friday' ),
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'return_value' => '1',
					'default'      => '',
				)
			);
			$this->add_control(
				'pagination',
				array(
					'label'        => __( 'صفحه‌بندی', 'exin-black-friday' ),
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);
		} elseif ( 'badge' === $this->kind ) {
			$this->add_control(
				'product_id',
				array(
					'label' => __( 'شناسه محصول یا تنوع', 'exin-black-friday' ),
					'type'  => \Elementor\Controls_Manager::NUMBER,
					'min'   => 1,
				)
			);
		}
		$this->end_controls_section();
		$this->start_controls_section(
			'appearance',
			array(
				'label' => __( 'ظاهر', 'exin-black-friday' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'accent',
			array(
				'label'     => __( 'رنگ تأکید', 'exin-black-friday' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}}' => '--exin-bf-accent: {{VALUE}};' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Render escaped presentation output while containing internal failures.
	 *
	 * @return void
	 */
	protected function render(): void {
		if ( self::$storefront ) {
			$settings = $this->get_settings_for_display();
			if ( array_key_exists( 'pagination', $settings ) && 'yes' !== $settings['pagination'] ) {
				$settings['pagination'] = 'no';
			}
			echo self::$storefront->render( $this->kind, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Presentation service escapes each output.
		}
	}
}
