<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Domain\Repository;

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

use Exception;
use In2code\In2publishCore\Config\ConfigContainer;
use In2code\In2publishCore\Domain\Factory\RecordFactory;
use In2code\In2publishCore\Domain\Model\NullRecord;
use In2code\In2publishCore\Domain\Model\RecordInterface;
use In2code\In2publishCore\Domain\Repository\Exception\MissingArgumentException;
use In2code\In2publishCore\Domain\Service\ReplaceMarkersService;
use In2code\In2publishCore\Event\CommonRepositoryWasInstantiated;
use In2code\In2publishCore\Event\PublishingOfOneRecordBegan;
use In2code\In2publishCore\Event\PublishingOfOneRecordEnded;
use In2code\In2publishCore\Event\RecordWasEnriched;
use In2code\In2publishCore\Event\RecursiveRecordPublishingBegan;
use In2code\In2publishCore\Event\RecursiveRecordPublishingEnded;
use In2code\In2publishCore\Event\RelatedRecordsByRteWereFetched;
use In2code\In2publishCore\Event\VoteIfFindingByIdentifierShouldBeSkipped;
use In2code\In2publishCore\Event\VoteIfFindingByPropertyShouldBeSkipped;
use In2code\In2publishCore\Event\VoteIfPageRecordEnrichingShouldBeSkipped;
use In2code\In2publishCore\Event\VoteIfRecordShouldBeIgnored;
use In2code\In2publishCore\Event\VoteIfRecordShouldBeSkipped;
use In2code\In2publishCore\Event\VoteIfSearchingForRelatedRecordsByFlexFormPropertyShouldBeSkipped;
use In2code\In2publishCore\Event\VoteIfSearchingForRelatedRecordsByFlexFormShouldBeSkipped;
use In2code\In2publishCore\Event\VoteIfSearchingForRelatedRecordsByPropertyShouldBeSkipped;
use In2code\In2publishCore\Event\VoteIfSearchingForRelatedRecordsByTableShouldBeSkipped;
use In2code\In2publishCore\Event\VoteIfSearchingForRelatedRecordsShouldBeSkipped;
use In2code\In2publishCore\Service\Configuration\TcaService;
use In2code\In2publishCore\Utility\DatabaseUtility;
use In2code\In2publishCore\Utility\FileUtility;
use Throwable;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidIdentifierException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidParentRowException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidParentRowLoopException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidParentRowRootException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidPointerFieldValueException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidSinglePointerFieldException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidTcaException;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_shift;
use function array_unique;
use function count;
use function explode;
use function gettype;
use function htmlspecialchars_decode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function key;
use function parse_str;
use function parse_url;
use function preg_match_all;
use function reset;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * CommonRepository - actions in foreign and local database
 *
 * This is, beside the Record Model, the second important class in this Extension
 *
 * What does this Repository
 *  - find Records by identifier
 *  - find Records by properties
 *  - find related Records to a Record by its TCA definition
 *
 * Important notice:
 *  This Repository does not simple fetch a local or a foreign Record,
 *  it fetches always both. Hence the Resulting Record object contains
 *  properties from both databases "local" and "foreign"
 *
 *  Any Record created by this Repository will be enriched with related Records
 *  if they are existing. This is achieved by recursion. The recursion is hidden
 *  between this Repository and the RecordFactory:
 *
 *    repository->findByIdentifier()
 *    '- factory->makeInstance()
 *       '- repository->enrichRecordWithRelatedRecords()
 *          '- repository->convertPropertyArraysToRecords()
 *             '- factory->makeInstance()
 *                '- continue as long as depth < maxDepth
 *
 *  this loop breaks in the factory when maximumRecursionDepth is reached
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CommonRepository extends BaseRepository
{
    public const REGEX_T3URN = '~(?P<URN>t3\://(?:file|page)\?uid=\d+)~';
    /**
     * @deprecated Listen to the new event RelatedRecordsByRteWereFetched instead.
     *  The signal and this constant will be removed in in2publish_core version 11.
     */
    public const SIGNAL_RELATION_RESOLVER_RTE = 'relationResolverRTE';

    /** @var Connection */
    protected $localDatabase;

    /** @var Connection */
    protected $foreignDatabase;

    /** @var RecordFactory */
    protected $recordFactory;

    /** @var ResourceFactory */
    protected $resourceFactory;

    /** @var TaskRepository */
    protected $taskRepository;

    /** @var ConfigContainer */
    protected $configContainer;

    /** @var EventDispatcher */
    protected $eventDispatcher;

    /** @var ReplaceMarkersService */
    protected $replaceMarkersService;

    /** @var FlexFormTools */
    protected $flexFormTools;

    /** @var FlexFormService */
    private $flexFormService;

    /** @var array */
    protected $visitedRecords = [];

    public function __construct(
        TcaService $tcaService,
        ?Connection $localDatabase,
        ?Connection $foreignDatabase,
        RecordFactory $recordFactory,
        ResourceFactory $resourceFactory,
        TaskRepository $taskRepository,
        ConfigContainer $configContainer,
        EventDispatcher $eventDispatcher,
        ReplaceMarkersService $replaceMarkersService,
        FlexFormTools $flexFormTools,
        FlexFormService $flexFormService
    ) {
        parent::__construct($tcaService);
        $this->localDatabase = $localDatabase;
        $this->foreignDatabase = $foreignDatabase;
        if ($foreignDatabase === null || !$foreignDatabase->isConnected()) {
            $this->foreignDatabase = $localDatabase;
        }
        $this->recordFactory = $recordFactory;
        $this->resourceFactory = $resourceFactory;
        $this->taskRepository = $taskRepository;
        $this->configContainer = $configContainer;
        $this->eventDispatcher = $eventDispatcher;
        $this->eventDispatcher->dispatch(new CommonRepositoryWasInstantiated($this));
        $this->replaceMarkersService = $replaceMarkersService;
        $this->flexFormTools = $flexFormTools;
        $this->flexFormService = $flexFormService;
    }

    /**
     * Find and create a Record where the Records identifier equals $identifier
     * Returns exactly one Record.
     *
     * @param int $identifier
     * @param string $tableName
     * @param string $idFieldName
     *
     * @return RecordInterface|null
     */
    public function findByIdentifier(int $identifier, string $tableName, string $idFieldName = 'uid'): ?RecordInterface
    {
        if ($this->shouldSkipFindByIdentifier($identifier, $tableName)) {
            return GeneralUtility::makeInstance(NullRecord::class, $tableName);
        }
        $record = $this->recordFactory->getCachedRecord($tableName, $identifier);
        if (null !== $record) {
            return $record;
        }
        $recordRepository = GeneralUtility::makeInstance(CombinedRecordRepository::class);
        $identifiers = GeneralUtility::makeInstance(Identifiers::class, [$idFieldName => $identifier]);
        $combinedRecords = $recordRepository->findByIdentifiers($identifiers, $tableName);
        if (empty($combinedRecords)) {
            return GeneralUtility::makeInstance(NullRecord::class, $tableName);
        }
        return $combinedRecords[0]->toLegacyRecord();
    }

    /**
     * Finds and creates none or more Records in the current table name
     * where the propertyName (e.g. pid or tstamp) matches the given value.
     *
     * @param string $propertyName
     * @param mixed $propertyValue
     * @param string $tableName
     *
     * @return RecordInterface[]
     */
    public function findByProperty(string $propertyName, $propertyValue, string $tableName): array
    {
        if ($this->shouldSkipFindByProperty($propertyName, $propertyValue, $tableName)) {
            return [];
        }
        if (
            $propertyName === 'uid'
            && $record = $this->recordFactory->getCachedRecord($tableName, $propertyValue)
        ) {
            return [$record];
        }
        $localProperties = $this->findPropertiesByProperty(
            $this->localDatabase,
            $propertyName,
            $propertyValue,
            '',
            '',
            '',
            '',
            'uid',
            $tableName
        );
        $foreignProperties = $this->findPropertiesByProperty(
            $this->foreignDatabase,
            $propertyName,
            $propertyValue,
            '',
            '',
            '',
            '',
            'uid',
            $tableName
        );
        return $this->convertPropertyArraysToRecords($localProperties, $foreignProperties, $tableName);
    }

    /**
     * Finds and creates none or more Records in the current table name
     * where the properties are matching.
     *
     * @param array $properties
     * @param bool $simulateRoot Simulate an existent root record to prevent filePostProcessing
     *  in the RecordFactory for each single Record
     * @param string|null $tableName
     *
     * @return RecordInterface[]
     */
    public function findByProperties(array $properties, bool $simulateRoot = false, string $tableName = null): array
    {
        if (null === $tableName) {
            throw new MissingArgumentException('tableName');
        }
        if ($simulateRoot) {
            $this->recordFactory->simulateRootRecord();
        }
        foreach ($properties as $propertyName => $propertyValue) {
            if ($this->shouldSkipFindByProperty($propertyName, $propertyValue, $tableName)) {
                return [];
            }
        }
        if (
            isset($properties['uid'])
            && $record = $this->recordFactory->getCachedRecord($tableName, $properties['uid'])
        ) {
            return [$record];
        }
        $localProperties = $this->findPropertiesByProperties(
            $this->localDatabase,
            $properties,
            '',
            '',
            '',
            '',
            'uid',
            $tableName
        );
        $foreignProperties = $this->findPropertiesByProperties(
            $this->foreignDatabase,
            $properties,
            '',
            '',
            '',
            '',
            'uid',
            $tableName
        );
        $records = $this->convertPropertyArraysToRecords($localProperties, $foreignProperties, $tableName);
        if ($simulateRoot) {
            $this->recordFactory->endSimulation();
        }
        return $records;
    }

    /**
     * Find the last record by property and table name
     *
     * @param Connection $connection
     * @param string $tableName
     * @param string $propertyName
     * @param mixed $propertyValue
     *
     * @return array
     */
    public function findLastPropertiesByPropertyAndTableName(
        Connection $connection,
        string $tableName,
        string $propertyName,
        $propertyValue
    ): array {
        $properties = $this->findPropertiesByProperty(
            $connection,
            $propertyName,
            $propertyValue,
            '',
            '',
            'uid desc',
            '1',
            'uid',
            $tableName
        );
        $firstKey = key($properties);
        if ($firstKey !== null) {
            return $properties[$firstKey];
        }
        return [];
    }

    /**
     * converts arrays of propertyArrays to records.
     * the key of each propertyArrays must be an UID
     *
     * this method ignores following property arrays:
     *      - deleted on both sides and identical
     *      - is deleted local and non existing on foreign
     *
     * structure:
     *  $properties['uid'] = array(
     *              'property_A' => 'value_A',
     *              'property_B' => 'value_B'
     *  );
     *
     * @param array $localProperties
     * @param array $foreignProperties
     * @param string $tableName
     *
     * @return RecordInterface[]
     * @throws MissingArgumentException
     */
    protected function convertPropertyArraysToRecords(
        array $localProperties,
        array $foreignProperties,
        string $tableName
    ): array {
        $keysToIterate = array_unique(array_merge(array_keys($localProperties), array_keys($foreignProperties)));

        $foundRecords = [];

        foreach ($keysToIterate as $key) {
            if (strpos((string)$key, ',') === false) {
                if (empty($localProperties[$key])) {
                    $propertyArray = $this->findPropertiesByProperty(
                        $this->localDatabase,
                        'uid',
                        $key,
                        '',
                        '',
                        '',
                        '',
                        'uid',
                        $tableName
                    );
                    if (!empty($propertyArray[$key])) {
                        $localProperties[$key] = $propertyArray[$key];
                    }
                }
                if (empty($foreignProperties[$key])) {
                    $propertyArray = $this->findPropertiesByProperty(
                        $this->foreignDatabase,
                        'uid',
                        $key,
                        '',
                        '',
                        '',
                        '',
                        'uid',
                        $tableName
                    );
                    if (!empty($propertyArray[$key])) {
                        $foreignProperties[$key] = $propertyArray[$key];
                        if (
                            isset($localProperties[$key]['file'], $foreignProperties[$key]['file'])
                            && 'sys_file_metadata' === $tableName
                            && (int)$localProperties[$key]['file'] !== (int)$foreignProperties[$key]['file']
                        ) {
                            // If the fixing of this relation results in a different related
                            // record we log it because it is very very very unlikely for
                            // sys_file_metadata to change their target sys_file entry
                            $this->logger->warning(
                                'Identified a sys_file_metadata for a file which has a different UID on foreign.'
                                . ' The foreign sys_file_metadata will be overwritten and therefore be lost',
                                [
                                    'table' => $tableName,
                                    'key (UID of the local record and the found foreign record)' => $key,
                                    'file_local' => $localProperties[$key]['file'],
                                    'file_foreign' => $foreignProperties[$key]['file'],
                                ]
                            );
                        }
                    }
                }
            }
            if (!$this->isIgnoredRecord((array)$localProperties[$key], (array)$foreignProperties[$key], $tableName)) {
                $foundRecords[$key] = $this->recordFactory->makeInstance(
                    $this,
                    (array)$localProperties[$key],
                    (array)$foreignProperties[$key],
                    [],
                    $tableName
                );
            }
        }

        return array_filter($foundRecords);
    }

    /**
     * Adds all related Records to the given Record
     * until maximum recursion depth is reached
     * Any related Record must be connected by valid TCA of the given one
     * Relations are only solved for the given record as "owning side"
     *
     * Records which relate on the given Record are not included,
     * they will have $record as their related record (from the other side)
     *
     * @param RecordInterface $record
     * @param array $excludedTableNames
     *
     * @return RecordInterface
     */
    public function enrichRecordWithRelatedRecords(RecordInterface $record, array $excludedTableNames): RecordInterface
    {
        if ($this->shouldSkipSearchingForRelatedRecords($record)) {
            return $record;
        }
        $recordTableName = $record->getTableName();
        // keep the following extra line for debugging issues
        $columns = $record->getColumnsTca();
        foreach ($columns as $propertyName => $columnConfiguration) {
            if ($this->shouldSkipSearchingForRelatedRecordsByProperty($record, $propertyName, $columnConfiguration)) {
                continue;
            }
            switch ($columnConfiguration['type']) {
                case 'select':
                    $whereClause = '';
                    if (!empty($columnConfiguration['foreign_table_where'])) {
                        $whereClause = $columnConfiguration['foreign_table_where'];
                        if (false !== strpos($whereClause, '#')) {
                            $whereClause = QueryHelper::quoteDatabaseIdentifiers($this->localDatabase, $whereClause);
                            $whereClause = $this->replaceMarkersService->replaceMarkers(
                                $record,
                                $whereClause,
                                $propertyName
                            );
                        }
                    }
                    $relatedRecords = $this->fetchRelatedRecordsBySelect(
                        $columnConfiguration,
                        $record,
                        $propertyName,
                        $excludedTableNames,
                        $whereClause
                    );
                    break;
                case 'inline':
                    $relatedRecords = $this->fetchRelatedRecordsByInline(
                        $columnConfiguration,
                        $recordTableName,
                        $record,
                        $excludedTableNames,
                        $propertyName
                    );
                    break;
                case 'group':
                    $relatedRecords = $this->fetchRelatedRecordsByGroup(
                        $columnConfiguration,
                        $record,
                        $propertyName,
                        $excludedTableNames
                    );
                    break;
                case 'flex':
                    $relatedRecords = $this->fetchRelatedRecordsByFlexForm(
                        $record,
                        $propertyName,
                        $excludedTableNames,
                        $columnConfiguration
                    );
                    break;
                case 'input':
                    // fall through because fetch by RTE already supports "file:x" links
                case 'text':
                    // TODO: use some kind of merged property to check against changed foreign RTE relations
                    $relatedRecords = [];
                    $bodyText = trim((string)$record->getLocalProperty($propertyName));
                    if (!empty($bodyText)) {
                        $relatedRecords = $this->fetchRelatedRecordsByRte($bodyText, $excludedTableNames);
                    }
                    break;
                default:
                    $relatedRecords = [];
            }

            foreach ($relatedRecords as $index => $relatedRecord) {
                if (!($relatedRecord instanceof RecordInterface)) {
                    $this->logger->alert(
                        'Relation was resolved but result is not a record',
                        [
                            'tablename' => $record->getTableName(),
                            'uid' => $record->getIdentifier(),
                            'propertyName' => $propertyName,
                            'columnConfiguration' => $columnConfiguration,
                            'relatedRecordType' => gettype($relatedRecord),
                        ]
                    );
                    unset($relatedRecords[$index]);
                }
            }

            try {
                $record->addRelatedRecords($relatedRecords);
            } catch (Throwable $e) {
                $this->logger->emergency(
                    'Exception thrown while adding related record: ' . $e->getMessage(),
                    [
                        'code' => $e->getCode(),
                        'tablename' => $record->getTableName(),
                        'uid' => $record->getIdentifier(),
                        'propertyName' => $propertyName,
                        'columnConfiguration' => $columnConfiguration,
                        'relatedRecords' => $relatedRecords,
                    ]
                );
            }
        }

        $event = new RecordWasEnriched($record);
        $this->eventDispatcher->dispatch($event);
        return $event->getRecord();
    }

    /**
     * If RTE (rtehtmlarea) is enabled for a records text property,
     * the complete string will be searched for image urls. These urls are
     * converted into sys_file_processedfile which have a related sys_file record.
     *
     * @param string $bodyText
     * @param array $excludedTableNames
     *
     * @return array
     */
    protected function fetchRelatedRecordsByRte(string $bodyText, array $excludedTableNames): array
    {
        $relatedRecords = [];
        // if RTE is enabled
        if (strpos($bodyText, 'src=') !== false) {
            // match and src tag
            preg_match_all('~src="([^\s]*)"~', $bodyText, $matches);
            // remove the "matched" portion from results
            // we only need the "matching" portion
            if (!empty($matches[1])) {
                $matches = $matches[1];
            }
            if (
                count($matches) > 0
                && !in_array('sys_file_processedfile', $excludedTableNames)
            ) {
                foreach ($matches as $match) {
                    if (!empty($match)) {
                        // replace fileadmin if present. It has been replaced by the storage field (FAL)
                        if (strpos($match, 'fileadmin') === 0) {
                            $match = substr($match, 9);
                        }
                        $relatedProcFiles = $this->findByProperty('identifier', $match, 'sys_file_processedfile');
                        $relatedRecords = array_merge($relatedRecords, $relatedProcFiles);
                    }
                }
            }
        }
        if (strpos($bodyText, 'file:') !== false) {
            preg_match_all('~file:(\d+)~', $bodyText, $matches);
            if (!empty($matches[1])) {
                $matches = $matches[1];
            }
            $matches = array_filter($matches);
            if (
                count($matches) > 0
                && !in_array('sys_file', $excludedTableNames)
            ) {
                foreach ($matches as $match) {
                    $relatedRecords[] = $this->findByIdentifier($match, 'sys_file');
                }
            }
        }
        if (strpos($bodyText, 't3://') !== false) {
            preg_match_all(self::REGEX_T3URN, $bodyText, $matches);
            if (!empty($matches['URN'])) {
                foreach ($matches['URN'] as $urn) {
                    // Do NOT use LinkService because the URN might either be not local or not available
                    $urnParsed = parse_url($urn);
                    parse_str(htmlspecialchars_decode($urnParsed['query']), $data);
                    switch ($urnParsed['host']) {
                        case 'file':
                            if (isset($data['uid']) && !in_array('sys_file', $excludedTableNames)) {
                                $relatedRecords[] = $this->findByIdentifier($data['uid'], 'sys_file');
                            }
                            break;
                        case 'page':
                            if (isset($data['uid']) && !in_array('pages', $excludedTableNames)) {
                                $relatedRecords[] = $this->findByIdentifier($data['uid'], 'pages');
                            }
                            break;
                        default:
                            // do not handle any other relation type
                    }
                }
            }
        }
        $event = new RelatedRecordsByRteWereFetched($this, $bodyText, $excludedTableNames, $relatedRecords);
        $this->eventDispatcher->dispatch($event);
        $relatedRecords = $event->getRelatedRecords();

        // Filter probable null values (e.g. the page linked in the TYPO3 URN is the page currently in enrichment mode)
        return array_filter($relatedRecords);
    }

    /**
     * finds and adds related records to pages. this is a special case, because any
     * related Record is found by its pid
     *
     * @param RecordInterface $record
     * @param array $excludedTableNames
     *
     * @return RecordInterface
     */
    public function enrichPageRecord(RecordInterface $record, array $excludedTableNames): RecordInterface
    {
        if ($this->shouldSkipEnrichingPageRecord($record)) {
            return $record;
        }

        $recordIdentifier = $record->getIdentifier();
        $doktype = $record->getLocalProperty('doktype') ?? $record->getForeignProperty('doktpye');
        if (null !== $doktype) {
            $doktype = (int)$doktype;
        }
        $tablesAllowedOnPage = $this->tcaService->getTablesAllowedOnPage(
            $recordIdentifier,
            $doktype
        );
        $tablesToSearchIn = array_diff($tablesAllowedOnPage, $excludedTableNames);
        foreach ($tablesToSearchIn as $tableName) {
            // Never search for redirects by their pid!
            if ('sys_redirect' === $tableName) {
                continue;
            }
            if ($this->shouldSkipSearchingForRelatedRecordByTable($record, $tableName)) {
                continue;
            }
            $recordRepository = GeneralUtility::makeInstance(CombinedRecordRepository::class);
            $identifiers = GeneralUtility::makeInstance(Identifiers::class, ['pid' => $recordIdentifier]);
            $relatedRecords = [];
            $records = $recordRepository->findByIdentifiers($identifiers, $tableName);
            foreach ($records as $relatedRecord) {
                $relatedRecords[] = $relatedRecord->toLegacyRecord();
            }
            $record->addRelatedRecords($relatedRecords);
        }
        return $record;
    }

    /**
     * Get flex form configuration from file or reference
     *
     * @param RecordInterface $record
     * @param string $column
     * @param array $columnConfiguration
     *
     * @return array
     * @throws InvalidParentRowException
     * @throws InvalidParentRowLoopException
     * @throws InvalidParentRowRootException
     * @throws InvalidPointerFieldValueException
     * @throws InvalidTcaException
     * @throws InvalidIdentifierException
     */
    protected function getFlexFormDefinition(RecordInterface $record, string $column, array $columnConfiguration): array
    {
        $tableName = $record->getTableName();
        $localProperties = $record->getLocalProperties();
        try {
            $dataStructIdentifier = $this->flexFormTools->getDataStructureIdentifier(
                ['config' => $columnConfiguration],
                $tableName,
                $column,
                $localProperties
            );
        } catch (InvalidSinglePointerFieldException $exception) {
            // Known exception.
            // This occurs when a FAL driver was deactivated but the sys_file_storage record still exists.
            return [];
        }
        $flexFormDefinition = $this->flexFormTools->parseDataStructureByIdentifier($dataStructIdentifier);
        $flexFormDefinition = $flexFormDefinition['sheets'];
        $flexFormDefinition = $this->flattenFlexFormDefinition((array)$flexFormDefinition);
        return $this->filterFlexFormDefinition($flexFormDefinition);
    }

    /**
     * Simplify flexform definition
     *
     *      'sDEF' => array(
     *          'ROOT' => array(
     *              'TCEforms' => array(
     *                  'sheetTitle' => 'Common'
     *              ),
     *              'type' => 'array',
     *              'el' => array(
     *                  'settings.pid' => array(
     *                      'TCEforms' => array(
     *                          'exclude' => '1',
     *                          'label' => 'test',
     *                          'config' => array(
     *                              'type' => 'group'
     *                          )
     *                      )
     *                  )
     *              )
     *          )
     *      )
     *
     *      =>
     *
     *      'settings.pid' => array(
     *          'type' => 'group'
     *      )
     *
     * @param array $flexFormDefinition
     *
     * @return array
     */
    protected function flattenFlexFormDefinition(array $flexFormDefinition): array
    {
        $flattenedDefinition = [];
        foreach ($flexFormDefinition as $sheetDefinition) {
            foreach ($sheetDefinition as $rootDefinition) {
                if (is_array($rootDefinition) && !empty($rootDefinition['el'])) {
                    foreach ($rootDefinition['el'] as $fieldKey => $fieldDefinition) {
                        $flattenedDefinition = $this->flattenFieldFlexForm(
                            $flattenedDefinition,
                            $fieldDefinition,
                            $fieldKey
                        );
                    }
                }
            }
        }
        return $flattenedDefinition;
    }

    /**
     * Simplify flexform definition for a single field
     *
     *      'key' => array(
     *          'TCEforms' => array(
     *              'exclude' => '1',
     *              'label' => 'test',
     *              'config' => array(
     *                  'type' => 'group'
     *              )
     *          )
     *      )
     *
     *      =>
     *
     *      'key' => array(
     *          'type' => 'group'
     *      )
     *
     * @param array $flattenedDefinition
     * @param array $fieldDefinition
     * @param string $fieldKey
     *
     * @return array
     */
    protected function flattenFieldFlexForm(array $flattenedDefinition, array $fieldDefinition, string $fieldKey): array
    {
        // default FlexForm for a single field
        if (array_key_exists('TCEforms', $fieldDefinition)) {
            $flattenedDefinition[$fieldKey] = $fieldDefinition['TCEforms']['config'];
        } elseif (array_key_exists('el', $fieldDefinition)) {
            // advanced FlexForm for a single field with n subfields
            $fieldDefinition = $fieldDefinition['el'];
            foreach (array_keys($fieldDefinition) as $subKey) {
                if (array_key_exists('el', $fieldDefinition[$subKey])) {
                    foreach ($fieldDefinition[$subKey]['el'] as $subFieldKey => $subFieldDefinition) {
                        $newFieldKey = $fieldKey . '.[ANY].' . $subKey . '.' . $subFieldKey;
                        $flattenedDefinition = $this->flattenFieldFlexForm(
                            $flattenedDefinition,
                            $subFieldDefinition,
                            $newFieldKey
                        );
                    }
                }
            }
        } elseif (array_key_exists('config', $fieldDefinition)) {
            $flattenedDefinition[$fieldKey] = $fieldDefinition['config'];
        }
        return $flattenedDefinition;
    }

    protected function filterFlexFormDefinition(array $flexFormDefinition): array
    {
        foreach ($flexFormDefinition as $key => $config) {
            if (
                empty($config['type'])
                // Treat input and text always as field with relation because we can't access defaultExtras
                // settings here and better assume it's a RTE field
                || !in_array($config['type'], ['select', 'group', 'inline', 'input', 'text'])
            ) {
                unset($flexFormDefinition[$key]);
            }
        }
        return $flexFormDefinition;
    }

    protected function getFlexFormDataByDefinition(array $originalData, array $flexFormDefinition): array
    {
        $flexFormData = [];
        $keys = array_keys($flexFormDefinition);
        foreach ($keys as $key) {
            $indexStack = explode('.', $key);
            $flexFormData[$key] = $this->getValueByIndexStack($indexStack, $originalData);
        }
        return $flexFormData;
    }

    /**
     * @param array $indexStack
     * @param array $data
     * @param array $pathStack
     *
     * @return mixed
     */
    protected function getValueByIndexStack(array $indexStack, array $data, array &$pathStack = [])
    {
        $workingData = $data;
        while ($index = array_shift($indexStack)) {
            if ($index === '[ANY]') {
                if (!is_array($workingData)) {
                    // $workingData is unpacked by the else part and can be any data type.
                    // If th index is [ANY] $workingData is expected to be an array.
                    // TYPO3 saves empty arrays as non self-closing <el> xml tags whose value parses to string,
                    // not back to empty arrays, that's why values can be string instead of array.
                    return null;
                }
                foreach ($workingData as $subtreeIndex => $subtreeWorkingData) {
                    unset($workingData[$subtreeIndex]);
                    $tmp = $pathStack;
                    $pathStack[] = $subtreeIndex;
                    $value = $this->getValueByIndexStack($indexStack, $subtreeWorkingData, $pathStack);
                    $workingData[implode('.', $pathStack)] = $value;
                    $pathStack = $tmp;
                }
                return $workingData;
            }

            $pathStack[] = $index;
            if (array_key_exists($index, $workingData)) {
                $workingData = $workingData[$index];
            } else {
                return null;
            }
        }
        return $workingData;
    }

    /**
     * Get saved flexform from database
     *
     * @param RecordInterface $record
     * @param string $column
     *
     * @return array
     */
    protected function getLocalFlexFormDataFromRecord(RecordInterface $record, string $column): array
    {
        $localFlexFormData = [];
        if ($record->hasLocalProperty($column)) {
            $localProperty = $record->getLocalProperty($column);
            $localFlexFormData = $this->flexFormService->convertFlexFormContentToArray($localProperty);
        }
        return $localFlexFormData;
    }

    /**
     * Records like Plugins may have related Records defined in FlexForms
     * This method searched for the FlexForm Structure and
     * combines it with the FlexForm data array.
     * Currently only FlexForms using "select" and "group/db"-relations are supported
     *
     * @param RecordInterface $record
     * @param string $column
     * @param array $excludedTableNames
     * @param array $columnConfiguration
     *
     * @return array
     * @throws Exception
     */
    protected function fetchRelatedRecordsByFlexForm(
        RecordInterface $record,
        string $column,
        array $excludedTableNames,
        array $columnConfiguration
    ): array {
        $records = [];

        $localFlexFormData = $this->getLocalFlexFormDataFromRecord($record, $column);
        if (empty($localFlexFormData)) {
            return $records;
        }

        $flexFormDefinition = $this->getFlexFormDefinition($record, $column, $columnConfiguration);
        if (empty($flexFormDefinition)) {
            return $records;
        }

        $flexFormData = $this->getFlexFormDataByDefinition($localFlexFormData, $flexFormDefinition);
        if (empty($flexFormData)) {
            return $records;
        }

        $shouldSkip = $this->shouldSkipSearchingForRelatedRecordsByFlexForm(
            $record,
            $column,
            $columnConfiguration,
            $flexFormDefinition,
            $flexFormData
        );
        if ($shouldSkip) {
            return $records;
        }

        foreach ($flexFormDefinition as $key => $config) {
            if (!empty($flexFormData[$key])) {
                if (false === strpos($key, '[ANY]')) {
                    $currentFlexFormData = [$flexFormData[$key]];
                } else {
                    $currentFlexFormData = $flexFormData[$key];
                }
                foreach ($currentFlexFormData as $currentFlexFormDatum) {
                    $newRecords = $this->getRecordsByFlexFormRelation(
                        $record,
                        $column,
                        $excludedTableNames,
                        $config,
                        $currentFlexFormDatum,
                        $key
                    );
                    $records = array_merge($records, $newRecords);
                }
            }
        }
        return $records;
    }

    /**
     * @param RecordInterface $record
     * @param string $column
     * @param array $exclTables
     * @param array $config
     * @param mixed $flexFormData
     * @param string $key
     *
     * @return array
     * @throws Exception
     */
    protected function getRecordsByFlexFormRelation(
        RecordInterface $record,
        string $column,
        array $exclTables,
        array $config,
        $flexFormData,
        string $key
    ): array {
        if (
        $this->shouldSkipSearchingForRelatedRecordsByFlexFormProperty(
            $record,
            $column,
            $key,
            $config,
            $flexFormData
        )
        ) {
            return [];
        }

        $records = [];
        $recTable = $record->getTableName();
        $recordId = $record->getIdentifier();
        switch ($config['type']) {
            case 'select':
                $whereClause = '';
                if (!empty($config['foreign_table_where'])) {
                    $whereClause = $config['foreign_table_where'];
                    if (false !== strpos($whereClause, '{#')) {
                        $whereClause = QueryHelper::quoteDatabaseIdentifiers($this->localDatabase, $whereClause);
                    }
                    if (false !== strpos($whereClause, '###')) {
                        $whereClause = $this->replaceMarkersService->replaceFlexFormMarkers(
                            $record,
                            $whereClause,
                            $column,
                            $key
                        );
                    }
                }

                $records = $this->fetchRelatedRecordsBySelect(
                    $config,
                    $record,
                    $column,
                    $exclTables,
                    $whereClause,
                    $flexFormData
                );
                break;
            case 'inline':
                $records = $this->fetchRelatedRecordsByInline(
                    $config,
                    $recTable,
                    $record,
                    $exclTables,
                    $column,
                    $flexFormData
                );
                break;
            case 'group':
                $records = $this->fetchRelatedRecordsByGroup($config, $record, $column, $exclTables, $flexFormData);
                break;
            case 'text':
                // input and text are both treated as RTE
            case 'input':
                if (is_string($flexFormData)) {
                    $records = $this->fetchRelatedRecordsByRte($flexFormData, $exclTables);
                }
                break;
            default:
                $this->logger->emergency(
                    'A weird error occurred. An unsupported FlexForm type sneaked through the FlexForm filter',
                    [
                        'sheetConfiguration' => $config,
                        'column' => $column,
                        'tableName' => $recTable,
                        'identifier' => $recordId,
                    ]
                );
        }
        return $records;
    }

    /**
     * @param array $columnConfiguration
     * @param RecordInterface $record
     * @param array $excludedTableNames
     * @param string $propertyName
     * @param array $overrideIdentifiers
     *
     * @return RecordInterface[]
     */
    protected function fetchRelatedRecordsByGroupTypeDb(
        array $columnConfiguration,
        RecordInterface $record,
        array $excludedTableNames,
        string $propertyName,
        array $overrideIdentifiers = []
    ): array {
        /** @var RecordInterface[] $records */
        $records = [];
        $tableName = '';
        if (!empty($columnConfiguration['foreign_table'])) {
            $tableName = $columnConfiguration['foreign_table'];
        } elseif (!empty($columnConfiguration['allowed'])) {
            $tableName = $columnConfiguration['allowed'];
        }
        if (strpos($tableName, ',') !== false) {
            $tableNames = explode(',', $tableName);
            if (!empty($overrideIdentifiers)) {
                $identifierArray = $overrideIdentifiers;
            } else {
                $identifierArray = array_filter(
                    GeneralUtility::trimExplode(
                        ',',
                        $record->getLocalProperty($propertyName)
                    )
                );
            }
            if (!empty($identifierArray)) {
                $identifierToTableMap = [];
                foreach ($tableNames as $tableName) {
                    if (in_array($tableName, $excludedTableNames)) {
                        continue;
                    }
                    foreach ($identifierArray as $identifier) {
                        if (strpos($identifier, $tableName) !== false) {
                            $id = substr($identifier, strlen($tableName) + 1);
                            $identifierToTableMap[$tableName][] = $id;
                        }
                    }
                }
                foreach ($identifierToTableMap as $tableName => $identifiers) {
                    if ($columnConfiguration['MM']) {
                        $this->logger->alert(
                            'Missing implementation: GROUP MM',
                            [
                                'table' => $record->getTableName(),
                                'property' => $propertyName,
                                'columnConfiguration' => $columnConfiguration,
                            ]
                        );
                    }
                    foreach ($identifiers as $identifier) {
                        $localProperties = $this->findPropertiesByProperty(
                            $this->localDatabase,
                            'uid',
                            $identifier,
                            '',
                            '',
                            '',
                            '',
                            'uid',
                            $tableName
                        );
                        $foreignProps = $this->findPropertiesByProperty(
                            $this->foreignDatabase,
                            'uid',
                            $identifier,
                            '',
                            '',
                            '',
                            '',
                            'uid',
                            $tableName
                        );
                        $found = $this->convertPropertyArraysToRecords($localProperties, $foreignProps, $tableName);
                        $records = array_merge($records, $found);
                    }
                }
            }
        } else {
            if (in_array($tableName, $excludedTableNames)) {
                return $records;
            }
            if ($columnConfiguration['MM']) {
                // skip if this record is not the owning side of the relation
                if (!empty($columnConfiguration['MM_oppositeUsage'])) {
                    return $records;
                }
                if (
                    !empty($columnConfiguration['MM_match_fields'])
                    || !empty($columnConfiguration['MM_insert_fields'])
                    || !empty($columnConfiguration['MM_table_where'])
                    || !empty($columnConfiguration['MM_hasUidField'])
                ) {
                    $this->logger->error(
                        'Group MM relations with MM_match_fields,'
                        . ' MM_insert_fields, MM_table_where or MM_hasUidField are not supported',
                        [
                            'tableName' => $tableName,
                            'propertyName' => $propertyName,
                            'columnConfiguration' => $columnConfiguration,
                        ]
                    );
                }
                $mmTableName = $columnConfiguration['MM'];
                $localProperties = $this->findPropertiesByProperty(
                    $this->localDatabase,
                    $this->getLocalField($columnConfiguration),
                    $record->getIdentifier(),
                    '',
                    '',
                    '',
                    '',
                    'uid_local,uid_foreign',
                    $mmTableName
                );
                $foreignProperties = $this->findPropertiesByProperty(
                    $this->foreignDatabase,
                    $this->getLocalField($columnConfiguration),
                    $record->getIdentifier(),
                    '',
                    '',
                    '',
                    '',
                    'uid_local,uid_foreign',
                    $mmTableName
                );
                $records = $this->convertPropertyArraysToRecords($localProperties, $foreignProperties, $mmTableName);
                foreach ($records as $relatedRecord) {
                    if ($relatedRecord->hasLocalProperty('tablenames')) {
                        $originalTableName = $relatedRecord->hasLocalProperty('tablenames');
                    } else {
                        $originalTableName = $tableName;
                    }
                    $localUid = $relatedRecord->getLocalProperty($this->getForeignField($columnConfiguration));
                    $foreignUid = $relatedRecord->getForeignProperty($this->getForeignField($columnConfiguration));

                    if ($localUid > 0 && $foreignUid > 0 && $localUid !== $foreignUid) {
                        $this->logger->alert(
                            'Detected different UIDs in fetchRelatedRecordsByGroup',
                            [
                                'columnConfiguration' => $columnConfiguration,
                                'recordTableName' => $originalTableName,
                                'relatedRecordIdentifier' => $relatedRecord->getIdentifier(),
                            ]
                        );
                        continue;
                    }
                    if (!in_array($originalTableName, $excludedTableNames)) {
                        $originalRecord = $this->findByIdentifier($localUid, $originalTableName);
                        if ($originalRecord !== null) {
                            $relatedRecord->addRelatedRecord($originalRecord);
                        }
                    }
                }
            } else {
                if (!empty($overrideIdentifiers)) {
                    $identifiers = $overrideIdentifiers;
                } else {
                    $identifiers = array_filter(
                        GeneralUtility::trimExplode(
                            ',',
                            $record->getMergedProperty($propertyName)
                        )
                    );
                }
                foreach ($identifiers as $identifier) {
                    $localProperties = $this->findPropertiesByProperty(
                        $this->localDatabase,
                        'uid',
                        $identifier,
                        '',
                        '',
                        '',
                        '',
                        'uid',
                        $tableName
                    );
                    $foreignProperties = $this->findPropertiesByProperty(
                        $this->foreignDatabase,
                        'uid',
                        $identifier,
                        '',
                        '',
                        '',
                        '',
                        'uid',
                        $tableName
                    );
                    $found = $this->convertPropertyArraysToRecords($localProperties, $foreignProperties, $tableName);
                    $records = array_merge($records, $found);
                }
            }
        }
        return $records;
    }

    /**
     * type=group is widely spread among old Extensions and Core.
     * This method fetches records related by group.
     *
     * @param array $columnConfiguration
     * @param RecordInterface $record
     * @param string $propertyName
     * @param array $excludedTableNames
     * @param mixed $flexFormData
     *
     * @return array
     * @throws Exception
     */
    protected function fetchRelatedRecordsByGroup(
        array $columnConfiguration,
        RecordInterface $record,
        string $propertyName,
        array $excludedTableNames,
        $flexFormData = ''
    ): array {
        $records = [];
        switch ($columnConfiguration['internal_type']) {
            case 'db':
                $records = $this->fetchRelatedRecordsByGroupTypeDb(
                    $columnConfiguration,
                    $record,
                    $excludedTableNames,
                    $propertyName,
                    GeneralUtility::trimExplode(',', $flexFormData, true)
                );
                break;
            case 'file_reference':
            case 'file':
                $fileAndPathNames = $this->getFileAndPathNames(
                    $columnConfiguration,
                    $record,
                    $propertyName,
                    $flexFormData
                );
                foreach ($fileAndPathNames as $fileAndPathName) {
                    $record = $this->findByIdentifier($fileAndPathName, 'sys_file', 'identifier');
                    if ($record instanceof RecordInterface) {
                        $recordIdentifier = $record->getIdentifier();

                        // special case: the record exists only in the local database and the same uid
                        // is existent in the foreign table, but not with the given identifier
                        // Solution: Re-fetch the record by its UID, so we ensure we can overwrite the foreign record,
                        // given the relation is broken
                        if (RecordInterface::RECORD_STATE_ADDED === $record->getState()) {
                            $this->recordFactory->forgetCachedRecord('sys_file', $recordIdentifier);
                            $record = $this->findByIdentifier($recordIdentifier, 'sys_file');
                            if (RecordInterface::RECORD_STATE_ADDED !== $record->getState()) {
                                $this->logger->notice(
                                    'Detected broken record relation between local and foreign. '
                                    . 'The foreign\'s identifier differs from the local, but the uid is the same',
                                    ['uid' => $recordIdentifier, 'identifier' => $fileAndPathName]
                                );
                            }
                        }
                        $records[$recordIdentifier] = $record;
                    }
                }
                break;
            default:
                $this->logger->alert(
                    'Missing implementation: GROUP TYPE',
                    [
                        'table' => $record->getTableName(),
                        'property' => $propertyName,
                        'columnConfiguration' => $columnConfiguration,
                    ]
                );
        }
        return $records;
    }

    /**
     * Get file and path (and index it)
     *
     * @param array $columnConfiguration
     * @param RecordInterface $record
     * @param string $propertyName
     * @param mixed $flexFormData
     *
     * @return array
     */
    protected function getFileAndPathNames(
        array $columnConfiguration,
        RecordInterface $record,
        string $propertyName,
        $flexFormData
    ): array {
        $prefix = '';
        if (!empty($columnConfiguration['uploadfolder'])) {
            $prefix = FileUtility::getCleanFolder($columnConfiguration['uploadfolder']);
        }
        if (empty($flexFormData)) {
            $fileNames = GeneralUtility::trimExplode(',', $record->getLocalProperty($propertyName), true);
        } else {
            $fileNames = GeneralUtility::trimExplode(',', $flexFormData, true);
        }
        foreach ($fileNames as $key => $filename) {
            // Force indexing of the record
            $fileNames[$key] = $this->resourceFactory->getFileObjectFromCombinedIdentifier($prefix . $filename)
                                                     ->getIdentifier();
        }
        return $fileNames;
    }

    /**
     * Fetches records by select relations. supports MM tables
     *
     * @param array $columnConfiguration
     * @param RecordInterface $record
     * @param string $propertyName
     * @param array $excludedTableNames
     * @param string $whereClause
     * @param mixed $recordIdentifierOverride
     *
     * @return array
     */
    protected function fetchRelatedRecordsBySelect(
        array $columnConfiguration,
        RecordInterface $record,
        string $propertyName,
        array $excludedTableNames,
        string $whereClause,
        $recordIdentifierOverride = null
    ): array {
        $tableName = $columnConfiguration['foreign_table'];
        $isL10nPointer = $propertyName === $this->tcaService->getTransOrigPointerField($record->getTableName());
        // Ignore $excludedTableNames if the field points to the record's l10Parent, which is required to be published.
        if (!$isL10nPointer && (empty($tableName) || in_array($tableName, $excludedTableNames))) {
            return [];
        }
        $records = [];

        if (null !== $recordIdentifierOverride) {
            $recordIdentifier = $recordIdentifierOverride;
        } else {
            $recordIdentifier = $record->getMergedProperty($propertyName);
        }

        if ($recordIdentifier !== null && trim((string)$recordIdentifier) !== '' && (int)$recordIdentifier > 0) {
            // if the relation is an MM type, then select all identifiers from the MM table
            if (!empty($columnConfiguration['MM'])) {
                $records = $this->fetchRelatedRecordsBySelectMm($columnConfiguration, $record, $excludedTableNames);
            } else {
                $uidArray = [];

                if (MathUtility::canBeInterpretedAsInteger($recordIdentifier)) {
                    $uidArray[] = $recordIdentifier;
                } elseif (is_string($recordIdentifier) && strpos($recordIdentifier, ',')) {
                    $uidArray = GeneralUtility::trimExplode(',', $recordIdentifier);
                } elseif (is_array($recordIdentifier)) {
                    $uidArray = $recordIdentifier;
                }
                foreach ($uidArray as $uid) {
                    $localProperties = $this->findPropertiesByProperty(
                        $this->localDatabase,
                        'uid',
                        $uid,
                        $whereClause,
                        '',
                        '',
                        '',
                        'uid',
                        $tableName
                    );
                    $foreignProperties = $this->findPropertiesByProperty(
                        $this->foreignDatabase,
                        'uid',
                        $uid,
                        $whereClause,
                        '',
                        '',
                        '',
                        'uid',
                        $tableName
                    );
                    $found = $this->convertPropertyArraysToRecords($localProperties, $foreignProperties, $tableName);
                    $records = array_merge($records, $found);
                }
            }
        }
        return $records;
    }

    protected function fetchRelatedRecordsBySelectMm(
        array $columnConfiguration,
        RecordInterface $record,
        array $excludedTableNames
    ): array {
        $tableName = $columnConfiguration['MM'];

        // log not supported TCA function
        if (!empty($columnConfiguration['MM_table_where'])) {
            $this->logger->warning(
                'MM_table_where of select records is not implemented. Please contact the extension authors at in2code',
                [
                    'columnConfiguration' => $columnConfiguration,
                ]
            );
        }

        // build additional where clause
        $additionalWhereArray = [];
        if (!empty($columnConfiguration['MM_match_fields'])) {
            $foreignMatchFields = [];
            foreach ($columnConfiguration['MM_match_fields'] as $matchField => $matchValue) {
                $foreignMatchFields[] = $matchField . ' LIKE "' . $matchValue . '"';
            }
            $additionalWhereArray = array_merge($additionalWhereArray, $foreignMatchFields);
        }
        $additionalWhere = implode(' AND ', $additionalWhereArray);
        if ($additionalWhere !== '') {
            $additionalWhere = ' AND ' . $additionalWhere;
        }
        $localProperties = $this->findPropertiesByProperty(
            $this->localDatabase,
            $this->getLocalField($columnConfiguration),
            $record->getIdentifier(),
            $additionalWhere,
            '',
            '',
            '',
            'uid_local,uid_foreign',
            $tableName
        );
        $foreignProperties = $this->findPropertiesByProperty(
            $this->foreignDatabase,
            $this->getLocalField($columnConfiguration),
            $record->getIdentifier(),
            $additionalWhere,
            '',
            '',
            '',
            'uid_local,uid_foreign',
            $tableName
        );
        $records = $this->convertPropertyArraysToRecords($localProperties, $foreignProperties, $tableName);

        $foreignField = $this->getForeignField($columnConfiguration);

        foreach ($records as $relationRecord) {
            $originalTableName = $columnConfiguration['foreign_table'];
            if (!in_array($originalTableName, $excludedTableNames)) {
                if ($relationRecord->hasLocalProperty($foreignField)) {
                    $identifier = $relationRecord->getLocalProperty($foreignField);
                } elseif ($relationRecord->hasForeignProperty($foreignField)) {
                    $identifier = $relationRecord->getForeignProperty($foreignField);
                } else {
                    continue;
                }
                if (null !== $identifier) {
                    $originalRecord = $this->findByIdentifier((int)$identifier, $originalTableName);
                    if ($originalRecord !== null) {
                        $relationRecord->addRelatedRecord($originalRecord);
                    }
                }
            }
        }

        return $records;
    }

    /**
     * Fetches inline related Records like FAL images. Often used in FAL
     * or custom Extensions. Lacks support for MM tables
     *
     * @param array $columnConfiguration
     * @param string $recordTableName
     * @param RecordInterface $record
     * @param array $excludedTableNames
     * @param string $propertyName
     * @param string|null $flexFormData
     *
     * @return array
     */
    protected function fetchRelatedRecordsByInline(
        array $columnConfiguration,
        string $recordTableName,
        RecordInterface $record,
        array $excludedTableNames,
        string $propertyName,
        string $flexFormData = null
    ): array {
        $recordIdentifier = $record->getIdentifier();
        $tableName = $columnConfiguration['foreign_table'];
        if (in_array($tableName, $excludedTableNames)) {
            return [];
        }

        $where = [];

        if (!empty($columnConfiguration['MM'])) {
            return $this->fetchRelatedRecordsByInlineMm(
                $columnConfiguration,
                $recordTableName,
                $recordIdentifier,
                $excludedTableNames
            );
        }

        if (!empty($columnConfiguration['foreign_table_field'])) {
            $where[] = $columnConfiguration['foreign_table_field'] . ' LIKE "' . $recordTableName . '"';
        }

        if (
            !empty($columnConfiguration['foreign_match_fields'])
            && is_array($columnConfiguration['foreign_match_fields'])
        ) {
            foreach ($columnConfiguration['foreign_match_fields'] as $fieldName => $fieldValue) {
                $where[] = $fieldName . ' LIKE "' . $fieldValue . '"';
            }
        }

        $whereClause = '';
        if (!empty($where)) {
            $whereClause = ' AND ' . implode(' AND ', $where);
        }

        if (empty($columnConfiguration['foreign_field'])) {
            $records = [];
            if (empty($flexFormData)) {
                $localList = $record->getLocalProperty($propertyName);
                $localList = GeneralUtility::trimExplode(',', $localList, true);
                $foreignList = $record->getForeignProperty($propertyName);
                $foreignList = GeneralUtility::trimExplode(',', $foreignList, true);
                $identifierList = array_unique(array_merge($localList, $foreignList));
            } else {
                $identifierList = GeneralUtility::intExplode(',', $flexFormData);
            }
            foreach ($identifierList as $uid) {
                $records[] = $this->findByIdentifier((int)$uid, $tableName);
            }
            return $records;
        }

        $foreignField = $columnConfiguration['foreign_field'];

        $localProperties = $this->findPropertiesByProperty(
            $this->localDatabase,
            $foreignField,
            $recordIdentifier,
            $whereClause,
            '',
            '',
            '',
            'uid',
            $tableName
        );
        $foreignProperties = $this->findPropertiesByProperty(
            $this->foreignDatabase,
            $foreignField,
            $recordIdentifier,
            $whereClause,
            '',
            '',
            '',
            'uid',
            $tableName
        );
        return $this->convertPropertyArraysToRecords($localProperties, $foreignProperties, $tableName);
    }

    protected function fetchRelatedRecordsByInlineMm(
        array $columnConfiguration,
        string $recordTableName,
        int $recordIdentifier,
        array $excludedTableNames
    ): array {
        if (
            !empty($columnConfiguration['foreign_field'])
            || !empty($columnConfiguration['foreign_selector'])
            || !empty($columnConfiguration['filter'])
            || !empty($columnConfiguration['foreign_types'])
            || !empty($columnConfiguration['foreign_table_field'])
        ) {
            $this->logger->error(
                'Inline MM relations with foreign_field, foreign_types, symmetric_field, filter, '
                . 'foreign_table_field or foreign_selector are not supported',
                [
                    'columnConfiguration' => $columnConfiguration,
                    'recordTableName' => $recordTableName,
                    'recordIdentifier' => $recordIdentifier,
                ]
            );
        }
        $tableName = $columnConfiguration['MM'];
        $localProperties = $this->findPropertiesByProperty(
            $this->localDatabase,
            'uid_local',
            $recordIdentifier,
            '',
            '',
            '',
            '',
            'uid_local,uid_foreign',
            $tableName
        );
        $foreignProperties = $this->findPropertiesByProperty(
            $this->foreignDatabase,
            'uid_local',
            $recordIdentifier,
            '',
            '',
            '',
            '',
            'uid_local,uid_foreign',
            $tableName
        );
        $relationRecords = $this->convertPropertyArraysToRecords($localProperties, $foreignProperties, $tableName);
        $this->fetchOriginalRecordsForInlineRecord(
            $relationRecords,
            $columnConfiguration,
            $recordTableName,
            $recordIdentifier,
            $excludedTableNames
        );
        return $relationRecords;
    }

    /**
     * @param RecordInterface[] $relationRecords
     * @param array $columnConfiguration
     * @param string $recordTableName
     * @param int $recordIdentifier
     * @param array $excludedTableNames
     *
     * @return array
     */
    protected function fetchOriginalRecordsForInlineRecord(
        array $relationRecords,
        array $columnConfiguration,
        string $recordTableName,
        int $recordIdentifier,
        array $excludedTableNames
    ): array {
        foreach ($relationRecords as $mmRecord) {
            $localUid = $mmRecord->getLocalProperty('uid_foreign');
            $foreignUid = $mmRecord->getForeignProperty('uid_foreign');

            if ($localUid > 0 && $foreignUid > 0 && $localUid !== $foreignUid) {
                $this->logger->alert(
                    'Detected different UIDs in fetchRelatedRecordsByInline',
                    [
                        'columnConfiguration' => $columnConfiguration,
                        'recordTableName' => $recordTableName,
                        'recordIdentifier' => $recordIdentifier,
                    ]
                );
                continue;
            }

            $originalTableName = $columnConfiguration['foreign_table'];
            if (!in_array($originalTableName, $excludedTableNames)) {
                $originalRecord = $this->findByIdentifier($localUid, $columnConfiguration['foreign_table']);
                if ($originalRecord !== null) {
                    $mmRecord->addRelatedRecord($originalRecord);
                }
            }
        }
        return $relationRecords;
    }

    /**
     * Check if this record should be ignored
     *
     * @param array $localProperties
     * @param array $foreignProperties
     * @param string $tableName
     *
     * @return bool
     */
    protected function isIgnoredRecord(array $localProperties, array $foreignProperties, string $tableName): bool
    {
        if ($this->isDeletedAndUnchangedRecord($localProperties, $foreignProperties)) {
            return true;
        }

        if ($this->isRemovedAndDeletedRecord($localProperties, $foreignProperties)) {
            return true;
        }

        if ($this->shouldIgnoreRecord($localProperties, $foreignProperties, $tableName)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the record is removed on one side (never existed) and was deleted on the other (it got deleted)
     *
     * @param array $localProps
     * @param array $foreignProps
     *
     * @return bool
     */
    protected function isRemovedAndDeletedRecord(array $localProps, array $foreignProps): bool
    {
        if ($this->configContainer->get('factory.treatRemovedAndDeletedAsDifference')) {
            // "Removed and deleted" only refers to the local side.
            // If the record is not exactly 1. deleted on foreign and 2. removed on local this feature does not apply.
            /** @noinspection NestedPositiveIfStatementsInspection */
            if (
                empty($localProps)
                && array_key_exists('deleted', $foreignProps)
                && 1 === (int)$foreignProps['deleted']
            ) {
                return false;
            }
        }
        return (empty($localProps) && isset($foreignProps['deleted']) && 1 === (int)$foreignProps['deleted'])
               || (empty($foreignProps) && isset($localProps['deleted']) && 1 === (int)$localProps['deleted']);
    }

    /**
     * Check if this record is deleted on local and foreign
     * site and both are completely identical (don't show)
     *
     * @param array $localProperties
     * @param array $foreignProperties
     *
     * @return bool
     */
    protected function isDeletedAndUnchangedRecord(array $localProperties, array $foreignProperties): bool
    {
        return $localProperties['deleted'] === '1' && !count(array_diff($localProperties, $foreignProperties));
    }

    /**
     * Publishes the given Record and all related Records
     * where the related Record's tableName is not excluded
     *
     * @param RecordInterface $record
     * @param array $excludedTables
     *
     * @return void
     * @throws Throwable
     */
    public function publishRecordRecursive(RecordInterface $record, array $excludedTables = ['p ages']): void
    {
        try {
            // Dispatch Anomaly
            $this->eventDispatcher->dispatch(new RecursiveRecordPublishingBegan($record, $this));

            $this->publishRecordRecursiveInternal($record, $excludedTables);

            // Dispatch Anomaly
            $this->eventDispatcher->dispatch(new RecursiveRecordPublishingEnded($record, $this));
        } catch (Throwable $exception) {
            $this->logger->critical('Publishing single record failed', ['exception' => $exception]);
            throw $exception;
        }
    }

    protected function publishRecordRecursiveInternal(RecordInterface $record, array $excludedTables): void
    {
        $tableName = $record->getTableName();

        if (
            !empty($this->visitedRecords[$tableName])
            && in_array($record->getIdentifier(), $this->visitedRecords[$tableName])
        ) {
            return;
        }
        $this->visitedRecords[$tableName][] = $record->getIdentifier();

        if (!$this->shouldSkipRecord($record)) {
            // Dispatch Anomaly
            $this->eventDispatcher->dispatch(new PublishingOfOneRecordBegan($record, $this));

            /*
             * For Records shown as moved:
             * Since moved pages only get published explicitly, they will
             * have the state "changed" instead of "moved".
             * Because of this, we don't need to take care about that state
             */

            if ($record->hasAdditionalProperty('recordDatabaseState')) {
                $state = $record->getAdditionalProperty('recordDatabaseState');
            } else {
                $state = $record->getState();
            }

            if (true === $record->getAdditionalProperty('isPrimaryIndex')) {
                $this->logger->notice(
                    'Removing duplicate index from remote',
                    [
                        'tableName' => $tableName,
                        'local_uid' => $record->getLocalProperty('uid'),
                        'foreign_uid' => $record->getForeignProperty('uid'),
                    ]
                );
                // remove duplicate remote index
                $this->deleteRecord($this->foreignDatabase, $record->getForeignProperty('uid'), $tableName);
            }

            if ($state === RecordInterface::RECORD_STATE_CHANGED || $state === RecordInterface::RECORD_STATE_MOVED) {
                $this->updateForeignRecord($record);
            } elseif ($state === RecordInterface::RECORD_STATE_ADDED) {
                $this->addForeignRecord($record);
            } elseif ($state === RecordInterface::RECORD_STATE_DELETED) {
                if ($record->localRecordExists()) {
                    $this->updateForeignRecord($record);
                } elseif ($record->foreignRecordExists()) {
                    $this->deleteForeignRecord($record);
                }
            }

            // Dispatch Anomaly
            $this->eventDispatcher->dispatch(new PublishingOfOneRecordEnded($record, $this));

            // set the records state to published/unchanged to prevent
            // a second INSERT or UPDATE (superfluous queries)
            $record->setState(RecordInterface::RECORD_STATE_UNCHANGED);
        }

        // publish all related records
        $this->publishRelatedRecordsRecursive($record, $excludedTables);
    }

    /**
     * Publishes all related Records of the given record if
     * their tableName is not included in $excludedTables
     *
     * @param RecordInterface $record
     * @param array $excludedTables
     *
     * @return void
     */
    protected function publishRelatedRecordsRecursive(RecordInterface $record, array $excludedTables): void
    {
        foreach ($record->getTranslatedRecords() as $translatedRecord) {
            $this->publishRecordRecursiveInternal($translatedRecord, $excludedTables);
        }

        if (
            $record->hasAdditionalProperty('isRoot')
            && $record->getAdditionalProperty('isRoot') === true
            && !empty($languageField = $this->tcaService->getLanguageField($record->getTableName()))
            && (
                $record->getLocalProperty($languageField) > 0
                || $record->getForeignProperty($languageField) > 0
            )
            && !empty($pointerField = $this->tcaService->getTransOrigPointerField($record->getTableName()))
            && $record->getMergedProperty($pointerField) > 0
        ) {
            $translationOriginals = $record->getRelatedRecordByTableAndProperty(
                $record->getTableName(),
                'uid',
                $record->getMergedProperty($pointerField)
            );
            foreach ($translationOriginals as $translationOriginal) {
                $this->publishRecordRecursiveInternal($translationOriginal, $excludedTables);
            }
        }

        foreach ($record->getRelatedRecords() as $tableName => $relatedRecords) {
            if (is_array($relatedRecords) && !in_array($tableName, $excludedTables, true)) {
                /** @var RecordInterface $relatedRecord */
                foreach ($relatedRecords as $relatedRecord) {
                    $this->publishRecordRecursiveInternal($relatedRecord, $excludedTables);
                }
            }
        }
    }

    /**
     * Publishing Method: Executes an UPDATE query on the
     * foreign Database with all record properties
     *
     * @param RecordInterface $record
     *
     * @return void
     */
    protected function updateForeignRecord(RecordInterface $record): void
    {
        $identifier = $record->getIdentifier();
        $properties = $record->getLocalProperties();
        $tableName = $record->getTableName();
        $this->updateRecord($this->foreignDatabase, $identifier, $properties, $tableName);
    }

    /**
     * Publishing Method: Executes an INSERT query on the
     * foreign database with all record properties
     *
     * @param RecordInterface $record
     *
     * @return void
     */
    protected function addForeignRecord(RecordInterface $record): void
    {
        $tableName = $record->getTableName();
        $properties = $record->getLocalProperties();
        $this->addRecord($this->foreignDatabase, $properties, $tableName);
    }

    /**
     * Publishing Method: Removes a row from the foreign database
     * This can not be undone and the row will be removed forever
     *
     * Since this action is highly destructive, it
     * must be enabled in the Configuration
     *
     * @param RecordInterface $record
     *
     * @return void
     */
    protected function deleteForeignRecord(RecordInterface $record): void
    {
        $identifier = $record->getIdentifier();
        $tableName = $record->getTableName();
        $this->logger->notice(
            'Deleting foreign record',
            [
                'localProperties' => $record->getLocalProperties(),
                'foreignProperties' => $record->getForeignProperties(),
                'identifier' => $identifier,
                'tableName' => $tableName,
            ]
        );
        $this->deleteRecord($this->foreignDatabase, $identifier, $tableName);
    }

    /**
     * Get local field for mm tables (and switch name if "MM_opposite_field" is set, should be set for categories only)
     *
     * @param array $columnConfiguration
     *
     * @return string
     */
    protected function getLocalField(array $columnConfiguration): string
    {
        $localField = 'uid_local';
        if (!empty($columnConfiguration['MM_opposite_field'])) {
            $localField = 'uid_foreign';
        }
        return $localField;
    }

    /**
     * Get foreign field for mm tables (and switch name if "MM_opposite_field" is set)
     *
     * @param array $columnConfiguration
     *
     * @return string
     */
    protected function getForeignField(array $columnConfiguration): string
    {
        $localField = 'uid_foreign';
        if (!empty($columnConfiguration['MM_opposite_field'])) {
            $localField = 'uid_local';
        }
        return $localField;
    }

    /**
     * Disable Page Recursion
     *
     * @return void
     */
    public function disablePageRecursion(): void
    {
        $this->recordFactory->disablePageRecursion();
    }

    protected function shouldSkipFindByIdentifier(int $identifier, string $tableName): bool
    {
        $event = new VoteIfFindingByIdentifierShouldBeSkipped($this, $identifier, $tableName);
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    protected function shouldSkipFindByProperty($propertyName, $propertyValue, $tableName): bool
    {
        $event = new VoteIfFindingByPropertyShouldBeSkipped($this, $propertyName, $propertyValue, $tableName);
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    protected function shouldSkipSearchingForRelatedRecords(RecordInterface $record): bool
    {
        $event = new VoteIfSearchingForRelatedRecordsShouldBeSkipped($this, $record);
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    protected function shouldSkipSearchingForRelatedRecordsByProperty(
        RecordInterface $record,
        string $propertyName,
        array $columnConfiguration
    ): bool {
        $event = new VoteIfSearchingForRelatedRecordsByPropertyShouldBeSkipped(
            $this,
            $record,
            $propertyName,
            $columnConfiguration
        );
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    protected function shouldSkipSearchingForRelatedRecordsByFlexForm(
        RecordInterface $record,
        string $column,
        $columnConfiguration,
        $flexFormDefinition,
        $flexFormData
    ): bool {
        $event = new VoteIfSearchingForRelatedRecordsByFlexFormShouldBeSkipped(
            $this,
            $record,
            $column,
            $columnConfiguration,
            $flexFormDefinition,
            $flexFormData
        );
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    protected function shouldSkipSearchingForRelatedRecordsByFlexFormProperty(
        RecordInterface $record,
        string $column,
        string $key,
        array $config,
        $flexFormData
    ): bool {
        $event = new VoteIfSearchingForRelatedRecordsByFlexFormPropertyShouldBeSkipped(
            $this,
            $record,
            $column,
            $key,
            $config,
            $flexFormData
        );
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    protected function shouldSkipEnrichingPageRecord(RecordInterface $record): bool
    {
        $event = new VoteIfPageRecordEnrichingShouldBeSkipped($this, $record);
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    protected function shouldSkipSearchingForRelatedRecordByTable(RecordInterface $record, string $tableName): bool
    {
        $event = new VoteIfSearchingForRelatedRecordsByTableShouldBeSkipped($this, $record, $tableName);
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    protected function shouldSkipRecord(RecordInterface $record): bool
    {
        $event = new VoteIfRecordShouldBeSkipped($this, $record);
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    protected function shouldIgnoreRecord(
        array $localProperties,
        array $foreignProperties,
        string $tableName
    ): bool {
        $event = new VoteIfRecordShouldBeIgnored($this, $localProperties, $foreignProperties, $tableName);
        $this->eventDispatcher->dispatch($event);
        return $event->getVotingResult();
    }

    public static function getDefaultInstance(): CommonRepository
    {
        return GeneralUtility::makeInstance(
            CommonRepository::class,
            DatabaseUtility::buildLocalDatabaseConnection(),
            DatabaseUtility::buildForeignDatabaseConnection()
        );
    }
}
