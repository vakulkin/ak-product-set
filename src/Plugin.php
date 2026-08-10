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
        $this->services['order_admin_view']  = new Admin\Order_Admin_View();
        $this->services['roster_page']       = new Admin\Roster_Admin_Page();
        $this->services['roster_guard']      = new Admin\Roster_Access_Guard();

        // Ensure roster manager role and capabilities are synced
        $roster_caps = [
            'read'                       => true,
            'ak_view_roster'             => true,
            'edit_shop_orders'           => true,
            'read_shop_order'            => true,
            'read_private_shop_orders'   => true,
            'edit_others_shop_orders'    => true,
            'edit_published_shop_orders' => true,
            'publish_shop_orders'        => true,
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
