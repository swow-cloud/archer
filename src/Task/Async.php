<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SerendipitySwow\Archer\Task;

use Closure;
use SerendipitySwow\Archer\Task;
use Throwable;

class Async extends Task
{
    protected ?Closure $finishFunc = null;

    public function __construct(callable $taskCallback, ?array $params = null, ?callable $finishFunc = null)
    {
        $this->finishFunc = $finishFunc;
        parent::__construct($taskCallback, $params);
    }

    public function process(): void
    {
        $results = null;
        $isThrow = false;
        ($throw = $this->call($results)) instanceof Throwable ? $isThrow = true : null;
        if ($isThrow) {
            if ($this->finishFunc !== null) {
                call($this->finishFunc, [
                    $this->id,
                    null,
                    $throw,
                ]);
                $this->finishFuncf = null;
            } else {
                trigger_error(
                    "Throwable caught in Archer async task, but no finish callback found:{$throw->getMessage()} in {$throw->getFile()}({$throw->getLine()})"
                );
            }
        } elseif ($this->finishFunc !== null) {
            call($this->finishFunc, [
                $this->id,
                $results,
                null,
            ]);
            $this->finishFuncf = null;
        }
    }
}
