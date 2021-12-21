<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

require_once(dirname(__DIR__)) . '/../vendor/autoload.php';
Swow\Debug\Debugger::runOnTTY();
$callback = static function (...$param) {
    return $param;
};
var_dump(SwowCloud\Archer\Archer::taskWait($callback, ['呵呵', 'cnm'], 1));
