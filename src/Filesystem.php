<?php

namespace Northrook;

use Northrook\Logger\Log;
use Symfony\Component\Filesystem\Exception\IOException;

final class Filesystem extends \Symfony\Component\Filesystem\Filesystem
{
    /**
     * Mimetypes for simple .extension lookup.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Common_types
     */
    private const array MIME_TYPES = [

        // Text and XML
        'txt'    => 'text/plain',
        'htm'    => 'text/html',
        'html'   => 'text/html',
        'php'    => 'text/html',
        'css'    => 'text/css',
        'js'     => 'text/javascript',

        // Documents
        'rtf'    => 'application/rtf',
        'doc'    => 'application/msword',
        'pdf'    => 'application/pdf',
        'eps'    => 'application/postscript',

        // Data sources
        'csv'    => 'text/csv',
        'json'   => 'application/json',
        'jsonld' => 'application/ld+json',
        'xls'    => 'application/vnd.ms-excel',
        'xml'    => 'application/xml',

        // Images and vector graphics
        'apng'   => 'image/png',
        'png'    => 'image/png',
        'jpe'    => 'image/jpeg',
        'jpeg'   => 'image/jpeg',
        'jpg'    => 'image/jpeg',
        'gif'    => 'image/gif',
        'bmp'    => 'image/bmp',
        'ico'    => 'image/vnd.microsoft.icon',
        'tiff'   => 'image/tiff',
        'tif'    => 'image/tiff',
        'svg'    => 'image/svg+xml',
        'svgz'   => 'image/svg+xml',
        'webp'   => 'image/webp',
        'webm'   => 'video/webm',

        // archives
        '7z'     => 'application/x-7z-compressed',
        'zip'    => 'application/zip',
        'rar'    => 'application/x-rar-compressed',
        'exe'    => 'application/x-msdownload',
        'msi'    => 'application/x-msdownload',
        'cab'    => 'application/vnd.ms-cab-compressed',
        'tar'    => 'application/x-tar',

        // audio/video
        'mp3'    => 'audio/mpeg',
        'qt'     => 'video/quicktime',
        'mov'    => 'video/quicktime',

        // Fonts
        'ttf'    => 'font/ttf',
        'otf'    => 'font/otf',
        'woff'   => 'font/woff',
        'woff2'  => 'font/woff2',
        'eot'    => 'application/vnd.ms-fontobject',
    ];

    /**
     * @param string $path
     *
     * @return null|int
     */
    public function size( string $path ) : ?int
    {
        try {
            return \filesize( $path );
        }
        catch ( IOException $exception ) {
            Log::exception( $exception, 'error', 'Could not determine file size for provided path.' );
        }
        return null;
    }

    /**
     * @param string $path
     *
     * @return null|string
     */
    public function getMimeType( string $path ) : ?string
    {
        try {
            return $this::MIME_TYPES[\pathinfo( $path, PATHINFO_EXTENSION )];
        }
        catch ( \Throwable $exception ) {
            Log::exception( $exception, 'error', 'Could not determine mime type for provided path.' );
        }
        return null;
    }
}
