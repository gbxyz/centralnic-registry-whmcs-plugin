# CentralNic Registry WHMCS Plugin

[![CI](https://github.com/gbxyz/centralnic-registry-whmcs-plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/gbxyz/centralnic-registry-whmcs-plugin/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/gbxyz/centralnic-registry-whmcs-plugin/graph/badge.svg?token=PLWL4ISS79)](https://codecov.io/gh/gbxyz/centralnic-registry-whmcs-plugin)

This repository contains a plugin for WHMCS that interacts with the [CentralNic Registry](https://centralnicregistry.com) EPP server.
It should also work with any standards-compliant EPP server, although this is not guaranteed.

This plugin should not be confused with the [CentralNic Reseller](https://docs.whmcs.com/CentralNic_Reseller) plugin for WHMCS.

## Using this plugin with the SK-NIC and LANIC registries

The .LA and .SK TLDs use dedicated instances of the CentralNic system, so if you want to use this plugin for these TLDs, you need to tell the plugin to use a different EPP server.

To do this, create an environment variable called `EPP_SERVER_NAME`. Note that this will override the `testMode` config option.

### Production & OT&E Servers

* SK:
  * Production: `epp.sk-nic.sk`
  * OT&E: `epp-ote.sk-nic.sk`
* LA:
  * Production: `epp.lanic.la`
  * OT&E `ote.lanic.la`

### Examples

#### Native PHP

```php
<?php
setenv('EPP_SERVER_NAME=epp.sk-nic.sk');
```

#### Apache

```
LoadModule env_module modules/mod_env.so

SetEnv EPP_SERVER_NAME epp.sk-nic.sk
```

## Support

Please use the [GitHub issues system](https://github.com/gbxyz/centralnic-registry-whmcs-plugin/issues) to report any issues with this plugin.

## Copyright

Copyright 2023 CentralNic Group PLC. All rights reserved.
