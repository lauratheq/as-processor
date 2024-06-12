<?php

namespace juvo\AS_Processor;

use Exception;
use Iterator;
use League\Csv\InvalidArgument;
use League\Csv\Reader;
use League\Csv\UnavailableStream;

abstract class CSV_Sync extends Sync
{

    const SERIALIZED_DELIMITER = "\n--END--\n";
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
     * @throws \League\Csv\Exception
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
        $chunkFilePath = $data['chunk_file_path'];
        if (!file_exists($chunkFilePath)) {
            throw new Exception("File '$chunkFilePath' does not exist");
        }

        $file = fopen($chunkFilePath, 'r');
        $buffer = '';

        /**
         * Always read some larger portion and check if the end delimiter is present.
         * If not, we continue reading.
         */
        $formattedDataGenerator = (function() use ($file, &$buffer) {
            $chunkSize = 8192; // Read 8 KB at a time
            while (!feof($file)) {

                // Read a chunk of data from the file and append it to the buffer
                $buffer .= fread($file, $chunkSize);

                // Check if the delimiter is present in the buffer
                while (($pos = strpos($buffer, self::SERIALIZED_DELIMITER)) !== false) {

                    // Extract the complete serialized record up to the delimiter
                    $record = substr($buffer, 0, $pos);

                    // Remove the processed record from the buffer
                    $buffer = substr($buffer, $pos + strlen(self::SERIALIZED_DELIMITER));

                    // Deserialize and yield the record if it's not empty
                    if (!empty(trim($record))) {
                        yield unserialize(trim($record));
                    }
                }
            }

            // Process any remaining data in the buffer after reading the entire file
            if (!empty(trim($buffer))) {
                yield unserialize(trim($buffer));
            }
        })();

        $this->process_chunk_data($formattedDataGenerator);

        fclose($file);

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
            fwrite($file, serialize($record) . self::SERIALIZED_DELIMITER);
        }
        fclose($file);

        $this->schedule_chunk([
            'chunk_file_path' => $filename,
            'chunk_count'     => $chunkCount,
        ]);
    }

}
