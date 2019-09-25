<?php

/*
 * This file is part of the php-gelf package.
 *
 * (c) Benjamin Zikarsky <http://benjamin-zikarsky.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gelf\Transport;

use Gelf\Encoder\EncoderInterface;
use Gelf\MessageInterface;
use Gelf\PublisherInterface;

/**
 * The CompressedJsonEncoder allows the encoding of GELF messages as described
 * in http://www.graylog2.org/resources/documentation/sending/gelfhttp
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
abstract class AbstractTransport implements TransportInterface, PublisherInterface
{

    /**
     * @var EncoderInterface
     */
    protected $messageEncoder;

    /**
     * Sets a message encoder
     *
     * @param EncoderInterface $encoder
     *
     * @return $this
     */
    public function setMessageEncoder(EncoderInterface $encoder)
    {
        $this->messageEncoder = $encoder;

        return $this;
    }

    /**
     * Returns the current message encoder
     *
     * @return EncoderInterface
     */
    public function getMessageEncoder()
    {
        return $this->messageEncoder;
    }

    /**
     * Alias to send() without return value
     * Required to fulfill the PublisherInterface
     *
     * @deprecated deprecated since 1.1
     * @codeCoverageIgnore
     *
     * @param MessageInterface $message
     *
     * @return int the number of bytes sent
     */
    public function publish(MessageInterface $message)
    {
        return $this->send($message);
    }
}
