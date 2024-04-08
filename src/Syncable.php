<?php

namespace juvo\AS_Processor;

interface Syncable
{

    /**
     * Returns the name of the sync
     *
     * @return string
     */
    public function get_sync_name(): string;

}