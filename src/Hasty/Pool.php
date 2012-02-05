<?php

namespace Hasty;

/**
 * TODO
 *
 * - Improve error handling
 * - Support PUT/DELETE
 * - Support Cookies
 * - Support chunked transfer-encoding
 * - Support non-HTTP/HTTPS schemas
 * - Add HTTP 1.1 support
 * - Add support for keep-alive in HTTP 1.1
 * - Implement Request/Response proper objects
 * - Check RFC 3986 compliance
 * - Added request data and encoding support (query strings and post data)
 * - Support restrictions on parallel request count both globally and by host
 * - Support for discarding responses, i.e. discard streams after writing?
 * - Refactor, refactor, refactor...
 */
class Pool
{

    const GET = 'GET';
    const POST = 'POST';
    const HEAD = 'HEAD';

    const STATUS_PROGRESSING = 'progressing';
    const STATUS_TIMEDOUT = 'timedout';
    const STATUS_ERROR = 'error';
    const STATUS_READING = 'reading';
    const STATUS_COMPLETED = 'completed';
    const STATUS_WAITINGFORRESPONSE = 'waitingforresponse';
    const STATUS_CONNECTIONFAILED = 'connectionfailed';

    protected $options = array(
        'timeout' => 30,
        'context' => null,
        'max_redirects' => 3,
        'headers' => array(),
        'method' => self::GET,
        'chunk_size' => 1024
    );

    protected $requests = array();
    protected $responses = array();
    protected $streams = array();
    protected $streamCounter = 0;
    protected $maxTimeout = 30;

    protected $responseCodes = array(
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported'
      );

    public function __construct(array $options = null)
    {
        if (!is_null($options)) {
            $options = $this->processOptions($options);
            $this->options = array_merge($this->options, $options);
        }
    }

    public function add($request, array $requestOptions = null)
    {   
        if(!$request instanceof Request) {
            $request = new Request($request, $requestOptions);
        }
        $pointer = null;
        $errorCode = null;
        $errorString = null;
        set_error_handler(function($severity, $message, $file, $line) {
            throw new \ErrorException($message, $severity, $severity, $file, $line);
        });
        if (is_null($request->get('context'))) { // needed dup?
            $pointer = stream_socket_client(
                $request->get('socket'),
                $errorCode,
                $errorString,
                $request->get('timeout'),
                STREAM_CLIENT_ASYNC_CONNECT|STREAM_CLIENT_CONNECT
            );
        } else {
            $pointer = stream_socket_client(
                $request->get('socket'),
                $errorCode,
                $errorString,
                $request->get('timeout'),
                STREAM_CLIENT_ASYNC_CONNECT|STREAM_CLIENT_CONNECT,
                $options['context']
            );
        }
        restore_error_handler();
        if ($pointer === false) {
            throw new \RuntimeException(
                'Error encountered while attempting to open a socket to: '.$socket
            );
        }
        stream_set_blocking($pointer, 0);
        $this->stashRequest($request, $pointer);
        return $this;
    }

    public function execute()
    {
        if (count($this->responses) == 0) {
            throw new \RuntimeException(
                'Unable to execute request pool as there appear to be no requests pooled. '
                . 'You may want to add a few!'
            );
        }
        set_error_handler(function($severity, $message, $file, $line) {
            throw new \ErrorException($message, $severity, $severity, $file, $line);
        });
        while (!empty($this->streams)) {
            $excepts = array();
            $reads = $writes = $this->streams;
            $result = stream_select($reads, $writes, $excepts, $this->maxTimeout);
            if ($result === false) {
                throw new \RuntimeException(
                    'Unexpected error encountered while opening streams'
                );
            }
            if ($result > 0) {
                foreach ($reads as $read) {
                    $this->performRead($read);
                }
                foreach ($writes as $write) {
                    $this->performWrite($write);
                }
            } else {
                break;
            }
            if (!empty($this->streams)) {
                usleep(30000);
            }
        }
        restore_error_handler();
        $this->decodeResponsesData();
        return $this->responses;
    }

    public function reset()
    {
        $this->responses = array();
        $this->requests = array();
        $this->streams = array();
        $this->streamCounter = 0;
        $this->maxTimeout = 30;
    }

    public function getResponses()
    {
        return $this->responses;
    }

    public function getDefaultOptions()
    {
        return $this->options;
    }

    public function getDefaultOption($key)
    {
        if (isset($options[$key])) {
            return $options[$key];
        }
        return null;
    }

    public function getMaxTimeout()
    {
        return $this->maxTimeout;
    }

    protected function setMaxTimeout($timeout)
    {
        $this->maxTimeout = $timeout;
    }

    protected function parseHeaders($id)
    {
        if (count($this->responses[$id]['headers']) > 0) {
            return;
        }
        $split = preg_split("/\r\n\r\n|\n\n|\r\r/", $this->responses[$id]['data'], 2);
        $headers = preg_split("/\r\n|\n|\r/", $split[0]);
        $content = $this->responses[$id]['data'] = $split[1];
        $protocol = explode(' ', trim(array_shift($headers)), 3);
        $this->responses[$id]['protocol'] = $protocol[0];
        $code = $protocol[1];
        if (isset($protocol[2])) {
            $this->responses[$id]['message'] = $protocol[2];
        }
        while ($header = trim(array_shift($headers))) {
            $parts = explode(':', $header, 2);
            $name = strtolower($parts[0]);
            $this->responses[$id]['headers'][$name] = trim($parts[1]);
        }
        if (!isset($this->responseCodes[$code])) {
            $code = floor($code / 100) * 100;
        }
        $this->responses[$id]['code'] = $code;
        switch ($code) {
            case 200:
            case 304:
                break;
            case 301:
            case 302:
            case 307:
                $this->handleRedirectFor($id, $code);
                break;
            default:
                $this->responses[$id]['error'] = true;
                $this->responses[$id]['message'] = $this->responses[$id]['status'];
        }
    }

    protected function performRead($read)
    {
        $id = array_search($read, $this->streams);
        $content = fread($read, $this->responses[$id]['options']['chunk_size']);
        $this->responses[$id]['data'] .= $content;
        $meta = stream_get_meta_data($read);
        if (empty($this->responses[$id]['headers']) && (
            strpos($this->responses[$id]['data'], "\r\r")
            || strpos($this->responses[$id]['data'], "\r\n\r\n")
            || strpos($this->responses[$id]['data'], "\n\n")
        )) {
            $this->parseHeaders($id);
            if (count($this->responses[$id]['headers']) > 0) {
                if (!empty($this->responses[$id]['redirect_uri'])) {
                    $this->responses[$id]['status'] = self::STATUS_COMPLETED;
                    fclose($read);
                    unset($this->streams[$id]);
                    return;
                }
            }
            $this->responses[$id]['options']['chunk_size'] = 32768;
        }
        $active = !feof($read)
            && !$meta['eof']
            && !$meta['timed_out']
            && strlen($content);
        if (!$active) {
            if ($this->responses[$id]['status'] == self::STATUS_PROGRESSING) {
                $this->responses[$id]['status'] = self::STATUS_CONNECTIONFAILED;
            } else {
                $this->responses[$id]['status'] = self::STATUS_COMPLETED;
            }
            fclose($read);
            unset($this->streams[$id]);
        } else {
            $this->responses[$id]['status'] = self::STATUS_READING;
        }
    }

    protected function performWrite($write)
    {
        $id = array_search($write, $this->streams);
        if (isset($this->streams[$id])
        && $this->responses[$id]['status'] == self::STATUS_PROGRESSING) {
            $size = strlen($this->responses[$id]['request']);
            $written = fwrite($write, $this->responses[$id]['request'], $size);
            if ($written >= $size) {
                $this->responses[$id]['status'] = self::STATUS_WAITINGFORRESPONSE;
            } else {
                $this->responses[$id]['request'] = substr(
                    $this->responses[$id]['request'],
                    $written
                );
            }
        }
    }

    protected function handleRequestErrorFromRead()
    {

    }

    protected function handleRequestRedirectFromRead()
    {

    }

    protected function decodeResponsesData()
    {
        foreach ($this->responses as $id => $response) {
            if (isset($response['headers']['transfer-encoding'])
            && $response['headers']['transfer-encoding'] = 'chunked') {
                # TODO
            } elseif (isset($response['headers']['content-encoding'])
            && $response['headers']['transfer-encoding'] = 'gzip') {
                $this->responses[$id]['data'] = gzinflate(substr($reponse['data'], 10));
            } elseif (isset($response['headers']['transfer-encoding'])
            && $response['headers']['transfer-encoding'] = 'deflate') {
                $this->responses[$id]['data'] = gzinflate($reponse['data']);
            }
        }
    }

    protected function handleRedirectFor($id, $code)
    {
        if (!filter_var($this->responses[$id]['headers']['location'], FILTER_VALIDATE_URL)) {
            $location = '';
            $parts = @parse_url($this->responses[$id]['headers']['location']);
            $base = @parse_url($this->responses[$id]['request']);
            if (empty($parts['scheme'])) {
                $parts['scheme'] = $base['scheme'];
            }
            if (empty($parts['host']) && !empty($base['host'])) {
                $parts['host'] = $base['host'];
            } else {
                $parts['host'] = $_SERVER['HTTP_HOST'];
            }
            if (empty($parts['port']) && !empty($base['port'])) {
                $parts['port'] = $base['port'];
            }
            if (isset($parts['scheme'])) {
                $location .= $parts['scheme'].'://';
            }
            if (isset($parts['host'])) {
                $location .= $parts['host'];
            }
            if (!empty($parts['port'])) {
                $location .= ':'.$parts['port'];
            }
            if ('/' !== $parts['path'][0]) {
                $parts['path'] = '/'.$parts['path'];
            }
            if (isset($parts['query'])) {
                $location .= '?'.$parts['query'];
            }
            if (isset($parts['fragment'])) {
                $location .= '#'.$parts['fragment'];
            }
            if (!filter_var($location, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('Unable to construct a valid redirect URI'
                .' from the details received from'.$this->responses[$id]['request']);
            }
        } else {
            $location = $this->responses[$id]['headers']['location'];
        }
        if ($this->responses[$id]['options']['max_redirects'] <= 0) {
            $this->responses[$id]['error'] = true;
            $this->responses[$id]['message'] = 'Maximum redirects have been exhausted';
        } else {
            $this->responses[$id]['max_redirects']--;
            $this->responses[$id]['headers']['Referer'] = $this->responses[$id]['url'];
            unset($this->responses[$id]['headers']['Host']);
            $this->responses[$id]['redirect_code'] = $code;
        }
        $this->responses[$id]['redirect_uri'] = $location;
        $this->addRequest($location, $this->responses[$id]['options']);
    }

    protected function stashRequest(Request $request, $pointer)
    {
        $this->streams[$this->streamCounter] = $pointer;
        $this->responses[$this->streamCounter] = array(
            'url' => $request->get('url'),
            'options' => $request->getOptions(),
            'request' => $request->get('raw_request'),
            'status' => self::STATUS_PROGRESSING,
            'headers' => array(),
            'data' => '',
            'redirect_uri' => null,
            'redirect_code' => null,
            'id' => null,
            'protocol' => null,
            'code' => null,
            'message' => null,
            'error' => false
        );
        $this->streamCounter++;
    }

    protected function processOptions(array $options)
    {
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'timeout':
                    $value = (float) $value;
                    $value = max($value, $this->getDefaultOption('timeout'));
                    $options[$key] = (float) $value;
                    $this->setMaxTimeout((float) $value);
                    break;
                case 'context':
                    if (!is_resource($value) || get_resource_type($value) !== 'stream-context') {
                        throw new \InvalidArgumentException(
                            'Value of \'context\' provided to HttpRequestPool must be a valid '
                            . 'stream-context resource created via the stream_context_create() function'
                        );
                    }
                    break;
                case 'max_redirects':
                    $value = (int) $value;
                    if ($value <= 0) {
                        throw new \InvalidArgumentException(
                            'Value of \'max_redirects\' provided to HttpRequestPool must be greater '
                            . 'than zero'
                        );
                    }
                    $options[$key] = $value;
                    break;
                case 'headers':
                    if (!is_array($value)) {
                        throw new \InvalidArgumentException(
                            'Value of \'headers\' provided to HttpRequestPool must be an '
                            . 'associative array of header names and values.'
                        );
                    }
                    break;
                case 'method':
                    if (!in_array($value, array(self::GET, self::POST, self::HEAD))) {
                        throw new \InvalidArgumentException(
                            'Value of \'method\' provided to HttpRequestPool must be one of '
                            . 'GET, POST or HEAD'
                        );
                    }
                    break;
            }
        }
        return $options;
    }

}