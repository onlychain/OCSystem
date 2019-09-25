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

use Gelf\MessageInterface as Message;

/**
 * A transport is responsible for the encoding and transport
 * of a GELF message to a GELF endpoint
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
interface TransportInterface
{
    /**
     * Sends a Message over this transport.
     *
     * @param Message $message
     * @return int the number of bytes sent
     */
    public function send(Message $message);
}
