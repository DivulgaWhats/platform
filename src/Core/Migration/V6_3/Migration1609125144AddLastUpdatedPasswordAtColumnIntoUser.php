<?php declare(strict_types=1);

namespace Shopware\Core\Migration\V6_3;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @deprecated tag:v6.5.0 - reason:becomes-internal - Migrations will be internal in v6.5.0
 */
class Migration1609125144AddLastUpdatedPasswordAtColumnIntoUser extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1609125144;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            ALTER TABLE `user` ADD COLUMN `last_updated_password_at` DATETIME(3) NULL AFTER `store_token`;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
