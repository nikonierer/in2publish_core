<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\Query;

use Doctrine\DBAL\Driver\Statement;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class SimpleQuery implements Query
{
    /**
     * @var int
     */
    protected $identifier = null;

    /**
     * @var string
     */
    protected $table = null;

    /**
     * SimpleQuery constructor.
     *
     * @param int $identifier
     * @param string $table
     */
    public function __construct(int $identifier, string $table)
    {
        $this->identifier = $identifier;
        $this->table = $table;
    }

    /**
     * @return int
     */
    public function getIdentifier(): int
    {
        return $this->identifier;
    }

    /**
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    public function execute(QueryBuilder $queryBuilder): Statement
    {
        return $queryBuilder->select('*')->from($this->table)->execute();
    }
}
