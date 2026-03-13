<?php
/**
 * PHPUnit bootstrap — loads Composer autoloader and stubs ABSPATH.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Define ABSPATH so plugin files don't bail out early.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
