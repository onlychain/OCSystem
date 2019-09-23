<?php

namespace Server\CoreBase;

/**
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 16-7-29
 * Time: 上午11:22
 */
class HttpOutput
{
    /**
     * http response
     * @var \swoole_http_response
     */
    public $response;

    /**
     * http request
     * @var \swoole_http_request
     */
    public $request;
    /**
     * @var Controller
     */
    protected $controller;

    /**
     * HttpOutput constructor.
     * @param $controller
     */
    public function __construct($controller)
    {
        $this->controller = $controller;
    }

    /**
     * 设置
     * @param $request
     * @param $response
     */
    public function set($request, $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * 重置
     */
    public function reset()
    {
        unset($this->response);
        unset($this->request);
    }

    /**
     * Set HTTP Status Header
     *
     * @param    int    the status code
     * @param    string
     * @return HttpOutPut
     */
    public function setStatusHeader($code = 200)
    {
        if (!$this->controller->canEnd()) {
            return;
        }
        $this->response->status($code);
        return $this;
    }

    /**
     * Set Content-Type Header
     *
     * @param    string $mime_type Extension of the file we're outputting
     * @return    HttpOutPut
     */
    public function setContentType($mime_type)
    {
        if (!$this->controller->canEnd()) {
            return;
        }
        $this->setHeader('Content-Type', $mime_type);
        return $this;
    }

    /**
     * set_header
     * @param $key
     * @param $value
     * @return $this
     */
    public function setHeader($key, $value)
    {
        if (!$this->controller->canEnd()) {
            return;
        }
        $this->response->header($key, $value);
        return $this;
    }

    /**
     * 发送
     * @param string $output
     */
    public function end($output = '')
    {
        if (!$this->controller->canEnd()) {
            return;
        }

        if (is_array($output)||is_object($output)) {
            $this->setHeader('Content-Type','text/html; charset=UTF-8');
            $output = json_encode($output,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        }
        $this->response->end($output);
        $this->controller->endOver();
    }

    /**
     * 设置HTTP响应的cookie信息。此方法参数与PHP的setcookie完全一致。
     * @param string $key
     * @param string $value
     * @param int $expire
     * @param string $path
     * @param string $domain
     * @param bool $secure
     * @param bool $httponly
     */
    public function setCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false)
    {
        if (!$this->controller->canEnd()) {
            return;
        }
        $this->response->cookie($key, $value, $expire, $path, $domain, $secure, $httponly);
    }

    /**
     * 输出文件
     * @param $root_file
     * @param $file_name
     * @return mixed
     */
    public function endFile($root_file, $file_name)
    {
        if (!$this->controller->canEnd()) {
            return null;
        }
        $result = httpEndFile($root_file . '/' . $file_name, $this->request, $this->response);
        $this->controller->endOver();
        return $result;
    }
    /**
     * 输出正确信息
     * @param string $output
     * @param int $code
     * @param bool $gzip
     */
    public function yesPut($output = '', $code = 200, $gzip = true)
    {
        $result = array('code'=>200);//'status' => 'ok',
        if ($code) {
            $pam = get_instance()->config->get('apinote');
            $result ['code'] = intval($code);
            if (!isset($pam[$result ['code']])) {
                $result ['msg'] = $pam['1005'];
            } else {
                $result ['msg'] = $pam[$result ['code']];
            }
        }
        if ($output) {
            $result['msg'] = $output;
        }
        $this->end($result, $gzip);
    }

    /**
     * HTTP 错误信息输出
     * @param int $code
     * @param string $msg
     * @param string $output
     * @param bool $gzip
     */
    public function notPut($code = 9999, $msg = '', $output = '', $gzip = true)
    {
        $result = array('code' => 200, 'msg' => '');//'status' => 'ok',
        if($msg){
            $result['code'] = 9999;
            $result['msg'] = $msg;
        }else{
            $pam = get_instance()->config->get('api_note');
            $result ['code'] = intval($code);
            if (!isset($pam[$result ['code']])) {
                $result['msg'] = $pam['1005'];
            } else {
                $result['msg'] = $msg ? $msg : $pam[$result ['code']];
            }
        }
        if(!empty($output) || $output != ''){
            $result['record'] = $output;
        }
        $this->end($result, $gzip);
    }

    /**
     * 输出数据
     * @param string $output
     * @param string $code
     * @param bool $gzip
     */
    public function lists($output = '', $gzip = true)
    {
        $result = array('code'=>200);//'status' => 'ok',
        $result['record'] = [];
        if ($output) {
            $result['record'] = $output;
        }
        $this->end($result, $gzip);
    }
}