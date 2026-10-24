<?php

declare(strict_types=1);

namespace Watcher\State;

use RuntimeException;

/** A value that the reader would refuse (over StateCodec's size or structure budget): refused at write instead of silently lost on read. */
final class StateValueTooLarge extends RuntimeException
{
}
