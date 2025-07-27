<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Exceptions;

use Exception;
use Psr\SimpleCache\CacheException as PsrCacheException;

/**
 * Base cache exception
 */
class CacheException extends Exception implements PsrCacheException
{
    //
}