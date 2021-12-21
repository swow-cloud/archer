<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

use SwowCloud\ArcherTest\Archer;

require_once(dirname(__DIR__)) . '/../vendor/autoload.php';
Swow\Debug\Debugger::runOnTTY();
$callback = function (string $method, ...$param) {
    return $param;
};
$task1 = Archer::taskDefer($callback, ['get', 'some_key']);
$task2 = Archer::taskDefer($callback, ['hget', 'a', 'b']);
$task3 = Archer::taskDefer($callback, ['lget', 'k1', 10]);
var_dump($task1->getId());
var_dump($task2->getId());
var_dump($task3->getId());
var_dump($task1->recv(1));
var_dump($task2->recv(1));
var_dump($task3->recv(1));

exit;
