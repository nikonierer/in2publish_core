<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\QueryResult;

class CombinedQueryResult
{
    /**
     * @var QueryResult[]
     */
    protected $queryResults = [];

    public function addQueryResult(QueryResult $queryResult)
    {
        $this->queryResults[$queryResult->getStorage()->getName()] = $queryResult;
    }
}
