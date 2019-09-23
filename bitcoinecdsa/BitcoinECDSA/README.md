[![Build](https://travis-ci.org/BitcoinPHP/BitcoinECDSA.php.svg?branch=master)](https://travis-ci.org/BitcoinPHP/BitcoinECDSA.php) &nbsp;
[![Quality Score](https://scrutinizer-ci.com/g/BitcoinPHP/BitcoinECDSA.php/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/BitcoinPHP/BitcoinECDSA.php/?branch=master) &nbsp;
[![Latest Stable Version](https://poser.pugx.org/bitcoin-php/bitcoin-ecdsa/v/stable.svg)](https://packagist.org/packages/bitcoin-php/bitcoin-ecdsa) &nbsp;
[![Downloads](http://img.shields.io/packagist/dt/bitcoin-php/bitcoin-ecdsa.svg?style=flat)](https://packagist.org/packages/bitcoin-php/bitcoin-ecdsa)


WARNING
===============

This piece of software is provided without warranty of any kind, use it at your own risk.

REQUIREMENTS
===============

*php 5.4.0* or newer.

*php5-gmp* needs to be installed.

If you want to launch the test file you need to be under a unix system with libbitcoin intalled on it.

USAGE
===============

**Installation**

Best way is to use composer
```
composer require bitcoin-php/bitcoin-ecdsa
```
Alternatively add following snippet in you composer.json
```
"bitcoin-php/bitcoin-ecdsa" : ">=1.3"
```

**Instanciation**

```php
use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;
require_once("src/BitcoinPHP/BitcoinECDSA/BitcoinECDSA.php");
$bitcoinECDSA = new BitcoinECDSA();
```

**Set a private key**

```php
$bitcoinECDSA->setPrivateKey($k);
```
examples of private keys :

4C28FCA386C7A227600B2FE50B7CAE11EC86D3BF1FBE471BE89827E19D72AA1D
00FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC

**Generate a random private key**

```php
$bitcoinECDSA->generateRandomPrivateKey($nonce);
```

The nonce is optional, typically the nonce is a chunck of random data you get from the user. This can be mouse coordinates.
Using a nonce adds randomness, which means the generated private key is stronger.

**Get the private key**

```php
$bitcoinECDSA->getPrivateKey();
```

Returns the private key.

**Get the Wif**

```php
$bitcoinECDSA->getWif();
```

returns the private key under the Wallet Import Format


**Get the Public Key**

```php
$bitcoinECDSA->getPubKey();
```
Returns the compressed public key.
The compressed PubKey starts with 0x02 if it's y coordinate is even and 0x03 if it's odd, the next 32 bytes corresponds to the x coordinates.

Example : 0226c50013603b085fbc26411d5d7e564b252d88964eedc4e01251d2d495e92c29

**Get the Uncompressed Public Key**

```php
$bitcoinECDSA->getUncompressedPubKey();
```

Returns the uncompressed PubKey.
The uncompressed PubKey starts with 0x04, the next 32 bytes are the x coordinates, the last 32 bytes are the y coordinates.

Example : 04c80e8af3f1b7816a18aa24f242fc0740e9c4027d67c76dacf4ce32d2e5aace241c426fd288a9976ca750f1b192d3acd89dfbeca07ef27f3e5eb5d482354c4249

**Get the coordinates of the Public Key**

```php
$bitcoinECDSA->getPubKeyPoints();
```

Returns an array containing the x and y coordinates of the public key

Example :
Array ( [x] => a69243f3c4c047aba38d7ac3660317629c957ab1f89ea42343aee186538a34f8 [y] => b6d862f39819060378542a3bb43ff76b5d7bb23fc012f09c3cd2724bebe0b0bd ) 

**Get the Address**

```php
$bitcoinECDSA->getAddress();
```

Returns the compressed Bitcoin Address.

**Get the uncompressed Address**

```php
$bitcoinECDSA->getUncompressedAddress();
```

Returns the uncompressed Bitcoin Address.


**Validate an address**

```php
$bitcoinECDSA->validateAddress($address);
```
Returns true if the address is valid and false if it isn't


**Validate a Wif key**

```php
$bitcoinECDSA->validateWifKey($wif);
```
Returns true if the WIF key is valid and false if it isn't


Signatures
===============

**Sign a message**

```php
$bitcoinECDSA->signMessage('message');
```

Returns a satoshi client standard signed message.


**verify a message**

```php
$bitcoinECDSA->checkSignatureForRawMessage($signedMessage);
```

Returns true if the signature is matching the address and false if it isn't.


**sign a sha256 hash**

```php
$bitcoinECDSA->signHash($hash);
```

Returns a DER encoded hexadecimal signature.


**verify a signature**

```php
$bitcoinECDSA->checkDerSignature($pubKey, $signature, $hash)
```

Returns true if the signature is matching the public key and false if it isn't.

Examples
===============
 - [Generate an address](https://github.com/BitcoinPHP/BitcoinECDSA.php/blob/master/Examples/generateAddress.php)
 - [Sign a message](https://github.com/BitcoinPHP/BitcoinECDSA.php/blob/master/Examples/signMessage.php)
 - [Verify a message](https://github.com/BitcoinPHP/BitcoinECDSA.php/blob/master/Examples/verifyMessage.php)
 - [Import or export a private key using WIF](https://github.com/BitcoinPHP/BitcoinECDSA.php/blob/master/Examples/wif.php)

License
===============
This is free and unencumbered software released into the public domain.

Anyone is free to copy, modify, publish, use, compile, sell, or
distribute this software, either in source code form or as a compiled
binary, for any purpose, commercial or non-commercial, and by any
means.

In jurisdictions that recognize copyright laws, the author or authors
of this software dedicate any and all copyright interest in the
software to the public domain. We make this dedication for the benefit
of the public at large and to the detriment of our heirs and
successors. We intend this dedication to be an overt act of
relinquishment in perpetuity of all present and future rights to this
software under copyright law.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR
OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
OTHER DEALINGS IN THE SOFTWARE.

For more information, please refer to <http://unlicense.org/>
