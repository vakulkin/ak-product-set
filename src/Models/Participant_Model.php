<?php

namespace AK_Set\Models;

use AK_Set\Support\Helper;

if (!defined('ABSPATH')) {
    exit;
}

class Participant_Model {
    /** @var string */
    public $id;

    /** @var string */
    public $name;

    /** @var string */
    public $email;

    /** @var string */
    public $phone;

    /** @var string */
    public $tshirt_size;

    /** @var string */
    public $tshirt_cut;

    public function __construct(array $data = []) {
        $this->id = !empty($data['id']) ? sanitize_text_field($data['id']) : Helper::generate_uuid();
        $this->name = !empty($data['name']) ? sanitize_text_field($data['name']) : '';
        $this->email = !empty($data['email']) ? sanitize_email($data['email']) : '';
        $this->phone = !empty($data['phone']) ? sanitize_text_field($data['phone']) : '';
        $this->tshirt_size = !empty($data['tshirt_size']) ? sanitize_text_field($data['tshirt_size']) : '';
        $this->tshirt_cut = !empty($data['tshirt_cut']) ? sanitize_text_field($data['tshirt_cut']) : '';
    }

    /**
     * Convert model to array representation
     *
     * @return array
     */
    public function to_array() {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'tshirt_size' => $this->tshirt_size,
            'tshirt_cut'  => $this->tshirt_cut,
        ];
    }

    /**
     * Create collection of models from array of participant data
     *
     * @param array $items
     * @return Participant_Model[]
     */
    public static function from_collection(array $items) {
        $models = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $models[] = new self($item);
            }
        }
        return $models;
    }
}
