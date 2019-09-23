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

use Psr\Log\LogLevel;
use RuntimeException;

/**
 * A message complying to the GELF standard
 * <https://github.com/Graylog2/graylog2-docs/wiki/GELF>
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
class Message implements MessageInterface
{

    protected $host;
    protected $shortMessage;
    protected $fullMessage;
    protected $timestamp;
    protected $level;
    protected $facility;
    protected $file;
    protected $line;
    protected $additionals = array();
    protected $version;

    /**
     * A list of the PSR LogLevel constants which is also a mapping of
     * syslog code to psr-value
     *
     * @var array
     */
    private static $psrLevels = array(
        LogLevel::EMERGENCY,    // 0
        LogLevel::ALERT,        // 1
        LogLevel::CRITICAL,     // 2
        LogLevel::ERROR,        // 3
        LogLevel::WARNING,      // 4
        LogLevel::NOTICE,       // 5
        LogLevel::INFO,         // 6
        LogLevel::DEBUG         // 7
    );

    /**
     * Creates a new message
     *
     * Populates timestamp and host with sane default values
     */
    public function __construct()
    {
        $this->timestamp = microtime(true);
        $this->host = gethostname();
        $this->level = 1; //ALERT
        $this->version = "1.0";
    }

    /**
     * Trys to convert a given log-level (psr or syslog) to
     * the psr representation
     *
     * @param  mixed  $level
     * @return string
     */
    final public static function logLevelToPsr($level)
    {
        $origLevel = $level;

        if (is_numeric($level)) {
            $level = intval($level);
            if (isset(self::$psrLevels[$level])) {
                return self::$psrLevels[$level];
            }
        } elseif (is_string($level)) {
            $level = strtolower($level);
            if (in_array($level, self::$psrLevels)) {
                return $level;
            }
        }

        throw new RuntimeException(
            sprintf("Cannot convert log-level '%s' to psr-style", $origLevel)
        );
    }

    /**
     * Trys to convert a given log-level (psr or syslog) to
     * the syslog representation
     *
     * @param mxied
     * @return integer
     */
    final public static function logLevelToSyslog($level)
    {
        $origLevel = $level;

        if (is_numeric($level)) {
            $level = intval($level);
            if ($level < 8 && $level > -1) {
                return $level;
            }
        } elseif (is_string($level)) {
            $level = strtolower($level);
            $syslogLevel = array_search($level, self::$psrLevels);
            if (false !== $syslogLevel) {
                return $syslogLevel;
            }
        }

        throw new RuntimeException(
            sprintf("Cannot convert log-level '%s' to syslog-style", $origLevel)
        );
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function setVersion($version)
    {
        $this->version = $version;

        return $this;
    }

    public function getHost()
    {
        return $this->host;
    }

    public function setHost($host)
    {
        $this->host = $host;

        return $this;
    }

    public function getShortMessage()
    {
        return $this->shortMessage;
    }

    public function setShortMessage($shortMessage)
    {
        $this->shortMessage = $shortMessage;

        return $this;
    }

    public function getFullMessage()
    {
        return $this->fullMessage;
    }

    public function setFullMessage($fullMessage)
    {
        $this->fullMessage = $fullMessage;

        return $this;
    }

    public function getTimestamp()
    {
        return (float) $this->timestamp;
    }

    public function setTimestamp($timestamp)
    {
        if ($timestamp instanceof \DateTime || $timestamp instanceof \DateTimeInterface) {
            $timestamp = $timestamp->format("U.u");
        }

        $this->timestamp = (float) $timestamp;

        return $this;
    }

    public function getLevel()
    {
        return self::logLevelToPsr($this->level);
    }

    public function getSyslogLevel()
    {
        return self::logLevelToSyslog($this->level);
    }

    public function setLevel($level)
    {
        $this->level = self::logLevelToSyslog($level);

        return $this;
    }

    public function getFacility()
    {
        return $this->facility;
    }

    public function setFacility($facility)
    {
        $this->facility = $facility;

        return $this;
    }

    public function getFile()
    {
        return $this->file;
    }

    public function setFile($file)
    {
        $this->file = $file;

        return $this;
    }

    public function getLine()
    {
        return $this->line;
    }

    public function setLine($line)
    {
        $this->line = $line;

        return $this;
    }

    public function getAdditional($key)
    {
        if (!isset($this->additionals[$key])) {
            throw new RuntimeException(
                sprintf("Additional key '%s' is not defined", $key)
            );
        }

        return $this->additionals[$key];
    }

    public function hasAdditional($key)
    {
        return isset($this->additionals[$key]);
    }

    public function setAdditional($key, $value)
    {
        if (!$key) {
            throw new RuntimeException("Additional field key cannot be empty");
        }

        $this->additionals[$key] = $value;

        return $this;
    }

    public function getAllAdditionals()
    {
        return $this->additionals;
    }

    public function toArray()
    {
        $message = array(
            'version'       => $this->getVersion(),
            'host'          => $this->getHost(),
            'short_message' => $this->getShortMessage(),
            'full_message'  => $this->getFullMessage(),
            'level'         => $this->getSyslogLevel(),
            'timestamp'     => $this->getTimestamp(),
            'facility'      => $this->getFacility(),
            'file'          => $this->getFile(),
            'line'          => $this->getLine()
        );

        // Transform 1.1 deprecated fields to additionals
        // Will be refactored for 2.0, see #23
        if ($this->getVersion() == "1.1") {
            foreach (array('line', 'facility', 'file') as $idx) {
                $message["_" . $idx] = $message[$idx];
                unset($message[$idx]);
            }
        }

        // add additionals
        foreach ($this->getAllAdditionals() as $key => $value) {
            $message["_" . $key] = $value;
        }

        // return after filtering empty strings and null values
        return array_filter($message, function ($message) {
            return is_bool($message)
                || (is_string($message) && strlen($message))
                || is_int($message)
                || !empty($message);
        });
    }
}
