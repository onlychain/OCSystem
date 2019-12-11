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

class SuperNodeProcess extends Process
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
    private $SuperNode;

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
//        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoUrl = 'mongodb://' . MONGO_IP . ":" . MONGO_PORT;
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->SuperNode = $this->MongoDB->selectCollection('nodes', 'superNode');
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
    public function getSuperNodeList($where = [], $data = [], $page = 1, $pagesize = 10000, $sort = [])
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
        $list_res = $this->SuperNode->find($filter, $options)->toArray();
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
    public function getSuperNodeInfo($where = [], $data = [], $order_by = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'sort'          =>  $order_by,
        ];
        //获取数据
        $list_res = $this->SuperNode->findOne($filter, $options);
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
    public function updateSuperNode($where = [], $data = [])
    {
        $insert_res = $this->SuperNode->updateOne($where, $data);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess(['id' => $insert_res->getInsertedId()->__toString()]);
    }

    /**
     * 修改单条数据
     * @param array $vote
     * @return bool
     */
    public function updateSuperNodeMany($where = [], $data = [])
    {
        $insert_res = $this->SuperNode->updateMany($where, $data);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess(['id' => $insert_res->getInsertedId()->__toString()]);
    }

    /**
     * 插入单条数据
     * @param array $vote
     * @return bool
     */
    public function insertSuperNode($vote = [])
    {
        if(empty($vote)) return returnError('交易内容不能为空.');
        $insert_res = $this->SuperNode->insertOne($vote);
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
    public function insertSuperNodeMany($votes = [], $get_ids = false)
    {
        if(empty($votes)) return returnError('交易内容不能为空.');
        $insert_res = $this->SuperNode->insertMany($votes);
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
    public function deleteSuperNodePool(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->SuperNode->deleteOne($delete_where);
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
    public function deleteSuperNodePoolMany(array $delete_where = [])
    {
        $delete_res = $this->SuperNode->deleteMany($delete_where);
        if(!$delete_res){
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "超级节点进程关闭.";
    }
}
