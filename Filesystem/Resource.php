<?php

declare( strict_types = 1 );

namespace Northrook\Filesystem;

use Northrook\Core\Trait\PropertyAccessor;
use Northrook\Filesystem;
use Stringable;

/**
 * @template UrlString as string
 * @template PathString as string
 * @template UnixTimestamp as int
 * @template Bytes as int
 */
abstract class Resource implements Stringable
{
    use PropertyAccessor;

    private static Filesystem $filesystem;
    private static array      $readCache = [];

    protected string $path;
    protected mixed  $content;
    protected ?bool  $exists = null;


    final public function __toString() : string {
        return $this->path;
    }

    final protected function filesystem() : Filesystem {
        return $this::$filesystem ??= new Filesystem();
    }
}