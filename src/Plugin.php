<?php

namespace AK_Set;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin {
    /**
     * Singleton instance
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Registered services
     * @var array
     */
    private $services = [];

    /**
     * Get singleton instance
     * @return Plugin
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Boot all plugin services
     */
    public function boot(): void {
        // Core Admin & Custom Post Types
        $this->services['acf_registrar']     = new Admin\ACF_Registrar();
        $this->services['roster_page']       = new Admin\Roster_Admin_Page();
        $this->services['roster_guard']      = new Admin\Roster_Access_Guard();

        // Ensure roster manager role and capabilities are synced
        $roster_caps = [
            'read'                          => true,
            'ak_view_roster'                => true,
            'upload_files'                  => true,

            // Read-only Shop Orders
            'edit_shop_orders'              => true,
            'read_shop_order'               => true,
            'read_private_shop_orders'      => true,
            'edit_others_shop_orders'       => true,
            'edit_published_shop_orders'    => true,
            'publish_shop_orders'           => true,

            // Courses (ak_set CPT capability_type => 'post')
            'edit_posts'                    => true,
            'edit_others_posts'             => true,
            'publish_posts'                 => true,
            'read_private_posts'            => true,
            'delete_posts'                  => true,
            'delete_others_posts'           => true,
            'delete_published_posts'        => true,
            'delete_private_posts'          => true,
            'edit_private_posts'            => true,
            'edit_published_posts'          => true,

            // Products
            'edit_product'                  => true,
            'read_product'                  => true,
            'delete_product'                => true,
            'edit_products'                 => true,
            'edit_others_products'          => true,
            'publish_products'              => true,
            'read_private_products'         => true,
            'delete_products'               => true,
            'delete_private_products'       => true,
            'delete_published_products'     => true,
            'delete_others_products'        => true,
            'edit_private_products'         => true,
            'edit_published_products'       => true,
            'manage_product_terms'          => true,
            'edit_product_terms'            => true,
            'delete_product_terms'          => true,
            'assign_product_terms'          => true,

            // Coupons
            'edit_shop_coupon'              => true,
            'read_shop_coupon'              => true,
            'delete_shop_coupon'            => true,
            'edit_shop_coupons'             => true,
            'edit_others_shop_coupons'      => true,
            'publish_shop_coupons'          => true,
            'read_private_shop_coupons'     => true,
            'delete_shop_coupons'           => true,
            'delete_private_shop_coupons'   => true,
            'delete_published_shop_coupons' => true,
            'delete_others_shop_coupons'    => true,
            'edit_private_shop_coupons'     => true,
            'edit_published_shop_coupons'   => true,
        ];

        $roster_role = get_role('ak_roster_manager');
        if ($roster_role) {
            foreach ($roster_caps as $cap => $grant) {
                if (!$roster_role->has_cap($cap)) {
                    $roster_role->add_cap($cap);
                }
            }
        } else {
            add_role('ak_roster_manager', __('Menadżer rejestru', 'ak-product-set'), $roster_caps);
        }

        foreach (['administrator', 'shop_manager'] as $role_name) {
            $role = get_role($role_name);
            if ($role && !$role->has_cap('ak_view_roster')) {
                $role->add_cap('ak_view_roster');
            }
        }

        // Cart & Order Engine
        $this->services['cart_manager'] = new Cart\Composed_Cart_Manager();
        $this->services['cart_display'] = new Cart\Cart_Display_Filters();
        $this->services['order_processor'] = new Order\Order_Processor();
        $this->services['order_decomposer'] = new Order\Order_Decomposer();

        // Frontend & Shortcode
        $this->services['catalog_excluder'] = new Frontend\Catalog_Excluder();
        $this->services['shortcode_handler'] = new Frontend\Shortcode_Handler();
        $this->services['ajax_controller'] = new Frontend\Ajax_Controller();

        // Initialize services
        foreach ($this->services as $service) {
            if (method_exists($service, 'init')) {
                $service->init();
            }
        }
    }

    /**
     * Get a registered service instance
     * 
     * @param string $key
     * @return mixed|null
     */
    public function get_service(string $key) {
        return isset($this->services[$key]) ? $this->services[$key] : null;
    }
}
