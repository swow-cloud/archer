<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SwowCloud\Archer;

use Hyperf\Engine\Channel;
use Hyperf\Utils\Coroutine;

class Queue
{
    public const TIMEOUT_CODE = -60;

    private static ?self $instance = null;

    private static int $size = 8192;

    private static int $concurrent = 2048;

    private ?Channel $queue;

    private ?Channel $running;

    private int $producer = 0;

    private function __construct()
    {
        $this->queue = new Channel(self::$size);
        $this->running = new Channel(self::$concurrent);
    }

    /**
     * 队列的size，默认为8192。当待执行的Task数量超过size时，再投递Task会导致协程切换，直到待执行的Task数量小于size后才可恢复
     * 调用该方法改变size，必须在第一次投递任何Task之前调用。建议在 onWorkerStart 中调用.
     */
    public static function setQueueSize(int $size): void
    {
        self::$size = $size;
    }

    /**
     * 最大并发数，默认为2048。
     * 调用该方法改变concurrent，必须在第一次投递任何Task之前调用。建议在 onWorkerStart 中调用.
     */
    public static function setConcurrent(int $concurrent): void
    {
        self::$concurrent = $concurrent;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            Coroutine::create([
                self::$instance,
                'loop',
            ]);
        }

        return self::$instance;
    }

    public static function stop(): void
    {
        if (self::$instance !== null && self::$instance->queue !== null) {
            self::$instance->queue->close();
        }
    }

    public function push(Task $task): bool
    {
        ++$this->producer;

        return $this->queue->push($task);
    }

    public function loop(): void
    {
        do {
            $task = $this->queue->pop();
            $this->producer > 0 ? --$this->producer : null;
            if ($task === false) {
                return;
            }
            if (!$task instanceof Task) {
                throw new Exception\RuntimeException('Channel pop error');
            }
            $this->running->push(true);
            Coroutine::create([
                $task,
                'process',
            ]);
            unset($task);
        } while (true);
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    public function isFull(): bool
    {
        return $this->queue->isFull();
    }

    /**
     * @return array 返回三个数字，第一个是队列中待执行的Task数量，第二个是超过队列size的待执行Task数量，第三个是正在执行中的Task数量
     */
    public function stats(): array
    {
        return [
            $this->queue->getLength(),
            $this->producer,
            $this->running->getLength(),
        ];
    }

    /**
     * 不要手动调用该方法！
     */
    public function end(): void
    {
        $this->running->pop(1);
    }
}
