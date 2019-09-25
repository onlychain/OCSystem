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
use Server\Components\Process\ProcessManager;
use MongoDB;

class IncentivesProcess extends Process
{

    /**
     * 存储数据库对象
     * @var
     */
    private $MongoDB;

    /**
     * 确认激励表集合
     * @var
     */
    private $Incentives;

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
        var_dump('IncentivesProcess');
        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->Incentives = $this->MongoDB->selectCollection('Incentives', 'Incentives');
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
    public function getIncentivesList($where = [], $data = [], $page = 1, $pagesize = 10000, $sort = [])
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
        $list_res = $this->Incentives->find($filter, $options)->toArray();
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
    public function getIncentivesInfo($where = [], $data = [], $order_by = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'sort'          =>  $order_by,
        ];
        //获取数据
        $list_res = $this->Incentives->findOne($filter, $options);
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
    public function updateIncentives($where = [], $data = [])
    {
        $insert_res = $this->Incentives->updateOne($where, ['$set' => $data]);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess();
    }

    /**
     * 修改多条数据
     * @param array $vote
     * @return bool
     */
    public function updateIncentivesMany($where = [], $data = [])
    {
        $insert_res = $this->Incentives->updateMany($where, $data);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess();
    }


    /**
     * 插入单条数据
     * @param array $incentives
     * @return bool
     */
    public function insertIncentives($incentives = [])
    {
        if(empty($incentives)) return returnError('交易内容不能为空.');
        $insert_res = $this->Incentives->insertOne($incentives);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess(['id' => $insert_res->getInsertedId()->__toString()]);
    }

    /**
     * 插入多条数据
     * @param array $incentives
     * @return bool
     */
    public function insertIncentivesMany($incentives = [], $get_ids = false)
    {
        if(empty($incentives)) return returnError('交易内容不能为空.');
        $insert_res = $this->Incentives->insertMany($incentives);
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
    public function deleteIncentivesPool(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Incentives->deleteOne($delete_where);
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
    public function deleteIncentivesPoolMany(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Incentives->deleteMany($delete_where);
        if(!$delete_res){
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     * 更新激励表
     */
    public function updateIncentivesTable(array $incentives = [], $table_num = 0)
    {
        //先修改缓存
        CatCacheRpcProxy::getRpc()['Incentives'] = $incentives;
        //修改数据库
        $table_where = ['_id' => $table_num];
        $this->updateIncentives($table_where, $incentives[$table_num]);
        return returnSuccess();
    }

    /**
     * 获取激励表
     */
    public function getIncentivesTable()
    {
        $incentives = CatCacheRpcProxy::getRpc()->offsetGet('Incentives');
        if(empty($incentives)) $incentives = $this->setIncentivesTable()['Data'];
        return returnSuccess($incentives);
    }

    /**
     * 设置激励表
     */
    public function setIncentivesTable()
    {
        $incentives = $this->getIncentivesList();
        //如果数据库里面没有数据，插入最原始的数据，同时发起共识从别的节点获取数据
        if(empty($incentives['Data'])){
            $incentives = $this->originalTable();
        }
        CatCacheRpcProxy::getRpc()['Incentives'] = $incentives['Data'];
        return returnSuccess($incentives['Data']);
    }

    /**
     * 载入激励数据表
     */
    public function originalTable()
    {
        //发起共识，请求数据

        //没有数据的话，从配置文件中载入数据
        if(empty($incentives)){
            $incentives = get_instance()->config['incentives'];
            foreach ($incentives as $i_key => &$i_val){
                $i_val['_id'] = $i_key;
            }
        }
        //插入数据库当中
        $this->insertIncentivesMany($incentives);
        return returnSuccess($incentives);
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
