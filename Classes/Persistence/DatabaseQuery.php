<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use Doctrine\DBAL\Driver\Statement;
use TYPO3\CMS\Core\Database\ConnectionPool;

interface DatabaseQuery extends Query
{
    public function execute(ConnectionPool $connectionPool): Statement;
}
