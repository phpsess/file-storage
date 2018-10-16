<?php

declare(strict_types=1);

namespace PHPSess\Tests;

use PHPSess\Exception\BadSessionContentException;
use PHPSess\Storage\FileStorage\SessionContent;

use PHPUnit\Framework\TestCase;
use Exception;

/**
 * @runTestsInSeparateProcesses
 */
final class SessionContentTest extends TestCase
{

    /**
     * @covers \PHPSess\Storage\FileStorage\SessionContent::__construct
     */
    public function testAutomaticallySetCurrentTime()
    {
        $session = new SessionContent();

        $time = $session->getTime();

        $this->assertGreaterThan(0, $time);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage\SessionContent::setData
     * @covers \PHPSess\Storage\FileStorage\SessionContent::getData
     */
    public function testSetThenGetData()
    {
        $session = new SessionContent();

        $set_data = 'test';

        $session->setData($set_data);

        $get_data = $session->getData();

        $this->assertEquals($set_data, $get_data);
    }


    /**
     * @covers \PHPSess\Storage\FileStorage\SessionContent::setTime
     * @covers \PHPSess\Storage\FileStorage\SessionContent::getTime
     */
    public function testSetThenGetTime()
    {
        $session = new SessionContent();

        $set_time = microtime(true);

        $session->setTime($set_time);

        $get_time = $session->getTime();

        $this->assertEquals($set_time, $get_time);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage\SessionContent::parse
     * @covers \PHPSess\Storage\FileStorage\SessionContent::toString
     * @covers \PHPSess\Storage\FileStorage\SessionContent::validate
     */
    public function testCanParseItself()
    {
        $session = new SessionContent();

        $data = $session->toString();

        $exception = null;
        try {
            $session->parse($data);
        } catch (Exception $exception) {
        }

        $this->assertNull($exception);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage\SessionContent::parse
     */
    public function testThrowExceptionWhenParsingBadJson()
    {
        $session = new SessionContent();

        $data = '{corrupted: json"';

        $this->expectException(BadSessionContentException::class);

        $session->parse($data);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage\SessionContent::validate
     */
    public function testThrowExceptionWhenTheresNoDataField()
    {
        $session = new SessionContent();

        $data = json_encode(['time' => 1]);

        $this->expectException(BadSessionContentException::class);

        $session->parse($data);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage\SessionContent::validate
     */
    public function testThrowExceptionWhenTheresNoTimeField()
    {
        $session = new SessionContent();

        $data = json_encode(['data' => 'test']);

        $this->expectException(BadSessionContentException::class);

        $session->parse($data);
    }

    /**
     * @covers \PHPSess\Storage\FileStorage\SessionContent::validate
     */
    public function testThrowExceptionWhenTimeIsNotMicrosecond()
    {
        $session = new SessionContent();

        $data = json_encode(['data' => 'test', 'time' => '18:12:56']);

        $this->expectException(BadSessionContentException::class);

        $session->parse($data);
    }
}
