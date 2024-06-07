<?php

namespace juvo\AS_Processor;

use PhpParser\Node\Expr\Print_;

class XMLSerializer
{
    private $stack;

    /**
     * @param   object $object to be serialized as xml
     */
    public function __construct()
    {
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public function maybe_convert_to_array($data)
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (!is_array($data)) {
            return $data;
        }

        if (count($data) == 0) {
            return [];
        }

        $output = [];
        foreach ($data as $key => $o_a) {
            # remove special chars
            $key = preg_replace('/[\x00-\x1F\x7F]/u', '', $key);
            $key = str_replace('*', '', $key);
            $output[$key] = $this->maybe_convert_to_array($o_a);
        }
        return $output;
    }

    /**
     * @return string
     */
    public function get_xml_header(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" ?>'."\n";;
    }

    /**
     * @param string $node_name
     * @return string
     */
    public function get_node_start($node_name): string
    {
        return '<'.$node_name.'>'."\n";
    }

    /**
     * @param string $node_name
     * @return string
     */
    public function get_node_end($node_name): string
    {
        return '</'.$node_name.'>'."\n";
    }

    /**
     * @param mixed $object
     * @return string
     */
    public function get_node($object, $node_name = 'node'): string
    {
        $object = $this->maybe_convert_to_array($object);

        $xml = '<'.$node_name.'>';
        if (is_array($object)) {
            foreach ($object as $key => $value) {
                if (is_numeric($key)) {
                    $key = $node_name;
                }
                $xml .= $this->get_node($value, $key);
            }
        } else {
            $xml .= htmlspecialchars(
                $object,
                ENT_QUOTES | ENT_HTML5 | ENT_DISALLOWED | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }

        $xml .= '</'.$node_name.'>'."\n";

        return $xml;
    }
}
