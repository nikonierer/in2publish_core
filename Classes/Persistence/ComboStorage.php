<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use TYPO3\CMS\Core\Utility\GeneralUtility;

class ComboStorage
{
    /**
     * @var Storage[]
     */
    protected $storages = [];

    public function createQuery(): ComboQuery
    {
        return GeneralUtility::makeInstance(ActualComboQuery::class);
    }

    public function addStorage(Storage $storage)
    {
        $this->storages[$storage->getName()] = $storage;
    }

    public function execute(ComboQuery $query): ComboResult
    {
        $comboResult = GeneralUtility::makeInstance(ActualComboResult::class, $query->getIdentifiers());
        foreach ($this->storages as $storage) {
            $comboResult->addResultForStorage($storage->execute($query->forStorage($storage)), $storage);
        }
        return $comboResult;
    }
}
