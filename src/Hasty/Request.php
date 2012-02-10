<?php

namespace Hasty;

class Request
{

    const GET = 'GET';
    const POST = 'POST';
    const HEAD = 'HEAD';
    const PUT = 'PUT';
    const DELETE = 'DELETE';
    const OPTIONS = 'OPTIONS';
    const TRACE = 'TRACE';
    const CONNECT = 'CONNECT';

    const HTTP_10 = '1.0';
    const HTTP_11 = '1.1';

    protected $options = array(
        'timeout' => 30,
        'context' => null,
        'max_redirects' => 5
    );

    protected $method = self::GET;

    protected $uri = null;

    protected $version = self::HTTP_10;

    protected $parameters = array();

    public $headers = null;

    protected $query = array();

    protected $post = array();

    protected $file = array();

    protected $uriScheme = null;

    protected $uriHost = null;

    protected $uriPort = null;

    protected $uriPath = null;

    protected $socketUri = null;

    protected $context = null; // set this to fix the stupid insecure PHP defaults

    protected $timeout = 30.0;

    public function __construct($url, array $options = null)
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(
                'Unable to create a new request due to an invalid URL: '.$url
            );
        }
        $this->headers = new HeaderStore;
        if (!is_null($options)) {
            $this->setOptions($options);
        }
        $this->processUri($url);
    }

    public function setOptions(array $options)
    {
        $options = $this->processOptions($options);
        $this->options = $this->options + $options;
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function setMethod($method)
    {
        if (!in_array($method, array(self::GET, self::POST, self::HEAD,
        self::PUT, self::DELETE, self::OPTIONS, self::TRACE, self::CONNECT))) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid method type given: %s', $method
            ));
        }
        $this->method = $method;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function setUri($uri)
    {
        if (!filter_var($uri, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid HTTP URI provided: %s', $uri
            ));
        }
        $this->processUri($uri);
    }

    public function getUri()
    {
        return $this->uri;
    }

    public function getUrl()
    {
        return $this->getUri();
    }

    public function setVersion($version)
    {
        if (!in_array($version, array(self::HTTP_10, self::HTTP_11))) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid protocol version string: %s', $version
            ));
        }
        $this->version = $version;
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function setQuery()
    {

    }

    public function setPost()
    {

    }

    public function setFile()
    {

    }

    public function setContext($context)
    {
        if (!is_null($context) && (!is_resource($context)
        || get_resource_type($context) !== 'stream-context')) {
            throw new \InvalidArgumentException(
                'Value of \'context\' provided to Hasty\Request must be a valid '
                . 'stream-context resource created via the stream_context_create() function'
            );
        }
        $this->context = $context;
    }

    public function getContext()
    {
        if (!is_null($this->context)) {
            return $this->context;
        }
        $this->context = stream_context_create();
        return $this->context;
    }

    public function setTimeout($timeout)
    {
        $this->timeout = (float) $timeout;
    }

    public function getTimeout()
    {
        return $this->timeout;
    }

    // parseable URI info

    public function setUriScheme($scheme)
    {
        $this->uriScheme = $scheme;
    }

    public function setUriHost($host)
    {
        $this->uriHost = $host;
    }

    public function setUriPort($port)
    {
        $this->uriPort = $port;
    }

    public function setUriPath($path)
    {
        $this->uriPath = $path;
    }

    public function setSocketUri($uri)
    {
        $this->socketUri = $uri;
    }

    public function getUriScheme()
    {
        return $this->uriScheme;
    }

    public function getUriHost()
    {
        return $this->uriHost;
    }

    public function getUriPort()
    {
        return $this->uriPort;
    }

    public function getUriPath()
    {
        return $this->uriPath;
    }

    public function getSocketUri()
    {
        return $this->socketUri;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function isGet()
    {
        return $this->getMethod() === self::GET;
    }

    public function isPost()
    {
        return $this->getMethod() === self::POST;
    }

    public function isHead()
    {
        return $this->getMethod() === self::HEAD;
    }

    public function isPut()
    {
        return $this->getMethod() === self::PUT;
    }

    public function isDelete()
    {
        return $this->getMethod() === self::DELETE;
    }

    public function isOptions()
    {
        return $this->getMethod() === self::OPTIONS; 
    }

    public function isTrace()
    {
        return $this->getMethod() === self::TRACE;
    }

    public function isConnect()
    {
        return $this->getMethod() === self::CONNECT;
    }

    public function isSecure()
    {
        return $this->getUriScheme() === 'https';
    }

    public function toString()
    {
        $this->headers->set('host', $this->getUriHost());
        $this->headers->set('connection', 'close');
        $request = $this->getMethod()
            . " "
            . $this->getUriPath()
            . " HTTP/"
            . $this->getVersion()
            . "\r\n"
            . $this->headers->toString()
            . "\r\n";
        return $request;
    }

    public function __toString()
    {
        return $this->toString();
    }

    public function fromString($string)
    {

    }

    protected function processUri($uri)
    {
        $parts = parse_url($uri);
        $port = '';
        $socket = '';
        $host = $parts['host'];
        $path = '';
        $request = '';
        switch ($parts['scheme']) {
            case 'http':
                if (isset($parts['port'])) {
                    $port = $parts['port'];
                    $host = $host.':'.$port;
                } else {
                    $port = '80';
                }
                $socket = 'tcp://'.$host.':'.$port;
                break;
            case 'https':
                if (isset($parts['port'])) {
                    $port = $parts['port'];
                    $host = $host.':'.$port;
                } else {
                    $port = '443';
                }
                $socket = 'ssl://'.$host.':'.$port;
                break;
            default:
                throw new \InvalidArgumentException(sprintf(
                    'Unable to add a new request due to an unsupported URL schema in: %s', $uri
                ));
                break;
        }
        if (isset($parts['path'])) {
            $path = $parts['path'];
        } else {
            $path = '/';
        }
        $this->uri = $uri;
        $this->setUriScheme($parts['scheme']);
        $this->setUriHost($host);
        $this->setUriPort($port);
        $this->setUriPath($path);
        $this->setSocketUri($socket);
    }

    protected function processOptions(array $options)
    {
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'timeout':
                    $value = (float) $value;
                    $value = max($value, $this->options[$key]);
                    $options[$key] = (float) $value;
                    break;
                case 'max_redirects':
                    $value = (int) $value;
                    if ($value <= 0) {
                        throw new \InvalidArgumentException(
                            'Value of \'max_redirects\' provided to Hasty\\Request must be greater '
                            . 'than zero'
                        );
                    }
                    $options[$key] = $value;
                    break;
                case 'headers':
                    if (!is_array($value)) { // TODO - accept HeaderStore ;)
                        throw new \InvalidArgumentException(
                            'Value of \'headers\' provided to Hasty\\Request must be an '
                            . 'associative array of header names and values.'
                        );
                    }
                    $this->headers->populate($value);
                    unset($options['headers']);
                    break;
                case 'method':
                    $this->setMethod($value);
                    unset($options['method']);
                    break;
            }
        }
        return $options;
    }

}