<?php
/**
 * @license GPL-3.0-or-later
 *
 * Modified by Justin Vogt on 03-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Sinnewerk\Dependencies\juvo\AS_Processor;

use Exception;
use Sinnewerk\Dependencies\League\Csv\Reader;

abstract class CSV_Sync extends Sync
{

    protected int $chunkSize = 5000;
    protected string $delimiter = ',';
    protected bool $hasHeader = true;

    public function __construct()
    {
        parent::__construct();
        add_action($this->get_sync_name(), [$this, 'split_csv_into_chunks']);
    }

    abstract protected function get_source_csv_path(): string;

    /**
     * Returns number ob chunks
     *
     * @return void
     * @throws Exception
     */
    public function split_csv_into_chunks(): void
    {
        $csvFilePath = $this->get_source_csv_path();
        $handle = fopen($csvFilePath, 'rb');
        if (!$handle) {
            throw new Exception("Failed to open the file: $csvFilePath");
        }

        $chunkCount = 0;
        $chunkRowCount = 0;
        $chunkData = [];

        // Read csv from file
        $reader = Reader::createFromPath($this->get_source_csv_path(), 'r');
        $reader->setDelimiter($this->delimiter);

        // Maybe add header
        if ($this->hasHeader) {
            $reader->setHeaderOffset(0);
        }

        // Split in chunks
        foreach ($reader->getRecords() as $record) {
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
