<?php declare(strict_types=1);

/**
 * CentralNic Registry WHMCS Module
 * Copyright 2023 CentralNic Group PLC. All rights reserved.
 */

use PHPUnit\Framework\TestCase;
use centralnic\whmcs\{plugin,epp};
use centralnic\whmcs\xml\frame;
use WHMCS\Exception\Module\InvalidConfiguration;

class pluginTest extends TestCase {

    const tld               = 'smoketestcnic';
    const currency          = 'USD';
    const registrationFee   = 35.00;
    const renewalFee        = 35.00;

    //
    // these are populated during the course of the test run and will
    // be different each time
    //
    private static string $domain;
    private static string $authInfo;

    private static function getenv(string $name) : string {
        $value = getenv($name);

        if (false === $value || strlen($name) < 1) {
            throw new Exception("Missing or empty '{$name}' environment variable");
        }

        return $value;
    }

    public static function setUpBeforeClass() : void {
        self::$domain = strtolower(__CLASS__.'-'.uniqid()).'.'.self::tld;
        plugin::$debug = true;
    }

    /**
     * this is a data provider allowing us to validate
     * the existence of all the module functions
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
                // not registered, standard
                'sld'       => 'available-domain-'.uniqid(),
                'tld'       => 'uk.com',
                'avail'     => true,
                'premium'   => false,
            ],
            [
                // registered, standard
                'sld'       => 'example',
                'tld'       => 'uk.com',
                'avail'     => false,
                'premium'   => false,
            ],

/* disabled until CN provides alternative test names
            [
                // not registered, premium
                'sld'       => 'aa',
                'tld'       => 'uk.com',
                'avail'     => true,
                'premium'   => true,
                'currency'  => 'GBP',
                'register'  => 99.00,
                'renew'     => 16.25,
            ],
            [
                // registered, premium
                'sld'       => 'ab',
                'tld'       => 'uk.com',
                'avail'     => false,
                'premium'   => true,
                'currency'  => 'GBP',
                'register'  => 99.00,
                'renew'     => 16.25,
            ],
*/

        ];
    }

    /**
     * this returns a set of common function parameters common
     * to all module functions
     */
    public static function standardFunctionParams() : array {
        return [
            'testMode'              => 1,
            'ResellerHandle'        => self::getenv('EPP_CLIENT1_ID') ?? self::getenv('EPP_CLIENT_ID'),
            'ResellerAPIPassword'   => self::getenv('EPP_CLIENT1_PW') ?? self::getenv('EPP_CLIENT_PW'),
            'tld'                   => self::tld,
            'premiumEnabled'        => true,
            'BillingCurrency'       => self::currency,
        ];
    }

    /**
     * return values from plugin functions have a standard structure,
     * which this method tests
     */
    private function doStandardResultChecks($result) {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertIsBool($result['success']);
        $this->assertTrue($result['success']);
    }
 
    /**
     * @dataProvider functionNamesDataProvider
     */
    public function testModuleFunctionsExist($function) {
        $this->assertTrue(function_exists('centralnic_' . $function));
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
    public function testModuleFunctionMethods($function) {
        $this->assertTrue(method_exists('centralnic\whmcs\plugin', $function));
    }

    /**
     * Test the signatures of each method
     *
     * @dataProvider functionNamesDataProvider
     */
    public function testModuleMethodSignatures($function) {

        $ref = new ReflectionMethod('centralnic\whmcs\plugin', $function);

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

        $this->assertEquals($expectedType, $ret->getName());
    }

    public function testMetaData() : void {
        $array = centralnic_MetaData();
        $this->assertIsArray($array);
        $this->assertCount(2, $array);
        $this->assertNotEmpty($array['DisplayName']);
        $this->assertNotEmpty($array['APIVersion']);
    }

    public function testgetConfigArray() : void {
        $array = centralnic_getConfigArray();
        $this->assertIsArray($array);

        $keys = [
            'ResellerHandle',
            'ResellerAPIPassword',
            'testMode',
            'BillingCurrency',
            'ClientCertificate',
            'PrivateKey'
        ];

        $this->assertCount(count($keys), $array);

        foreach ($keys as $k) {
            $this->assertArrayHasKey($k, $array);
            $this->assertIsArray($array[$k]);

            foreach (['Type', 'Description'] as $v) {
                $this->assertArrayHasKey($v, $array[$k]);
                $this->assertIsString($array[$k][$v]);
                $this->assertNotEmpty($array[$k][$v]);
            }
        }
    }

    public function testParams() : void {
        $params = self::standardFunctionParams();

        foreach (['ResellerHandle', 'ResellerAPIPassword'] as $k) {
            $this->assertArrayHasKey($k, $params);
        }
    }

    public function testRegisterDomain() : void {
        $params = self::standardFunctionParams();

        $params['sld']              = substr(self::$domain, 0, strpos(self::$domain, '.'));

        $params['regperiod']        = 1;
        $params['premiumCost']      = self::registrationFee;

        $params['ns1']              = 'ns1.example.invalid';
        $params['ns2']              = 'ns2.example.invalid';
        $params['ns3']              = 'ns3.example.invalid';
        $params['ns4']              = 'ns4.example.invalid';

        $params['fullname']         = 'John Doe';
        $params['companyname']      = 'Example Inc.';
        $params['address1']         = '123 Example Dr';
        $params['address2']         = 'Suite 100';
        $params['city']             = 'Dulles';
        $params['state']            = 'VA';
        $params['postcode']         = '20166-6503';
        $params['country']          = 'US';
        $params['fullphonenumber']  = '+1.7035555555';
        $params['email']            = 'jdoe@example.invalid';

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

        $info = plugin::info(self::$domain);

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

        $this->assertEquals($expectedExDate, $exDate);

        //
        // validate nameservers
        //

        $expectedNS = [];
        foreach ($params as $k => $v) if (1 == preg_match('/^ns\d+$/', $k)) $expectedNS[] = strtolower($v);
        sort($expectedNS, SORT_STRING);

        $ns = [];
        foreach ($info->getElementsByTagName('hostObj') as $el) $ns[] = strtolower($el->textContent);
        sort($ns, SORT_STRING);

        $this->assertEquals($expectedNS, $ns);

        //
        // validate registrant
        //

        $registrant_id = $info->getElementsByTagName('registrant')->item(0)->textContent;
        $registrant = plugin::contactInfo($registrant_id);

        $this->assertEquals($params['fullname'],    $registrant->getElementsByTagName('name')->item(0)->textContent);
        $this->assertEquals($params['companyname'], $registrant->getElementsByTagName('org')->item(0)->textContent);

        $expectedStreet = [];
        foreach ($params as $k => $v) if (1 == preg_match('/^address\d+$/', $k)) $expectedStreet[] = $v;

        $street = [];
        foreach ($registrant->getElementsByTagName('street') as $el) $street[] = $el->textContent;

        $this->assertEquals($expectedStreet, $street);

        $this->assertEquals($params['city'],            $registrant->getElementsByTagName('city')->item(0)->textContent);
        $this->assertEquals($params['state'],           $registrant->getElementsByTagName('sp')->item(0)->textContent);
        $this->assertEquals($params['postcode'],        $registrant->getElementsByTagName('pc')->item(0)->textContent);
        $this->assertEquals($params['country'],         $registrant->getElementsByTagName('cc')->item(0)->textContent);
        $this->assertEquals($params['fullphonenumber'], $registrant->getElementsByTagName('voice')->item(0)->textContent);
        $this->assertEquals($params['email'],           $registrant->getElementsByTagName('email')->item(0)->textContent);

        //
        // validate contact objects
        //

        $contacts = [];
        foreach ($info->getElementsByTagName('contact') as $el) $contacts[$el->getAttribute('type')] = $el->textContent;

        foreach (['admin', 'tech', 'billing'] as $type) {
            $this->assertArrayHasKey($type, $contacts);
            $this->assertEquals($registrant_id, $contacts[$type]);
        }
    }

    public function testRenewDomain() : void {
        $params = self::standardFunctionParams();

        $params['domain']           = self::$domain;
        $params['regperiod']        = 1;
        $params['premiumCost']      = self::renewalFee;

        $this->doStandardResultChecks(centralnic_RenewDomain($params));
    }

    public function testGetNameservers() : void {
        $params = self::standardFunctionParams();

        $params['domain'] = self::$domain;

        $ns = centralnic_GetNameservers($params);

        $this->assertIsArray($ns);

        for ($i = 1 ; $i <= count($ns) ; $i++) {
            $this->assertEquals(sprintf('ns%u.example.invalid', $i), $ns[sprintf('ns%u', $i)]);
        }
    }

    public function testSaveNameservers() : void {
        $params = self::standardFunctionParams();

        $params['domain'] = self::$domain;

        //
        // set new nameservers
        //
        $params['ns1'] = 'ns1.example2.invalid';
        $params['ns2'] = 'ns2.example2.invalid';
        $params['ns3'] = 'ns3.example2.invalid';

        //
        // check return value from the module function
        //
        $this->doStandardResultChecks(centralnic_SaveNameservers($params));

        $expectedNS = [];
        foreach ($params as $k => $v) if (1 == preg_match('/^ns\d+$/', $k)) $expectedNS[] = strtolower($v);
        sort($expectedNS, SORT_STRING);

        $info = plugin::info($params['domain']);

        $actualNS = [];
        foreach ($info->getElementsByTagName('hostObj') as $el) $actualNS[] = strtolower($el->textContent);
        sort($actualNS, SORT_STRING);

        $this->assertEquals($expectedNS, $actualNS);
    }

    public function testGetRegistrarLock() : void {
        $params = self::standardFunctionParams();

        $params['domain'] = self::$domain;

        $lock = centralnic_GetRegistrarLock($params);

        $this->assertIsString($lock);
        $this->assertEquals('unlocked', $lock);
    }

    public function testAddRegistrarLock() : void {
        $params = self::standardFunctionParams();

        $params['domain']       = self::$domain;
        $params['lockenabled']  = 'locked';

        $this->doStandardResultChecks(centralnic_SaveRegistrarLock($params));
    }

    public function testRemoveRegistrarLock() : void {
        $params = self::standardFunctionParams();

        $params['domain']       = self::$domain;
        $params['lockenabled']  = 'unlocked';

        $this->doStandardResultChecks(centralnic_SaveRegistrarLock($params));
    }

    public function testGetContactDetails() : void {
        $params = self::standardFunctionParams();

        $params['domain'] = self::$domain;

        $result = centralnic_GetContactDetails($params);

        $this->assertIsArray($result);

        foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $k) {
            $this->assertArrayHasKey($k, $result);
            $this->assertIsArray($result[$k]);

            foreach (['Full Name', 'Company Name', 'Address 1', 'Address 2', 'Address 3', 'City', 'State', 'Postcode', 'Country', 'Phone Number', 'Email Address'] as $j) {
                $this->assertArrayHasKey($j, $result[$k]);
                $this->assertIsString($result[$k][$j]);
            }
        }
    }

    public function testSaveContactDetails() : void {
        $params = self::standardFunctionParams();

        $params['domain'] = self::$domain;

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

    public function testGetEPPCode() : void {
        $params = self::standardFunctionParams();

        $params['domain'] = self::$domain;

        $result = centralnic_GetEPPCode($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('eppcode', $result);
        $this->assertIsString($result['eppcode']);
        $this->assertNotEmpty($result['eppcode']);

        self::$authInfo = $result['eppcode'];
    }

    public function testRegisterNameserver() : void {
        $params = self::standardFunctionParams();

        $params['nameserver']   = 'ns0.'.self::$domain;
        $params['ipaddress']    = '193.105.170.1';

        $this->doStandardResultChecks(centralnic_RegisterNameserver($params));
    }

    public function testModifyNameserver() : void {
        $params = self::standardFunctionParams();

        $params['nameserver']   = 'ns0.'.self::$domain;
        $params['currentipaddress'] = '193.105.170.1';
        $params['newipaddress']     = '193.105.170.2';

        $this->doStandardResultChecks(centralnic_ModifyNameserver($params));
    }

    public function testDeleteNameserver() : void {
        $params = self::standardFunctionParams();

        $params['nameserver'] = 'ns0.'.self::$domain;

        $this->doStandardResultChecks(centralnic_DeleteNameserver($params));
    }

    public function testSync() : void {
        $params = self::standardFunctionParams();
        $params['domain'] = self::$domain;

        $result = centralnic_Sync($params);

        $this->assertIsArray($result);

        foreach (['expirydate', 'active', 'expired', 'transferredAway'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['expirydate']);

        $this->assertIsBool($result['active']);
        $this->assertTrue($result['active']);

        $this->assertIsBool($result['expired']);
        $this->assertFalse($result['expired']);

        $this->assertIsBool($result['transferredAway']);
        $this->assertFalse($result['transferredAway']);
    }

    public function testTransferDomain() : void {
        $params = self::standardFunctionParams();

        //
        // force the current connection to be shut down
        //
        plugin::forceDisconnect();

        //
        // use the second set of credentials to transfer the domain
        //
        $params['ResellerHandle']       = self::getenv('EPP_CLIENT2_ID');
        $params['ResellerAPIPassword']  = self::getenv('EPP_CLIENT2_PW');

        $params['domain']               = self::$domain;
        $params['eppcode']              = self::$authInfo;
        $params['regperiod']            = 1;
        $params['premiumCost']          = self::renewalFee;

        $result = centralnic_TransferDomain($params);

        plugin::forceDisconnect();

        $this->doStandardResultChecks($result);
    }

    private static function waitForNewMessage(array $params) : void {
        $frame = new frame;
        $poll = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create('poll'));

        $poll->setAttribute('op', 'req');

        $count = null;
        $t0 = hrtime(true);
        while (true) {
            $dt = (hrtime(true) - $t0) / 1_000_000_000;

            if ($dt >= 120) break;

            $response = plugin::getConnection($params)->request($frame);
            $msgQ = $response->getElementsByTagNameNS(epp::xmlns, 'msgQ')->item(0);

            if ($msgQ instanceof DOMElement) {
                $newCount = (int)$msgQ->getAttribute('count');
                if (is_null($count)) {
                    $count = $newCount;

                } elseif ($newCount > $count) {
                    break;

                }
            }

            sleep(1);
        }

        return;
    }

    public function testTransferSync() : void {
        $params = self::standardFunctionParams();
        $params['domain'] = self::$domain;

        self::waitForNewMessage($params);
        
        $result = centralnic_TransferSync($params);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('completed', $result);
        $this->assertIsBool($result['completed']);
        $this->assertFalse($result['completed']);

        //
        // reject the transfer so we can delete the domain
        //
        $frame = new frame;
        $transfer = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp:: transfer))
                            ->add($frame->nsCreate(epp::xmlns_domain, epp::transfer));

        $transfer->parentNode->setAttribute('op', 'reject');

        $transfer->add($frame->create('name', $params['domain']));

        plugin::getConnection()->request($frame);

        plugin::forceDisconnect();

        $params['ResellerHandle']       = self::getenv('EPP_CLIENT2_ID');
        $params['ResellerAPIPassword']  = self::getenv('EPP_CLIENT2_PW');

        self::waitForNewMessage($params);

        plugin::forceDisconnect();
    }

    public function testRequestDelete() : void {
        $params = self::standardFunctionParams();

        $params['domain'] = self::$domain;

        $this->doStandardResultChecks(centralnic_RequestDelete($params));
    }

    public function testRequestDeleteForNonExistentDomain() : void {
        $params = self::standardFunctionParams();

        $params['domain'] = 'this-domain-should-not-exist-'.uniqid().'.'.$params['tld'];

        $result = centralnic_RequestDelete($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertIsBool($result['success']);
        $this->assertFalse($result['success']);

        $this->assertTrue(true);
    }

    /**
     * @dataProvider availabilitySearchDataProvider
     */
    public function testCheckAvailability(
        string  $sld,
        string  $tld,
        bool    $avail,
        bool    $premium,
        ?string $currency=NULL,
        ?float  $registerPrice=NULL,
        ?float  $renewPrice=NULL
    ) : void {
        $this->assertTrue(true);

        $params = self::standardFunctionParams();

        $params['searchTerm'] = $sld;
        $params['tldsToInclude'] = [$tld];

        if (!is_null($currency)) $params['BillingCurrency'] = $currency;

        $result = centralnic_CheckAvailability($params);

        $this->assertInstanceOf('WHMCS\Domains\DomainLookup\ResultsList', $result);
        $this->assertInstanceOf('WHMCS\Domains\DomainLookup\SearchResult', $result->results[0]);

        $this->assertEquals($sld, $result->results[0]->sld);
        $this->assertEquals($tld, $result->results[0]->tld);

        $this->assertObjectHasProperty('status', $result->results[0]);
        $this->assertEquals($avail, $result->results[0]->status);

        $this->assertObjectHasProperty('premium', $result->results[0]);
        $this->assertEquals($premium, $result->results[0]->premium);

        if (true === $premium) {
            $this->assertObjectHasProperty('pricing', $result->results[0]);
            $this->assertIsArray($result->results[0]->pricing);

            $this->assertArrayHasKey('register', $result->results[0]->pricing);
            $this->assertEquals($registerPrice, $result->results[0]->pricing['register']);

            $this->assertArrayHasKey('renew', $result->results[0]->pricing);
            $this->assertEquals($renewPrice, $result->results[0]->pricing['renew']);
        }
    }

    public static function configurationProvider(): array {
        $std = self::standardFunctionParams();

        return [
            [
                [],
                false,
            ],
            [
                $std,
                true,
            ],
            [
                array_merge($std, ['ResellerAPIPassword' => 'bogusPassword']),
                false,
            ],
        ];
    }

    /**
     * @dataProvider configurationProvider
     */
    public function testConfigValidate(array $config, bool $valid): void {
        if (!$valid) $this->expectException(InvalidConfiguration::class);

        try {
            centralnic_config_validate($config);

        } catch (Throwable $e) {
            throw $e;

        }

        $this->assertTrue(true);
    }

    public function testServerName(): void {
        $this->assertEquals(plugin::prod_host, plugin::serverName([]));

        putenv('EPP_SERVER_NAME=');

        $name = 'epp.invalid';
        $this->assertEquals($name, plugin::serverName(['eppServer' => $name]));

        putenv('EPP_SERVER_NAME='.$name);
        $this->assertEquals($name, plugin::serverName([]));

        putenv('EPP_SERVER_NAME=');
    }
}
