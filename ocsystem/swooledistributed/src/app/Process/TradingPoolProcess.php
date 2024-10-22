<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易相关操作自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;

use app\Models\Trading\TradingEncodeModel;
use app\Models\Action\ActionEncodeModel;
use app\Models\Purse\PurseModel;

use Server\Components\CatCache\CatCacheRpcProxy;
use Server\Components\Process\Process;
use MongoDB;

class TradingPoolProcess extends Process
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
    private $TradingPool;

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
     * 钱包模型
     * @var
     */
    private $PurseModel;

    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('TradingPoolProcess');
//        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoUrl = 'mongodb://' . MONGO_IP . ":" . MONGO_PORT;
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->TradingPool = $this->MongoDB->selectCollection('tradings', 'tradingPool');
        //载交易序列化模型
//        $this->EncodeTrading = new TradingEncodeModel();
        $this->EncodeTrading = new ActionEncodeModel();
        //钱包模型
        $this->PurseModel = new PurseModel();
    }

    /**
     * 创建交易池索引
     * @return bool
     */
    public function createdTradingPoolIndex()
    {
        $this->TradingPool->createIndexes(
            [
                ['key' => ['_id' => 1]],
                ['key' => ['noce' => 1]],
            ]
        );
        return returnSuccess();
    }

    /**
     * 获取交易池内的交易数据
     * @param array $where
     * @param array $data
     * @param int $page
     * @param int $pagesize
     * @param array $sort
     * @return bool
     */
    public function getTradingPoolList($where = [], $data = [], $page = 1, $pagesize = 10000, $sort = [])
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
        $list_res = $this->TradingPool->find($filter, $options)->toArray();
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 获取单条交易池内的交易数据
     * @param array $where
     * @param array $data
     * @param array $order_by
     * @return bool
     */
    public function getTradingPoolInfo($where = [], $data = [], $order_by = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'sort'          =>  $order_by,
        ];
        //获取数据
        $list_res = $this->TradingPool->findOne($filter, $options);
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 插入单条数据
     * @param array $trading
     * @return bool
     */
    public function insertTradingPool($trading = [])
    {
        if(empty($trading)) return returnError('交易内容不能为空.');
        $trading['time'] = time();
        $insert_res = $this->TradingPool->insertOne($trading);
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
    public function insertTradingPoolMany($trading = [], $get_ids = false)
    {
        if(empty($trading)) return returnError('交易内容不能为空.');
        $insert_res = $this->TradingPool->insertMany($trading);
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
    public function deleteTradingPool(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->TradingPool->deleteOne($delete_where);
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
    public function deleteTradingPoolMany(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
//        if(isset($delete_where['_id'])){
//            $new_where = [];
//            foreach ($delete_where['_id'] as $dw_key => $dw_val){
//                foreach ($dw_val as $dev_key => $dwv_val){
//                    $new_where['_id'][$dw_key][] = new MongoDB\BSON\ObjectID($dwv_val);
//                }
//            }
//        }
//        unset($delete_where['_id']);
//        $delete_where['_id'] = $new_where['_id'];
        $delete_res = $this->TradingPool->deleteMany($delete_where);
        if(!$delete_res){
            var_dump(66666);
            var_dump($delete_res);
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     *  撤回交易
     * @param array $recall_trading
     * @return bool
     */
    public function recallTrading2(array $recall_trading = [])
    {
        if(empty($recall_trading)){
            return returnError('请输入要替换的交易!');
        }
        //先查询交易
        $where = [
            '_id' => bin2hex(hash('sha256', hash('sha256', hex2bin($recall_trading['trading']), true), true)),
            'noce' => $recall_trading['renoce']
        ];
        $data = [];
        $old_trading = $this->getTradingPoolInfo($where, $data);
        if(empty($old_trading['Data'])){
            return returnError('没有改交易或交易已被打包.');
        }
        $old_trading = $this->EncodeTrading->decodeTrading($old_trading['Data']['trading']);
        //删除该交易
        $del_where = [
//            '_id'   =>  bin2hex(hash('sha256', hash('sha256', hex2bin($recall_trading['trading']), true), true)),
            'noce'  =>  $recall_trading['renoce'],
        ];
        $del_trading = $this->deleteTradingPool($del_where);
        //刷新钱包，把恢复的交易写入缓存
        //先用比较不合适的写法，后期考虑优化
        $purses = CatCacheRpcProxy::getRpc()['purses'];//[$ot_val['txId']][$ot_val['n']];
        $using = CatCacheRpcProxy::getRpc()['Using'];
        foreach ($old_trading['vin'] as $ot_key => $ot_val){
            $purses[$ot_val['txId']][$ot_val['n']] = $using[$ot_val['txId']][$ot_val['n']];
            unset($using[$ot_val['txId']][$ot_val['n']]);
        }
        CatCacheRpcProxy::getRpc()['purses'] = $purses;
        CatCacheRpcProxy::getRpc()['Using'] = $using;
        //返回消息
        return returnSuccess();
    }

    /**
     *  撤回交易
     * @param array $recall_trading
     * @return bool
     */
    public function recallTrading(array $recall_trading = [], string $address = '')
    {
        $purses = [];//钱包
        if(empty($recall_trading)){
            return returnError('请输入要替换的交易!');
        }
        //先查询交易
        $where = ['_id'  => bin2hex(hash('sha256', hash('sha256', hex2bin($recall_trading['trading']), true), true)),
                  'noce' => $recall_trading['renoce']];
        $data = [];
        $old_trading = $this->getTradingPoolInfo($where, $data);
        if(empty($old_trading['Data'])){
            return returnError('没有改交易或交易已被打包.');
        }
        $old_trading = $this->EncodeTrading->decodeTrading($old_trading['Data']['trading']);
        //删除该交易
        $del_where = [
            '_id'   =>  bin2hex( hash('sha256', hash('sha256', hex2bin($recall_trading['trading']), true), true)),
            'noce'  =>  $recall_trading['renoce'],
        ];
        $del_trading = $this->deleteTradingPool($del_where);
        //刷新钱包，把恢复的交易写入缓存
        //先用比较不合适的写法，后期考虑优化
        $using = CatCacheRpcProxy::getRpc()['Using'];
        foreach ($old_trading['vin'] as $ot_key => $ot_val){
            $purses[$ot_val['txId']] = $using[$ot_val['txId']][$ot_val['n']];
            $purses[$ot_val['txId']]['n'] = $ot_val['n'];
            $purses[$ot_val['txId']]['address'] = $address;
            unset($using[$ot_val['txId']][$ot_val['n']]);
        }
        //把撤回的交易插入缓存
        $this->PurseModel->setPurse($address, $purses);

        //把撤回的数据插入数据库
        sort($purses);
        $this->PurseModel->addPurseTradings($purses);

        CatCacheRpcProxy::getRpc()['Using'] = $using;
        //返回消息
        return returnSuccess();
    }


    /**
     * 退还交易缓存数据
     * @param array $action_text
     * @return bool
     * @oneWay
     */
    public function refundTradingCache(array $action_text = [])
    {
        $purses = [];//钱包
        if(empty($action_text)){
            return returnError('请输入要退还的交易!');
        }
        //先查询交易
        $where = ['_id'  => ['$in' => $action_text]];
        $data = [];
        $old_trading = $this->getTradingPoolList($where, $data);
        if(empty($old_trading['Data'])){
            return returnError('没有改交易或交易已被打包.');
        }

        //删除该交易
        foreach ($old_trading['Data'] as $ot_key => $ot_val){
            $decode_action = $this->EncodeTrading->decodeAction($ot_val['trading']);
            if(!$decode_action){
                continue;
            }
            if(in_array($decode_action['actionType'], [5, 6, 7, 8])){
                continue;
            }
            if (!empty($decode_action['trading'])){
                foreach ($decode_action['trading']['vin'] as $ott_key => $ott_val){
                    $purses = CatCacheRpcProxy::getRpc()['Using' . $ott_val['txId'] . $ott_val['n']];
                    $insert_purse[] = [
                        'address'       =>  $purses['address'],
                        'txId'          =>  $purses['txId'],
                        'n'             =>  $purses['n'],
                        'value'         =>  $purses['value'],
                        'reqSigs'       =>  $purses['reqSigs'],
                        'lockTime'      =>  $purses['lockTime'],
                        'createdBlock'  =>  $purses['createdBlock'],
                        'actionType'    =>  $purses['actionType'],
                    ];
                    unset(CatCacheRpcProxy::getRpc()['Using' . $ott_val['txId'] . $ott_val['n']]);
                }
            }
        }
        //删除交易池数据
        $del_trading = $this->deleteTradingPool($where);
        //刷新钱包，把恢复的交易写入钱包数据库
        //把撤回的数据插入数据库
        $this->PurseModel->addPurseTradings($insert_purse);
        //返回消息
        return returnSuccess();
    }

    /**
     * 验证action是否已经存在
     * @param string $action
     * @return bool
     */
    public function removalDuplicate(string $action = '', string $action_txid = '')
    {
        if($action == '' && $action_txid != ''){
            $tx_id = bin2hex(hash('sha256', hash('sha256', hex2bin($action), true), true));
        }elseif($action != '' && $action_txid == ''){
            $tx_id = $action_txid;
        }else{
            return returnError('请传入action或者actionTxId任意一个数据.');
        }

        $where = [
            '_id' => $tx_id
        ];
        $res = $this->getTradingPoolInfo($where);
        if(!empty($res['Data'])){
            return returnError('action已存在.');
        }
        return returnSuccess();
    }

    /**
     * 退还交易缓存数据
     * @param array $action_text
     * @return bool
     * @oneWay
     */
    public function clearTradingCache()
    {

    }

    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "交易池进程关闭.";
    }
}
