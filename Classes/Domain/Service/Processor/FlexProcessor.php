<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Domain\Service\Processor;

use function array_key_exists;

/*
 * Copyright notice
 *
 * (c) 2016 in2code.de and the following authors:
 * Oliver Eglseder <oliver.eglseder@in2code.de>
 *
 * All rights reserved
 *
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 */

class FlexProcessor extends AbstractProcessor
{
    protected $canHoldRelations = true;

    public const DS = 'ds';
    public const DS_POINTER_FIELD = 'ds_pointerField';
    public const DS_POINTER_FIELD_SEARCH_PARENT = 'ds_pointerField_searchParent';
    public const DS_POINTER_FIELD_SEARCH_PARENT_SUB_FIELD = 'ds_pointerField_searchParent_subField';
    public const SEARCH = 'search';
    public const MISSING_POINTER_FIELD = 'can not resolve flexform values without "ds_pointerField" or default value';
    public const DEFAULT_VALUE = 'default';

    protected $forbidden = [
        'ds_pointerField_searchParent is not supported' => self::DS_POINTER_FIELD_SEARCH_PARENT,
        'ds_pointerField_searchParent_subField is not supported' => self::DS_POINTER_FIELD_SEARCH_PARENT_SUB_FIELD,
    ];

    protected $required = [
        'can not resolve flexform values without "ds"' => self::DS,
    ];

    protected $allowed = [
        self::SEARCH,
        self::DS_POINTER_FIELD,
    ];

    public function canPreProcess(array $config): bool
    {
        if (
            !array_key_exists(static::DS_POINTER_FIELD, $config)
            && parent::canPreProcess($config)
            && empty($config[static::DS][static::DEFAULT_VALUE])
        ) {
            $this->lastReasons[static::DS_POINTER_FIELD] = self::MISSING_POINTER_FIELD;
        }

        return empty($this->lastReasons);
    }
}
