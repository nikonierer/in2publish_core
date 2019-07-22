<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

interface ComboQuery extends Query
{
    public function forStorage(Storage $storage): Query;
}
