<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publish\In2publishException;

class MissingTableException extends In2publishException
{
    const MESSAGE = 'The query was not configured with a table name';
    const CODE = 1563801741;

    public static function for()
    {
        return new static(static::MESSAGE, static::CODE);
    }
}
