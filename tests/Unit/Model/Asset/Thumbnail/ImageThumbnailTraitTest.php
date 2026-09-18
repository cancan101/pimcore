<?php
declare(strict_types=1);

/**
 * This source file is available under the terms of the
 * Pimcore Open Core License (POCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (https://www.pimcore.com)
 *  @license    Pimcore Open Core License (POCL)
 */

namespace Pimcore\Tests\Unit\Model\Asset\Thumbnail;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\MockObject\MockObject;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image\Thumbnail\Config;
use Pimcore\Model\Asset\Thumbnail\ImageThumbnailTrait;
use Pimcore\Tests\Support\Test\TestCase;

/**
 * @internal
 */
class ImageThumbnailTraitTest extends TestCase
{
    private const STORAGE_PATH = '/testimage/1/image-thumb__1__unittest/testimage.jpg';

    public function testGetStreamRethrowsWhenFileStillExists(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('readStream')->willThrowException(UnableToReadFile::fromLocation(self::STORAGE_PATH));
        $storage->method('fileExists')->willReturn(true);

        $asset = $this->createImageAsset();
        $asset->expects($this->never())->method('getDao');

        $thumbnail = $this->createThumbnail($storage, $asset, $this->createConfig());

        try {
            $thumbnail->getStream();
            $this->fail('Expected ' . UnableToReadFile::class . ' to be thrown');
        } catch (UnableToReadFile $e) {
            // the instance keeps its state, the thumbnail is not regenerated for a storage problem
            $this->assertSame(0, $thumbnail->generateCalls);
            $this->assertSame(self::STORAGE_PATH, $thumbnail->getPathReference(true)['storagePath']);
        }
    }

    public function testGetStreamRethrowsWhenExistenceCannotBeDetermined(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('readStream')->willThrowException(UnableToReadFile::fromLocation(self::STORAGE_PATH));
        $storage->method('fileExists')->willThrowException(new UnableToCheckFileExistence('Unable to check file existence for: ' . self::STORAGE_PATH));

        $asset = $this->createImageAsset();
        $asset->expects($this->never())->method('getDao');

        $thumbnail = $this->createThumbnail($storage, $asset, $this->createConfig());

        $this->expectException(UnableToReadFile::class);
        $thumbnail->getStream();
    }

    public function testGetStreamRegeneratesAndServesWhenFileIsMissing(): void
    {
        $regenerated = fopen('php://memory', 'r+');

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('fileExists')->willReturn(false);
        // the first read runs into the missing file, the second one delivers the regenerated thumbnail
        $storage->expects($this->exactly(2))
            ->method('readStream')
            ->with(self::STORAGE_PATH)
            ->willReturnCallback(function () use ($regenerated) {
                static $call = 0;
                if (++$call === 1) {
                    throw UnableToReadFile::fromLocation(self::STORAGE_PATH);
                }

                return $regenerated;
            });

        $dao = $this->createMock(Asset\Dao::class);
        $dao->expects($this->once())
            ->method('deleteFromThumbnailCache')
            ->with('unittest', basename(self::STORAGE_PATH));

        $asset = $this->createImageAsset();
        $asset->method('getDao')->willReturn($dao);

        $thumbnail = $this->createThumbnail($storage, $asset, $this->createConfig());

        // the stale status cache entry is invalidated, the instance reset and the thumbnail
        // regenerated and served by the very same call
        $this->assertSame($regenerated, $thumbnail->getStream());
        $this->assertSame(1, $thumbnail->generateCalls);
        $this->assertSame(self::STORAGE_PATH, $thumbnail->getPathReference(true)['storagePath']);
    }

    public function testGetStreamReturnsNullWhenRegenerationFails(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('readStream')->willThrowException(UnableToReadFile::fromLocation(self::STORAGE_PATH));
        $storage->method('fileExists')->willReturn(false);

        $dao = $this->createMock(Asset\Dao::class);
        $dao->expects($this->once())->method('deleteFromThumbnailCache');

        $asset = $this->createImageAsset();
        $asset->method('getDao')->willReturn($dao);

        $thumbnail = $this->createThumbnail($storage, $asset, $this->createConfig(), [
            'type' => 'error',
            'src' => '/bundles/pimcoreadmin/img/filetype-not-supported.svg',
        ]);

        // a failed regeneration ends in the placeholder path reference, which has no stream
        $this->assertNull($thumbnail->getStream());
        $this->assertSame(1, $thumbnail->generateCalls);
        $this->assertSame('error', $thumbnail->getPathReference(true)['type']);
    }

    public function testGetStreamInvalidatesStatusCacheOfDelegatedOwner(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('readStream')->willThrowException(UnableToReadFile::fromLocation(self::STORAGE_PATH));
        $storage->method('fileExists')->willReturn(false);

        // e.g. a video thumbnail delegating its path reference to a poster image asset:
        // the stale status cache entry belongs to the delegated asset, not the thumbnail's own asset
        $ownerDao = $this->createMock(Asset\Dao::class);
        $ownerDao->expects($this->once())
            ->method('deleteFromThumbnailCache')
            ->with('unittest', basename(self::STORAGE_PATH));

        $owner = $this->createMock(Asset\Image::class);
        $owner->method('getDao')->willReturn($ownerDao);

        $asset = $this->createImageAsset();
        $asset->expects($this->never())->method('getDao');

        $thumbnail = new class($this->createConfig(), $storage, $asset, $owner, self::STORAGE_PATH) {
            use ImageThumbnailTrait;

            public function __construct(
                ?Config $config,
                private readonly FilesystemOperator $storage,
                ?Asset $asset,
                private readonly ?Asset $cacheOwner,
                string $storagePath
            ) {
                $this->asset = $asset;
                $this->config = $config;
                $this->pathReference = [
                    'type' => 'thumbnail',
                    'src' => $storagePath,
                    'storagePath' => $storagePath,
                ];
            }

            protected function getThumbnailStorage(): FilesystemOperator
            {
                return $this->storage;
            }

            protected function getThumbnailStatusCacheOwner(): ?Asset
            {
                return $this->cacheOwner;
            }

            public function generate(bool $deferredAllowed = true): void
            {
                // the regeneration fails, so there is nothing to serve
                $this->pathReference = ['type' => 'error', 'src' => '/bundles/pimcoreadmin/img/filetype-not-supported.svg'];
            }
        };

        $this->assertNull($thumbnail->getStream());
    }

    /**
     * the filename is needed when the thumbnail is resolved again after a reset()
     */
    private function createImageAsset(): Asset\Image&MockObject
    {
        $asset = $this->createMock(Asset\Image::class);
        $asset->method('getFilename')->willReturn('testimage.jpg');

        return $asset;
    }

    private function createConfig(): Config
    {
        $config = new Config();
        $config->setName('unittest');

        return $config;
    }

    /**
     * @param array|null $regeneratedPathReference the path reference generate() resolves to after a reset(),
     *                                             defaults to the same thumbnail path reference as initially
     */
    private function createThumbnail(FilesystemOperator $storage, Asset $asset, Config $config, ?array $regeneratedPathReference = null): object
    {
        $pathReference = [
            'type' => 'thumbnail',
            'src' => self::STORAGE_PATH,
            'storagePath' => self::STORAGE_PATH,
        ];

        return new class($storage, $asset, $config, $pathReference, $regeneratedPathReference ?? $pathReference) {
            use ImageThumbnailTrait;

            public int $generateCalls = 0;

            public function __construct(
                private readonly FilesystemOperator $storage,
                ?Asset $asset,
                ?Config $config,
                array $pathReference,
                private readonly array $regeneratedPathReference
            ) {
                $this->asset = $asset;
                $this->config = $config;
                $this->pathReference = $pathReference;
            }

            protected function getThumbnailStorage(): FilesystemOperator
            {
                return $this->storage;
            }

            public function generate(bool $deferredAllowed = true): void
            {
                $this->generateCalls++;
                $this->pathReference = $this->regeneratedPathReference;
            }
        };
    }
}
