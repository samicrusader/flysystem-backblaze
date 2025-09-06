<?php

namespace Samicrusader\Flysystem;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\AdapterInterface;
use obregonco\B2\Client;
use obregonco\B2\File as B2File;
use League\Flysystem\Config;
use League\Flysystem\Util;

class BackblazeAdapter extends AbstractAdapter implements AdapterInterface, CanOverwriteFiles
{
    use NotSupportingVisibilityTrait;
    protected $client;
    protected $bucket;
    protected $streamReads;

    /**
     * Initializes the adapter.
     *
     * @param Client $client
     * @param $bucket
     * @param $pathPrefix
     * @param $streamReads
     */
    public function __construct(Client $client, $bucket, $pathPrefix = '', $streamReads = true)
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
        $return = array();
        $prefix = $this->applyPathPrefix($directory);

        $listing = $this->client->listFilesFromArray([ // listFilesFromArray() will recurse automatically
            'BucketId' => $this->bucket->getId(),
            'Prefix' => $prefix
        ]);

        foreach ($listing as $file) {
            if ($file->getContentType() == 'application/x-bz-hide-marker')
                // hide hidden files
                continue;
            $return[] = $this->parseB2File($file);
        }

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
        $file = $this->client->listFilesFromArray([
            'BucketId' => $this->bucket->getId(),
            'FileName' => $this->applyPathPrefix($path)
        ]);
        return !empty($file);
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
        $file = $this->client->listFilesFromArray([
            'BucketId' => $this->bucket->getId(),
            'FileName' => $this->applyPathPrefix($path)
        ]);
        if (empty($file))
            return false;
        return $this->parseB2File($file[0]);
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
        $file = $this->client->listFilesFromArray([
            'BucketId' => $this->bucket->getId(),
            'FileName' => $this->applyPathPrefix($path)
        ]);
        if (empty($file))
            return false;
        return ['size' => $file[0]->size];
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
        $file = $this->client->listFilesFromArray([
            'BucketId' => $this->bucket->getId(),
            'FileName' => $this->applyPathPrefix($path)
        ]);
        if (empty($file))
            return false;
        return ['mimetype' => $file[0]->getContentType()];
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
        $file = $this->client->listFilesFromArray([
            'BucketId' => $this->bucket->getId(),
            'FileName' => $this->applyPathPrefix($path)
        ]);
        if (empty($file))
            return false;
        return ['timestamp' => intval($file[0]->getUploadTimestamp() / 1000)];
    }

    /* metadata modify funcs */
    /**
     * @param $path
     * @param $newpath
     * @return bool
     */
    public function rename($path, $newpath)
    {
        // TODO: Implement rename() method.
    }

    /**
     * @param $path
     * @param $newpath
     * @return bool
     */
    public function copy($path, $newpath)
    {
        // TODO: Implement copy() method.
    }

    /**
     * @param $path
     * @return bool
     */
    public function delete($path)
    {
        // TODO: Implement delete() method.
    }

    /**
     * @param $dirname
     * @return bool
     */
    public function deleteDir($dirname)
    {
        // TODO: Implement deleteDir() method.
    }

    /**
     * @param $dirname
     * @param Config $config
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        // TODO: Implement createDir() method.
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