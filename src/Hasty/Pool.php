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

    const STATUS_PROGRESSING = 'progressing';
    const STATUS_TIMEDOUT = 'timedout';
    const STATUS_ERROR = 'error';
    const STATUS_READING = 'reading';
    const STATUS_COMPLETED = 'completed';
    const STATUS_WAITINGFORRESPONSE = 'waitingforresponse';
    const STATUS_CONNECTIONFAILED = 'connectionfailed';

    protected $defaultOptions = array(
        'timeout' => 30,
        'context' => null,
        'max_redirects' => 3,
        'chunk_size' => 1024
    );

    protected $requests = array();

    protected $responses = array();

    protected $streams = array();

    protected $states = array();

    protected $streamCounter = 0;

    protected $maxTimeout = 30;

    protected $writeBuffers = array();

    public function __construct(array $options = null)
    {
        if (!is_null($options)) {
            $options = $this->processOptions($options);
            $this->defaultOptions = $this->defaultOptions + $options;
        }
    }

    public function attach($request, array $requestOptions = null)
    {   
        if(!$request instanceof Request) {
            if(!is_null($requestOptions)) $requestOptions = $requestOptions + $this->defaultOptions;
            $request = new Request($request, $requestOptions);
        } else {
            $request->setOptions($request->getOptions() + $this->defaultOptions);
        }
        $pointer = null;
        $errorCode = null;
        $errorString = null;
        set_error_handler(function($severity, $message, $file, $line) {
            throw new \ErrorException($message, $severity, $severity, $file, $line);
        });
        $pointer = stream_socket_client(
            $request->getSocketUri(),
            $errorCode,
            $errorString,
            $request->getTimeout(),
            STREAM_CLIENT_ASYNC_CONNECT|STREAM_CLIENT_CONNECT,
            $request->getContext()
        );
        restore_error_handler();
        if ($pointer === false) {
            throw new \RuntimeException(sprintf(
                'Error encountered while attempting to open a socket with message: %s',
                $errorString
            ));
        }
        stream_set_blocking($pointer, 0);
        $this->stashRequest($request, $pointer);
        return $this;
    }

    public function run()
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
        return $this->responses; // later, we'll add callbacks...
    }

    public function reset()
    {
        $this->responses = array();
        $this->requests = array();
        $this->streams = array();
        $this->writeBuffers = array();
        $this->streamCounter = 0;
        $this->maxTimeout = 30;
    }

    public function getResponses()
    {
        return $this->responses;
    }

    public function getDefaultOptions()
    {
        return $this->defaultOptions;
    }

    public function getDefaultOption($key)
    {
        if (array_key_exists($key, $this->defaultOptions)) {
            return $this->defaultOptions[$key];
        }
        return null;
    }

    public function getMaxTimeout()
    {
        return $this->maxTimeout;
    }

    protected function setMaxTimeout($timeout)
    {
        $this->maxTimeout = (int) $timeout;
    }

    protected function performRead($read)
    {
        $id = array_search($read, $this->streams);
        $response = $this->responses[$id];
        $chunk = fread($read, $response->getChunkSize());
        $response->appendChunk($chunk);
        if (count($response->headers) > 0 && $response->isRedirect()) {
            $response->setRequestStatus(self::STATUS_COMPLETED);
            fclose($read);
            unset($this->streams[$id]);
            return;
        } elseif (count($response->headers) > 0) {
            $response->setChunkSize(32768);
        }
        $meta = stream_get_meta_data($read);
        $active = !feof($read)
            && !$meta['eof']
            && !$meta['timed_out']
            && strlen($chunk);
        if (!$active) {
            if ($response->getRequestStatus() == self::STATUS_PROGRESSING) {
                $response->setRequestStatus(self::STATUS_CONNECTIONFAILED);
            } else {
                $response->setRequestStatus(self::STATUS_COMPLETED);
            }
            fclose($read);
            unset($this->streams[$id]);
        } else {
            $response->setRequestStatus(self::STATUS_READING);
        }
    }

    protected function performWrite($write)
    {
        $id = array_search($write, $this->streams);
        $response = $this->responses[$id];
        $request = $this->requests[$id];
        if (isset($this->streams[$id])
        && $response->getRequestStatus() == self::STATUS_PROGRESSING) {
            if (!isset($this->writeBuffers[$id])) {
                $this->writeBuffers[$id] = $request->toString();
            }
            $size = strlen($this->writeBuffers[$id]);
            $written = fwrite($write, $this->writeBuffers[$id], $size);
            if ($written >= $size) {
                $response->setRequestStatus(self::STATUS_WAITINGFORRESPONSE);
                unset($this->writeBuffers[$id]);
            } else {
                $this->writeBuffers[$id] = substr($this->writeBuffers[$id], $written);
            }
        }
    }

    protected function handleRequestErrorFromRead()
    {

    }

    protected function handleRequestRedirectFromRead()
    {

    }

    protected function handleRedirectFor()
    {

    }

    protected function stashRequest(Request $request, $pointer)
    {
        $this->streams[$this->streamCounter] = $pointer;
        $this->responses[$this->streamCounter] = new Response(
            array('url' => $request->getUri(),
            'options' => $request->getOptions(),
            'request' => $request,
            'raw_request' => $request->toString(),
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
        $this->requests[$this->streamCounter] = $request;
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
                            'Value of \'context\' provided to Hasty\Pool must be a valid '
                            . 'stream-context resource created via the stream_context_create() function'
                        );
                    }
                    break;
                case 'max_redirects':
                    $value = (int) $value;
                    if ($value <= 0) {
                        throw new \InvalidArgumentException(
                            'Value of \'max_redirects\' provided to Hasty\Pool must be greater '
                            . 'than zero'
                        );
                    }
                    $options[$key] = $value;
                    break;
            }
        }
        return $options;
    }

}