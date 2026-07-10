<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'success' => true,
    'message' => 'Backend КП ШИШКИ работает',
    'server_time' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
