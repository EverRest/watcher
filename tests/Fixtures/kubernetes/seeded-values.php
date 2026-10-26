<?php

declare(strict_types=1);

/**
 * Values seeded into the Kubernetes fixtures that must never reach a model request, a notifier message, a state write,
 * the JSON summary or a log (spec 008, privacy corpus). Pod suffixes are listed separately: they must collapse.
 */
return [
    'values' => [
        'registry.corp.example',
        'payments/api',
        '2.4.1-rc',
        'sk-live-9f8e7d6c5b4a3921abcdef01',
        'hunter2-prod',
        'ip-10-0-4-17.eu-west-1.compute.internal',
        'ip-10-0-4-22.eu-west-1.compute.internal',
        '10.42.3.17',
        'db-credentials-prod',
        'olena.shevchenko',
        'olena.shevchenko@example.com',
        'validate.policy.example.com',
        'checkout-eu',
        'payments-olena',
        'PAY-4411',
        'ENCODED-CONTINUE-TOKEN',
        'c0ffee12c0ffee12',
        '9b1deb4d-3b7d-4bad-9bdd',
        'ab12ab12ab12ab12',
    ],
    'podSuffixes' => ['7d9f8c6b5', 'x2k4q', 'm8v2p', 'h5t7n', 'c9w3z', 'j4b6d', 'r2f8s', 'k7g1x', 'q3y5l', '5c8d9f7b4', 't6n2v', '6f5d7c9b8', 'z9k3w'],
    'tokenAndCredentials' => ['BEARER-TEST-TOKEN-0123456789'],
];
