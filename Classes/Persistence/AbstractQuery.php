<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publishCore\Domain\Repository\Identifiers;

abstract class AbstractQuery implements Query
{
    protected $fields = [];

    protected $table = '';

    /**
     * @var Identifiers
     */
    protected $identifiers = null;

    protected $additionalWhere = '';

    public function select(string ...$fields): Query
    {
        $this->fields = $fields;
        return $this;
    }

    public function from(string $table): Query
    {
        $this->table = $table;
        return $this;
    }

    public function hasTable(): bool
    {
        return !empty($this->table);
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function whereIdentifiers(Identifiers $identifiers): Query
    {
        $this->identifiers = $identifiers;
        return $this;
    }

    public function hasIdentifiers(): bool
    {
        return null !== $this->identifiers;
    }

    public function getIdentifiers(): Identifiers
    {
        return $this->identifiers;
    }

    public function additionalWhere(string $additionalWhere): Query
    {
        $this->additionalWhere = $additionalWhere;
        return $this;
    }

    public function hasAdditionalWhere(): bool
    {
        return !empty($this->additionalWhere);
    }
}
