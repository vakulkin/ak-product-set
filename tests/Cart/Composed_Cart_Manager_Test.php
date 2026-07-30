<?php
namespace AK_Set\Tests\Cart;

use AK_Set\Tests\TestCase;
use AK_Set\Cart\Composed_Cart_Manager;
use Brain\Monkey\Functions;

class Composed_Cart_Manager_Test extends TestCase {

    /**
     * add_set_to_cart must return false immediately when WC() returns null
     * (no active cart session). This prevents any real cart interaction.
     */
    public function test_add_set_to_cart_returns_false_when_wc_unavailable() {
        Functions\expect('WC')->andReturn(null);

        $manager = new Composed_Cart_Manager();
        $result = $manager->add_set_to_cart(1, [1], 5, []);
        $this->assertFalse($result);
    }

    /**
     * clear_existing_set_cart_items must be a no-op when WC() unavailable
     */
    public function test_clear_existing_set_cart_items_is_safe_when_wc_unavailable() {
        Functions\expect('WC')->andReturn(null);

        $manager = new Composed_Cart_Manager();
        $manager->clear_existing_set_cart_items();
        $this->assertTrue(true);
    }

    /**
     * validate_cart_stock must be a no-op when WC() unavailable
     */
    public function test_validate_cart_stock_is_safe_when_wc_unavailable() {
        Functions\expect('WC')->andReturn(null);

        $manager = new Composed_Cart_Manager();
        $manager->validate_cart_stock();
        $this->assertTrue(true);
    }

    /**
     * inject_prices_from_session must handle empty cart safely
     */
    public function test_inject_prices_from_session_is_safe_when_cart_empty() {
        $manager = new Composed_Cart_Manager();
        $manager->inject_prices_from_session(null);
        $this->assertTrue(true);
    }
}
