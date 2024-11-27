<?php

namespace juvo\AS_Processor\Imports;

use WP_Error;
use juvo\AS_Processor\Import;

abstract class JSON extends Import
{
    /**
     * The size of the chunks
     *
     * @var int $chunk_size
     */
    public int $chunk_size = 10;

    /**
     * Processes the fetching of the whole data.
     *
     * @return array|WP_Error the data
     */
    abstract protected function process_fetch(): array|WP_Error;

    /**
     * Uses the data and splits that into chunks.
     *
     * @return void
     */
    public function split_data_into_chunks(): void
    {
        $data = $this->process_fetch();
        if ($data instanceof WP_Error || empty($data)) {
            return;
        }

        $chunks = array_chunk($data, $this->chunk_size);
        foreach ( $chunks as $chunk ) {
            $this->schedule_chunk( $chunk );
        }
    }
}
