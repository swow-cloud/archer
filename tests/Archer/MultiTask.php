<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

require_once(dirname(__DIR__)) . '/../vendor/autoload.php';
Swow\Debug\Debugger::runOnTTY();
$container = \SerendipitySwow\Archer\Archer::getMultiTask(20);
$callback = static function ($i) {
    sleep(1);

    return $i;
};

$map = [];
$map2 = [];
$results = [];
for ($id = 1; $id <= 20; ++$id) {// 虽然用 GROUP BY 一条SQL实现，这里只是举个例子
    $taskid = $container->addTask($callback, [$id]);
    $map[$taskid] = $id;
    $map2[$id] = $taskid;
}

foreach ($container->waitForAll(10) as $taskid => $count) {
    $results[$map[$taskid]] = $count;
}

for ($id = 1; $id <= 20; ++$id) {
    if (array_key_exists($id, $results)) {
        echo "id:{$id} count:{$results[$id]}\n";
    } else {
        echo "id:{$id} error:" . $container->getError($map2[$id])
            ->getMessage() . "\n";
    }
}

foreach ($container->yieldEachOne(-1) as $taskid => $count) {
    echo 'taskid: ' . $taskid . 'count: ' . $count . "\n";
    unset($map[$taskid]);
}
