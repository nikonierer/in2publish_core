<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Component\TcaPreProcessor;

use In2code\In2publishCore\Component\TcaPreProcessor\Events\TcaPreProcessingBeganEvent;

use function array_key_exists;
use function is_array;

class ProcessingUnit
{
    public function process()
    {
        $relationRegister = new RelationRegister();

        $tca = $GLOBALS['TCA'];

        foreach ($tca as $table => $tableConfig) {
            if (is_array($tableConfig) && array_key_exists('columns', $tableConfig)) {
                foreach ($tableConfig['columns'] as $column => $columnConfig) {
                    if (is_array($columnConfig) && array_key_exists('config', $columnConfig)) {
                        $config = $columnConfig['config'];
                        if (is_array($config) && array_key_exists('type', $config)) {
                            switch ($config['type']) {
                                case 'select':
                                    foreach (['fileFolder', 'itemsProcFunc', 'special'] as $unsupported) {
                                        if (array_key_exists($unsupported, $config)) {
                                            break 2;
                                        }
                                    }
                                    if (array_key_exists('MM', $config)) {
                                    } else {
                                        if (array_key_exists('foreign_table', $config)) {
                                            $relation = new Relation($table, $column, $config['foreign_table'], 'uid');
                                            $relationRegister->register($relation);
                                        }
                                    }
                                    break;
                                case 'inline':
                                    if (array_key_exists('foreign_table', $config)) {
                                        $relation = new Relation($table, $column, $config['foreign_table'], $config['foreign_field'] ?? 'uid');
                                        $relationRegister->register($relation);
                                    }
                            }
                        }
                    }
                }
            }
        }

        return $relationRegister;
    }
}
