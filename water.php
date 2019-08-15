<?php

require_once 'mosruConnector.php';

$pgu = new mosruConnector();

if (!isset($argv[1])) {
    echo "Скрипт для получения/передачи/удаления показаний счетчиков воды на портале https://my.mos.ru\n";
    echo "Использование:\n";
    echo "php ./water.php get - получение истории последних переданных показаний\n";
    echo "php ./water.php set <counterNum>:<value> [<counterNum>:<value>] - передача текущих показаний\n";
    echo "php ./water.php remove - удаление последних переданных показаний\n";
}
elseif ($argv[1] == 'get') {
    echo $pgu->getWaterHistoryPrintable();
}
elseif (($argv[1] == 'set') && ($argc >= 3)) {
    $params = $argv;
    array_shift($params);
    array_shift($params);
    $values = [];
    foreach ($params as $param) {
        list($num, $value) = explode(':', $param);
        $values[$num] = $value;
    }
    $pgu->updateWaterCountersInfo($values);
}
elseif ($argv[1] == 'remove') {
    $pgu->removeWaterCounterInfo();
}