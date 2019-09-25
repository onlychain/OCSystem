<?php
/**
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 16-7-15
 * Time: 上午11:38
 */
/**
 * 获取实例
 * @return \Server\SwooleDistributedServer
 */
function &get_instance()
{
    return \Server\SwooleDistributedServer::get_instance();
}

/**
 * 获取服务器运行到现在的毫秒数
 * @return int
 */
function getTickTime()
{
    return getMillisecond() - \Server\Start::getStartMillisecond();
}

/**
 * 获取当前的时间(毫秒)
 * @return float
 */
function getMillisecond()
{
    list($t1, $t2) = explode(' ', microtime());
    return (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
}

function shell_read()
{
    $fp = fopen('php://stdin', 'r');
    $input = fgets($fp, 255);
    fclose($fp);
    $input = chop($input);
    return $input;
}

/**
 * http发送文件
 * @param $path
 * @param $response
 * @return mixed
 */
function httpEndFile($path, $request, $response)
{
    $path = urldecode($path);
    if (!file_exists($path)) {
        return false;
    }
    $lastModified = gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';
    //缓存
    if (isset($request->header['if-modified-since']) && $request->header['if-modified-since'] == $lastModified) {
        $response->status(304);
        $response->end('');
        return true;
    }
    $extension = get_extension($path);
    $normalHeaders = get_instance()->config->get("fileHeader.normal", ['Content-Type: application/octet-stream']);
    $headers = get_instance()->config->get("fileHeader.$extension", $normalHeaders);
    foreach ($headers as $value) {
        list($hk, $hv) = explode(': ', $value);
        $response->header($hk, $hv);
    }
    $response->header('Last-Modified', $lastModified);
    $response->sendfile($path);
    return true;
}

/**
 * 获取后缀名
 * @param $file
 * @return mixed
 */
function get_extension($file)
{
    $info = pathinfo($file);
    return strtolower($info['extension'] ?? '');
}

/**
 * php在指定目录中查找指定扩展名的文件
 * @param $path
 * @param $ext
 * @return array
 */
function get_files_by_ext($path, $ext)
{
    $files = array();
    if (is_dir($path)) {
        $handle = opendir($path);
        while ($file = readdir($handle)) {
            if ($file[0] == '.') {
                continue;
            }
            if (is_file($path . $file) and preg_match('/\.' . $ext . '$/', $file)) {
                $files[] = $file;
            }
        }
        closedir($handle);
    }
    return $files;
}

function getLuaSha1($name)
{
    return \Server\Asyn\Redis\RedisLuaManager::getLuaSha1($name);
}

/**
 * 检查扩展
 * @return bool
 */
function checkExtension()
{
    $check = true;
    if (!extension_loaded('swoole')) {
        secho("STA", "[扩展依赖]缺少swoole扩展");
        $check = false;
    }
    if (extension_loaded('xhprof')) {
        secho("STA", "[扩展错误]不允许加载xhprof扩展，请去除");
        $check = false;
    }
    if (extension_loaded('xdebug')) {
        secho("STA", "[扩展错误]不允许加载xdebug扩展，请去除");
        $check = false;
    }
    if (version_compare(PHP_VERSION, '7.0.0', '<')) {
        secho("STA", "[版本错误]PHP版本必须大于7.0.0\n");
        $check = false;
    }
    if (version_compare(SWOOLE_VERSION, '4.0.3', '<')) {
        secho("STA", "[版本错误]Swoole版本必须大于4.0.3\n");
        $check = false;
    }

    if (!class_exists('swoole_redis')) {
        secho("STA", "[编译错误]swoole编译缺少--enable-async-redis,具体参见文档http://docs.sder.xin/%E7%8E%AF%E5%A2%83%E8%A6%81%E6%B1%82.html");
        $check = false;
    }
    if (!extension_loaded('redis')) {
        secho("STA", "[扩展依赖]缺少redis扩展");
        $check = false;
    }
    if (!extension_loaded('pdo')) {
        secho("STA", "[扩展依赖]缺少pdo扩展");
        $check = false;
    }

    if (get_instance()->config->has('consul_enable')) {
        secho("STA", "consul_enable配置已被弃用，请换成['consul']['enable']");
        $check = false;
    }
    if (get_instance()->config->has('use_dispatch')) {
        secho("STA", "use_dispatch配置已被弃用，请换成['dispatch']['enable']");
        $check = false;
    }
    if (get_instance()->config->has('dispatch_heart_time')) {
        secho("STA", "dispatch_heart_time配置已被弃用，请换成['dispatch']['heart_time']");
        $check = false;
    }
    if (get_instance()->config->get('config_version', '') != \Server\SwooleServer::config_version) {
        secho("STA", "配置文件有不兼容的可能，请将vendor/tmtbe/swooledistributed/src/config目录替换src/config目录，然后重新配置");
        $check = false;
    }
    return $check;
}

/**
 * 是否是mac系统
 * @return bool
 */
function isDarwin()
{
    if (PHP_OS == "Darwin") {
        return true;
    } else {
        return false;
    }
}
function displayExceptionHandler(\Throwable $exception)
{
    get_instance()->log->error($exception->getMessage(),["trace"=>$exception->getTrace()]);
    secho("EX","------------------发生异常：".$exception->getMessage()."-----------------------");
    $string = $exception->getTraceAsString();
    $arr = explode("#",$string);
    unset($arr[0]);
    foreach ($arr as $value){
        secho("EX","#".$value);
    }
}
/**
 * 代替sleep
 * @param $ms
 * @return mixed
 */
function sleepCoroutine($ms)
{
    \co::sleep($ms / 1000);
//    Swoole\Coroutine::sleep($ms / 1000);
}

/**
 * @param string $dev
 * @return string
 */
function getServerIp($dev = 'eth0')
{
    return exec("ip -4 addr show $dev | grep inet | awk '{print $2}' | cut -d / -f 1");
}

/**
 * @return string
 */
function getBindIp()
{
    return get_instance()->getBindIp();
}

/**
 * @return array|false|mixed|string
 */
function getNodeName()
{
    global $node_name;
    if (!empty($node_name)) {
        return $node_name;
    }
    $env_SD_NODE_NAME = getenv("SD_NODE_NAME");
    if (!empty($env_SD_NODE_NAME)) {
        $node_name = $env_SD_NODE_NAME;
    } else {
        if (!isset(get_instance()->config['consul']['node_name'])
            || empty(get_instance()->config['consul']['node_name'])) {
            $node_name = exec('hostname');
        } else {
            $node_name = get_instance()->config['consul']['node_name'];
        }
    }
    return $node_name;
}

/**
 * @return mixed|string
 */
function getServerName()
{
    return get_instance()->config['name'] ?? 'SWD';
}

/**
 * @return string
 */
function getConfigDir()
{
    $env_SD_CONFIG_DIR = getenv("SD_CONFIG_DIR");
    if (!empty($env_SD_CONFIG_DIR)) {
        $dir = CONFIG_DIR . '/' . $env_SD_CONFIG_DIR;
        if (!is_dir($dir)) {
            secho("STA", "$dir 目录不存在\n");
            exit();
        }
        return $dir;
    } else {
        return CONFIG_DIR;
    }
}

/**
 * @param string $prefix
 * @return string
 */
function create_uuid($prefix = "")
{    //可以指定前缀
    $str = md5(uniqid(mt_rand(), true));
    $uuid = substr($str, 0, 8) . '-';
    $uuid .= substr($str, 8, 4) . '-';
    $uuid .= substr($str, 12, 4) . '-';
    $uuid .= substr($str, 16, 4) . '-';
    $uuid .= substr($str, 20, 12);
    return $prefix . $uuid;
}

function print_context($context)
{
    secho("EX", "运行链路:");
    foreach ($context['RunStack'] as $key => $value) {
        secho("EX", "$key# $value");
    }
}

function secho($tile, $message)
{
    ob_start();
    if (is_string($message)) {
        $message = ltrim($message);
        $message = str_replace(PHP_EOL, '', $message);
    }
    print_r($message);
    $content = ob_get_contents();
    ob_end_clean();
    $could = false;
    if (empty(\Server\Start::getDebugFilter())) {
        $could = true;
    } else {
        foreach (\Server\Start::getDebugFilter() as $filter) {
            if (strpos($tile, $filter) !== false || strpos($content, $filter) !== false) {
                $could = true;
                break;
            }
        }
    }

    $content = explode("\n", $content);
    $send = "";
    foreach ($content as $value) {
        if (!empty($value)) {
            $echo = "[$tile] $value";
            $send = $send . $echo . "\n";
            if ($could) {
                echo " > $echo\n";
            }
        }
    }
    try {
        if (get_instance() != null) {
            get_instance()->pub('$SYS/' . getNodeName() . "/echo", $send);
        }
    } catch (Exception $e) {

    }
}

function setTimezone()
{
    date_default_timezone_set('Asia/Shanghai');
}

function format_date($time)
{
    $day = (int)($time / 60 / 60 / 24);
    $hour = (int)($time / 60 / 60) - 24 * $day;
    $mi = (int)($time / 60) - 60 * $hour - 60 * 24 * $day;
    $se = $time - 60 * $mi - 60 * 60 * $hour - 60 * 60 * 24 * $day;
    return "$day 天 $hour 小时 $mi 分 $se 秒";
}

function sd_call_user_func($function, ...$parameter)
{
    if(is_callable($function)){
        return $function(...$parameter);
    }
}

function sd_call_user_func_array($function, $parameter)
{
    if (is_callable($function)) {
        return $function(...$parameter);
    }
}

/**
 * @param $arr
 * @throws \Server\Asyn\MQTT\Exception
 */
function sd_debug($arr)
{
    Server\Components\SDDebug\SDDebug::debug($arr);
}

function read_dir_queue($dir)
{
    $files = array();
    $queue = array($dir);
    while ($data = each($queue)) {
        $path = $data['value'];
        if (is_dir($path) && $handle = opendir($path)) {
            while ($file = readdir($handle)) {
                if ($file == '.' || $file == '..') continue;
                $files[] = $real_path = realpath($path . '/' . $file);
                if (is_dir($real_path)) $queue[] = $real_path;
            }
        }
        closedir($handle);
    }
    $result = [];
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) == "php") {
            $result[] = $file;
        }
    }
    return $result;
}

/**
 * 十进制转换为其他进制默认转换为90进制
 * @param int $num
 * @param string $str
 * @return null|string
 */
function xbin($num = 0, $str = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ`~!@#$%^*()-_+=,<>.?{}[]|;:"')
{
    $num = floatval($num);
    $x = strlen($str);
    $arr = str_split($str);
    $end_num = '';
    if($x > $num){
        $end_num = isset($arr[$num]) ? $arr[$num] : null;
    }else{
        while($num >= 1){
            $digit = fmod($num, $x);//取模
            $xbin = isset($arr[$digit]) ? $arr[$digit] : null;
            $num = floor($num / $x);
            $end_num = $xbin . $end_num;
        }
    }
    return $end_num;
}

/**
 * 任意进制转换为十进制，默认转换为90进制
 * @param int $num
 * @param string $str
 * @return null|string
 */
function unxbin($num_str = '', $type = 1)
{
    if($type == 1 && strpos($num_str, '?') === false){
        $str = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    }else{
        $str = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ`~!@#$%^*()-_+=,<>.?{}[]|;:"';
    }
    $num = 0;
    $conversion = str_split($num_str);
    $code_table = str_split($str);//将字符串转换成数组
    $code_table = array_flip($code_table);//将编码表键值对调
    $con_len = count($conversion);
    $ct_len = count($code_table);
    for($i = 0; $i < $con_len; $i++){
        $index = $code_table[$conversion[$i]];
        $num += $index * pow($ct_len, $con_len - $i - 1);
    }
    return $num;
}

/**
 * 错误信息返回方法
 * @param type $msg错误提示信息
 * @return boolean
 */
function returnError($msg = '', $code = 9999, $data = array())
{
    $result = array();//返回结果集
    $result['Message'] = $msg;
    $result['Code'] = $code;
    $result['IsSuccess'] = false;
    if(!empty($data)){
        $result["Data"] = $data;
    }
    return $result;
}

/**
 * 成功信息返回方法
 * @param type $msg错误提示信息
 * @return boolean
 */
function returnSuccess($data = array(), $msg = '')
{
    $result = array();//返回结果集
    $result['Data'] = $data;
    $result['IsSuccess'] = true;
    if($msg != ''){
        $result["Message"] = $msg;
    }
    return $result;
}

/**
 * 对用户的密码进行加密
 * @param $password
 * @param $encrypt //传入加密串，在修改密码时做认证
 * @return array/password
 */
function md5pw($password, $encrypt = '') {
    $pwd = array();
    $pwd['encrypt'] = $encrypt ? $encrypt : create_randomstr();
    $pwd['password'] = md5(md5(trim($password)) . $pwd['encrypt']);
    return $encrypt ? $pwd['password'] : $pwd;
}

/**
 * 生成随机字符串
 * @param string $lenth 长度
 * @return string 字符串
 */
function create_randomstr($lenth = 6) {
    return random($lenth, '123456789abcdefghijklmnpqrstuvwxyzABCDEFGHIJKLMNPQRSTUVWXYZ');
}

/**
 * 产生随机字符串
 *
 * @param    int        $length  输出长度
 * @param    string     $chars   可选的 ，默认为 0123456789
 * @return   string     字符串
 */

function random($length, $chars = '0123456789') {
    $hash = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $hash .= $chars[mt_rand(0, $max)];
    }
    return $hash;
}
/**
 * 生成guid
 * @return
 */
function guid(){
    $str_guid = "";
    if(function_exists('com_create_guid')){
        $str_guid = com_create_guid();
        $str_guid = substr($str_guid, 1);//去掉第一个{
        $str_guid = substr($str_guid, 0, strlen($str_guid)-1);//去掉最后一个}
        return $str_guid;//window下
    }else{//非windows下
        mt_srand((double)microtime()*10000);//optional for php 4.2.0 andup.
        $charid = strtoupper(md5(uniqid(rand(), true)));
        $hyphen = chr(45);//字符 "-"
        $str_guid .= substr($charid, 0, 8).$hyphen
            .substr($charid, 8, 4).$hyphen
            .substr($charid,12, 4).$hyphen
            .substr($charid,16, 4).$hyphen
            .substr($charid,20,12);
        return $str_guid;
    }
}

/**
 * 判断email格式是否正确
 * @param $email
 */
function is_email($email = '') {
    return strlen($email) > 6 && preg_match("/^[\w\-\.]+@[\w\-\.]+(\.\w+)+$/", $email);
}

/**
 * 判断手机号码格式是否正确
 * @param $mobilephone
 */
function is_mobilephone($mobilephone = '') {
    return strlen($mobilephone) > 9 && preg_match("/^13[0-9]{1}[0-9]{8}$|14[0-9]{1}[0-9]{8}$|15[0-9]{1}[0-9]{8}$|18[0-9]{1}[0-9]{8}|17[0-9]{1}[0-9]{8}$/", $mobilephone);
}

/**
 * 判断身份证号码格式是否正确
 * @param $id_number
 */
function is_idnumber($id_number = '') {
    return preg_match("/^[1-9]\d{7}((0\d)|(1[0-2]))(([0|1|2]\d)|3[0-1])\d{3}$|^[1-9]\d{5}[1-9]\d{3}((0\d)|(1[0-2]))(([0|1|2]\d)|3[0-1])\d{3}([0-9]|X)$/", $id_number);
}

/**
 * 获取图片的文件路径（去除图片链接的域名）
 * http://image.***.com/201603/29/1231232131.png
 * return 201603/29/1231232131.png
 */
function getImageRelUrl($http_url) {
    if (strpos($http_url, 'http://') === 0) {
        $array = explode('/', $http_url);
        unset($array[0]);
        unset($array[1]);
        unset($array[2]);
        $url = implode('/', $array);
        return $url;
    }
    return $http_url;
}

/**
 * 生成6位手机验证码
 */
function verificationCode(){
    $code = "";
    $code = $mobcode = rand(100000,999999);
    return $code;
}

/**
 * 获取指定天数当天开始与结束的时间戳
 * @param type $time时间戳或者时间格式字符串
 * @param type $type 1：时间戳  2：时间格式字符串
 */
function getDaySE($time = '', $type = 1){
    $result = array();
    if(empty($time)){
        $time = time();
    }
    if($type != 1){
        $time = strtotime($time);
    }
    $result = array(
        "star_time" => mktime(0,0,0,date("m",$time),date("d",$time),date("Y",$time)),
        "end_time"  => mktime(23,59,59,date("m",$time),date("d",$time),date("Y",$time)),
    );
    return $result;
}

/**
 * 对象转数组
 * @param array $data
 * @return array|mixed|string
 */
function objectToArray($data = [])
{
    $data = json_encode($data);
    $data = json_decode($data, true);
    return $data;
}

/**
 * 数组转对象
 * @param array $data
 * @return array|mixed|string
 */
function arrayToObject($data = [])
{
    $data = json_encode($data, JSON_FORCE_OBJECT);
    $data = json_decode($data);
    return $data;
}