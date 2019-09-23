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

use RuntimeException;

/**
 * Validates a given message according to the GELF standard
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 * @author Joe Green
 */
class MessageValidator implements MessageValidatorInterface
{
    public function validate(MessageInterface $message, &$reason = "")
    {
        switch ($message->getVersion()) {
            case "1.0":
                return $this->validate0100($message, $reason);
            case "1.1":
                return $this->validate0101($message, $reason);
        }

        throw new RuntimeException(
            sprintf(
                "No validator for message version '%s'",
                $message->getVersion()
            )
        );
    }

    /**
     * Validates a message according to 1.0 standard
     *
     * @param  MessageInterface $message
     * @param  string           &$reason reason for the validation fail
     * @return bool
     */
    public function validate0100(MessageInterface $message, &$reason = "")
    {
        if (self::isEmpty($message->getHost())) {
            $reason = "host not set";

            return false;
        }

        if (self::isEmpty($message->getShortMessage())) {
            $reason = "short-message not set";

            return false;
        }

        if (self::isEmpty($message->getVersion())) {
            $reason = "version not set";

            return false;
        }

        if ($message->hasAdditional('id')) {
            $reason = "additional field 'id' is not allowed";

            return false;
        }

        return true;
    }

    /**
     * Validates a message according to 1.1 standard
     *
     * @param  MessageInterface $message
     * @param  string           &$reason
     * @return bool
     */
    public function validate0101(MessageInterface $message, &$reason = "")
    {
        // 1.1 incorporates 1.0 validation standar
        if (!$this->validate0100($message, $reason)) {
            return false;
        }

        foreach ($message->getAllAdditionals() as $key => $value) {
            if (!preg_match('#^[\w\.\-]*$#', $key)) {
                $reason = sprintf(
                    "additional key '%s' contains invalid characters",
                    $key
                );

                return false;
            }
        }

        return true;
    }

    /**
     * Checks that a given scalar will later translate
     * to a non-empty message element
     *
     * Fails on null, false and empty strings
     *
     * @param  string $scalar
     * @return bool
     */
    public static function isEmpty($scalar)
    {
        return strlen($scalar) < 1;
    }
}
