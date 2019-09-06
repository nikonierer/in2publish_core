<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DatabaseStorage extends AbstractStorage
{
    /**
     * @var ConnectionPool
     */
    protected $connectionPool = null;

    public function setConnectionPool(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
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
        $statement = $query->execute($this->connectionPool);
        $result = GeneralUtility::makeInstance(DatabaseResult::class);
        $result->setStatement($statement);
        return $result;
    }
}
