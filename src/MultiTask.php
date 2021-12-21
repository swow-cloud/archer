<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SwowCloud\Archer;

use Countable;
use Exception;
use Generator;
use SwowCloud\Archer\Exception\AddNewTaskFailException;
use SwowCloud\Archer\Exception\RuntimeException;
use SwowCloud\Archer\Exception\TaskTimeoutException;
use SwowCloud\Archer\Task\CoroutinePackUnit;
use SplQueue;
use Swow\Channel;
use Throwable;
use function count;

class MultiTask implements Countable
{
    private const STATUS_PREPARING = 0;

    private const STATUS_WAITING = 1;

    private const STATUS_DONE = 2;

    private const TYPE_WAIT_FOR_ALL = 0;

    private const TYPE_YIELD_EACH_ONE = 1;

    private static int $counter = 0;

    private int $maxConcurrent;

    private SplQueue $maxConcurrentQueue;

    private int $running;

    private int $id;

    private array $resultMap;

    private array $errorMap;

    private int $status;

    private int $size;

    private ?Channel $channel;

    private int $type;

    /**
     * 键值对，用来记录每个Task的执行状态
     */
    private array $taskIds;

    public function __construct(?int $maxConcurrent = null)
    {
        if ($maxConcurrent !== null) {
            $this->maxConcurrent = $maxConcurrent;
            $this->maxConcurrentQueue = new SplQueue();
        }
        $this->running = 0;
        $this->id = ++self::$counter;
        $this->status = self::STATUS_PREPARING;
        $this->resultMap = [];
        $this->errorMap = [];
        $this->taskIds = [];
        $this->size = 0;
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * 这个方法会向队列投递Task并立即返回Task id
     * 注意：Task执行时的协程与当前协程不是同一个.
     *
     * @throws Exception\AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     * @return int Task id
     */
    public function addTask(callable $taskCallback, ?array $params = null): int
    {
        if ($this->status !== self::STATUS_PREPARING) {
            throw new Exception\RuntimeException('Wrong status when adding task:' . $this->status);
        }
        $task = new CoroutinePackUnit($taskCallback, $params, $this);
        $this->taskIds[$task->getId()] = null;
        ++$this->size;
        if ($this->maxConcurrentQueue !== null && $this->running >= $this->maxConcurrent) {
            $this->maxConcurrentQueue->push($task);
        } else {
            ++$this->running;
            if (!Queue::getInstance()
                ->push($task)) {
                throw new Exception\AddNewTaskFailException();
            }
        }

        return $task->getId();
    }

    public function count(): int
    {
        return $this->size;
    }

    /**
     * 若运行时所有Task已执行完，则会直接以键值对的形式返回所有Task的返回值。
     * 否则当前协程挂起。当该所有Task执行完成后，会恢复投递的协程，并返回结果。
     * 注意1：若Task抛出了任何\Throwable异常，本方法返回的结果集中将不包含该Task对应的id，需要使用getError($id)方法获取异常对象
     *
     * @param null|int $timeout
     *                          超时时间，缺省表示不超时
     *
     * @throws TaskTimeoutException
     */
    public function waitForAll(?int $timeout = null): array
    {
        $startTime = 0;
        if ($timeout !== null && $timeout !== -1) {
            $startTime = microtime(true);
        }
        $timeout = $timeout === -1 ? -1 : ($timeout !== null ? $timeout * 1000 : null);
        $this->type = self::TYPE_WAIT_FOR_ALL;
        if (!$this->prepareDone()) {
            return [];
        }
        // 已全部执行完
        if ($this->getUnfinishedTaskCount() === 0) {
            return $this->resultMap;
        }
        // 尚未执行完，设置接收器
        $this->channel = new Channel(1);
        if ($timeout !== null && $timeout !== -1) {
            // 由于上面的操作可能会发生协程切换占用时间，这里调整一下pop的timeout减少时间误差
            $diff = (int) ((microtime(true) - $startTime) * 1000);
            if ($diff < $timeout) {
                try {
                    $result = $this->channel->pop($timeout - $diff);

                    if ($result === true) {
                        $this->channel = null;

                        return $this->resultMap;
                    }
                } catch (Exception $e) {
                    if ($e instanceof Channel\Exception && $e->getCode() === Queue::TIMEOUT_CODE) {
                        throw new RuntimeException('Channel pop error');
                    }
                }
            }
            $this->channel = null;

            throw new TaskTimeoutException();
        }
        try {
            $this->channel->pop();
        } catch (Exception $e) {
            if ($e instanceof Channel\Exception && $e->getCode() === Queue::TIMEOUT_CODE) {
                throw new RuntimeException('Channel pop error');
            }
        }
        $this->channel = null;

        return $this->resultMap;
    }

    /**
     * 若运行时已经有些Task已执行完，则会按执行完毕的顺序将他们先yield出来。
     * 若这之后仍存在未执行完的Task，则当前协程将会挂起，每有一个Task执行完，当前协程将恢复且其结果就会以以键值对的方式yield出来，然后协程会挂起等待下一个执行完的Task。
     * 注意1：若Task抛出了任何\Throwable异常，本方法将不会yield出该Task对应的键值对，getReturn()获取结果集数组也不会包含，需要使用getError($id)方法获取异常对象
     * 注意2：Task执行时的协程与当前协程不是同一个.
     *
     * @param null|int $timeout
     *                          总超时时间，缺省表示不超时。（注意该时间表示花费在本方法内的时间，外界调用该方法处理每个返回值所耗费的时间不计入）
     *
     * @throws TaskTimeoutException
     * @return Generator 迭代完所有项之后，可以通过 getReturn() 获取结果集数组
     */
    public function yieldEachOne(?int $timeout = null): Generator
    {
        $startTime = 0;
        if ($timeout !== null && $timeout !== -1) {
            $startTime = microtime(true);
        }
        $timeout = $timeout === -1 ? -1 : ($timeout !== null ? $timeout * 1000 : null);
        $this->type = self::TYPE_YIELD_EACH_ONE;
        if (!$this->prepareDone()) {
            return [];
        }
        $unfinishedTaskCount = $this->getUnfinishedTaskCount();
        if ($unfinishedTaskCount === 0) {
            foreach ($this->resultMap as $k => $v) {
                yield $k => $v;
            }

            return $this->resultMap;
        }

        // 先设置接收器并记录下已有的result数量，yield已有Result的过程中若有新的Task完成，则不会产生影响
        $this->channel = new Channel($unfinishedTaskCount);
        $count = count($this->resultMap);
        $outsideTimeCost = 0;
        foreach ($this->resultMap as $k => $v) {
            if ($count === 0) {
                break;
            }
            --$count;
            $yieldTime = microtime(true);
            yield $k => $v;
            $outsideTimeCost += (microtime(true) - $yieldTime);
        }

        if ($timeout !== null && $timeout !== -1) {
            for ($i = 0; $i < $unfinishedTaskCount; ++$i) {
                $diff = (int) ((microtime(true) - $startTime - $outsideTimeCost) * 1000);
                if ($diff < $timeout) {
                    try {
                        $id = $this->channel->pop($timeout - $diff);
                        if (is_numeric($id)) {
                            // 若不存在于 $this->resultMap 中，表示Task抛出了异常
                            if (array_key_exists($id, $this->resultMap)) {
                                $yieldTime = microtime(true);
                                yield $id => $this->resultMap[$id];
                                $outsideTimeCost += microtime(true) - $yieldTime;
                            }

                            continue;
                        }
                    } catch (Exception $e) {
                        if ($e instanceof Channel\Exception && $e->getCode() === Queue::TIMEOUT_CODE) {
                            throw new RuntimeException('Channel pop error');
                        }
                    }
                }
                $this->channel = null;

                throw new TaskTimeoutException();
            }
        } else {
            for ($i = 0; $i < $unfinishedTaskCount; ++$i) {
                try {
                    $id = $this->channel->pop();
                    // 若不存在于 $this->resultMap 中，表示Task抛出了异常
                    if (array_key_exists($id, $this->resultMap)) {
                        yield $id => $this->resultMap[$id];
                    }
                } catch (Exception $e) {
                    if ($e instanceof Channel\Exception && $e->getCode() === Queue::TIMEOUT_CODE) {
                        throw new RuntimeException('Channel pop error');
                    }
                }
            }
        }
        $this->status = self::STATUS_DONE;
        $this->channel = null;

        return $this->resultMap;
    }

    public function registerResult(int $id, $result): void
    {
        $this->checkRegisterPrecondition($id);
        $this->resultMap[$id] = $result;
        $this->notifyReceiver($id);
    }

    public function registerError(int $id, Throwable $e): void
    {
        $this->checkRegisterPrecondition($id);
        $this->errorMap[$id] = $e;
        $this->notifyReceiver($id);
    }

    public function getError(int $id): ?Throwable
    {
        if (isset($this->errorMap) && array_key_exists($id, $this->errorMap)) {
            return $this->errorMap[$id];
        }

        return null;
    }

    public function getErrorMap(): array
    {
        if (empty($this->errorMap)) {
            return [];
        }

        return $this->errorMap;
    }

    private function prepareDone(): bool
    {
        if ($this->status !== self::STATUS_PREPARING) {
            throw new RuntimeException('Wrong status when executing:' . $this->status);
        }
        if (empty($this->taskIds)) {
            $this->status = self::STATUS_DONE;

            return false;
        }
        $this->status = self::STATUS_WAITING;

        return true;
    }

    private function getUnfinishedTaskCount(): int
    {
        return $this->size - count($this->resultMap) - count($this->errorMap);
    }

    private function checkRegisterPrecondition(int $id): void
    {
        if ($this->status === self::STATUS_DONE) {
            throw new RuntimeException('Wrong status when registering result:' . $this->status);
        }
        if (!array_key_exists($id, $this->taskIds)) {
            throw new RuntimeException('Task not found when registering result');
        }
        if (array_key_exists($id, $this->resultMap)) {
            throw new RuntimeException('Result already present when registering result');
        }
        if (array_key_exists($id, $this->errorMap)) {
            throw new RuntimeException('Error already present when registering result');
        }
    }

    private function notifyReceiver(int $id): void
    {
        --$this->running;
        if (isset($this->channel)) {
            if ($this->type === self::TYPE_YIELD_EACH_ONE) {
                $this->channel->push($id);
            } elseif ($this->getUnfinishedTaskCount() === 0) {
                $this->status = self::STATUS_DONE;
                $this->channel->push(true);
            }
        }
        if ($this->running < $this->maxConcurrent && $this->maxConcurrentQueue !== null && !$this->maxConcurrentQueue->isEmpty(
            )) {
            ++$this->running_task;
            if (!Queue::getInstance()
                ->push($this->maxConcurrentQueue->pop())) {
                throw new AddNewTaskFailException();
            }
        }
    }
}
