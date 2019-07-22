<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DatabaseStorage extends AbstractStorage
{
    /**
     * @var Connection
     */
    protected $connection = null;

    public function setConnectionPool(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function createQuery(): Query
    {
        return GeneralUtility::makeInstance(ActualDatabaseQuery::class);
    }

    public function execute(Query $query): Result
    {
        if (!($query instanceof DatabaseQuery)) {
            throw InvalidQueryTypeException::for($this, DatabaseQuery::class, $query);
        }
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $statement = $query->execute($queryBuilder);
        $result = GeneralUtility::makeInstance(DatabaseResult::class);
        $result->setStatement($statement);
        return $result;
    }
}
