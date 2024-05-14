<?php

namespace juvo\AS_Processor;

use cardinalby\ContentDisposition\ContentDisposition;
use Sabre\DAV\Client;

class WebDav extends Remotes
{

    protected mixed $client;

    private string $base_uri = "";
    private string $host = "";
    private string $username = "";
    private string $password = "";

    protected function init_client(): Client
    {
        // Define your SFTP credentials and the remote file path
        $base_uri = apply_filters('as_processor/dav/base_uri', $this->base_uri);
        $username = apply_filters('as_processor/dav/user', $this->username);
        $password = apply_filters('as_processor/dav/password', $this->password);

        if (empty($base_uri)) {
            throw new \Exception('Base URI cannot be empty.');
        }

        return new Client([
            'baseUri'  => $base_uri,
            'userName' => $username,
            'password' => $password,
        ]);
    }

    /**
     * Scans whole folder and gets youngest file
     *
     * @param string $remote_folder
     * @return string|null
     */
    public function get_newest_file_in_folder(string $remote_folder = ""): ?string
    {

        $remote_folder = ltrim(implode('/', array_map('rawurlencode', explode('/', $remote_folder))), '/');

        // Fetch the list of files in the folder
        $response = $this->get_client()->propFind($this->base_uri . $remote_folder, [
            '{DAV:}getlastmodified',
            '{DAV:}displayname',
            '{DAV:}resourcetype',
            '{DAV:}getcontenttype',
        ], 1);

        // Initialize variables to find the newest file
        $newestFile = false;
        $newestTime = 0;

        foreach ($response as $file => $props) {

            // Skip Everything but files
            if (!isset($props['{DAV:}getcontenttype'])) {
                continue;
            }

            if (isset($props['{DAV:}getlastmodified'])) {
                $lastModified = strtotime($props['{DAV:}getlastmodified']);

                if ($lastModified > $newestTime) {
                    $newestTime = $lastModified;
                    $newestFile = $file;
                }
            }
        }

        if ($newestFile) {
            return $newestFile;
        }

        return $newestFile;
    }

    /**
     * Downloads a remote file to the filesystem
     *
     * @param string $remote_location relative to the base_uri
     * @param string $local_path
     * @return string
     * @throws \Exception
     */
    public function download_file(string $remote_location, string $local_path = ""): string {

        $remote_location = $this->host . $remote_location;

        // Download file
        $request = $this->get_client()->request('GET', $remote_location);

        // Exit if body is empty
        $fileContents = $request['body'];
        if (empty($fileContents)) {
            throw new \Exception('Failed to download file');
        }

        // Process Content disposition information
        $headers = $request['headers'];
        if (empty($headers) || empty($headers['content-disposition']) || empty($headers['content-disposition'][0])) {
            throw new \Exception('Failed to retrieve file information');
        }

        $cd = ContentDisposition::parse($headers['content-disposition'][0]);
        if ($cd->getType() !== "attachment") {
            throw new \Exception('File is not an attachment');
        }

        // Get local path to save file
        if (empty($local_path)) {
            $local_path = $this->get_temp_path($cd->getFilename());
        }

        file_put_contents($local_path, $fileContents);
        return $local_path;
    }

    public function set_base_uri(string $base_uri): void
    {
        $this->base_uri = trailingslashit($base_uri);

        // Extract the host url from base_uri
        $parsedUrl = parse_url($this->base_uri);
        if (!isset($parsedUrl['scheme']) || !isset($parsedUrl['host'])) {
            return;
        }
        $this->host = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    }

    public function set_username(string $username): void
    {
        $this->username = $username;
    }

    public function set_password(string $password): void
    {
        $this->password = $password;
    }

}