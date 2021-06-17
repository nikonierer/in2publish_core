<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Component\TcaPreProcessor;

use function array_keys;

class RelationRegister
{
    /** @var array<Relation> */
    protected $relations = [];

    public function register(Relation $relation): void
    {
        $this->relations[] = $relation;
    }

    public function scanForCircularRelations(): array
    {
        $fromTo = [];
        foreach ($this->relations as $relation) {
            $fromTo[$relation->getFromTable()][$relation->getToTable()] = $relation;
        }
        $circulars = [];
        foreach (array_keys($fromTo) as $source) {
            foreach ($fromTo[$source] as $target => $relation) {
                    if (isset($fromTo[$target])) {
                        foreach (array_keys($fromTo[$target]) as $targetTarget) {
                            if ($targetTarget === $source && $target !== $source) {
                                $circulars[] = [$relation];
                            }
                        }
                }
            }
        }
        return $circulars;
    }
}
