<?php

namespace Northrook\Filesystem\Exception;

use Throwable;

final class FileNotFoundException extends FileSystemException
{
    public function __construct(
        public readonly ?string $path,
        ?string                 $message = null,
        int                     $code = 500,
        ?Throwable              $previous = null,
    ) {
        $message ??= 'File not found'.( $path ? ": {$path}." : '.' );

        parent::__construct( $message, $path, $code, $previous );
    }
}
