<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

interface Storage
{
    public function __construct(string $name);

    public function getName(): string;

    public function createQuery(): Query;

    public function execute(Query $query): Result;
}
