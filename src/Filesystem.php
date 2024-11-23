<?php

declare(strict_types=1);

namespace Northrook;

use Northrook\Filesystem\Exception\{FileNotFoundException, FilesystemException, LinkException};
use InvalidArgumentException;
use Support\Arr;
use TypeError;
use const FILE_APPEND;
use const LOCK_EX;
use FilesystemIterator;
use Traversable;
use const DIRECTORY_SEPARATOR;
use Exception;
use LogicException;
use const PHP_MAXPATHLEN;
use const PHP_URL_SCHEME;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use const PHP_URL_HOST;
use Throwable;
use SplFileInfo;

function temp_name( string $file ) : string
{
    $path = \realpath( $file ) ?: $file;
    try {
        $seed = \base64_encode( \random_bytes( 2 ) );
    }
    catch ( Exception $e ) {
        throw new LogicException( 'Failed to generate random string: '.$e->getMessage(), 500, $e );
    }

    return \dirname( $path ).'/.!'.\strrev( \strtr( $seed, '/=', '-!' ) );
}

final class Filesystem
{
    /**
     * Mimetypes for simple .extension lookup.
     * @see           https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Common_types
     * @formatter:off
     */
    private const array MIME_TYPES = [
        // Text and XML
        'text/plain'      => 'txt',
        'text/html'       => ['htm', 'html'],
        'text/css'        => 'css',
        'text/javascript' => 'js',

        // Documents & Languages
        'application/rtf'         => 'rtf',
        'application/msword'      => 'doc',
        'application/pdf'         => 'pdf',
        'application/postscript'  => 'eps',
        'application/x-httpd-php' => 'php',

        // Data sources
        'text/csv'                 => 'csv',
        'application/json'         => 'json',
        'application/ld+json'      => 'jsonld',
        'application/vnd.ms-excel' => 'xls',
        'application/xml'          => 'xml',

        // Images and vector graphics
        'image/png'     => ['png', 'apng'],
        'image/jpeg'    => ['jpg', 'jpeg', 'jpe'],
        'image/gif'     => 'gif',
        'image/bmp'     => 'bmp',
        'image/x-icon'  => 'ico',
        'image/tiff'    => ['tif', 'tiff'],
        'image/svg+xml' => ['svg', 'svgz'],
        'image/webp'    => 'webp',
        'video/webm'    => 'webm',

        // archives
        'application/x-7z-compressed'       => '7z',
        'application/zip'                   => 'zip',
        'application/x-rar-compressed'      => 'rar',
        'application/x-msdownload'          => ['exe', 'msi'],
        'application/vnd.ms-cab-compressed' => 'cab',
        'application/x-tar'                 => 'tar',

        // audio/video
        'audio/mp3'       => ['mp3', 'mpga'],
        'audio/flac'      => ['flac'],
        'video/quicktime' => ['mov', 'qt'],

        // Fonts
        'font/ttf'                      => 'ttf',
        'font/otf'                      => 'otf',
        'font/woff'                     => 'woff',
        'font/woff2'                    => 'woff2',
        'application/vnd.ms-fontobject' => 'eot',
    ];

    private static ?string $lastError = null;

    // :: ACTIONS ::::::

    // :: READ

    /**
     * Resolves links in paths.
     *
     * With $canonicalize = false (default)
     *      - if $path does not exist or is not a link, returns null
     *      - if $path is a link, returns the next direct target of the link without considering the existence of the target
     *
     * With $canonicalize = true
     *      - if $path does not exist, returns null
     *      - if $path exists, returns its absolute fully resolved final version
     * @param  string      $path
     * @param  bool        $canonicalize
     * @return null|string
     */
    public function readlink( string $path, bool $canonicalize = false ) : ?string
    {
        if ( ! $canonicalize && ! \is_link( $path ) ) {
            return null;
        }

        if ( $canonicalize ) {
            if ( ! $this->exists( $path ) ) {
                return null;
            }

            return \realpath( $path ) ?: null;
        }

        return \readlink( $path ) ?: null;
    }

    /**
     * Appends content to an existing file.
     *
     * @param string          $filename
     * @param resource|string $content  The content to append
     * @param bool            $lock     Whether the file should be locked when writing to it
     *
     * @throws FilesystemException If the file is not writable
     */
    public function appendToFile( string $filename, mixed $content, bool $lock = false ) : void
    {
        if ( ! ( \is_resource( $content ) || \is_string( $content ) ) ) {
            throw new TypeError( 'The $content argument passed to '.__METHOD__.'() must be a sttring or resource, '.\gettype( $content ).' given.' );
        }

        $dir = \dirname( $filename );

        if ( ! \is_dir( $dir ) ) {
            $this->mkdir( $dir );
        }

        if ( false === self::box( 'file_put_contents', $filename, $content, FILE_APPEND | ( $lock ? LOCK_EX : 0 ) ) ) {
            throw new FilesystemException( "Failed to write file '{$filename}': ".self::$lastError, $filename );
        }
    }

    /**
     * Returns the content of a file as a string.
     *
     * @param  string $filename
     * @return string
     */
    public function readFile( string $filename ) : string
    {
        if ( \is_dir( $filename ) ) {
            throw new FilesystemException( "Failed to read file '{$filename}'; the path points to a directory.", $filename );
        }

        $content = self::box( '\file_get_contents', $filename );
        if ( false === $content ) {
            throw new FilesystemException( "Failed to read file '{$filename}':".self::$lastError, $filename );
        }

        return $content;
    }

    // :: CHECK

    /**
     * Checks the existence of files or directories.
     * @param  string ...$files
     * @return bool
     */
    public function exists( string ...$files ) : bool
    {

        foreach ( $this->toIterable( $files ) as $file ) {

            $this::guardMaxLength( $file );

            if ( ! \file_exists( $file ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $path
     *
     * @return null|int
     *
     * @throws FilesystemException if the file size can't be determined
     */
    public function size( string $path ) : ?int
    {
        try {
            return \filesize( $path ) ?: null;
        }
        catch ( Throwable $exception ) {
            throw new FilesystemException( 'Could not determine file size for provided path: '.$exception->getMessage(), $path );
        }
    }

    /**
     * @param string $path
     *
     * @return string
     *
     * @throws FilesystemException if the file size can't be determined
     */
    public function mimeType( string $path ) : string
    {
        $ext = \pathinfo( $path, PATHINFO_EXTENSION );

        $mimeType = Arr::search( $this::MIME_TYPES, $ext ) ?: null;

        if ( \is_string( $mimeType ) ) {
            return $mimeType;
        }

        throw new FilesystemException( 'Could not determine mime type for provided path: '.$path, $path );
    }

    // :: CREATE

    /**
     * Atomically dumps content into a file.
     *
     * @param string          $filename
     * @param resource|string $content  The data to write into the file
     *
     * @throws FilesystemException if the file cannot be written to
     */
    public function dumpFile( string $filename, mixed $content ) : void
    {
        $this::isResource( $content, __METHOD__, '$content' );

        $dir = \dirname( $filename );

        if ( \is_link( $filename ) && $linkTarget = $this->readlink( $filename ) ) {
            $this->dumpFile( $this::makePathAbsolute( $linkTarget, $dir ), $content );

            return;
        }

        if ( ! \is_dir( $dir ) ) {
            $this->mkdir( $dir );
        }

        // Will create a temp file with 0600 access rights
        // when the filesystem supports chmod.
        $tmpFile = $this->tempnam( $dir, \basename( $filename ) );

        try {
            if ( false === self::box( 'file_put_contents', $tmpFile, $content ) ) {
                throw new FilesystemException( "Failed to write file '{$tmpFile}': ".self::$lastError, $filename );
            }

            self::box( 'chmod', $tmpFile, self::box( 'fileperms', $filename ) ?: 0666 & ~\umask() );

            $this->rename( $tmpFile, $filename, true );
        }
        finally {
            if ( \file_exists( $tmpFile ) ) {
                if ( '\\' === DIRECTORY_SEPARATOR && ! \is_writable( $tmpFile ) ) {
                    $perms = self::box( 'fileperms', $tmpFile );
                    \assert( \is_int( $perms ) );
                    self::box( 'chmod', $tmpFile, $perms | 0200 );
                }

                self::box( 'unlink', $tmpFile );
            }
        }
    }

    /**
     * Creates a directory recursively.
     *
     * @throws FilesystemException On any directory creation failure
     * @param  string|string[]     $dirs
     * @param  int                 $mode
     */
    public function mkdir( string|array $dirs, int $mode = 0777 ) : void
    {
        foreach ( $this->toIterable( $dirs ) as $dir ) {
            if ( \is_dir( $dir ) ) {
                continue;
            }

            if ( ! self::box( 'mkdir', $dir, $mode, true ) && ! \is_dir( $dir ) ) {
                throw new FilesystemException( "Failed to create directory '{$dir}': ".self::$lastError, $dir );
            }
        }
    }

    /**
     * Creates a temporary file with support for custom stream wrappers.
     *
     * @param string $dir
     * @param string $prefix The prefix of the generated temporary filename
     *                       Note: Windows uses only the first three characters of prefix
     * @param string $suffix The suffix of the generated temporary filename
     *
     * @return string The new temporary filename (with path), or throw an exception on failure
     */
    public function tempnam( string $dir, string $prefix, string $suffix = '' ) : string
    {
        [$scheme, $hierarchy] = $this->getSchemeAndHierarchy( $dir );

        // If no scheme or scheme is "file" or "gs" (Google Cloud) create temp file in local filesystem
        if ( ( null === $scheme || 'file' === $scheme || 'gs' === $scheme ) && '' === $suffix ) {
            // If tempnam failed or no scheme return the filename otherwise prepend the scheme
            if ( $tmpFile = self::box( 'tempnam', $hierarchy, $prefix ) ) {
                \assert( \is_string( $tmpFile ) );
                if ( null !== $scheme && 'gs' !== $scheme ) {
                    return $scheme.'://'.$tmpFile;
                }

                return $tmpFile;
            }

            throw new FilesystemException( 'A temporary file could not be created '.self::$lastError );
        }

        // Loop until we create a valid temp file or have reached 10 attempts
        for ( $i = 0; $i < 10; $i++ ) {
            // Create a unique filename
            $tmpFile = $dir.'/'.$prefix.\uniqid( (string) \mt_rand(), true ).$suffix;

            // Use fopen instead of file_exists as some streams do not support stat
            // Use mode 'x+' to atomically check existence and create to avoid a "time-of-check to time-of-use" vulnerability
            if ( ! $handle = self::box( 'fopen', $tmpFile, 'x+' ) ) {
                continue;
            }

            // Close the file if it was successfully opened
            self::box( 'fclose', $handle );

            return $tmpFile;
        }

        throw new FilesystemException( 'A temporary file could not be created '.self::$lastError );
    }

    // :: REMOVE

    /**
     * Removes files or directories.
     *
     * @param iterable|string $files
     */
    public function remove( string|iterable $files ) : void
    {
        if ( $files instanceof Traversable ) {
            $files = \iterator_to_array( $files, false );
        }
        elseif ( ! \is_array( $files ) ) {
            $files = [$files];
        }

        self::doRemove( $files, false );
    }

    /**
     * @param  array<array-key, SplFileInfo|string> $files
     * @param  bool                                 $isRecursive
     * @return void
     */
    private static function doRemove( array $files, bool $isRecursive ) : void
    {
        dump( $files );
        $files = \array_reverse( $files );
        dump( $files );

        foreach ( $files as $file ) {

            \assert( \is_string( $file ) );

            if ( \is_link( $file ) ) {
                // See https://bugs.php.net/52176
                if ( ! ( self::box( 'unlink', $file ) || '\\' !== DIRECTORY_SEPARATOR || self::box( 'rmdir', $file ) ) && \file_exists( $file ) ) {
                    throw new FilesystemException( "Failed to remove symlink '{$file}': ".self::$lastError, $file );
                }
            }
            elseif ( \is_dir( $file ) ) {

                $origFile = null;
                if ( ! $isRecursive ) {

                    $tmpName = temp_name( $file );

                    if ( \file_exists( $tmpName ) ) {
                        try {
                            self::doRemove( [$tmpName], true );
                        }
                        catch ( FilesystemException ) {
                        }
                    }

                    if ( ! \file_exists( $tmpName ) && self::box( 'rename', $file, $tmpName ) ) {
                        $origFile = $file;
                        $file     = $tmpName;
                    }
                }

                $filesystemIterator = new FilesystemIterator( $file, FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS );

                self::doRemove( \iterator_to_array( $filesystemIterator ), true );

                if ( ! self::box( 'rmdir', $file ) && \file_exists( $file ) && ! $isRecursive ) {
                    $lastError = self::$lastError;

                    if ( null !== $origFile && self::box( 'rename', $file, $origFile ) ) {
                        $file = $origFile;
                    }

                    throw new FilesystemException( "Failed to remove directory '{$file}': ".$lastError, $file );
                }
            }
            elseif ( ! self::box( 'unlink', $file ) && ( ( self::$lastError && \str_contains( self::$lastError, 'Permission denied' ) ) || \file_exists( $file ) ) ) {
                throw new FilesystemException( "Failed to remove file '{$file}': ".self::$lastError, $file );
            }
        }
    }

    // :: CHANGE

    /**
     * Renames a file or a directory.
     *
     * @throws FilesystemException When target file or directory already exists
     * @throws FilesystemException When origin cannot be renamed
     * @param  string              $origin
     * @param  string              $target
     * @param  bool                $overwrite
     */
    public function rename( string $origin, string $target, bool $overwrite = false ) : void
    {
        // we check that target does not exist
        if ( ! $overwrite && $this->isReadable( $target ) ) {
            throw new FilesystemException( "Cannot rename '{$origin}' to '{$target}', because it already exists.", $target );
        }

        if ( ! self::box( 'rename', $origin, $target ) ) {
            if ( \is_dir( $origin ) ) {
                // See https://bugs.php.net/54097 & https://php.net/rename#113943
                $this->mirror( $origin, $target, null, ['override' => $overwrite, 'delete' => $overwrite] );
                $this->remove( $origin );

                return;
            }
            throw new FilesystemException( "Cannot rename '{$origin}' to '{$target}': ".self::$lastError, $target );
        }
    }

    /**
     * Copies a file.
     *
     * If the target file is older than the origin file, it's always overwritten.
     * If the target file is newer, it is overwritten only when the
     * $overwriteNewerFiles option is set to true.
     *
     * @throws FileNotFoundException When originFile doesn't exist
     * @throws FilesystemException   When copy fails
     * @param  string                $originFile
     * @param  string                $targetFile
     * @param  bool                  $overwriteNewerFiles
     */
    public function copy( string $originFile, string $targetFile, bool $overwriteNewerFiles = false ) : void
    {
        $originIsLocal = \stream_is_local( $originFile ) || 0 === \stripos( $originFile, 'file://' );
        if ( $originIsLocal && ! \is_file( $originFile ) ) {
            throw new FileNotFoundException( $originFile, "Failed to copy '{$originFile}' because file does not exist." );
        }

        $this->mkdir( \dirname( $targetFile ) );

        $doCopy = true;
        if ( ! $overwriteNewerFiles && ! \parse_url( $originFile, PHP_URL_HOST ) && \is_file( $targetFile ) ) {
            $doCopy = \filemtime( $originFile ) > \filemtime( $targetFile );
        }

        if ( $doCopy ) {
            // https://bugs.php.net/64634
            if ( ! $source = self::box( 'fopen', $originFile, 'r' ) ) {
                throw new FilesystemException( "Failed to copy '{$originFile}' to '{$targetFile}'; the source could not be opened for reading: ".self::$lastError, $targetFile );
            }

            // Stream context created to allow files overwrite when using FTP stream wrapper - disabled by default
            if ( ! $target = self::box( 'fopen', $targetFile, 'w', false, \stream_context_create( ['ftp' => ['overwrite' => true]] ) ) ) {
                throw new FilesystemException( "Failed to copy '{$originFile}' to '{$targetFile}'; the source could not be opened for writing: ".self::$lastError, $targetFile );
            }

            \assert( \is_resource( $source ) && \is_resource( $target ) );

            $bytesCopied = \stream_copy_to_stream( $source, $target );
            \fclose( $source );
            \fclose( $target );
            unset( $source, $target );

            if ( ! \is_file( $targetFile ) ) {
                throw new FilesystemException( "Failed to copy '{$originFile}' to '{$targetFile}'.", $targetFile );
            }

            if ( $originIsLocal ) {
                // Like `cp`, preserve executable permission bits
                self::box( 'chmod', $targetFile, \fileperms( $targetFile ) | ( \fileperms( $originFile ) & 0111 ) );

                // Like `cp`, preserve the file modification time
                self::box( 'touch', $targetFile, \filemtime( $originFile ) );

                if ( $bytesCopied !== $bytesOrigin = \filesize( $originFile ) ) {
                    throw new FilesystemException( "Failed to copy the whole content of '{$originFile}' to '{$targetFile}'; {$bytesCopied} out of {$bytesOrigin} copied.", $targetFile );
                }
            }
        }
    }

    // :: PERMISSIONS

    /**
     * Sets access and modification time of file.
     *
     * @param string|string[] $files
     * @param null|int        $time  The touch time as a Unix timestamp, if not supplied the current system time is used
     * @param null|int        $atime The access time as a Unix timestamp, if not supplied the current system time is used
     *
     * @throws FilesystemException When touch fails
     */
    public function touch( string|array $files, ?int $time = null, ?int $atime = null ) : void
    {
        foreach ( $this->toIterable( $files ) as $file ) {
            if ( ! ( $time ? self::box( 'touch', $file, $time, $atime ) : self::box( 'touch', $file ) ) ) {
                throw new FilesystemException( "Failed to touch '{$file}': ".self::$lastError, $file );
            }
        }
    }

    /**
     * Change mode for an array of files or directories.
     *
     * @param iterable|string $files
     * @param int             $mode      The new mode (octal)
     * @param int             $umask     The mode mask (octal)
     * @param bool            $recursive Whether change the mod recursively or not
     *
     * @throws FilesystemException When the change fails
     */
    public function chmod( string|iterable $files, int $mode, int $umask = 0000, bool $recursive = false ) : void
    {
        foreach ( $this->toIterable( $files ) as $file ) {
            if ( ! self::box( 'chmod', $file, $mode & ~$umask ) ) {
                throw new FilesystemException( "Failed to chmod '{$file}': ".self::$lastError, $file );
            }
            if ( $recursive && \is_dir( $file ) && ! \is_link( $file ) ) {
                $this->chmod( new FilesystemIterator( $file ), $mode, $umask, true );
            }
        }
    }

    /**
     * Change the owner of an array of files or directories.
     *
     * This method always throws on Windows, as the underlying PHP function is not supported.
     *
     * @see https://www.php.net/chown
     *
     * @param iterable|string $files
     * @param int|string      $user      A username or id
     * @param bool            $recursive Whether change the owner recursively or not
     *
     * @throws FilesystemException When the change fails
     */
    public function chown( string|iterable $files, string|int $user, bool $recursive = false ) : void
    {
        foreach ( $this->toIterable( $files ) as $file ) {
            if ( $recursive && \is_dir( $file ) && ! \is_link( $file ) ) {
                $this->chown( new FilesystemIterator( $file ), $user, true );
            }
            if ( \is_link( $file ) && \function_exists( 'lchown' ) ) {
                if ( ! self::box( 'lchown', $file, $user ) ) {
                    throw new FilesystemException( "Failed to lchown '{$file}': ".self::$lastError, $file );
                }
            }
            else {
                if ( ! self::box( 'chown', $file, $user ) ) {
                    throw new FilesystemException( "Failed to chown '{$file}': ".self::$lastError, $file );
                }
            }
        }
    }

    /**
     * Change the group of an array of files or directories.
     *
     * This method always throws on Windows, as the underlying PHP function is not supported.
     *
     * @see https://www.php.net/chgrp
     *
     * @param iterable|string $files
     * @param int|string      $group     A group name or number
     * @param bool            $recursive Whether change the group recursively or not
     *
     * @throws FilesystemException When the change fails
     */
    public function chgrp( string|iterable $files, string|int $group, bool $recursive = false ) : void
    {
        foreach ( $this->toIterable( $files ) as $file ) {
            if ( $recursive && \is_dir( $file ) && ! \is_link( $file ) ) {
                $this->chgrp( new FilesystemIterator( $file ), $group, true );
            }
            if ( \is_link( $file ) && \function_exists( 'lchgrp' ) ) {
                if ( ! self::box( 'lchgrp', $file, $group ) ) {
                    throw new FilesystemException( "Failed to lchgrp '{$file}': ".self::$lastError, $file );
                }
            }
            else {
                if ( ! self::box( 'chgrp', $file, $group ) ) {
                    throw new FilesystemException( "Failed to chgrp '{$file}': ".self::$lastError, $file );
                }
            }
        }
    }

    // :: LINK

    /**
     * Mirrors a directory to another.
     *
     * Copies files and directories from the origin directory into the target directory. By default:
     *
     *  - existing files in the target directory will be overwritten, except if they are newer (see the `override` option)
     *  - files in the target directory that do not exist in the source directory will not be deleted (see the `delete` option)
     *
     * @param string                                                                                 $originDir
     * @param string                                                                                 $targetDir
     * @param null|Traversable<array-key,SplFileInfo|string>                                         $iterator  Iterator that filters which files and directories to copy, if null a recursive iterator is created
     * @param array<string, bool>|array{'override': false,'copy_on_windows': false, 'delete': false} $options   An array of boolean options
     *                                                                                                          Valid options are:
     *                                                                                                          - $options['override'] If true, target files newer than origin files are overwritten (see copy(), defaults to false)
     *                                                                                                          - $options['copy_on_windows'] Whether to copy files instead of links on Windows (see symlink(), defaults to false)
     *                                                                                                          - $options['delete'] Whether to delete files that are not in the source directory (defaults to false)
     *
     * @throws FilesystemException When file type is unknown
     */
    public function mirror( string $originDir, string $targetDir, ?Traversable $iterator = null, array $options = [] ) : void
    {
        $targetDir    = \rtrim( $targetDir, '/\\' );
        $originDir    = \rtrim( $originDir, '/\\' );
        $originDirLen = \strlen( $originDir );

        if ( ! $this->exists( $originDir ) ) {
            throw new FilesystemException( "Origin directory '{$originDir}' does not exist.", $originDir );
        }

        // Iterate in destination folder to remove obsolete entries
        if ( $this->exists( $targetDir ) && isset( $options['delete'] ) && $options['delete'] ) {
            $deleteIterator = $iterator;
            if ( null === $deleteIterator ) {
                $flags          = FilesystemIterator::SKIP_DOTS;
                $deleteIterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $targetDir, $flags ), RecursiveIteratorIterator::CHILD_FIRST );
            }
            $targetDirLen = \strlen( $targetDir );

            foreach ( $deleteIterator as $file ) {
                \assert( $file instanceof SplFileInfo );
                $origin = $originDir.\substr( $file->getPathname(), $targetDirLen );
                if ( ! $this->exists( $origin ) ) {
                    $this->remove( $file->getPathname() );
                }
            }
        }

        $copyOnWindows = $options['copy_on_windows'] ?? false;

        if ( null === $iterator ) {
            $flags    = $copyOnWindows ? FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS : FilesystemIterator::SKIP_DOTS;
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $originDir, $flags ), RecursiveIteratorIterator::SELF_FIRST );
        }

        $this->mkdir( $targetDir );
        $filesCreatedWhileMirroring = [];

        foreach ( $iterator as $file ) {
            \assert( $file instanceof SplFileInfo );

            $path = $file->getPathname();

            if ( $file->getPathname() === $targetDir || $file->getRealPath() === $targetDir || isset( $filesCreatedWhileMirroring[$file->getRealPath()] ) ) {
                continue;
            }

            $target                              = $targetDir.\substr( $file->getPathname(), $originDirLen );
            $filesCreatedWhileMirroring[$target] = true;

            if ( ! $copyOnWindows && \is_link( $path ) ) {
                $this->symlink( $file->getLinkTarget(), $target );
            }
            elseif ( \is_dir( $path ) ) {
                $this->mkdir( $target );
            }
            elseif ( \is_file( $path ) ) {
                $this->copy( $path, $target, $options['override'] ?? false );
            }
            else {
                throw new FilesystemException( "Unable to guess the {$path} file type.", $path );
            }
        }
    }

    /**
     * Creates a symbolic link or copy a directory.
     *
     * @throws FilesystemException When symlink fails
     * @param  string              $originDir
     * @param  string              $targetDir
     * @param  bool                $copyOnWindows
     */
    public function symlink( string $originDir, string $targetDir, bool $copyOnWindows = false ) : void
    {
        if ( ! \function_exists( 'symlink' ) ) {
            throw new FilesystemException( "Required function 'symlink' does not exist or is not available." );
        }

        if ( '\\' === DIRECTORY_SEPARATOR ) {
            $originDir = \strtr( $originDir, '/', '\\' );
            $targetDir = \strtr( $targetDir, '/', '\\' );

            if ( $copyOnWindows ) {
                $this->mirror( $originDir, $targetDir );

                return;
            }
        }

        $this->mkdir( \dirname( $targetDir ) );

        if ( \is_link( $targetDir ) ) {
            if ( \readlink( $targetDir ) === $originDir ) {
                return;
            }
            $this->remove( $targetDir );
        }

        if ( ! self::box( 'symlink', $originDir, $targetDir ) ) {
            $this->linkException( $originDir, $targetDir, 'symbolic' );
        }
    }

    /**
     * Creates a hard link, or several hard links to a file.
     *
     * @param string          $originFile
     * @param iterable|string $targetFiles The target file(s)
     *
     * @throws FileNotFoundException When original file is missing or not a file
     * @throws FilesystemException   When link fails, including if link already exists
     */
    public function hardlink( string $originFile, string|iterable $targetFiles ) : void
    {
        if ( ! \function_exists( 'link' ) ) {
            throw new FilesystemException( "Required function 'link' does not exist or is not available." );
        }

        if ( ! $this->exists( $originFile ) ) {
            throw new FileNotFoundException( $originFile );
        }

        if ( ! \is_file( $originFile ) ) {
            throw new FileNotFoundException( $originFile, "Origin file '{$originFile}' is not a file." );
        }

        foreach ( $this->toIterable( $targetFiles ) as $targetFile ) {
            if ( \is_file( $targetFile ) ) {
                if ( \fileinode( $originFile ) === \fileinode( $targetFile ) ) {
                    continue;
                }
                $this->remove( $targetFile );
            }

            if ( ! self::box( 'link', $originFile, $targetFile ) ) {
                $this->linkException( $originFile, $targetFile, 'hard' );
            }
        }
    }

    // :: END :: ACTIONS

    /**
     * Returns whether the file path is an absolute path.
     * @param  string $file
     * @return bool
     */
    public static function isAbsolutePath( string $file ) : bool
    {
        return '' !== $file && (
            \strspn( $file, '/\\', 0, 1 )
                        || (
                            \strlen( $file ) > 3 && \ctype_alpha( $file[0] )
                                                 && ':' === $file[1]
                                                 && \strspn( $file, '/\\', 2, 1 )
                        )
                        || null !== \parse_url( $file, PHP_URL_SCHEME )
        );
    }

    /**
     * Turns a relative path into an absolute path in canonical form.
     *
     * Usually, the relative path is appended to the given base path. Dot
     * segments ("." and "..") are removed/collapsed and all slashes turned
     * into forward slashes.
     *
     * ```php
     * echo Path::makeAbsolute("../style.css", "/symfony/public/css");
     * // => /symfony/public/style.css
     * ```
     *
     * If an absolute path is passed, that path is returned unless its root
     * directory is different than the one of the base path. In that case, an
     * exception is thrown.
     *
     * ```php
     * Path::makeAbsolute("/style.css", "/symfony/public/css");
     * // => /style.css
     *
     * Path::makeAbsolute("C:/style.css", "C:/symfony/public/css");
     * // => C:/style.css
     *
     * Path::makeAbsolute("C:/style.css", "/symfony/public/css");
     * // InvalidArgumentException
     * ```
     *
     * If the base path is not an absolute path, an exception is thrown.
     *
     * The result is a canonical path.
     *
     * @param string $path
     * @param string $basePath an absolute base path
     *
     * @return string
     */
    public static function makePathAbsolute( string $path, string $basePath ) : string
    {
        if ( '' === $basePath ) {
            throw new InvalidArgumentException( \sprintf( 'The base path must be a non-empty string. Got: "%s".', $basePath ) );
        }

        if ( ! self::isAbsolutePath( $basePath ) ) {
            throw new InvalidArgumentException( \sprintf( 'The base path "%s" is not an absolute path.', $basePath ) );
        }

        if ( self::isAbsolutePath( $path ) ) {
            return self::normalizePath( $path );
        }

        if ( false !== $schemeSeparatorPosition = \strpos( $basePath, '://' ) ) {
            $scheme   = \substr( $basePath, 0, $schemeSeparatorPosition + 3 );
            $basePath = \substr( $basePath, $schemeSeparatorPosition + 3 );
        }
        else {
            $scheme = '';
        }

        return self::normalizePath( $scheme, $basePath, $path );
    }

    /**
     * Given an existing path, convert it to a path relative to a given starting path.
     * @param  string $endPath
     * @param  string $startPath
     * @return string
     */
    public function makePathRelative( string $endPath, string $startPath ) : string
    {
        if ( ! $this::isAbsolutePath( $startPath ) ) {
            throw new InvalidArgumentException( \sprintf( 'The start path "%s" is not absolute.', $startPath ) );
        }

        if ( ! $this::isAbsolutePath( $endPath ) ) {
            throw new InvalidArgumentException( \sprintf( 'The end path "%s" is not absolute.', $endPath ) );
        }

        // Normalize separators on Windows
        if ( '\\' === DIRECTORY_SEPARATOR ) {
            $endPath   = \str_replace( '\\', '/', $endPath );
            $startPath = \str_replace( '\\', '/', $startPath );
        }

        $splitDriveLetter = fn( $path ) => ( \strlen( $path ) > 2 && ':' === $path[1] && '/' === $path[2] && \ctype_alpha( $path[0] ) )
                ? [\substr( $path, 2 ), \strtoupper( $path[0] )]
                : [$path, null];

        $splitPath = function( $path ) {
            $result = [];

            foreach ( \explode( '/', \trim( $path, '/' ) ) as $segment ) {
                if ( '..' === $segment ) {
                    \array_pop( $result );
                }
                elseif ( '.' !== $segment && '' !== $segment ) {
                    $result[] = $segment;
                }
            }

            return $result;
        };

        [$endPath, $endDriveLetter]     = $splitDriveLetter( $endPath );
        [$startPath, $startDriveLetter] = $splitDriveLetter( $startPath );

        $startPathArr = $splitPath( $startPath );
        $endPathArr   = $splitPath( $endPath );

        if ( $endDriveLetter && $startDriveLetter && $endDriveLetter != $startDriveLetter ) {
            // End path is on another drive, so no relative path exists
            return $endDriveLetter.':/'.( $endPathArr ? \implode( '/', $endPathArr ).'/' : '' );
        }

        // Find for which directory the common path stops
        $index = 0;
        while ( isset( $startPathArr[$index], $endPathArr[$index] ) && $startPathArr[$index] === $endPathArr[$index] ) {
            $index++;
        }

        // Determine how deep the start path is relative to the common path (ie, "web/bundles" = 2 levels)
        if ( 1 === \count( $startPathArr ) && ! $startPathArr[0] ) {
            $depth = 0;
        }
        else {
            $depth = \count( $startPathArr ) - $index;
        }

        // Repeated "../" for each level need to reach the common path
        $traverser = \str_repeat( '../', $depth );

        $endPathRemainder = \implode( '/', \array_slice( $endPathArr, $index ) );

        // Construct $endPath from traversing to the common path, then to the remaining $endPath
        $relativePath = $traverser.( '' !== $endPathRemainder ? $endPathRemainder.'/' : '' );

        return '' === $relativePath ? './' : $relativePath;
    }

    public static function normalizePath( string ...$path ) : string
    {
        // Normalize separators
        $normalized = \str_replace( ['\\', '/'], DIRECTORY_SEPARATOR, $path );

        // TODO : Validate
        $isRelative = DIRECTORY_SEPARATOR === $normalized[0];

        // Implode->Explode for separator deduplication
        $exploded = \explode( DIRECTORY_SEPARATOR, \implode( DIRECTORY_SEPARATOR, $normalized ) );

        // Ensure each part does not start or end with illegal characters
        $exploded = \array_map( static fn( $item ) => \trim( $item, " \n\r\t\v\0\\/" ), $exploded );

        // Filter the exploded path, and implode using the directory separator
        $path = \implode( DIRECTORY_SEPARATOR, \array_filter( $exploded ) );

        // Preserve intended relative paths
        if ( $isRelative ) {
            $path = DIRECTORY_SEPARATOR.$path;
        }

        return $path;
    }

    /**
     * @param  callable|callable-string|string $call
     * @param  mixed                           ...$args
     * @return mixed
     */
    private static function box( callable|string $call, mixed ...$args ) : mixed
    {
        if ( \is_string( $call ) && ! \function_exists( $call ) ) {
            throw new FilesystemException( "Required function '{$call}' does not exist or is not available." );
        }

        self::$lastError = null;
        \set_error_handler( self::handleError( ... ) );
        try {
            return $call( ...$args );
        }
        finally {
            \restore_error_handler();
        }
    }

    /**
     * Gets a 2-tuple of scheme (could be null) and hierarchical part of a filename (e.g. file:///tmp -> [file, tmp]).
     * @param  string                       $filename
     * @return array{0: ?string, 1: string}
     */
    private function getSchemeAndHierarchy( string $filename ) : array
    {
        $components = \explode( '://', $filename, 2 );

        return 2 === \count( $components ) ? [$components[0], $components[1]] : [null, $components[0]];
    }

    /**
     * {@see Filesystem} error handling.
     *
     * @internal
     *
     * @param int    $type
     * @param string $msg
     */
    public static function handleError( int $type, string $msg ) : void
    {
        self::$lastError = $msg;
    }

    /**
     * Tells whether a file exists and is readable.
     *
     * @param  string $filename
     * @return bool
     */
    private function isReadable( string $filename ) : bool
    {

        $this::guardMaxLength( $filename );

        return \is_readable( $filename );
    }

    protected static function isResource( mixed $value, string $caller, ?string $name = null ) : void
    {
        if ( ! ( \is_resource( $value ) || \is_string( $value ) ) ) {
            $type   = \gettype( $value );
            $caller = $name ? "{$caller}({$name})" : $caller;
            throw new TypeError( "{$caller} expected a resource or string, '{$type}' given. " );
        }
    }

    /**
     * @param  string $path          The path to check
     * @param  ?int   $maxPathLength - uses current system limit by default
     * @return void
     *
     * @throws FilesystemException when the $path exceeds $maxPathLength
     */
    public static function guardMaxLength( string $path, ?int $maxPathLength = null ) : void
    {
        $maxPathLength ??= PHP_MAXPATHLEN - 2;
        if ( \strlen( $path ) > $maxPathLength ) {
            throw new FilesystemException( "Could not check if the file exists; the path length exceeds the maximum length of {$maxPathLength}.", $path );
        }
    }

    /**
     * @param string $origin
     * @param string $target
     * @param string $linkType Name of the link type, typically 'symbolic' or 'hard'
     *
     * @throws LinkException
     */
    private function linkException( string $origin, string $target, string $linkType ) : never
    {
        if ( self::$lastError ) {
            if ( '\\' === DIRECTORY_SEPARATOR && \str_contains( self::$lastError, 'error code(1314)' ) ) {
                $error = "error code(1314): 'A required privilege is not held by the client'";
                throw new LinkException( "Unable to create {$linkType}: {$error}. Do you have the required Administrator permissions?", $origin, $target );
            }
        }
        throw new LinkException( "Failed to create {$linkType} link from {$origin} to {$target}: ".self::$lastError, $origin, $target );
    }

    /**
     * @param  iterable|string|string[] $files
     * @return string[]
     */
    private function toIterable( mixed $files ) : iterable
    {
        return \is_iterable( $files ) ? $files : [$files];
    }
}
