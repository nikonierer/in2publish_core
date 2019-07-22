<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Domain\Repository;

use In2code\In2publishCore\Persistence\CombinedRecord;
use In2code\In2publishCore\Persistence\ComboStorage;
use In2code\In2publishCore\Persistence\DatabaseStorage;
use In2code\In2publishCore\Utility\DatabaseUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CombinedRecordRepository
{
    /**
     * @var ComboStorage
     */
    protected $comboStorage = null;

    public function __construct()
    {
        $this->comboStorage = GeneralUtility::makeInstance(ComboStorage::class);

        $localStorage = new DatabaseStorage('local');
        $localStorage->setConnectionPool(DatabaseUtility::buildLocalDatabaseConnection());
        $this->comboStorage->addStorage($localStorage);

        $foreignStorage = new DatabaseStorage('foreign');
        $foreignStorage->setConnectionPool(DatabaseUtility::buildForeignDatabaseConnection());
        $this->comboStorage->addStorage($foreignStorage);
    }

    /**
     * @param Identifiers $identifiers
     * @param string $table
     *
     * @return CombinedRecord[]
     */
    public function findByIdentifiers(Identifiers $identifiers, string $table): array
    {
        $query = $this->comboStorage->createQuery();
        $query->from($table)->whereIdentifiers($identifiers);
        $records = [];
        $comboResult = $this->comboStorage->execute($query);
        foreach ($comboResult->getCombinedRows()->asArray() as $combinedRow) {
            $records[] = GeneralUtility::makeInstance(CombinedRecord::class, $table, $identifiers, $combinedRow);
        }
        return $records;
    }
}
