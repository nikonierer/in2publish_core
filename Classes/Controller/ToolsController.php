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

use In2code\In2publishCore\Communication\RemoteProcedureCall\Letterbox;
use In2code\In2publishCore\Config\ConfigContainer;
use In2code\In2publishCore\Config\PostProcessor\DynamicValueProvider\DynamicValueProviderRegistry;
use In2code\In2publishCore\Domain\Service\ExecutionTimeService;
use In2code\In2publishCore\Domain\Service\ForeignSiteFinder;
use In2code\In2publishCore\Domain\Service\TcaProcessingService;
use In2code\In2publishCore\Event\CreatedDefaultHelpLabels;
use In2code\In2publishCore\In2publishCoreException;
use In2code\In2publishCore\Service\Environment\EnvironmentService;
use In2code\In2publishCore\Testing\Service\TestingService;
use In2code\In2publishCore\Testing\Tests\TestResult;
use In2code\In2publishCore\Tools\ToolsRegistry;
use In2code\In2publishCore\Utility\DatabaseUtility;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Extensionmanager\Utility\ListUtility;

use function array_keys;
use function array_merge;
use function class_exists;
use function defined;
use function file_get_contents;
use function flush;
use function gmdate;
use function header;
use function implode;
use function is_array;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function ob_clean;
use function ob_end_clean;
use function ob_get_level;
use function php_uname;
use function sprintf;
use function strftime;
use function strlen;
use function substr;
use function time;

use const PHP_EOL;
use const PHP_OS;
use const PHP_VERSION;
use const TYPO3_COMPOSER_MODE;

/**
 * The ToolsController is the controller of the Backend Module "Publish Tools" "m3"
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ToolsController extends ActionController
{
    public const LOG_INIT_DB_ERROR = 'Error while initialization. The Database is not correctly configured';

    /** @var TestingService */
    protected $testingService;

    /** @var Letterbox */
    protected $letterbox;

    /** @var Registry */
    protected $registry;

    /** @var ExtensionConfiguration */
    protected $extensionConfiguration;

    /** @var ConnectionPool */
    protected $connectionPool;

    /** @var DynamicValueProviderRegistry */
    protected $dynamicValueProviderRegistry;

    /** @var SiteFinder */
    protected $siteFinder;

    /** @var ForeignSiteFinder */
    protected $foreignSiteFinder;

    /** @var ToolsRegistry */
    protected $toolsRegistry;

    protected $tests = [];

    public function __construct(
        ConfigContainer $configContainer,
        ExecutionTimeService $executionTimeService,
        EnvironmentService $environmentService,
        TestingService $testingService,
        Letterbox $letterbox,
        Registry $registry,
        ExtensionConfiguration $extensionConfiguration,
        ConnectionPool $connectionPool,
        DynamicValueProviderRegistry $dynamicValueProviderRegistry,
        SiteFinder $siteFinder,
        ForeignSiteFinder $foreignSiteFinder,
        ToolsRegistry $toolsRegistry
    ) {
        parent::__construct($configContainer, $executionTimeService, $environmentService);
        $this->testingService = $testingService;
        $this->letterbox = $letterbox;
        $this->registry = $registry;
        $this->extensionConfiguration = $extensionConfiguration;
        $this->connectionPool = $connectionPool;
        $this->dynamicValueProviderRegistry = $dynamicValueProviderRegistry;
        $this->siteFinder = $siteFinder;
        $this->foreignSiteFinder = $foreignSiteFinder;
        $this->toolsRegistry = $toolsRegistry;
    }

    protected function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);
        try {
            $this->view->assign('canFlushEnvelopes', $this->letterbox->hasUnAnsweredEnvelopes());
        } catch (Throwable $throwable) {
            $this->logger->error(self::LOG_INIT_DB_ERROR, ['exception' => $throwable]);
        }
    }

    public function indexAction(): void
    {
        $testStates = $this->environmentService->getTestStatus();

        $messages = [];
        foreach ($testStates as $testState) {
            $messages[] = LocalizationUtility::translate('test_state_error.' . $testState, 'in2publish_core');
        }
        if (!empty($messages)) {
            $this->addFlashMessage(
                implode(PHP_EOL, $messages),
                LocalizationUtility::translate('test_state_error', 'in2publish_core'),
                AbstractMessage::ERROR
            );
        }

        $supports = [
            LocalizationUtility::translate('help.github_issues', 'in2publish_core'),
            LocalizationUtility::translate('help.slack_channel', 'in2publish_core'),
        ];

        $event = new CreatedDefaultHelpLabels($supports);
        $this->eventDispatcher->dispatch($event);
        $supports = $event->getSupports();

        $this->view->assign('supports', $supports);

        $this->view->assign('tools', $this->toolsRegistry->getTools());
    }

    /** @throws In2publishCoreException */
    public function testAction(): void
    {
        $testingResults = $this->testingService->runAllTests();

        $success = true;

        foreach ($testingResults as $testingResult) {
            if ($testingResult->getSeverity() === TestResult::ERROR) {
                $success = false;
                break;
            }
        }

        $this->environmentService->setTestResult($success);

        $this->view->assign('testingResults', $testingResults);
    }

    public function configurationAction(int $emulatePage = null): void
    {
        if (null !== $emulatePage) {
            $_POST['id'] = $emulatePage;
        }
        $this->view->assign('containerDump', $this->configContainer->dump());
        $this->view->assign('globalConfig', $this->configContainer->getContextFreeConfig());
        $this->view->assign('emulatePage', $emulatePage);
    }

    public function tcaAction(): void
    {
        $this->view->assign('incompatibleTca', TcaProcessingService::getIncompatibleTca());
        $this->view->assign('compatibleTca', TcaProcessingService::getCompatibleTca());
        $this->view->assign('controls', TcaProcessingService::getControls());
    }

    /** @throws StopActionException */
    public function clearTcaCachesAction(): void
    {
        GeneralUtility::makeInstance(TcaProcessingService::class)->flushCaches();
        $this->redirect('index');
    }

    /** @throws StopActionException */
    public function flushRegistryAction(): void
    {
        $this->registry->removeAllByNamespace('tx_in2publishcore');
        $this->addFlashMessage(LocalizationUtility::translate('module.m4.registry_flushed', 'in2publish_core'));
        $this->redirect('index');
    }

    /** @throws StopActionException */
    public function flushEnvelopesAction(): void
    {
        $this->letterbox->removeAnsweredEnvelopes();
        $this->addFlashMessage(
            LocalizationUtility::translate(
                'module.m4.superfluous_envelopes_flushed',
                'in2publish_core'
            )
        );
        $this->redirect('index');
    }

    public function sysInfoIndexAction(): void
    {
    }

    /** @throws Throwable */
    public function sysInfoShowAction(): void
    {
        $info = $this->getFullInfo();
        $this->view->assign('info', $info);
        $this->view->assign('infoJson', json_encode($info));
    }

    public function sysInfoDecodeAction(string $json = ''): void
    {
        if (!empty($json)) {
            $info = json_decode($json, true);
            if (is_array($info)) {
                $this->view->assign('info', $info);
            } else {
                $args = [json_last_error(), json_last_error_msg()];
                $this->addFlashMessage(
                    LocalizationUtility::translate('system_info.decode.json_error.details', 'in2publish_core', $args),
                    LocalizationUtility::translate('system_info.decode.json_error', 'in2publish_core'),
                    AbstractMessage::ERROR
                );
            }
        }
        $this->view->assign('infoJson', $json);
    }

    /** @throws Throwable */
    public function sysInfoDownloadAction(): void
    {
        $info = $this->getFullInfo();
        $json = json_encode($info);

        $downloadName = 'cp_sysinfo_' . time() . '.json';
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Type: text/json');
        header('Content-Length: ' . strlen($json));
        header("Cache-Control: ''");
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT', true, 200);
        ob_clean();
        flush();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo $json;
        die;
    }

    /** @throws StopActionException */
    public function sysInfoUploadAction(): void
    {
        try {
            /** @var array $file */
            $file = $this->request->getArgument('jsonFile');
        } catch (NoSuchArgumentException $e) {
            return;
        }
        $content = file_get_contents($file['tmp_name']);
        $this->forward('sysInfoDecode', null, null, ['json' => $content]);
    }

    /** @throws Throwable */
    protected function getFullInfo(): array
    {
        $listUtility = $this->objectManager->get(ListUtility::class);
        $packages = $listUtility->getAvailableAndInstalledExtensionsWithAdditionalInformation();
        $extensions = [];
        foreach ($packages as $package) {
            $extensions[$package['key']] = [
                'title' => $package['title'],
                'state' => $package['state'],
                'version' => $package['version'],
                'installed' => $package['installed'],
            ];
        }

        $return = [];
        $testingResults = $this->testingService->runAllTests();
        foreach ($testingResults as $testClass => $testingResult) {
            $severityString = '[' . $testingResult->getSeverityLabel() . '] ';
            $message = '[' . $testingResult->getTranslatedLabel() . '] ' . $testingResult->getTranslatedMessages();

            $return[$testingResult->getSeverity()][$severityString . $testClass] = $message;
        }

        $tests = [];
        foreach ([TestResult::ERROR, TestResult::WARNING, TestResult::SKIPPED, TestResult::OK] as $severity) {
            if (isset($return[$severity])) {
                $tests = array_merge($tests, $return[$severity]);
            }
        }

        $full = $this->configContainer->getContextFreeConfig();
        $pers = $this->configContainer->get();

        $containerDump = $this->configContainer->dump();
        unset($containerDump['fullConfig']);

        $protectedValues = [
            'foreign.database.password',
            'sshConnection.privateKeyPassphrase',
        ];
        foreach ($protectedValues as $protectedValue) {
            foreach ([&$full, &$pers] as &$cfgArray) {
                try {
                    $value = ArrayUtility::getValueByPath($cfgArray, $protectedValue, '.');
                    if (!empty($value)) {
                        $value = 'xxxxxxxx (masked)';
                        $cfgArray = ArrayUtility::setValueByPath($cfgArray, $protectedValue, $value, '.');
                    }
                } catch (Throwable $e) {
                }
            }
            unset($cfgArray);

            foreach ($containerDump['providers'] as &$providerCfg) {
                try {
                    $value = ArrayUtility::getValueByPath($providerCfg, $protectedValue, '.');
                    if (!empty($value)) {
                        $value = 'xxxxxxxx (masked)';
                        $providerCfg = ArrayUtility::setValueByPath($providerCfg, $protectedValue, $value, '.');
                    }
                } catch (Throwable $e) {
                }
            }
            unset($providerCfg);
        }

        $extConf = [];
        foreach (array_keys($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']) as $extKey) {
            try {
                $extConf[$extKey] = $this->extensionConfiguration->get($extKey);
            } catch (Throwable $e) {
                $extConf[$extKey] = 'Exception: ' . $e->getMessage();
            }
        }

        $databases = [];
        foreach ($this->connectionPool->getConnectionNames() as $name) {
            $databases[$name] = $this->connectionPool->getConnectionByName($name)->getServerVersion();
        }

        $composerMode = class_exists(Environment::class)
            ? Environment::isComposerMode()
            : defined('TYPO3_COMPOSER_MODE') && true === TYPO3_COMPOSER_MODE;

        $logQueryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_in2publishcore_log');
        $logs = $logQueryBuilder->select('*')
                                ->from('tx_in2publishcore_log')
                                ->where($logQueryBuilder->expr()->lte('level', 4))
                                ->setMaxResults(500)
                                ->orderBy('uid', 'DESC')
                                ->execute()
                                ->fetchAllAssociative();

        $logsFormatted = [];
        foreach ($logs as $log) {
            $message = sprintf(
                '[%s] [lvl:%d] @%s "%s"',
                $log['component'],
                $log['level'],
                strftime('%F %T', (int)$log['time_micro']),
                $log['message']
            );
            $logData = $log['data'];
            $logDataJson = substr($logData, 2);
            $logsFormatted[$message] = json_decode($logDataJson, true);
        }

        $schema = [];
        foreach (['local', 'foreign'] as $side) {
            $schemaManager = DatabaseUtility::buildDatabaseConnectionForSide($side)->getSchemaManager();
            foreach ($schemaManager->listTables() as $table) {
                $schema[$side][$table->getName()]['options'] = $table->getOptions();
                foreach ($table->getColumns() as $column) {
                    $schema[$side][$table->getName()]['columns'][$column->getName()] = $column->toArray();
                }
                foreach ($table->getIndexes() as $index) {
                    $schema[$side][$table->getName()]['indexes'][$index->getName()] = [
                        'columns' => $index->getColumns(),
                        'isPrimary' => $index->isPrimary(),
                        'isSimple' => $index->isSimpleIndex(),
                        'isUnique' => $index->isUnique(),
                        'isQuoted' => $index->isQuoted(),
                        'options' => $index->getOptions(),
                        'flags' => $index->getFlags(),
                    ];
                }
                foreach ($table->getForeignKeys() as $foreignKey) {
                    $schema[$side][$table->getName()]['fk'][$foreignKey->getName()] = [
                        'isQuoted' => $foreignKey->isQuoted(),
                        'options' => $foreignKey->getOptions(),
                    ];
                }
            }
        }

        $dynamicProvider = $this->dynamicValueProviderRegistry->getRegisteredClasses();

        $siteConfigs = [];

        $localSites = $this->siteFinder->getAllSites(false);
        $foreignSites = $this->foreignSiteFinder->getAllSites();
        /**
         * @var string $side
         * @var Site $site
         */
        foreach (['local' => $localSites, 'foreign' => $foreignSites] as $side => $sites) {
            foreach ($sites as $site) {
                $langs = [];
                $rootPageId = $site->getRootPageId();
                foreach ($site->getAllLanguages() as $language) {
                    $languageId = $language->getLanguageId();
                    try {
                        $uri = $site->getRouter()->generateUri($rootPageId, ['_language' => $languageId])->__toString();
                    } catch (Throwable $throwable) {
                        $uri = (string)$throwable;
                    }
                    $langs[] = [
                        'base' => $language->getBase()->__toString(),
                        'actualURI' => $uri,
                        'langId' => $languageId,
                        'typo3Lang' => $language->getTypo3Language(),
                        'isocode' => $language->getTwoLetterIsoCode(),
                    ];
                }
                try {
                    $uri = $site->getRouter()->generateUri($rootPageId)->__toString();
                } catch (Throwable $throwable) {
                    $uri = (string)$throwable;
                }
                $siteConfigs[$side][$site->getIdentifier()] = [
                    'rootPageId' => $rootPageId,
                    'base' => $site->getBase()->__toString(),
                    'actualURI' => $uri,
                    'langs' => $langs,
                ];
            }
        }

        return [
            'TYPO3 Version' => VersionNumberUtility::getCurrentTypo3Version(),
            'PHP Version' => PHP_VERSION,
            'Database Version' => $databases,
            'Application Context' => Environment::getContext()->__toString(),
            'Composer mode' => $composerMode,
            'Operating System' => PHP_OS . ' ' . php_uname('r'),
            'extensions' => $extensions,
            'extConf' => $extConf,
            'tests' => $tests,
            'config' => $full,
            'containerDump' => $containerDump,
            'dynamicProvider' => $dynamicProvider,
            '$_SERVER ' => $_SERVER,
            'compatible TCA' => TcaProcessingService::getCompatibleTca(),
            'incompatible TCA' => TcaProcessingService::getIncompatibleTca(),
            'logs' => $logsFormatted,
            'personal config' => $pers,
            'TCA' => $GLOBALS['TCA'],
            'schema' => $schema,
            'sites' => $siteConfigs,
        ];
    }
}
