<?php

namespace Samicrusader\Flysystem;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\AdapterInterface;
use Samicrusader\Flysystem\BackblazeClient as Client;
use obregonco\B2\Bucket;
use obregonco\B2\File as B2File;
use League\Flysystem\Config;
use League\Flysystem\Util;

class BackblazeAdapter extends AbstractAdapter implements AdapterInterface, CanOverwriteFiles
{
    use NotSupportingVisibilityTrait;
    protected Client $client;
    protected Bucket $bucket;
    protected bool $streamReads;

    /**
     * Initializes the adapter.
     *
     * @param BackblazeClient $client
     * @param string $bucket
     * @param string $pathPrefix
     * @param bool $streamReads
     */
    public function __construct(Client $client, string $bucket, string $pathPrefix = '', bool $streamReads = true)
    {
        $this->client = $client;
        $this->bucket = $this->client->getBucketFromName($bucket);
        $this->setPathPrefix($pathPrefix);
        $this->streamReads = $streamReads;
    }

    /* internal funcs */
    /**
     * Prefix a path.
     *
     * @param string $path
     *
     * @return string prefixed path
     */
    public function applyPathPrefix($path): string
    {
        // allows for developer laziness when it comes to the prefix; let's just ltrim twice!
        return ltrim($this->getPathPrefix() . ltrim($path, '\\/'), '/');
    }

    /**
     * Remove a path prefix.
     *
     * @param string $path
     *
     * @return string path without the prefix
     */
    public function removePathPrefix($path): string
    {
        if ($this->getPathPrefix() !== null) // quiet warning about passing null to strlen()
            $path = substr($path, strlen($this->getPathPrefix()) - 1); // needs `- 1` because fucking thing clips the first part of the path...
        $path = ltrim($path, '/');
        return $path;
    }

    /**
     * Parses obregonco\B2\File object.
     *
     * @param B2File $file
     * @return array
     */
    private function parseB2File(B2File $file): array {
        $fp = [];
        $fp['type'] = 'file';
        $fp['path'] = $this->removePathPrefix($file->getFileName());
        $fp['timestamp'] = intval($file->getUploadTimestamp() / 1000);
        if (isset($file->size))
            $fp['size'] = $file->size;
        $fp['dirname'] = Util::normalizeDirname(dirname($fp['path'])); // needed for emulateDirectories
        $fp['mimetype'] = $file->getContentType();
        return $fp;
    }

    /**
     * Filters listings for non-hidden files.
     *
     * @param array $files
     * @param bool $parse
     * @return array
     */
    private function filterB2Listings(array $files, bool $parse = true): array {
        $return = array();
        foreach ($files as $file) {
            if ($file->getContentType() == 'application/x-bz-hide-marker')
                continue;
            if ($parse)
                $return[] = $this->parseB2File($file);
            else
                $return[] = $file;
        }
        return $return;
    }

    /**
     * Searches for a specific filename in a bucket.
     *
     * @param string $path
     * @param bool $parse
     * @return array|bool
     */
    private function searchB2File(string $path, bool $parse = true): array|bool
    {
        $listing = $this->client->listFilesFromArray([
            'BucketId' => $this->bucket->getId(),
            'FileName' => $this->applyPathPrefix($path)
        ]);
        $file = $this->filterB2Listings($listing, $parse);
        if (empty($file))
            return false;
        return $file[0];
    }

    private function setupB2Upload(string $path, Config $config, array &$writeOptions): void {
        $writeOptions['BucketId'] = $this->bucket->getId();
        $writeOptions['FileName'] = $this->applyPathPrefix($path);
        $writeOptions['FileLastModified'] = $config->get('timestamp');
        $writeOptions['FileContentType'] = $config->get('mimetype', 'b2/x-auto');
    }

    /* metadata read funcs */
    /**
     * List contents of a directory.
     *
     * `$recursive` does nothing.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false): array
    {
        $prefix = $this->applyPathPrefix($directory);

        $listing = $this->client->listFilesFromArray([ // listFilesFromArray() will recurse automatically
            'BucketId' => $this->bucket->getId(),
            'Prefix' => $prefix
        ]);

        $return = $this->filterB2Listings($listing);

        /*
         * Backblaze B2's API actually WILL give you directory listings assuming you set `delimiter`, however!
         * - The library I'm using doesn't support it (and I can't be assed to write my own)
         * - AND the only thing that will make it list dirs doesn't fucking recurse!
         * This is ass backwards fucking retarded. This code should be cleaner.
         */
        return Util::emulateDirectories($return); // fuck me
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool
     */
    public function has($path): array|bool
    {
        return $this->searchB2File($path);
    }

    /**
     * Get all the metadata of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path): array|false
    {
        return $this->searchB2File($path);
    }

    /**
     * Get the size of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path): array|false
    {
        $file = $this->searchB2File($path);
        if (!$file)
            return false;
        return ['size' => $file['size']];
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path): array|false
    {
        $file = $this->searchB2File($path);
        if (!$file)
            return false;
        return ['mimetype' => $file['mimetype']];
    }

    /**
     * Get the last modified time of a file as a timestamp.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path): array|false
    {
        $file = $this->searchB2File($path);
        if (!$file)
            return false;
        return ['timestamp' => $file['timestamp']];
    }

    /* metadata modify funcs */
    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath): bool
    {
        $file = $this->searchB2File($path, false);
        if (!$file)
            return false;
        $this->client->copyFile([
            'BucketName' => $this->bucket,
            'SourceFileId' => $file[0]->getFileId(),
            'DestinationFileName' => $this->applyPathPrefix($newpath)
        ]);
        $this->client->deleteFile($file[0]);
        return true;
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath): bool
    {
        $file = $this->searchB2File($path, false);
        if (!$file)
            return false;
        $this->client->copyFile([
            'BucketName' => $this->bucket,
            'SourceFileId' => $file[0]->getFileId(),
            'DestinationFileName' => $this->applyPathPrefix($newpath)
        ]);
        return true;
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path): bool
    {
        $file = $this->searchB2File($path, false);
        if (!$file)
            return false;
        $this->client->deleteFile($file[0]);
        return true;
    }

    /**
     * Delete a directory.
     *
     * This works the same as a recursive erase. Files that are marked hidden are skipped over.
     * Backblaze will hide the files until they expire.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname): bool
    {
        $prefix = $this->applyPathPrefix($dirname);

        $listing = $this->client->listFilesFromArray([ // listFilesFromArray() will recurse automatically
            'BucketId' => $this->bucket->getId(),
            'Prefix' => $prefix
        ]);
        if (empty($listing)) {
            return false;
        }

        $has_deleted = false;

        foreach ($listing as $file) {
            if ($file->getContentType() == 'application/x-bz-hide-marker')
                continue; // these get auto-removed
            $this->client->deleteFile($file);
            $has_deleted = true;
        }
        return $has_deleted;
    }

    /**
     * Create a directory.
     *
     * Stub function
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array
     */
    public function createDir($dirname, Config $config): array
    {
        $fp = array();
        $fp['path'] = $this->removePathPrefix($dirname);
        $fp['type'] = 'dir';
        return $fp;
    }

    /* data write funcs */
    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config): array|false
    {
        $writeOptions = array();
        $writeOptions['Body'] = $contents;
        $this->setupB2Upload($path, $config, $writeOptions);
        $writeOptions['size'] = mb_strlen($contents, '8bit');
        $writeOptions['hash'] = sha1($contents);
        $file = $this->client->uploadStandardFile($writeOptions);
        return $this->parseB2File($file);
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config): array|false
    {
        // Make sure that we actually have a size. This fails otherwise.
        $stat = fstat($resource);
        if (empty($stat['size']))
            return false;

        // Prepare for uploading the parts of a large file.
        $payload = [
            'bucketId' => $this->bucket->getId(),
            'fileName' => $this->applyPathPrefix($path),
            'contentType' => $config->get('mimetype', 'b2/x-auto')
        ];
        if ($config->has('timestamp'))
            $payload['fileInfo'] = ['src_last_modified_millis' => $config->get('timestamp')];
        $response = $this->client->request('POST', '/b2_start_large_file', ['json' => $payload]);
        $fileId = $response['fileId'];

        $hashList = [];
        $partSize = 10 * 1000 * 1000;
        if ($stat['size'] < $partSize) // Large files must have atleast 2 parts and part #1 must be atleast 5 MB
            $partSize = 5 * 1000 * 1000;
        $partsCount = ceil($stat['size'] / $partSize);

        for ($i = 1; $i <= $partsCount; $i++) {
            $chunk = fread($resource, $partSize);
            $hash = sha1($chunk);
            $hashList[] = $hash;

            // Retrieve the URL that we should be uploading to.
            $response = $this->client->request('POST', '/b2_get_upload_part_url', [
                'json' => [
                    'fileId' => $fileId,
                ],
            ]);

            // Upload chunk
            $response = $this->client->request('POST', $response['uploadUrl'], [
                'headers' => [
                    'Authorization' => $response['authorizationToken'],
                    'X-Bz-Part-Number' => $i,
                    'Content-Length' => mb_strlen($chunk, '8bit'),
                    'X-Bz-Content-Sha1' => $hash,
                ],
                'body' => $chunk
            ]);
        }

        // Finish upload of large file
        $response = $this->client->request('POST', '/b2_finish_large_file', [
            'json' => [
                'fileId' => $fileId,
                'partSha1Array' => $hashList,
            ],
        ]);

        return $this->parseB2File(new B2File($response));
    }

    /**
     * Update a file.
     *
     * This internally just calls $this->write(). B2 will hide and then auto-delete the old file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config): array|false
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * This internally just calls $this->write(). B2 will hide and then auto-delete the old file.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config): array|false
    {
        return $this->writeStream($path, $resource, $config);
    }

    /* data read funcs */
    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path): array|false
    {
        try {
            $file = $this->client->download([
                'BucketName' => $this->bucket->getName(),
                'FileName' => $this->applyPathPrefix($path),
                'stream' => false
            ]);
        } catch (\GuzzleHttp\Exception\ClientException) {
            return false;
        }
        return ['contents' => $file];
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path): array|false
    {
        try {
            $file = $this->client->download([
                'BucketName' => $this->bucket->getName(),
                'FileName' => $this->applyPathPrefix($path),
                'stream' => true
            ]);
        } catch (\GuzzleHttp\Exception\ClientException) {
            return false;
        }
        return ['stream' => $file];
    }
}