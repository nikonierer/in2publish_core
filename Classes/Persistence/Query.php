<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publishCore\Domain\Repository\Identifiers;

interface Query
{
    public function select(string ...$fields): Query;

    public function from(string $table): Query;

    public function hasTable(): bool;

    public function getTable(): string;

    public function whereIdentifiers(Identifiers $identifiers): Query;

    public function hasIdentifiers(): bool;

    public function getIdentifiers(): Identifiers;

    public function additionalWhere(string $additionalWhere): Query;

    public function hasAdditionalWhere(): bool;
}
