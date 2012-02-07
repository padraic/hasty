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

    public function clear()
    {
        $this->headers = array();
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

    public function fromString($string)
    {
        $current = array();
        foreach (preg_split('#\r\n#', $string) as $line) {
            if (preg_match('/^(?P<name>[^()><@,;:\"\\/\[\]?=}{ \t]+):.*$/', $line, $matches)) {
                if ($current) {
                    list($name, $value) = preg_split('#: #', $current['line'], 2);
                    $this->set($name, $value);
                }
                $current = array(
                    'name' => $matches['name'],
                    'line' => trim($line)
                );
            } elseif (preg_match('/^\s+.*$/', $line, $matches)) {
                $current['line'] .= trim($line);
            } elseif (preg_match('/^\s*$/', $line)) {
                break;
            } else {
                throw new \RuntimeException(sprintf(
                    'Line "%s" does not match header format!',
                    $line
                ));
            }
        }
        if ($current) {
            list($name, $value) = preg_split('#: #', $current['line'], 2);
            $this->set($name, $value);
        }
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