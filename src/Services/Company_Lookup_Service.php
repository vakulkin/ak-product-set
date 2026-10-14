<?php

namespace AK_Set\Services;

use AK_Set\Support\NIP_Validator;

if (!defined('ABSPATH')) {
    exit;
}

class Company_Lookup_Service {

    const BIALA_LISTA_URL = 'https://wl-api.mf.gov.pl/api/search/nip';
    const CEIDG_URL       = 'https://dane.biznes.gov.pl/api/ceidg/v2/firmy';
    const CACHE_EXPIRATION = DAY_IN_SECONDS; // 24 hours
    /**
     * Lookup company details by NIP.
     * Uses Biała Lista VAT first (no token required).
     * Falls back to CEIDG v2 if token is configured.
     *
     * @return array [
     *     'success' => bool,
     *     'source'  => string, // 'biala_lista' | 'ceidg'
     *     'data'    => array,
     *     'error'   => string|null,
     * ]
     */
    public static function lookup(string $raw_nip): array {
        $nip = NIP_Validator::clean($raw_nip);

        if (!NIP_Validator::is_valid($nip)) {
            return [
                'success' => false,
                'source'  => '',
                'data'    => [],
                'error'   => __('Nieprawidłowy numer NIP (błędna suma kontrolna).', 'ak-product-set'),
            ];
        }

        // 1. Check WordPress transient cache
        $cache_key = 'ak_nip_lookup_' . $nip;
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['success'])) {
            return $cached;
        }

        // 2. Primary source: Biała Lista VAT (Ministerstwo Finansów)
        $biala_lista_result = self::query_biala_lista($nip);
        if ($biala_lista_result['success']) {
            set_transient($cache_key, $biala_lista_result, self::CACHE_EXPIRATION);
            return $biala_lista_result;
        }

        // 3. Fallback source: CEIDG v2 (biznes.gov.pl)
        $ceidg_token = self::get_ceidg_token();
        if ($ceidg_token !== '') {
            $ceidg_result = self::query_ceidg($nip, $ceidg_token);
            if ($ceidg_result['success']) {
                set_transient($cache_key, $ceidg_result, self::CACHE_EXPIRATION);
                return $ceidg_result;
            }
        }

        // If neither found the company
        return [
            'success' => false,
            'source'  => '',
            'data'    => [],
            'error'   => __('Nie znaleziono danych firmy dla podanego NIP w rejestrze. Możesz wpisać dane firmy ręcznie.', 'ak-product-set'),
        ];
    }

    /**
     * Query Biała Lista VAT API.
     */
    public static function query_biala_lista(string $nip): array {
        $date = gmdate('Y-m-d');
        $endpoint = sprintf('%s/%s?date=%s', self::BIALA_LISTA_URL, rawurlencode($nip), $date);

        $response = wp_remote_get($endpoint, [
            'timeout' => 5,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [
                'success' => false,
                'error'   => sprintf('HTTP %d', $code),
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if (!is_array($json) || empty($json['result']['subject'])) {
            return [
                'success' => false,
                'error'   => __('Brak podmiotu w odpowiedzi Białej Listy.', 'ak-product-set'),
            ];
        }

        $subject = $json['result']['subject'];
        $raw_address = !empty($subject['workingAddress']) ? $subject['workingAddress'] : ($subject['residenceAddress'] ?? '');
        $address = self::parse_address((string)$raw_address);

        $name = trim($subject['name'] ?? '');
        $addr_parts = array_filter([$address['street'], trim($address['postcode'] . ' ' . $address['city'])]);
        $formatted = $name . (!empty($addr_parts) ? ', ' . implode(', ', $addr_parts) : '');

        return [
            'success' => true,
            'source'  => 'biala_lista',
            'data'    => [
                'nip'        => $subject['nip'] ?? $nip,
                'name'       => $name,
                'street'     => $address['street'],
                'postcode'   => $address['postcode'],
                'city'       => $address['city'],
                'formatted'  => $formatted,
                'status_vat' => $subject['statusVat'] ?? '',
                'regon'      => $subject['regon'] ?? '',
                'krs'        => $subject['krs'] ?? '',
            ],
            'error'   => null,
        ];
    }

    /**
     * Query CEIDG v2 API.
     */
    public static function query_ceidg(string $nip, string $token): array {
        $endpoint = sprintf('%s?nip=%s', self::CEIDG_URL, rawurlencode($nip));

        $response = wp_remote_get($endpoint, [
            'timeout' => 5,
            'headers' => [
                'Authorization' => 'Bearer ' . trim($token),
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [
                'success' => false,
                'error'   => sprintf('HTTP %d', $code),
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if (!is_array($json) || empty($json['firmy']) || !isset($json['firmy'][0])) {
            return [
                'success' => false,
                'error'   => __('Brak podmiotu w bazie CEIDG.', 'ak-product-set'),
            ];
        }

        $firma = $json['firmy'][0];
        $addr = $firma['adresDzialalnosci'] ?? [];

        $street_parts = [];
        if (!empty($addr['ulica'])) {
            $street_parts[] = $addr['ulica'];
        }
        if (!empty($addr['budynek'])) {
            $street_parts[] = $addr['budynek'];
        }
        $street = implode(' ', $street_parts);
        if (!empty($addr['lokal'])) {
            $street .= '/' . $addr['lokal'];
        }

        $name = trim($firma['nazwa'] ?? '');
        $postcode = trim($addr['kod'] ?? '');
        $city = trim($addr['miasto'] ?? '');
        $addr_parts = array_filter([$street, trim($postcode . ' ' . $city)]);
        $formatted = $name . (!empty($addr_parts) ? ', ' . implode(', ', $addr_parts) : '');

        return [
            'success' => true,
            'source'  => 'ceidg',
            'data'    => [
                'nip'        => $firma['wlasciciel']['nip'] ?? $nip,
                'name'       => $name,
                'street'     => $street,
                'postcode'   => $postcode,
                'city'       => $city,
                'formatted'  => $formatted,
                'status_vat' => '',
                'regon'      => '',
                'krs'        => '',
            ],
            'error'   => null,
        ];
    }

    /**
     * Parse Polish address string into street, postcode, city.
     * Examples:
     * - "ul. Emilii Plater 53, 00-113 Warszawa"
     * - "MARSZAŁKOWSKA 10/2, 00-001 WARSZAWA"
     * - "Lipowa 5 80-001 Gdańsk"
     *
     * @return array ['street' => string, 'postcode' => string, 'city' => string]
     */
    public static function parse_address(string $raw): array {
        $raw = trim($raw);
        $postcode = '';
        $city     = '';

        if ($raw === '') {
            return ['street' => '', 'postcode' => '', 'city' => ''];
        }

        // Try format: Street, XX-XXX City or Street XX-XXX City
        if (preg_match('/^(.*?)[,\s]+(\d{2}-\d{3})\s+(.*)$/u', $raw, $matches)) {
            $street   = trim(rtrim($matches[1], ','));
            $postcode = trim($matches[2]);
            $city     = trim($matches[3]);
        } else {
            $street = $raw;
        }

        return [
            'street'   => $street,
            'postcode' => $postcode,
            'city'     => $city,
        ];
    }

    /**
     * Get CEIDG API token from constant or option.
     */
    public static function get_ceidg_token(): string {
        if (defined('AK_CEIDG_API_TOKEN') && is_string(AK_CEIDG_API_TOKEN)) {
            return trim(AK_CEIDG_API_TOKEN);
        }
        $opt = get_option('ak_ceidg_api_token', '');
        return is_string($opt) ? trim($opt) : '';
    }
}
