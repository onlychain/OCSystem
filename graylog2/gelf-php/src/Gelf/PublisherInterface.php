<?php

namespace Gelf;

/*
 * This file is part of the php-gelf package.
 *
 * (c) Benjamin Zikarsky <http://benjamin-zikarsky.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * A publisher is responsible for publishing a given GELF-message
 * to one or multiple backends
 */
interface PublisherInterface
{

    /**
     * Publish a message
     *
     * @param MessageInterface $message
     * @return void
     */
    public function publish(MessageInterface $message);
}
