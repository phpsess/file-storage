<?php

declare(strict_types=1);

namespace PHPSess\Tests;

use PHPSess\Exception\UnableToFetchException;
use PHPSess\Exception\UnableToSetupStorageException;
use PHPSess\Storage\FileStorage;
use PHPSess\Exception\SessionNotFoundException;
use PHPSess\Exception\UnableToSaveException;
use PHPSess\Exception\UnableToDeleteException;

use Exception;
use ReflectionClass;

use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;

/**
 * @runTestsInSeparateProcesses
 */
final class FileStorageTest extends TestCase
{

    private const UNWRITABLE = 0444;

    private const UNREADABLE = 0222;

    public function setUp()
    {
        $path = vfsStream::setup('root', 0777, ['session' => []])->url();

        try {
            $reflection = new ReflectionClass(self::class);
        } catch (Exception $exception) {
            $this->fail('Not able to determine the test class name');
            return;
        }

        $class_name = $reflection->getShortName();

        $test_name = $this->getName();

        $session_path = "$path/session/$class_name-$test_name";

        ini_set('session.save_path', $session_path);

        parent::setUp();
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::setUpSessionPath
     */
    public function testUnwritableDirectory()
    {
        $session_path = session_save_path();

        mkdir($session_path, self::UNWRITABLE);

        $this->expectException(UnableToSetupStorageException::class);

        new FileStorage();
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::setUpSessionPath
     */
    public function testUnreadableDirectory()
    {
        $session_path = session_save_path();

        mkdir($session_path, self::UNREADABLE);

        $this->expectException(UnableToSetupStorageException::class);

        new FileStorage();
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::setUpSessionPath
     */
    public function testCantFigureOutPath()
    {
        ini_set('session.save_path', '');

        $this->expectException(UnableToSetupStorageException::class);

        new FileStorage();
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::setUpSessionPath
     */
    public function testNoPermissionToCreatePath()
    {
        $path = ini_get('session.save_path');

        $forbiddenPath = "$path/forbidden";

        mkdir($forbiddenPath, self::UNWRITABLE);

        $sessionPath = "$forbiddenPath/sessions";

        $this->expectException(UnableToSetupStorageException::class);

        new FileStorage($sessionPath);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::__construct
     * @covers \PHPSess\Storage\FileStorage::setUpSessionPath
     */
    public function testNoErrorThrownIncontroller()
    {
        $exception = null;
        try {
            new FileStorage();
        } catch (Exception $exception) {
        }

        $this->assertNull($exception);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::get
     * @covers \PHPSess\Storage\FileStorage::getFileName
     */
    public function testThrowErrorWhenCantGetSessionFile()
    {
        $storage = new FileStorage('', 'ssess_');

        $identifier = $this->getName();

        $storage->save($identifier, 'data');

        $session_path = session_save_path();

        chmod("$session_path/ssess_$identifier", self::UNREADABLE);

        $this->expectException(UnableToFetchException::class);

        $storage->get($identifier);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::save
     */
    public function testUnableToSave()
    {
        $session_path = session_save_path();

        $file_storage = new FileStorage();

        chmod($session_path, self::UNWRITABLE);

        $this->expectException(UnableToSaveException::class);

        $file_storage->save('aSessionIdentifier', 'someData');
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::destroy
     */
    public function testUnableToDestroy()
    {
        $session_path = session_save_path();

        $identifier = 'aSessionIdentifier';

        $file_storage = new FileStorage();

        $file_storage->save($identifier, 'someData');

        chmod($session_path, 0555);

        $this->expectException(UnableToDeleteException::class);

        $file_storage->destroy($identifier);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::destroy
     */
    public function testThrowErrorDestroyingInexistentSession()
    {
        $file_storage = new FileStorage();

        $identifier = $this->getName();

        $this->expectException(SessionNotFoundException::class);

        $file_storage->destroy($identifier);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::save
     * @covers \PHPSess\Storage\FileStorage::get
     */
    public function testSaveThenGet()
    {
        $file_storage = new FileStorage();

        $identifier = $this->getName();

        $data = 'test_data';

        $file_storage->save($identifier, $data);

        $saved_data = $file_storage->get($identifier);

        $this->assertEquals($data, $saved_data);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::get
     */
    public function testGetWithDifferentInstance()
    {
        $file_storage = new FileStorage();

        $identifier = $this->getName();

        $data = 'test_data';

        $file_storage->save($identifier, $data);

        $new_file_storage = new FileStorage();

        $saved_data = $new_file_storage->get($identifier);

        $this->assertEquals($data, $saved_data);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::get
     */
    public function testGetInexistent()
    {
        $file_storage = new FileStorage();

        $identifier = $this->getName();

        $this->expectException(SessionNotFoundException::class);

        $file_storage->get($identifier);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::sessionExists
     */
    public function testExists()
    {
        $file_storage = new FileStorage();

        $identifier = $this->getName();

        $exists = $file_storage->sessionExists($identifier);

        $this->assertFalse($exists);

        $file_storage->save($identifier, 'test');

        $exists = $file_storage->sessionExists($identifier);

        $this->assertTrue($exists);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::destroy
     */
    public function testDestroy()
    {
        $file_storage = new FileStorage();

        $identifier = $this->getName();

        $file_storage->save($identifier, 'test');

        $exists = $file_storage->sessionExists($identifier);

        $this->assertTrue($exists);

        $file_storage->destroy($identifier);

        $exists = $file_storage->sessionExists($identifier);

        $this->assertFalse($exists);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::clearOld
     * @covers \PHPSess\Storage\FileStorage::shouldBeCleared
     */
    public function testClearOld()
    {
        $file_storage = new FileStorage();

        $identifier = $this->getName();

        $file_storage->save($identifier, 'test');

        usleep(1000); // 1 millisecond

        $exists = $file_storage->sessionExists($identifier);

        $this->assertTrue($exists);

        $file_storage->clearOld(10);

        $exists = $file_storage->sessionExists($identifier);

        $this->assertFalse($exists);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::clearOld
     * @covers \PHPSess\Storage\FileStorage::shouldBeCleared
     */
    public function testDoNotClearNew()
    {
        $file_storage = new FileStorage();

        $identifier = $this->getName();

        $file_storage->save($identifier, 'test');

        $exists = $file_storage->sessionExists($identifier);

        $this->assertTrue($exists);

        $file_storage->clearOld(1000000); // one second

        $exists = $file_storage->sessionExists($identifier);

        $this->assertTrue($exists);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::clearOld
     * @covers \PHPSess\Storage\FileStorage::shouldBeCleared
     */
    public function testNoPermissionToScanToClear()
    {
        $path = ini_get('session.save_path');

        $fileStorage = new FileStorage();

        $identifier = $this->getName();

        $fileStorage->save($identifier, 'test data');

        chmod($path, self::UNWRITABLE);

        $this->expectException(UnableToDeleteException::class);

        $fileStorage->clearOld(0);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::clearOld
     * @covers \PHPSess\Storage\FileStorage::shouldBeCleared
     */
    public function testNoPermissionToClear()
    {
        $path = ini_get('session.save_path');

        $fileStorage = new FileStorage();

        chmod($path, 0111);

        $this->expectException(UnableToFetchException::class);

        $fileStorage->clearOld(0);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::clearOld
     * @covers \PHPSess\Storage\FileStorage::shouldBeCleared
     */
    public function testNoPermissionToReadFileWhenClearing()
    {
        $path = ini_get('session.save_path');

        $fileStorage = new FileStorage('', 'ssess_');

        $identifier = $this->getName();

        $fileStorage->save($identifier, 'test data');

        chmod("$path/ssess_$identifier", self::UNREADABLE);

        $this->expectException(UnableToFetchException::class);

        $fileStorage->clearOld(0);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::clearOld
     * @covers \PHPSess\Storage\FileStorage::shouldBeCleared
     */
    public function testDoNotTryToClearDirectory()
    {
        $path = ini_get('session.save_path');

        $fileStorage = new FileStorage('', 'ssess_');

        mkdir("$path/ssess_random_dir");

        $exception = null;
        try {
            $fileStorage->clearOld(0);
        } catch (Exception $exception) {
        }

        $this->assertNull($exception);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::lock
     * @covers \PHPSess\Storage\FileStorage::getLock
     */
    public function testCanLockOnce()
    {
        $storage = new FileStorage();

        $identifier = $this->getName();

        $locked = $storage->lock($identifier);

        $this->assertTrue($locked);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::lock
     * @covers \PHPSess\Storage\FileStorage::getLock
     */
    public function testLockingTwiceIsOk()
    {
        $storage = new FileStorage();

        $identifier = $this->getName();

        $storage->lock($identifier);

        $locked = $storage->lock($identifier);

        $this->assertTrue($locked);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::lock
     * @covers \PHPSess\Storage\FileStorage::unlock
     * @covers \PHPSess\Storage\FileStorage::getLock
     */
    public function testCanLockUnlockAndLockAgain()
    {
        $storage = new FileStorage();

        $identifier = $this->getName();

        $storage->lock($identifier);

        $storage->unlock($identifier);

        $locked = $storage->lock($identifier);

        $this->assertTrue($locked);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::unlock
     * @covers \PHPSess\Storage\FileStorage::getLock
     */
    public function testUnlockInexistentThrowNoErrors()
    {
        $storage = new FileStorage();

        $identifier = $this->getName();

        $exception = null;
        try {
            $storage->unlock($identifier);
        } catch (Exception $exception) {
        }

        $this->assertNull($exception);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage::lock
     * @covers \PHPSess\Storage\FileStorage::getLock
     */
    public function testCantLockUnreadableDirectory()
    {
        $storage = new FileStorage('', 'ssess_');

        $identifier = $this->getName();

        $storage->save($identifier, 'data');

        $save_path = ini_get('session.save_path');

        chmod("$save_path/ssess_$identifier", self::UNREADABLE);

        $locked = $storage->lock($identifier);

        $this->assertFalse($locked);
    }
}
