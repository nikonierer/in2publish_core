<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Component\TcaPreProcessor;

class Relation
{
    protected $fromTable;

    protected $fromColumn;

    protected $toTable;

    protected $toField;

    public function __construct(string $fromTable, string $fromColumn, string $toTable, string $toField)
    {
        $this->fromTable = $fromTable;
        $this->fromColumn = $fromColumn;
        $this->toTable = $toTable;
        $this->toField = $toField;
    }

    public function getFromTable(): string
    {
        return $this->fromTable;
    }

    public function getFromColumn(): string
    {
        return $this->fromColumn;
    }

    public function getToTable(): string
    {
        return $this->toTable;
    }

    public function getToField(): string
    {
        return $this->toField;
    }
}
