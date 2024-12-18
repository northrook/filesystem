<?php

declare(strict_types=1);

namespace Northrook\Filesystem;

use LogicException;
use Northrook\Filesystem;
use Northrook\Logger\Log;
use Support\Num;
use Symfony\Component\Filesystem\Exception\IOException;
use function Assert\isUrl;

final class File
{
    private static Filesystem $filesystem;

    final protected function __construct()
    {
        throw new LogicException( $this::class.' is using the `StaticClass` trait, and should not be instantiated directly.');
    }

    final protected function __clone()
    {
        throw new LogicException( $this::class.' is using the `StaticClass` trait, and should not be cloned.' );
    }

    /**
     * Checks the existence of files or directories.
     *
     * @param string ...$path The files to check
     *
     * @return bool
     */
    public static function exists( string ...$path ) : bool
    {
        try {
            return File::filesystem()->exists( ...$path );
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }
        return false;
    }

    /**
     * Checks the provided paths are directories.
     *
     * @param string ...$path The paths to check
     *
     * @return bool
     */
    public static function isDir( string ...$path ) : bool
    {
        foreach ( $path as $file ) {
            if ( ! \is_dir( $file ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks the provided paths are files.
     *
     * @param string ...$path The paths to check
     *
     * @return bool
     */
    public static function isFile( string ...$path ) : bool
    {
        foreach ( $path as $file ) {
            if ( ! \is_file( $file ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks the provided paths can be read.
     *
     * @param string ...$path The files to check
     *
     * @return bool
     */
    public static function isReadable( string ...$path ) : bool
    {
        foreach ( $path as $file ) {
            if ( ! \is_readable( $file ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if files or directories can be written to.
     *
     * @param string ...$path The files to check
     *
     * @return bool
     */
    public static function isWritable( string ...$path ) : bool
    {
        foreach ( $path as $file ) {
            if ( ! \is_writable( $file ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks the provided paths are URL strings.
     *
     *  - Does not validate the response.
     *
     * @param string ...$path The paths to check
     *
     * @return bool
     */
    public static function isFilePath( string ...$path ) : bool
    {
        foreach ( $path as $file ) {
            if ( isUrl( $file ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sets access and modification time of file.
     *
     * @param string|string[] $files The files to touch
     * @param ?int            $time  The touch time as a Unix timestamp, if not supplied the current system time is used
     * @param ?int            $atime The access time as a Unix timestamp, if not supplied the current system time is used
     *
     * @return bool
     */
    public static function touch( string|array $files, ?int $time = null, ?int $atime = null ) : bool
    {
        try {
            File::filesystem()->touch( $files, $time, $atime );
            return true;
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }
        return false;
    }

    /**
     * Reads the contents of a file.
     *
     * - {@see IOException} will be caught and logged as an error, returning `null`
     *
     * @param string $path The path to the file
     *
     * @return ?string Returns the contents of the file, or null if an {@see IOException} was thrown
     */
    public static function read( string $path ) : ?string
    {
        try {
            return File::filesystem()->readFile( $path );
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }
        return null;
    }

    /**
     * Atomically dumps content into a file.
     *
     * - {@see IOException} will be caught and logged as an error, returning false
     *
     * @param string          $path    The path to the file
     * @param resource|string $content The data to write into the file
     *
     * @return bool True if the file was written to, false if it already existed or an error occurred
     */
    public static function save( string $path, mixed $content ) : bool
    {
        try {
            File::filesystem()->dumpFile( $path, $content );
            return true;
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }

        return false;
    }

    /**
     * Copies {@see $originFile} to {@see $targetFile}.
     *
     * - If the target file is automatically overwritten when this file is newer.
     * - If the target is newer, $overwriteNewerFiles decides whether to overwrite.
     * - {@see IOException}s will be caught and logged as an error, returning false
     *
     * @param string $originFile
     * @param string $targetFile
     * @param bool   $overwriteNewerFiles
     *
     * @return bool True if the file was written to, false if it already existed or an error occurred
     */
    public static function copy( string $originFile, string $targetFile, bool $overwriteNewerFiles = false ) : bool
    {
        try {
            File::filesystem()->copy( $originFile, $targetFile, $overwriteNewerFiles );
            return true;
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }

        return false;
    }

    /**
     * Renames a file or a directory.
     *
     * @param string $origin
     * @param string $target
     * @param bool   $overwrite
     *
     * @return bool
     */
    public static function rename( string $origin, string $target, bool $overwrite = false ) : bool
    {
        try {
            File::filesystem()->rename( $origin, $target, $overwrite );
            return true;
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }
        return false;
    }

    /**
     * Creates a directory recursively.
     *
     * @param string|string[] $dirs
     * @param int             $mode
     * @param bool            $returnPath
     *
     * @return bool|string|string[]
     */
    public static function mkdir(
        string|array $dirs,
        int          $mode = 0777,
        bool         $returnPath = true,
    ) : bool|string|array {
        try {
            File::filesystem()->mkdir( $dirs, $mode );
            return $returnPath ? $dirs : true;
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }
        return false;
    }

    /**
     * Removes files or directories.
     *
     * @param string|string[] $files
     *
     * @return bool
     */
    public static function remove( string|array $files ) : bool
    {
        try {
            File::filesystem()->remove( $files );
            return true;
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }
        return false;
    }

    public static function getMimeType( string $path ) : ?string
    {
        return File::filesystem()->getMimeType( $path );
    }

    /**
     * Get the file size of a given file.
     *
     * @param int|string $bytes Provide a path to a file or a file size in bytes
     *
     * @return null|string
     */
    public static function size( string|int $bytes ) : ?string
    {
        $bytes = \is_string( $bytes ) ? File::filesystem()->size( $bytes ) : $bytes;

        if ( null === $bytes ) {
            return null;
        }

        return Num::byteSize( $bytes );
    }

    final protected static function filesystem() : Filesystem
    {
        return File::$filesystem ??= new Filesystem();
    }
}
