<?php

namespace AK_Set\Admin;

use AK_Set\Frontend\Template_Loader;
use AK_Set\Models\Weekend_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Admin_View {

    public function init() {
        // Disabled custom card rendering to allow standard WooCommerce order item meta fields
    }
}
