<?php declare(strict_types=1);

/**
 * CentralNic Registry WHMCS Module
 * Copyright 2023 CentralNic Group PLC. All rights reserved.
 */

if (!defined('WHMCS')) die('This file cannot be accessed directly');

require __DIR__.'/lib/centralnic/whmcs/plugin.php';
require __DIR__.'/lib/centralnic/whmcs/error.php';
require __DIR__.'/lib/centralnic/whmcs/epp.php';
require __DIR__.'/lib/centralnic/whmcs/frame.php';

use WHMCS\Domains\DomainLookup\ResultsList;
use function \centralnic\whmcs\magic as _;

function centralnic_MetaData                    (               ) : array       { return _(); }
function centralnic_getConfigArray              (               ) : array       { return _(); }
function centralnic_RegisterDomain              ( array $params ) : array       { return _(); }
function centralnic_TransferDomain              ( array $params ) : array       { return _(); }
function centralnic_RenewDomain                 ( array $params ) : array       { return _(); }
function centralnic_GetNameservers              ( array $params ) : array       { return _(); }
function centralnic_SaveNameservers             ( array $params ) : array       { return _(); }
function centralnic_GetRegistrarLock            ( array $params ) : string      { return _(); }
function centralnic_SaveRegistrarLock           ( array $params ) : array       { return _(); }
function centralnic_GetContactDetails           ( array $params ) : array       { return _(); }
function centralnic_SaveContactDetails          ( array $params ) : array       { return _(); }
function centralnic_GetEPPCode                  ( array $params ) : array       { return _(); }
function centralnic_RegisterNameserver          ( array $params ) : array       { return _(); }
function centralnic_ModifyNameserver            ( array $params ) : array       { return _(); }
function centralnic_DeleteNameserver            ( array $params ) : array       { return _(); }
function centralnic_Sync                        ( array $params ) : array       { return _(); }
function centralnic_RequestDelete               ( array $params ) : array       { return _(); }
function centralnic_TransferSync                ( array $params ) : array       { return _(); }
function centralnic_CheckAvailability           ( array $params ) : ResultsList { return _(); }
function centralnic_config_validate             ( array $params ) : void        {        _(); }
