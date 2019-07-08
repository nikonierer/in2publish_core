<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\QueryResult;

class SingleQueryResult implements QueryResult
{
    protected $rows = [];

    /**
     * SingleQueryResult constructor.
     *
     * @param array $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function getRows(): array
    {
        return $this->rows;
    }
}
