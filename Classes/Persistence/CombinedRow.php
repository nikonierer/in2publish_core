<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publishCore\Domain\Repository\Identifiers;
use function array_key_exists;
use function reset;

class CombinedRow
{
    protected $rows = [];

    /**
     * @var Identifiers
     */
    protected $identifiers = null;

    public function setRowForStorage(array $row, Storage $storage, Identifiers $identifiers)
    {
        $this->rows[$storage->getName()] = $row;
        $this->identifiers = $identifiers;
    }

    /**
     * @param string $storageName
     *
     * @return array|null
     */
    public function tryGetRowForStorageName(string $storageName)
    {
        if ($this->hasRowForStorageName($storageName)) {
            return $this->getRowForStorageName($storageName);
        }
        return null;
    }

    public function hasRowForStorageName(string $storageName): bool
    {
        return array_key_exists($storageName, $this->rows);
    }

    public function getRowForStorageName(string $storageName): array
    {
        return $this->rows[$storageName];
    }

    public function buildRowIdentifierHash(): string
    {
        return $this->identifiers->buildRowIdentifierHash(reset($this->rows));
    }
}
