<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SerendipitySwow\Archer\Exception;

use Exception;
use JetBrains\PhpStorm\Pure;

class AddNewTaskFailException extends Exception
{
    #[Pure]
    public function __construct()
    {
        parent::__construct('Add new task fail because channel closed unexpectedly');
    }
}
