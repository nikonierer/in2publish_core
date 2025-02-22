<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Controller;

/*
 * Copyright notice
 *
 * (c) 2015 in2code.de and the following authors:
 * Alex Kellner <alexander.kellner@in2code.de>,
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

use In2code\In2publishCore\Communication\RemoteCommandExecution\RemoteCommandDispatcher;
use In2code\In2publishCore\Config\ConfigContainer;
use In2code\In2publishCore\Domain\Repository\CommonRepository;
use In2code\In2publishCore\Domain\Service\ExecutionTimeService;
use In2code\In2publishCore\Domain\Service\TcaProcessingService;
use In2code\In2publishCore\Event\RecordWasCreatedForDetailAction;
use In2code\In2publishCore\Event\RecordWasSelectedForPublishing;
use In2code\In2publishCore\Features\SimpleOverviewAndAjax\Domain\Factory\FakeRecordFactory;
use In2code\In2publishCore\In2publishCoreException;
use In2code\In2publishCore\Service\Environment\EnvironmentService;
use In2code\In2publishCore\Service\Error\FailureCollector;
use In2code\In2publishCore\Service\Permission\PermissionService;
use In2code\In2publishCore\Utility\LogUtility;
use Throwable;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

use function array_keys;
use function array_merge;
use function implode;
use function rawurldecode;
use function strpos;

/**
 * Content publishing Controller. Any action is for the "Publish Records" Backend module "m1"
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RecordController extends AbstractController
{
    /** @var CommonRepository */
    protected $commonRepository;

    /** @var FailureCollector */
    protected $failureCollector;

    /** @var FakeRecordFactory */
    protected $fakeRecordFactory;

    /** @var PermissionService */
    protected $permissionService;

    public function __construct(
        ConfigContainer $configContainer,
        ExecutionTimeService $executionTimeService,
        EnvironmentService $environmentService,
        RemoteCommandDispatcher $remoteCommandDispatcher,
        FailureCollector $failureCollector,
        FakeRecordFactory $fakeRecordFactory,
        PermissionService $permissionService
    ) {
        parent::__construct(
            $configContainer,
            $executionTimeService,
            $environmentService,
            $remoteCommandDispatcher
        );
        $this->failureCollector = $failureCollector;
        $this->fakeRecordFactory = $fakeRecordFactory;
        $this->permissionService = $permissionService;
    }

    public function initializeAction(): void
    {
        parent::initializeAction();
        if (static::BLANK_ACTION !== $this->actionMethodName) {
            $this->commonRepository = GeneralUtility::makeInstance(CommonRepository::class);
        }
    }

    /**
     * Create a Record instance of the current selected page
     * If none is chosen, a Record with uid = 0 is created which
     * represents the instance root
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function indexAction(): void
    {
        $this->logger->debug('Called indexAction');
        GeneralUtility::makeInstance(TcaProcessingService::class);
        if (!$this->configContainer->get('factory.simpleOverviewAndAjax')) {
            $record = $this->commonRepository->findByIdentifier($this->pid, 'pages');
        } else {
            $record = $this->fakeRecordFactory->buildFromStartPage($this->pid);
        }
        $failures = $this->failureCollector->getFailures();

        if (!empty($failures)) {
            $message = '"' . implode('"; "', array_keys($failures)) . '"';
            $title = LocalizationUtility::translate('relation_resolving_errors', 'in2publish_core');
            $mostCriticalLogLevel = $this->failureCollector->getMostCriticalLogLevel();
            $severity = LogUtility::translateLogLevelToSeverity($mostCriticalLogLevel);
            $this->addFlashMessage($message, $title, $severity);
        }
        $this->view->assign('record', $record);
    }

    /**
     * Show record details (difference view) to a page
     * Normally called via AJAX
     *
     * @param int $identifier record identifier
     * @param string $tableName
     *
     * @return void
     */
    public function detailAction(int $identifier, string $tableName): void
    {
        $this->logger->debug('Called detailAction');
        $this->commonRepository->disablePageRecursion();
        $record = $this->commonRepository->findByIdentifier($identifier, $tableName);

        $this->eventDispatcher->dispatch(new RecordWasCreatedForDetailAction($this, $record));

        $this->view->assign('record', $record);
    }

    /**
     * Check if user is allowed to publish
     *
     * @throws In2publishCoreException
     */
    public function initializePublishRecordAction(): void
    {
        if (!$this->permissionService->isUserAllowedToPublish()) {
            throw new In2publishCoreException('You are not allowed to publish', 1435306780);
        }
    }

    /**
     * Publish the selected page record with all related content records
     *
     * @param int $identifier
     * @param string|null $returnUrl
     *
     * @throws StopActionException
     */
    public function publishRecordAction(int $identifier, string $returnUrl = null): void
    {
        $this->logger->info('publishing record in ' . $this->request->getPluginName(), ['identifier' => $identifier]);
        $this->publishRecord($identifier, ['pages']);
        if ($returnUrl !== null) {
            while (strpos($returnUrl, '/') === false || strpos($returnUrl, 'typo3') === false) {
                $returnUrl = rawurldecode($returnUrl);
            }
            $this->redirectToUri($returnUrl);
        }
        $this->addFlashMessagesAndRedirectToIndex();
    }

    /**
     * toggle filter status and save the filter status
     * in the current backendUser's session.
     *
     * @param string $filter "changed", "added", "deleted"
     *
     * @throws StopActionException
     */
    public function toggleFilterStatusAndRedirectToIndexAction(string $filter): void
    {
        $this->toggleFilterStatusAndRedirect('in2publish_filter_records_', $filter, 'index');
    }

    protected function publishRecord(int $identifier, array $exceptTableNames = []): void
    {
        $record = $this->commonRepository->findByIdentifier($identifier, 'pages');

        $this->eventDispatcher->dispatch(new RecordWasSelectedForPublishing($record, $this));

        try {
            $this->commonRepository->publishRecordRecursive(
                $record,
                array_merge($this->configContainer->get('excludeRelatedTables'), $exceptTableNames)
            );
        } catch (Throwable $exception) {
            $this->logger->error('Error while publishing', ['exception' => $exception]);
            $this->addFlashMessage($exception->getMessage(), AbstractMessage::ERROR);
        }
        $this->runTasks();
    }

    /**
     * Add success message and redirect to indexAction
     *
     * @throws StopActionException
     */
    protected function addFlashMessagesAndRedirectToIndex(): void
    {
        $failures = $this->failureCollector->getFailures();

        if (empty($failures)) {
            $message = '';
            $title = LocalizationUtility::translate('record_published', 'in2publish_core');
            $severity = AbstractMessage::OK;
        } else {
            $message = '"' . implode('"; "', array_keys($failures)) . '"';
            $title = LocalizationUtility::translate('record_publishing_failure', 'in2publish_core');
            $mostCriticalLogLevel = $this->failureCollector->getMostCriticalLogLevel();
            $severity = LogUtility::translateLogLevelToSeverity($mostCriticalLogLevel);
        }
        $this->addFlashMessage($message, $title, $severity);

        $this->redirect('index', 'Record');
    }
}
