<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Persistence\Storage;

abstract class AbstractStorage implements Storage
{
    /**
     * @var string
     */
    private $name = '';

    /**
     * AbstractStorage constructor.
     *
     * @param string $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
