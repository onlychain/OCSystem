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

use Gelf\Transport\TransportInterface;
use Gelf\MessageValidator as DefaultMessageValidator;
use SplObjectStorage as Set;
use RuntimeException;

/**
 * A GELF publisher functions as a hub for pushing out a GELF message
 * to a least one GELF endpoint
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
class Publisher implements PublisherInterface
{
    /**
     * @var Set
     */
    protected $transports;

    /**
     * @var MessageValidatorInterface
     */
    protected $messageValidator;

    /**
     * Creates a Publisher for GELF-messages.
     *
     * @param TransportInterface|null         $transport
     * @param MessageValidatorInterface|null  $messageValidator
     */
    public function __construct(
        TransportInterface $transport = null,
        MessageValidatorInterface $messageValidator = null
    ) {
        $this->transports = new Set();
        $this->messageValidator = $messageValidator
            ?: new DefaultMessageValidator();

        if (null !== $transport) {
            $this->addTransport($transport);
        }
    }

    /**
     * Publish a message over all defined transports
     *
     * @param MessageInterface $message
     */
    public function publish(MessageInterface $message)
    {
        if (count($this->transports) == 0) {
            throw new RuntimeException(
                "Publisher requires at least one transport"
            );
        }

        $reason = '';
        if (!$this->messageValidator->validate($message, $reason)) {
            throw new RuntimeException("Message is invalid: $reason");
        }

        foreach ($this->transports as $transport) {
            /* @var $transport TransportInterface */
            $transport->send($message);
        }
    }

    /**
     * Adds a transport to the publisher.
     *
     * @param TransportInterface $transport
     */
    public function addTransport(TransportInterface $transport)
    {
        $this->transports->attach($transport);
    }

    /**
     * Returns all defined transports.
     *
     * @return TransportInterface[]
     */
    public function getTransports()
    {
        return iterator_to_array($this->transports);
    }

    /**
     * Returns the current message validator.
     *
     * @return MessageValidatorInterface
     */
    public function getMessageValidator()
    {
        return $this->messageValidator;
    }
}
