<?php

declare(strict_types=1);

namespace Northrook\Resource;

use Northrook\Filesystem\{File, Resource};
use Support\Normalize;

/**
 * @property string  $path
 * @property bool    $exists
 * @property ?string $fetch
 */
class URL extends Resource
{
    private int $httpCode;

    protected mixed $content;

    /**
     * @param Path|string $url
     * @param ?string     $enforceDomain
     */
    public function __construct(
        string|Path       $url,
        protected ?string $enforceDomain = null,
    ) {
        $this->path = Normalize::url( (string) $url );
    }

    public function __get( string $property )
    {
        return match ( $property ) {
            'path'   => $this->path,
            'exists' => $this->exists  ??= $this->exists(),
            'fetch'  => $this->content ??= File::read( $this->path ),
        };
    }

    public function save( string $path ) : ?Path
    {
        if ( ! $this->fetch() ) {
            return null;
        }

        $path = new Path( $path );

        $path->save( $this->content );

        return $path;
    }

    public function fetch( bool $retry = false ) : mixed
    {

        if ( isset( $this->content ) && ! $retry ) {
            return $this->content;
        }

        return $this->content = File::read( $this->path );
    }

    private function exists() : bool
    {
        $httpCode = $this->getHttpCode();

        return $httpCode >= 200 && $httpCode < 400 ;

    }

    /**
     * @return int HTTP response code
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status MDN
     */
    private function getHttpCode() : int
    {

        if ( isset( $this->httpCode ) ) {
            return $this->httpCode;
        }

        $handle = \curl_init( $this->path );

        \curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );

        $this->httpCode = \curl_exec( $handle )
            ? \curl_getinfo( $handle, CURLINFO_HTTP_CODE )
            : 204;

        \curl_close( $handle );

        return $this->httpCode;
    }
}
