<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SwowCloud\Archer\Task;

use SwowCloud\Archer\Exception\ChannelPopException;
use SwowCloud\Archer\Exception\TaskTimeoutException;
use SwowCloud\Archer\Task;
use Swow\Channel;
use Swow\Channel\Exception;

class Defer extends Task
{
    protected ?Channel $channel = null;

    public function __construct(callable $taskCallback, ?array $params = null)
    {
        $this->channel = new Channel();
        parent::__construct($taskCallback, $params);
    }

    public function process(): void
    {
        $results = null;
        $throw = $this->call($results);
        if ($throw !== null) {
            $this->channel->push($throw);
        } else {
            // 将返回值放入数组中是为了与\Throwable区分开
            $this->channel->push([
                $results,
            ]);
        }
    }

    /**
     * @throws TaskTimeoutException
     */
    public function recv(int $timeout = -1): mixed
    {
        try {
            $ret = $this->channel->pop($timeout === -1 ? -1 : ($timeout * 1000));
            if (is_array($ret)) {
                return current($ret);
            }
            throw $ret;
        } catch (Exception $error) {
            if ($error->getCode() === 60) {
                throw new TaskTimeoutException();
            }
        }
        throw new ChannelPopException('Channel pop error');
    }
}
