<?php

declare(strict_types=1);

/**
 * Case table of specs/010-api-context/contracts/matching.md (SC-002). Each row: n, template text of a group
 * (null = withheld), config (base_path, ignore_prefixes), expected operation name or null, optional op overrides.
 * Rows 35-37 also pin the line level (EndpointResolver, later): `noLine` = the operation matches but has no text to show.
 */

// name => [METHOD, template, summary, tag, deprecated, operationId]
$ops = [
    'op1' => ['POST', '/orders/{id}/refund', 'Refund an order', 'payments', false, ''],
    'op2' => ['GET', '/orders/{id}', 'Get an order', 'orders', false, ''],
    'op3' => ['DELETE', '/orders/{id}', 'Delete an order', 'orders', false, ''],
    'op4' => ['GET', '/orders/recent', 'Recent orders', 'orders', false, ''],
    'op5' => ['GET', '/users/{userId}', 'Get a user', 'users', false, ''],
    'op6' => ['GET', '/users/{userId}/orders/{orderId}', 'Get a user order', 'users', false, ''],
    'op7' => ['GET', '/files/{name}.{ext}', 'Download a file', 'files', false, ''],
    'op8' => ['GET', '/health', 'Health probe', 'ops', false, ''],
    'op9' => ['GET', '/a/{x}/c', 'A one', 'a', false, ''],
    'op10' => ['GET', '/a/{y}/c', 'A two', 'a', false, ''],
    'op11' => ['POST', '/v2/orders/{id}/refund', 'Refund an order (v2)', 'payments', false, ''],
    'op12' => ['GET', '/', 'Root', 'ops', false, ''],
];

$rows = [
    [1, 'POST /orders/<ID>/refund failed: timeout', [], 'op1'],
    [2, 'POST /api/orders/<ID>/refund failed', [], 'op1'],
    [3, 'post /orders/<ID>/refund failed', [], 'op1'],
    [4, 'GET /orders/<N>', [], 'op2'],
    [5, '/orders/<ID>', [], null],
    [6, '/orders/<ID>/refund', [], 'op1'],
    [7, 'GET /orders/recent', [], 'op4'],
    [8, 'GET /orders/<ID>', [], 'op2'],
    [9, 'GET /orders/latest', [], null],
    [10, 'GET /users/<N>', [], 'op5'],
    [11, 'GET /users/<EMAIL>', [], 'op5'],
    [12, 'GET /users/<TOKEN>/orders/<N>', [], 'op6'],
    [13, 'GET /users/<NAME>/orders/<STR>', [], 'op6'],
    [14, 'GET /users/<USER>', [], 'op5'],
    [15, 'GET /users/<N>/orders', [], null],
    [16, 'GET /files/<PATH>', [], null],
    [17, 'GET /files/<PATH>/…/x.png', [], null],
    [18, 'GET /files/report.pdf', [], null],
    [19, 'GET /files/<N>.pdf', [], null],
    [20, 'GET /health', [], 'op8'],
    [21, 'GET /health/', [], 'op8'],
    [22, 'GET /health?probe=1', [], 'op8'],
    [23, 'GET /HEALTH', [], null],
    [24, 'GET /a/<ID>/c', [], null],
    [25, 'POST /v2/orders/<ID>/refund', ['ignore_prefixes' => ['/v2']], 'op1'],
    [26, 'POST /v2/orders/<ID>/refund', [], 'op11'],
    [27, 'POST /orders/<ID>/refund', ['base_path' => '/api'], 'op1'],
    [28, 'POST /api/api/orders/<ID>/refund', [], null],
    [29, 'GET /', [], 'op12'],
    [30, 'GET /orders/<ID>, retrying', [], 'op2'],
    [31, 'error at /var/log/app.log', [], null],
    [32, 'connect ECONNREFUSED <HOST:orders-db>/orders/<ID>', [], null],
    [33, 'a/b/c 10/12', [], null],
    [34, null, [], null],
    [35, 'GET /orders/<ID>', [], 'op2', ['op2' => ['GET', '/orders/{id}', 'Get an order', 'orders', true, '']]],
    [36, 'GET /orders/<ID>', [], 'op2', ['op2' => ['GET', '/orders/{id}', '', 'orders', false, 'getOrder']]],
    [37, 'GET /orders/<ID>', [], 'op2', ['op2' => ['GET', '/orders/{id}', '', '', false, '']], 'noLine'],
    [38, 'retry 3 / 5 failed', [], null],
    [39, 'a // b', [], null],
    [40, 'retry 3 / 5 then GET /health', [], 'op8'],
    [41, 'see /', [], null],
];

return ['ops' => $ops, 'rows' => $rows, 'base' => '/api'];
