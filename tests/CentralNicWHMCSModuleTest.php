<?php

/**
 * CentralNic Registry WHMCS Module
 * Copyright 2023 CentralNic Group PLC. All rights reserved.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CentralNicWHMCSModuleTest extends TestCase {

    protected static array $params = [];

    protected static string $sld;

    private static function getenv(string $name): string {
        $value = getenv($name);

        if (false === $value || strlen($name) < 1) {
            throw new Exception("Missing or empty '{$name}' environment variable");
        }

        return $value;
    }

    public static function setUpBeforeClass() : void {
        global $argv;

        self::$params = [
            'ResellerHandle'        => self::getenv('EPP_CLIENT_ID'),
            'ResellerAPIPassword'   => self::getenv('EPP_CLIENT_PW'),
            'BillingCurrency'       => self::getenv('BILLING_CURRENCY'),
            'TestTLD'               => self::getenv('TEST_TLD'),
            'PremiumCost'           => self::getenv('PREMIUM_COST'),
        ];

        self::$sld = substr(strtolower(__CLASS__.'-test-'.uniqid()), 0, 63);

        \centralnic\whmcs\plugin::$debug = true;
    }

    /**
     * this is a data provider allowing us to validate the existence of all the
     * module functions
     */
    public static function functionNamesDataProvider() : array {
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

    public static function availabilitySearchDataProvider() : array {
        return [
            [
                # not registered, standard
                'sld'       => 'available-domain-'.uniqid(),
                'tld'       => 'uk.com',
                'avail'     => true,
                'premium'   => false,
            ],
            [
                # registered, standard
                'sld'       => 'example',
                'tld'       => 'uk.com',
                'avail'     => false,
                'premium'   => false,
            ],
            [
                # not registered, premium
                'sld'       => 'aa',
                'tld'       => 'uk.com',
                'avail'     => true,
                'premium'   => true,
                'register'  => 125.73,
                'renew'     => 20.64,
            ],
            [
                # registered, premium
                'sld'       => 'ab',
                'tld'       => 'uk.com',
                'avail'     => false,
                'premium'   => true,
                'register'  => 125.73,
                'renew'     => 20.64,
            ],
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
            'BillingCurrency'       => self::$params['BillingCurrency'],
            'sld'                   => self::$sld,
            'tld'                   => self::$params['TestTLD'],
            'nameserver'            => 'nameserver-test.'.self::$sld.'.'.self::$params['TestTLD'],
            'premiumEnabled'        => true,
            'premiumCost'           => self::$params['PremiumCost'],
        ];
    }

    /**
     * return values from plugin functions had a standard structure,
     * which this method tests
     */
    private function doStandardResultChecks($result) {
        $this->assertIsArray($result, 'function returned an array');

        $this->assertArrayHasKey('success', $result, "return array contains the 'success' key");

        $this->assertIsBool($result['success'], "'success' value is a boolean");

        if (true !== $result['success']) {
            fwrite(STDERR, $result['error']."\n");
        }

        $this->assertTrue($result['success'], "'success' value is true");
    }

    /*
     *
     * tests from here onwards
     *
     */

    /**
     * @dataProvider functionNamesDataProvider
     */
    public function test_ModuleFunctionsExist($function) {
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
     * @dataProvider functionNamesDataProvider
     */
    public function test_ModuleFunctionMethods($function) {
        $this->assertTrue(method_exists('\centralnic\whmcs\plugin', $function), 'module method exists');
    }

    /**
     * Test the signatures of each method
     *
     * @dataProvider functionNamesDataProvider
     */
    public function test_ModuleMethodSignatures($function) {

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

    public function test_MetaData() {
        $array = centralnic_MetaData();
        $this->assertIsArray($array, 'MetaData() returns an array');
        $this->assertCount(2, $array, 'array returned by MetaData() contains two members');
        $this->assertNotEmpty($array['DisplayName'], 'DisplayName present in MetaData() return array');
        $this->assertNotEmpty($array['APIVersion'], 'APIVersion present in MetaData() return array');
    }

    public function test_getConfigArray() {
        $array = centralnic_getConfigArray();
        $this->assertIsArray($array, 'GetConfig() returns an array');
        $this->assertCount(4, $array, 'array returned by GetConfig() contains 4 members');
        $this->assertNotEmpty($array['ResellerHandle'], 'required config option exists in return array');
        $this->assertNotEmpty($array['ResellerAPIPassword'], 'required config option exists in return array');
        $this->assertNotEmpty($array['testMode'], 'required config option exists in return array');
        $this->assertNotEmpty($array['BillingCurrency'], 'required config option exists in return array');
    }

    public function test_Params() {
        foreach (['ResellerHandle', 'ResellerAPIPassword', 'TestTLD'] as $k) {
            $this->assertNotEmpty(self::$params[$k], 'test config contains required parameter "{$k}"');
        }
    }

    public function test_RegisterDomain() {
        $params = self::standardFunctionParams();

        $params['regperiod']        = 1;

        $params['ns1']              = 'ns1.centralnic.net';
        $params['ns2']              = 'ns2.centralnic.net';
        $params['ns3']              = 'ns3.centralnic.net';
        $params['ns4']              = 'ns4.centralnic.net';

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

        //
        // check return value from the module function
        //
        $this->doStandardResultChecks(centralnic_RegisterDomain($params));

        //
        // check the state of the domain
        //

        $info = \centralnic\whmcs\plugin::info($params['sld'].'.'.$params['tld']);

        //
        // validate expiry date
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

        $this->assertEquals($params['fullname'],    $registrant->getElementsByTagName('name')->item(0)->textContent, 'registrant name matches');
        $this->assertEquals($params['companyname'], $registrant->getElementsByTagName('org')->item(0)->textContent, 'company name name matches');

        $expectedStreet = [];
        foreach ($params as $k => $v) if (1 == preg_match('/^address\d+$/', $k)) $expectedStreet[] = $v;

        $street = [];
        foreach ($registrant->getElementsByTagName('street') as $el) $street[] = $el->textContent;

        $this->assertEquals($expectedStreet, $street, 'street address matches');

        $this->assertEquals($params['city'],            $registrant->getElementsByTagName('city')->item(0)->textContent, 'city matches');
        $this->assertEquals($params['state'],           $registrant->getElementsByTagName('sp')->item(0)->textContent, 'state/province matches');
        $this->assertEquals($params['postcode'],        $registrant->getElementsByTagName('pc')->item(0)->textContent, 'postcode matches');
        $this->assertEquals($params['country'],         $registrant->getElementsByTagName('cc')->item(0)->textContent, 'country matches');
        $this->assertEquals($params['fullphonenumber'], $registrant->getElementsByTagName('voice')->item(0)->textContent, 'voice matches');
        $this->assertEquals($params['email'],           $registrant->getElementsByTagName('email')->item(0)->textContent, 'email matches');

        //
        // validate contact objects
        //

        $contacts = [];
        foreach ($info->getElementsByTagName('contact') as $el) $contacts[$el->getAttribute('type')] = $el->textContent;

        foreach (['admin', 'tech', 'billing'] as $type) {
            $this->assertArrayHasKey($type, $contacts, "{$type} contact exists");
            $this->assertEquals($registrant_id, $contacts[$type], "{$type} contact matches registrant");
        }
    }

    /**
     * not currently testable
     */
    public function test_TransferDomain() {
        $this->assertTrue(true);
    }

    public function test_RenewDomain() {
        $params = self::standardFunctionParams();

        $params['regperiod'] = 1;

        $this->doStandardResultChecks(centralnic_RenewDomain($params));
    }

    public function test_GetNameservers() {
        $params = self::standardFunctionParams();

        $ns = centralnic_GetNameservers($params);

        $this->assertIsArray($ns);

        for ($i = 1 ; $i <= count($ns) ; $i++) {
            $this->assertEquals($ns[sprintf('ns%u', $i)], sprintf('ns%u.centralnic.net', $i));
        }
    }

    public function test_SaveNameservers() {
        $params = self::standardFunctionParams();

        //
        // set new nameservers
        //
        $params['ns1'] = 'ns1.centralnic.org';
        $params['ns2'] = 'ns2.centralnic.org';
        $params['ns3'] = 'ns3.centralnic.org';

        //
        // check return value from the module function
        //
        $this->doStandardResultChecks(centralnic_SaveNameservers($params));

        $info = \centralnic\whmcs\plugin::info($params['sld'].'.'.$params['tld']);

        $expectedNS = [];
        foreach ($params as $k => $v) if (1 == preg_match('/^ns\d+$/', $k)) $expectedNS[] = strtolower($v);
        sort($expectedNS, SORT_STRING);

        $ns = [];
        foreach ($info->getElementsByTagName('hostObj') as $el) $ns[] = strtolower($el->textContent);
        sort($ns, SORT_STRING);

        $this->assertEquals($expectedNS, $ns, 'nameservers match expected set');
    }

    public function test_GetRegistrarLock() {
        $lock = centralnic_GetRegistrarLock(self::standardFunctionParams());

        $this->assertIsString($lock);
        $this->assertEquals($lock, 'unlocked');
    }

    public function test_AddRegistrarLock() {
        $params = self::standardFunctionParams();

        $params['lockenabled'] = 'locked';

        $this->doStandardResultChecks(centralnic_SaveRegistrarLock($params));
    }

    public function test_RemoveRegistrarLock() {
        $params = self::standardFunctionParams();

        $params['lockenabled'] = 'unlocked';

        $this->doStandardResultChecks(centralnic_SaveRegistrarLock($params));
    }

    public function test_GetContactDetails() {
        $result = centralnic_GetContactDetails(self::standardFunctionParams());

        $this->assertIsArray($result, 'function returned an array');

        foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $k) {
            $this->assertArrayHasKey($k, $result, "return array contains '{$k}' key");
            $this->assertIsArray($result[$k], "'{$k}' key is an array");

            foreach (['Full Name', 'Company Name', 'Address 1', 'Address 2', 'Address 3', 'City', 'State', 'Postcode', 'Country', 'Phone Number', 'Email Address'] as $j) {
                $this->assertArrayHasKey($j, $result[$k], "return array contains '{$j}' key");
                $this->assertIsString($result[$k][$j]);
            }
        }
    }

    public function test_SaveContactDetails() {
        $params = self::standardFunctionParams();

        $contact = [
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

        $params['contactdetails'] = [
            'Registrant'    => $contact,
            'Admin'         => $contact,
            'Technical'     => $contact,
            'Billing'       => $contact,
        ];

        $this->doStandardResultChecks(centralnic_SaveContactDetails($params));
    }

    public function test_GetEPPCode() {
        $result = centralnic_GetEPPCode(self::standardFunctionParams());

        $this->assertIsArray($result, 'function returned an array');
        $this->assertArrayHasKey('eppcode', $result, 'return array contains EPP code');
        $this->assertIsString($result['eppcode'], 'EPP code is a string');
    }

    public function test_RegisterNameserver() {
        $params = self::standardFunctionParams();

        $params['ipaddress'] = '193.105.170.1';

        $this->doStandardResultChecks(centralnic_RegisterNameserver($params));
    }

    public function test_ModifyNameserver() {
        $params = self::standardFunctionParams();

        $params['currentipaddress'] = '193.105.170.1';
        $params['newipaddress']     = '193.105.170.2';

        $this->doStandardResultChecks(centralnic_ModifyNameserver($params));
    }

    public function test_DeleteNameserver() {
        $this->doStandardResultChecks(centralnic_DeleteNameserver(self::standardFunctionParams()));
    }

    public function test_Sync() {
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
    public function test_TransferSync() {
        $this->assertTrue(true);
    }

    /**
     * @dataProvider availabilitySearchDataProvider
     */
    public function test_CheckAvailability(string $sld, string $tld, bool $avail, bool $premium, $registerPrice=NULL, $renewPrice=NULL) {
        $this->assertTrue(true);

        $params = self::standardFunctionParams();

        $params['searchTerm'] = $sld;
        $params['tldsToInclude'] = [$tld];

        $result = centralnic_CheckAvailability($params);

        $this->assertInstanceOf('WHMCS\Domains\DomainLookup\ResultsList', $result);
        $this->assertInstanceOf('WHMCS\Domains\DomainLookup\SearchResult', $result->results[0]);

        $this->assertEquals($result->results[0]->sld, $sld);
        $this->assertEquals($result->results[0]->tld, $tld);

        $this->assertObjectHasProperty('status', $result->results[0]);
        $this->assertEquals($result->results[0]->status, $avail);

        $this->assertObjectHasProperty('premium', $result->results[0]);
        $this->assertEquals($result->results[0]->premium, $premium);

        if (true === $premium) {
            $this->assertObjectHasProperty('pricing', $result->results[0]);
            $this->assertIsArray($result->results[0]->pricing);

            $this->assertArrayHasKey('register', $result->results[0]->pricing);
            $this->assertEquals($result->results[0]->pricing['register'], $registerPrice);

            $this->assertArrayHasKey('renew', $result->results[0]->pricing);
            $this->assertEquals($result->results[0]->pricing['renew'], $renewPrice);
        }
    }

    public function test_RequestDelete() {
        $this->doStandardResultChecks(centralnic_RequestDelete(self::standardFunctionParams()));
    }

    public function test_RequestDeleteForNonExistentDomain() {
        $params = self::standardFunctionParams();

        $params['domain'] = 'this-domain-should-not-exist-'.uniqid().'.'.$params['tld'];

        $result = centralnic_RequestDelete($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertIsBool($result['success']);
        $this->assertFalse($result['success']);

        $this->assertTrue(true);
    }
}
