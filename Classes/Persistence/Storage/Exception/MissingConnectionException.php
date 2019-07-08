<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\Storage\Exception;

use In2code\In2publishCore\In2publishCoreException;
use In2code\In2publishCore\Persistence\Storage\DatabaseStorage;
use function sprintf;

class MissingConnectionException extends In2publishCoreException
{
    protected const MESSAGE = 'The DatabaseStorage "%s" was not initialized with a connection';
    public const CODE = 1561731595;

    public static function fromDatabaseStorage(DatabaseStorage $databaseStorage): MissingConnectionException
    {
        return new self(sprintf(self::MESSAGE, $databaseStorage->getName()), self::CODE);
    }
}
