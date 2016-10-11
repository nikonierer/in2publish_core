<?php
namespace In2code\In2publishCore\Domain\Driver;

/***************************************************************
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
 ***************************************************************/

use In2code\In2publishCore\Domain\Driver\Rpc\Envelope;
use In2code\In2publishCore\Domain\Driver\Rpc\EnvelopeDispatcher;
use In2code\In2publishCore\Domain\Driver\Rpc\Letterbox;
use In2code\In2publishCore\Security\SshConnection;
use In2code\In2publishCore\Utility\DatabaseUtility;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Class RemoteFileAbstractionLayerDriver
 */
class RemoteFileAbstractionLayerDriver extends AbstractHierarchicalFilesystemDriver implements
    DriverInterface,
    SingletonInterface
{
    /**
     * @var SshConnection
     */
    protected $sshConnection = null;

    /**
     * @var Letterbox
     */
    protected $letterBox = null;

    /**
     * @var array
     */
    protected $remoteDriverSettings = array();

    /**
     * Maybe most important property in this class, since sending envelopes is very costly
     *
     * @var array
     */
    protected $cache = array();

    /**
     * RemoteFileAbstractionLayerDriver constructor.
     * @param array $configuration
     */
    public function __construct(array $configuration = array())
    {
        parent::__construct($configuration);
        $this->sshConnection = SshConnection::makeInstance();
        $this->letterBox = GeneralUtility::makeInstance('In2code\\In2publishCore\\Domain\\Driver\\Rpc\\Letterbox');
    }

    /**
     * Never called
     *
     * @return void
     */
    public function processConfiguration()
    {
    }

    /**
     * Sets the storage uid the driver belongs to
     *
     * @param int $storageUid
     * @return void
     */
    public function setStorageUid($storageUid)
    {
        $this->storageUid = $storageUid;
    }

    /**
     * Initializes this object. This is called by the storage after the driver has been attached.
     *
     * @return void
     */
    public function initialize()
    {
        $this->remoteDriverSettings = DatabaseUtility::buildForeignDatabaseConnection()->exec_SELECTgetSingleRow(
            '*',
            'sys_file_storage',
            'uid=' . (int)$this->storageUid
        );
        if (!is_array($this->remoteDriverSettings)) {
            throw new \LogicException('Could not find the remote storage.', 1474470724);
        }
        $flexFormService = GeneralUtility::makeInstance('TYPO3\\CMS\\Extbase\\Service\\FlexFormService');
        $this->configuration = $flexFormService->convertFlexFormContentToArray(
            $this->remoteDriverSettings['configuration']
        );
    }

    /**
     * Not required
     *
     * @param int $capabilities
     * @return int
     */
    public function mergeConfigurationCapabilities($capabilities)
    {
    }

    /**
     * Not required
     *
     * @param int $capability
     * @return bool
     */
    public function hasCapability($capability)
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isCaseSensitiveFileSystem()
    {
        return $this->configuration['caseSensitive'];
    }

    /**
     * Not required
     *
     * @return string
     */
    public function getRootLevelFolder()
    {
        return '/';
    }

    /**
     * Not required
     *
     * @return string
     */
    public function getDefaultFolder()
    {
    }

    /**
     * Returns the public URL to a file.
     * Either fully qualified URL or relative to PATH_site (rawurlencoded).
     *
     * @param string $identifier
     * @return string
     */
    public function getPublicUrl($identifier)
    {
        xdebug_break();
    }

    /**
     * Creates a folder, within a parent folder.
     * If no parent folder is given, a root level folder will be created
     *
     * @param string $newFolderName
     * @param string $parentFolderIdentifier
     * @param bool $recursive
     * @return string the Identifier of the new folder
     */
    public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = false)
    {
        xdebug_break();
    }

    /**
     * Renames a folder in this storage.
     *
     * @param string $folderIdentifier
     * @param string $newName
     * @return array A map of old to new file identifiers of all affected resources
     */
    public function renameFolder($folderIdentifier, $newName)
    {
        xdebug_break();
    }

    /**
     * Removes a folder in filesystem.
     *
     * @param string $folderIdentifier
     * @param bool $deleteRecursively
     * @return bool
     */
    public function deleteFolder($folderIdentifier, $deleteRecursively = false)
    {
        xdebug_break();
    }

    /**
     * Checks if a file exists.
     *
     * @param string $fileIdentifier
     * @return bool
     */
    public function fileExists($fileIdentifier)
    {
        $callback = function () use ($fileIdentifier) {
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_FILE_EXISTS,
                    array('storage' => $this->storageUid, 'fileIdentifier' => $fileIdentifier)
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "fileExists" request to remote system', 1475058957);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };

        return $this->cache(__FUNCTION__ . $fileIdentifier, $callback);
    }

    /**
     * Checks if a folder exists.
     *
     * @param string $folderIdentifier
     * @throws \Exception
     * @return bool
     */
    public function folderExists($folderIdentifier)
    {
        $callback = function () use ($folderIdentifier) {
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_FOLDER_EXISTS,
                    array('storage' => $this->storageUid, 'folderIdentifier' => $folderIdentifier)
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "folderExists" request to remote system', 1474458299);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };

        return $this->cache($this->getFolderExistsCacheIdentifier($folderIdentifier), $callback);
    }

    /**
     * Checks if a folder contains files and (if supported) other folders.
     *
     * @param string $folderIdentifier
     * @return bool TRUE if there are no files and folders within $folder
     */
    public function isFolderEmpty($folderIdentifier)
    {
        xdebug_break();
    }

    /**
     * Adds a file from the local server hard disk to a given path in TYPO3s
     * virtual file system. This assumes that the local file exists, so no
     * further check is done here! After a successful the original file must
     * not exist anymore.
     *
     * @param string $localFilePath (within PATH_site)
     * @param string $targetFolderIdentifier
     * @param string $newFileName optional, if not given original name is used
     * @param bool $removeOriginal if set the original file will be removed
     *                                after successful operation
     * @return string the identifier of the new file
     */
    public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = true)
    {
        $callback = function () use ($localFilePath, $targetFolderIdentifier, $newFileName, $removeOriginal) {
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_ADD_FILE,
                    array(
                        'storage' => $this->storageUid,
                        'localFilePath' => $localFilePath,
                        'targetFolderIdentifier' => $targetFolderIdentifier,
                        'newFileName' => $newFileName,
                        'removeOriginal' => $removeOriginal,
                    )
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "addFile" request to remote system', 1475932227);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };
        return $this->cache('addFile' . $localFilePath . '|' . $targetFolderIdentifier . '|' . $newFileName, $callback);
    }

    /**
     * Creates a new (empty) file and returns the identifier.
     *
     * @param string $fileName
     * @param string $parentFolderIdentifier
     * @return string
     */
    public function createFile($fileName, $parentFolderIdentifier)
    {
        xdebug_break();
    }

    /**
     * Copies a file *within* the current storage.
     * Note that this is only about an inner storage copy action,
     * where a file is just copied to another folder in the same storage.
     *
     * @param string $fileIdentifier
     * @param string $targetFolderIdentifier
     * @param string $fileName
     * @return string the Identifier of the new file
     */
    public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName)
    {
        xdebug_break();
    }

    /**
     * Renames a file in this storage.
     *
     * @param string $fileIdentifier
     * @param string $newName The target path (including the file name!)
     * @return string The identifier of the file after renaming
     */
    public function renameFile($fileIdentifier, $newName)
    {
        $callback = function () use ($fileIdentifier, $newName) {
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_RENAME_FILE,
                    array(
                        'storage' => $this->storageUid,
                        'fileIdentifier' => $fileIdentifier,
                        'newName' => $newName,
                    )
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "renameFile" request to remote system', 1475932033);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };

        return $this->cache(__FUNCTION__ . $fileIdentifier . '|' . $newName, $callback);
    }

    /**
     * Replaces a file with file in local file system.
     *
     * @param string $fileIdentifier
     * @param string $localFilePath
     * @return bool TRUE if the operation succeeded
     */
    public function replaceFile($fileIdentifier, $localFilePath)
    {
        $callback = function () use ($fileIdentifier, $localFilePath) {
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_REPLACE_FILE,
                    array(
                        'storage' => $this->storageUid,
                        'fileIdentifier' => $fileIdentifier,
                        'localFilePath' => $localFilePath,
                    )
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "replaceFile" request to remote system', 1475930835);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };

        return $this->cache(__FUNCTION__ . $fileIdentifier . '|' . $localFilePath, $callback);
    }

    /**
     * Removes a file from the filesystem. This does not check if the file is
     * still used or if it is a bad idea to delete it for some other reason
     * this has to be taken care of in the upper layers (e.g. the Storage)!
     *
     * @param string $fileIdentifier
     * @return bool TRUE if deleting the file succeeded
     */
    public function deleteFile($fileIdentifier)
    {
        $callback = function () use ($fileIdentifier) {
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_DELETE_FILE,
                    array('storage' => $this->storageUid, 'fileIdentifier' => $fileIdentifier)
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "deleteFile" request to remote system', 1475930502);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };

        return $this->cache(__FUNCTION__ . $fileIdentifier, $callback);
    }

    /**
     * Creates a hash for a file.
     *
     * @param string $fileIdentifier
     * @param string $hashAlgorithm The hash algorithm to use
     * @return string
     */
    public function hash($fileIdentifier, $hashAlgorithm)
    {
        $callback = function () use ($fileIdentifier, $hashAlgorithm) {
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_GET_HASH,
                    array(
                        'storage' => $this->storageUid,
                        'identifier' => $fileIdentifier,
                        'hashAlgorithm' => $hashAlgorithm,
                    )
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "hash" request to remote system', 1475229789);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };

        return $this->cache(__FUNCTION__ . $fileIdentifier, $callback);
    }

    /**
     * Moves a file *within* the current storage.
     * Note that this is only about an inner-storage move action,
     * where a file is just moved to another folder in the same storage.
     *
     * @param string $fileIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFileName
     * @return string
     */
    public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName)
    {
        xdebug_break();
    }

    /**
     * Folder equivalent to moveFileWithinStorage().
     *
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     * @return array All files which are affected, map of old => new file identifiers
     */
    public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        xdebug_break();
    }

    /**
     * Folder equivalent to copyFileWithinStorage().
     *
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     * @return bool
     */
    public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        xdebug_break();
    }

    /**
     * Returns the contents of a file. Beware that this requires to load the
     * complete file into memory and also may require fetching the file from an
     * external location. So this might be an expensive operation (both in terms
     * of processing resources and money) for large files.
     *
     * @param string $fileIdentifier
     * @return string The file contents
     */
    public function getFileContents($fileIdentifier)
    {
        xdebug_break();
    }

    /**
     * Sets the contents of a file to the specified value.
     *
     * @param string $fileIdentifier
     * @param string $contents
     * @return int The number of bytes written to the file
     */
    public function setFileContents($fileIdentifier, $contents)
    {
        xdebug_break();
    }

    /**
     * Checks if a file inside a folder exists
     *
     * @param string $fileName
     * @param string $folderIdentifier
     * @return bool
     */
    public function fileExistsInFolder($fileName, $folderIdentifier)
    {
        xdebug_break();
    }

    /**
     * Checks if a folder inside a folder exists.
     *
     * @param string $folderName
     * @param string $folderIdentifier
     * @return bool
     */
    public function folderExistsInFolder($folderName, $folderIdentifier)
    {
        xdebug_break();
    }

    /**
     * Returns a path to a local copy of a file for processing it. When changing the
     * file, you have to take care of replacing the current version yourself!
     *
     * @param string $fileIdentifier
     * @param bool $writable Set this to FALSE if you only need the file for read
     *                       operations. This might speed up things, e.g. by using
     *                       a cached local version. Never modify the file if you
     *                       have set this flag!
     * @return string The path to the file on the local disk
     */
    public function getFileForLocalProcessing($fileIdentifier, $writable = true)
    {
        xdebug_break();
    }

    /**
     *
     * Returns the permissions of a file/folder as an array
     * (keys r, w) of boolean flags
     *
     * @param string $identifier
     * @return array
     *
     * @return string
     * @throws \Exception
     */
    public function getPermissions($identifier)
    {
        $callback = function () use ($identifier) {
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_GET_PERMISSIONS,
                    array('storage' => $this->storageUid, 'identifier' => $identifier)
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "getPermissions" request to remote system', 1474460823);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };

        return $this->cache(__FUNCTION__ . $identifier, $callback);
    }

    /**
     * Directly output the contents of the file to the output
     * buffer. Should not take care of header files or flushing
     * buffer before. Will be taken care of by the Storage.
     *
     * @param string $identifier
     * @return void
     */
    public function dumpFileContents($identifier)
    {
        xdebug_break();
    }

    /**
     * Checks if a given identifier is within a container, e.g. if
     * a file or folder is within another folder.
     * This can e.g. be used to check for web-mounts.
     *
     * Hint: this also needs to return TRUE if the given identifier
     * matches the container identifier to allow access to the root
     * folder of a filemount.
     *
     * @param string $folderIdentifier
     * @param string $identifier identifier to be checked against $folderIdentifier
     * @return bool TRUE if $content is within or matches $folderIdentifier
     */
    public function isWithin($folderIdentifier, $identifier)
    {
        xdebug_break();
    }

    /**
     * Returns information about a file.
     *
     * @param string $fileIdentifier
     * @param array $propertiesToExtract Array of properties which are be extracted
     *                                   If empty all will be extracted
     * @return array
     */
    public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = array())
    {
        $callback = function () use ($fileIdentifier, $propertiesToExtract) {
            if (!$this->fileExists($fileIdentifier)) {
                throw new \InvalidArgumentException('File ' . $fileIdentifier . ' does not exist.', 1476199721);
            } else {
                $uid = $this->letterBox->sendEnvelope(
                    new Envelope(
                        EnvelopeDispatcher::CMD_GET_FILE_INFO_BY_IDENTIFIER,
                        array(
                            'storage' => $this->storageUid,
                            'fileIdentifier' => $fileIdentifier,
                            'propertiesToExtract' => $propertiesToExtract,
                        )
                    )
                );

                if (false === $uid) {
                    throw new \Exception(
                        'Could not send "getFileInfoByIdentifier" request to remote system',
                        1474460823
                    );
                }

                return $this->executeEnvelopeAndReceiveResponse($uid);
            }
        };

        return $this->cache(__FUNCTION__ . $fileIdentifier, $callback);
    }

    /**
     * Returns information about a file.
     *
     * @param string $folderIdentifier
     * @return array
     *
     * @throws FolderDoesNotExistException
     */
    public function getFolderInfoByIdentifier($folderIdentifier)
    {
        $callback = function () use ($folderIdentifier) {
            $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

            if (!$this->folderExists($folderIdentifier)) {
                throw new FolderDoesNotExistException(
                    'Folder "' . $folderIdentifier . '" does not exist.',
                    1314516810
                );
            }
            return array(
                'identifier' => $folderIdentifier,
                'name' => PathUtility::basename($folderIdentifier),
                'storage' => $this->storageUid,
            );
        };

        return $this->cache(__FUNCTION__ . $folderIdentifier, $callback);
    }

    /**
     * Returns the identifier of a file inside the folder
     *
     * @param string $fileName
     * @param string $folderIdentifier
     * @return string file identifier
     */
    public function getFileInFolder($fileName, $folderIdentifier)
    {
        xdebug_break();
    }

    /**
     * Returns a list of files inside the specified path
     *
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $filenameFilterCallbacks callbacks for filtering the items
     * @param string $sort Property name used to sort the items.
     *                     Among them may be: '' (empty, no sorting), name,
     *                     fileext, size, tstamp and rw.
     *                     If a driver does not support the given property, it
     *                     should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     * @return array of FileIdentifiers
     */
    public function getFilesInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $filenameFilterCallbacks = array(),
        $sort = '',
        $sortRev = false
    ) {
        $callback = function () use (
            $folderIdentifier,
            $start,
            $numberOfItems,
            $recursive,
            $filenameFilterCallbacks,
            $sort,
            $sortRev
        ) {
            if (!$this->folderExists($folderIdentifier)) {
                throw new \InvalidArgumentException(
                    'Cannot list items in directory ' . $folderIdentifier . ' - does not exist or is no directory',
                    1475235331
                );
            }
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_GET_FILES_IN_FOLDER,
                    array(
                        'folderIdentifier' => $folderIdentifier,
                        'start' => $start,
                        'numberOfItems' => $numberOfItems,
                        'recursive' => $recursive,
                        'filenameFilterCallbacks' => $filenameFilterCallbacks,
                        'sort' => $sort,
                        'sortRev' => $sortRev,
                        'storage' => $this->storageUid,
                    )
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "getFilesInFolder" request to remote system', 1475229150);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };

        return $this->cache($this->getGetFilesInFolderCacheIdentifier(func_get_args()), $callback);
    }

    /**
     * Returns the identifier of a folder inside the folder
     *
     * @param string $folderName The name of the target folder
     * @param string $folderIdentifier
     * @return string folder identifier
     */
    public function getFolderInFolder($folderName, $folderIdentifier)
    {
        xdebug_break();
    }

    /**
     * Returns a list of folders inside the specified path
     *
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks callbacks for filtering the items
     * @param string $sort Property name used to sort the items.
     *                     Among them may be: '' (empty, no sorting), name,
     *                     fileext, size, tstamp and rw.
     *                     If a driver does not support the given property, it
     *                     should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     * @return array of Folder Identifier
     */
    public function getFoldersInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $folderNameFilterCallbacks = array(),
        $sort = '',
        $sortRev = false
    ) {
        $callback = function () use (
            $folderIdentifier,
            $start,
            $numberOfItems,
            $recursive,
            $folderNameFilterCallbacks,
            $sort,
            $sortRev
        ) {
            $uid = $this->letterBox->sendEnvelope(
                new Envelope(
                    EnvelopeDispatcher::CMD_GET_FOLDERS_IN_FOLDER,
                    array(
                        'folderIdentifier' => $folderIdentifier,
                        'start' => $start,
                        'numberOfItems' => $numberOfItems,
                        'recursive' => $recursive,
                        'folderNameFilterCallbacks' => $folderNameFilterCallbacks,
                        'sort' => $sort,
                        'sortRev' => $sortRev,
                        'storage' => $this->storageUid,
                    )
                )
            );

            if (false === $uid) {
                throw new \Exception('Could not send "getFoldersInFolder" request to remote system', 1474475092);
            }

            return $this->executeEnvelopeAndReceiveResponse($uid);
        };

        return $this->cache($this->getGetFoldersInFolderCacheIdentifier($folderIdentifier), $callback);
    }

    /**
     * Returns the number of files inside the specified path
     *
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $filenameFilterCallbacks callbacks for filtering the items
     * @return int Number of files in folder
     */
    public function countFilesInFolder($folderIdentifier, $recursive = false, array $filenameFilterCallbacks = array())
    {
        xdebug_break();
    }

    /**
     * Returns the number of folders inside the specified path
     *
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks callbacks for filtering the items
     * @return int Number of folders in folder
     */
    public function countFoldersInFolder(
        $folderIdentifier,
        $recursive = false,
        array $folderNameFilterCallbacks = array()
    ) {
        xdebug_break();
    }

    /**
     * @param int $uid
     * @return array
     */
    protected function executeEnvelopeAndReceiveResponse($uid)
    {
        $this->sshConnection->executeRpc($uid);
        return $this->letterBox->receiveEnvelope($uid)->getResponse();
    }

    /**
     * Callback cache proxy method. If the identifier's cache entry is not found it is generated by invoking the
     * callback and stored afterwards
     *
     * @param string $identifier
     * @param callable $callback
     * @return mixed
     */
    protected function cache($identifier, $callback)
    {
        if (!isset($this->cache[$identifier])) {
            $this->cache[$identifier] = $callback();
        }
        return $this->cache[$identifier];
    }

    /**
     * @param $folderIdentifier
     * @return string
     */
    protected function getFolderExistsCacheIdentifier($folderIdentifier)
    {
        return 'folderExists|' . $folderIdentifier;
    }

    /**
     * @param string $folderIdentifier
     * @return string
     */
    protected function getGetFoldersInFolderCacheIdentifier($folderIdentifier)
    {
        return 'getFoldersInFolder|' . $folderIdentifier;
    }

    /**
     * @param string $folderIdentifier
     * @return string
     */
    protected function getGetFilesInFolderCacheIdentifier($folderIdentifier)
    {
        return 'getFilesInFolder|' . $folderIdentifier;
    }
}
