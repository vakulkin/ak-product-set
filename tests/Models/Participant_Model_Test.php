<?php
namespace AK_Set\Tests\Models;

use AK_Set\Tests\TestCase;
use AK_Set\Models\Participant_Model;
use Brain\Monkey\Functions;

class Participant_Model_Test extends TestCase {
    
    public function test_initializes_with_empty_data() {
        $model = new Participant_Model([]);
        
        $this->assertNotEmpty($model->id, "UUID should be generated if id is not provided");
        $this->assertEquals('', $model->name);
        $this->assertEquals('', $model->email);
        $this->assertEquals('', $model->phone);
        $this->assertEquals('', $model->tshirt_size);
        $this->assertEquals('', $model->tshirt_cut);
    }

    public function test_initializes_with_provided_data() {
        $data = [
            'id' => '1234-abcd',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '123456789',
            'tshirt_size' => 'L',
            'tshirt_cut' => 'men'
        ];

        $model = new Participant_Model($data);
        
        $this->assertEquals('1234-abcd', $model->id);
        $this->assertEquals('John Doe', $model->name);
        $this->assertEquals('john@example.com', $model->email);
        $this->assertEquals('123456789', $model->phone);
        $this->assertEquals('L', $model->tshirt_size);
        $this->assertEquals('men', $model->tshirt_cut);
    }

    public function test_to_array_returns_correct_format() {
        $data = [
            'id' => '1234-abcd',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '123456789',
            'tshirt_size' => 'L',
            'tshirt_cut' => 'men'
        ];

        $model = new Participant_Model($data);
        $array = $model->to_array();

        $this->assertEquals($data, $array);
    }

    public function test_from_collection_builds_models() {
        $data = [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com']
        ];

        $models = Participant_Model::from_collection($data);
        
        $this->assertCount(2, $models);
        $this->assertInstanceOf(Participant_Model::class, $models[0]);
        $this->assertEquals('Alice', $models[0]->name);
        $this->assertEquals('Bob', $models[1]->name);
        $this->assertNotEmpty($models[0]->id);
        $this->assertNotEmpty($models[1]->id);
    }
}
