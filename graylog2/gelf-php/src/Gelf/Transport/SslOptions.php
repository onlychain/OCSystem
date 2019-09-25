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

/**
 * Abstraction of supported SSL configuration paramaters
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
class SslOptions
{
    /**
     * Enable certificate validation of remote party
     *
     * @param boolean
     */
    protected $verifyPeer = true;

    /**
     * Allow self-signed certificates
     *
     * @param boolean
     */
    protected $allowSelfSigned = false;

    /**
     * Path to custom CA
     *
     * @param string|null
     */
    protected $caFile = null;

    /**
     * List of ciphers the SSL layer may use
     *
     * Formatted as specified in `ciphers(1)`
     *
     * @param string|null
     */
    protected $ciphers = null;

    /**
     * Whether self-signed certificates are allowed
     *
     * @return boolean
     */
    public function getAllowSelfSigned()
    {
        return $this->allowSelfSigned;
    }

    /**
     * Enables or disables the error on self-signed certificates
     *
     * @param boolean $allowSelfSigned
     */
    public function setAllowSelfSigned($allowSelfSigned)
    {
        $this->allowSelfSigned = $allowSelfSigned;
    }

    /**
     * Returns the path to a custom CA
     *
     * @return string|null
     */
    public function getCaFile()
    {
        return $this->caFile;
    }

    /**
     * Sets the path toa custom CA
     *
     * @param string|null $caFile
     */
    public function setCaFile($caFile)
    {
        $this->caFile = $caFile;
    }

    /**
     * Returns des description of allowed ciphers
     *
     * @return string|null
     */
    public function getCiphers()
    {
        return $this->ciphers;
    }

    /**
     * Set the allowed SSL/TLS ciphers
     *
     * Format must follow `ciphers(1)`
     *
     * @param string|null $ciphers
     */
    public function setCiphers($ciphers)
    {
        $this->ciphers = $ciphers;
    }

    /**
     * Whether to check the peer certificate
     *
     * @return boolean
     */
    public function getVerifyPeer()
    {
        return $this->verifyPeer;
    }

    /**
     * Enable or disable the peer certificate check
     *
     * @param boolean $verifyPeer
     */
    public function setVerifyPeer($verifyPeer)
    {
        $this->verifyPeer = $verifyPeer;
    }

    /**
     * Returns a stream-context representation of this config
     *
     * @param string|null $serverName
     * @return array<string,mixed>
     */
    public function toStreamContext($serverName = null)
    {
        $sslContext = array(
            'verify_peer'       => (bool) $this->verifyPeer,
            'allow_self_signed' => (bool) $this->allowSelfSigned
        );

        if (null !== $this->caFile) {
            $sslContext['cafile'] = $this->caFile;
        }

        if (null !== $this->ciphers) {
            $sslContext['ciphers'] = $this->ciphers;
        }

        if (null !== $serverName) {
            $sslContext['SNI_enabled'] = true;
            $sslContext[PHP_VERSION_ID < 50600 ? 'SNI_server_name' : 'peer_name'] = $serverName;

            if ($this->verifyPeer) {
                $sslContext[PHP_VERSION_ID < 50600 ? 'CN_match' : 'peer_name'] = $serverName;
            }
        }

        return array('ssl' => $sslContext);
    }
}
