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

/**
 * This interface defines the minimum amount of method any Message
 * implementation must provide to be used by the publisher or transports.
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
interface MessageInterface
{
    /**
     * Returns the GELF version of the message
     *
     * @return string
     */
    public function getVersion();

    /**
     * Returns the host of the message
     *
     * @return string
     */
    public function getHost();

    /**
     * Returns the short text of the message
     *
     * @return string
     */
    public function getShortMessage();

    /**
     * Returns the full text of the message
     *
     * @return string
     */
    public function getFullMessage();

    /**
     * Returns the timestamp of the message
     *
     * @return float
     */
    public function getTimestamp();

    /**
     * Returns the log level of the message as a Psr\Log\Level-constant
     *
     * @return string
     */
    public function getLevel();

    /**
     * Returns the log level of the message as a numeric syslog level
     *
     * @return int
     */
    public function getSyslogLevel();

    /**
     * Returns the facility of the message
     *
     * @return string
     */
    public function getFacility();

    /**
     * Returns the file of the message
     *
     * @return string
     */
    public function getFile();

    /**
     * Returns the the line of the message
     *
     * @return string
     */
    public function getLine();

    /**
     * Returns the value of the additional field of the message
     *
     * @param  string $key
     * @return mixed
     */
    public function getAdditional($key);

    /**
     * Checks if a additional fields is set
     *
     * @param  string $key
     * @return bool
     */
    public function hasAdditional($key);

    /**
     * Returns all additional fields as an array
     *
     * @return array
     */
    public function getAllAdditionals();

    /**
     * Converts the message to an array
     *
     * @return array
     */
    public function toArray();
}
