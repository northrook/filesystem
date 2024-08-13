<?php

declare( strict_types = 1 );

namespace Northrook\Resource;

use Northrook\Filesystem\File;
use Northrook\Filesystem\Resource;
use function curl_close, curl_exec, curl_getinfo, curl_init, curl_setopt;
use function Northrook\normalizeUrl;

/**
 * @template UrlString as string
 *
 *
 * @property-read  string  $path
 * @property-read  bool    $exists
 * @property-read  ?string $fetch
 */
class URL extends Resource
{
    private int $httpCode;

    protected mixed $content;

    /**
     * @param Path|string<UrlString>  $path
     */
    public function __construct(
        string | Path     $path,
        protected ?string $enforceDomain = null,
    ) {
        $this->path = normalizeUrl( ( string ) $path );
    }

    public function __get( string $property ) {
        return match ( $property ) {
            'path'   => $this->path,
            'exists' => $this->exists ??= $this->exists(),
            'fetch'  => $this->content ??= File::read( $this->path ),
        };
    }

    public function save( string $path ) : ?Path {
        if ( !$this->fetch() ) {
            return null;
        }

        $path = new Path( $path );

        $path->save( $this->content );

        return $path;
    }

    public function fetch( bool $retry = false ) : mixed {

        if ( isset( $this->content ) && !$retry ) {
            return $this->content;
        }

        return $this->content = File::read( $this->path );
    }

    private function exists() : bool {
        $httpCode = $this->getHttpCode();

        if ( $httpCode >= 200 && $httpCode < 400 ) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * @return int HTTP response code
     * @link https://developer.mozilla.org/en-US/docs/Web/HTTP/Status MDN
     */
    private function getHttpCode() : int {

        if ( isset( $this->httpCode ) ) {
            return $this->httpCode;
        }

        $handle = curl_init( $this->path );

        curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );

        $this->httpCode = curl_exec( $handle )
            ? curl_getinfo( $handle, CURLINFO_HTTP_CODE )
            : 204;

        curl_close( $handle );

        return $this->httpCode;
    }

}