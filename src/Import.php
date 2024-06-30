<?php

namespace juvo\AS_Processor;

use Exception;

abstract class Import extends Sync
{
    use Chunker;

    /**
     * @throws Exception
     */
    public function process_chunk(array $data): void
    {
        $this->import_chunk($data);
    }

    /**
     * Handles the actual data processing. Should be implemented in the class lowest in hierarchy
     *
     * @param \Generator $chunkData
     * @return void
     */
    abstract function process_chunk_data(\Generator $chunkData): void;

}