<?php

declare(strict_types=1);

// Router for `php -S` in ResponseCapTest: one hostile reply, chosen by NOTIFY_CAP_MODE; sizes follow NOTIFY_CAP_BYTES
// (the notifier cap, passed in by the test so the fixture never holds its own copy of the number).

$mode = (string) getenv('NOTIFY_CAP_MODE');
$cap = (int) getenv('NOTIFY_CAP_BYTES');
header('Content-Type: application/json');

switch ($mode) {
    case 'ok':
        echo '{"ok":true}';
        break;
    case 'large':
        // A declared Content-Length over the cap: refused at the headers, the body is never sent.
        header('Content-Length: ' . ($cap + 1));
        echo str_repeat('x', $cap + 1);
        break;
    case 'chunked':
        // No Content-Length: flushed pieces of 8 KiB past the cap, so the reply is chunked and only the sink can cut it.
        for ($sent = 0; $sent < $cap + 32768; $sent += 8192) {
            echo str_repeat('x', 8192);
            flush();
        }
        break;
    case 'trickle':
        // One piece of 4 KiB every 10 ms for 20 s: the cap is crossed after about cap / 4 KiB pieces, long before the end.
        for ($i = 0; $i < 2000; ++$i) {
            echo str_repeat('x', 4096);
            flush();
            usleep(10000);
        }
        break;
    default:
        http_response_code(500);
}
