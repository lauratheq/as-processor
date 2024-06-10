<?php

namespace juvo\AS_Processor;

use Exception;
use Iterator;
use League\Csv\InvalidArgument;
use League\Csv\Reader;
use League\Csv\UnavailableStream;

abstract class CSV_Sync extends Sync
{

    protected int $chunkSize = 5000;
    protected string $delimiter = ',';
    protected bool $hasHeader = true;
    protected string $srcEncoding = "";

    public function set_hooks(): void
    {
        parent::set_hooks();
        add_action($this->get_sync_name(), [$this, 'split_csv_into_chunks']);
    }

    abstract protected function get_source_csv_path(): string;

    /**
     * Takes the source csv and splits it into chunk files that contain the set amount of items
     *
     * @return void
     * @throws \League\Csv\Exception
     * @throws InvalidArgument
     * @throws UnavailableStream
     * @throws \Zeller_Gmelin\Dependencies\League\Csv\Exception
     */
    public function split_csv_into_chunks(): void
    {

        $csvFilePath = $this->get_source_csv_path();
        if (!file_exists($csvFilePath)) {
            throw new Exception("Failed to open the file: $csvFilePath");
        }

        // Read csv from file
        $reader = Reader::createFromPath($this->get_source_csv_path(), 'r');
        $reader->setDelimiter($this->delimiter);

        // If src encoding is set convert table to utf-8
        if (!empty($this->srcEncoding)) {
            $reader->addStreamFilter("convert.iconv.$this->srcEncoding/UTF-8");
        }

        // Maybe add header
        if ($this->hasHeader) {
            $reader->setHeaderOffset(0);
        }

        // Process chunks
        $chunkCount = 0;
        foreach ($reader->chunkBy($this->chunkSize) as $chunk) {
            $this->schedule_csv_chunk($chunk->getRecords(), $chunkCount);
            $chunkCount++;
        }

        // Remove chunk file after sync
        unlink($csvFilePath);
    }

    /**
     * Callback function for the single chunk jobs.
     * This jobs reads the chunk file line by line and yields each line to the actual process
     *
     * @param array $data
     * @return void
     * @throws Exception
     */
    public function process_chunk(array $data): void
    {

        $file = fopen( $data['chunk_file_path'], 'r');

        $formattedDataGenerator = (function() use ($file) {
            while (($line = fgets($file)) !== false) {
                yield unserialize(trim($line));
            }
        })();

        $this->process_chunk_data($formattedDataGenerator);

        fclose($file);

        // Remove chunk file after sync
        unlink($data['chunk_file_path']);
    }

    /**
     * Generates a deterministic chunk file path
     *
     * @param int $count
     * @return string
     */
    private function get_csv_chunk_path(int $count): string
    {
        $filename = str_replace('/', '-', "{$this->get_sync_name()}_$count.csv");
        $tmp = get_temp_dir();
        return strtolower($tmp . $filename);
    }

    /**
     * Schedules an async chunk job, but first saves the chunk data to a file
     *
     * @param Iterator $chunkData
     * @param int $chunkCount
     * @return void
     */
    private function schedule_csv_chunk(Iterator $chunkData, int $chunkCount): void
    {
        $filename = $this->get_csv_chunk_path($chunkCount);

        // Write data to Chunk file
        $file = fopen($filename, 'w');
        foreach ($chunkData as $record) {
            fwrite($file, serialize($record) . "\n");
        }
        fclose($file);

        $this->schedule_chunk([
            'chunk_file_path' => $filename,
            'chunk_count'     => $chunkCount,
        ]);
    }

}
