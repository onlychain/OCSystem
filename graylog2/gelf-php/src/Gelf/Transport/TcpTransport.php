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
use Gelf\Encoder\JsonEncoder as DefaultEncoder;

/**
 * TcpTransport allows the transfer of GELF-messages (with SSL/TLS support)
 * to a compatible GELF-TCP-backend as described in
 * https://github.com/Graylog2/graylog2-docs/wiki/GELF
 *
 * It can also act as a direct publisher
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 * @author Ahmed Trabelsi <ahmed.trabelsi@proximedia.fr>
 */
class TcpTransport extends AbstractTransport
{
    const DEFAULT_HOST = "127.0.0.1";
    const DEFAULT_PORT = 12201;

    const AUTO_SSL_PORT = 12202;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var StreamSocketClient
     */
    protected $socketClient;

    /**
     * @var SslOptions|null
     */
    protected $sslOptions = null;

    /**
     * Class constructor
     *
     * @param string|null     $host       when NULL or empty default-host is used
     * @param int|null        $port       when NULL or empty default-port is used
     * @param SslOptions|null $sslOptions when null not SSL is used
     */
    public function __construct(
        $host = self::DEFAULT_HOST,
        $port = self::DEFAULT_PORT,
        SslOptions $sslOptions = null
    ) {
        $this->host = $host;
        $this->port = $port;

        if ($port == self::AUTO_SSL_PORT && $sslOptions == null) {
            $sslOptions = new SslOptions();
        }

        $this->sslOptions = $sslOptions;

        $this->messageEncoder = new DefaultEncoder();
        $this->socketClient = new StreamSocketClient(
            $this->getScheme(),
            $this->host,
            $this->port,
            $this->getContext()
        );
    }

    /**
     * Sends a Message over this transport
     *
     * @param Message $message
     *
     * @return int the number of TCP packets sent
     */
    public function send(Message $message)
    {
        $rawMessage = $this->getMessageEncoder()->encode($message) . "\0";

        // send message in one packet
        $this->socketClient->write($rawMessage);

        return 1;
    }

    /**
     * @return string
     */
    private function getScheme()
    {
        return null === $this->sslOptions ? 'tcp' : 'ssl';
    }

    /**
     * @return array
     */
    private function getContext()
    {
        if (null === $this->sslOptions) {
            return array();
        }

        return $this->sslOptions->toStreamContext($this->host);
    }

    /**
     * Sets the connect-timeout
     *
     * @param int $timeout
     */
    public function setConnectTimeout($timeout)
    {
        $this->socketClient->setConnectTimeout($timeout);
    }

    /**
     * Returns the connect-timeout
     *
     * @return int
     */
    public function getConnectTimeout()
    {
        return $this->socketClient->getConnectTimeout();
    }
}
