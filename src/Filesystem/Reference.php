<?php

declare(strict_types=1);

namespace Northrook\Filesystem;

use Northrook\Filesystem;
use Stringable;
use function Assert\isUrl;

/**
 * @internal
 */
abstract class Reference implements Stringable
{
    private static Filesystem $filesystem;

    /** @var non-empty-string|string */
    protected string $path;

    /** @var null|resource|string */
    protected mixed $content;

    protected ?bool $exists = null;

    final public function __toString() : string
    {
        return $this->path;
    }

    public function exists() : bool
    {
        return $this->exists = $this::filesystem()->exists( $this->path );
    }

    /**
     * @param non-empty-string $path
     *
     * @return self
     */
    final public static function from( string $path ) : self
    {
        return isUrl( $path ) ? new URL( $path ) : new Path( $path );
    }

    final protected function filesystem() : Filesystem
    {
        return $this::$filesystem ??= new Filesystem();
    }
}
