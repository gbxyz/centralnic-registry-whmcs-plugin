<?php declare(strict_types=1);

/**
 * CentralNic Registry WHMCS Module
 * Copyright 2023 CentralNic Group PLC. All rights reserved.
 */

use PHPUnit\Framework\TestCase;
use centralnic\whmcs\{plugin,epp,error};

class eppClientTest extends TestCase {

    public function testFailedConnection(): void {
        $this->expectException(ErrorException::class);

        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                // This error code is not included in error_reporting
                return;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        $epp = new epp('127.0.0.1', 'foo', 'bar', true);

        set_error_handler(null);
    }

    public function testDroppedConnection(): void {
        $epp = new epp(
            plugin::test_host,
            getenv('EPP_CLIENT1_ID') ?: getenv('EPP_CLIENT_ID'),
            getenv('EPP_CLIENT1_PW') ?: getenv('EPP_CLIENT_PW'),
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
}
