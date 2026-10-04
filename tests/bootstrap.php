<?php
/**
 * PHPUnit bootstrap — loads Composer autoloader and stubs ABSPATH.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Define ABSPATH so plugin files don't bail out early.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Global helpers are not autoloaded. The file only declares functions, so it is
// safe to load without WordPress; the ones that call WordPress still need it.
require_once __DIR__ . '/../includes/Helpers.php';
