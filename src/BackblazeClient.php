<?php

namespace Samicrusader\Flysystem;

use obregonco\B2\Client as B2Client;
use obregonco\B2\File;

class BackblazeClient extends B2Client {

    /**
     * hooked so function can be accessed
     */
    public function uploadStandardFile(array $options = []): File
    {
        return parent::uploadStandardFile($options);
    }

    /**
     * hooked so function can be accessed
     */
    public function uploadLargeFile(array $options): File
    {
        return parent::uploadLargeFile($options);
    }

    /**
     * hooked so function can be accessed
     */
    public function request(string $method, string $uri = '', array $options = [], bool $asJson = true)
    {
        return parent::request($method, $uri, $options, $asJson);
    }
}