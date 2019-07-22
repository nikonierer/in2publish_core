<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence;

abstract class AbstractStorage implements Storage
{
    protected $name = '';

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
