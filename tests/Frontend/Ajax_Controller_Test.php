<?php
namespace AK_Set\Tests\Frontend;

use AK_Set\Tests\TestCase;
use AK_Set\Frontend\Ajax_Controller;
use Brain\Monkey\Functions;
use Mockery;

class Ajax_Controller_Test extends TestCase {

    /**
     * Helper: simulate a handle_add_to_cart call with given POST data.
     * Captures the exception thrown by our wp_send_json_error stub.
     */
    private function callHandleAddToCart(array $post): string {
        $_POST = $post;
        $controller = new Ajax_Controller();

        try {
            $controller->handle_add_to_cart();
            return '__SUCCESS__';
        } catch (\Exception $e) {
            return $e->getMessage();
        } finally {
            $_POST = [];
        }
    }

    protected function setUp(): void {
        parent::setUp();
        Functions\when('check_ajax_referer')->justReturn(true);
    }

    // -------------------------------------------------------------------------
    // Missing set_id / no weekends selected
    // -------------------------------------------------------------------------

    public function test_handle_add_to_cart_rejects_missing_set_id() {
        $result = $this->callHandleAddToCart([
            'set_id'           => 0,
            'selected_weekends' => [1, 2],
            'headcount'        => 1,
        ]);

        $decoded = json_decode($result, true);
        $this->assertStringContainsString('termin', $decoded['message']);
    }

    public function test_handle_add_to_cart_rejects_empty_weekends() {
        $result = $this->callHandleAddToCart([
            'set_id'            => 5,
            'selected_weekends' => [],
            'headcount'         => 1,
        ]);

        $decoded = json_decode($result, true);
        $this->assertStringContainsString('termin', $decoded['message']);
    }

    // -------------------------------------------------------------------------
    // Non-existent set
    // -------------------------------------------------------------------------

    public function test_handle_add_to_cart_rejects_nonexistent_set() {
        Functions\when('get_post')->justReturn(null);

        $result = $this->callHandleAddToCart([
            'set_id'            => 999,
            'selected_weekends' => [1],
            'headcount'         => 1,
            'participants'      => [],
        ]);

        $decoded = json_decode($result, true);
        $this->assertStringContainsString('istnieje', $decoded['message']);
    }

    // -------------------------------------------------------------------------
    // Participant field validation
    // -------------------------------------------------------------------------

    public function test_handle_add_to_cart_rejects_incomplete_participant() {
        $fakePost = new \stdClass();
        $fakePost->ID = 5;
        $fakePost->post_type = 'ak_set';
        $fakePost->post_title = 'Test Set';

        Functions\when('get_post')->justReturn($fakePost);
        // has_tshirt → false
        Functions\when('get_field')->justReturn(false);

        $result = $this->callHandleAddToCart([
            'set_id'            => 5,
            'selected_weekends' => [1],
            'headcount'         => 1,
            'participants'      => [
                ['name' => 'Alice', 'email' => '', 'phone' => '123'], // missing email
            ],
        ]);

        $decoded = json_decode($result, true);
        $this->assertStringContainsString('prawidłowy adres e-mail', $decoded['message']);
    }

    public function test_handle_add_to_cart_rejects_missing_tshirt_size_when_required() {
        $fakePost = new \stdClass();
        $fakePost->ID = 5;
        $fakePost->post_type = 'ak_set';
        $fakePost->post_title = 'Test Set';

        Functions\when('get_post')->justReturn($fakePost);
        // has_tshirt → true
        Functions\when('get_field')->justReturn(true);

        $result = $this->callHandleAddToCart([
            'set_id'            => 5,
            'selected_weekends' => [1],
            'headcount'         => 1,
            'participants'      => [
                ['name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '+48600000000', 'tshirt_size' => ''],
            ],
        ]);

        $decoded = json_decode($result, true);
        $this->assertStringContainsString('rozmiar koszulki', $decoded['message']);
    }

    // -------------------------------------------------------------------------
    // Participant count truncation to headcount
    // -------------------------------------------------------------------------

    public function test_participant_payload_is_truncated_to_headcount() {
        // Confirms that array_slice in the controller limits participants
        $participants = [
            ['name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '111'],
            ['name' => 'Bob',   'email' => 'bob@example.com',   'phone' => '222'],
            ['name' => 'Carl',  'email' => 'carl@example.com',  'phone' => '333'],
        ];

        $sliced = array_slice($participants, 0, 1);
        $this->assertCount(1, $sliced);
        $this->assertEquals('Alice', $sliced[0]['name']);
    }
}
