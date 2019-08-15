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

    const TYPE_COLD = 1;
    const TYPE_HOT = 2;

    /**
     * mosruConnector constructor
     */
    public function __construct()
    {
        if (!file_exists('config.php')) {
            exit("Ошибка! Не найден файл конфигурации!\n Ознакомьтесь с примером в файле config.php.sample\n");
        }
        $this->config = require_once('config.php');;
        $this->cookie = tempnam(sys_get_temp_dir(), "mosru-cookie-");

        $this->curlDefaultOptions = [
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36',
            CURLOPT_REFERER        => ';auto',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $this->cookie,
            CURLOPT_COOKIEFILE     => $this->cookie,
            CURLOPT_RETURNTRANSFER => true,
        ];
    }

    /**
     * mosruConnector destructor
     */
    public function __destruct()
    {
        unlink($this->cookie);
    }

    /**
     * @return boolean Auth result
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
            CURLOPT_URL        => 'https://login.mos.ru/sps/login/methods/password',
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query(
                [
                    'login'    => urldecode($this->config['login']),
                    'password' => urldecode($this->config['password']),
                    'me'       => 'on',
                ]
            ),
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
     * Retrieves water counters info
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
            CURLOPT_URL        => 'https://www.mos.ru/pgu/common/ajax/index.php',
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query(
                [
                    'ajaxModule'     => 'Guis',
                    'ajaxAction'     => 'getCountersInfo',
                    'items[paycode]' => $this->config['payerCode'],
                    'items[flat]'    => $this->config['flatNumber'],
                ]
            ),
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        foreach (json_decode($this->response)->counter as $counter) {
            switch ($counter->type) {
                case self::TYPE_COLD:
                    $this->coldCounter[$counter->num] = [
                        'num'       => $counter->num,
                        'counterId' => $counter->counterId,
                    ];
                    foreach ($counter->indications as $line) {
                        $this->coldCounter[$counter->num]['indications'][str_replace('+03:00', '', $line->period)] = $line->indication;
                    }
                    break;
                case self::TYPE_HOT:
                    $this->hotCounter[$counter->num] = [
                        'num'       => $counter->num,
                        'counterId' => $counter->counterId,
                    ];
                    foreach ($counter->indications as $line) {
                        $this->hotCounter[$counter->num]['indications'][str_replace('+03:00', '', $line->period)] = $line->indication;
                    }
                    break;
            }
        }
    }

    /**
     * @return array
     */
    private function parseWaterCountersInfo()
    {
        $dates = [];
        foreach ($this->coldCounter as $counter) {
            foreach ($counter['indications'] as $date => $value) {
                $dates[$date]['cold'][$counter['num']] = $value;
            }
        }
        foreach ($this->hotCounter as $counter) {
            foreach ($counter['indications'] as $date => $value) {
                $dates[$date]['hot'][$counter['num']] = $value;
            }
        }
        return $dates;
    }

    /**
     * @return array
     */
    public function getWaterHistory()
    {
        $this->getWaterCountersInfo();
        return $this->parseWaterCountersInfo();
    }

    /**
     * @return string
     */
    public function getWaterHistoryPrintable()
    {
        $this->getWaterCountersInfo();
        $output = "История показаний счетчиков воды\n";
        setlocale(LC_TIME, 'ru_RU.UTF-8');
        foreach ($this->parseWaterCountersInfo() as $date => $values) {
            $output .= strftime("\n%B %Y", strtotime($date)) . "\n";
            foreach ($values['cold'] as $num => $value) {
                $output .= "Холодная ({$num}): {$value}\t";
            }
            $output .= "\n";
            foreach ($values['hot'] as $num => $value) {
                $output .= "Горячая ({$num}): {$value}\t";
            }
            $output .= "\n";
        }
        return $output;
    }

    /**
     * Sends current indications
     * @param array $values
     */
    public function updateWaterCountersInfo($values)
    {
        $this->error = false;
        $this->errorMessage = '';

        $this->getWaterCountersInfo();
        $countersInfo = $this->parseWaterCountersInfo();
        krsort($countersInfo);

        foreach ($values as $num => $value) {
            if (isset($this->coldCounter[$num]) && (array_values($countersInfo)[0]['cold'][$num] >= $value)) {
                $this->error = true;
                $this->errorMessage .= "Ошибка! Прежнее показание счетчика холодной воды ({$num}) больше или равно указанному!\n";
            }
            elseif (isset($this->hotCounter[$num]) && (array_values($countersInfo)[0]['hot'][$num] >= $value)) {
                $this->error = true;
                $this->errorMessage .= "Ошибка! Прежнее показание счетчика горячей воды ({$num}) больше или равно указанному!\n";
            }
        }

        if (!$this->error) {
            $date = date("Y-m-d", strtotime('last day of this month', time()));

            $query = [
                'ajaxModule'     => 'Guis',
                'ajaxAction'     => 'addCounterInfo',
                'items[paycode]' => $this->config['payerCode'],
                'items[flat]'    => $this->config['flatNumber'],
            ];

            $i = 0;
            foreach ($values as $num => $value) {
                if (isset($this->coldCounter[$num])) {
                    $id = $this->coldCounter[$num]['counterId'];
                }
                elseif (isset($this->hotCounter[$num])) {
                    $id = $this->hotCounter[$num]['counterId'];
                }
                $query["items[indications][{$i}}][counterNum]"] = $id;
                $query["items[indications][{$i}}][counterVal]"] = $value;
                $query["items[indications][{$i}}][num]"] = $num;
                $query["items[indications][{$i}}][period]"] = $date;
                $i++;
            }

            $ch = curl_init();
            $curlOptions = [
                CURLOPT_URL        => 'https://www.mos.ru/pgu/common/ajax/index.php',
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => http_build_query($query),
            ];
            curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
            $this->response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($this->response);
            if ($result->code != 0) {
                $this->error = true;
                $this->errorMessage .= "Ошибка! Невозможно передать показания счетчиков!\n";
                $this->errorMessage .= $result->error . "\n";
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
     * Removes last sent indications (if possible)
     */
    public function removeWaterCounterInfo()
    {
        $this->error = false;
        $this->errorMessage = '';

        $this->getWaterCountersInfo();

        foreach ($this->coldCounter as $num => $counter) {
            $result = $this->removeIndication($counter['counterId']);
            if ($result->code != 0) {
                $this->error = true;
                $this->errorMessage .= "Ошибка! Невозможно удалить последнее показание для счетчика холодной воды ({$num})!\n";
                $this->errorMessage .= $result->error . "\n";
            }
        }

        foreach ($this->hotCounter as $num => $counter) {
            $result = $this->removeIndication($counter['counterId']);
            if ($result->code != 0) {
                $this->error = true;
                $this->errorMessage .= "Ошибка! Невозможно удалить последнее показание для счетчика горячей воды ({$num})!\n";
                $this->errorMessage .= $result->error . "\n";
            }
        }

        if (!$this->error) {
            echo "Последние показания счетчиков успешно удалены.\n";
        }
        else {
            echo $this->errorMessage;
        }
    }

    /**
     * @param string $id
     * @return mixed
     */
    private function removeIndication($id)
    {
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL        => 'https://www.mos.ru/pgu/common/ajax/index.php',
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query(
                [
                    'ajaxModule'       => 'Guis',
                    'ajaxAction'       => 'removeCounterIndication',
                    'items[paycode]'   => $this->config['payerCode'],
                    'items[flat]'      => $this->config['flatNumber'],
                    'items[counterId]' => $id,
                ]
            ),
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);
        return json_decode($this->response);
    }

}
