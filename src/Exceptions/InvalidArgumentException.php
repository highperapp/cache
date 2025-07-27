<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Exceptions;

use InvalidArgumentException as BaseInvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Invalid argument exception for cache operations
 */
class InvalidArgumentException extends BaseInvalidArgumentException implements PsrInvalidArgumentException
{
    //
}