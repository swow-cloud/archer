<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SwowCloud\Archer\Exception;

use JetBrains\PhpStorm\Pure;

class TaskTimeoutException extends \Exception
{
    #[Pure]
    public function __construct()
    {
        parent::__construct('Task timeout. Noted that the task itself will continue running normally.');
    }
}
