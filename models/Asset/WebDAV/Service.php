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

namespace Pimcore\Model\Asset\WebDAV;

use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 *
 * The WebDAV delete log lets Tree::move() restore a destination that a client deleted and then
 * re-created via a separate MOVE request (e.g. Photoshop). See Asset\WebDAV\File::delete() and
 * Asset\WebDAV\Tree::move().
 *
 * KNOWN LIMITATION (multi-node): the log is stored on the local filesystem
 * (PIMCORE_SYSTEM_TEMP_DIRECTORY), and the DELETE and the follow-up MOVE are separate HTTP
 * requests. In a horizontally-scaled deployment (typical with blob/cloud asset storage) those two
 * requests may land on different nodes, so the MOVE won't find the entry and the restore silently
 * degrades to a plain rename (source keeps its own id; the deleted asset's id/metadata are not
 * preserved). Any writer-side locking here is per-node only (advisory flock on a local file), so
 * it guards against lost updates on the same node but not across nodes.
 *
 * TODO (cluster-safe delete log): move this state into a shared backend so it works regardless of
 * which node handles each request. The natural choice is a small DB table (mirroring how the Sabre
 * lock plugin already uses the `webdav_locks` table) or the Pimcore cache/Redis. That would make
 * the read-modify-write genuinely atomic cluster-wide (row upsert / Redis) and let any local file
 * locking be removed. Start in File::delete() (writer), Tree::move() (reader), and this class.
 */
class Service
{
    public static function getDeleteLogFile(): string
    {
        return PIMCORE_SYSTEM_TEMP_DIRECTORY . '/webdav-delete.dat';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getDeleteLog(): array
    {
        $log = [];
        if (file_exists(self::getDeleteLogFile())) {
            $raw = file_get_contents(self::getDeleteLogFile());
            if (is_string($raw)) {
                // the delete log only holds scalar entries (path => [id, timestamp]),
                // so no object instantiation is expected or allowed
                $log = unserialize($raw, ['allowed_classes' => false]);
            }

            if (!is_array($log)) {
                $log = [];
            } else {
                // cleanup old entries
                $tmpLog = [];
                foreach ($log as $path => $data) {
                    if ($data['timestamp'] > (time() - 30)) { // remove 30 seconds old entries
                        $tmpLog[$path] = $data;
                    }
                }

                $log = $tmpLog;
            }
        }

        return $log;
    }

    public static function saveDeleteLog(array $log): void
    {
        // cleanup old entries
        $tmpLog = [];
        foreach ($log as $path => $data) {
            if ($data['timestamp'] > (time() - 30)) { // remove 30 seconds old entries
                $tmpLog[$path] = $data;
            }
        }

        $filesystem = new Filesystem();
        $filesystem->dumpFile(self::getDeleteLogFile(), serialize($tmpLog));
    }

    /**
     * Atomically add (or overwrite) a single delete-log entry, so concurrent WebDAV requests
     * cannot lose each other's entries.
     *
     * A dedicated lock file provides mutual exclusion between writers (read-modify-write), while
     * the data file itself is still written via dumpFile() (temp file + atomic rename). That way a
     * concurrent reader in getDeleteLog() - which does not take the lock - always sees a complete
     * file, never a torn one.
     *
     * NOTE: this locking is per-node only (see the class-level TODO on a cluster-safe delete log);
     * it does not coordinate writers across nodes in a horizontally-scaled deployment.
     *
     * @param array<string, mixed> $entry
     */
    public static function addDeleteLogEntry(string $path, array $entry): void
    {
        $lockHandle = fopen(self::getDeleteLogFile() . '.lock', 'c');
        if ($lockHandle === false) {
            return; // best effort: never let logging failure break a delete
        }

        try {
            flock($lockHandle, LOCK_EX);

            $log = self::getDeleteLog(); // reads + prunes stale entries
            $log[$path] = $entry;

            $filesystem = new Filesystem();
            $filesystem->dumpFile(self::getDeleteLogFile(), serialize($log));
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
