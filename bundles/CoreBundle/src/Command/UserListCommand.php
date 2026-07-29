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

namespace Pimcore\Bundle\CoreBundle\Command;

use Pimcore\Console\AbstractCommand;
use Pimcore\Model\Element;
use Pimcore\Model\User;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
#[AsCommand(
    name: 'pimcore:user:list',
    description: 'List or export the configured Pimcore users',
)]
class UserListCommand extends AbstractCommand
{
    private const FORMAT_TABLE = 'table';

    private const FORMAT_CSV = 'csv';

    /**
     * @var array<string, string>
     */
    private const COLUMNS = [
        'id' => 'ID',
        'username' => 'Username',
        'firstname' => 'First name',
        'lastname' => 'Last name',
        'email' => 'Email',
        'language' => 'Language',
        'admin' => 'Admin',
        'active' => 'Active',
        'roles' => 'Roles',
        'lastLogin' => 'Last login',
    ];

    protected function configure(): void
    {
        $this
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format, either "table" or "csv"',
                self::FORMAT_TABLE
            )
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Write the output to the given file instead of stdout (recommended for CSV exports)'
            )
            ->addOption(
                'admins-only',
                null,
                InputOption::VALUE_NONE,
                'Only list users with admin privileges'
            )
            ->addOption(
                'exclude-inactive',
                null,
                InputOption::VALUE_NONE,
                'Exclude inactive (disabled) users'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, [self::FORMAT_TABLE, self::FORMAT_CSV], true)) {
            $this->writeError(sprintf('Invalid format "%s", allowed values are "%s" and "%s".', $format, self::FORMAT_TABLE, self::FORMAT_CSV));

            return self::FAILURE;
        }

        $rows = $this->collectRows(
            (bool) $input->getOption('admins-only'),
            (bool) $input->getOption('exclude-inactive')
        );

        $file = $input->getOption('file');
        $file = $file !== null ? (string) $file : null;

        if ($format === self::FORMAT_CSV) {
            return $this->outputCsv($rows, $file);
        }

        return $this->outputTable($rows, $file);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function collectRows(bool $adminsOnly, bool $excludeInactive): array
    {
        $listing = new User\Listing();
        $listing->setOrderKey('name');
        $listing->setOrder('ASC');

        $rows = [];
        foreach ($listing->load() as $user) {
            // skip folders, only export actual users
            if (!$user instanceof User) {
                continue;
            }

            if ($adminsOnly && !$user->getAdmin()) {
                continue;
            }

            if ($excludeInactive && !$user->getActive()) {
                continue;
            }

            $rows[] = [
                'id' => (string) $user->getId(),
                'username' => (string) $user->getName(),
                'firstname' => (string) $user->getFirstname(),
                'lastname' => (string) $user->getLastname(),
                'email' => (string) $user->getEmail(),
                'language' => $user->getLanguage(),
                'admin' => $user->getAdmin() ? '1' : '0',
                'active' => $user->getActive() ? '1' : '0',
                'roles' => $this->resolveRoleNames($user->getRoles()),
                'lastLogin' => $user->getLastLogin() ? date('c', $user->getLastLogin()) : '',
            ];
        }

        return $rows;
    }

    /**
     * @param int[] $roleIds
     */
    private function resolveRoleNames(array $roleIds): string
    {
        $names = [];
        foreach ($roleIds as $roleId) {
            $role = User\Role::getById($roleId);
            if ($role) {
                $names[] = $role->getName();
            }
        }

        return implode(', ', $names);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function outputTable(array $rows, ?string $file): int
    {
        if ($file !== null) {
            $this->writeError('The --file option is only supported for the "csv" format.');

            return self::FAILURE;
        }

        $tableRows = array_map(static fn (array $row): array => array_values($row), $rows);

        $this->io->table(array_values(self::COLUMNS), $tableRows);
        $this->io->writeln(sprintf('%d user(s) found.', count($rows)));

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function outputCsv(array $rows, ?string $file): int
    {
        // without a target file the CSV is buffered and handed to the console output, so that it
        // honors output redirection instead of writing to the process' stdout directly
        $target = $file ?? 'php://temp';

        // failures are reported through the command output instead of a PHP warning
        $stream = @fopen($target, $file !== null ? 'w' : 'r+');
        if ($stream === false) {
            $this->writeError(sprintf('Unable to open "%s" for writing.', $target));

            return self::FAILURE;
        }

        // the escape character is passed explicitly to opt out of PHP's proprietary escaping
        // mechanism, which is deprecated as of PHP 8.4, and to produce RFC 4180 compliant output
        fputcsv($stream, array_values(self::COLUMNS), ',', '"', '');
        foreach ($rows as $row) {
            // guard against CSV injection, values such as names are not restricted to safe characters
            fputcsv($stream, array_values(Element\Service::escapeCsvRecord($row)), ',', '"', '');
        }

        if ($file === null) {
            rewind($stream);
            $this->output->write((string) stream_get_contents($stream), false, OutputInterface::OUTPUT_RAW);
            fclose($stream);

            return self::SUCCESS;
        }

        fclose($stream);
        $this->io->writeln(sprintf('Exported %d user(s) to %s', count($rows), $file));

        return self::SUCCESS;
    }
}
