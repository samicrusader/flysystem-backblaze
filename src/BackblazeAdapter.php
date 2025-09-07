<?php

namespace Samicrusader\Flysystem;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\AdapterInterface;
use obregonco\B2\Client;
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
     * @param Client $client
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
    public function parseB2File(B2File $file): array {
        $fp = [];
        $fp['type'] = 'file';
        $fp['path'] = $this->removePathPrefix($file->getFileName());
        $fp['timestamp'] = intval($file->getUploadTimestamp() / 1000);
        $fp['size'] = $file->size; // WTF?
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
    public function filterB2Listings(array $files, bool $parse = true): array {
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
    public function searchB2File(string $path, bool $parse = true): array|bool
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
     * @param $path
     * @param $contents
     * @param Config $config
     * @return array|false
     */
    public function write($path, $contents, Config $config)
    {
        // TODO: Implement write() method.
    }

    /**
     * @param $path
     * @param $resource
     * @param Config $config
     * @return array|false
     */
    public function writeStream($path, $resource, Config $config)
    {
        // TODO: Implement writeStream() method.
    }

    /**
     * @param $path
     * @param $contents
     * @param Config $config
     * @return array|false
     */
    public function update($path, $contents, Config $config)
    {
        // TODO: Implement update() method.
    }

    /**
     * @param $path
     * @param $resource
     * @param Config $config
     * @return array|false
     */
    public function updateStream($path, $resource, Config $config)
    {
        // TODO: Implement updateStream() method.
    }

    /* data read funcs */
    /**
     * @param $path
     * @return mixed
     */
    public function read($path)
    {
        // TODO: Implement read() method.
    }

    /**
     * @param $path
     * @return mixed
     */
    public function readStream($path)
    {
        // TODO: Implement readStream() method.
    }
}