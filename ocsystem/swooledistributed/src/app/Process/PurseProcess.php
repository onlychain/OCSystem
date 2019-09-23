<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;

use Server\Components\Process\Process;
use Server\Components\CatCache\CatCacheRpcProxy;
use MongoDB;

use Server\Components\Process\ProcessManager;
use app\Process\TradingPoolProcess;

class PurseProcess extends Process
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
    private $Purse;

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
        var_dump('PurseProcess');
        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->Purse = $this->MongoDB->selectCollection('purses', 'purse');
    }

    /**
     * 获取区块头部数据
     * @param array $where
     * @param array $data
     * @param int $page
     * @param int $pagesize
     * @param array $sort
     * @return bool
     */
    public function getPurseList($where = [], $data = [], $page = 1, $pagesize = 10000, $sort = [])
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

        $list_res = $this->Purse->find($filter, $options)->toArray();
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 获取区块头部单条数据
     * @param array $where
     * @param array $data
     * @return bool
     */
    public function getPurseInfo($where = [], $data = [], $order_by = [])
    {
        $info_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'sort'          =>  [],
        ];
        //获取数据
        $info_res = $this->Purse->findOne($filter, $options);
        if(!empty($info_res)){
            //把数组对象转为数组
            $info_res = objectToArray($info_res);
        }
        return returnSuccess($info_res);
    }

    /**
     * 插入单条数据
     * @param array $trading
     * @return bool
     */
    public function insertPurse($purse = [])
    {
        if(empty($purse)) return returnError('交易内容不能为空.');

        $insert_res = $this->Purse->insertOne($purse);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess(['id' => $insert_res->getInsertedId()]);
    }

    /**
     * 插入多条数据
     * @param array $trading
     * @return bool
     */
    public function insertPurseMany($purses = [], $get_ids = false)
    {
        if(empty($purses)) return returnError('交易内容不能为空.');
        $insert_res = $this->Purse->insertMany($purses);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        $ids = [];
        if($get_ids){
            foreach ($insert_res->getInsertedIds() as $ir_val){
                $ids[] = $ir_val->__toString();
            }
        }
        return returnSuccess(['ids' => $ids]);
    }

    /**
     * 删除单条数据
     * @param array $delete_where
     * @return bool
     */
    public function deletePurse(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Purse->deleteOne($delete_where);
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
    public function deletePurseMany(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Purse->deleteMany($delete_where);
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
    public function updatePurse($where = [], $data = [])
    {
        $insert_res = $this->Purse->updateOne($where, $data);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess();
    }

    /**
     * 聚合操作
     * @param array $where
     * @param array $data
     * @param int $page
     * @param int $pagesize
     * @param array $sort
     * @return bool
     */
    public function getPurseAggregation($match = [], $group = [])
    {
        $list_res = [];//查询结果
        //聚合查询内容
        $options = [
            ['$match'         =>  $match],
            ['$group'         =>  $group],
        ];
        //获取数据
        var_dump($options);
        $aggregation_res = $this->Purse->aggregate($options);
        var_dump($aggregation_res);
        if(!empty($aggregation_res)){
            //把数组对象转为数组
            $aggregation_res = objectToArray($aggregation_res);
        }
        return returnSuccess($aggregation_res);
    }

    /**
     * 批量修改数据
     * @param array $vote
     * @return bool
     */
    public function updatePurseMany($where = [], $data = [])
    {
        $insert_res = $this->Purse->updateMany($where, $data);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess();
    }



    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "区块头进程关闭.";
    }
}
