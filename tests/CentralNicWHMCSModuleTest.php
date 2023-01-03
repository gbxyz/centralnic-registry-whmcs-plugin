<?php

declare(strict_types=1);

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

    /**
     * this is a data provider allowing us to validate the existence of all the
     * module functions
     */
    public static function FunctionNamesProvider() {
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
     * this returns a set of common function parameters common to all module functions
     */
    protected static function standardFunctionParams() : array {
        return [
            'testMode'              => 1,
            'ResellerHandle'        => self::$params['ResellerHandle'],
            'ResellerAPIPassword'   => self::$params['ResellerAPIPassword'],
            'sld'                   => self::$sld,
            'tld'                   => self::$params['TestTLD'],
        ];
    }

    /**
     * return values from plugin functions had a standard structure,
     * which this method tests
     */
    private function doStandardResultChecks($result) {
        $this->assertIsArray($result, 'function returned an array');

        $this->assertArrayHasKey('success', $result, "return array contains the 'success' key");

        $this->assertIsBool($result['success'], 'success is a boolean');

        $this->assertTrue($result['success'], 'success is true');
    }

    /*
     *
     * tests from here onwards
     *
     */

    /**
     * @dataProvider FunctionNamesProvider
     */
    public function testModuleFunctionsExist($function) {
        $this->assertTrue(function_exists('centralnic_' . $function), 'module function exists');
    }

    /**
     * Test all Module Functions Methods Exist
     *
     * This test confirms that the static methods for
     * each function exist
     *
     * @param $function
     *
     * @dataProvider FunctionNamesProvider
     */
    public function testModuleFunctionMethods($function) {
        $this->assertTrue(method_exists('\centralnic\whmcs\plugin', $function), 'module method exists');
    }

    /**
     * Test the signatures of each method
     *
     * @dataProvider FunctionNamesProvider
     */
    public function testModuleMethodSignatures($function) {

        $ref = new ReflectionMethod('\centralnic\whmcs\plugin', $function);

        //
        // check the number of parameters each method accepts
        //
        $params = $ref->getParameters();
        if (in_array($function, ['MetaData', 'getConfigArray'])) {
            $pcount = 0;

        } else {
            $pcount = 1;

        }

        $this->assertCount($pcount, $params);

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

        $this->assertInstanceOf('ReflectionNamedType', $ret);

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

        //
        // ensure this array is sorted for later validation of server response
        //
        ksort($params, SORT_STRING);

        $this->doStandardResultChecks(centralnic_RegisterDomain($params));

        try {
            //
            // get domain info from server
            //
            $info = \centralnic\whmcs\plugin::info($params['sld'].'.'.$params['tld']);

            //
            // this is what we expect the expiry date to be
            //
            $expectedExDate = gmdate('Y-m-d', gmmktime(
                intval(gmdate('G')),
                intval(gmdate('i')),
                intval(gmdate('s')),
                intval(gmdate('n')),
                intval(gmdate('j')),
                intval(gmdate('Y')) + $params['regperiod'],
            ));

            $exDate = gmdate('Y-m-d', strtotime($info->getElementsByTagName('exDate')->item(0)->textContent));

            $this->assertEquals($expectedExDate, $exDate, 'Domain expiry date matches expected value');

            //
            // validate nameservers
            //

            $expectedNS = [];
            foreach ($params as $k => $v) if (1 == preg_match('/^ns\d+$/', $k)) $expectedNS[] = strtolower($v);
            sort($expectedNS, SORT_STRING);

            $ns = [];
            foreach ($info->getElementsByTagName('hostObj') as $el) $ns[] = strtolower($el->textContent);
            sort($ns, SORT_STRING);

            $this->assertEquals($expectedNS, $ns, 'nameservers match expected set');

            //
            // validate registrant
            //

            $registrant_id = $info->getElementsByTagName('registrant')->item(0)->textContent;
            $registrant = \centralnic\whmcs\plugin::contactInfo($registrant_id);

            $this->assertEquals($params['fullname'], $registrant->getElementsByTagName('name')->item(0)->textContent, 'registrant name matches');
            $this->assertEquals($params['companyname'], $registrant->getElementsByTagName('org')->item(0)->textContent, 'company name name matches');

            $expectedStreet = [];
            foreach ($params as $k => $v) if (1 == preg_match('/^address\d+$/', $k)) $expectedStreet[] = $v;

            $street = [];
            foreach ($registrant->getElementsByTagName('street') as $el) $street[] = $el->textContent;

            $this->assertEquals($expectedStreet, $street, 'street address matches');

            $this->assertEquals($params['city'], $registrant->getElementsByTagName('city')->item(0)->textContent, 'city matches');
            $this->assertEquals($params['state'], $registrant->getElementsByTagName('sp')->item(0)->textContent, 'state/province matches');
            $this->assertEquals($params['postcode'], $registrant->getElementsByTagName('pc')->item(0)->textContent, 'postcode matches');
            $this->assertEquals($params['country'], $registrant->getElementsByTagName('cc')->item(0)->textContent, 'country matches');
            $this->assertEquals($params['fullphonenumber'], $registrant->getElementsByTagName('voice')->item(0)->textContent, 'voice matches');
            $this->assertEquals($params['email'], $registrant->getElementsByTagName('email')->item(0)->textContent, 'email matches');

            //
            // validate contact objects
            //

            $contacts = [];
            foreach ($info->getElementsByTagName('contact') as $el) $contacts[$el->getAttribute('type')] = $el->textContent;

            foreach (['admin', 'tech', 'billing'] as $type) {
                $this->assertArrayHasKey($type, $contacts, "{$type} contact exists");
                $this->assertEquals($registrant_id, $contacts[$type], "{$type} contact matches registrant");
            }

        } catch (\centralnic\whmcs\error $e) {
            fwrite(STDERR, $e->getMessage());

        }
    }

    /**
     * not currently testable
     */
    public function testTransferDomain() {
        $this->assertTrue(true);
    }

    public function testRenewDomain() {
        $params = self::standardFunctionParams();
        $params['regperiod'] = 1;

        $this->doStandardResultChecks(centralnic_RenewDomain($params));
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

        $this->doStandardResultChecks(centralnic_SaveNameservers($params));
    }

    public function testGetRegistrarLock() {
        $params = self::standardFunctionParams();

        $lock = centralnic_GetRegistrarLock($params);

        $this->assertIsString($lock);
        $this->assertEquals($lock, 'unlocked');
    }

    public function testAddRegistrarLock() {
        $params = self::standardFunctionParams();
        $params['lockenabled'] = 'locked';

        $this->doStandardResultChecks(centralnic_SaveRegistrarLock($params));
    }

    public function testRemoveRegistrarLock() {
        $params = self::standardFunctionParams();
        $params['lockenabled'] = 'unlocked';

        $this->doStandardResultChecks(centralnic_SaveRegistrarLock($params));
    }

    public function testGetContactDetails() {
        $params = self::standardFunctionParams();

        $result = centralnic_GetContactDetails($params);

        $this->assertIsArray($result, 'function returned an array');

        foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $k) {
            $this->assertArrayHasKey($k, $result, "return array contains '{$k}' key");
            $this->assertIsArray($result[$k], "'{$k}' key is an array");

            foreach (['Full Name', 'Company Name', 'Address 1', 'Address 2', 'Address 3', 'City', 'State', 'Postcode', 'Country', 'Phone Number', 'Email Address'] as $j) {
                $this->assertArrayHasKey($j, $result[$k], "return array contains '{$j}' key");
            }
        }
    }

    public function testSaveContactDetails() {
        $params = self::standardFunctionParams();

        $info = [
            'Full Name'     => 'New Person',
            'Company Name'  => 'New Company',
            'Address 1'     => 'New Street',
            'Address 2'     => '',
            'Address 3'     => '',
            'City'          => 'Newtown',
            'State'         => 'Newshire',
            'Postcode'      => 'NE1 WPC',
            'Country'       => 'GB',
            'Phone Number'  => '+44.2033880600',
            'Fax Number'    => '',
            'Email Address' => 'test@centralnic.com',
        ];

        $params['contactdetails'] = [];
        $params['contactdetails']['Registrant'] = $params['contactdetails']['Admin'] = $info;

        $this->doStandardResultChecks(centralnic_SaveContactDetails($params));
    }

    public function testGetEPPCode() {
        $params = self::standardFunctionParams();

        $result = centralnic_GetEPPCode($params);

        $this->assertIsArray($result, 'function returned an array');
        $this->assertArrayHasKey('eppcode', $result, 'return array contains EPP code');
        $this->assertIsString($result['eppcode'], 'EPP code is a string');
    }

    public function testRegisterNameserver() {
        $params = self::standardFunctionParams();

        $params['nameserver'] = 'nameserver-test.'.$params['sld'].'.'.$params['tld'];
        $params['ipaddress'] = '193.105.170.1';

        $this->doStandardResultChecks(centralnic_RegisterNameserver($params));
    }

    public function testModifyNameserver() {
        $params = self::standardFunctionParams();

        $params['nameserver'] = 'nameserver-test.'.$params['sld'].'.'.$params['tld'];
        $params['currentipaddress'] = '193.105.170.1';
        $params['newipaddress'] = '193.105.170.2';

        $this->doStandardResultChecks(centralnic_ModifyNameserver($params));
    }

    public function testDeleteNameserver() {
        $params = self::standardFunctionParams();
        $params['nameserver'] = 'nameserver-test.'.$params['sld'].'.'.$params['tld'];

        $this->doStandardResultChecks(centralnic_DeleteNameserver($params));
    }

    public function testSync() {
        $params = self::standardFunctionParams();

        $result = centralnic_Sync($params);

        $this->assertIsArray($result, 'function returned an array');

        foreach (['expirydate', 'active', 'expired', 'transferredAway'] as $k) {
            $this->assertArrayHasKey($k, $result, "return array contains '{$k}' key");
        }

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['expirydate']);

        $this->assertIsBool($result['active']);
        $this->assertTrue($result['active']);

        $this->assertIsBool($result['expired']);
        $this->assertFalse($result['expired']);

        $this->assertIsBool($result['transferredAway']);
        $this->assertFalse($result['transferredAway']);
    }

    /**
     * not currently testable
     */
    public function testTransferSync() {
        $this->assertTrue(true);
    }

    /**
     * not currently testable
     */
    public function testCheckAvailability() {
        $this->assertTrue(true);
    }

    public function testRequestDelete() {
        $this->doStandardResultChecks(centralnic_RequestDelete(self::standardFunctionParams()));
    }
}
