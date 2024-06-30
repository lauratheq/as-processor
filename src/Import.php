<?php

namespace juvo\AS_Processor;

use Exception;

abstract class Import extends Sync
{
    use Chunker;

    /**
     * @param string $chunk_file_path
     * @throws Exception
     */
    public function process_chunk(string $chunk_file_path): void
    {
        $this->import_chunk($chunk_file_path);
    }

    /**
     * Handles the actual data processing. Should be implemented in the class lowest in hierarchy
     *
     * @param \Generator $chunkData
     * @return void
     */
    abstract function process_chunk_data(\Generator $chunkData): void;

}