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

namespace Pimcore\Tests\Model\Asset;

use Pimcore\Model\Asset\WebDAV\Service;
use Pimcore\Tests\Support\Test\ModelTestCase;

/**
 * Unit coverage for the WebDAV delete-log housekeeping. The end-to-end restore behaviour
 * (Tree::move() reusing the deleted destination id) is covered by WebDavIntegrationTest.
 *
 * @group model.asset.webdav
 */
class WebDavTest extends ModelTestCase
{
    protected function needsDb(): bool
    {
        return false;
    }

    protected function tearDown(): void
    {
        foreach ([Service::getDeleteLogFile(), Service::getDeleteLogFile() . '.lock'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * The delete log stores only scalar identity data (path => [id, timestamp]); this must
     * survive the serialize/save/read round-trip without needing any object instantiation.
     */
    public function testDeleteLogStoresIdAndSurvivesRoundTrip(): void
    {
        Service::saveDeleteLog([
            '/some/path.jpg' => [
                'id' => 42,
                'timestamp' => time(),
            ],
        ]);

        $log = Service::getDeleteLog();

        $this->assertArrayHasKey('/some/path.jpg', $log);
        $this->assertSame(42, $log['/some/path.jpg']['id']);
    }

    /**
     * Old entries (>30s) must be pruned by the delete-log housekeeping so stale ids are never
     * reused during a move.
     */
    public function testDeleteLogPrunesStaleEntries(): void
    {
        Service::saveDeleteLog([
            '/stale' => [
                'id' => 1,
                'timestamp' => time() - 60,
            ],
            '/fresh' => [
                'id' => 2,
                'timestamp' => time(),
            ],
        ]);

        $log = Service::getDeleteLog();

        $this->assertArrayNotHasKey('/stale', $log);
        $this->assertArrayHasKey('/fresh', $log);
    }

    /**
     * addDeleteLogEntry() must merge into the existing log, not replace it - this is the
     * lost-update protection that File::delete() relies on for concurrent WebDAV requests.
     */
    public function testAddDeleteLogEntryPreservesExistingEntries(): void
    {
        Service::addDeleteLogEntry('/first.jpg', ['id' => 1, 'timestamp' => time()]);
        Service::addDeleteLogEntry('/second.jpg', ['id' => 2, 'timestamp' => time()]);

        $log = Service::getDeleteLog();

        $this->assertSame(1, $log['/first.jpg']['id'] ?? null);
        $this->assertSame(2, $log['/second.jpg']['id'] ?? null);
    }

    /**
     * Re-adding the same path overwrites the previous entry.
     */
    public function testAddDeleteLogEntryOverwritesSamePath(): void
    {
        Service::addDeleteLogEntry('/same.jpg', ['id' => 1, 'timestamp' => time()]);
        Service::addDeleteLogEntry('/same.jpg', ['id' => 2, 'timestamp' => time()]);

        $log = Service::getDeleteLog();

        $this->assertSame(2, $log['/same.jpg']['id'] ?? null);
    }

    /**
     * Adding an entry must not resurrect stale entries already on disk.
     */
    public function testAddDeleteLogEntryPrunesStaleEntries(): void
    {
        Service::saveDeleteLog([
            '/stale' => ['id' => 1, 'timestamp' => time() - 60],
        ]);

        Service::addDeleteLogEntry('/fresh.jpg', ['id' => 2, 'timestamp' => time()]);

        $log = Service::getDeleteLog();

        $this->assertArrayNotHasKey('/stale', $log);
        $this->assertArrayHasKey('/fresh.jpg', $log);
    }
}
