<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SerendipitySwow\Archer;

use ArrayObject;
use Closure;
use Hyperf\Engine\Coroutine as Co;
use SerendipitySwow\Archer\Interfaces\TaskInterface;
use Throwable;

abstract class Task implements TaskInterface
{
    protected static ?Closure $finishFunction = null;

    protected ?Closure $taskCallback = null;

    protected ?array $params;

    protected ?int $id;

    private static int $counter = 0;

    public function __construct(callable $taskCallback, ?array $params = null)
    {
        $this->id = ++self::$counter;
        $this->taskCallback = $taskCallback;
        $this->params = $params ?? [];
    }

    abstract public function process();

    public function call(&$results, bool $clearAfter = true): ?Throwable
    {
        if ($this->taskCallback === null) {
            throw new Exception\RuntimeException(
                'Task already executed. This maybe caused by manually calling process().'
            );
        }
        $data = null;
        try {
            /**
             * @var ArrayObject $context
             */
            $context = Co::getContextFor();
            $context->archerTaskId = $this->id;
            /**
             * @var array|mixed $results
             */
            $results = call($this->taskCallback, $this->params);
            if (self::$finishFunction !== null) {
                call(self::$finishFunction, [
                    $this->id,
                    $results,
                    null,
                ]);
            }
        } catch (Throwable $e) {
            $data = $e;
            if (self::$finishFunction !== null) {
                call(self::$finishFunction, [
                    $this->id,
                    null,
                    $e,
                ]);
            }
        }
        $context->archerTaskId = null;
        if ($clearAfter) {
            $this->taskCallback = null;
            $this->params = null;
        }
        Queue::getInstance()
            ->end();

        return $data;
    }

    public static function setFinishFunction(?Closure $finishFunction): void
    {
        self::$finishFunction = $finishFunction;
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * 在Task内调用可以获取当前的Taskid，否则会返回null.
     */
    public static function getCurrentTaskId(): ?int
    {
        return Co::getContextFor()->archerTaskId ?? null;
    }
}
