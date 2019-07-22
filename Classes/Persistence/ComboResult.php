<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

use In2code\In2publishCore\Domain\Repository\Identifiers;

interface ComboResult
{
    public function __construct(Identifiers $identifiers);

    public function addResultForStorage(Result $result, Storage $storage);

    public function getCombinedRows(): CombinedRows;
}
