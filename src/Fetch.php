<?php

namespace juvo\AS_Processor;

use Exception;

abstract class Fetch extends Import
{

    /**
     * The current index. Store either your offset or page number for the API call.
     *
     * @var int $current_index
     */
    protected int $current_index = 0;

    /**
     * Sets the current counter. Store either your offset or page number that have already been processed
     *
     * @var int counter
     */
    protected int $counter;

    /**
     * Sets the amount of requests
     *
     * @var int $requests_per_schedule
     */
    protected int $requests_per_schedule = 5;

    /**
     * Sets the time between requests default = 1 sec
     *
     * @var int $time_between_requests
     */
    protected int $time_between_requests = 60000;

    /**
     * The size of the chunks
     *
     * @var int $chunk_size
     */
    public int $chunk_size;

    const SERIALIZED_DELIMITER = "\n--END--\n";

    public function set_hooks(): void
    {
        parent::set_hooks();

        // register the hooks for the scheduled actions
        // first of the regular scheduler main action
        add_action( $this->get_sync_name() . '/schedule_fetches', [ $this, 'schedule_fetches' ] );

        // register the hook for each job, called chunk here
        add_action( $this->get_sync_name() . '/process_request', [ $this, 'process_request' ] );
    }


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
}
