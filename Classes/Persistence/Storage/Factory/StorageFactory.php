<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\Storage\Factory;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class StorageFactory
{
    public function createSplitStorage(array $names)
    {

    }

    public function createDatabaseStorage(DatabaseStorageConfiguration $configuration)
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool->getQueryBuilderForTable()
    }
}
