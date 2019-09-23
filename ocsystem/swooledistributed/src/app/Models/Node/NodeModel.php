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
use app\Process\NodeProcess;
use app\Process\BlockProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class NodeModel extends Model
{


    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
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
                'pledge'    =>  [
                    [
                        'value'     => $node_data['value'],
                        'txId'      => $node_data['txId'],
                        'lockTime'  => $node_data['lockTime'],
                    ],
                ],
            ];
//            $node_data['value'] >= 3000000000000000 && $node['state'] = true;
            $node_data['value'] >= 30000000000 && $node['state'] = true;
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
//            $node['state'] = $total_pledge >= 3000000000000000 ? true : false;
            $node['state'] = $total_pledge >= 30000000000 ? true : false;
            //修改数据

            $node_where = ['address' => $node['address']];
            $node_data = ['$set' => ['pledge' => $node['pledge'], 'state' => $node['state']]];
            $res = ProcessManager::getInstance()
                                    ->getRpcCall(NodeProcess::class)
                                    ->updateNode($node_where, $node_data);
        }

        if(!$res['IsSuccess'])
            return returnError($vote_res['Message']);

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
         * 质押时间必须是一年
         * 允许有10个块的误差时间
         */
        if(abs(($node_data['lockTime'] - $top_block_height - 15768000)) > 10){
            return returnError('质押时间有误');
        }
        return returnSuccess();
    }
}
