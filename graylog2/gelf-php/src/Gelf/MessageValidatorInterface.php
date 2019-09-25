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
 * A message validator validates a message for validity
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
interface MessageValidatorInterface
{
    /**
     * Validate a the given message for validity.
     *
     * @param  MessageInterface $message
     * @param  string           &$reason
     * @return bool
     */
    public function validate(MessageInterface $message, &$reason = "");
}
