<?php

namespace Northrook\Filesystem\Exception;

use RuntimeException;
use Throwable;

class LinkException extends RuntimeException
{
    public function __construct(
        string                  $message,
        public readonly string $origin,
        public readonly string $target,
        int                     $code = 500,
        ?Throwable              $previous = null,
    ) {
        parent::__construct( $message, $code, $previous );
    }
}
