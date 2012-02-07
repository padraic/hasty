<?php

namespace Hasty;

class Response
{

    protected $data = array( // move anything unrelated to Status object for Pool (delete rest)
        'url' => null,
        'options' => null,
        'request' => null,
        'raw_request' => '',
        'status' => Pool::STATUS_PROGRESSING,
        'data' => '',
        'redirect_uri' => null,
        'redirect_code' => null,
        'id' => null,
        'protocol' => null,
        'code' => null,
        'message' => null,
        'error' => false
    );

    public $headers = null;

    protected $protocol = 'HTTP'; // won't change in this iteration

    protected $version = Pool::HTTP_10;

    protected $statusCode = 200;

    protected $reasonPhrase = '';

    protected $content = '';

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

    public function __construct(array $data) // deprecate!
    {
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }
        $this->headers = new HeaderStore();
    }

    public function get($key) //deprecate!
    {
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }
    }

    public function set($key, $value) //deprecate!
    {
        if (!array_key_exists($key, $this->data)) {
            throw new \InvalidArgumentException (
                'Data key does not exist: '.$key
            );
        }
        $data = array($key => $value);
        $this->data = array_merge($this->data, $data);
    }

    // the actual API to implement

    public function setVersion($version)
    {
        $this->version = $version;
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function setStatusCode($code)
    {
        $code = (int) $code;
        if (!in_array($code, array_keys($this->responseCodes))) {
            throw new \InvalidArgumentException(
                'Invalid status code provided: ' . $code
            );
        }
        $this->statusCode = $code;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function setReasonPhrase($phrase)
    {
        $this->reasonPhrase = $phrase;
    }

    public function getReasonPhrase()
    {
        if (empty($this->reasonPhrase)) {
            return $this->responseCodes[$this->getStatusCode()];
        }
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function getContent()
    {
        return $this->decodeContent($this->content);
    }

    public function setContent($content)
    {
        $this->content = $content;
    }

    public function appendContent($content)
    {
        $this->setContent(
            $this->getContent() . $content
        );
    }

    public function isClientError()
    {
        $code = $this->getStatusCode();
        return ($code < 500 && $code >= 400);
    }

    public function isInformationalError()
    {
        $code = $this->getStatusCode();
        return ($code >= 100 && $code < 200);
    }

    public function isForbidden()
    {
        return (403 == $this->getStatusCode());
    }

    public function isOk()
    {
        return (200 === $this->getStatusCode());
    }

    public function isNotFound()
    {
        return (404 === $this->getStatusCode());
    }

    public function isServerError()
    {
        $code = $this->getStatusCode();
        return (500 <= $code && 600 > $code);
    }

    public function isRedirect()
    {
        $code = $this->getStatusCode();
        return (300 <= $code && 400 > $code);
    }

    public function isSuccess()
    {
        $code = $this->getStatusCode();
        return (200 <= $code && 300 > $code);
    }

    public function toString()
    {
        $string = 'HTTP/'
            .$this->getVersion()
            .' '.$this->getStatusCode()
            .' '.$this->getReasonPhrase()
            ."\r\n"
            . (string) $this->headers
            ."\r\n"
            .$this->getContent();
        return $string;
    }

    public function __toString()
    {
        return $this->toString();
    }

    public function appendChunk($string)
    {
        if (count($this->headers) === 0) {
            $lines = preg_split('/\r\n/', $string);
            if (!is_array($lines) || count($lines) == 1) {
                $lines = preg_split ('/\n/',$string);
            }
            $firstLine = array_shift($lines);
            $matches = null;
            if (!preg_match('/^HTTP\/(?P<version>1\.[01]) (?P<status>\d{3}) (?P<reason>.*)$/',
            $firstLine, $matches)) {
                throw new \InvalidArgumentException(
                    'A valid response status line was not found in the provided string'
                );
            }
            $this->setVersion($matches['version']);
            $this->setStatusCode($matches['status']);
            $this->setReasonPhrase($matches['reason']);
            if (count($lines) == 0) {
                return;
            }
            $isHeader = true;
            $headers = $content = array();
            while ($lines) {
                $nextLine = array_shift($lines);
                if ($nextLine == '') {
                    $isHeader = false;
                    continue;
                }
                if ($isHeader) {
                    $headers[] .= $nextLine;
                } else {
                    $content[] .= $nextLine;
                }
            }
            if ($headers) {
                $this->headers->fromString(implode("\r\n", $headers));
            }
            if ($content) {
                $this->appendContent(implode("\r\n", $content));
            }
        } else {
            $this->appendContent($string);
        }  
    }

    public function fromString($string)
    {
        return $this->appendChunk($string);
    }

    protected function decodeContent($content)
    {
        if ($this->headers->contains('transfer_encoding', 'chunked')) {
            $decBody = '';
            if (function_exists('mb_internal_encoding') &&
               ((int) ini_get('mbstring.func_overload')) & 2) {
                $mbIntEnc = mb_internal_encoding();
                mb_internal_encoding('ASCII');
            }
            while (trim($content)) {
                if (!preg_match("/^([\da-fA-F]+)[^\r\n]*\r\n/sm", $content, $m)) {
                    throw new Exception\RuntimeException(
                        'Error parsing body - does not seem to be a chunked message'
                    );
                }
                $length = hexdec(trim($m[1]));
                $cut = strlen($m[0]);
                $decBody .= substr($content, $cut, $length);
                $content = substr($content, $cut + $length + 2);
            }
            if (isset($mbIntEnc)) {
                mb_internal_encoding($mbIntEnc);
            }
            return $decBody;
        } elseif ($this->headers->contains('content_encoding', 'gzip')) {
            return gzinflate(substr($content), 10);
        } elseif ($this->headers->contains('content_encoding', 'deflate')) {
            return gzinflate($content);
        }
        return $content;
    }

}