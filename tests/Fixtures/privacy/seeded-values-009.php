<?php

declare(strict_types=1);

/** Personal values seeded in tests/Fixtures/privacy/{metrics-labels,sentry-issues}.json and trace ids (spec 009). @return list<string> */
return [
    'customer-4711',
    'anna.kowalski@example.org',
    '203.0.113.42',
    'Anna Kowalski',
    '4111 1111 1111 1111',
    'sk_demo_51HxQ2eKzP9vWb3nTq7LmZ4Yc',
    'clicked pay',
    '4bf92f3577b34da6a3ce929d0e0e4736', // trace id: allowed only inside a link URL
];
