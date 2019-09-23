gelf-php [![Latest Stable Version](https://img.shields.io/packagist/v/graylog2/gelf-php.svg?style=flat-square)](https://packagist.org/packages/graylog2/gelf-php) [![Total Downloads](https://img.shields.io/packagist/dt/graylog2/gelf-php.svg?style=flat-square)](https://packagist.org/packages/graylog2/gelf-php) 
========
[![Build Status](https://img.shields.io/travis/com/bzikarsky/gelf-php.svg?style=flat-square)](https://travis-ci.com/bzikarsky/gelf-php)
[![Code Coverage](https://img.shields.io/scrutinizer/coverage/g/bzikarsky/gelf-php.svg?style=flat-square)](https://scrutinizer-ci.com/g/bzikarsky/gelf-php/)
[![Scrutinizer Quality Score](https://img.shields.io/scrutinizer/g/bzikarsky/gelf-php.svg?style=flat-square)](https://scrutinizer-ci.com/g/bzikarsky/gelf-php/)


A php implementation to send log-files to a gelf compatible backend like [Graylog2](http://graylog2.org/).
This library conforms to the PSR standards in regards to structure ([4](http://www.php-fig.org/psr/psr-4/)),
coding-style ([1](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-1-basic-coding-standard.md),
[2](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md))
and logging ([3](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md)).

It's a loosely based on the original [Graylog2 gelf-php](https://github.com/Graylog2/gelf-php)
and [mlehner's fork](https://github.com/mlehner/gelf-php).

Stable release and deprecation of the original graylog2/gelf-php
----------------------------------------------------------------

This implementation became the official PHP GELF library on 2013-12-19 and is now released as `graylog2/gelf-php`.
The old library became deprecated at the same time and it's recommended to upgrade.

Since the deprecated library never got a stable release, we decided keep it available as `v0.1`. This means:
If you have a project based on the deprecated library but no time to upgrade to version 1.0, we recommend to change your
`composer.json` as following:

        "require": {
           // ...
           "graylog2/gelf-php": "0.1.*"
           // ...
        }

After running an additional `composer update` everything should work as expected.

Usage
-----

### Recommended installation via composer:

Add gelf-php to `composer.json` either by running `composer require graylog2/gelf-php` or by defining it manually:

    "require": {
       // ...
       "graylog2/gelf-php": "~1.5"
       // ...
    }

Reinstall dependencies: `composer install`

### Examples

For usage examples, go to [/examples](https://github.com/bzikarsky/gelf-php/tree/master/examples).

### Muting connection and transport errors

Oftentimes projects run into the situation where they don't want to raise exceptions for logging-errors. Since
the standard transports like Udp, Tcp and Http can be kind of noise for fwrite/fopen errors, gelf-php provides
a `IgnoreErrorTransportWrapper`. This class can decorate any `AbstractTransport` and will mute all exceptions.

How this applies in practice can be seen in the [advanced-example](https://github.com/bzikarsky/gelf-php/blob/master/examples/advanced.php#L18-L20).

If you use gelf-php in conjunction with monolog/symfony, the following snippet should help you with properly setting up your logging backend.

Assumung you have a typical monolog config:

```yml
monolog:
  handlers:
    graylog:
      type: service
      id: monolog.gelf_handler
      level: debug
```

You only need to properly define the symfony-service `gelf-handler`:

```yml
services:
  monolog.gelf_handler:
    class: Monolog\Handler\GelfHandler
    arguments: [@gelf.publisher]
    
  gelf.publisher:
    class: Gelf\Publisher
    arguments: [@gelf.ignore_error_transport]
    
  gelf.ignore_error_transport:
    class: Gelf\Transport\IgnoreErrorTransportWrapper
    arguments: [@gelf.transport]
    
  gelf.transport:
    class: Gelf\Transport\UdpTransport # or Tcp, Amp, Http,...
    arguments: [] # ... whatever is required
```





HHVM
----

While HHVM is supported/tested, there are some restrictions to look out for:
- Stream-context support is very limited (as of 2014) - especially regarding SSL - many use-cases might not work as expected (or not at all...)
- `fwrite` does behave a little different

The failing unit-tests are skipped by default when running on HHVM. They are also all annotated with `@group hhvm-failures`.
You can force to run those failures by setting `FORCE_HHVM_TESTS=1` in the environment. Therefore you can specifically check
the state of HHVM failures by running:

    FORCE_HHVM_TESTS=1 hhvm vendor/bin/phpunit --group hhvm-failures


License
-------

The library is licensed under the MIT license. For details check out the LICENSE file.


Development & Contributing
--------------------------

You are welcome to modify, extend and bugfix all you like. :-)
If you have any questions/proposals/etc. you can contact me on Twitter ([@bzikarsky](https://twitter.com/bzikarsky)) or message me on [freenode#graylog2](irc://irc.freenode.net#graylog2).

### Tools
1. [composer](http://getcomposer.org), preferably a system-wide installation as `composer`
2. [PHPUnit](http://phpunit.de/manual/current/en/installation.html)
3. Optional: [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) for PSR-X-compatibility checks

### Steps
1. Clone repository and cd into it: `git clone git@github.com:bzikarsky/gelf-php && cd gelf-php`
2. Install dependencies: `composer install`
3. Run unit-tests: `vendor/bin/phpunit`
4. Check PSR compatibility: `vendor/bin/phpcs --standard=PSR2 src tests examples`
