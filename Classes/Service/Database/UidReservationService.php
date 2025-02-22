<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Service\Database;

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

use In2code\In2publishCore\Utility\DatabaseUtility;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;

use function max;
use function spl_object_hash;
use function sprintf;

class UidReservationService
{
    /** @var Connection */
    protected $localDatabaseConnection;

    /** @var Connection */
    protected $foreignDatabaseConnection;

    /** @var array */
    protected $cache = [];

    public function __construct()
    {
        $this->localDatabaseConnection = DatabaseUtility::buildLocalDatabaseConnection();
        $this->foreignDatabaseConnection = DatabaseUtility::buildForeignDatabaseConnection();
    }

    /**
     * Increases the auto increment value on local and foreign DB
     * until it's two numbers higher than the highest taken uid.
     */
    public function getReservedUid(): int
    {
        $nextAutoIncrement = (int)max(
            $this->fetchSysFileAutoIncrementFromDatabase($this->localDatabaseConnection),
            $this->fetchSysFileAutoIncrementFromDatabase($this->foreignDatabaseConnection)
        );

        do {
            // increase the auto increment to "reserve" the previous integer
            $possibleUid = $nextAutoIncrement++;

            // apply the new auto increment on both databases
            $this->setAutoIncrement($nextAutoIncrement);
        } while (!$this->isUidFree($possibleUid));

        return $possibleUid;
    }

    protected function determineDatabaseOfConnection(Connection $databaseConnection): string
    {
        $cacheKey = spl_object_hash($databaseConnection);
        if (!isset($this->cache[$cacheKey])) {
            $this->cache[$cacheKey] = $databaseConnection->getDatabase();
        }

        return $this->cache[$cacheKey];
    }

    protected function setAutoIncrement(int $autoIncrement): void
    {
        $statement = 'ALTER TABLE sys_file AUTO_INCREMENT = ' . $autoIncrement;
        /** @var Connection $databaseConnection */
        foreach ([$this->localDatabaseConnection, $this->foreignDatabaseConnection] as $databaseConnection) {
            try {
                $databaseConnection->executeQuery($statement);
            } catch (Throwable $exception) {
                throw new RuntimeException('Failed to increase auto_increment on sys_file', 1475248851, $exception);
            }
        }
    }

    protected function isUidFree(int $uid): bool
    {
        /** @var Connection $databaseConnection */
        foreach ([$this->localDatabaseConnection, $this->foreignDatabaseConnection] as $databaseConnection) {
            $queryBuilder = $databaseConnection->createQueryBuilder();
            $numberOfRows = $queryBuilder
                ->count('uid')
                ->from('sys_file')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
                ->execute()
                ->fetchOne();

            if (0 !== $numberOfRows) {
                return false;
            }
        }

        return true;
    }

    protected function fetchSysFileAutoIncrementFromDatabase(Connection $databaseConnection): int
    {
        $statement = sprintf(
            'SHOW TABLE STATUS FROM `%s` WHERE name LIKE "sys_file";',
            $this->determineDatabaseOfConnection($databaseConnection)
        );

        try {
            $tableStatus = $databaseConnection->executeQuery($statement)->fetchAssociative();
        } catch (Throwable $exception) {
            throw new RuntimeException('Could not select table status from database', 1475242494, $exception);
        }
        if (!isset($tableStatus['Auto_increment'])) {
            throw new RuntimeException('Could not fetch Auto_increment value from query result', 1475242706);
        }

        return (int)$tableStatus['Auto_increment'];
    }
}
