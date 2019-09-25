<?php
namespace app\Controllers;

use app\Models\AppModel;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use MongoDB;
//自定义进程

use app\Process\VoteProcess;
use app\Process\NodeProcess;
use app\Process\BlockProcess;
use app\Process\TimeClockProcess;
use app\Process\TradingProcess;
use app\Process\SuperNodeProcess;
use app\Process\ConsensusProcess;
use app\Process\TradingPoolProcess;
use Server\Components\Process\ProcessManager;

use Server\Components\CatCache\CatCacheRpcProxy;

class NodeController extends Controller
{
    protected $TradingModel;//交易处理模型
    protected $TradingEncodeModel;//交易序列化模型
    protected $NodeModel;//交易序列化模型
    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        $this->Validation = $this->loader->model('Trading/ValidationModel', $this);
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        $this->TradingEncodeModel = $this->loader->model('Trading/TradingEncodeModel', $this);
        $this->NodeModel = $this->loader->model('Node/NodeModel', $this);
    }

    /**
     * 质押接口
     */
    public function http_campaign()
    {
        $node_data = $this->http_input->getAllPostGet();
        if(empty($node_data)){
            return $this->http_output->notPut(1004);
        }
        $node_res = [];//投票操作结果
        $check_node = [];//需要验证的投票数据
        $check_node_res = [];//投票验证结果
        $check_trading_res = [];//交易验证结果
        $decode_trading = [];//交易解析后的数据
        $trading_res = [];//交易操作验证结果
        //做交易所有权验证
//        $validation = $this->Validation->varifySign($trading_data);
//        if(!$validation['IsSuccess']){
//            return $this->http_output->notPut($validation['Code'], $validation['Message']);
//        }

        //广播交易

        //反序列化交易['pledge']
        $decode_trading = $this->TradingEncodeModel->decodeTrading($node_data['pledge']['trading']);
        //判断质押类型是否有误
        if($decode_trading['lockType'] != 3)  return $this->http_output->notPut('', '质押类型有误!');

        $check_node['value'] = $decode_trading['vout'][0]['value'];//质押金额
        $check_node['lockTime'] = $decode_trading['lockTime'];//质押时间
        $check_node_res = $this->NodeModel->checkNode($check_node);
        if(!$check_node_res['IsSuccess']){
            return $this->http_output->notPut('', $check_node_res['Message']);
        }
        //验证交易是否可用
        $check_trading_res = ProcessManager::getInstance()
                                    ->getRpcCall(TradingProcess::class)
                                    ->checkTrading($decode_trading, $node_data['address']);
        if(!$check_trading_res['IsSuccess']){
            return $this->http_output->notPut($check_trading_res['Code'], $check_trading_res['Message']);
        }

        //交易入库
        $trading_res = $this->TradingModel->createTradingEecode($node_data['pledge']);
        if(!$trading_res['IsSuccess']){
            return $this->http_output->notPut('', $trading_res['Message']);
        }
        //交易验证成功，投票写入数据库
        $check_node['address'] = $node_data['address'];
        $check_node['txId'] = $decode_trading['txId'];
        $node_res = $this->NodeModel->submitNode($check_node);
        if(!$node_res['IsSuccess']){
            return $this->http_output->notPut('', $node_res['Message']);
        }
        return $this->http_output->yesPut();
    }

    /**
     * 获取竞选的超级节点信息
     */
    public function http_getNodeList()
    {
        //获取此轮参与投票的节点数
        $nodes = [];//存储参与竞选的节点
        $super_nodes = [];//超级节点
        $new_super_node = [];//新的超级节点
        $node_where = ['state' => true];//查询条件
        $node_data = ['address' => 1, '_id' => 0, 'pledge' => 1];//查询字段
        $nodes = ProcessManager::getInstance()
                                ->getRpcCall(NodeProcess::class)
                                ->getNodeList($node_where, $node_data);
        if(count($nodes['Data']) < 1){
            //少于21个节点参选，不进行统计
            return $this->http_output->notPut('', '节点数为空!');
        }
        foreach ($nodes['Data'] as $nd_val => $nd_key){
            $super_nodes[] = $nd_key['address'];
            //取质押数
            $new_super_node[$nd_key['address']]['pledge'] = $nd_key['pledge'];
            $new_super_node[$nd_key['address']]['vote'] = 0;
        }
        //先获取下一轮的投票结果,先设定获取一百万条数据
        $incentive_users = [];//可以享受激励的一千个用户地址
        $vote_where = ['rounds' => $rounds, 'address' => ['$in' => $super_nodes]];
        $vote_sort = ['value' => -1];
        $vote_res = ProcessManager::getInstance()
                                    ->getRpcCall(VoteProcess::class)
                                    ->getVoteList($vote_where, [], 1, 1000000);
        if(empty($vote_res['Data'])){
            //没有投票数据，不再执行
            return $this->http_output->notPut('', '投票数为0');
        }
        foreach ($vote_res['Data'] as $vr_key => $vr_val){
            //组装各节点前1000名用户投票数据
            $incentive_users[$vr_val['address']][] = [
                'address'   => $vr_val['address'],
                'value'     => $vr_val['value'],
            ];
            //取投票总质押
            $new_super_node[$vr_val['address']]['totalVote'] += $vr_val['value'];
        }
        //拼接节点数据
        foreach ($new_super_node as $nsn_key => $nsn_val){
            $new_super_node[$nsn_key]['voters'] = $incentive_users[$nsn_key];
            $new_super_node[$nsn_key]['address'] = $nsn_key;
        }
        //重新排列key
        sort($new_super_node);
        return $this->http_output->lists($new_super_node);
    }

    /**
     * 获取当前工作的超级节点信息
     */
    public function http_getSuperNodeList()
    {
        $super_nodes = [];//存储超级节点数据
        $super_where = [];//查询条件
        $super_data = ['_id' => 0];//查询字段
        $super_nodes = ProcessManager::getInstance()
                                        ->getRpcCall(SuperNodeProcess::class)
                                        ->getVoteList($super_where, $super_data, 1, 1000);
        return $this->http_output->lists($super_nodes['Data']);

    }


    public function http_examinationNode()
    {
        $super_nodes = ProcessManager::getInstance()
                                    ->getRpcCall(TimeClockProcess::class)
                                    ->runTimeClock();

    }

}