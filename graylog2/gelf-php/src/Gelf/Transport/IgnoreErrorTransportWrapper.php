<?php

namespace Gelf\Transport;

use Gelf\MessageInterface as Message;

/**
 * A wrapper for any AbstractTransport to ignore any kind of errors
 * @package Gelf\Transport
 */
class IgnoreErrorTransportWrapper extends AbstractTransport
{

    /**
     * @var AbstractTransport
     */
    private $transport;

    /**
     * @var \Exception|null
     */
    private $lastError = null;

    /**
     * IgnoreErrorTransportWrapper constructor.
     *
     * @param AbstractTransport $transport
     */
    public function __construct(AbstractTransport $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Sends a Message over this transport.
     *
     * @param Message $message
     *
     * @return int the number of bytes sent
     */
    public function send(Message $message)
    {
        try {
            return $this->transport->send($message);
        } catch (\Exception $e) {
            $this->lastError = $e;
            return 0;
        }
    }

    /**
     * Returns the last error
     * @return \Exception|null
     */
    public function getLastError()
    {
        return $this->lastError;
    }
}
