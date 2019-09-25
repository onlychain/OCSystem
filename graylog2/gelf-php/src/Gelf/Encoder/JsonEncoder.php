<?php

/*
 * This file is part of the php-gelf package.
 *
 * (c) Benjamin Zikarsky <http://benjamin-zikarsky.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gelf\Encoder;

use Gelf\MessageInterface;

/**
 * The JsonEncoder allows the encoding of GELF messages as described
 * in http://www.graylog2.org/resources/documentation/sending/gelfhttp
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
class JsonEncoder implements EncoderInterface
{
    /**
     * Encodes a given message
     *
     * @param  MessageInterface $message
     * @return string
     */
    public function encode(MessageInterface $message)
    {
        return json_encode($message->toArray());
    }
}
