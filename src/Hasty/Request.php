<?php

namespace Hasty;

class Request
{

    protected $options = array(
        'timeout' => 30,
        'context' => null,
        'max_redirects' => 5,
        'headers' => array(),
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

    public function __construct($url, array $options = null)
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(
                'Unable to create a new request due to an invalid URL: '.$url
            );
        }
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
        $this->set('headers', array('Host' => $host)); // this doesn't work obviously :P
        $this->set('headers', array('Connection' => 'close')); 
        if (isset($parts['path'])) {
            $path = $parts['path'];
        } else {
            $path = '/';
        }
        $request = $this->get('method')
            . " "
            . $path
            . " HTTP/1.0\r\n"; // for now...
        foreach ($this->get('headers') as $name => $value) {
            $request .= trim($name)
                . ": "
                . trim($value)
                . "\r\n";
        }
        $request .= "\r\n";
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
                    if (!is_array($value)) {
                        throw new \InvalidArgumentException(
                            'Value of \'headers\' provided to Hasty\\Request must be an '
                            . 'associative array of header names and values.'
                        );
                    }
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
}