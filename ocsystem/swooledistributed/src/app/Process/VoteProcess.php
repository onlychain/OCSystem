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
use app\Process\BlockProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class VoteProcess extends Process
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
    private $Vote;

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
        var_dump('VoteProcess');
        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->Vote = $this->MongoDB->selectCollection('nodes', 'vote');
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
    public function getVoteList($where = [], $data = [], $page = 1, $pagesize = 10000, $sort = [])
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
        $list_res = $this->Vote->find($filter, $options)->toArray();
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 聚合操作数据库
     * @param array $where
     * @param array $data
     * @param int $page
     * @param int $pagesize
     * @param array $sort
     * @return bool
     */
    public function getVoteAggregation($match = [], $ops = [], $page = 1, $pagesize = 10000, $sort = [])
    {
        $list_res = [];//查询结果
        //聚合查询内容
        $options = [
            ['$match'         =>  $match],
            ['$group'         =>  $ops],
//            ['$sort'          =>  $sort],
            ['$limit'         =>  $pagesize],
            ['$skip'          =>  ($page - 1) * $pagesize],
        ];
        //获取数据
        $aggregation_res = $this->Vote->aggregate($options);
        if(!empty($aggregation_res)){
            //把数组对象转为数组
            $aggregation_res = objectToArray($aggregation_res);
        }
        return returnSuccess($aggregation_res);
    }

    /**
     * 获取单条交易数据
     * @param array $where
     * @param array $data
     * @param array $order_by
     * @return bool
     */
    public function getVoteInfo($where = [], $data = [], $order_by = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'sort'          =>  $order_by,
        ];
        //获取数据
        $list_res = $this->Vote->findOne($filter, $options);
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }



    /**
     * 插入单条数据
     * @param array $vote
     * @return bool
     */
    public function insertVote($vote = [])
    {
        if(empty($vote)) return returnError('交易内容不能为空.');
        $insert_res = $this->Vote->insertOne($vote);
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
    public function insertVoteMany($votes = [], $get_ids = false)
    {
        if(empty($votes)) return returnError('交易内容不能为空.');
        $insert_res = $this->Vote->insertMany($votes);
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
    public function deleteVotePool(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Vote->deleteOne($delete_where);
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
    public function deleteVotePoolMany(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Vote->deleteMany($delete_where);
        if(!$delete_res){
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     * 修改单条数据
     * @param array $vote
     * @return bool
     */
    public function updateVote($where = [], $data = [])
    {
        $insert_res = $this->Vote->updateOne($where, $data, ['upsert' => true]);
        var_dump($insert_res);
        var_dump($where);
        var_dump($data);
        if(!$insert_res->isAcknowledged()){
            return returnError('修改失败!');
        }
        return returnSuccess();
    }

    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "投票进程关闭.";
    }
}
