<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Service\Database;

/*
 * Copyright notice
 *
 * (c) 2021 in2code.de and the following authors:
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

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_key_exists;
use function count;
use function in_array;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function sha1;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;
use function trim;

class SimpleWhereClauseParsingService implements SingletonInterface
{
    protected $cache = [];

    /**
     * Indicates if something in the cache has changed and that it should be persisted at the end of the runtime
     *
     * @var bool
     */
    protected $cacheChanged = false;

    public function __construct()
    {
        $cachingInstance = GeneralUtility::makeInstance(CacheManager::class)->getCache('in2publish_core');
        if ($cachingInstance->has('where_clause_parser')) {
            $this->cache = $cachingInstance->get('where_clause_parser');
        }
    }

    public function __destruct()
    {
        if ($this->cacheChanged) {
            GeneralUtility::makeInstance(CacheManager::class)->getCache('in2publish_core')->set(
                'where_clause_parser',
                $this->cache
            );
        }
    }

    public function parseToPropertyArray(string $where, string $table): ?array
    {
        $where = strtolower(trim($where));
        $cacheKey = $table . '.' . sha1($where);
        return array_key_exists($cacheKey, $this->cache)
            ? $this->cache[$cacheKey]
            : $this->cache[$cacheKey] = $this->actualParseWhereClause($where, $table);
    }

    /**
     * Splits the where clause into simple, understandable parts.
     * If there is anything in the query that does not even "feel" right,
     * the query will not be parsed and null is returned.
     *
     * @param string $where
     * @param string $table
     * @return array|null An array of properties this query would match or null, if the query is too complex
     */
    protected function actualParseWhereClause(string $where, string $table): ?array
    {
        $this->cacheChanged = true;
        if (empty($where)) {
            return [];
        }
        // Remove all newlines
        $where = str_replace(["\r", "\n"], ['', ''], $where);
        // Unescape all `table`.`field` clauses and add force spaces around the '=' or 'like' (if there is one)
        $where = preg_replace('/\s\`([\w_]+)\`\.\`([\w_]+)\`\s?(=|like)?\s?/', ' $1.$2 $3 ', $where, -1, $count);
        // Remove the table prefix table.field if the table is the one that would be queried, force spaces again
        $where = preg_replace('/\s' . preg_quote($table, '/') . '\.([\w]+)\s?(=|like)?\s?/', ' $1 $2 ', $where);
        // Return if there are still some unsupported characters
        if (1 === preg_match('/(?:\(|%| or |\.| join |\`| order )/', $where)) {
            return null;
        }
        if (0 === strpos($where, 'and')) {
            $where = trim(substr($where, 3));
        }
        $properties = [];
        $statements = GeneralUtility::trimExplode(' and ', $where, true);
        foreach ($statements as $statement) {
            $statementParts = GeneralUtility::trimExplode(' ', $statement, true);
            // If there are no 3 parts than the query is probably broken or more complex than anticipated. Quit here.
            if (count($statementParts) !== 3 || !in_array($statementParts[1], ['=', 'like'])) {
                return null;
            }
            $properties[$statementParts[0]] = strtolower(trim($statementParts[2], '"\'`'));
        }
        return $properties;
    }
}
