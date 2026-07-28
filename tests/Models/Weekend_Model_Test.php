<?php
namespace AK_Set\Tests\Models;

use AK_Set\Tests\TestCase;
use AK_Set\Models\Weekend_Model;
use Brain\Monkey\Functions;
use Mockery;

class Weekend_Model_Test extends TestCase {

    public function test_managing_stock_and_get_stock_quantity() {
        $wcProduct = Mockery::mock('WC_Product');
        $wcProduct->shouldReceive('managing_stock')->andReturn(true);
        $wcProduct->shouldReceive('get_stock_quantity')->andReturn(12);

        Functions\when('wc_get_product')->justReturn($wcProduct);

        $model = new Weekend_Model(101);
        $this->assertTrue($model->managing_stock());
        $this->assertEquals(12, $model->get_stock_quantity());
    }

    public function test_is_expired_returns_false_when_no_end_date() {
        $wcProduct = Mockery::mock('WC_Product');
        Functions\when('wc_get_product')->justReturn($wcProduct);
        Functions\when('get_field')->justReturn('');

        $model = new Weekend_Model(101);
        $this->assertFalse($model->is_expired());
    }
}
