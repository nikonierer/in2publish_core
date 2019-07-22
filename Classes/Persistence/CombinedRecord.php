<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publishCore\Domain\Factory\RecordFactory;
use In2code\In2publishCore\Domain\Model\RecordInterface;
use In2code\In2publishCore\Domain\Repository\CommonRepository;
use In2code\In2publishCore\Domain\Repository\Identifiers;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CombinedRecord
{
    protected $table = '';

    /**
     * @var Identifiers
     */
    protected $identifiers = null;

    protected $combinedRow = [];

    public function __construct(string $table, Identifiers $identifiers, CombinedRow $combinedRow)
    {
        $this->table = $table;
        $this->identifiers = $identifiers;
        $this->combinedRow = $combinedRow;
    }

    public function toLegacyRecord(): RecordInterface
    {
        $localProperties = $this->combinedRow->tryGetRowForStorageName('local') ?? [];
        $foreignProperties = $this->combinedRow->getRowForStorageName('foreign') ?? [];
        $record = GeneralUtility::makeInstance(RecordFactory::class)->makeInstance(
            CommonRepository::getDefaultInstance(),
            $localProperties,
            $foreignProperties,
            [],
            $this->table,
            $this->identifiers->getIdentifierFieldList()
        );
        return $record;
    }
}
