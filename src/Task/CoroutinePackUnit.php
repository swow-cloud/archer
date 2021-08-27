<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SerendipitySwow\Archer\Task;

use SerendipitySwow\Archer\MultiTask;
use SerendipitySwow\Archer\Task;

class CoroutinePackUnit extends Task
{
    protected ?MultiTask $multiTask;

    public function __construct(callable $task_callback, ?array $params, MultiTask $multiTask)
    {
        parent::__construct($task_callback, $params);
        $this->multiTask = $multiTask;
    }

    public function process(): void
    {
        $results = null;
        $e = $this->call($results);
        if ($e !== null) {
            $this->multiTask->registerError($this->id, $e);
        } else {
            $this->multiTask->registerResult($this->id, $results);
        }

        $this->multiTask = null;
    }
}
