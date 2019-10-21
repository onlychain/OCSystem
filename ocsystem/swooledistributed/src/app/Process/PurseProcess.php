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
use app\Models\Trading\TradingEncodeModel;
use MongoDB;

use Server\Components\Process\ProcessManager;
use app\Process\TradingProcess;

class PurseProcess extends Process
{
    /**
     * 钱包同步状态
     * 1:钱包未同步;2:钱包同步中;3:钱包同步完成;4:钱包同步失败;
     * @var bool
     */
    private $PurseState = 1;

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
     * 交易序列化模型
     * @var
     */
    private $EncodeTrading;

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

        //载入交易序列化模型
        $this->EncodeTrading = new TradingEncodeModel();
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
            return returnError('修改失败!');
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
        $aggregation_res = $this->Purse->aggregate($options);
        var_dump($aggregation_res);
        if(!empty($aggregation_res)){
            //把数组对象转为数组
            $aggregation_res = objectToArray($aggregation_res);
        }
        return returnSuccess($aggregation_res);
    }

    /**
     * 同步钱包数据（刷新钱包数据）
     * 同步钱包必须要在交易同步完成后再进行
     * @oneWay
     */
    public function syncPurse()
    {

        $this->setPurseState(2);
        $block_index = 10;//区块页码
        $block_size = 50;//每次处理50个区块
        $flag = true;//是否继续同步
        //获取交易
        while ($flag){
            var_dump('开始同步钱包');
            //先获取区块
            /**
             * **************************************获取区块**************************************
             */
            $block_where = [
                'height' =>
                    [
                        '$gte'    => ($block_index - 1) * $block_size + 1 ,
                        '$lte'    =>  $block_index * $block_size,
                    ]
            ];
            $block_data = ['tradingInfo' => 1, '_id' => 0];
            $block_res = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getBloclHeadList($block_where, $block_data, 1, $block_size, ['height' => 1]);
            var_dump('========================================');
            var_dump($block_where);
            var_dump(count($block_res['Data']));
            if(empty($block_res['Data'])){
                $flag = false;
                break;
            }
            $tradings = [];
            //获取区块的交易txid
            foreach ($block_res['Data'] as $br_key => $br_val){
                $tradings = array_merge($tradings, $br_val['tradingInfo']);
            }

            /**
             * **************************************获取交易**************************************
             */
            $trading_where = ['_id' => ['$in' => $tradings]];//交易条件
            $trading_data = ['_id' => 0];//交易查询的字段
            //获取数据库中的交易
            $trading_res = ProcessManager::getInstance()
                                        ->getRpcCall(TradingProcess::class)
                                        ->getTradingList($trading_where, $trading_data, 1, count($tradings));
            //如果数据为空，代表已经同步完所有数据
            if(empty($trading_res['Data'])){
                $flag = false;
                break;
            }

            /**
             * **************************************处理钱包**************************************
             */
            //循环解析交易，同时拼接钱包数据
            $purses = [];//存储交易数据
            $del_purse = [];//存储要删除的钱包数据(暂时不用)
            //先删除与交易相关的钱包数据
            $this->deletePurseMany(['txId' => ['$in' => $tradings]]);
            //重新刷新钱包
            foreach ($trading_res['Data'] as $tr_key => $tr_val){
                $decode_trading = $this->EncodeTrading->decodeTrading($tr_val['trading']);
                $tx_id = $decode_trading['txId'];
                $lock_time = $decode_trading['lockTime'];
                array_map(function ($vin, $vout) use (&$purses, $tx_id, $lock_time){
                    //处理交易输入,用于删除钱包数据
                    if($vin != null && isset($vin['txId'])){
                        //删除这笔交易,想办法优化，目前获取不到地址
                        $delete_purse = ['txId' => $vin['txId'], 'n' => $vin['n']];
                        $this->deletePurse($delete_purse);
                    }
                    if($vout != null){
                        //新生成的交易
                        $purses[] = [
                            'address'   => $vout['address'],
                            'txId'      => $tx_id,
                            'n'         => $vout['n'],
                            'value'     => $vout['value'],
                            'reqSigs'   => $vout['reqSigs'],
                            'lockTime'  => $lock_time,
                        ];
                    }
                },$decode_trading['vin'], $decode_trading['vout']);
            }
            //把钱包数据插入数据库
            $this->insertPurseMany($purses);
            //页码递增
            ++$block_index;
        }
        var_dump('over');
        $this->setPurseState(3);
        var_dump($this->getPurseState());
    }

    /**
     * 刷新个人钱包，建议不要用，因为要遍历所有的区块与交易，还不如整个钱包进行刷新
     * @param string $address
     * @return bool
     */
    public function rushPurseInfo(string $address = '')
    {
        if($address == ''){
            return returnError('钱包地址不能为空!');
        }
        //先删除数据库中，该地址的所有交易数据
        $delete_where = ['address' => $address];
        $this->deletePurseMany($delete_where);
        //循环从交易中获取该地址的数据
        $block_index = 1;//区块页码
        $block_size = 50;//每次处理50个区块
        $flag = true;//是否继续同步
        //获取交易
        while ($flag){
            //先获取区块
            /**
             * **************************************获取区块**************************************
             */
            $block_where = [
                'height' =>
                    [
                        '$gt'    => ($block_index - 1) > 0 ? (($block_index - 1) * $block_size) + 1 : 1,
                        '$lt'    =>  $block_index * $block_size,
                    ]
            ];
            $block_data = ['tradingInfo' => 1];
            $block_res = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getBloclHeadList($block_where, $block_data, 1, $block_size, ['height' => 1]);
            if(empty($block_res['Data'])){
                $flag = false;
                break;
            }
            $tradings = [];
            //获取区块的交易txid
            foreach ($block_res['Data'] as $br_key => $br_val){
                $tradings = array_merge($tradings, $br_val);
            }
            /**
             * **************************************获取交易**************************************
             */
            $trading_where = ['_id' => ['$in' => $tradings]];//交易条件
            $trading_data = ['_id' => 0];//交易查询的字段
            //获取数据库中的交易
            $trading_res = ProcessManager::getInstance()
                                        ->getRpcCall(TradingProcess::class)
                                        ->getTradingList($trading_where, $trading_data, 1, count($tradings));
            //如果数据为空，代表已经查询完所有的数据
            if(empty($trading_res['Data'])){
                $flag = false;
                break;
            }

            /**
             * **************************************处理钱包**************************************
             */
            //循环解析交易，同时拼接钱包数据
            $purses = [];//存储交易数据
            $del_purse = [];//存储要删除的钱包数据(暂时不用)
            foreach ($trading_res['Data'] as $tr_key => $tr_val){
                $decode_trading = $this->EncodeTrading->decodeTrading($tr_val['trading']);
                $tx_id = $decode_trading['txId'];
                $lock_time = $decode_trading['lockTime'];
                array_map(function ($vin, $vout) use (&$purses, $tx_id, $lock_time, $address){
                    //处理交易输入,用于删除钱包数据
                    if($vin != null && isset($vin['txId'])){
                        //删除这笔交易,想办法优化，目前获取不到地址
                        $delete_purse = ['txId' => $vin['txId'], 'n' => $vin['n'], 'address' => $address];
                        $this->deletePurse($delete_purse);
                    }
                    if($vout != null){
                        //新生成的交易
                        $purses[] = [
                            'address'   => $vout['address'],
                            'txId'      => $tx_id,
                            'n'         => $vout['n'],
                            'value'     => $vout['value'],
                            'reqSigs'   => $vout['reqSigs'],
                            'lockTime'  => $lock_time,
                        ];
                    }
                },$decode_trading['vin'], $decode_trading['vout']);
            }
            //把钱包数据插入数据库
            $this->insertPurseMany($purses);
            //页码递增
            ++$block_index;
        }
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
     * 获取钱包状态
     * @return bool
     */
    public function getPurseState() : int
    {
        return $this->PurseState;
    }

    /**
     * 设置钱包状态
     * @param bool $state
     */
    public function setPurseState(int $state = 1)
    {
        $this->PurseState = $state;
    }


    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "钱包进程关闭.";
    }
}
