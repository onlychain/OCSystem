<?php

/*
 * This file is part of the php-gelf package.
 *
 * (c) Benjamin Zikarsky <http://benjamin-zikarsky.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gelf;

use Gelf\Transport\UdpTransport;
use Psr\Log\LoggerInterface;
use Psr\Log\AbstractLogger;
use Exception;

/**
 * A basic PSR-3 compliant logger
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
class Logger extends AbstractLogger implements LoggerInterface
{
    /**
     * @var string|null
     */
    protected $facility;

    /**
     * @var array
     */
    protected $defaultContext;

    /**
     * @var PublisherInterface
     */
    protected $publisher;

    /**
     * Creates a PSR-3 Logger for GELF/Graylog2
     *
     * @param PublisherInterface|null $publisher
     * @param string|null $facility
     * @param array $defaultContext
     */
    public function __construct(
        PublisherInterface $publisher = null,
        $facility = null,
        array $defaultContext = array()
    ) {
        // if no publisher is provided build a "default" publisher
        // which is logging via Gelf over UDP to localhost on the default port
        $this->publisher = $publisher ?: new Publisher(new UdpTransport());

        $this->setFacility($facility);
        $this->setDefaultContext($defaultContext);
    }

    /**
     * Publishes a given message and context with given level
     *
     * @param mixed $level
     * @param mixed $rawMessage
     * @param array $context
     */
    public function log($level, $rawMessage, array $context = array())
    {
        $message = $this->initMessage($level, $rawMessage, $context);

        // add exception data if present
        if (isset($context['exception'])
           && $context['exception'] instanceof Exception
        ) {
            $this->initExceptionData($message, $context['exception']);
        }

        $this->publisher->publish($message);
    }

    /**
     * Returns the currently used publisher
     *
     * @return PublisherInterface
     */
    public function getPublisher()
    {
        return $this->publisher;
    }

    /**
     * Sets a new publisher
     *
     * @param PublisherInterface $publisher
     */
    public function setPublisher(PublisherInterface $publisher)
    {
        $this->publisher = $publisher;
    }

    /**
     * Returns the faciilty-name used in GELF
     *
     * @return string|null
     */
    public function getFacility()
    {
        return $this->facility;
    }

    /**
     * Sets the facility for GELF messages
     *
     * @param string|null $facility
     */
    public function setFacility($facility = null)
    {
        $this->facility = $facility;
    }

    /**
     * @return array
     */
    public function getDefaultContext()
    {
        return $this->defaultContext;
    }

    /**
     * @param array $defaultContext
     */
    public function setDefaultContext($defaultContext)
    {
        $this->defaultContext = $defaultContext;
    }

    /**
     * Initializes message-object
     *
     * @param  mixed   $level
     * @param  mixed   $message
     * @param  array   $context
     * @return Message
     */
    protected function initMessage($level, $message, array $context)
    {
        // assert that message is a string, and interpolate placeholders
        $message = (string) $message;
        $context = $this->initContext($context);
        $message = self::interpolate($message, $context);

        // create message object
        $messageObj = new Message();
        $messageObj->setLevel($level);
        $messageObj->setShortMessage($message);
        $messageObj->setFacility($this->facility);

        foreach ($this->getDefaultContext() as $key => $value) {
            $messageObj->setAdditional($key, $value);
        }
        foreach ($context as $key => $value) {
            $messageObj->setAdditional($key, $value);
        }

        return $messageObj;
    }

    /**
     * Initializes context array, ensuring all values are string-safe
     *
     * @param array $context
     * @return array
     */
    protected function initContext($context)
    {
        foreach ($context as $key => &$value) {
            switch (gettype($value)) {
                case 'string':
                case 'integer':
                case 'double':
                    // These types require no conversion
                    break;
                case 'array':
                case 'boolean':
                    $value = json_encode($value);
                    break;
                case 'object':
                    if (method_exists($value, '__toString')) {
                        $value = (string)$value;
                    } else {
                        $value = '[object (' . get_class($value) . ')]';
                    }
                    break;
                case 'NULL':
                    $value = 'NULL';
                    break;
                default:
                    $value = '[' . gettype($value) . ']';
                    break;
            }
        }

        return $context;
    }

    /**
     * Initializes Exceptiondata with given message
     *
     * @param Message   $message
     * @param Exception $exception
     */
    protected function initExceptionData(Message $message, Exception $exception)
    {
        $message->setLine($exception->getLine());
        $message->setFile($exception->getFile());

        $longText = "";

        do {
            $longText .= sprintf(
                "%s: %s (%d)\n\n%s\n",
                get_class($exception),
                $exception->getMessage(),
                $exception->getCode(),
                $exception->getTraceAsString()
            );

            $exception = $exception->getPrevious();
        } while ($exception && $longText .= "\n--\n\n");

        $message->setFullMessage($longText);
    }

    /**
     * Interpolates context values into the message placeholders.
     *
     * Reference implementation
     * @link https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md#12-message
     *
     * @param mixed $message
     * @param array $context
     * @return string
     */
    private static function interpolate($message, array $context)
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            $replace['{' . $key . '}'] = $val;
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }
}
