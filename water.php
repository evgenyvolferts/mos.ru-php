<?php

require_once 'mosruConnector.php';

$pgu = new mosruConnector();

if (!isset($argv[1])) {
	echo "Скрипт для получения/передачи/удаления показаний счетчиков воды на портале https://my.mos.ru\n";
	echo "Использование:\n";
	echo "php ./water.php get - получение истории последних переданных показаний\n";
	echo "php ./water.php set <cold> <hot> - передача текущих показаний\n";
	echo "php ./water.php remove - удаление последней пары переданных показаний\n";
}
elseif ($argv[1] == 'get') {
	echo $pgu->getWaterHistoryPrintable();
}
elseif (($argv[1] == 'set') && isset($argv[2]) && isset($argv[3])) {
	$pgu->updateWaterCountersInfo($argv[2], $argv[3]);
}
elseif ($argv[1] == 'remove') {
	$pgu->removeWaterCounterInfo();
}