<?php
declare(strict_types=1);
namespace In2code\In2publishCore\Domain\Repository;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use In2code\In2publishCore\Config\ConfigContainer;
use In2code\In2publishCore\Service\Environment\ForeignEnvironmentService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/*
 * Mostly a copy of ConnectionPool.
 */

class ForeignConnectionPool extends ConnectionPool
{
    protected $foreignConfVars = [];

    /**
     * @var Connection[]
     */
    private static $foreignConnections = [];

    /**
     * ForeignConnectionPool constructor.
     */
    public function __construct()
    {
        $configuration = GeneralUtility::makeInstance(ConfigContainer::class)->get('foreign.database');

        $foreignEnvService = GeneralUtility::makeInstance(ForeignEnvironmentService::class);
        $initCommands = $foreignEnvService->getDatabaseInitializationCommands();

        $this->foreignConfVars = [
            'DB' => [
                'Connections' => [
                    'Default' => [
                        'dbname' => $configuration['name'],
                        'driver' => 'mysqli',
                        'host' => $configuration['hostname'],
                        'password' => $configuration['password'],
                        'port' => $configuration['port'],
                        'user' => $configuration['username'],
                        'initCommands' => $initCommands,
                    ],
                ],
            ],
        ];
    }

    /**
     * Creates a connection object based on the specified table name.
     *
     * This is the official entry point to get a database connection to ensure
     * that the mapping of table names to database connections is honored.
     *
     * @param string $tableName
     *
     * @return Connection
     */
    public function getConnectionForTable(string $tableName): Connection
    {
        if (empty($tableName)) {
            throw new \UnexpectedValueException(
                'ForeignConnectionPool->getConnectionForTable() requires a table name to be provided.',
                1459421719
            );
        }

        $connectionName = self::DEFAULT_CONNECTION_NAME;
        if (!empty($this->foreignConfVars['DB']['TableMapping'][$tableName])) {
            $connectionName = (string)$this->foreignConfVars['DB']['TableMapping'][$tableName];
        }

        return $this->getConnectionByName($connectionName);
    }

    /**
     * Creates a connection object based on the specified identifier.
     *
     * This method should only be used in edge cases. Use getConnectionForTable() so
     * that the tablename<>databaseConnection mapping will be taken into account.
     *
     * @param string $connectionName
     *
     * @return Connection
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getConnectionByName(string $connectionName): Connection
    {
        if (empty($connectionName)) {
            throw new \UnexpectedValueException(
                'ForeignConnectionPool->getConnectionByName() requires a connection name to be provided.',
                1459422125
            );
        }

        if (isset(static::$foreignConnections[$connectionName])) {
            return static::$foreignConnections[$connectionName];
        }

        $connectionParams = $this->foreignConfVars['DB']['Connections'][$connectionName] ?? [];
        if (empty($connectionParams)) {
            throw new \RuntimeException(
                'The requested database connection named "' . $connectionName . '" has not been configured.',
                1459422492
            );
        }

        if (empty($connectionParams['wrapperClass'])) {
            $connectionParams['wrapperClass'] = Connection::class;
        }

        if (!is_a($connectionParams['wrapperClass'], Connection::class, true)) {
            throw new \UnexpectedValueException(
                'The "wrapperClass" for the connection name "' . $connectionName .
                '" needs to be a subclass of "' . Connection::class . '".',
                1459422968
            );
        }

        static::$foreignConnections[$connectionName] = $this->getDatabaseConnection($connectionParams);

        return static::$foreignConnections[$connectionName];
    }

    /**
     * Creates a connection object based on the specified parameters
     *
     * @param array $connectionParams
     *
     * @return Connection
     */
    protected function getDatabaseConnection(array $connectionParams): Connection
    {
        // Default to UTF-8 connection charset
        if (empty($connectionParams['charset'])) {
            $connectionParams['charset'] = 'utf8';
        }

        // Force consistent handling of binary objects across datbase platforms
        // MySQL returns strings by default, PostgreSQL streams.
        if (strpos($connectionParams['driver'], 'pdo_') === 0) {
            $connectionParams['driverOptions'][\PDO::ATTR_STRINGIFY_FETCHES] = true;
        }

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($connectionParams);
        $conn->setFetchMode(\PDO::FETCH_ASSOC);
        $conn->prepareConnection($connectionParams['initCommands'] ?? '');

        // Register custom data types
        foreach ($this->customDoctrineTypes as $type => $className) {
            if (!Type::hasType($type)) {
                Type::addType($type, $className);
            }
        }

        // Register all custom data types in the type mapping
        foreach ($this->customDoctrineTypes as $type => $className) {
            $conn->getDatabasePlatform()->registerDoctrineTypeMapping($type, $type);
        }

        return $conn;
    }

    /**
     * Returns the connection specific query builder object that can be used to build
     * complex SQL queries using and object oriented approach.
     *
     * @param string $tableName
     *
     * @return QueryBuilder
     */
    public function getQueryBuilderForTable(string $tableName): QueryBuilder
    {
        if (empty($tableName)) {
            throw new \UnexpectedValueException(
                'ForeignConnectionPool->getQueryBuilderForTable() requires a connection name to be provided.',
                1459423448
            );
        }

        return $this->getConnectionForTable($tableName)->createQueryBuilder();
    }

    /**
     * Returns an array containing the names of all currently configured connections.
     *
     * This method should only be used in edge cases. Use getConnectionForTable() so
     * that the tablename<>databaseConnection mapping will be taken into account.
     *
     * @return array
     * @internal
     */
    public function getConnectionNames(): array
    {
        return array_keys($this->foreignConfVars['DB']['Connections']);
    }

    /**
     * Returns the list of custom Doctrine data types implemented by TYPO3.
     * This method is needed by the Schema parser to register the types as it
     * does not require a database connection and thus the types don't get
     * registered automatically.
     *
     * @return array
     * @internal
     */
    public function getCustomDoctrineTypes(): array
    {
        return $this->customDoctrineTypes;
    }

    /**
     * Reset internal list of connections.
     * Currently primarily used in functional tests to close connections and start
     * new ones in between single tests.
     */
    public function resetConnections(): void
    {
        static::$foreignConnections = [];
    }
}
