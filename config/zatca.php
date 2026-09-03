<?php

return [
    'environment' => env('ZATCA_ENV', 'sandbox'),
    'base_url' => rtrim(env(
        'ZATCA_BASE_URL',
        'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
    ), '/'),
    'portal_url' => env('ZATCA_PORTAL_URL', 'https://sandbox.zatca.gov.sa'),
    'accept_version' => env('ZATCA_ACCEPT_VERSION', 'V2'),
    'timeout' => (int) env('ZATCA_TIMEOUT', 15),

    'storage_disk' => env('ZATCA_STORAGE_DISK', 'local'),
    'storage_path' => trim(env('ZATCA_STORAGE_PATH', 'zatca'), '/'),
    'openssl_bin' => env('ZATCA_OPENSSL_BIN', 'openssl'),
    'openssl_timeout' => (int) env('ZATCA_OPENSSL_TIMEOUT', 20),

    'csr' => [
        'templates' => [
            'sandbox' => 'TSTZATCA-Code-Signing',
            'simulation' => 'PREZATCA-Code-Signing',
            'production' => 'ZATCA-Code-Signing',
        ],
        'egs_solution_name' => env('ZATCA_EGS_SOLUTION', 'GaslahPOS'),
        'egs_model' => env('ZATCA_EGS_MODEL', 'Laravel13'),
        'common_name' => env('ZATCA_EGS_COMMON_NAME', 'GaslahPOS-EGS'),
        'invoice_type' => env('ZATCA_INVOICE_TYPE', '1100'),
        'business_category' => env('ZATCA_BUSINESS_CATEGORY', 'Laundry'),
        'registered_address' => env('ZATCA_REGISTERED_ADDRESS', 'Riyadh, KSA'),
        'country' => env('ZATCA_COUNTRY', 'SA'),
    ],
];
