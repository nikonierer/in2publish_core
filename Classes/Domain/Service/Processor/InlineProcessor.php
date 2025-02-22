<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Domain\Service\Processor;

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

class InlineProcessor extends AbstractProcessor
{
    public const FOREIGN_FIELD = 'foreign_field';
    public const FOREIGN_MATCH_FIELDS = 'foreign_match_fields';
    public const FOREIGN_TABLE_FIELD = 'foreign_table_field';
    public const SYMMETRIC_FIELD = 'symmetric_field';

    protected $canHoldRelations = true;

    protected $forbidden = [
        'symmetric_field is set on the foreign side of relations, which must not be resolved' => self::SYMMETRIC_FIELD,
    ];

    protected $required = [
        'Must be set, there is no type "inline" without a foreign table' => self::FOREIGN_TABLE,
    ];

    protected $allowed = [
        self::FOREIGN_FIELD,
        self::FOREIGN_MATCH_FIELDS,
        self::FOREIGN_TABLE_FIELD,
        self::MM,
    ];
}
