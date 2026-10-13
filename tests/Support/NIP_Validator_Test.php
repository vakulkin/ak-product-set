<?php

namespace AK_Set\Tests\Support;

use AK_Set\Support\NIP_Validator;
use AK_Set\Tests\TestCase;

class NIP_Validator_Test extends TestCase {

    public function test_clean_removes_country_code_and_separators(): void {
        $this->assertEquals('5252344078', NIP_Validator::clean('PL 525-234-40-78'));
        $this->assertEquals('5252344078', NIP_Validator::clean('pl5252344078'));
        $this->assertEquals('5252344078', NIP_Validator::clean(' 525 234 40 78 '));
    }

    public function test_is_valid_accepts_valid_nips(): void {
        // Valid NIPs:
        // Google Poland: 5252344078
        // Orlen: 7740001454
        $this->assertTrue(NIP_Validator::is_valid('5252344078'));
        $this->assertTrue(NIP_Validator::is_valid('PL 525-234-40-78'));
        $this->assertTrue(NIP_Validator::is_valid('7740001454'));
    }

    public function test_is_valid_rejects_invalid_nips(): void {
        $this->assertFalse(NIP_Validator::is_valid(''));
        $this->assertFalse(NIP_Validator::is_valid('12345'));
        $this->assertFalse(NIP_Validator::is_valid('0000000000'));
        $this->assertFalse(NIP_Validator::is_valid('1111111111'));
        $this->assertFalse(NIP_Validator::is_valid('5252344079')); // wrong checksum
        $this->assertFalse(NIP_Validator::is_valid('abcdefghij'));
    }
}
