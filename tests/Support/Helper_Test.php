<?php
namespace AK_Set\Tests\Support;

use AK_Set\Tests\TestCase;
use AK_Set\Support\Helper;
use Mockery;

class Helper_Test extends TestCase {

    public function test_generate_uuid_returns_valid_uuid_v4_format() {
        $uuid = Helper::generate_uuid();
        $this->assertIsString($uuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
    }

    public function test_get_pricing_field_key_formats_correctly() {
        $key = Helper::get_pricing_field_key(2, 1, 'ind');
        $this->assertEquals('price_2w_round1_ind', $key);

        $key_g5 = Helper::get_pricing_field_key(5, 3, 'g5');
        $this->assertEquals('price_5w_round3_g5', $key_g5);
    }

    public function test_get_tshirt_sizes_and_cuts_return_arrays() {
        $sizes = Helper::get_tshirt_sizes();
        $cuts  = Helper::get_tshirt_cuts();

        $this->assertIsArray($sizes);
        $this->assertArrayHasKey('S', $sizes);
        $this->assertArrayHasKey('XL', $sizes);

        $this->assertIsArray($cuts);
        $this->assertArrayHasKey('men', $cuts);
        $this->assertArrayHasKey('women', $cuts);
    }

    public function test_add_participant_meta_to_order_item_adds_formatted_meta() {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('add_meta_data')->with('_ak_participants', Mockery::type('array'), true)->once();
        $item->shouldReceive('add_meta_data')->with('Uczestnik 1', Mockery::type('string'), true)->once();

        $participants = [
            [
                'name'        => 'Jan Kowalski',
                'email'       => 'jan@example.com',
                'phone'       => '+48600000000',
                'tshirt_size' => 'M',
                'tshirt_cut'  => 'men',
            ],
        ];

        Helper::add_participant_meta_to_order_item($participants, $item);
        $this->assertTrue(true);
    }
}
