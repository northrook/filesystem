<?php

declare( strict_types = 1 );
use PhpCsFixer\Finder;
$finder = Finder::create()
                ->in( __DIR__ )
                ->exclude( 'vendor' )
                ->append( [ '.php_cs.dist' ] );

$rules = [];

return \Northrook\standards( $finder, $rules );