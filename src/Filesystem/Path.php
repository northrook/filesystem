<?php

declare(strict_types=1);

namespace Northrook\Filesystem;

use Northrook\Logger\Log;
use Support\Normalize;
use Symfony\Component\Filesystem\Exception\IOException;
use UnexpectedValueException;

/**
 * @property string  $mimeType
 * @property ?string $basename
 * @property ?string $filename
 * @property ?string $extension Retrieve the path extension. Will return 'dir' for directories.
 *
 * @property bool    $isDir
 * @property bool    $isFile
 * @property bool    $isWritable
 * @property bool    $isReadable
 * @property ?string $read
 * @property ?string $size
 * @property int     $lastModified
 */
final class Path extends Reference
{
    /** @var array{dirname: ?string, basename: ?string, extension: ?string, filename: ?string} */
    private array $pathInfo;

    /**
     * @param non-empty-string|non-empty-string[]|Path $path
     */
    public function __construct( Path|string|array $path )
    {
        $this->path = Normalize::path( $path instanceof Path ? $path->path : $path );
    }

    public function __get( string $property ) : string|bool|int|null
    {
        return match ( $property ) {
            'path'         => $this->path,
            'exists'       => $this->exists(),
            'mimeType'     => $this::filesystem()->getMimeType( $this->path ),
            'size'         => $this->getPathSize(),
            'lastModified' => @\filemtime( $this->path ) ?: null,

            'basename'  => $this->getPathInfo( 'basename' ),
            'filename'  => $this->getPathInfo( 'filename' ),
            'extension' => $this->getPathInfo( 'extension' )
                           ?? ( \is_dir( $this->path ) ? 'dir' : null ),

            'isDir'      => $this->exists = File::isDir( $this->path ),
            'isFile'     => $this->exists = File::isFile( $this->path ),
            'isWritable' => $this->exists = File::isWritable( $this->path ),
            'isReadable' => $this->exists = File::isReadable( $this->path ),
            default      => throw new UnexpectedValueException( __METHOD__.' Unknown property: '.$property ),
        };
    }

    public function read( bool $refresh = false ) : mixed
    {
        if ( $this->content && ! $refresh ) {
            return $this->content;
        }

        try {
            $this->content = $this::filesystem()->readFile( $this->path );
            $this->exists  = true;
        }
        catch ( IOException $exception ) {
            $this->content = null;
            $this->exists  = false;
            Log::exception( $exception );
        }
        return $this->content;
    }

    /**
     * @param non-empty-string ...$path
     *
     * @return $this
     */
    public function append( string ...$path ) : Path
    {
        $this->path = Normalize::path( [$this->path, ...$path] );
        return $this;
    }

    /**
     * Sets access and modification time of file.
     *
     * @param ?int $time  The touch time as a Unix timestamp, if not supplied the current system time is used
     * @param ?int $atime The access time as a Unix timestamp, if not supplied the current system time is used
     *
     * @return bool
     */
    public function touch( ?int $time = null, ?int $atime = null ) : bool
    {
        return File::touch( $this->path, $time, $atime );
    }

    /**
     * Atomically dumps content into a file.
     *
     * - {@see IOException} will be caught and logged as an error, returning false
     *
     * @param resource|string $content The data to write into the file
     *
     * @return bool True if the file was written to, false if it already existed or an error occurred
     */
    public function save( mixed $content ) : bool
    {
        return File::save( $this->path, $content );
    }

    /**
     * Copies this {@see File} to the provided {@see $path}.
     *
     * - If the target file is automatically overwritten when this file is newer.
     * - If the target is newer, $overwriteNewerFiles decides whether to overwrite.
     * - {@see IOException}s will be caught and logged as an error, returning false
     *
     * @param string $path
     * @param bool   $overwriteNewerFiles
     *
     * @return bool True if the file was written to, false if it already existed or an error occurred
     */
    public function copy( string $path, bool $overwriteNewerFiles = false ) : bool
    {
        return File::copy( $this->path, $path, $overwriteNewerFiles );
    }

    /**
     * Rename this {@see Path}.
     *
     * @param string $string
     * @param bool   $overwrite
     *
     * @return bool
     */
    public function rename( string $string, bool $overwrite = false ) : bool
    {
        return File::rename( $this->path, $string, $overwrite );
    }

    /**
     * Remove this {@see Path}.
     */
    public function remove() : bool
    {
        return File::remove( $this->path );
    }

    /**
     * @return null|string
     */
    final protected function getPathSize() : ?string
    {
        return File::size( $this->path );
    }

    /**
     * @param null|string $get
     *
     * @return ($get is string ? null|string : array{dirname: ?string, basename: ?string, extension: ?string, filename: ?string})
     */
    final protected function getPathInfo( ?string $get = null ) : array|string|null
    {
        $this->pathInfo ??= (array) \array_merge( ['dirname' => null, 'basename' => null, 'extension' => null, 'filename' => null], \pathinfo( $this->path ) );
        return $get ? $this->pathInfo[$get] ?? null : $this->pathInfo;
    }
}
