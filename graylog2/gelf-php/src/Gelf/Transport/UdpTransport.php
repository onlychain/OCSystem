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
use Gelf\Encoder\CompressedJsonEncoder as DefaultEncoder;
use RuntimeException;

/**
 * UdpTransport allows the transfer of GELF-messages to an compatible
 * GELF-UDP-backend as described in
 * https://github.com/Graylog2/graylog2-docs/wiki/GELF
 *
 * It can also act as a direct publisher
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
class UdpTransport extends AbstractTransport
{
    const CHUNK_GELF_ID = "\x1e\x0f";
    const CHUNK_MAX_COUNT = 128; // as per GELF spec
    const CHUNK_SIZE_LAN = 8154;
    const CHUNK_SIZE_WAN = 1420;

    const DEFAULT_HOST = "127.0.0.1";
    const DEFAULT_PORT = 12201;

    /**
     * @var int
     */
    protected $chunkSize;

    /**
     * @var StreamSocketClient
     */
    protected $socketClient;

    /**
     * Class constructor
     *
     * @param string $host      when NULL or empty DEFAULT_HOST is used
     * @param int    $port      when NULL or empty DEFAULT_PORT is used
     * @param int    $chunkSize defaults to CHUNK_SIZE_WAN,
     *                          0 disables chunks completely
     */
    public function __construct(
        $host = self::DEFAULT_HOST,
        $port = self::DEFAULT_PORT,
        $chunkSize = self::CHUNK_SIZE_WAN
    ) {
        // allow NULL-like values for fallback on default
        $host = $host ?: self::DEFAULT_HOST;
        $port = $port ?: self::DEFAULT_PORT;

        $this->socketClient = new StreamSocketClient('udp', $host, $port);
        $this->chunkSize = $chunkSize;

        $this->messageEncoder = new DefaultEncoder();
    }

    /**
     * Sends a Message over this transport
     *
     * @param Message $message
     *
     * @return int the number of UDP packets sent
     */
    public function send(Message $message)
    {
        $rawMessage = $this->getMessageEncoder()->encode($message);

        // test if we need to split the message to multiple chunks
        // chunkSize == 0 allows for an unlimited packet-size, and therefore
        // disables chunking
        if ($this->chunkSize && strlen($rawMessage) > $this->chunkSize) {
            return $this->sendMessageInChunks($rawMessage);
        }

        // send message in one packet
        $this->socketClient->write($rawMessage);

        return 1;
    }

    /**
     * Sends given string in multiple chunks
     *
     * @param  string $rawMessage
     * @return int
     *
     * @throws RuntimeException on too large messages which would exceed the
                                maximum number of possible chunks
     */
    protected function sendMessageInChunks($rawMessage)
    {
        // split to chunks
        $chunks = str_split($rawMessage, $this->chunkSize);
        $numChunks = count($chunks);

        if ($numChunks > self::CHUNK_MAX_COUNT) {
            throw new RuntimeException(
                sprintf(
                    "Message is too big. Chunk count exceeds %d",
                    self::CHUNK_MAX_COUNT
                )
            );
        }

        // generate a random 8byte-message-id
        $messageId = substr(md5(uniqid("", true), true), 0, 8);

        // send chunks with a correct chunk-header
        // @link http://graylog2.org/gelf#specs
        foreach ($chunks as $idx => $chunk) {
            $data = self::CHUNK_GELF_ID            // GELF chunk magic bytes
                . $messageId                       // unique message id
                . pack('CC', $idx, $numChunks)     // sequence information
                . $chunk                           // chunk-data
            ;

            $this->socketClient->write($data);
        }

        return $numChunks;
    }
}
