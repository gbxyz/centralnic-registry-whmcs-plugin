<?php declare(strict_types=1);

/**
 * CentralNic Registry WHMCS Module
 * Copyright 2023 CentralNic Group PLC. All rights reserved.
 */

use PHPUnit\Framework\TestCase;
use centralnic\whmcs\{plugin,epp,error};

require_once __DIR__.'/pluginTest.php';

class eppClientTest extends TestCase {

    private static function setupErrorHandler(): void {
        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                // This error code is not included in error_reporting
                return;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public function testFailedConnection(): void {
        self::setupErrorHandler();

        $this->expectException(ErrorException::class);

        $epp = new epp('127.0.0.1', 'foo', 'bar', true);

        set_error_handler(null);
    }

    public function testDroppedConnection(): void {
        $params = pluginTest::standardFunctionParams();

        $epp = new epp(
            host:   plugin::serverName($params),
            clid:   $params['ResellerHandle'],
            pw:     $params['ResellerAPIPassword'],
        );

        $this->assertIsObject($epp);

        $epp->debug = true;
        $epp->logout();

        $this->expectException(TypeError::class);

        $hello = new centralnic\whmcs\xml\frame;
        $hello->appendChild($hello->createElementNS(epp::xmlns, 'epp'))
                ->appendChild($hello->createElement('hello'));

        $epp->sendFrame($hello);
    }

    public function testXMLParser(): void {
        $this->expectException(error::class);

        epp::parseXML('this is not XML');
    }

    public function testConnectWithClientCertificate(): void {
        $params = pluginTest::standardFunctionParams();

        $params['ClientCertificate']    = 'foo';
        $params['PrivateKey']           = 'bar';

        self::setupErrorHandler();

        $this->expectException(ErrorException::class);

        plugin::getConnection($params);

        set_error_handler(null);
    }
}
