<?php

namespace Hasty;

class Request
{

    protected $options = array(
        'timeout' => 30,
        'context' => null,
        'max_redirects' => 5,
        'method' => Pool::GET,
        'chunk_size' => 1024,
        'socket' => '',
        'host' => '',
        'port' => '',
        'raw_request' => '',
        'scheme' => '',
        'path' => '',
        'url' => ''
    );

    public $headers = null;

    public function __construct($url, array $options = null)
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(
                'Unable to create a new request due to an invalid URL: '.$url
            );
        }
        $this->headers = new HeaderStore;
        if (!is_null($options)) {
            $options = $this->processOptions($options);
            $this->options = array_merge($this->options, $options);
        }
        $this->processUrl($url);
    }

    public function get($key)
    {
        if (isset($this->options[$key])) {
            return $this->options[$key];
        }
    }

    public function set($key, $value)
    {
        if (!array_key_exists($key, $this->options)) {
            throw new \InvalidArgumentException (
                'Option does not exist: '.$key
            );
        }
        $option = array($key => $value);
        $option = $this->processOptions($option);
        $this->options = array_merge($this->options, $option);
    }

    public function getOptions()
    {
        return $this->options;
    }

    protected function processUrl($url)
    {
        $parts = parse_url($url);
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
                throw new \InvalidArgumentException(
                    'Unable to add a new request due to an unsupported URL schema in: '.$url
                );
                break;
        }
        $this->headers->set('host', $host);
        $this->headers->set('connection', 'close');
        if (isset($parts['path'])) {
            $path = $parts['path'];
        } else {
            $path = '/';
        }
        $request = $this->get('method')
            . " "
            . $path
            . " HTTP/1.0\r\n" // for now...
            . $this->headers->toString()
            . "\r\n";
        $this->set('scheme', $port);
        $this->set('host', $port);
        $this->set('port', $port);
        $this->set('path', $port);
        $this->set('raw_request', $request);
        $this->set('url', $url);
        $this->set('socket', $socket);
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
                case 'context':
                    if (!is_null($value) && (!is_resource($value)
                    || get_resource_type($value) !== 'stream-context')) {
                        throw new \InvalidArgumentException(
                            'Value of \'context\' provided to Hasty\\Request must be a valid '
                            . 'stream-context resource created via the stream_context_create() function'
                        );
                    }
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
                    if (!in_array($value, array(Pool::GET, Pool::POST, Pool::HEAD))) {
                        throw new \InvalidArgumentException(
                            'Value of \'method\' provided to Hasty\\Request must be one of '
                            . 'GET, POST or HEAD'
                        );
                    }
                    break;
            }
        }
        return $options;
    }

    // API to implement from Pool

    const GET = 'GET';
    const POST = 'POST';
    const HEAD = 'HEAD';
    const PUT = 'PUT';
    const DELETE = 'DELETE';
    const OPTIONS = 'OPTIONS';
    const TRACE = 'TRACE';
    const CONNECT = 'CONNECT';

    protected $method = null;

    protected $uri = null;

    protected $parameters = array();

    public $headers = null;

    protected $query = array();

    protected $post = array();

    protected $file = array();



    public function setMethod($method)
    {
        
    }

    public function getMethod()
    {
        
    }

    public function setUri($uri)
    {
        
    }

    public function getUri()
    {
        
    }

    public function getUrl()
    {
        return $this->getUri();
    }

    public function setVersion($version)
    {

    }

    public function getVersion()
    {

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

    public function getHeaders()
    {
        return $this->headers;
    }

    public function isGet()
    {

    }

    public function isPost()
    {

    }

    public function isHead()
    {

    }

    public function isPut()
    {

    }

    public function isDelete()
    {

    }

    public function isOptions()
    {

    }

    public function isTrace()
    {

    }

    public function isConnect()
    {

    }

    public function toString()
    {

    }

    public function __toString()
    {
        return $this->toString();
    }

    public function fromString($string)
    {

    }


}