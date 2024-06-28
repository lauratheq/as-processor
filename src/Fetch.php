<?php

namespace juvo\AS_Processor;

use Exception;
use Iterator;
use League\Csv\Reader;

abstract class Fetch extends Sync
{
    const SERIALIZED_DELIMITER = "\n--END--\n";

    /**
     * Returns the name of the sync. The name must always be deterministic.
     *
     * @return string
     */
    abstract function get_sync_name(): string;

    /**
     * Gets the filename
     *
     * @return string
     */
    abstract function get_filename(): string;

    /**
     * Gets the path where the files will have a cozy little home
     *
     * @return  string
     */
    public function get_path(): string
    {
        return WP_CONTENT_DIR . '/kununu-exports/';
    }

    /**
     * Gets the full path to the file
     *
     * @return  string
     */
    public function get_filepath(): string
    {
        $path = $this->get_path();
        $filepath = $path . $this->get_filename();
        return $filepath; 
    }

    /**
     * Gets the full path to the chunked file
     *
     * @param   int $chunk_count the current chunk count
     *
     * @return  string
     */
    public function get_filepath_chunked( int $chunk_count ): string
    {
        $filename = str_replace('/', '-', "{$this->get_sync_name()}_$chunk_count.txt");
        $tmp = get_temp_dir();
        return strtolower($tmp . $filename);
    }

    /**
     * Gets the chunk size
     *
     * @return int
     */
    abstract function get_chunk_size(): int;

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
        $formattedDataGenerator = (function() use ($file) {
            while ( ( $line = stream_get_line($file, 0, "\n")) !== false ) {
                $line = trim($line);
                yield $line;
            }
        })();

        $this->process_chunk_data($formattedDataGenerator);

        fclose($file);

        // Remove chunk file after sync
        unlink($chunkFilePath);
    }

    /**
     * Handles the actual data processing. Should be implemented in the class lowest in hierarchy
     *
     * @param \Generator $chunkData
     * @return void
     */
    abstract function process_chunk_data(\Generator $chunkData): void;

    /**
     * Splits the file into chunks and schedules their execution
     *
     * @return  void
     */
    public function schedule_chunks(): void
    {
        // check if filename is set
        if ( empty( $this->get_filename() ) ) {
            throw new Exception('The property filename is not set');
        }

        // prep the file
        $path = $this->get_path();
        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
        }

        $filename = $this->get_filepath();
        if (!file_exists($filename)) {
            throw new Exception("Failed to open the file: $filename");
        }

        // walk the file and split everything into chunks
        $handle = fopen($filename,'r');
        $counter = 1; //new file number
        while ( ! feof( $handle ) ) {
            $chunked_filename = $this->get_filepath_chunked( $counter );
            $chunk_file = fopen($chunked_filename, 'w');

            for ( $i = 1; $i <= $this->get_chunk_size(); $i++ ) {
                $import = fgets( $handle );

                // don't write if we have nothing to import
                if ( empty( $import ) ) {
                    break;
                }
                fwrite( $chunk_file, $import );

                // break if file ends
                if ( feof( $handle ) ) {
                    break;
                }
            }
            fclose( $chunk_file );

            // schedule the chunk
            $this->schedule_chunk([
                'chunk_file_path' => $chunked_filename,
                'chunk_count'     => $counter,
            ]);

            $counter++;
        }
        fclose($handle);

        // Remove file after sync
        //unlink($filename);
    }

    /**
     * Saves the data to a file
     *
     * @param   array $items the items to store
     *
     * @return  void
     */
    public function save_to_file( array $items ): void
    {
        // check if filename is set
        if ( empty( $this->get_filename() ) ) {
            throw new Exception('The property filename is not set');
        }

        // prep the file
        $path = WP_CONTENT_DIR . '/kununu-exports/';
        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
        }

        $filename = $path . $this->get_filename();
        $fp = fopen($filename, "w");
        foreach ( $items as $item ) {
            fwrite($fp, $item);
            fwrite($fp, "\n");
        }
        fclose($fp);
    }
}
