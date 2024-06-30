<?php

namespace juvo\AS_Processor;

use Exception;
use Iterator;

trait Chunker
{

    /**
     * Generates a deterministic chunk file path
     *
     * @param int $count
     * @return string
     */
    private function get_chunk_path(int $count): string
    {
        $filename = str_replace('/', '-', "{$this->get_sync_name()}_$count.txt");
        $tmp = get_temp_dir();
        $path = strtolower($tmp . $filename);

        return apply_filters('as_processor/chunk/path', $path, $this);
    }

    /**
     * Schedules an async action to process a chunk of data. Passed items are serialized and added to a chunk.
     *
     * @param Iterator $chunkData
     * @param int $chunkCount
     * @return void
     */
    protected function schedule_chunk(Iterator $chunkData, int $chunkCount): void
    {
        $filename = $this->get_chunk_path($chunkCount);

        // Write data to Chunk file
        $file = fopen($filename, 'w');
        foreach ($chunkData as $record) {
            fwrite($file, serialize($record) . self::SERIALIZED_DELIMITER);
        }
        fclose($file);

        as_enqueue_async_action(
            $this->get_sync_name() . '/process_chunk',
            [
                'chunk_file_path' => $filename,
                'chunk_count'     => $chunkCount,
            ], // Wrap in array to pass as single argument. Needed because of abstract child method enforcement
            $this->get_sync_group_name()
        );
    }

    /**
     * Callback function for the single chunk jobs.
     * This jobs reads the chunk file line by line and yields each line to the actual process
     *
     * @param array $data The array passed by the "schedule_chunk" method
     * @return void
     * @throws Exception
     */
    protected function import_chunk(array $data): void
    {
        $chunkFilePath = $data['chunk_file_path'] ?? "";
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

}
