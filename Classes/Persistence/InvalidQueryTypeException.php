<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publish\In2publishException;
use function get_class;
use function sprintf;

class InvalidQueryTypeException extends In2publishException
{
    const MESSAGE = 'The storage %s expects the query to be of type %s but it is a %s';
    const CODE = 1563803607;

    public static function for(Storage $storage, string $expectedType, Query $query)
    {
        $message = sprintf(static::MESSAGE, $storage->getName(), $expectedType, get_class($query));
        return new static($message, static::CODE);
    }
}
