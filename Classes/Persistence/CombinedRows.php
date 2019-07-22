<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publishCore\Domain\Repository\Identifiers;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CombinedRows
{
    /**
     * @var Identifiers
     */
    protected $identifiers = null;

    /**
     * @var CombinedRow[]
     */
    protected $combinedRows = [];

    /**
     * @param Identifiers $identifiers
     */
    public function __construct(Identifiers $identifiers)
    {
        $this->identifiers = $identifiers;
    }

    public function addResultForStorage(Result $result, Storage $storage)
    {
        foreach ($result->getRows() as $row) {
            $rowIdentifierHash = $this->identifiers->buildRowIdentifierHash($row);
            if (!isset($this->combinedRows[$rowIdentifierHash])) {
                $this->combinedRows[$rowIdentifierHash] = GeneralUtility::makeInstance(CombinedRow::class);
            }
            $this->combinedRows[$rowIdentifierHash]->setRowForStorage($row, $storage, $this->identifiers);
        }
    }

    /**
     * @return CombinedRow[]
     */
    public function asArray(): array
    {
        return $this->combinedRows;
    }
}
