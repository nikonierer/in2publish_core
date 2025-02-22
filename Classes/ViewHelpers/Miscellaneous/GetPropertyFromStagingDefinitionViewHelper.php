<?php

declare(strict_types=1);

namespace In2code\In2publishCore\ViewHelpers\Miscellaneous;

/*
 * Copyright notice
 *
 * (c) 2015 in2code.de and the following authors:
 * Alex Kellner <alexander.kellner@in2code.de>,
 * Oliver Eglseder <oliver.eglseder@in2code.de>
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

use In2code\In2publishCore\Domain\Model\Record;
use In2code\In2publishCore\Domain\Model\RecordInterface;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

use function ucfirst;

class GetPropertyFromStagingDefinitionViewHelper extends AbstractViewHelper
{
    protected const EMPTY_FIELD_VALUE = '---';

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('record', RecordInterface::class, 'The record with the desired property value', true);
        $this->registerArgument('propertyName', 'string', 'The name of the desired property', true);
        $this->registerArgument('stagingLevel', 'string', 'Fetch the local or the foreign property', false, 'local');
        $this->registerArgument('fallbackProperty', 'string', 'Fetch this if the primary prop is empty', false);
    }

    public function render(): string
    {
        $record = $this->arguments['record'];
        $propertyName = $this->arguments['propertyName'];
        $stagingLevel = $this->arguments['stagingLevel'];
        $fallbackProperty = $this->arguments['fallbackProperty'];

        return $this->getProperty($record, $propertyName, $stagingLevel, $fallbackProperty);
    }

    protected function getProperty(
        Record $record,
        string $propertyName,
        string $stagingLevel,
        ?string $fallbackProperty
    ): string {
        $properties = ObjectAccess::getProperty($record, ucfirst($stagingLevel) . 'Properties');
        if (isset($properties[$propertyName])) {
            $value = $properties[$propertyName];
            if (empty($value) && !empty($fallbackProperty)) {
                $value = $this->getProperty($record, $fallbackProperty, $stagingLevel, null);
            }
        } else {
            $value = $this->fallbackRootPageTitle($record, $propertyName, $stagingLevel);
        }
        return (string)$value;
    }

    /**
     * Return labels if PID 0 and tableName="pages"
     *
     * @param Record $record
     * @param string $propertyName
     * @param string $stagingLevel
     *
     * @return string
     */
    protected function fallbackRootPageTitle(
        Record $record,
        string $propertyName,
        string $stagingLevel = 'local'
    ): string {
        if (
            'title' === $propertyName
            && 'pages' === $record->getTableName()
            && 0 === $record->getIdentifier()
        ) {
            if ($stagingLevel === 'local') {
                return $this->getSiteName();
            }

            return LocalizationUtility::translate('label_production', 'in2publish_core');
        }
        return static::EMPTY_FIELD_VALUE;
    }

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    protected function getSiteName(): string
    {
        return $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'];
    }
}
