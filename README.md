mosruConnector.php
=======
Класс для получения/передачи/удаления показаний счетчиков воды на портале госуслуг Москвы http://my.mos.ru

config.php.sample
========
Пример конфига, переименуйте в config.php и внесите свои данные

water.php
========
Пример использования класса для работы с порталом госуслуг Москвы http://my.mos.ru

php ./water.php get - получение истории последних переданных показаний

php ./water.php set <cold> <hot> - передача текущих показаний

php ./water.php remove - удаление последней пары переданных показаний



За вдохновение и идею большое спасибо [@basiliocat](https://github.com/basiliocat/mos.ru)