<?php
namespace app\Controllers;

use app\Models\AppModel;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use MongoDB;
//自定义进程

use Server\Components\Process\ProcessManager;

use Server\Components\CatCache\CatCacheRpcProxy;

class TimeController extends Controller
{
    /**
     * 时间钟模型
     * @var
     */
    protected $TimeModel;

    /**
     * 初始化函数
     * @param string $controller_name
     * @param string $method_name
     */
    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        $this->TimeModel = $this->loader->model('TimeClocl/TimeModel', $this);
    }

    /**
     * 获取当前时间钟与轮次数据
     * @param $param
     * @throws \Exception
     */
    public function tcp_getTimeClock($param)
    {
        $res = $this->TimeModel->getRoundAndTime();
        return $this->send($res);
    }
}