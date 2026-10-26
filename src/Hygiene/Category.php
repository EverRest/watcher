<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/** The closed set of personal-data categories a report may name (data-model.md). */
enum Category: string
{
    case Email = 'email';
    case Token = 'token';
    case Address = 'address';
    case Phone = 'phone';
    case Card = 'card';
    case Host = 'host';
    case Other = 'other';
}
