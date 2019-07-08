<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\Storage;

use In2code\In2publishCore\Persistence\Query\Query;
use In2code\In2publishCore\Persistence\Storage\Exception\MissingConnectionException;
use PDO;
use TYPO3\CMS\Core\Database\Connection;

class DatabaseStorage extends AbstractStorage
{
    /**
     * @var Connection
     */
    protected $connection = null;

    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function execute(Query $query): array
    {
        if (null === $this->connection) {
            throw MissingConnectionException::fromDatabaseStorage($this);
        }
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $statement = $query->execute($queryBuilder);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
