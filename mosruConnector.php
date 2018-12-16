<?php

/**
 * Класс для взаимодействия с порталом https://my.mos.ru
 * Поддерживаются получение, передача и удаление показаний счетчиков воды.
 *
 * @author Evgeny Volferts <eugene.wolferz@gmail.com>
 */
class mosruConnector
{

    private $config = [];
    private $response = '';
    private $cookie = '';
    private $coldCounter = [];
    private $hotCounter = [];
    private $curlDefaultOptions = [];
    private $authorized = false;
    private $error = false;
    private $errorMessage = "";

    public function __construct()
    {
        if (!file_exists('config.php')) {
            exit("Ошибка! Не найден файл конфигурации!\n Ознакомьтесь с примером в файле config.php.sample\n");
        }
        require_once 'config.php';
        $this->config = $config;
        $this->cookie = tempnam(sys_get_temp_dir(), "mosru-cookie-");

        $this->curlDefaultOptions = [
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36',
            CURLOPT_REFERER => ';auto',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookie,
            CURLOPT_COOKIEFILE => $this->cookie,
            CURLOPT_RETURNTRANSFER => true,
        ];
    }

    public function __destruct()
    {
        unlink($this->cookie);
    }

    /**
     * @return boolean Результат авторизации
     */
    private function login()
    {
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://my.mos.ru/my/',
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        preg_match_all('/value="(https:\/\/oauth20.mos.ru\/sps\/oauth\/oauth20(.*))"/', $this->response, $matches);

        if (!isset($matches[1])) {
            return false;
        }

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $matches[1][0],
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://login.mos.ru/sps/login/methods/password',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'login' => urldecode($this->config['login']),
                'password' => urldecode($this->config['password']),
                'me' => 'on',
            ]),
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://beta-my.mos.ru/my/',
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        if (isset($_COOKIE['Ltpatoken2'])) {
            return false;
        }
        else {
            $this->authorized = true;
            return true;
        }
    }

    /**
     * Получает данные счетчиков воды
     */
    private function getWaterCountersInfo()
    {
        if (!$this->authorized && !$this->login()) {
            exit("Ошибка авторизации!\n");
        }

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://www.mos.ru/pgu/ru/application/guis/1111/',
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://www.mos.ru/pgu/common/ajax/index.php',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'ajaxModule' => 'Guis',
                'ajaxAction' => 'getCountersInfo',
                'items[paycode]' => $this->config['payerCode'],
                'items[flat]' => $this->config['flatNumber'],
            ]),
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($this->response, true);
        $this->coldCounter['name'] = $response['counter'][0]['num'];
        $this->coldCounter['id'] = $response['counter'][0]['counterId'];
        $this->coldCounter['indications'] = $response['counter'][0]['indications'];
        $this->hotCounter['name'] = $response['counter'][1]['num'];
        $this->hotCounter['id'] = $response['counter'][1]['counterId'];
        $this->hotCounter['indications'] = $response['counter'][1]['indications'];
    }

    /**
     * @return array Массив, ключами которого служат последние дни месяцев, а значениями пары показаний счетчиков воды в этом месяце
     */
    private function parseWaterCountersInfo()
    {
        $dates = [];
        foreach ($this->coldCounter['indications'] as $data) {
            $dates[str_replace('+03:00', '', $data['period'])]['cold'] = $data['indication'];
        }
        foreach ($this->hotCounter['indications'] as $data) {
            $dates[str_replace('+03:00', '', $data['period'])]['hot'] = $data['indication'];
        }
        return $dates;
    }

    /**
     * @return array Массив с показаниями счетчиков воды за последние несколько месяцев
     */
    public function getWaterHistory()
    {
        $this->getWaterCountersInfo();
        return $this->parseWaterCountersInfo();
    }

    /**
     * @return string Строка с показаниями счетчиков воды за последние несколько месяцев в удобном для печати формате
     */
    public function getWaterHistoryPrintable()
    {
        $this->getWaterCountersInfo();
        $output = "История показаний счетчиков воды\nМесяц и год\t\tХолодная\tГорячая\n";
        setlocale(LC_TIME, 'ru_RU.UTF-8');
        foreach ($this->parseWaterCountersInfo() as $date => $values) {
            $output .= strftime('%B %Y', strtotime($date)) . "\t\t{$values['cold']}\t\t{$values['hot']}\n";
        }
        return $output;
    }

    /**
     * Передает текущие показания счетчиков холодной и горячей воды
     * 
     * @param int $cold Показание счетчика холодной воды
     * @param int $hot Показание счетчика горячей воды
     */
    public function updateWaterCountersInfo($cold, $hot)
    {
        $this->error = false;
        $this->errorMessage = '';

        $this->getWaterCountersInfo();
        $countersInfo = $this->parseWaterCountersInfo();
        krsort($countersInfo);

        $cold = intval($cold);
        if (array_values($countersInfo)[0]['cold'] >= $cold) {
            $this->error = true;
            $this->errorMessage .= "Ошибка! Прежнее показание счетчика холодной воды больше или равно указанному!\n";
        }

        $hot = intval($hot);
        if (array_values($countersInfo)[0]['hot'] >= $hot) {
            $this->error = true;
            $this->errorMessage .= "Ошибка! Прежнее показание счетчика горячей воды больше или равно указанному!\n";
        }

        if (!$this->error) {
            $date = date("Y-m-d", strtotime('last day of this month', time()));

            $ch = curl_init();
            $curlOptions = [
                CURLOPT_URL => 'https://www.mos.ru/pgu/common/ajax/index.php',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'ajaxModule' => 'Guis',
                    'ajaxAction' => 'addCounterInfo',
                    'items[paycode]' => $this->config['payerCode'],
                    'items[flat]' => $this->config['flatNumber'],
                    'items[indications][0][counterNum]' => $this->coldCounter['id'],
                    'items[indications][0][counterVal]' => $cold,
                    'items[indications][0][num]' => $this->coldCounter['name'],
                    'items[indications][0][period]' => $date,
                    'items[indications][1][counterNum]' => $this->hotCounter['id'],
                    'items[indications][1][counterVal]' => $hot,
                    'items[indications][1][num]' => $this->hotCounter['name'],
                    'items[indications][1][period]' => $date,
                ]),
            ];
            curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
            $this->response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($this->response, true);
            if ($result['code'] != 0) {
                $this->error = true;
                $this->errorMessage .= "Ошибка! Невозможно передать показания счетчиков!\n";
                $this->errorMessage .= $result['error'] . "\n";
            }
        }

        if (!$this->error) {
            echo "Показания счетчиков успешно переданы.\n";
        }
        else {
            echo $this->errorMessage;
        }
    }

    /**
     * Удаляет последнюю пару показаний холодной и горячей воды
     */
    public function removeWaterCounterInfo()
    {
        $this->error = false;
        $this->errorMessage = '';

        $this->getWaterCountersInfo();

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://www.mos.ru/pgu/common/ajax/index.php',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'ajaxModule' => 'Guis',
                'ajaxAction' => 'removeCounterIndication',
                'items[paycode]' => $this->config['payerCode'],
                'items[flat]' => $this->config['flatNumber'],
                'items[counterId]' => $this->coldCounter['id'],
            ]),
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($this->response, true);
        if ($result['code'] != 0) {
            $this->error = true;
            $this->errorMessage .= "Ошибка! Невозможно удалить последнее показание для счетчика холодной воды!\n";
            $this->errorMessage .= $result['error'] . "\n";
        }

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://www.mos.ru/pgu/common/ajax/index.php',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'ajaxModule' => 'Guis',
                'ajaxAction' => 'removeCounterIndication',
                'items[paycode]' => $this->config['payerCode'],
                'items[flat]' => $this->config['flatNumber'],
                'items[counterId]' => $this->hotCounter['id'],
            ]),
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($this->response, true);
        if ($result['code'] != 0) {
            $this->error = true;
            $this->errorMessage .= "Ошибка! Невозможно удалить последнее показание для счетчика горячей воды!\n";
            $this->errorMessage .= $result['error'] . "\n";
        }

        if (!$this->error) {
            echo "Последние показания счетчиков успешно удалены.\n";
        }
        else {
            echo $this->errorMessage;
        }
    }

}
