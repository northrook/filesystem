<?php

declare(strict_types=1);

namespace Northrook\Filesystem;

use Override;
use Support\Normalize;

final class URL extends Reference
{
    private int $httpCode;

    /**
     * @param Path|string|URL $url
     * @param ?string         $enforceDomain
     */
    public function __construct(
        string|URL|Path   $url,
        protected ?string $enforceDomain = null,
    ) {
        $this->path = Normalize::url( $url instanceof Path ? $url->path : (string) $url );
    }

    /**
     * @param non-empty-string $path
     *
     * @return null|Path
     */
    public function save( string $path ) : ?Path
    {
        if ( ! $this->fetch() ) {
            return null;
        }

        $path = new Path( $path );

        $path->save( (string) $this->content );

        return $path;
    }

    /**
     * @param bool $retry
     *
     * @return null|resource|string
     */
    public function fetch( bool $retry = false ) : mixed
    {

        if ( $this->content && ! $retry ) {
            return $this->content;
        }

        return $this->content = File::read( $this->path );
    }

    #[Override]
    public function exists() : bool
    {
        static $httpCode;
        $httpCode ??= $this->getHttpCode();

        return $this->exists = ( $httpCode >= 200 && $httpCode < 400 );
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
