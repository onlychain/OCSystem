<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易相关操作自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;

use Server\Components\CatCache\CatCacheRpcProxy;
use app\Models\Trading\ValidationModel;
use app\Models\Trading\TradingEncodeModel;
use Server\Components\Process\Process;

//自定义进程
use app\Process\VoteProcess;
use app\Process\SuperNoteProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class NodeProcess extends Process
{

    /**
     * 存储数据库对象
     * @var
     */
    private $MongoDB;

    /**
     * 确认交易数据集合
     * @var
     */
    private $Node;

    /**
     * 存储数据库连接地址
     * @var
     */
    private $MongoUrl;
    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('NoteProcess');
        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->Node = $this->MongoDB->selectCollection('nodes', 'node');
    }

    /**
     * 获取多条已经入库的交易
     * @param array $where
     * @param array $data
     * @param int $page
     * @param int $pagesize
     * @param array $sort
     * @return bool
     */
    public function getNodeList($where = [], $data = [], $page = 1, $pagesize = 10000, $sort = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'limit'         =>  $pagesize,
            'skip'          =>  ($page - 1) * $pagesize,
        ];
        //获取数据
        $list_res = $this->Node->find($filter, $options)->toArray();
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 获取单条交易数据
     * @param array $where
     * @param array $data
     * @param array $order_by
     * @return bool
     */
    public function getNodeInfo($where = [], $data = [], $order_by = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'sort'          =>  $order_by,
        ];
        //获取数据
        $list_res = $this->Node->findOne($filter, $options);
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 修改单条数据
     * @param array $vote
     * @return bool
     */
    public function updateNode($where = [], $data = [])
    {
        $insert_res = $this->Node->updateOne($where, $data);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess();
    }

    /**
     * 批量修改数据
     * @param array $vote
     * @return bool
     */
    public function updateNodeMany($where = [], $data = [])
    {
        $insert_res = $this->Node->updateMany($where, $data);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess();
    }


    /**
     * 插入单条数据
     * @param array $vote
     * @return bool
     */
    public function insertNode($vote = [])
    {
        if(empty($vote)) return returnError('交易内容不能为空.');
        $insert_res = $this->Node->insertOne($vote);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess(['id' => $insert_res->getInsertedId()->__toString()]);
    }

    /**
     * 插入多条数据
     * @param array $vote
     * @return bool
     */
    public function insertNodeMany($votes = [], $get_ids = false)
    {
        if(empty($votes)) return returnError('交易内容不能为空.');
        $insert_res = $this->Node->insertMany($votes);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        $ids = [];
        if($get_ids){
            foreach ($insert_res->getInsertedIds() as $ir_val){
                $ids[] = $ir_val;
            }
        }
        return returnSuccess(['ids' => $ids]);
    }

    /**
     * 删除单条数据
     * @param array $delete_where
     * @return bool
     */
    public function deleteNodePool(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Node->deleteOne($delete_where);
        if(!$delete_res){
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     * 删除多条数据
     * @param array $delete_where
     * @return bool
     */
    public function deleteNodePoolMany(array $delete_where = [])
    {
        $delete_res = $this->Node->deleteMany($delete_where);
        if(!$delete_res){
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     * 更新超级节点
     * 该方法一定要在每轮节点健康检查方法执行之后再执行
     * @param int $rounds
     * @oneWay
     */
    public function rotationSuperNode(int $rounds = 1)
    {
        //获取此轮参与投票的节点数
        $nodes = [];//存储参与竞选的节点
        $super_nodes = [];//超级节点
        $node_rounds = 0;//当前节点所在顺位顺序
        $new_super_node = [];//新的超级节点
        $node_where = ['state' => true];//查询条件
        $node_data = ['address' => 1, '_id' => 0, 'pledge' => 1];//查询字段
        $nodes = $this->getNodeList($node_where, $node_data);
        if(count($nodes['Data']) < 1){
            //少于21个节点参选，不进行统计
            return returnError();
        }
        foreach ($nodes['Data'] as $nd_val => $nd_key){
            $super_nodes[] = $nd_key['address'];
            //取质押的40%
            $new_super_node[$nd_key['address']]['value'] = floor(array_sum(array_column($nd_key['pledge'], 'value')) * 0.4);
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
            return;
        }
        foreach ($vote_res['Data'] as $vr_key => $vr_val){
            //组装各节点前1000名用户投票数据
            $incentive_users[$vr_val['address']] = [
                'address'   => $vr_val['address'],
                'value'     => $vr_val['value'],
            ];
            //取投票的百分之六十
            $new_super_node[$vr_val['address']]['value'] += floor($vr_val['value'] * 0.6);
        }
        //对值进行排序
        //获取前30个节点
        $new_super_node = array_slice($new_super_node, 0, 30);
        //执行节点健康检查函数
        $count = 1;
        foreach ($new_super_node as $nsn_key => $nsn_val){
            if($nsn_key == get_instance()->config['address']){
                $node_rounds = $count;
            }
            $new_super_node[$nsn_key]['voters'] = $incentive_users[$nsn_key] ?? [];
            $new_super_node[$nsn_key]['address'] = $nsn_key;
            ++$count;
        }
        sort($new_super_node);
        //先删除超级节点数据
        ProcessManager::getInstance()
                        ->getRpcCall(SuperNodeProcess::class)
                        ->deleteSuperNodePoolMany();
        var_dump(123);
        //插入新的超级节点数据
        ProcessManager::getInstance()
                        ->getRpcCall(SuperNodeProcess::class)
                        ->insertSuperNodeMany($new_super_node);

        return returnSuccess($node_rounds);
    }

    /**
     * 对节点进行健康检查,同时更新新的出块节点
     * @param array $nodes
     * @oneWay
     */
    public function examinationNode()
    {
        //先获取是所有节点数目
        $all_node = $this->getNodeList();
        if(empty($all_node['Data'])){
            return false;
        }
        //获取区块高度
        $block_height = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getTopBlockHeight();
        $block_height += 63;
        foreach ($all_node['Data'] as $an_key => &$an_val){

            $count_val = 0;//存储累积的
            if(empty($an_val['pledge'])){
                //没有质押则删除该节点
                unset($all_node['Data'][$an_key]);
                continue;
            }
            //删除主键
            unset($all_node['Data'][$an_key]['_id']);
            foreach ($an_val['pledge'] as $av_key => &$av_val){
                if($av_val['lockTime'] <= $block_height){
                    //下一轮到期，则下一轮判断为过期，不再参加
                    unset($all_node['Data'][$an_key]['pledge'][$av_key]);
                    continue;
                }
                //未过期收集质押金额，判断是否还有资格参与超级节点精选
                $count_val += $av_val['value'];
            }
//            $an_val['state'] = $count_val >= 3000000000000000 ? true : false;
            $an_val['state'] = $count_val >= 30000 ? true : false;
        }
        //删除旧节点数据
        $this->deleteNodePoolMany([]);
        //把更新后的超级节点数据存入数据库
        $this->insertNodeMany($all_node['Data']);
        return returnSuccess();
    }
    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "交易进程关闭.";
    }
}
