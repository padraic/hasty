<?php

namespace Hasty;

class HeaderStore implements Countable
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

    public function toString()
    {
        if (count($headers) == 0) {
            return '';
        }
        ksort($this->headers);
        $string = '';
        foreach ($headers as $name => $value) {
            $parts = explode($name, '-');
            $parts = array_map('ucfirst', $parts);
            $string .= implode('-', $parts) . ': ' . $value . "\r\n";
        }
        return $string;
    }

    public function __toString()
    {
        return $this->toString();
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