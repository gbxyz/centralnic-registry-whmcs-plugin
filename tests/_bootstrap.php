<?php

// This is the bootstrap for PHPUnit testing.

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

// include the WHMCS module.
require_once __DIR__ . '/../modules/registrars/centralnic/centralnic.php';

/**
 * Mock logModuleCall function for testing purposes.
 *
 * Inside of WHMCS, this function provides logging of module calls for debugging
 * purposes. The module log is accessed via Utilities > Logs.
 *
 * @param string $module
 * @param string $action
 * @param string|array $request
 * @param string|array $response
 * @param string|array $data
 * @param array $variablesToMask
 *
 * @return void|false
 */
function logModuleCall(
    $module,
    $action,
    $request,
    $response,
    $data = '',
    $variablesToMask = array()
) {
    // do nothing during tests
}

require_once __DIR__.'/_whmcs-shim.php';
