<?php
declare(strict_types=1);

return [
    'default' => [
        'enable_ssl' => false,
        'hosts' => [env('ELASTICSEARCH_HOST', 'http://127.0.0.1:9200')],
        'username' => env('ELASTICSEARCH_USERNAME', 'elastic'),
        'password' => env('ELASTICSEARCH_PASSWORD', ''),
        'https_cert_path' => BASE_PATH . env('ELASTICSEARCH_HTTPS_CERT_PATH', '/storage/cert/http_ca.crt'),
        'max_connections' => 50,
        'timeout' => 2.0
    ]
];
