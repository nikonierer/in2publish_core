<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\Storage\Factory;

class DatabaseStorageConfiguration
{
    protected $storageName = '';

    protected $connectionName = '';

    /**
     * DatabaseStorageConfiguration constructor.
     *
     * @param string $storageName
     * @param string $connectionName
     */
    public function __construct(string $storageName, string $connectionName)
    {
        $this->storageName = $storageName;
        $this->connectionName = $connectionName;
    }

    /**
     * @return string
     */
    public function getStorageName(): string
    {
        return $this->storageName;
    }

    /**
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }
}
