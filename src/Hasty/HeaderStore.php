<?php

namespace Hasty;

class HeaderStore implements \Countable
{

    protected $headers = array();

    public function __construct(array $headers = null)
    {
        if (!is_null($headers)) {
            foreach ($headers as $header => $value) {
                $this->set($header, $value);
            }
        }
    }

    public function set($name, $value, $replace = true)
    {
        $name = $this->normalize($name);
        if ($replace === false) {
            if (array_key_exists($name, $this->headers)) {
                return;
            }
        } else {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    public function get($name)
    {
        $name = $this->normalize($name);
        if (isset($this->headers[$name])) {
            return $this->headers[$name];
        }
    }

    public function has($name)
    {
        $name = $this->normalize($name);
        return array_key_exists($name, $this->headers);
    }

    public function contains($name, $value)
    {
        $name = $this->normalize($name);
        return $this->has($name) && $this->headers[$name] == $value;
    }

    public function remove($name)
    {
        $name = $this->normalize($name);
        if ($this->has($name)) {
            unset($this->headers[$name]);
        }
        return $this;
    }

    public function keys()
    {
        return array_keys($this->headers);
    }

    public function toArray()
    {
        return $this->headers;
    }

    public function populate(array $headers)
    {
        foreach ($headers as $name => $value) {
            $this->set($name, $value);
        }
    }

    public function toString()
    {
        if (count($this->headers) == 0) {
            return '';
        }
        $string = '';
        foreach ($this->headers as $name => $value) {
            if (strpos($name, '_')) {
                $parts = explode('_', $name);
                $parts = array_map('ucfirst', $parts);
                $name = implode('-', $parts);
            } else {
                $name = ucfirst($name);
            }
            $string .= sprintf("%s: %s\r\n", $name, $value);
        }
        return $string;
    }

    public function __toString()
    {
        return $this->toString();
    }

    /**
     * Updated for revised response - TODO
     */
    public function parseFromString($string)
    {
        $split = preg_split("/\r\n\r\n|\n\n|\r\r/", $string, 2);
        $headers = preg_split("/\r\n|\n|\r/", $split[0]);
        $content = $split[1];
        $protocolArray = explode(' ', trim(array_shift($headers)), 3);
        $protocol = $protocolArray[0];
        $code = $protocolArray[1];
        if (isset($protocolArray[2])) {
            $message = $protocolArray[2];
        } else {
            $message = '';
        }
        while ($header = trim(array_shift($headers))) {
            $parts = explode(':', $header, 2);
            $name = strtolower($parts[0]);
            $this->set($name, trim($parts[1]));
        }
        return array(
            'content' => $content,
            'protocol' => $protocol,
            'code' => $code,
            'message' => $message
        );
    }

    public function count()
    {
        return count($this->headers);
    }

    protected function normalize($name)
    {
        return strtolower(str_replace('-', '_', trim($name)));
    }

}