<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use Doctrine\DBAL\Driver\Statement;

class DatabaseResult implements Result
{
    /**
     * @var Statement
     */
    protected $statement = null;

    public function setStatement(Statement $statement)
    {
        $this->statement = $statement;
    }

    public function getRows(): array
    {
        return $this->statement->fetchAll();
    }
}
