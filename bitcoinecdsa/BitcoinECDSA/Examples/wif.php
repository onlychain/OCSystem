<?php

require_once '../src/BitcoinPHP/BitcoinECDSA/BitcoinECDSA.php';

use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;

$bitcoinECDSA = new BitcoinECDSA();
$bitcoinECDSA->generateRandomPrivateKey(); //generate new random private key

$wif = $bitcoinECDSA->getWif();
$address = $bitcoinECDSA->getAddress();
echo "Address : " . $address . PHP_EOL;
echo "WIF : " . $wif . PHP_EOL;

unset($bitcoinECDSA); //destroy instance

//import wif
$bitcoinECDSA = new BitcoinECDSA();
if($bitcoinECDSA->validateWifKey($wif)) {
    $bitcoinECDSA->setPrivateKeyWithWif($wif);
    $address = $bitcoinECDSA->getAddress();
    echo "imported address : " . $address . PHP_EOL;
} else {
    echo "invalid WIF key" . PHP_EOL;
}
