<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\QueryResult;

interface QueryResult
{
    public function getRows(): array;
}
