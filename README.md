# For testing without composer installation of plugin

add or extend this to the composer.json in the shop root

<code>
"autoload: {\
&nbsp; &nbsp; "psr-4": {\
&nbsp; &nbsp; &nbsp; &nbsp; "Fatchip\\PayOne\\": "./source/modules/fc/fcpayone"\
&nbsp; &nbsp; }\
},
</code>

dann 
<code>composer dump-autoload</code> und danach <code>vendor/bin/oe-console oe:module:install source/modules/fc/fcpayone</code>
auf der CLI ausführen.

# PAYONE for Oxid

![license LGPL](https://img.shields.io/badge/license-LGPL-blue.svg)
[![GitHub issues](https://img.shields.io/github/issues/PAYONE-GmbH/oxid-7.svg)](https://github.com/PAYONE-GmbH/oxid-7/issues)

# PAYMENT FOR YOUR OXID-SHOP

The Payone-FinanceGate-Module is already certified by OXID to guarantee faultless code quality and correct operation,
but we are willing to do an even better job. The community here on Github is a great help for that and we are happy
about your participation. Take a look at our released version and send us commits or other feedback to take care for the
best possible solution.

## Important functions for OXID

* Seamless integration in the checkout processes
* Centralised administration within Oxid
* The offered portfolio of payment options can be controlled depending on the consumer's credit rating
* The payment extension is compatible with all OXID eShop editions of version 6: Community, Professional and Enterprise
  edition
* Supports simplified PCI DSS conformity in accordance with SAQ A
* Find all currently supported payment methods on www.payone.com/oxid

## More information

More information about OXID on https://www.payone.com/oxid
or https://www.fatchip.de/Plugins/OXID-eShop/OXID-PAYONE-Connector.html

## Requirements

Installed OXID eShop >= v7.0.0

## Installation

Just go to the directory of your Oxid `composer.json` file and perform the following command:

```
composer require payone-gmbh/oxid-7
```

After that, just activate the module in the Oxid backend.

## About BS PAYONE

Since the end of August 2017, the two payment specialist companies PAYONE and B+S Card Service merged to become BS
PAYONE GmbH. All current partnerships will be maintained the way they are. APIs, interfaces, and other technical
parameters will stay the same. Your current contact persons will continue to gladly be at your service.<br>
BS PAYONE GmbH is headquartered in Frankfurt am Main and is one of the leading omnichannel payment providers in Europe.
In addition to providing customer support to numerous Sparkasse banks, the full-service payment service provider also
provides cashless payment transactions services to more than 255,000 customers from various branches – whether that be
in stationary retail or when completing e-commerce and mobile payment transactions.
