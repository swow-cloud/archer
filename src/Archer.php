<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SwowCloud\Archer;

use SwowCloud\Archer\Exception\AddNewTaskFailException;
use SwowCloud\Archer\Exception\RuntimeException;
use SwowCloud\Archer\Exception\TaskTimeoutException;
use SwowCloud\Archer\Interfaces\ArcherInterface;
use SwowCloud\Archer\Task\Async;
use SwowCloud\Archer\Task\Coroutine;
use SwowCloud\Archer\Task\Defer;
use Swow\Channel;
use Throwable;
use function is_array;

abstract class Archer implements ArcherInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws AddNewTaskFailException
     */
    public static function task(callable $taskCallback, ?array $params = null, ?callable $finishCallback = null): int
    {
        $task = new Async($taskCallback, $params, $finishCallback);
        if (!Queue::getInstance()
            ->push($task)) {
            throw new AddNewTaskFailException();
        }

        return $task->getId();
    }

    /**
     * {@inheritdoc}
     *
     * @throws AddNewTaskFailException
     */
    public static function taskDefer(callable $taskCallback, ?array $params = null): Defer
    {
        $task = new Defer($taskCallback, $params);
        if (!Queue::getInstance()
            ->push($task)) {
            throw new AddNewTaskFailException();
        }

        return $task;
    }

    /**
     * {@inheritDoc}
     *
     * @throws AddNewTaskFailException
     * @throws TaskTimeoutException
     * @throws Throwable
     */
    public static function taskWait(callable $taskCallback, ?array $params = null, int $timeout = -1): mixed
    {
        $startTime = microtime(true);
        $timeout = $timeout === -1 ? -1 : $timeout * 1000;
        $channel = new Channel();
        $task = new Coroutine($taskCallback, $params, $channel);
        if (!Queue::getInstance()
            ->push($task)) {
            throw new AddNewTaskFailException();
        }
        if ($timeout !== -1) {
            // 由于上面的操作可能会发生协程切换占用时间，这里调整一下pop的timeout减少时间误差
            $diff = (int) ((microtime(true) - $startTime) * 1000);
            if ($diff < $timeout) {
                try {
                    $result = $channel->pop($timeout - $diff);
                    if (is_array($result)) {
                        return current($result);
                    }
                    throw $result;
                } catch (Channel\Exception $exception) {
                    if ($exception->getCode() === Queue::TIMEOUT_CODE) {
                        throw new RuntimeException('Channel pop error');
                    }
                }
            }

            throw new TaskTimeoutException();
        }
        $result = $channel->pop();

        if ($result instanceof Throwable) {
            throw $result;
        }

        return current($result);
    }

    /**
     * {@inheritDoc}
     */
    public static function getMultiTask(?int $maxConcurrent = null): MultiTask
    {
        return new MultiTask($maxConcurrent);
    }
}
