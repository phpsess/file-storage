<?php

namespace PHPSess\Storage\FileStorage;

use PHPSess\Exception\BadSessionContentException;
use stdClass;

class SessionContent
{
    /**
     * @var string $data
     */
    private $data = '';

    /**
     * @var float $time
     */
    private $time;

    /**
     * SessionContent constructor.
     */
    public function __construct()
    {
        $this->time = microtime(true);
    }

    /**
     * @param string $data
     * @return void
     */
    public function setData(string $data): void
    {
        $this->data = $data;
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @param float $time
     * @return void
     */
    public function setTime(float $time): void
    {
        $this->time = $time;
    }

    /**
     * @return float
     */
    public function getTime(): float
    {
        return $this->time;
    }

    /**
     * @throws BadSessionContentException
     * @param string $content
     * @return void
     */
    public function parse(string $content): void
    {
        $content = json_decode($content);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = 'Could not parse the session file as JSON.';
            throw new BadSessionContentException($errorMessage);
        }

        $this->validate($content);

        $this->setData((string) $content->data);
        $this->setTime((float) $content->time);
    }

    /**
     * @throws BadSessionContentException
     * @param stdClass $content
     * @return void
     */
    private function validate(stdClass $content): void
    {
        if (!isset($content->data)) {
            $errorMessage = 'The session file content has no "data" field.';
            throw new BadSessionContentException($errorMessage);
        }

        if (!isset($content->time)) {
            $errorMessage = 'The session file content has no "time" field.';
            throw new BadSessionContentException($errorMessage);
        }

        if (!is_numeric($content->time)) {
            $errorMessage = 'The "time" field of the session file is not a microsecond timestamp.';
            throw new BadSessionContentException($errorMessage);
        }
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        $content = [
            'data' => $this->data,
            'time' => $this->time
        ];

        return (string) json_encode($content);
    }
}
