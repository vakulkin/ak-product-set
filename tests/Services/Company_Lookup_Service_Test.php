<?php

namespace AK_Set\Tests\Services;

use AK_Set\Services\Company_Lookup_Service;
use AK_Set\Tests\TestCase;
use Brain\Monkey\Functions;

class Company_Lookup_Service_Test extends TestCase {

    public function test_parse_address_correctly_splits_polish_addresses(): void {
        $addr1 = Company_Lookup_Service::parse_address('ul. Emilii Plater 53, 00-113 Warszawa');
        $this->assertEquals('ul. Emilii Plater 53', $addr1['street']);
        $this->assertEquals('00-113', $addr1['postcode']);
        $this->assertEquals('Warszawa', $addr1['city']);

        $addr2 = Company_Lookup_Service::parse_address('MARSZAŁKOWSKA 10/2, 00-001 WARSZAWA');
        $this->assertEquals('MARSZAŁKOWSKA 10/2', $addr2['street']);
        $this->assertEquals('00-001', $addr2['postcode']);
        $this->assertEquals('WARSZAWA', $addr2['city']);

        $addr3 = Company_Lookup_Service::parse_address('Gdańska 4 80-001 Gdańsk');
        $this->assertEquals('Gdańska 4', $addr3['street']);
        $this->assertEquals('80-001', $addr3['postcode']);
        $this->assertEquals('Gdańsk', $addr3['city']);
    }

    public function test_lookup_bails_on_invalid_nip(): void {
        $result = Company_Lookup_Service::lookup('123456');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('błędna suma kontrolna', $result['error']);
    }

    public function test_query_biala_lista_success(): void {
        $fake_body = json_encode([
            'result' => [
                'subject' => [
                    'nip'            => '5252344078',
                    'name'           => 'TEST COMPANY SP. Z O.O.',
                    'statusVat'      => 'Czynny',
                    'regon'          => '123456789',
                    'krs'            => '0000123456',
                    'workingAddress' => 'ul. Testowa 10, 00-001 Warszawa',
                ],
            ],
        ]);

        Functions\stubs([
            'wp_remote_get' => function() { return ['response' => ['code' => 200]]; },
            'is_wp_error' => function() { return false; },
            'wp_remote_retrieve_response_code' => function() { return 200; },
            'wp_remote_retrieve_body' => function() use ($fake_body) { return $fake_body; },
        ]);

        $res = Company_Lookup_Service::query_biala_lista('5252344078');
        $this->assertTrue($res['success']);
        $this->assertEquals('biala_lista', $res['source']);
        $this->assertEquals('TEST COMPANY SP. Z O.O.', $res['data']['name']);
        $this->assertEquals('ul. Testowa 10', $res['data']['street']);
        $this->assertEquals('00-001', $res['data']['postcode']);
        $this->assertEquals('Warszawa', $res['data']['city']);
        $this->assertEquals('Czynny', $res['data']['status_vat']);
    }
}
