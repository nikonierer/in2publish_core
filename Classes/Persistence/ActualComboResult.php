<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publishCore\Domain\Repository\Identifiers;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ActualComboResult implements ComboResult
{
    /**
     * @var Identifiers
     */
    protected $identifiers = null;

    /**
     * @var Result[]
     */
    protected $results = [];

    /**
     * @var Storage[]
     */
    protected $storages = [];

    public function __construct(Identifiers $identifiers)
    {
        $this->identifiers = $identifiers;
    }

    public function addResultForStorage(Result $result, Storage $storage)
    {
        $name = $storage->getName();
        $this->storages[$name] = $storage;
        $this->results[$name] = $result;
    }

    public function getCombinedRows(): CombinedRows
    {
        $combinedRows = GeneralUtility::makeInstance(CombinedRows::class, $this->identifiers);
        foreach ($this->results as $storageName => $result) {
            $combinedRows->addResultForStorage($result, $this->storages[$storageName]);
        }
        return $combinedRows;
    }
}
