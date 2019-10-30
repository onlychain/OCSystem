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
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\PeerProcess;
use app\Process\NodeProcess;
use app\Process\BlockProcess;
use app\Process\TradingProcess;
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
            foreach ($node['pledge'] as $np_key => $np_val){
                $total_pledge += $np_val['value'];
            }
            $node['state'] = $total_pledge >= 3000000000000000 ? true : false;
//            $node['state'] = $total_pledge >= 30000000000 ? true : false;
            //修改数据

            $node_where = ['address' => $node['address']];
            $node_data = ['$set' => ['pledge' => $node['pledge'], 'state' => $node['state']]];
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
        return returnSuccess();
    }

    /**
     * 验证交易数据
     * @param array $node_data
     * @return bool
     */
    public function checkNodeRequest(array $node_data = [], $type = 1, $is_broadcast = 1)
    {
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

        //反序列化交易['pledge']
        $decode_trading = $this->TradingEncodeModel->decodeTrading($node_data['pledge']['trading']);
        //判断质押类型是否有误
        if($decode_trading['lockType'] != 3)
            return returnError('质押类型有误!');

        $check_node['value'] = $decode_trading['vout'][0]['value'];//质押金额
        $check_node['lockTime'] = $decode_trading['lockTime'];//质押时间
        $check_node_res = $this->checkNode($check_node);
        if(!$check_node_res['IsSuccess']){
            return returnError($check_node_res['Message']);
        }
        if($type == 1){
            //验证交易是否可用
            $check_trading_res = ProcessManager::getInstance()
                                            ->getRpcCall(TradingProcess::class)
                                            ->checkTrading($decode_trading, $node_data['pledge']['address']);
            if(!$check_trading_res['IsSuccess']){
                return returnError($check_trading_res['Message'], $check_trading_res['Code']);
            }
            //交易入库
            $trading_res = $this->TradingModel->createTradingEecode($node_data['pledge']);
            if(!$trading_res['IsSuccess']){
                return returnError($trading_res['Message']);
            }
        }

        //交易验证成功，质押写入数据库
        $check_node['address'] = $node_data['address'];
        $check_node['ip']   = $node_data['ip'];
        $check_node['port'] = $node_data['port'];
        $check_node['txId'] = $decode_trading['txId'];
        $node_res = $this->submitNode($check_node);
        if(!$node_res['IsSuccess']){
            return returnError($node_res['Message']);
        }
        if($is_broadcast == 2){
            ProcessManager::getInstance()
                ->getRpcCall(PeerProcess::class, true)
                ->broadcast(json_encode(['broadcastType' => 'Pledge', 'Data' => $node_data]));
        }
        return returnSuccess();
    }
}
