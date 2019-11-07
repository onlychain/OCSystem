<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit301669c15036b536d924505e5052685e
{
    public static $files = array (
        '0e6d7bf4a5811bfa5cf40c5ccd6fae6a' => __DIR__ . '/..' . '/symfony/polyfill-mbstring/bootstrap.php',
        '72579e7bd17821bb1321b87411366eae' => __DIR__ . '/..' . '/illuminate/support/helpers.php',
        'def43f6c87e4f8dfd0c9e1b1bab14fe8' => __DIR__ . '/..' . '/symfony/polyfill-iconv/bootstrap.php',
        '8825ede83f2f289127722d4e842cf7e8' => __DIR__ . '/..' . '/symfony/polyfill-intl-grapheme/bootstrap.php',
        'e69f7f6ee287b969198c3c9d6777bd38' => __DIR__ . '/..' . '/symfony/polyfill-intl-normalizer/bootstrap.php',
        '25072dd6e2470089de65ae7bf11d3109' => __DIR__ . '/..' . '/symfony/polyfill-php72/bootstrap.php',
        'b46ad4fe52f4d1899a2951c7e6ea56b0' => __DIR__ . '/..' . '/voku/portable-utf8/bootstrap.php',
        '01872de466184325f7c54c2eed2fbb45' => __DIR__ . '/..' . '/tmtbe/swooledistributed/src/Server/helpers/Common.php',
        '01872de466184325f7c54c2eed2fbb98' => __DIR__ . '/..' . '/mongodb/mongodb/src/functions.php',
    );

    public static $prefixLengthsPsr4 = array (
        'v' => 
        array (
            'voku\\tests\\' => 11,
            'voku\\helper\\' => 12,
            'voku\\' => 5,
        ),
        't' => 
        array (
            'test\\' => 5,
        ),
        'a' => 
        array (
            'app\\' => 4,
        ),
        'W' => 
        array (
            'Whoops\\' => 7,
        ),
        'S' => 
        array (
            'Symfony\\Polyfill\\Php72\\' => 23,
            'Symfony\\Polyfill\\Mbstring\\' => 26,
            'Symfony\\Polyfill\\Intl\\Normalizer\\' => 33,
            'Symfony\\Polyfill\\Intl\\Grapheme\\' => 31,
            'Symfony\\Polyfill\\Iconv\\' => 23,
            'Symfony\\Contracts\\' => 18,
            'Symfony\\Component\\Translation\\' => 30,
            'Symfony\\Component\\Finder\\' => 25,
            'Symfony\\Component\\Debug\\' => 24,
            'Symfony\\Component\\Console\\' => 26,
            'Server\\' => 7,
        ),
        'P' => 
        array (
            'Psr\\SimpleCache\\' => 16,
            'Psr\\Log\\' => 8,
            'Psr\\Container\\' => 14,
            'PhpAmqpLib\\' => 11,
            'ParagonIE\\ConstantTime\\' => 23,
        ),
        'N' => 
        array (
            'Noodlehaus\\' => 11,
        ),
        'M' => 
        array (
            'Monolog\\' => 8,
            'MongoDB\\' => 8,
            'MongoDB\\Exception\\' => 18,
            'MongoDB\\Model\\' => 12,
            'MongoDB\\Operation\\' => 18,
            'MongoDB\\GridFS\\'     =>  15,
            'MongoDB\\GridFS\\Exception\\' => 25,
        ),
        'I' => 
        array (
            'Illuminate\\View\\' => 16,
            'Illuminate\\Support\\' => 19,
            'Illuminate\\Filesystem\\' => 22,
            'Illuminate\\Events\\' => 18,
            'Illuminate\\Contracts\\' => 21,
            'Illuminate\\Container\\' => 21,
        ),
        'G' => 
        array (
            'Gelf\\' => 5,
        ),
        'D' => 
        array (
            'Ds\\' => 3,
            'Doctrine\\Common\\Inflector\\' => 26,
        ),
        'C' => 
        array (
            'Carbon\\' => 7,
        ),
        'B' =>
        array (
            'BitcoinPHP\\BitcoinECDSA\\' => 24,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'voku\\tests\\' => 
        array (
            0 => __DIR__ . '/..' . '/voku/portable-utf8/tests',
        ),
        'voku\\helper\\' => 
        array (
            0 => __DIR__ . '/..' . '/voku/anti-xss/src/voku/helper',
        ),
        'voku\\' => 
        array (
            0 => __DIR__ . '/..' . '/voku/portable-utf8/src/voku',
        ),
        'test\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src/test',
            1 => __DIR__ . '/..' . '/tmtbe/swooledistributed/src/test',
        ),
        'app\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src/app',
            1 => __DIR__ . '/..' . '/tmtbe/swooledistributed/src/app',
        ),
        'Whoops\\' => 
        array (
            0 => __DIR__ . '/..' . '/filp/whoops/src/Whoops',
        ),
        'Symfony\\Polyfill\\Php72\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/polyfill-php72',
        ),
        'Symfony\\Polyfill\\Mbstring\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/polyfill-mbstring',
        ),
        'Symfony\\Polyfill\\Intl\\Normalizer\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/polyfill-intl-normalizer',
        ),
        'Symfony\\Polyfill\\Intl\\Grapheme\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/polyfill-intl-grapheme',
        ),
        'Symfony\\Polyfill\\Iconv\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/polyfill-iconv',
        ),
        'Symfony\\Contracts\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/contracts',
        ),
        'Symfony\\Component\\Translation\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/translation',
        ),
        'Symfony\\Component\\Finder\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/finder',
        ),
        'Symfony\\Component\\Debug\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/debug',
        ),
        'Symfony\\Component\\Console\\' => 
        array (
            0 => __DIR__ . '/..' . '/symfony/console',
        ),
        'Server\\' => 
        array (
            0 => __DIR__ . '/..' . '/tmtbe/swooledistributed/src/Server',
        ),
        'Psr\\SimpleCache\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/simple-cache/src',
        ),
        'Psr\\Log\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/log/Psr/Log',
        ),
        'Psr\\Container\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/container/src',
        ),
        'PhpAmqpLib\\' => 
        array (
            0 => __DIR__ . '/..' . '/php-amqplib/php-amqplib/PhpAmqpLib',
        ),
        'ParagonIE\\ConstantTime\\' => 
        array (
            0 => __DIR__ . '/..' . '/paragonie/constant_time_encoding/src',
        ),
        'Noodlehaus\\' => 
        array (
            0 => __DIR__ . '/..' . '/hassankhan/config/src',
        ),
        'Monolog\\' => 
        array (
            0 => __DIR__ . '/..' . '/monolog/monolog/src/Monolog',
        ),
        'MongoDB\\' =>
        array(
            0 => __DIR__ . '/..' . '/mongodb/mongodb/src',
        ),
        'MongoDB\\Model\\' =>
        array(
            0 => __DIR__ . '/..' . '/mongodb/mongodb/src/Model',
        ),
        'MongoDB\\Exception\\' =>
        array(
            0 => __DIR__ . '/..' . '/mongodb/mongodb/src/Exception',
        ),
        'MongoDB\\Operation\\' =>
        array(
            0 => __DIR__ . '/..' . '/mongodb/mongodb/src/Operation',
        ),
        'MongoDB\\GridFS\\' =>
        array(
            0 => __DIR__ . '/..' . '/mongodb/mongodb/src/GridFS',
        ),
        'MongoDB\\GridFS\\Exception\\' =>
        array(
            0 => __DIR__ . '/..' . '/mongodb/mongodb/src/GridFS/Exception',
        ),
        'Illuminate\\View\\' => 
        array (
            0 => __DIR__ . '/..' . '/illuminate/view',
        ),
        'Illuminate\\Support\\' => 
        array (
            0 => __DIR__ . '/..' . '/illuminate/support',
        ),
        'Illuminate\\Filesystem\\' => 
        array (
            0 => __DIR__ . '/..' . '/illuminate/filesystem',
        ),
        'Illuminate\\Events\\' => 
        array (
            0 => __DIR__ . '/..' . '/illuminate/events',
        ),
        'Illuminate\\Contracts\\' => 
        array (
            0 => __DIR__ . '/..' . '/illuminate/contracts',
        ),
        'Illuminate\\Container\\' => 
        array (
            0 => __DIR__ . '/..' . '/illuminate/container',
        ),
        'Gelf\\' => 
        array (
            0 => __DIR__ . '/..' . '/graylog2/gelf-php/src/Gelf',
        ),
        'Ds\\' => 
        array (
            0 => __DIR__ . '/..' . '/php-ds/php-ds/src',
        ),
        'Doctrine\\Common\\Inflector\\' => 
        array (
            0 => __DIR__ . '/..' . '/doctrine/inflector/lib/Doctrine/Common/Inflector',
        ),
        'Carbon\\' => 
        array (
            0 => __DIR__ . '/..' . '/nesbot/carbon/src/Carbon',
        ),
        'BitcoinPHP\\BitcoinECDSA\\' =>
        array(
            0 => __DIR__ . '/..' . '/bitcoinecdsa/BitcoinECDSA/src/BitcoinPHP/BitcoinECDSA'
        ),
    );

    public static $classMap = array (
        'Normalizer' => __DIR__ . '/..' . '/symfony/polyfill-intl-normalizer/Resources/stubs/Normalizer.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit301669c15036b536d924505e5052685e::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit301669c15036b536d924505e5052685e::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit301669c15036b536d924505e5052685e::$classMap;

        }, null, ClassLoader::class);
    }
}
