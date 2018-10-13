<?php
/**
 * Project: MyTools
 * Created At: 12/10/2018
 * Author: Dang Van Diep <diepdangvan2603@gmail.com>
 */

$accessToken = 'EAACW5Fg5N2IBAAHqe4GXNm44wcNGzOSU1ZB3qd8TetbS9p6ES1T1tD4pDDvheJe6su6I01zOhHrJcZBGgAbPWTxOgSfkBNsigZBtmyFoBPTZC22muReBe3FixddVOppZCHZCgDimbS6LSHngfoz4SvSn2v6JxUDuPaU8tvQ6fwkSE4TZCA2pmE8Sy5K2FRFBy0ZD';

$startApplication = microtime(true);

/**
 * Dividing an array into equal pieces
 *
 * @param $array
 * @param $segmentCount
 *
 * @return array|bool
 */
function array_divide($array, $segmentCount)
{
    $dataCount = count($array);
    if ($dataCount == 0) return false;
    $segmentLimit = ceil($dataCount / $segmentCount);
    $outputArray = array_chunk($array, $segmentLimit);
    return $outputArray;
}

function write_log($content, $id = null)
{
    if (!empty($id)) {
        file_put_contents('get_phone_failed.txt', $id . PHP_EOL, FILE_APPEND);
    }

    $filename = 'logs/' . date('d_m_Y') . '.log';
    $content = '[' . date('H:i:s d/m/Y') . '] ' . $content . PHP_EOL;

    file_put_contents($filename, $content, FILE_APPEND);
}

/**
 * Check proxy is live or die
 *
 * @param string  $host
 * @param integer $port
 * @param float   $timeout
 *
 * @return bool
 */
function proxyCheck($host, $port, $timeout = 0.3)
{
    if ($fp = @fsockopen($host, $port, $errCode, $errStr, $timeout)) {
        fclose($fp);

        /*$aContext = [
            'http' => [
                'timeout'         => 1,
                'proxy'           => "tcp://$host:$port",
                'request_fulluri' => true,
            ],
        ];
        $cxContext = stream_context_create($aContext);

        $sFile = @file_get_contents('https://hitn.run/api/facebook/get-phone', false, $cxContext);

        if (empty($sFile)) {
            return false;
        }*/

        return true;
    }

    return false;
}

/**
 * -----------------------------
 * Get user id's
 * -----------------------------
 */
if (isset($argv[1]) && $argv[1] == 'users') {
    if (!file_exists('input/users.txt')) {
        die("[Error] Can't find users file!");
    }

    echo "[Info] Geting user id's from file..." . PHP_EOL;

    $users = array_map('trim', explode(PHP_EOL, file_get_contents('input/users.txt')));

    if (empty($users)) {
        die("Can't get users.");
    }

    goto run;
}

/**
 * -----------------------------
 * Get comments from post
 * -----------------------------
 */
if (!file_exists('input/posts.txt')) {
    die("[Error] Can't find posts file!");
}

$getComments = [];

function getCommentsFromUrl($url)
{
    global $getComments;

    try {
        # Make request
        $response = json_decode(@file_get_contents($url), true);

        # Handler error
        if (isset($response['error']) || !isset($response['data'])) {
            write_log('Graph API Error: (' . $url . ') ' . serialize($response));
            return;
        }

        $getComments = array_merge($getComments, $response['data']);

        # Get comments from next page
        if (isset($response['paging']['next'])) {
            getCommentsFromUrl($response['paging']['next']);
        }
    } catch (Exception $e) {
        write_log('Graph API Error: (' . $url . ') ' . $e->getMessage());
    }
}

function getCommentsFromPostId($postId, $accessToken)
{
    global $getComments;

    $query = http_build_query([
        'limit'        => 500,
        'format'       => 'json',
        'access_token' => $accessToken,
    ]);

    getCommentsFromUrl('https://graph.facebook.com/v3.1/' . $postId . '/comments?' . $query);

    return $getComments;
}

$fp = fopen('input/posts.txt', 'r');

if (!$fp) {
    die('[Error] Can\'t open posts file!');
}

$comments = [];

while (!feof($fp)) {
    $postId = trim(fgets($fp));

    if (empty($postId)) continue;

    echo "[Info] Geting comments from post [$postId]..." . PHP_EOL;

    $comments = array_merge($comments, getCommentsFromPostId($postId, $accessToken));
}

if (empty($comments)) {
    die('[Error] Can\'t find comments!');
}

echo '[Info] Geting user from comments...' . PHP_EOL;

$users = [];

foreach ($comments as $comment) {
    $users[] = $comment['from']['id'];
}

echo '[Info] Filter duplicate values...' . PHP_EOL;

$users = array_unique($users);

run:

/**
 * -----------------------------
 * Get proxy
 * -----------------------------
 */
echo '[Info] Geting proxies for request to api...' . PHP_EOL;

$dom = new DOMDocument();

$dom->loadHTMLFile('https://free-proxy-list.net/', LIBXML_NOWARNING | LIBXML_NOERROR);
$dom->preserveWhiteSpace = true;

$proxiesTable = $dom->getElementById('proxylisttable');
$proxiesTableBody = $proxiesTable->getElementsByTagName('tbody')->item(0);
$rows = $proxiesTableBody->getElementsByTagName('tr');

$proxies = [];

for ($i = 0; $i < $rows->length; $i++) {
    $cols = $rows->item($i)->getElementsByTagName('td');

    $host = $cols->item(0)->nodeValue;
    $port = $cols->item(1)->nodeValue;

    if (proxyCheck($host, $port)) {
        $proxies[] = "$host:$port";
    }

    if (count($proxies) == 10) break;
}

/**
 * ---------------------------------
 * Get phone numbers
 * ---------------------------------
 */
class PhoneNumber extends Thread
{
    private $users;
    private $output;
    private $proxy;

    public function __construct($users, $ouput, $proxy = null)
    {
        $this->users = $users;
        $this->output = $ouput;
        $this->proxy = $proxy;
    }

    public function run()
    {
        echo '  + Thread: ' . $this->getThreadId() . ' - Proxy: ' . $this->proxy . ' - Total User: ' . count($this->users) . '...' . PHP_EOL;

        $fp = @fopen($this->output, 'a');

        if (!$fp) {
            die('[Error] Can\'t open output file!');
        }

        $listUsers = array_chunk((array) $this->users, 50);

        foreach ($listUsers as $index => $users) {
            foreach ($users as $user) {
                $phone = $this->getPhoneNumber($user, $this->proxy);

                if (empty($phone)) continue;

                $content = $user . '|' . $phone . "\r\n";
                fwrite($fp, $content);
            }

            sleep(60);
        }

        echo '[Info] Thread [' . $this->getThreadId() . '] run complete.' . PHP_EOL;
    }

    private function getPhoneNumber($id, $proxy)
    {
        $url = 'https://hitn.run/api/facebook/get-phone';

        $headers = [
            'authorization' => "Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6IjYzMmZkMTM5YTllODA1MGM2ZDAwMWMzZTAxNGQ5NzdiY2M5YWE2YjU5ZmY1YWFiZWU1ZTVlZjlhZjhmNTgyZTA3NTA4ZTBlZWU2MWE0MTk5In0.eyJhdWQiOiIyIiwianRpIjoiNjMyZmQxMzlhOWU4MDUwYzZkMDAxYzNlMDE0ZDk3N2JjYzlhYTZiNTlmZjVhYWJlZTVlNWVmOWFmOGY1ODJlMDc1MDhlMGVlZTYxYTQxOTkiLCJpYXQiOjE1MzkyMzY4MTQsIm5iZiI6MTUzOTIzNjgxNCwiZXhwIjoxNTQwNTMyODE0LCJzdWIiOiIzNjEiLCJzY29wZXMiOltdfQ.kw4LTFYgEXSTRQ8AZ4eNMjTZksbGUmYjejeF9fU5JM68vuEwmmGdaidWgKDrsFnXU-h4l6XL3t5eZTJeBU6MZjb2BQsc1jLB8uZrguXa5nwNv6y7Fw1Z4Q_-UbrzuO7txHO-sHBygueKUtwPwFDXbSw7wBXrVo3nkhNu7rSLbp2UfyOo8zV7Y4kmzxRR8BEOXEpRoqKdhgvYPRUaxHgDwkGKOhVSXHhY55QQTN_QRfMgKo6jbS7h-CLDgfWQMhaojbMDLvnB0nDjtLBtDrdYgo1KZ3gn9qsOJy6LvWDgFdbWwHRpqj1wrB0zcPChni7PThcKt-CZ-kr9WIKwTIYpy2VS73oudAdGGopgERAkZLFhs8vg8ZFTHAjCCOCOYXk3xq_tCZzY_y2b0xghsIwCef4ZottzSh1wQ5tI-kGBGQj5m00QQUpufLJSaFjJi3sKMgwOf3Pe1C5_NyUwy4Lab-fm8g0NPaS6TrQmIs-PGH_FS_JmGQUs9fTtAwSHtI9ijHWbpr_7L8W987oX7jES9DH-owv6WTZGpWFZp4oGhktEe4eQS4MbhgyOJZw7BRotE8FMAMqZUap6LjjtycDZMVslP8_SNSTSYLRUFE8WFcxpwF5hKFHoH_COnPN70C8thqlVXODGPVtghyfHYM3x0F2NAN8Nn9u8r3bNBUMl2sA",
        ];

        $response = $this->makePostRequest($url, ['fb_id' => $id], $headers, $proxy);

        if (empty($response)) {
            write_log('Call get phone api failed. API return null. Proxy: ' . $proxy, $id);
            return null;
        }

        $result = json_decode($response, true);

        if (empty($result)) {
            write_log("Can't decode api response. API returned: " . $response, $id);
            return null;
        }

        if (isset($result['errors']) && $result['errors'][0] != 'Không có dữ liệu') {
            write_log("API returned an error:" . serialize($result), $id);
            return null;
        }

        if (isset($result['phoneNumber'])) {
            return $result['phoneNumber'];
        }

        file_put_contents('output/failed.txt', $id . PHP_EOL, FILE_APPEND);
        return null;
    }

    /**
     * Send post request
     *
     * @param string $url
     * @param array  $params
     * @param array  $headers
     * @param string $proxy
     * @param int    $timeout
     *
     * @return mixed
     */
    private function makePostRequest($url, $params, $headers = [], $proxy = '', $timeout = 3)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        // Headers
        $requestHeaders = [];

        foreach ($headers as $header => $value) {
            $requestHeaders[] = "$header: $value";
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

        // Proxy
        if (!empty($proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROTO_HTTP);
        }

        // SSl
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $result = curl_exec($ch);

        curl_close($ch);

        return $result;
    }
}

echo '[Info] Geting phone number of ' . count($users) . ' users...' . PHP_EOL;

$ouput = 'output/' . date('His_dmY') . '.txt';

$threads = [];

if (empty($proxies) || count($proxies) != 10) {
    $threads[0] = new PhoneNumber($users, $ouput);
    $threads[0]->start();
} else {
    foreach (array_divide($users, 10) as $index => $userParts) {
        $threads[$index] = new PhoneNumber($userParts, $ouput, $proxies[$index]);
        $threads[$index]->start();
    }
}

foreach ($threads as $thread) {
    $thread->join();
}

/**
 * -----------------------
 * Get failed user
 * -----------------------
 */
if (file_exists('get_phone_failed.txt')) {
    echo '[Info] Geting phone number again for fail user...' . PHP_EOL;

    $failUser = array_map('trim', explode(PHP_EOL, file_get_contents('get_phone_failed.txt')));

    if (empty($failUser)) {
        die("Can't get failed users.");
    }

    $failThread[0] = new PhoneNumber($failUser, $ouput);
    $failThread[0]->start();
    $failThread[0]->join();

    unlink('get_phone_failed.txt');
}

$endApplication = microtime(true);

echo PHP_EOL . PHP_EOL;
echo 'Complete!!! Took ' . ($endApplication - $startApplication) / 60 . ' Mins';
