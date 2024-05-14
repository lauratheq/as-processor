<?php

namespace juvo\AS_Processor;

use Exception;

abstract class Remotes
{

    protected mixed $client;

    /**
     * Returns client for the remote location
     */
    protected function get_client()
    {
        if (empty($this->client)) {
            $this->client = $this->init_client();
        }
        return $this->client;
    }

    /**
     * Initializes the client to interact with the remote
     *
     * @return mixed
     */
    protected abstract function init_client(): mixed;

    /**
     * Downloads a file from the remote server
     *
     * @param string $remote_location relative to the base path
     * @param string $local_path local location the file will be stored to
     * @return string
     * @throws Exception
     */
    public abstract function download_file(string $remote_location, string $local_path): string;

    /**
     * @param string $sourcePath can be any path that basename() can parse
     * @return string
     */
    protected function get_temp_path(string $sourcePath): string
    {
        // If local path is not provided, save to temp directory
        $tmp = get_temp_dir();
        return $tmp . ltrim(basename($sourcePath), '/');
    }

}