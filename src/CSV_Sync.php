<?php

namespace juvo\AS_Processor;

use Exception;
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
     * Returns number ob chunks
     *
     * @return void
     * @throws League\Csv\Exception
     * @throws InvalidArgument
     * @throws UnavailableStream
     */
    public function split_csv_into_chunks(): void
    {

        $csvFilePath = $this->get_source_csv_path();
        if (!file_exists($csvFilePath)) {
            throw new Exception("Failed to open the file: $csvFilePath");
        }

        $chunkCount = 0;
        $chunkRowCount = 0;
        $chunkData = [];

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

        // Split in chunks
        foreach ($reader->getRecords() as $record) {

            if ($chunkCount >= 2) {
                continue;
            }

            $chunkData[] = $record;
            $chunkRowCount++;

            if ($chunkRowCount === $this->chunkSize) {
                $this->schedule_csv_chunk($chunkData, $chunkCount);

                $chunkCount++;
                $chunkRowCount = 0;
                $chunkData = [];
            }
        }

        // Handle the last chunk if it contains data
        if (!empty($chunkData)) {
            $this->schedule_csv_chunk($chunkData, $chunkCount);
        }

        // Remove chunk file after sync
        unlink($csvFilePath);
    }

    /**
     * Callback function for the single chunk jobs.
     *
     * @param array $data
     * @return void
     * @throws Exception
     */
    public function process_chunk(array $data): void
    {
        $chunkFilePath = $data['chunk_file_path'];
        $chunkData = unserialize(file_get_contents($chunkFilePath));

        if (empty($chunkData)) {
            throw new Exception("Failed to read the chunk data from: $chunkFilePath");
        }

        $formattedDataGenerator = (function() use ($chunkData) {
            foreach ($chunkData as $row) {
                yield $row;
            }
        })();

        $this->process_chunk_data($formattedDataGenerator);

        // Remove chunk file after sync
        unlink($chunkFilePath);
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
     * @param array $chunkData
     * @param int $chunkCount
     * @return void
     */
    private function schedule_csv_chunk(array $chunkData, int $chunkCount): void
    {
        file_put_contents($this->get_csv_chunk_path($chunkCount), serialize($chunkData));
        $this->schedule_chunk([
            'chunk_file_path' => $this->get_csv_chunk_path($chunkCount),
            'chunk_count' => $chunkCount,
        ]);
    }

}