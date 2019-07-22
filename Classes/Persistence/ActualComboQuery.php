<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publishCore\Service\Database\DatabaseSchemaService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use function array_values;

class ActualComboQuery extends AbstractQuery implements ComboQuery
{
    public function forStorage(Storage $storage): Query
    {
        $query = $storage->createQuery();
        if (!empty($this->fields)) {
            $query->select(...$this->fields);
        } else {
            $fields = GeneralUtility::makeInstance(DatabaseSchemaService::class)->getFieldsForTable($this->table);
            $query->select(...array_values($fields));
        }
        $query->from($this->table);
        if (null !== $this->identifiers) {
            $query->whereIdentifiers($this->identifiers);
        }
        if (!empty($this->additionalWhere)) {
            $query->additionalWhere($this->additionalWhere);
        }
        return $query;
    }
}
