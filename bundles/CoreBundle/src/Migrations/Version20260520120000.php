<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\CoreBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520120000 extends AbstractMigration
{
    private const TABLE = 'element_workflow_state';

    private const COLUMN = 'publishedPlace';

    public function getDescription(): string
    {
        return 'Add publishedPlace column to element_workflow_state to support discard-draft revert for the versioned state_table marking store.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);
        if (!$table->hasColumn(self::COLUMN)) {
            $table->addColumn(self::COLUMN, 'text', ['notnull' => false, 'default' => null]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);
        if ($table->hasColumn(self::COLUMN)) {
            $table->dropColumn(self::COLUMN);
        }
    }
}
