<?php

namespace juvo\AS_Processor;

use Exception;
use phpseclib3\Net\SFTP as SFTP_Sec;

class SFTP extends Remotes
{

    private string $host = "";
    private string $username = "";
    private string $password = "";
    private int $port = 22;
    private string $base_path = "";

    /**
     * Returns an SFTP client
     *
     * @return SFTP_Sec
     * @throws Exception
     */
    protected function init_client(): SFTP_Sec
    {
        // Define your SFTP credentials and the remote file path
        $sftpHost = apply_filters('as_processor/sftp/host', $this->host);
        $sftpUsername = apply_filters('as_processor/sftp/user', $this->username);
        $sftpPassword = apply_filters('as_processor/sftp/password', $this->password);
        $sftpPort = apply_filters('as_processor/sftp/port', $this->port);

        // Initialize SFTP
        $sftp = new SFTP_Sec($sftpHost, $sftpPort);
        if (!$sftp->login($sftpUsername, $sftpPassword)) {
            throw new Exception('SFTP login failed');
        }

        return $sftp;
    }

    /**
     * Downloads a file from the remote server
     *
     * @param string $remote_location relative to the base path
     * @param string $local_path if none passed file will be saved to tmp folder
     * @return string
     * @throws Exception
     */
    public function download_file(string $remote_location, string $local_path): string
    {

        // If local path is not provided, save to temp directory
        if (empty($local_path)) {
            $local_path = $this->get_temp_path($remote_location);
        }

        // Check if file exists on SFTP
        if (!$this->get_client()->file_exists($remote_location)) {
            throw new Exception('File not found on SFTP server.');
        }

        $downloaded = $this->get_client()->get($remote_location, $local_path);
        if (!$downloaded) {
            throw new Exception("Failed to download file: $remote_location");
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

        $remoteFilePath = apply_filters('as_processor/sftp/base_path', $this->base_path);

        // Get all files the TI folder and index them for faster access
        return trailingslashit($remoteFilePath) . ltrim($path, '/');
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function getBasePath(): string
    {
        return $this->base_path;
    }

    public function setBasePath(string $base_path): void
    {
        $this->base_path = $base_path;
    }

}
