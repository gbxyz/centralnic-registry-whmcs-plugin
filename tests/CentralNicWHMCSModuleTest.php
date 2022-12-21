<?php

use PHPUnit\Framework\TestCase;

class CentralNicWHMCSModuleTest extends TestCase {

    protected static array $params = [];

    protected static string $sld;

    public static function setUpBeforeClass() : void {
        $file = __DIR__.'/config.ini';
        if (file_exists($file)) {
            self::$params = parse_ini_file($file, false);
        }

        self::$sld = substr(strtolower(__CLASS__.'-test-'.uniqid()), 0, 63);
    }

    protected static function standardFunctionParams() : array {
        return [
            'testMode'              => 1,
            'ResellerHandle'        => self::$params['ResellerHandle'],
            'ResellerAPIPassword'   => self::$params['ResellerAPIPassword'],
            'sld'                   => self::$sld,
            'tld'                   => self::$params['TestTLD'],
        ];
    }

    protected function getLastEPPResponseCode() : int {
        $response = \centralnic\whmcs\plugin::connection()->getLastResponse();

        return intval($response->getElementsByTagName('result')->item(0)->getAttribute('code'));
    }

    public static function providerCoreFunctionNames() {
        return [
            ['RegisterDomain'],
            ['TransferDomain'],
            ['RenewDomain'],
            ['GetNameservers'],
            ['SaveNameservers'],
            ['GetContactDetails'],
            ['SaveContactDetails'],
        ];
    }

    public static function providerAllFunctionNames() {
        return [
            ['MetaData'],
            ['getConfigArray'],
            ['RegisterDomain'],
            ['TransferDomain'],
            ['RenewDomain'],
            ['GetNameservers'],
            ['SaveNameservers'],
            ['GetRegistrarLock'],
            ['SaveRegistrarLock'],
            ['GetContactDetails'],
            ['SaveContactDetails'],
            ['GetEPPCode'],
            ['RegisterNameserver'],
            ['ModifyNameserver'],
            ['DeleteNameserver'],
            ['Sync'],
            ['RequestDelete'],
            ['TransferSync'],
            ['CheckAvailability'],
        ];
    }

    /**
     * Test Core Module Functions Exist
     *
     * This test confirms that the functions WHMCS recommend for all registrar
     * modules are defined for the module
     *
     * @param $function
     *
     * @dataProvider providerCoreFunctionNames
     */
    public function testCoreModuleFunctionsExist($function) {
        $this->assertTrue(function_exists('centralnic_' . $function), 'core function exists');
    }

    /**
     * Test all Module Functions Exist
     *
     * This test confirms that all functions for registrar
     * modules are defined for the module
     *
     * @param $function
     *
     * @dataProvider providerAllFunctionNames
     */
    public function testAllModuleFunctionsExist($function) {
        $this->assertTrue(function_exists('centralnic_' . $function), 'extended function exists');
    }

    /**
     * Test all Module Functions Methods Exist
     *
     * This test confirms that the static methods for
     * each function exist
     *
     * @param $function
     *
     * @dataProvider providerAllFunctionNames
     */
    public function testModuleFunctionMethods($function) {
        $this->assertTrue(method_exists('\centralnic\whmcs\plugin', $function), 'module method exists');
    }

    /**
     * Test the signatures of each method
     *
     * @dataProvider providerAllFunctionNames
     */
    public function testModuleMethodSignatures($function) {

        $ref = new ReflectionMethod('\centralnic\whmcs\plugin', $function);

        $params = $ref->getParameters();
        $this->assertCount(2, $params);

        var_export($params);

        //
        // normally functions return arrays, but there are a few exceptions
        //
        if ('GetRegistrarLock' == $function) {
            $expectedType = 'string';

        } elseif ('CheckAvailability' == $function) {
            $expectedType = 'WHMCS\Domains\DomainLookup\ResultsList';

        } else {
            $expectedType = 'array';

        }

        $ret = $ref->getReturnType();

        $this->assertTrue($ret instanceof ReflectionNamedType);

        $this->assertEquals($expectedType, $ret->getName(), 'method return type matches');
    }

    public function testMetaData() {
        $array = centralnic_MetaData();
        $this->assertIsArray($array, 'MetaData() returns an array');
        $this->assertCount(2, $array, 'array returned by MetaData() contains two members');
        $this->assertNotEmpty($array['DisplayName'], 'DisplayName present in MetaData() return array');
        $this->assertNotEmpty($array['APIVersion'], 'APIVersion present in MetaData() return array');
    }

    public function testgetConfigArray() {
        $array = centralnic_getConfigArray();
        $this->assertIsArray($array, 'GetConfig() returns an array');
        $this->assertCount(4, $array, 'array returned by GetConfig() contains 4 members');
        $this->assertNotEmpty($array['ResellerHandle'], 'required config option exists in return array');
        $this->assertNotEmpty($array['ResellerAPIPassword'], 'required config option exists in return array');
        $this->assertNotEmpty($array['testMode'], 'required config option exists in return array');
        $this->assertNotEmpty($array['BillingCurrency'], 'required config option exists in return array');
    }

    public function testParams() {
        foreach (['ResellerHandle', 'ResellerAPIPassword', 'TestTLD'] as $k) {
            $this->assertNotEmpty(self::$params[$k], 'test config contains required parameter "{$k}"');
        }
    }

    public function testRegisterDomain() {
        $params = self::standardFunctionParams();
        $params['regperiod']        = 1;

        $params['ns1']              = 'ns1.centralnic.net';
        $params['ns2']              = 'ns2.centralnic.net';
        $params['ns3']              = 'ns3.centralnic.net';
        $params['ns4']              = 'ns4.centralnic.net';
        $params['ns5']              = 'ns5.centralnic.net';

        $params['fullname']         = 'John Doe';
        $params['companyname']      = 'Example Inc.';
        $params['address1']         = '123 Example Dr';
        $params['address2']         = 'Suite 100';
        $params['city']             = 'Dulles';
        $params['state']            = 'VA';
        $params['postcode']         = '20166-6503';
        $params['country']          = 'US';
        $params['fullphonenumber']  = '+1.7035555555';
        $params['email']            = 'jdoe@example.com';

        centralnic_RegisterDomain($params);

        $this->assertEquals(1000, self::getLastEPPResponseCode(), 'EPP <create> returned code 1000');
    }

    public function testTransferDomain() {
        $this->assertTrue(true);
    }

    public function testRenewDomain() {
        $params = self::standardFunctionParams();
        $params['regperiod'] = 1;

        centralnic_RenewDomain($params);

        $this->assertEquals(1000, self::getLastEPPResponseCode(), 'EPP <renew> returned code 1000');
    }

    public function testGetNameservers() {
        $params = self::standardFunctionParams();

        $ns = centralnic_GetNameservers($params);

        $this->assertIsArray($ns);

        for ($i = 1 ; $i <= count($ns) ; $i++) {
            $this->assertEquals($ns[sprintf('ns%u', $i)], sprintf('ns%u.centralnic.net', $i));
        }
    }

    public function testSaveNameservers() {
        $params = self::standardFunctionParams();

        //
        // set new nameservers
        //
        $params['ns1'] = 'ns1.centralnic.org';
        $params['ns2'] = 'ns2.centralnic.org';
        $params['ns3'] = 'ns3.centralnic.org';
        $params['ns4'] = 'ns4.centralnic.org';
        $params['ns5'] = 'ns5.centralnic.org';

        centralnic_SaveNameservers($params);

        $this->assertEquals(1000, self::getLastEPPResponseCode(), 'EPP <update> returned code 1000');
    }

    public function testGetRegistrarLock() {
        $params = self::standardFunctionParams();

        $lock = centralnic_GetRegistrarLock($params);

        $this->assertEquals($lock, 'unlocked');
    }

    public function testAddRegistrarLock() {
        $params = self::standardFunctionParams();
        $params['lockenabled'] = 'locked';

        centralnic_SaveRegistrarLock($params);

        $this->assertEquals(1000, self::getLastEPPResponseCode(), 'EPP <update> returned code 1000');
    }

    public function testRemoveRegistrarLock() {
        $params = self::standardFunctionParams();
        $params['lockenabled'] = 'unlocked';

        centralnic_SaveRegistrarLock($params);

        $this->assertEquals(1000, self::getLastEPPResponseCode(), 'EPP <update> returned code 1000');
    }

    public function testGetContactDetails() {
        $this->assertTrue(true);
    }

    public function testSaveContactDetails() {
        $this->assertTrue(true);
    }

    public function testGetEPPCode() {
        $this->assertTrue(true);
    }

    public function testRegisterNameserver() {
        $this->assertTrue(true);
    }

    public function testModifyNameserver() {
        $this->assertTrue(true);
    }

    public function testDeleteNameserver() {
        $this->assertTrue(true);
    }

    public function testSync() {
        $this->assertTrue(true);
    }

    public function testTransferSync() {
        $this->assertTrue(true);
    }

    public function testCheckAvailability() {
        $this->assertTrue(true);
    }

    public function testRequestDelete() {
        $params = [
            'testMode'              => 1,
            'ResellerHandle'        => self::$params['ResellerHandle'],
            'ResellerAPIPassword'   => self::$params['ResellerAPIPassword'],
            'sld'                   => self::$sld,
            'tld'                   => self::$params['TestTLD'],
            'regperiod'             => 1,
        ];

        centralnic_RequestDelete($params);

        $response = \centralnic\whmcs\plugin::connection()->getLastResponse();

        $this->assertLessThanOrEqual(1999, $response->getElementsByTagName('result')->item(0)->getAttribute('code'), 'EPP <delete> returned code 1999 or lower');
    }
}
