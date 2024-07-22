<?php

declare( strict_types = 1 );

namespace Northrook\Resource;

use Northrook\Filesystem\{File, Resource};
use Symfony\Component\Filesystem\Exception\IOException;
use function filesize, filemtime;
use function Northrook\{normalizePath, numberByteSize};

/**
 * @template PathString as string
 * @template UnixTimestamp as int
 *
 *
 * @property-read  string  $path
 * @property-read  bool    $exists
 * @property-read  string  $mimeType
 * @property-read  ?string $basename
 * @property-read  ?string $filename
 * @property-read  ?string $extension
 *
 * @property-read  bool    $isDir
 * @property-read  bool    $isFile
 * @property-read  bool    $isWritable
 * @property-read  bool    $isReadable
 * @property-read  ?string $read
 * @property-read  ?string $size
 * @property-read  int     $lastModified
 */
class Path extends Resource
{
    private array $pathInfo;

    /**
     * @param string<PathString>  $path
     */
    public function __construct(
        protected string $path,
    ) {
        $this->path = normalizePath( $path );
    }

    public function __get( string $property ) {
        return match ( $property ) {
            'path'         => $this->path,
            'exists'       => $this->exists ??= File::exists( $this->path ),
            'mimeType'     => File::getMimeType( $this->path ),
            'size'         => $this->getPathSize(),
            'lastModified' => @filemtime( $this->path ) ?: null,

            'basename'     => $this->getPathInfo( 'basename' ),
            'filename'     => $this->getPathInfo( 'filename' ),
            'extension'    => $this->getPathInfo( 'extension' ),

            'isDir'        => File::isDir( $this->path ),
            'isFile'       => File::isFile( $this->path ),
            'isWritable'   => File::isWritable( $this->path ),
            'isReadable'   => File::isReadable( $this->path ),

            'read'         => $this->content ??= File::read( $this->path ),
        };
    }

    /**
     * Sets access and modification time of file.
     *
     * @param ?int<UnixTimestamp>  $time   The touch time as a Unix timestamp, if not supplied the current system time is used
     * @param ?int<UnixTimestamp>  $atime  The access time as a Unix timestamp, if not supplied the current system time is used
     *
     * @return bool
     */
    public function touch( ?int $time = null, ?int $atime = null ) : bool {
        return File::touch( $this->path, $time, $atime );
    }

    /**
     * Atomically dumps content into a file.
     *
     * - {@see IOException} will be caught and logged as an error, returning false
     *
     * @param string|resource  $content  The data to write into the file
     *
     * @return bool  True if the file was written to, false if it already existed or an error occurred
     */
    public function save( mixed $content ) : bool {
        return File::save( $this->path, $this->content = $content );
    }

    /**
     * Copies this {@see File} to given {@see Path}.
     *
     * - If the target file is automatically overwritten when this file is newer.
     * - If the target is newer, $overwriteNewerFiles decides whether to overwrite.
     * - {@see IOException}s will be caught and logged as an error, returning false
     *
     * @param string<PathString>  $path
     * @param bool                $overwriteNewerFiles
     *
     * @return bool  True if the file was written to, false if it already existed or an error occurred
     */
    public function copy( string $path, bool $overwriteNewerFiles = false ) : bool {
        return File::copy( $this->path, $path, $overwriteNewerFiles );
    }

    /**
     * Rename this {@see Path}.
     */
    public function rename( string $string, bool $overwrite = false ) : bool {
        return File::rename( $this->path, $string, $overwrite );
    }

    /**
     * Remove this {@see Path}.
     */
    public function delete() : bool {
        return File::remove( $this->path );
    }

    final protected function getPathSize() : ?string {
        return File::size( $this->path );
    }

    final protected function getPathInfo( ?string $get = null ) : array | string | null {
        $this->pathInfo ??= pathinfo( $this->path );
        return $get ? $this->pathInfo[ $get ] ?? null : $this->pathInfo;
    }


}