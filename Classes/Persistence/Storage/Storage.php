<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\Storage;

use In2code\In2publishCore\Persistence\Query\Query;

interface Storage
{
    public function __construct(string $name);

    public function getName(): string;

    public function execute(Query $query): array;
}
