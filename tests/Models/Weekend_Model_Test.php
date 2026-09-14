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

    public function test_is_expired_evaluates_event_end_datetime_correctly() {
        $wcProduct = Mockery::mock('WC_Product');
        Functions\when('wc_get_product')->justReturn($wcProduct);

        $now = 1700000000;

        // Past event date
        Functions\when('get_field')->alias(function($field) {
            if ($field === 'ak_event_end_datetime') {
                return '2023-11-13 18:00:00'; // ts: 1699898400 < 1700000000
            }
            return '';
        });
        $pastModel = new Weekend_Model(101);
        $this->assertTrue($pastModel->is_expired($now));

        // Future event date
        Functions\when('get_field')->alias(function($field) {
            if ($field === 'ak_event_end_datetime') {
                return '2023-11-20 18:00:00'; // ts: 1700503200 > 1700000000
            }
            return '';
        });
        $futureModel = new Weekend_Model(102);
        $this->assertFalse($futureModel->is_expired($now));
    }

    public function test_is_expired_falls_back_to_start_datetime_when_end_is_empty() {
        $wcProduct = Mockery::mock('WC_Product');
        Functions\when('wc_get_product')->justReturn($wcProduct);

        $now = 1700000000;

        // Event started in past, no end date
        Functions\when('get_field')->alias(function($field) {
            if ($field === 'ak_event_start_datetime') {
                return '2023-11-13 10:00:00'; // ts < 1700000000
            }
            return '';
        });
        $model = new Weekend_Model(101);
        $this->assertTrue($model->is_expired($now));

        // Event starts in future, no end date
        Functions\when('get_field')->alias(function($field) {
            if ($field === 'ak_event_start_datetime') {
                return '2023-11-20 10:00:00'; // ts > 1700000000
            }
            return '';
        });
        $futureModel = new Weekend_Model(102);
        $this->assertFalse($futureModel->is_expired($now));
    }

    public function test_is_expired_evaluates_recruitment_end_datetime_correctly() {
        $wcProduct = Mockery::mock('WC_Product');
        Functions\when('wc_get_product')->justReturn($wcProduct);

        $now = 1700000000;

        // Recruitment ended in past, future event date
        Functions\when('get_field')->alias(function($field) {
            if ($field === 'ak_event_end_datetime') {
                return '2023-11-25 18:00:00'; // in future compared to $now
            }
            if ($field === 'ak_recruitment_end_datetime') {
                return '2023-11-10 23:59:59'; // in past compared to $now
            }
            return '';
        });
        $model = new Weekend_Model(101);
        $this->assertTrue($model->is_expired($now));

        // Recruitment ends in future
        Functions\when('get_field')->alias(function($field) {
            if ($field === 'ak_event_end_datetime') {
                return '2023-11-25 18:00:00';
            }
            if ($field === 'ak_recruitment_end_datetime') {
                return '2023-11-20 23:59:59';
            }
            return '';
        });
        $futureRecruitModel = new Weekend_Model(102);
        $this->assertFalse($futureRecruitModel->is_expired($now));
    }
}
