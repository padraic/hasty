<?php

namespace Hasty;

class Response
{

    protected $data = array(
        'url' => null,
        'options' => null,
        'request' => null,
        'raw_request' => '',
        'status' => Pool::STATUS_PROGRESSING,
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

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function get($key)
    {
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }
    }

    public function set($key, $value)
    {
        if (!array_key_exists($key, $this->data)) {
            throw new \InvalidArgumentException (
                'Data key does not exist: '.$key
            );
        }
        $data = array($key => $value);
        $this->data = array_merge($this->data, $data);
    }

    public function getDataArray() // needed?
    {
        return $this->data;
    }

}