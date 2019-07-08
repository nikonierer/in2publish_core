<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\Storage;

use In2code\In2publishCore\Persistence\Query\Query;
use In2code\In2publishCore\Persistence\QueryResult\CombinedQueryResult;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SplitStorage extends AbstractStorage
{
    /**
     * @var Storage[]
     */
    protected $storages = [];

    public function addStorage(Storage $storage)
    {
        $this->storages[$storage->getName()] = $storage;
    }

    public function execute(Query $query): array
    {
        $combinedQueryResult = GeneralUtility::makeInstance(CombinedQueryResult::class);
        foreach ($this->storages as $name => $storage) {
            $result[$name] = $storage->execute($query);
        }
        return $result;
    }
}
