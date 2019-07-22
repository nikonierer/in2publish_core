<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

interface Result
{
    public function getRows(): array;
}
