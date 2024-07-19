<?php

namespace Northrook\Filesystem;

use Northrook\Core\Trait\PropertyAccessor;
use Northrook\Filesystem;
use Northrook\Logger\Log;
use Stringable;
use Symfony\Component\Filesystem\Exception\IOException;
use function count, floor, min, log, round;
use function Northrook\{normalizePath, isUrl};

/**
 * @template PathString as string
 * @template UnixTimestamp as int
 * @template Bytes as int
 *
 * @property-read  string  $path
 * @property-read  string  $basename
 * @property-read  string  $filename
 * @property-read  string  $extension
 * @property-read  string  $mimeType
 * @property-read  string  $size
 * @property-read  ?string $read
 *
 * @property-read  bool    $isValid
 * @property-read  bool    $exists
 * @property-read  bool    $isDir
 * @property-read  bool    $isFile
 * @property-read  bool    $isUrl
 * @property-read  bool    $isWritable
 * @property-read  bool    $isReadable
 * @property-read  int     $lastModified
 */
class File implements Stringable
{
    use PropertyAccessor;

    private static Filesystem $filesystem;
    private static array      $readCache = [];
    private string            $path;

    protected ?bool $valid = null;

    /**
     * @param string[]  $path
     */
    public function __construct(
        string | array $path,
    ) {
        $this->path = normalizePath( $path );
    }

    public function __get( string $property ) : string | bool | int | null {
        $result = match ( $property ) {
            'isValid'      => $this->valid ??= $this::exists( $this->path ),
            'path'         => $this->path,
            'mimeType'     => $this->filesystem()->getMimeType( $this->path ),
            'basename'     => pathinfo( $this->path, PATHINFO_BASENAME ),
            'filename'     => pathinfo( $this->path, PATHINFO_FILENAME ),
            'extension'    => pathinfo( $this->path, PATHINFO_EXTENSION ),
            'exists'       => $this::exists( $this->path ),
            'isDir'        => $this::isDir( $this->path ),
            'isFile'       => $this::isFile( $this->path ),
            'isUrl'        => $this::isFilePath( $this->path ),
            'isWritable'   => $this::isWritable( $this->path ),
            'isReadable'   => $this::isReadable( $this->path ),
            'size'         => $this::size( $this->path ),
            'read'         => $this::read( $this->path ),
            'lastModified' => @filemtime( $this->path ) ?: null,
        };

        $this->valid = (bool) $result;

        return $result;
    }

    public function __toString() : string {
        return $this->path;
    }

    //<editor-fold desc="Checks">

    /**
     * Checks the existence of files or directories.
     *
     * @param string<PathString>|iterable  $path  The files to check
     *
     * @return bool
     */
    public static function exists( string | iterable $path ) : bool {
        try {
            return File::filesystem()->exists( $path );
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }
        return false;
    }

    /**
     * Checks the provided paths are directories.
     *
     * @param string<PathString>|iterable  $path  The paths to check
     *
     * @return bool
     */
    public static function isDir( string | iterable $path ) : bool {

        foreach ( ( array ) $path as $file ) {
            if ( !is_dir( $file ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks the provided paths are files.
     *
     * @param string<PathString>|iterable  $path  The paths to check
     *
     * @return bool
     */
    public static function isFile( string | iterable $path ) : bool {

        foreach ( ( array ) $path as $file ) {
            if ( !is_file( $file ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks the provided paths can be read.
     *
     * @param string<PathString>|iterable  $path  The files to check
     *
     * @return bool
     */
    public static function isReadable( string | iterable $path ) : bool {

        foreach ( ( array ) $path as $file ) {
            if ( !is_readable( $file ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if files or directories can be written to.
     *
     * @param string<PathString>|iterable  $path  The files to check
     *
     * @return bool
     */
    public static function isWritable( string | iterable $path ) : bool {

        foreach ( ( array ) $path as $file ) {
            if ( !is_writable( $file ) ) {
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
     * @param string<PathString>|iterable  $path  The paths to check
     *
     * @return bool
     */
    public static function isFilePath( string | iterable $path ) : bool {

        foreach ( ( array ) $path as $file ) {
            if ( isUrl( $file ) ) {
                return false;
            }
        }

        return true;
    }


    //</editor-fold>
    //<editor-fold desc="Static">

    /**
     * Sets access and modification time of file.
     *
     * @param string<PathString>|iterable  $files  The files to touch
     * @param ?int<UnixTimestamp>          $time   The touch time as a Unix timestamp, if not supplied the current system time is used
     * @param ?int<UnixTimestamp>          $atime  The access time as a Unix timestamp, if not supplied the current system time is used
     *
     * @return bool
     */
    public static function touch( string | iterable $files, ?int $time = null, ?int $atime = null ) : bool {
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
     * @param string<PathString>  $path  The path to the file
     *
     * @return ?string Returns the contents of the file, or null if an {@see IOException} was thrown
     *
     */
    public static function read( string $path ) : ?string {
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
     * @param string<PathString>  $path     The path to the file
     * @param string|resource     $content  The data to write into the file
     *
     * @return bool  True if the file was written to, false if it already existed or an error occurred
     *
     *
     */
    public static function save( string $path, mixed $content ) : bool {
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
     * @param string<PathString>  $originFile
     * @param string<PathString>  $targetFile
     * @param bool                $overwriteNewerFiles
     *
     * @return bool  True if the file was written to, false if it already existed or an error occurred
     */
    public static function copy( string $originFile, string $targetFile, bool $overwriteNewerFiles = false ) : bool {
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
     */
    public static function rename( string $origin, string $target, bool $overwrite = false ) : bool {
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
     */
    public static function mkdir(
        string | iterable $dirs,
        int               $mode = 0777,
        bool              $returnPath = true,
    ) : bool | string | array {
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
     */
    public static function remove( string | iterable $files ) : bool {
        try {
            File::filesystem()->remove( $files );
            return true;
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }
        return false;
    }

    //</editor-fold>
    //<editor-fold desc="Actions">


    /**
     * Copies this {@see File} to given {@see $path}.
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
    public function copyTo( string $path, bool $overwriteNewerFiles = false ) : bool {
        return File::copy( $this->path, $path, $overwriteNewerFiles );
    }


    /**
     * Rename this {@see File} to given {@see $newName}.
     */
    public function renameTo( string $newName, bool $overwrite = false ) : bool {
        try {
            return File::rename( $this->path, $newName, $overwrite );
        }
        catch ( IOException $exception ) {
            Log::exception( $exception );
        }
        return false;
    }

    /**
     * Remove this {@see File}.
     */
    public function delete() : bool {
        return File::remove( $this->path );
    }

    //</editor-fold>

    /**
     * Get the file size of a given file.
     *
     * @param string<PathString>|int<Bytes>  $bytes  Provide a path to a file or a file size in bytes
     *
     * @return string
     */
    public static function size( string | int $bytes ) : string {

        if ( is_string( $bytes ) ) {
            if ( !file_exists( $bytes ) ) {
                Log::Error( '{path} does not exist.', [ 'path' => $bytes, ] );
                return 'Unknown';
            }
            $bytes = filesize( $bytes );
        }
        $unitDecimalsByFactor = [
            [ 'B', 0 ],
            [ 'kB', 0 ],
            [ 'MB', 2 ],
            [ 'GB', 2 ],
            [ 'TB', 3 ],
            [ 'PB', 3 ],
        ];

        $factor = $bytes ? floor( log( (int) $bytes, 1024 ) ) : 0;
        $factor = min( $factor, count( $unitDecimalsByFactor ) - 1 );

        $value = round( $bytes / ( 1024 ** $factor ), $unitDecimalsByFactor[ $factor ][ 1 ] );
        $units = $unitDecimalsByFactor[ $factor ][ 0 ];

        return $value . $units;
    }

    /// ----------------------------------------------------------


    final protected static function filesystem() : Filesystem {
        return File::$filesystem ??= new Filesystem();
    }
}