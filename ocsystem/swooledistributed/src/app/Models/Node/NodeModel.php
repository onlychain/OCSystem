<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Node;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\PeerProcess;
use app\Process\NodeProcess;
use app\Process\SuperNodeProcess;
use app\Process\BlockProcess;
use app\Process\TradingProcess;
use app\Process\ConsensusProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class NodeModel extends Model
{
    /**
     * 验签函数
     * @var
     */
    protected $Validation;

    /**
     * 交易处理模型
     * @var
     */
    protected $TradingModel;

    /**
     * 交易序列化模型
     * @var
     */
    protected $TradingEncodeModel;

    /**
     * 超级节点状态
     * @var bool
     */
    private $SuperState = false;

    /**
     * 普通节点状态
     * @var bool
     */
    private $NodeState = false;

    /**
     * 加签规则
     * @var
     */
    protected $BitcoinECDSA;

    /**
     * 节点质押缓存
     * @var array
     */
    protected $NodeCache = [];

    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        $this->Validation = $this->loader->model('Trading/ValidationModel', $this);
        //实例化交易模型
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        //实例化交易序列化模型
        $this->TradingEncodeModel = $this->loader->model('Trading/TradingEncodeModel', $this);
        //实例化椭圆曲线加密算法
        $this->BitcoinECDSA = new BitcoinECDSA();
    }

    /**
     * 插入质押数据到数据库
     * @param array $node_data
     * @return bool
     */
    public function submitNode($node_data = [])
    {
        if(empty($node_data)){
            return returnError('请输入节点数据.');
        }
        //先查询节点是否已经存在数据库当中
        $res = [];//操作结果
        $vote = [];//节点数据
        $node_res = [];//节点返回结果
        $node_where = [
            'address'  =>  $node_data['address'],
        ];//查询条件
        $node_info_data = [];//查询字段
        $node_res = ProcessManager::getInstance()
                                    ->getRpcCall(NodeProcess::class)
                                    ->getNodeInfo($node_where, $node_info_data);
        if(empty($node_res['Data'])){
            //如果没有数据
            $node = [
                'address'  =>  $node_data['address'],
                'ip'       =>  $node_data['ip'],
                'port'       =>  $node_data['port'],
                'pledge'    =>  [
                    [
                        'value'     => $node_data['value'],
                        'txId'      => $node_data['txId'],
                        'lockTime'  => $node_data['lockTime'],
                    ],
                ],
                'state' =>  false,
            ];
            $node_data['value'] >= 3000000000000000 && $node['state'] = true;
//            $node_data['value'] >= 30000000000 && $node['state'] = true;
            $node_data['votes'] = 0;

            //插入数据
            $res = ProcessManager::getInstance()
                                    ->getRpcCall(NodeProcess::class)
                                    ->insertNode($node);
        }else{
            $total_pledge = 0;
            $node = $node_res['Data'];
            $node['pledge'][] = [
                'value'     => $node_data['value'],
                'txId'      => $node_data['txId'],
                'lockTime'  => $node_data['lockTime'],
            ];
            $node['ip'] = empty($node_data['ip']) ? $node['ip'] : $node_data['ip'];
            $node['port'] = empty($node_data['port']) ? $node['port'] : $node_data['port'];
            foreach ($node['pledge'] as $np_key => $np_val){
                $total_pledge += $np_val['value'];
            }
            $node['state'] = $total_pledge >= 3000000000000000 ? true : false;
//            $node['state'] = $total_pledge >= 30000000000 ? true : false;
            //修改数据

            $node_where = ['address' => $node['address']];
            $node_data = ['$set' => ['pledge' => $node['pledge'], 'state' => $node['state'], 'ip' => $node['ip'], 'port' => $node['port']]];
            $res = ProcessManager::getInstance()
                                    ->getRpcCall(NodeProcess::class)
                                    ->updateNode($node_where, $node_data);
        }

        if(!$res['IsSuccess'])
            return returnError($res['Message']);

        return returnSuccess();
    }

    /**
     * 验证质押数据是否有误
     * @param array $node_data
     * @return bool
     */
    public function checkNode($node_data = [])
    {
        if(empty($node_data)){
            return returnError('请传入投票验证信息');
        }
        //获取最新的区块高度
        $top_block_height = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getTopBlockHeight();

        /*
         * 验证锁定时间，一般提前两轮进行投票
         * 质押金额必须是整数
         */
        if(!is_numeric($node_data['value']) || strpos($node_data['value'], ".") !== false)
            return returnError('质押金额必须是整数');

        /*
         * 质押时间必须是一年以上
         * 允许有10个块的误差时间
         */
        if(($node_data['lockTime'] - $top_block_height - 15768000) < 0){
            return returnError('质押时间有误');
        }

        //判断action是否已经被提交过
        $node_where = [
            'address'  =>  $node_data['address'],
        ];//查询条件
        $node_info_data = [];//查询字段
        $node_res = ProcessManager::getInstance()
                                ->getRpcCall(NodeProcess::class)
                                ->getNodeInfo($node_where, $node_info_data)['Data'];
        if(!empty($node_res)){
            foreach ($node_res['pledge'] as $np_key => $np_val){
                if($np_val['txId'] == $node_data['txId']){
                    return returnError('该action已经用于质押.');
                }
            }
            //判断质押的修改是否是由节点发起的
            if(!empty($node_data['ip']) &&
                $node_data['address'] != $node_data['pledge'] &&
                $node_data['ip'] != $node_res['ip']){
                return returnError('修改节点ip必须由节点自身发起.');
            }
            if(!empty($node_data['port']) &&
                $node_data['address'] != $node_data['pledge'] &&
                $node_data['port'] != $node_res['port']){
                return returnError('修改节点port必须由节点自身发起.');
            }
        }


        return returnSuccess();
    }

    /**
     *  验证交易数据
     *  @param array $decode_action解析后的action数据
     *  @param array $encode_action未解析的action数据
     *  @param array $type 1：验证交易,不清除交易缓存，2：验证交易，清除交易缓存
     * @return bool
     */
    public function checkNodeRequest(array $decode_action = [], $encode_action = '', $type = 1, $is_broadcast = 1)
    {
        $node_res = [];//投票操作结果
        $check_node = [];//需要验证的投票数据
        $check_node_res = [];//投票验证结果
        $check_trading_res = [];//交易验证结果
        $trading_res = [];//交易操作验证结果
        $check_trading_type = 1;//验证交易类型
        //做交易所有权验证
//        $validation = $this->Validation->varifySign($trading_data);
//        if(!$validation['IsSuccess']){
//            return $this->http_output->notPut($validation['Code'], $validation['Message']);
//        }


        //判断质押类型是否有误
        if($decode_action['action']['actionType'] != 3)
            return returnError('质押类型有误!');

//        if($is_broadcast == 2){
//            //广播数据需要再解一次签名
//            $action_message = $encode_action;
//            $encode_action = $this->BitcoinECDSA->checkSignatureForRawMessage($encode_action)['Data']['action'];
//        }

        $check_node['value'] = $decode_action['action']['trading']['vout'][0]['value'];//质押金额
        $check_node['lockTime'] = $decode_action['action']['trading']['lockTime'];//质押时间
        $check_node['address'] = $decode_action['action']['action']['pledgeNode'];
        $check_node['pledge'] = $decode_action['action']['action']['pledge'];
        $check_node['ip'] = $decode_action['action']['action']['ip'];
        $check_node['port'] = $decode_action['action']['action']['port'];
        $check_node['txId'] = $decode_action['action']['txId'];
        //验证质押详情
        $check_node_res = $this->checkNode($check_node);
        if(!$check_node_res['IsSuccess']){
            return returnError($check_node_res['Message']);
        }
        //写入缓存
        if($type == 1){
            //每次出块都清除一次
            $node_cache = ProcessManager::getInstance()
                                        ->getRpcCall(NodeProcess::class)
                                        ->setNodeCache($decode_action['action']['action']['pledge']);
            if (!$node_cache['IsSuccess']){
                return $node_cache;
            }
            //兼容处理区块出块验证与用户提交验证
            $check_trading_type = 3;
        }
        //验证交易是否可用
        $check_trading_res = ProcessManager::getInstance()
                                        ->getRpcCall(TradingProcess::class)
                                        ->checkTrading($decode_action['action'], $decode_action['action']['address'], $check_trading_type);
        if(!$check_trading_res['IsSuccess'] || empty($check_trading_res['Data'])){
            return returnError($check_trading_res['Message']);
        }
//            //交易入库
//            $trading_res = $this->TradingModel->createTradingEecode($node_data['pledge']);
//            if(!$trading_res['IsSuccess']){
//                return returnError($trading_res['Message']);
//            }


        //交易验证成功，质押写入数据库
//        $check_node['address'] = $decode_action['action']['action']['pledgeNode'];
//        $check_node['ip']   = $decode_action['action']['action']['ip'];
//        $check_node['port'] = $decode_action['action']['action']['port'];
//        $check_node['txId'] = $decode_action['action']['txId'];
//        $node_res = $this->submitNode($check_node);
//        if(!$node_res['IsSuccess']){
//            return returnError($node_res['Message']);
//        }
        //action入库
//        var_dump(2);
        if($type == 1){
//            var_dump(3);
            $insert_res = $this->TradingModel->createTradingEecode($encode_action);
            if(!$insert_res['IsSuccess']){
                return returnError($insert_res['Message'], 'ffffffff');
            }
        }

        if($is_broadcast == 2){
            ProcessManager::getInstance()
                ->getRpcCall(PeerProcess::class, true)
                ->broadcast(json_encode(['broadcastType' => 'Action', 'Data' => $encode_action]));
        }
        return returnSuccess();
    }

    /**
     * 更新广播的超级节点
     * @param array $super_node
     * @return bool
     * @oneWay
     */
    public function syncSuperNode(array $super_node = [])
    {
//        var_dump(1);
        if(empty($super_node)){
            var_dump('节点为空');
            return returnError('节点为空');
        }
        //获取当前节点身份
        $this_node_identity = ProcessManager::getInstance()
                                        ->getRpcCall(ConsensusProcess::class)
                                        ->getNodeIdentity();
        if($this_node_identity == 'core'){
            var_dump('超级节点不进行同步');
            return returnError('超级节点不进行同步.');
        }
        //先删除超级节点数据
        $del_res = ProcessManager::getInstance()
                            ->getRpcCall(SuperNodeProcess::class)
                            ->deleteSuperNodePoolMany();
        if(!$del_res['IsSuccess']){
            return returnError('删除旧数据失败!');
        }
        //插入新的超级节点数据
        ProcessManager::getInstance()
                    ->getRpcCall(SuperNodeProcess::class, true)
                    ->insertSuperNodeMany($super_node);
        return returnSuccess();
    }

    /**
     * 普通节点同步当前轮次节点信息
     * @param array $node
     * @return bool
     * @oneWay
     */
    public function syncNode(array $node = [])
    {
//        var_dump(2);
        if($this->NodeState){
            var_dump('备选节点已同步');
            return returnError('备选节点已同步');
        }
        if(empty($node)){
            var_dump('节点数据为空');
            return returnError('节点数据为空');
        }
        //获取当前节点身份
        $this_node_identity = ProcessManager::getInstance()
                                        ->getRpcCall(ConsensusProcess::class)
                                        ->getNodeIdentity();
        if($this_node_identity == 'core'){
            return returnError('超级节点不进行同步.');
        }
        //先删除普通节点数据
        $del_res = ProcessManager::getInstance()
                                ->getRpcCall(NodeProcess::class)
                                ->deleteNodePoolMany();
        if(!$del_res['IsSuccess']){
            return returnError('删除旧数据失败!');
        }
        //插入新的普通节点数据
        ProcessManager::getInstance()
                    ->getRpcCall(NodeProcess::class, true)
                    ->insertNodeMany($node);
        $this->NodeState = true;
        return returnSuccess();
    }


}
