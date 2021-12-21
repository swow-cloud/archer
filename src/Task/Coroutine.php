<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SwowCloud\Archer\Task;

use SwowCloud\Archer\Task;
use Swow\Channel;

class Coroutine extends Task
{
    protected ?Channel $channel = null;

    public function __construct(callable $taskCallback, ?array $params = null, ?Channel $channel = null)
    {
        $this->channel = $channel;
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

        $this->channel = null;
    }
}
