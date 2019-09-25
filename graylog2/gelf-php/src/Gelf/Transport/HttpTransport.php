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

use Gelf\MessageInterface;
use Gelf\Encoder\CompressedJsonEncoder;
use Gelf\Encoder\JsonEncoder as DefaultEncoder;
use RuntimeException;

/**
 * HttpTransport allows the transfer of GELF-messages to an compatible
 * GELF-HTTP-backend as described in
 * http://www.graylog2.org/resources/documentation/sending/gelfhttp
 *
 * It can also act as a direct publisher
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
class HttpTransport extends AbstractTransport
{
    const DEFAULT_HOST = "127.0.0.1";
    const DEFAULT_PORT = 12202;
    const DEFAULT_PATH = "/gelf";
    
    const AUTO_SSL_PORT = 443;
    
    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var string
     */
    protected $path;

    /**
     * @var StreamSocketClient
     */
    protected $socketClient;

    /**
     * @var SslOptions|null
     */
    protected $sslOptions = null;

    /**
     * @var string|null
     */
    protected $authentication = null;

    /**
     * @var string|null
     */
    protected $proxyUri = null;

    /**
     * @var bool
     */
    protected $requestFullUri = false;

    /**
     * Class constructor
     *
     * @param string|null     $host       when NULL or empty default-host is used
     * @param int|null        $port       when NULL or empty default-port is used
     * @param string|null     $path       when NULL or empty default-path is used
     * @param SslOptions|null $sslOptions when null not SSL is used
     */
    public function __construct(
        $host = self::DEFAULT_HOST,
        $port = self::DEFAULT_PORT,
        $path = self::DEFAULT_PATH,
        SslOptions $sslOptions = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;

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
     * Creates a HttpTransport from a URI
     *
     * Supports http and https schemes, port-, path- and auth-definitions
     * If the port is omitted 80 and 443 are used respectively.
     * If a username but no password is given, and empty password is used.
     * If a https URI is given, the provided SslOptions (with a fallback to
     * the default SslOptions) are used.
     *
     * @param  string          $url
     * @param  SslOptions|null $sslOptions
     *
     * @return HttpTransport
     */
    public static function fromUrl($url, SslOptions $sslOptions = null)
    {
        $parsed = parse_url($url);
        
        // check it's a valid URL
        if (false === $parsed || !isset($parsed['host']) || !isset($parsed['scheme'])) {
            throw new \InvalidArgumentException("$url is not a valid URL");
        }
        
        // check it's http or https
        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, array('http', 'https'))) {
            throw new \InvalidArgumentException("$url is not a valid http/https URL");
        }

        // setup defaults
        $defaults = array('port' => 80, 'path' => '', 'user' => null, 'pass' => '');

        // change some defaults for https
        if ($scheme == 'https') {
            $sslOptions = $sslOptions ?: new SslOptions();
            $defaults['port'] = 443;
        }
         
        // merge defaults and real data and build transport
        $parsed = array_merge($defaults, $parsed);
        $transport = new static($parsed['host'], $parsed['port'], $parsed['path'], $sslOptions);

        // add optional authentication
        if ($parsed['user']) {
            $transport->setAuthentication($parsed['user'], $parsed['pass']);
        }

        return $transport;
    }

    /**
     * Sets HTTP basic authentication
     *
     * @param string $username
     * @param string $password
     */
    public function setAuthentication($username, $password)
    {
        $this->authentication = $username . ":" . $password;
    }

    /**
     * Enables HTTP proxy
     *
     * @param $proxyUri
     * @param bool $requestFullUri
     */
    public function setProxy($proxyUri, $requestFullUri = false)
    {
        $this->proxyUri = $proxyUri;
        $this->requestFullUri = $requestFullUri;

        $this->socketClient->setContext($this->getContext());
    }

    /**
     * Sends a Message over this transport
     *
     * @param MessageInterface $message
     *
     * @return int the number of bytes sent
     */
    public function send(MessageInterface $message)
    {
        $messageEncoder = $this->getMessageEncoder();
        $rawMessage = $messageEncoder->encode($message);

        $request = array(
            sprintf("POST %s HTTP/1.1", $this->path),
            sprintf("Host: %s:%d", $this->host, $this->port),
            sprintf("Content-Length: %d", strlen($rawMessage)),
            "Content-Type: application/json",
            "Connection: Keep-Alive",
            "Accept: */*"
        );

        if (null !== $this->authentication) {
            $request[] = "Authorization: Basic " . base64_encode($this->authentication);
        }

        if ($messageEncoder instanceof CompressedJsonEncoder) {
            $request[] = "Content-Encoding: gzip";
        }

        $request[] = ""; // blank line to separate headers from body
        $request[] = $rawMessage;

        $request = implode($request, "\r\n");

        $byteCount = $this->socketClient->write($request);
        $headers = $this->readResponseHeaders();

        // if we don't have a HTTP/1.1 connection, or the server decided to close the connection
        // we should do so as well. next read/write-attempt will open a new socket in this case.
        if (strpos($headers, "HTTP/1.1") !== 0 || preg_match("!Connection:\s*Close!i", $headers)) {
            $this->socketClient->close();
        }

        if (!preg_match("!^HTTP/1.\d 202 Accepted!i", $headers)) {
            throw new RuntimeException(
                sprintf(
                    "Graylog-Server didn't answer properly, expected 'HTTP/1.x 202 Accepted', response is '%s'",
                    trim($headers)
                )
            );
        }

        return $byteCount;
    }

    /**
     * @return string
     */
    private function readResponseHeaders()
    {
        $chunkSize = 1024; // number of bytes to read at once
        $delimiter = "\r\n\r\n"; // delimiter between headers and response
        $response = "";

        do {
            $chunk = $this->socketClient->read($chunkSize);
            $response .= $chunk;
        } while (false === strpos($chunk, $delimiter) && strlen($chunk) > 0);

        $elements = explode($delimiter, $response, 2);

        return $elements[0];
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
        $options = array();

        if (null !== $this->sslOptions) {
            $options = array_merge($options, $this->sslOptions->toStreamContext($this->host));
        }

        if (null !== $this->proxyUri) {
            $options['http'] = array(
                'proxy' => $this->proxyUri,
                'request_fulluri' => $this->requestFullUri
            );
        }

        return $options;
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
