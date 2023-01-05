<?php

/**
 * CentralNic Registry WHMCS Module
 * Copyright 2023 CentralNic Group PLC. All rights reserved.
 */

declare(strict_types=1);

namespace centralnic\whmcs;

require __DIR__.'/error.php';
require __DIR__.'/epp.php';
require __DIR__.'/frame.php';

/**
 * this "magic" function avoids lots of boilerplate in the plugin file by
 * dynamically calling the appropriate static method with the provided arguments
 * so centralnic_FooBar($params) => \centralnic\whmcs\plugin::FooBar($params)
 */
function magic() {
    //
    // get a backtrace. we only want the top 2 entries
    //
    $bt = debug_backtrace(0, 2);

    //
    // this is what called us
    //
    $caller = $bt[1];

    //
    // this is the arguments passed to the caller
    //
    $args = $caller['args'];

    //
    // these are the components of the callback
    //
    $class  = __NAMESPACE__.'\plugin';
    $method = substr($caller['function'], 11);

    //
    // ensure the 'domain' parameter is always set, if the "sld" and "tld" parameters are available
    //
    if (isset($args[0]) && is_array($args[0]) && isset($args[0]['sld']) && isset($args[0]['tld']) && !isset($args[0]['domain'])) {
        $args[0]['domain'] = $args[0]['sld'].'.'.$args[0]['tld'];
    }

    try {
        //
        // call the function and pass back its return value
        //
        return call_user_func_array([$class, $method], $args);

    } catch (error $e) {
        //
        // return an error to the caller
        //
        return [
            'success'   => false,
            'error'     => $e->getMessage(),
        ];
    }
}

/**
 * this class holds the actual business logic
 */
final class plugin {

    const registry_domain   = 'centralnic.com';
	const prod_host         = 'epp.'.self::registry_domain;
	const test_host         = 'epp-ote.'.self::registry_domain;

    //
    // the $debug property of the EPP connection object is a
    // reference to this property, so that it's easy to enable/disable
    // debugging without giving access to the EPP connection object
    //
    public static bool $debug = false;

    //
    // EPP connection object
    //
    private static epp $epp;

    /**
     * this maps low-level contact types to human-readable descriptions used
     * in GetContactDetails() and SaveContactDetails()
     */
    private static array $contactTypeMap = [
        'registrant'    => 'Registrant',
        'admin'         => 'Admin',
        'tech'          => 'Technical',
        'billing'       => 'Billing',
    ];

    /**
     * return plugin metadata
     */
    public static function MetaData() : array {
        return array(
            'DisplayName'   => 'CentralNic Registry EPP Registrar Module for WHMCS',
            'APIVersion'    => '1.1',
        );
    }

    /**
     * Called when WHMCS needs to know what configuration options are needed.
     * This plugin is backwards compatible with the old plugin so uses the same
     * config parameters.
     */
    public static function getConfigArray() : array {
    	return [
    		'ResellerHandle' => [
                'Type'          => 'text',
                'Size'          => 64,
                'Description'   => 'Your CentralNic Registrar ID.'
            ],

    		'ResellerAPIPassword' => [
                'Type'          => 'password',
                'Size'          => 64,
                'Description'   => 'Your EPP password.',
            ],

    		'testMode' => [
                'Type'          => 'text',
                'Size'          => 1,
                'Default'       => '1',
                'Description'   => 'Set this to 1 to use the OT&E environment, and 0 to use the production environment.'
            ],

            'BillingCurrency' => [
                'Type'          => 'text',
                'Size'          => 3,
                'Default'       => 'USD',
                'Description'   => 'The currency to use for fees and pricing.'
            ]
        ];
    }

    /**
     * Called when the registration of a new domain is initiated within WHMCS.
     * will create new contacts as needed then the domain
     */
    public static function RegisterDomain(array $params) : array {
        $epp = self::connection($params);

        //
        // create contact objects
        //
        $contacts = [];
        foreach (array_keys(self::$contactTypeMap) as $type) {
            $prefix = ('registrant' == $type ? '' : $type);

            if (!isset($params[$prefix.'email'])) {
                $contacts[$type] = $contacts['registrant'];

            } else {
                $contacts[$type] = self::createContact([
                    'name'      => $params[$prefix.'fullname'],
                    'org'       => $params[$prefix.'companyname'] ?? NULL,
                    'street'    => [
                        $params[$prefix.'address1'] ?? NULL,
                        $params[$prefix.'address2'] ?? NULL,
                        $params[$prefix.'address3'] ?? NULL,
                    ],
                    'city'      => $params[$prefix.'city'],
                    'sp'        => $params[$prefix.'state'] ?? NULL,
                    'pc'        => $params[$prefix.'postcode'] ?? NULL,
                    'cc'        => $params[$prefix.'country'],
                    'voice'     => $params[$prefix.'fullphonenumber'] ?? NULL,
                    'email'     => $params[$prefix.'email'],
                ]);
            }
        }

        //
        // construct <create> frame
        //

        $frame = new xml\frame;

        $create = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::create))
                            ->add($frame->nsCreate(epp::xmlns_domain, epp::create));

        //
        // add basic information
        //

        $create->add($frame->create('name', $params['domain']));

        $create->add($frame->create('period', strval($params['regperiod'])))
            ->setAttribute('unit', 'y');

        //
        // add nameservers
        //
        $ns = $create->add($frame->create('ns'));
        for ($i = 1 ; $i <= 5 ; $i++) {
            $k = sprintf('ns%u', $i);
            if (isset($params[$k]) && strlen($params[$k]) > 0) {
                $ns->add($frame->create('hostObj', $params[$k]));
            }
        }
        if ($ns->childNodes->length < 1) $create->removeChild($ns);

        //
        // add registrant and other contacts
        //

        $create->add($frame->create('registrant', $contacts['registrant']));

        foreach (preg_grep('/^registrant$/', array_keys(self::$contactTypeMap), PREG_GREP_INVERT) as $type) {
            $create->add($frame->create('contact', $contacts[$type] ?? $contacts['registrant']))
                ->setAttribute('type', $type);
        }

        //
        // add authInfo
        //
        $create->add($frame->create('authInfo'))->add($frame->create('pw', self::generateAuthInfo()));

        //
        // add premium information if provided
        //
        if (isset($params['premiumEnabled']) && true === $params['premiumEnabled'] && isset($params['premiumCost'])) {
            $extension = $frame->first('command')->add($frame->create('extension'));
            $fee = $extension->add($frame->nsCreate(epp::xmlns_fee, epp::create));
            $fee->add($frame->create('currency', $params['BillingCurrency']));
            $fee->add($frame->create('fee', strval($params['premiumCost'])));
        }

        $epp->request($frame);

        return self::success();
    }

    /**
     * Called when a domain transfer request is initiated within WHMCS.
     */
    public static function TransferDomain(array $params) : array {
        $epp = self::connection($params);

        $frame = new xml\frame;

        $transfer = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp:: transfer))
                            ->add($frame->nsCreate(epp::xmlns_domain, epp::transfer));

        $transfer->parentNode->setAttribute('op', 'request');

        $transfer->add($frame->create('name', $params['domain']));

        $transfer->add($frame->create('period', strval($params['regperiod'])))
            ->setAttribute('unit', 'y');

        $transfer->add($frame->create('authInfo'))
            ->add($frame->create('pw', $params['eppcode']));

        if (isset($params['premiumEnabled']) && true === $params['premiumEnabled'] && isset($params['premiumCost'])) {
            $extension = $frame->first('command')->add($frame->create('extension'));
            $fee = $extension->add($frame->nsCreate(epp::xmlns_fee, epp::transfer));
            $fee->add($frame->create('currency', $params['BillingCurrency']));
            $fee->add($frame->create('fee', strval($params['premiumCost'])));
        }

        $epp->request($frame);

        return self::success();
    }

    /**
     * Called when a request to renew a domain is initiated within WHMCS.
     */
    public static function RenewDomain(array $params) : array {
        $epp = self::connection($params);

        //
        // get the domain's current expiry date
        //
        $info = self::info($params['domain']);

        $frame = new xml\frame;

        $renew = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::renew))
                            ->add($frame->nsCreate(epp::xmlns_domain, epp::renew));

        $renew->add($frame->create('name', $params['domain']));

        $renew->add($frame->create('curExpDate', gmdate('Y-m-d', strtotime($info->first('exDate')->textContent))));

        $renew->add($frame->create('period', strval($params['regperiod'])))
            ->setAttribute('unit', 'y');

        if (isset($params['premiumEnabled']) && true === $params['premiumEnabled'] && isset($params['premiumCost'])) {
            $extension = $frame->first('command')->add($frame->create('extension'));
            $fee = $extension->add($frame->nsCreate(epp::xmlns_fee, epp::renew));
            $fee->add($frame->create('currency', $params['BillingCurrency']));
            $fee->add($frame->create('fee', strval($params['premiumCost'])));
        }

        $epp->request($frame);

        return self::success();
    }

    /**
     * Called when a domain is viewed within WHMCS. It can return up to 5
     * nameservers that are set for the domain.
     */
    public static function GetNameservers(array $params) : array {
        $epp = self::connection($params);

        $info = self::info($params['domain']);

        $i = 0;
        $ns = [];
        foreach ($info->get('hostObj') as $host) $ns[sprintf('ns%u', ++$i)] = $host->textContent;

        return $ns;
    }

    /**
     * Called when a change is submitted for a domains nameservers.
     */
    public static function SaveNameservers(array $params) : array {
        $epp = self::connection($params);

        $info = self::info($params['domain']);

        $frame = new xml\frame;

        $update = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::update))
                            ->add($frame->nsCreate(epp::xmlns_domain, epp::update));

        $update->add($frame->create('name', $params['domain']));

        $add = $update->add($frame->create('add'));
        $rem = $update->add($frame->create('rem'));

        $add_ns = $add->add($frame->create('ns'));
        for ($i = 1 ; $i <= 5 ; $i++) {
            if (!isset($params["ns{$i}"])) {
                break;

            } else {
                $add_ns->add($frame->create('hostObj', $params["ns{$i}"]));

            }
        }

        $rem_ns = $rem->add($frame->create('ns'));
        foreach ($info->get('hostObj') as $host) {
            $rem_ns->add($frame->create('hostObj', $host->textContent));
        }

        if ($add_ns->childNodes->length < 1) $update->removeChild($add);
        if ($rem_ns->childNodes->length < 1) $update->removeChild($rem);

        $epp->request($frame);

        return self::success();
    }

    /**
     * Called when a domains details are viewed within WHMCS. It should return
     * the current lock status of a domain.
     */
    public static function GetRegistrarLock(array $params) : string {
        $epp = self::connection($params);

        $info = self::info($params['domain']);

        foreach ($info->get('status') as $el) {
            if ('clientTransferProhibited' == $el->getAttribute('s')) {
                return 'locked';
            }
        }

        return 'unlocked';
    }

    /**
     * Called when the lock status setting is toggled within WHMCS.
     */
    public static function SaveRegistrarLock(array $params) : array {
        $epp = self::connection($params);

        $locked = ('locked' == self::GetRegistrarLock($params));
        $lock_wanted = isset($params['lockenabled']) && 'locked' == $params['lockenabled'];

        if (($locked && !$lock_wanted) || (!$locked && $lock_wanted)) {
            $frame = new xml\frame;

            $update = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                        ->add($frame->create(epp::command))
                            ->add($frame->create(epp::update))
                                ->add($frame->nsCreate(epp::xmlns_domain, epp::update));

            $update->add($frame->create('name', $params['domain']));

            $update->add($frame->create($lock_wanted ? 'add' : 'rem'))
                ->add($frame->create('status'))
                    ->setAttribute('s', 'clientTransferProhibited');

            $epp->request($frame);
        }

        return self::success();
    }

    /**
     * Called when the WHOIS information is displayed within WHMCS.
     */
    public static function GetContactDetails(array $params) : array {
        $epp = self::connection($params);

        $info = self::info($params['domain']);

        $contacts = [ $info->first('registrant')->textContent => ['registrant'] ];
        foreach ($info->get('contact') as $contact) {
            if (!isset($contacts[$contact->textContent])) $contacts[$contact->textContent] = [];
            $contacts[$contact->textContent][] = $contact->getAttribute('type');
        }

        $cinfo = [];
        foreach (array_keys($contacts) as $id) $cinfo[$id] = self::contactInfo($id);

        $response = [];

        foreach (self::$contactTypeMap as $type => $name) {
            foreach ($contacts as $id => $roles) {
                if (in_array($type, $roles) && isset($cinfo[$id])) {
                    $postalInfo = [];
                    foreach ($cinfo[$id]->get('postalInfo') as $el) $postalInfo[$el->getAttribute('type')] = $el;
                    $pinfo = $postalInfo['int'] ?? $postalInfo['loc'];

                    $response[$name] = [
                        'Full Name'     => $pinfo->first('name')->textContent,
                        'Company Name'  => $pinfo->first('org')?->textContent,
                        'Address 1'     => $pinfo->first('street')?->textContent ?? '',
                        'Address 2'     => $pinfo->first('street')?->textContent ?? '',
                        'Address 3'     => $pinfo->first('street')?->textContent ?? '',
                        'City'          => $pinfo->first('city')->textContent,
                        'State'         => $pinfo->first('sp')?->textContent ?? '',
                        'Postcode'      => $pinfo->first('pc')?->textContent ?? '',
                        'Country'       => $pinfo->first('cc')->textContent,
                        'Phone Number'  => $cinfo[$id]->first('voice')?->textContent ?? '',
                        'Fax Number'    => $cinfo[$id]->first('fax')?->textContent ?? '',
                        'Email Address' => $cinfo[$id]->first('email')->textContent,
                    ];

                    break;
                }
            }
        }

        return $response;
    }

    /**
     * Called when revised WHOIS information is submitted.
     */
    public static function SaveContactDetails(array $params) : array {
        $epp = self::connection($params);

        //
        // create new contact objects
        //
        $new = [];
        foreach (self::$contactTypeMap as $type => $name) {
            if (isset($params['contactdetails'][$name])) {
                $new[$type] = self::createContact([
                    'name'      => $params['contactdetails'][$name]['Full Name'],
                    'org'       => $params['contactdetails'][$name]['Company Name'],
                    'street'    => [
                        $params['contactdetails'][$name]['Address 1'],
                        $params['contactdetails'][$name]['Address 2'],
                        $params['contactdetails'][$name]['Address 3'],
                    ],
                    'city'      => $params['contactdetails'][$name]['City'],
                    'sp'        => $params['contactdetails'][$name]['State'],
                    'pc'        => $params['contactdetails'][$name]['Postcode'],
                    'cc'        => $params['contactdetails'][$name]['Country'],
                    'voice'     => $params['contactdetails'][$name]['Phone Number'],
                    'fax'       => $params['contactdetails'][$name]['Fax Number'],
                    'email'     => $params['contactdetails'][$name]['Email Address'],
                ]);
            }
        }

        //
        // build <update> frame
        //
        $frame = new xml\frame;

        $update = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::update))
                            ->add($frame->nsCreate(epp::xmlns_domain, epp::update));

        $update->add($frame->create('name', $params['domain']));

        //
        // add <add> element for new contacts
        //
        $add = $update->add($frame->create('add'));
        foreach (preg_grep('/^registrant$/', array_keys(self::$contactTypeMap), PREG_GREP_INVERT) as $type) {
            if (isset($new[$type])) {
                $add->add($frame->create('contact', $new[$type]))->setAttribute('type', $type);
            }
        }
        if ($add->childNodes->length < 1) $update->removeChild($add);

        //
        // get current domain info
        //
        $info = self::info($params['domain']);

        //
        // this tracks contact objects being removed from the domain
        //
        $old = [];

        //
        // specify contacts being removed
        //
        $rem = $update->add($frame->create('rem'));
        foreach ($info->get('contact') as $el) {
            $type = $el->getAttribute('type');

            if (isset($new[$type])) {
                $rem->add($frame->create('contact', $el->textContent))
                    ->setAttribute('type', $type);

                $old[] = $el->textContent;
            }
        }
        if ($rem->childNodes->length < 1) $update->removeChild($rem);

        if (isset($new['registrant'])) {
            $old[] = $info->first('registrant')->textContent;

            $update->add($frame->create('chg'))
                ->add($frame->create('registrant', $new['registrant']));
        }

        //
        // if the <update> element only contains the <name> element, we're not making
        // any changes, so don't bother sending the frame to the server
        //
        if ($update->childNodes->length > 1) $epp->request($frame);

        //
        // delete any of the old contacts that are unlinked
        //
        foreach (array_unique($old) as $id) {
            try {
                $linked = false;
                $info = self::contactInfo($id);
                foreach ($info->get('status') as $el) {
                    if ('linked' == $el->getAttribute('s')) {
                        $linked = true;
                        break;
                    }
                }

                if (!$linked) {
                    $frame = new xml\frame;

                    $delete = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                                ->add($frame->create(epp::command))
                                    ->add($frame->create(epp::delete))
                                        ->add($frame->nsCreate(epp::xmlns_contact, epp::delete));

                    $delete->add($frame->create('id', $id));

                    $epp->request($frame);
                }

            } catch (error $e) {
                // we ignore these

            }
        }

        return self::success();
    }

    /**
     * Called when the EPP Code is requested for a transfer out.
     */
    public static function GetEPPCode(array $params) : array {
        $epp = self::connection($params);

        $frame = new xml\frame;

        $update = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::update))
                            ->add($frame->nsCreate(epp::xmlns_domain, epp::update));

        $update->add($frame->create('name', $params['domain']));

        $pw = self::generateAuthInfo();

        $update->add($frame->create('chg'))
                    ->add($frame->create('authInfo'))
                        ->add($frame->create('pw', $pw));

        $epp->request($frame);

        return ['eppcode' => $pw];
    }

    /**
     * Called when a child nameserver registration request comes from WHMCS.
     */
    public static function RegisterNameserver(array $params) : array {

        $epp = self::connection($params);

        $frame = new xml\frame;

        $create = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::create))
                            ->add($frame->nsCreate(epp::xmlns_host, epp::create));

        $create->add($frame->create('name', $params['nameserver']));
        $create->add($frame->create('addr', $params['ipaddress']))
            ->setAttribute('ip', 4 == strlen(inet_pton($params['ipaddress'])) ? 'v4' : 'v6');

        $epp->request($frame);

        return self::success();
    }

    /**
     * Called when a child nameserver modification request comes from WHMCS.
     */
    public static function ModifyNameserver(array $params) : array {

        $epp = self::connection($params);

        $frame = new xml\frame;

        $create = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::update))
                            ->add($frame->nsCreate(epp::xmlns_host, epp::update));

        $create->add($frame->create('name', $params['nameserver']));

        if (isset($params['newipaddress'])) {
            $create->add($frame->create('add'))
                ->add($frame->create('addr', $params['newipaddress']))
                    ->setAttribute('ip', 4 == strlen(inet_pton($params['newipaddress'])) ? 'v4' : 'v6');
        }

        if (isset($params['currentipaddress'])) {
            $create->add($frame->create('rem'))
                ->add($frame->create('addr', $params['currentipaddress']))
                    ->setAttribute('ip', 4 == strlen(inet_pton($params['currentipaddress'])) ? 'v4' : 'v6');
        }

        $epp->request($frame);

        return self::success();
    }

    /**
     * Called when a child nameserver deletion request comes from WHMCS.
     */
    public static function DeleteNameserver(array $params) : array {
        $epp = self::connection($params);

        $frame = new xml\frame;

        $create = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::delete))
                            ->add($frame->nsCreate(epp::xmlns_host, epp::delete));

        $create->add($frame->create('name', $params['nameserver']));

        $epp->request($frame);

        return self::success();
    }

    /**
     * Called when a domain name’s expiry date and status change at registry level is
     * requested for propagation into WHMCS. (i.e by the Domain Syncronisation feature).
     */
    public static function Sync(array $params) : array {

        $epp = self::connection($params);

        $info = self::info($params['domain']);

        return [
            'expirydate'        => gmdate('Y-m-d', strtotime($info->first('exDate')->textContent)),
            'active'            => true,
            'expired'           => false,
            'transferredAway'   => ($params['ResellerHandle'] != $info->first('clID')->textContent),
        ];
    }

    /**
     * Called when a domain deletion request comes from WHMCS.
     */
    public static function RequestDelete(array $params) : array {
        $epp = self::connection($params);

        $frame = new xml\frame;

        $create = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::delete))
                            ->add($frame->nsCreate(epp::xmlns_domain, epp::delete));

        $create->add($frame->create('name', $params['domain']));

        $epp->request($frame);

        return self::success();
    }

    /**
     * Called when the status of a domain transfer at registry level is
     * requested for propagation into WHMCS. (i.e by the Domain Syncronisation
     * feature).
     */
    public static function TransferSync(array $params) : array {
        $epp = self::connection($params);

        $frame = new xml\frame;

        $transfer = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp:: transfer))
                            ->add($frame->nsCreate(epp::xmlns_domain, epp::transfer));

        $transfer->parentNode->setAttribute('op', 'query');

        $transfer->add($frame->create('name', $params['domain']));

        $response = $epp->request($frame);

        $trStatus = $response->first('trStatus')->textContent;
        switch ($trStatus) {
            case 'serverApproved':
            case 'clientApproved':
                return [
                    'completed'     => true,
                    'expirydate'    => gmdate('Y-m-d', strtotime($response->first('exDate')->textContent)),
                ];

            case 'clientRejected':
            case 'clientCancelled':
            case 'serverCancelled':
                return [
                    'failed'    => true,
                    'reason'    => $trStatus,
                ];

            case 'pending':
                return [];
        }
    }

    public static function CheckAvailability(array $params) : \WHMCS\Domains\DomainLookup\ResultsList {
        $epp = self::connection($params);

        $frame = new xml\frame;
        
        $check = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create('check'))
                            ->add($frame->nsCreate(epp::xmlns_domain, 'check'));

        if (isset($params['premiumEnabled']) && true === $params['premiumEnabled']) {
            $extension = $frame->first('command')->add($frame->create('extension'));
            $fee = $extension->add($frame->nsCreate(epp::xmlns_fee, 'check'));
        }

        foreach ($params['tldsToInclude'] as $tld) {
            $name = ($params['punyCodeSearchTerm'] ?? $params['searchTerm']) . '.' . $tld;

            $check->add($frame->create('name', $name));

            if ($fee) {
                foreach ([epp::create, epp::renew] as $type) {
                    $domain = $fee->add($frame->create('domain'));
                    $domain->add($frame->create('name',     $name));
                    $domain->add($frame->create('currency', $params['BillingCurrency']));
                    $domain->add($frame->create('command',  $type));
                    $domain->add($frame->create('period',   '1'))->setAttribute('unit', 'y');
                }
            }
        }

        $response = $epp->request($frame);

        $data = [];

        foreach ($response->get('cd') as $cd) {
            if ($cd->namespaceURI == epp::xmlns_domain) {
                $data[$cd->firstChild->textContent] = [
                    'available' => (1 == $cd->firstChild->getAttribute('avail')),
                    'premium'   => false,
                ];

            } elseif ($cd->namespaceURI == epp::xmlns_fee) {
                $fee = [];
                foreach ($cd->childNodes as $el) $fee[$el->localName] = $el->textContent;
                if ('premium' == $fee['class']) $data[$fee['name']]['premium'] = true;
                $data[$fee['name']][$fee['command']] = $fee['fee'];

            }
        }

        $results = new \WHMCS\Domains\DomainLookup\ResultsList;

        foreach ($data as $domain => $info) {
            list($sld, $tld) = explode('.', $domain, 2);

            $searchResult = new \WHMCS\Domains\DomainLookup\SearchResult($sld, $tld);

            if ($info['available']) {
                $searchResult->setStatus(STATUS_NOT_REGISTERED);

            } else {
                $searchResult->setStatus(STATUS_REGISTERED);

            }

            if ($info['premium']) {
                $searchResult->setPremiumDomain(true);
                $searchResult->setPremiumCostPricing([
                    'register'      => $info[epp::create],
                    'renew'         => $info[epp::renew],
                    'CurrencyCode'  => $params['BillingCurrency'],
                ]);
            }

            $results->append($searchResult);
        }        

        return $results;
    }

    /**
     *
     * helper functions from this point onwards!
     *
     */

    /**
     * get the connection to the EPP server
     * if no $params is provided, the existing connection will be returned
     */
    public static function connection(array $params=NULL) : epp {
        if (!isset(self::$epp)) self::$epp = new epp(
            1 == $params['testMode'] ? self::test_host : self::prod_host,
            $params['ResellerHandle'],
            $params['ResellerAPIPassword']
        );

        self::$epp->debug = &self::$debug;

        return self::$epp;
    }

    /**
     * generate an authInfo code that meets the known server requirements
     */
    private static function generateAuthInfo() : string {
        // this contains all the characters that may appear in the authInfo code, grouped by "type"
        $sets = [
            range(0, 9),
            str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
            str_split('abcdefghijklmnopqrstuvwxyz'),
            str_split('!$%^&*()-_=+[]{};:@#~,./<>?'),
        ];

        $pw = [];

        // ensure at least one character of each type is present in the password
        for ($i = 0 ; $i < count($sets) ; $i++) $pw[] = $sets[$i][random_int(0, count($sets[$i])-1)];

        // pad the password out using random characters from random sets
        while (count($pw) < 48) {
            $i = random_int(0, count($sets)-1);
            $pw[] = $sets[$i][random_int(0, count($sets[$i])-1)];
        }

        // shuffle and join
        shuffle($pw);
        return implode('', $pw);
    }

    /**
     * create a contact object using the provided parameters.
     * $params has the following structure:
     *
     *  $params = [
     *      'name' => 'John Doe',
     *      'org' => 'Example Inc.',
     *      'street' => [
     *          '123 Example Dr.',
     *          'Suite 100',
     *      ]
     *      'city' => 'Dulles',
     *      'sp' => 'VA',
     *      'pc' => '20166-6503',
     *      'cc' => 'US',
     *      'voice' => +1.7035555555x1234',
     *      'fax' => '+1.7035555556',
     *      'email' => 'jdoe@example.com',
     *  ];
     *
     * the 'org', 'street', 'sp', 'pc', 'voice' and 'fax' properties are OPTIONAL.
     * 
     * returns the ID of the contact that was created.
     *
     */
    private static function createContact(array $params) : string {
        $frame = new xml\frame;

        $id = self::generateContactID();

        $create = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create(epp::create))
                            ->add($frame->nsCreate(epp::xmlns_contact, epp::create));

        $create->add($frame->create('id', $id));

        $postalInfo = $create->add($frame->create('postalInfo'));
        $postalInfo->setAttribute('type', 'int');

        $postalInfo->add($frame->create('name', $params['name']));
        if (isset($params['org'])) $postalInfo->add($frame->create('org', $params['org']));

        $addr = $postalInfo->add($frame->create('addr'));
        if (isset($params['street'])) {
            for ($i = 0 ; $i < 3 ; $i++) {
                if (isset($params['street'][$i])) {
                    $addr->add($frame->create('street', $params['street'][$i]));

                } else {
                    break;

                }
            }
        }

        $addr->add($frame->create('city', $params['city']));
        if (isset($params['sp'])) $addr->add($frame->create('sp', $params['sp']));
        if (isset($params['pc'])) $addr->add($frame->create('pc', $params['pc']));
        $addr->add($frame->create('cc', $params['cc']));

        if (isset($params['voice'])) $create->add($frame->create('voice', $params['voice']));
        if (isset($params['fax']) && strlen($params['fax']) > 0) $create->add($frame->create('fax', $params['fax']));
        $create->add($frame->create('email', $params['email']));

        $create->add($frame->create('authInfo'))
            ->add($frame->create('pw', self::generateAuthInfo()));

        self::connection()->request($frame);

        return $id;
    }

    /**
     * generate a valid contact ID
     */
    public static function generateContactID() : string {
        return strToUpper(substr(base_convert(bin2hex(openssl_random_pseudo_bytes(10)), 16, 36), 0, 16));
    }

    /**
     * get domain information
     */
    public static function info(string $domain) : xml\frame {
        $frame = new xml\frame;
        $info = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create('info'))
                            ->add($frame->nsCreate(epp::xmlns_domain, 'info'));

        $info->add($frame->create('name', $domain));
        return self::connection()->request($frame);
    }

    /**
     * get contact information
     */
    public static function contactInfo(string $id) : xml\frame {
        $frame = new xml\frame;
        $info = $frame->add($frame->nsCreate(epp::xmlns, epp::epp))
                    ->add($frame->create(epp::command))
                        ->add($frame->create('info'))
                            ->add($frame->nsCreate(epp::xmlns_contact, 'info'));

        $info->add($frame->create('id', $id));
        return self::connection()->request($frame);
    }

    /**
     * standard sucessful response
     */
    private static function success() : array {
        return ['success' => true];
    }
}
