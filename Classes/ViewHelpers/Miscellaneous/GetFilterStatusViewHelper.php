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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class GetFilterStatusViewHelper extends AbstractViewHelper
{
    /** @var BackendUserAuthentication */
    protected $backendUser;

    /** @SuppressWarnings(PHPMD.Superglobals) */
    public function __construct()
    {
        $this->backendUser = $GLOBALS['BE_USER'];
    }

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('filter', 'string', 'The filter name', true);
        $this->registerArgument('key', 'string', 'The filter type', false, 'records');
    }

    public function render(): bool
    {
        $key = $this->arguments['key'];
        $filter = $this->arguments['filter'];
        return $this->backendUser->getSessionData('in2publish_filter_' . $key . '_' . $filter) === true;
    }
}
