<?php

declare( strict_types = 1 );

namespace Northrook\Filesystem;

use Northrook\Core\Trait\PropertyAccessor;
use Northrook\Filesystem;
use Northrook\Resource\Path;
use Northrook\Resource\URL;
use Stringable;
use function Northrook\isUrl;

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

    public static function from( string $string ) : URL | Path {
        return isUrl( $string ) ? new URL( $string ) : new Path( $string );
    }

    final protected function filesystem() : Filesystem {
        return $this::$filesystem ??= new Filesystem();
    }
}