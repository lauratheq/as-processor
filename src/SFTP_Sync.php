<?php

namespace juvo\AS_Processor;

use Exception;
use phpseclib3\Net\SFTP;

trait SFTP_Sync
{

    private ?SFTP $sftp;

    /**
     * @throws Exception
     */
    public function get_sftp_client(): SFTP
    {
        if (empty($this->sftp)) {
            $this->sftp = $this->init_sftp_client();
        }
        return $this->sftp;
    }

    /**
     * Returns an SFTP client
     *
     * @return SFTP
     * @throws Exception
     */
    private function init_sftp_client(): SFTP
    {
        // Define your SFTP credentials and the remote file path
        $sftpHost = get_field('dmz_host', 'option');
        $sftpUsername = get_field('dmz_user', 'option');
        $sftpPassword = get_field('dmz_password', 'option');
        $sftpPort = get_field('dmz_port', 'option') ?: 22;

        // Initialize SFTP
        $sftp = new SFTP($sftpHost, $sftpPort);
        if (!$sftp->login($sftpUsername, $sftpPassword)) {
            throw new Exception('SFTP login failed');
        }

        return $sftp;
    }

    /**
     * Downloads a file from the remote server
     *
     * @param string $remote_path relative to the base path
     * @param string $local_path if none passed file will be saved to tmp folder
     * @return string
     * @throws Exception
     */
    public function download_file(string $remote_path, string $local_path = ""): string
    {

        // If local path is not provided, save to temp directory
        if (empty($local_path)) {
            $tmp = get_temp_dir();
            $local_path = $tmp . ltrim(basename($remote_path), '/');
        }

        // Check if file exists on SFTP
        if (!$this->get_sftp_client()->file_exists($remote_path)) {
            throw new Exception('File not found on SFTP server.');
        }

        $downloaded = $this->get_sftp_client()->get($remote_path, $local_path);
        if (!$downloaded) {
            throw new Exception("Failed to download file: $remote_path");
        }

        return $local_path;
    }

    /**
     * Appends a path to the base path for a remote location
     *
     * @param string $path
     * @return string
     */
    public function get_path(string $path): string {

        $remoteFilePath = get_field('dmz_base_path', 'option');

        // Get all files the TI folder and index them for faster access
        return trailingslashit($remoteFilePath) . ltrim($path, '/');
    }

}