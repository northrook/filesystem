<?php

namespace Northrook\Filesystem\Exception;

use RuntimeException;
use Throwable;

class FilesystemException extends RuntimeException
{
    public function __construct(
        string                  $message,
        public readonly ?string $path = null,
        int                     $code = 500,
        ?Throwable              $previous = null,
    ) {
        parent::__construct( $message, $code, $previous );
    }
}
