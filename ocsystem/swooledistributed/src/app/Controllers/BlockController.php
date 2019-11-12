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
    /**
     * 区块模型
     * @var
     */
    protected $BlockModel;

    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        //调用区块模型
        $this->BlockModel = $this->loader->model('Block/BlockBaseModel', $this);
    }

    /**
     * 接收超级节点区块验证函数
     * @param $block
     * @throws \Exception
     */
    public function tcp_checkBlock($block)
    {
        if(empty($block)){
            return $this->send(0);
        }
        //验证函数
        $block_res = ProcessManager::getInstance()
                                ->getRpcCall(ConsensusProcess::class)
                                ->superCheckBlock($block);
    }

    /**
     * 查询区块接口
     */
    public function http_queryBlock()
    {
        $block = $this->http_input->getAllPostGet();
        if(empty($block['headHash'])){
            return $this->http_output->notPut('', '请传入要查询的区块hash!');
        }
        $query_res = $this->BlockModel->queryBlock($block['headHash']);
        if(!$query_res['IsSuccess']) return $this->http_output->notPut('', '交易异常!');
        //返回查询结果
        return $this->http_output->lists($query_res['Data']);
    }

}