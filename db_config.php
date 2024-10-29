<?php

if (!defined('ABSPATH')) exit;

return [
    'upsert_abmsense_main_data' => 'https://api.abmsense.com/upsert_abmsense_main_data',
    'upsert_abmsense_our_customers_details' => 'https://api.abmsense.com/upsert_abmsense_our_customers_details',
    'check_consent_enabled' => 'https://api.abmsense.com/check_consent_enabled',
    'export_data_url' => 'https://api.abmsense.com/export_data',
    'public_key' => str_replace(
        "\r\n",
        "\n",
        "-----BEGIN PUBLIC KEY-----\n" .
        "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0fLuSwYCux6/wmjrPBCm\n" .
        "3W28MRK/Tdbcb5d9yGCGbMapFnanigIaChNJvUs/Nn/vIHpF19cx9jyt80RkFPIT\n" .
        "Pj8aQfEn/WVaw6gLohOgnlAHooGZhXNuC2hBQYpIMaDsVUU5NunJaaW1JuRMiI0i\n" .
        "8nNdZd4WnAQaUhhWcOzuBUj2RIdiewgdbnW9CJCESFoAjgDMP7etmqN96MgZKttE\n" .
        "VsVoBuVy4KlnKDdCibvJh8XVaiwtY9gqsIRXsOnC/ttfAOYRfFOTVcJj3NwSED2R\n" .
        "lQvq+M9asXu1K9iTSeRL2DiPIhp77NJff1khrz4SUrhr57uVlTZPOY7E5F1IgpAP\n" .
        "wwIDAQAB\n" .
        "-----END PUBLIC KEY-----\n"
    ),
];
