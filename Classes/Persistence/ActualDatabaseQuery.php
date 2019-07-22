<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use Doctrine\DBAL\Driver\Statement;
use In2code\In2publishCore\Service\Configuration\TcaService;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ActualDatabaseQuery extends AbstractQuery implements DatabaseQuery
{
    public function execute(QueryBuilder $queryBuilder): Statement
    {
        if (!$this->hasTable()) {
            throw MissingTableException::for();
        }
        if (!$this->hasIdentifiers()) {
            throw MissingIdentifiersException::for();
        }
        $queryBuilder->select(...$this->fields)
                     ->from($this->table);
        foreach ($this->identifiers->asArray() as $field => $value) {
            $queryBuilder->where($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)));
        }
        if ($this->hasAdditionalWhere()) {
            $queryBuilder->andWhere($this->additionalWhere);
        }
        $sortingField = GeneralUtility::makeInstance(TcaService::class)->getSortingField($this->table);
        if (!empty($sortingField)) {
            $queryBuilder->orderBy($sortingField);
        }
        return $queryBuilder->execute();
    }
}
