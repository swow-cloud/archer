<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SwowCloud\Archer\Interfaces;

use SwowCloud\Archer\Exception\AddNewTaskFailException;
use SwowCloud\Archer\Exception\TaskTimeoutException;

interface ArcherInterface
{
    /**
     * 投递一个Task进入队列异步执行，该方法立即返回
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后将该异常作为第三个参数传递给$finish_callback，若未设置则会产生一个warning。
     * 注意2：Task执行时的协程与当前协程不是同一个
     * 注意3：Task执行时的协程与回调函数执行时的协程是同一个.
     *
     * @param callable $taskCallback 需要执行的函数
     * @param null|array $params 传递进$task_callback中的参数，可缺省
     * @param null|callable $finishCallback $task_callback完成后触发的回调，参数1为Task的id，参数2为$task_callback的返回值，参数3为Task内抛出的\Throwable异常，参数2和3只会存在一个。可缺省
     *
     * @throws AddNewTaskFailException
     */
    public static function task(callable $taskCallback, ?array $params = null, ?callable $finishCallback = null): int;

    /**
     * 投递一个Task进入队列，同时当前协程挂起。当该Task执行完成后，会恢复投递的协程，并返回Task的返回值。
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后在这里抛出。
     * 注意2：Task执行时的协程与当前协程不是同一个.
     *
     * @param int $timeout 超时时间，超时后函数会直接返回。注意：超时返回后Task仍会继续执行，不会中断。若缺省则表示不会超时
     *
     * @throws TaskTimeoutException 超时时抛出的Exception，注意这个超时不会影响Task的执行
     * @throws AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     */
    public static function taskWait(callable $taskCallback, ?array $params = null, int $timeout = -1): mixed;

    /**
     * 投递一个Task进入队列，该方法立即返回刚才所投递的Task。通过执行$task->recv()获得执行结果
     * 注意1：若Task抛出了任何\Throwable异常，Archer会捕获后在$task->recv()抛出。
     * 注意2：Task执行时的协程与当前协程不是同一个.
     *
     * @throws AddNewTaskFailException 因channel状态错误AddTask失败，这是一种正常情况不应该出现的Exception
     */
    public static function taskDefer(callable $taskCallback, ?array $params = null): mixed;

    /**
     * 获取多Task的处理容器，每次执行都是获取一个全新的对象
     */
    public static function getMultiTask(?int $maxConcurrent = null);
}
