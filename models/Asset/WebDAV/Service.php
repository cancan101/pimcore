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
     * Atomically add (or overwrite) a single delete-log entry under an exclusive file lock,
     * so concurrent WebDAV requests cannot lose each other's entries.
     *
     * @param array<string, mixed> $entry
     */
    public static function addDeleteLogEntry(string $path, array $entry): void
    {
        $file = self::getDeleteLogFile();
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return; // best effort: never let logging failure break a delete
        }

        try {
            flock($handle, LOCK_EX);

            $log = [];
            $raw = stream_get_contents($handle);
            if (is_string($raw) && $raw !== '') {
                // the delete log only holds scalar entries (path => [id, timestamp]),
                // so no object instantiation is expected or allowed
                $decoded = unserialize($raw, ['allowed_classes' => false]);
                if (is_array($decoded)) {
                    $log = $decoded;
                }
            }

            // cleanup old entries
            $now = time();
            foreach ($log as $key => $data) {
                if (!is_array($data) || !isset($data['timestamp']) || $data['timestamp'] <= ($now - 30)) { // remove 30 seconds old entries
                    unset($log[$key]);
                }
            }

            $log[$path] = $entry;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, serialize($log));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
