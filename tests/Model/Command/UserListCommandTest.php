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

namespace Pimcore\Tests\Model\Command;

use Pimcore\Bundle\CoreBundle\Command\UserListCommand;
use Pimcore\Model\User;
use Pimcore\Tests\Support\Test\ModelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
class UserListCommandTest extends ModelTestCase
{
    private const ACTIVE_ADMIN = 'user-list-active-admin';

    private const INACTIVE_USER = 'user-list-inactive';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupUsers();

        $admin = new User();
        $admin->setName(self::ACTIVE_ADMIN);
        $admin->setEmail('active-admin@example.com');
        $admin->setAdmin(true);
        $admin->setActive(true);
        $admin->save();

        $inactive = new User();
        $inactive->setName(self::INACTIVE_USER);
        $inactive->setEmail('inactive@example.com');
        $inactive->setAdmin(false);
        $inactive->setActive(false);
        $inactive->save();
    }

    protected function tearDown(): void
    {
        $this->cleanupUsers();

        parent::tearDown();
    }

    private function cleanupUsers(): void
    {
        foreach ([self::ACTIVE_ADMIN, self::INACTIVE_USER] as $name) {
            $user = User::getByName($name);
            if ($user instanceof User) {
                $user->delete();
            }
        }
    }

    private function createCommandTester(): CommandTester
    {
        return new CommandTester(new UserListCommand());
    }

    public function testCsvExportContainsUsers(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--format' => 'csv'], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();
        $this->assertStringContainsString('ID,Username', $display);
        $this->assertStringContainsString(self::ACTIVE_ADMIN, $display);
        $this->assertStringContainsString('active-admin@example.com', $display);
        $this->assertStringContainsString(self::INACTIVE_USER, $display);
    }

    public function testExcludeInactiveFiltersDisabledUsers(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--format' => 'csv', '--exclude-inactive' => true], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString(self::ACTIVE_ADMIN, $display);
        $this->assertStringNotContainsString(self::INACTIVE_USER, $display);
    }

    public function testAdminsOnlyFiltersNonAdmins(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--format' => 'csv', '--admins-only' => true], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString(self::ACTIVE_ADMIN, $display);
        $this->assertStringNotContainsString(self::INACTIVE_USER, $display);
    }

    public function testInvalidFormatFails(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['--format' => 'xml'], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid format', $tester->getDisplay());
    }

    public function testCsvExportToFile(): void
    {
        $file = sys_get_temp_dir() . '/pimcore_user_list_' . uniqid() . '.csv';

        try {
            $tester = $this->createCommandTester();
            $tester->execute(['--format' => 'csv', '--file' => $file], ['interactive' => false]);

            $this->assertSame(0, $tester->getStatusCode());
            $this->assertFileExists($file);

            $contents = (string) file_get_contents($file);
            $this->assertStringContainsString('ID,Username', $contents);
            $this->assertStringContainsString(self::ACTIVE_ADMIN, $contents);
            $this->assertStringContainsString('Exported', $tester->getDisplay());
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
