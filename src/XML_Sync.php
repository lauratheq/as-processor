<?php

namespace juvo\AS_Processor;

use Automatic_CSS\Framework\Platforms\Generate;
use Generator;

abstract class XML_Sync extends Sync
{

    protected int $chunkSize = 5000;
    protected string $delimiter = ',';
    protected bool $hasHeader = true;
    protected string $srcEncoding = "";

    public function set_hooks(): void
    {
        parent::set_hooks();
        add_action($this->get_sync_name(), [$this, 'schedule_xml_chunks']);
    }

    abstract protected function get_source_xml_path(): string;

    /**
     * Schedules each xml export chunk file
     *
     * @return void
     */
    public function schedule_xml_chunks(): void
    {
        $export_path = $this->get_source_xml_path();
        $files = scandir($export_path);

        foreach ($files as $file) {
            # ignore every file than our export files
            if (strpos($file, 'export_') === FALSE) {
                continue;
            }
            $filename = $export_path . $file;
            $this->schedule_xml_chunk($filename);
        }
    }

    /**
     * Reads the XML Chunk file and moves the data to the processor
     *
     * @param array $data
     * @return void
     */
    public function process_chunk(array $data): void
    {
        $xml_file = $data['chunk_file_path'];
        $content = file_get_contents($xml_file);

        # create an xml object and convert it to an array
        $data = simplexml_load_string($content);
        $xml_parser = new XMLSerializer();
        $data = $xml_parser->maybe_convert_to_array($data);

        $formatted_data = $this->generator($data);

        $this->process_chunk_data($formatted_data);
    }

    public function generator($data): Generator
    {
        foreach ($data as $entry) {
            yield $entry;
        }
    }

    /**
     * Schedules an async chunk job, but first saves the chunk data to a file
     *
     * @param string $chunk_file_path
     * @return void
     */
    private function schedule_xml_chunk(string $chunk_file_path): void
    {
        $this->schedule_chunk([
            'chunk_file_path' => $chunk_file_path
        ]);
    }
}
