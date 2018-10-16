<?php

declare(strict_types=1);

namespace PHPSess\Storage;

use PHPSess\Interfaces\StorageInterface;
use PHPSess\Storage\FileStorage\SessionContent;
use PHPSess\Exception\BadSessionContentException;
use PHPSess\Exception\UnableToSetupStorageException;
use PHPSess\Exception\SessionNotFoundException;
use PHPSess\Exception\UnableToDeleteException;
use PHPSess\Exception\UnableToFetchException;
use PHPSess\Exception\UnableToSaveException;
use TH\Lock\FileLock;
use Exception;

/**
 * Uses the filesystem to store the session data.
 *
 * @package PHPSess\Storage
 * @author  Ayrton Fidelis <ayrton.vargas33@gmail.com>
 */
class FileStorage implements StorageInterface
{

    /**
     * @var string $filePrefix The prefix used in the session file name.
     */
    private $filePrefix;

    /**
     * @var string $filePath The absolute path where the session files are saved.
     */
    private $filePath;

    /**
     * @var FileLock[] $locks The locks to the session files
     */
    private static $locks;

    /**
     * FileStorage constructor.
     *
     * @throws UnableToSetupStorageException
     * @param  string|null $path        The absolute path to the session files directory. If not set, defaults to INI session.save_path.
     * @param  string      $filePrefix  The prefix used in the session file name.
     */
    public function __construct(?string $path = null, string $filePrefix = 'ssess_')
    {
        if (!$path) {
            $path = (string) ini_get('session.save_path');
        }

        $this->setUpSessionPath($path);

        $this->filePath = $path;
        $this->filePrefix = $filePrefix;
    }

    /**
     * @throws UnableToSetupStorageException
     * @param string $path
     * @return void
     */
    private function setUpSessionPath(string $path): void
    {
        if (!$path) {
            $errorMessage = 'The session path could not be determined. Either pass it as the first ' .
                'parameter to the Storage Driver constructor or define it in the ini setting session.save_path.';
            throw new UnableToSetupStorageException($errorMessage);
        }

        if (!file_exists($path) && !@mkdir($path, 0777)) {
            $errorMessage = 'The session path does not exist and could not be created. This may be a permission issue.';
            throw new UnableToSetupStorageException($errorMessage);
        }

        if (!is_readable($path)) {
            $errorMessage = 'The session path is not readable. This is likely a permission issue.';
            throw new UnableToSetupStorageException($errorMessage);
        }

        if (!is_writable($path)) {
            $errorMessage = 'The session path is not writable. This is likely a permission issue.';
            throw new UnableToSetupStorageException($errorMessage);
        }
    }

    /**
     * Saves the encrypted session data to the storage.
     *
     * @throws UnableToSaveException
     * @param  string $sessionIdentifier The string used to identify the session data.
     * @param  string $sessionData       The encrypted session data.
     * @return void
     */
    public function save(string $sessionIdentifier, string $sessionData): void
    {
        $fileName = $this->getFileName($sessionIdentifier);

        $contents = new SessionContent();
        $contents->setData($sessionData);

        if (@file_put_contents($fileName, $contents->toString()) === false) {
            $errorMessage = 'Unable to save the session file to the file-system. This may be a permission issue.';
            throw new UnableToSaveException($errorMessage);
        }
    }

    /**
     * Fetches the encrypted session data based on the session identifier.
     *
     * @throws SessionNotFoundException
     * @throws UnableToFetchException
     * @throws BadSessionContentException
     * @param  string $sessionIdentifier The session identifier
     * @return string The encrypted session data
     */
    public function get(string $sessionIdentifier): string
    {
        $fileName = $this->getFileName($sessionIdentifier);

        if (!$this->sessionExists($sessionIdentifier)) {
            throw new SessionNotFoundException();
        }

        $contents = @file_get_contents($fileName);
        if ($contents === false) {
            $errorMessage = 'Unable to get the session file from the file-system. This may be a permission issue.';
            throw new UnableToFetchException($errorMessage);
        }

        $session = new SessionContent();
        $session->parse($contents);

        return $session->getData();
    }

    /**
     * Asks the drive to lock the session storage
     *
     * @param string $sessionIdentifier The session identifier to be locked
     * @return bool Whether the session could be locked or not
     */
    public function lock(string $sessionIdentifier): bool
    {
        $lock = $this->getLock($sessionIdentifier);

        try {
            $lock->acquire();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Asks the drive to unlock the session storage
     *
     * @param string $sessionIdentifier The session identifier to be unlocked
     * @return void
     */
    public function unlock(string $sessionIdentifier): void
    {
        $lock = $this->getLock($sessionIdentifier);

        try {
            $lock->release();
        } catch (Exception $e) {
        }
    }

    /**
     * Gets the lock to the session file.
     *
     * If the lock don't exist, creates it.
     *
     * @param string $sessionIdentifier The session identifier
     * @return FileLock
     */
    private function getLock(string $sessionIdentifier): FileLock
    {
        if (!isset(self::$locks[$sessionIdentifier])) {
            $fileName = $this->getFileName($sessionIdentifier);
            self::$locks[$sessionIdentifier] = new FileLock($fileName);
        }

        return self::$locks[$sessionIdentifier];
    }

    /**
     * Checks if a session with the given identifier exists in the storage.
     *
     * @param  string $sessionIdentifier The session identifier.
     * @return boolean Whether the session exists or not.
     */
    public function sessionExists(string $sessionIdentifier): bool
    {
        $fileName = $this->getFileName($sessionIdentifier);

        clearstatcache(true, $fileName);

        return file_exists($fileName);
    }

    /**
     * Remove this session from the storage.
     *
     * @throws SessionNotFoundException
     * @throws UnableToDeleteException
     * @param  string $sessionIdentifier The session identifier.
     * @return void
     */
    public function destroy(string $sessionIdentifier): void
    {
        if (!$this->sessionExists($sessionIdentifier)) {
            $errorMessage = 'The session you are trying to destroy does not exist.';
            throw new SessionNotFoundException($errorMessage);
        }

        $fileName = $this->getFileName($sessionIdentifier);

        if (!@unlink($fileName)) {
            $errorMessage = 'The session file could not be deleted. This may be a permission issue.';
            throw new UnableToDeleteException($errorMessage);
        }

        clearstatcache(true, $fileName);
    }

    /**
     * Removes the session older than the specified time from the storage.
     *
     * @throws UnableToDeleteException
     * @throws UnableToFetchException
     * @throws BadSessionContentException
     * @param  int $maxLife The maximum time (in microseconds) that a session file must be kept.
     * @return void
     */
    public function clearOld(int $maxLife): void
    {
        $files = $this->getFilesInSessionPath();

        $limitTime = microtime(true) - $maxLife / 1000000;

        $hasError = false;
        foreach ($files as $file) {
            $fullPath = "$this->filePath/$file";

            if (!$this->shouldBeCleared($fullPath, $file, $this->filePrefix, $limitTime)) {
                continue;
            }

            if (!@unlink("$this->filePath/$file")) {
                $hasError = true;
            }

            clearstatcache(true, $fullPath);
        }

        if ($hasError) {
            $errorMessage = 'Could not delete a session file. This is likely a permission issue.';
            throw new UnableToDeleteException($errorMessage);
        }
    }

    /**
     * @throws UnableToFetchException
     * @return iterable
     */
    private function getFilesInSessionPath(): iterable
    {
        $files = @scandir($this->filePath);

        if ($files === false) {
            $errorMessage = 'Could not read the session path to determine the old session files. ' .
                'This may be a permission issue.';
            throw new UnableToFetchException($errorMessage);
        }

        return $files;
    }

    /**
     * Checks whether a file should be removed by clearOld or not
     *
     * @throws UnableToFetchException
     * @throws BadSessionContentException
     * @param  string $fullPath  The absolute path to the file
     * @param  string $fileName  Only the name of the file
     * @param  string $prefix    The prefix of the session files
     * @param  float  $limitTime The maximum timestamp (in microseconds) a file can be kept
     * @return bool If the file should be cleared or not
     */
    private function shouldBeCleared(string $fullPath, string $fileName, string $prefix, float $limitTime): bool
    {
        if (strpos($fileName, $prefix) !== 0) {
            return false;
        }

        clearstatcache(true, $fullPath);

        if (!is_file($fullPath)) {
            return false;
        }

        $contents = @file_get_contents($fullPath);
        if ($contents === false) {
            $errorMessage = 'Could not read the session file content to determine if it should be cleared. ' .
                'This is likely a permission issue';
            throw new UnableToFetchException($errorMessage);
        }

        $session = new SessionContent();
        $session->parse($contents);

        return $session->getTime() <= $limitTime;
    }

    /**
     * Mounts the absolute file name.
     *
     * @param  string $sessionIdentifier The session identifier
     * @return string The absolute file name.
     */
    private function getFileName(string $sessionIdentifier): string
    {
        return $this->filePath . '/' . $this->filePrefix . $sessionIdentifier;
    }
}
