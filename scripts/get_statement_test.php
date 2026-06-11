<?php
// quick test to call statements endpoint
$url = 'http://127.0.0.1:8000/api/statements?account_id=1&start_date=2026-06-01&end_date=2026-06-04&per_page=5';
$opts = ['http' => ['method' => 'GET', 'ignore_errors' => true]];
$context = stream_context_create($opts);
$res = file_get_contents($url, false, $context);
$status = null;
$headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
if (!empty($headers)) {
    foreach ($headers as $h) {
        if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $h, $m)) {
            $status = (int)$m[1];
            break;
        }
    }
}
echo json_encode(['status' => $status, 'response' => json_decode($res, true)], JSON_PRETTY_PRINT);
