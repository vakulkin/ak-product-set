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

        // Ensure roster manager role and administrator cap are registered
        if (!get_role('ak_roster_manager')) {
            add_role('ak_roster_manager', __('Menadżer rejestru', 'ak-product-set'), [
                'read'           => true,
                'ak_view_roster' => true,
            ]);
        }

        $admin_role = get_role('administrator');
        if ($admin_role && !$admin_role->has_cap('ak_view_roster')) {
            $admin_role->add_cap('ak_view_roster');
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
