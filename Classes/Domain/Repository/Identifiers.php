<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Domain\Repository;

use function array_keys;
use function implode;
use function json_encode;

class Identifiers
{
    const IDENTIFIER_GLUE = ',';

    protected $identifiers = [];

    public function __construct(array $fields)
    {
        foreach ($fields as $field => $value) {
            $this->addIdentifier($field, $value);
        }
    }

    public function addIdentifier(string $field, $value)
    {
        $this->identifiers[$field] = $value;
    }

    public function buildRowIdentifierHash(array $row): string
    {
        $identifier = [];
        foreach (array_keys($this->identifiers) as $field) {
            $identifier[$field] = $row[$field];
        }
        return sha1(json_encode($identifier));
    }

    public function asArray(): array
    {
        return $this->identifiers;
    }

    public function getIdentifierFields(): array
    {
        return array_keys($this->identifiers);
    }

    public function getIdentifierFieldList(string $glue = self::IDENTIFIER_GLUE): string
    {
        return implode($glue, $this->getIdentifierFields());
    }
}
