<?php

declare(strict_types=1);

namespace Watcher\Config;

/** Thrown for any invalid setting. The message names the key path and never a value. */
class InvalidConfig extends \InvalidArgumentException
{
}
