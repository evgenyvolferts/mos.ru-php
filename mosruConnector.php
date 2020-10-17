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
        $this->config = require_once('config.php');
        $this->cookie = tempnam(sys_get_temp_dir(), "mosru-cookie-");

        $this->curlDefaultOptions = [
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.75 Safari/537.36',
            CURLOPT_REFERER        => ';auto',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $this->cookie,
            CURLOPT_COOKIEFILE     => $this->cookie,
            CURLOPT_RETURNTRANSFER => true,
        ];
    }

    /**
     * @param array $cookies
     * @return bool
     */
    private static function tokenPresent($cookies = [])
    {
        foreach ($cookies as $cookie) {
            if ($cookie['name'] == 'Ltpatoken2') {
                return true;
            }
        }
        return false;
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
            CURLOPT_URL => 'https://my.mos.ru/login',
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        preg_match_all('/login_url = "(https:\/\/login.mos.ru\/sps\/oauth\/ae(.*))"/', $this->response, $matches);

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
                    'login'     => urldecode($this->config['login']),
                    'password'  => urldecode($this->config['password']),
                    'isDelayed' => 'false',
                ]
            ),
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://my.mos.ru/my/#/',
        ];
        curl_setopt_array($ch, $this->curlDefaultOptions + $curlOptions);
        $this->response = curl_exec($ch);
        curl_close($ch);

        if (!self::tokenPresent(self::extractCookies(file_get_contents($this->cookie)))) {
            return false;
        } else {
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
            } elseif (isset($this->hotCounter[$num]) && (array_values($countersInfo)[0]['hot'][$num] >= $value)) {
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
                } elseif (isset($this->hotCounter[$num])) {
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
        } else {
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
        } else {
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

    /**
     * Extract any cookies found from the cookie file. This function expects to get
     * a string containing the contents of the cookie file which it will then
     * attempt to extract and return any cookies found within.
     *
     * @param string $string The contents of the cookie file.
     *
     * @return array The array of cookies as extracted from the string.
     *
     * @link https://stackoverflow.com/questions/410109/php-reading-a-cookie-file
     *
     */
    private static function extractCookies($string)
    {
        $cookies = [];
        $lines = explode(PHP_EOL, $string);

        foreach ($lines as $line) {

            $cookie = [];

            // detect httponly cookies and remove #HttpOnly prefix
            if (substr($line, 0, 10) == '#HttpOnly_') {
                $line = substr($line, 10);
                $cookie['httponly'] = true;
            } else {
                $cookie['httponly'] = false;
            }

            // we only care for valid cookie def lines
            if (strlen($line) > 0 && $line[0] != '#' && substr_count($line, "\t") == 6) {

                // get tokens in an array
                $tokens = explode("\t", $line);

                // trim the tokens
                $tokens = array_map('trim', $tokens);

                // Extract the data
                $cookie['domain'] = $tokens[0]; // The domain that created AND can read the variable.
                $cookie['flag'] = $tokens[1];   // A TRUE/FALSE value indicating if all machines within a given domain can access the variable.
                $cookie['path'] = $tokens[2];   // The path within the domain that the variable is valid for.
                $cookie['secure'] = $tokens[3]; // A TRUE/FALSE value indicating if a secure connection with the domain is needed to access the variable.

                $cookie['expiration-epoch'] = $tokens[4];  // The UNIX time that the variable will expire on.
                $cookie['name'] = urldecode($tokens[5]);   // The name of the variable.
                $cookie['value'] = urldecode($tokens[6]);  // The value of the variable.

                // Convert date to a readable format
                $cookie['expiration'] = date('Y-m-d h:i:s', $tokens[4]);

                // Record the cookie.
                $cookies[] = $cookie;
            }
        }

        return $cookies;
    }

}
