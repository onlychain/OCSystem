<?php
namespace app\Controllers;

use app\Models\AppModel;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use MongoDB;

//自定义进程
use app\Process\ConsensusProcess;
use Server\Components\Process\ProcessManager;
use Server\Components\CatCache\CatCacheRpcProxy;

class BlockController extends Controller
{

    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
    }

    /**
     * 接收超级节点区块验证函数
     * @param $block
     * @throws \Exception
     */
    public function tcp_checkBlock($block)
    {
        var_dump(52222);
        if(empty($block)){
            return $this->send(0);
        }
        //验证函数
        $block_res = ProcessManager::getInstance()
                                ->getRpcCall(ConsensusProcess::class)
                                ->superCheckBlock($block);
    }

}