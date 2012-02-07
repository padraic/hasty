<?php

namespace Hasty;

/**
 * TODO
 *
 * - Improve error handling
 * - Support PUT/DELETE [DONE]
 * - Support Cookies
 * - Support chunked transfer-encoding [DONE]
 * - Support non-HTTP/HTTPS schemas
 * - Add HTTP 1.1 support
 * - Add support for keep-alive in HTTP 1.1
 * - Implement Request/Response proper objects [DONE]
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

    const HTTP_10 = '1.0';
    const HTTP_11 = '1.1';

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
        'headers' => array(),
        'max_redirects' => 3,
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
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
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
        418 => 'I\'m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        511 => 'Network Authentication Required',
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
            $requestOptions = array_merge($this->options, (array) $requestOptions);
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

    protected function performRead($read)
    {
        $id = array_search($read, $this->streams);
        $response = $this->responses[$id];
        $options = $response->get('options');
        $chunk = fread($read, $options['chunk_size']);
        $response->appendChunk($chunk);
        if (count($response->headers) > 0 && $response->isRedirect()) {
            $response->set('status', self::STATUS_COMPLETED);
            fclose($read);
            unset($this->streams[$id]);
            return;
        } elseif (count($response->headers) > 0) {
            $response->set('options', array('chunk_size'=>32768));
        }
        $meta = stream_get_meta_data($read);
        $active = !feof($read)
            && !$meta['eof']
            && !$meta['timed_out']
            && strlen($chunk);
        if (!$active) {
            if ($response->get('status') == self::STATUS_PROGRESSING) {
                $response->set('status', self::STATUS_CONNECTIONFAILED);
            } else {
                $response->set('status', self::STATUS_COMPLETED);
            }
            fclose($read);
            unset($this->streams[$id]);
        } else {
            $response->set('status', self::STATUS_READING);
        }
    }

    protected function performWrite($write)
    {
        $id = array_search($write, $this->streams);
        $response = $this->responses[$id];
        if (isset($this->streams[$id])
        && $response->get('status') == self::STATUS_PROGRESSING) {
            $size = strlen($response->get('raw_request'));
            $written = fwrite($write, $response->get('raw_request'), $size);
            if ($written >= $size) {
                $response->set('status', self::STATUS_WAITINGFORRESPONSE);
            } else {
                $response->set('raw_request', substr(
                    $response->get('raw_request'),
                    $written
                ));
            }
        }
    }

    protected function handleRequestErrorFromRead()
    {

    }

    protected function handleRequestRedirectFromRead()
    {

    }

    protected function handleRedirectFor($id, $code)
    {
        $response = $this->responses[$id];
        $headers = $response->headers;
        $options = $response->get('options');
        if (!filter_var($headers->get('location'), FILTER_VALIDATE_URL)) {
            $location = '';
            $parts = @parse_url($headers->get('location'));
            $base = @parse_url($response->get('raw_request')); //update to raw?
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
                .' from the details received from'.$response->get('request'));
            }
        } else {
            $location = $headers->get('location');
        }
        if ($options['max_redirects'] <= 0) {
            $response->set('error', true);
            $response->set('message', 'Maximum redirects have been exhausted');
        } else {
            $response->set('max_redirects', $responses->get('max_redirects')-1); //move to local status store
            $headers->set('referer', $response->get('url'));
            $headers->remove('host');
            $response->set('redirect_code', $code);
        }
        $response->set('redirect_uri', $location);
        $this->add($location, $response->get('options'));
    }

    protected function stashRequest(Request $request, $pointer)
    {
        $this->streams[$this->streamCounter] = $pointer;
        $this->responses[$this->streamCounter] = new Response(
            array('url' => $request->get('url'),
            'options' => $request->getOptions(),
            'request' => $request,
            'raw_request' => $request->get('raw_request'),
            'status' => self::STATUS_PROGRESSING,
            'data' => '',
            'redirect_uri' => null,
            'redirect_code' => null,
            'id' => null,
            'protocol' => null,
            'code' => null,
            'message' => null,
            'error' => false)
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