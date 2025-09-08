# flysystem-backblaze

<!-- [![Latest Version on Packagist][ico-version]][link-packagist] -->
[![Software License][ico-license]](LICENSE)

Connects Backblaze B2 object storage to Flysystem v1.0; forked from gliterd/mhetreramesh for use with XenForo because Backblaze's S3 implementation is AIDS.

This fork/rewrite uses [obregonco/backblaze-b2](https://github.com/obregonco/backblaze-b2) since it supports B2 API version 2 and also was updated in the last year.

``` bash
$ composer require samicrusader/flysystem-backblaze
```

XF install instructions coming soon

## Usage

You will need:
- `$accountId` -> Master Application Key `keyID`
- `$keyId` -> `keyID` (if you are using the master application key **which you shouldn't be**, you can omit this)
- `$applicationKey` => `applicationKey`

You can find these on the <https://secure.backblaze.com/app_keys.htm> page. 

```php
use Samicrusader\Flysystem\BackblazeClient;
use Samicrusader\Flysystem\BackblazeAdapter;
use League\Flysystem\Filesystem;

$client = new BackblazeClient(
    accountId: 'xxxxxxxxxxxx', 
    authorizationValues: [
        'keyId' => 'xxxxxxxxxxxxxxxxxxxxxxxxx', /* optional if using master account key */
        'applicationKey' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    ]
);
$client->version = 2; // client defaults to v1 for some reason

$adapter = new BackblazeAdapter($client, $bucketName);
$filesystem = new Filesystem($adapter);
```

[ico-version]: https://img.shields.io/packagist/v/mhetreramesh/flysystem-backblaze.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[link-packagist]: https://packagist.org/packages/mhetreramesh/flysystem-backblaze
