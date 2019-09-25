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
use app\Models\Purse\PurseModel;
use MongoDB;

//自定义进程
use app\Process\BlockProcess;
use app\Process\PurseProcess;
use Server\Components\Process\Process;
use Server\Components\Process\ProcessManager;

class TradingProcess extends Process
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
    private $Trading;

    /**
     * 存储数据库连接地址
     * @var
     */
    private $MongoUrl;

    /**
     * 验证模型
     * @var
     */
    private $Validation;

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
     * 缓存钱包
     * @var
     */
    private $Purses;

    /**
     * 被使用的utxo
     * @var
     */
    private $Using = [];

    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('TradingProcess');
        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->Trading = $this->MongoDB->selectCollection('tradings', 'trading');
        //载入验证模型
        $this->Validation = new ValidationModel();
        //载入交易序列化模型
        $this->EncodeTrading = new TradingEncodeModel();
        //载入钱包模型
        $this->PurseModel = new PurseModel();
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
    public function getTradingList($where = [], $data = [], $page = 1, $pagesize = 10000, $sort = [])
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
        $list_res = $this->Trading->find($filter, $options)->toArray();
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
    public function getTradingInfo($where = [], $data = [], $order_by = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'sort'          =>  $order_by,
        ];
        //获取数据
        $list_res = $this->Trading->findOne($filter, $options);
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 重载钱包(utxo)
     * @return bool
     */
    public function overloadPurse()
    {
        $this->Purses = [];
        $flag = true;
        $where = [];//查询条件
        $data = [];//查询字段
        $pagesize = 10000;//条数
        $page = 1;//页码
        while($flag){
            $del_res = [];//初始化获取结果
            $del_res = $this->getTradingList($where, $data, $page, $pagesize);
            if(empty($del_res['Data'])){
                $flag = false;
                break;
            }
            foreach ($del_res['Data'] as $dr_key => $dr_val){
                //解析交易
                $decode_val = $this->EncodeTrading->decodeTrading($dr_val['trading']);
                $this_txid = $decode_val['txId'];
                $lock_time = $decode_val['lockTime'];
                array_map(function ($vin, $vout) use ($this_txid, $lock_time){
                    $vout != null && $this->Purses[$this_txid][$vout['n']] = [
                                                'value' =>  $vout['value'],
                                                'reqSigs' =>  $vout['reqSigs'],
                                                'lockTime' =>  $lock_time,
                                            ];
                    if(isset($vin['txId'])){
                        unset($this->Purses[$vin['txId']][$vin['n']]);
                        if(empty($this->Purses[$vin['txId']][$vin['n']]))
                        unset($this->Purses[$vin['txId']]);
                    }
                }, $decode_val['vin'], $decode_val['vout']);
                ++$page;
            }
        }
        //写入缓存
        CatCacheRpcProxy::getRpc()['purses'] = $this->Purses;
        $this->purse = [];
        return returnSuccess(CatCacheRpcProxy::getRpc()->offsetGet('purses'));
    }

    /**
     * 交易后刷新钱包
     * @param array $new_trading
     * @return bool
     */
    public function refreshPurse(array $new_trading = [])
    {
        $this->Purses = CatCacheRpcProxy::getRpc()['purses'];
        foreach ($new_trading as $nt_key => $nt_val){
//            $decode_val = $this->EncodeTrading->decodeTrading($dr_val);
            $this_txid = $nt_val['txId'];
            $lock_time = $nt_val['lockTime'];
            array_map(function ($vin, $vout) use ($this_txid, $lock_time){
                $vout != null && $this->Purses[$this_txid][$vout['n']] = [
                    'value'     =>  $vout['value'],
                    'lockTime'  =>  $lock_time,
                    'reqSigs'   =>  $vout['reqSigs'],
                ];
                if(isset($vin['txId'])){
                    unset($this->Purses[$vin['txId']][$vin['n']]);
                    if(empty($this->Purses[$vin['txId']][$vin['n']]))
                        unset($this->Purses[$vin['txId']]);
                }
            }, $nt_val['vin'], $nt_val['vout']);
        }
        CatCacheRpcProxy::getRpc()['purses'] = $this->Purses;
        $this->Purses = [];
        return returnSuccess();
    }

    /**
     * 验证交易同时组装钱包（原始版）
     * @param array $trading
     * @return array|bool
     */
    public function checkTrading2($trading = [], $address = '')
    {

        //定义全局变量
        global $overload;
        global $count;
        $overload = true;
        if(empty($trading)){
            return returnError('请传入交易内容.');
        }
        CatCacheRpcProxy::getRpc()['purses'] = [];
        //获取缓存的utxo
        $purses = CatCacheRpcProxy::getRpc()->offsetGet('purses');
//        $purses = $this->PurseModel->getPurse($address);
        $this->Using = CatCacheRpcProxy::getRpc()->offsetGet('Using');
        if(empty($purses)){
            $purses = $this->overloadPurse()['Data'];
            $overload = false;
        }
        //获取最新的区块高度
        $top_block_height = ProcessManager::getInstance()
                                            ->getRpcCall(BlockProcess::class)
                                            ->getTopBlockHeight();
        $count = 0;
        //查看缓存中是否有
        $availabler_ecords = [//初始化可用交易记录
            'vin'   =>  [],
            'vout'  =>  [],
            'total_cost'    =>  0,
            'total_val'     =>  0,
        ];
        array_map(function ($tx, $to) use ($address, &$purses, &$availabler_ecords, $top_block_height)
        {
            //重新声明两个全局变量
            global $overload;
            global $count;
            if($tx != null && isset($purses[$tx['txId']][$tx['n']])){
                //判断交易是否被锁定
                if($top_block_height > $purses[$tx['txId']][$tx['n']]['lockTime']) return returnError('当前交易不可用');
                //解锁函数
                $check_res = $this->EncodeTrading->checkScriptSig(
                                                         $tx['txId'],
                                                            $tx['n'],
                           $purses[$tx['txId']][$tx['n']]['reqSigs'],
                                                    $tx['scriptSig']
                                                    );
                //赋值组成vin,
                $availabler_ecords['vin'][] = [
                    'txId'  =>  $tx['txId'],
                    'n'     =>  $tx['n'],
                    'scriptSig'     =>  $purses[$tx['txId']][$tx['n']],
                ];
                $availabler_ecords['total_val'] += $purses[$tx['txId']][$tx['n']]['value'];
                //把被使用的交易写入Using
                $this->Using[$tx['txId']][$tx['n']] = $purses[$tx['txId']][$tx['n']];
                if(count($purses[$tx['txId']]) === 1){
                    unset($purses[$tx['txId']]);
                }else{
                    unset($purses[$tx['txId']][$tx['n']]);
                }
            }elseif ($tx != null && $overload){
//                $purses = $this->overloadPurse()['Data'];
                $overload = false;
            }
            //处理地址以及金额
            if($to != null){
                $availabler_ecords['vout'][]    =  [
                    'value'     =>  $to['value'],
                    'type'      =>  1,
                    'address'   =>  $to['address'],

                ];
                $availabler_ecords['total_cost'] += $to['value'];
            }
        }, $trading['vin'], $trading['vout']);
        //判断交易金额是否充足
        if($availabler_ecords['total_val'] < $availabler_ecords['total_cost']){
            return returnError('金额不足.', 1001);
        }elseif($availabler_ecords['total_val'] > $availabler_ecords['total_cost']){
            $availabler_ecords['vout'][]    =  [
                'value'     =>  $availabler_ecords['total_val'] - $availabler_ecords['total_cost'],
                'type'      =>  1,
                'address'   =>  $address,
            ];
        }
        //刷新钱包以及撤回用的utxo
        CatCacheRpcProxy::getRpc()['purses'] = $purses;
        //删除交易

        //存入撤回用缓存
        CatCacheRpcProxy::getRpc()['Using'] = $this->Using;
        //删除全局变量
        $overload = [];
        $count = [];
        return returnSuccess($availabler_ecords);
    }


    /**
     * 验证交易金额是否足够，交易引用是否合法
     * @param array $trading
     * @param string 用户地址
     * @param int $type 1：正常交易，清理钱包以及缓存中的数据
     *                  2：仅仅只做验证，不清理数据
     * @return array|bool
     */
    public function checkTrading($trading = [], $address = '', $type = 1)
    {
        //定义全局变量
        global $del_trading;
        if(empty($trading)){
            return returnError('请传入交易内容.');
        }
        //获取缓存的utxo
        $purse_trading = [
            'txId'  =>  $trading['txId'],
        ];
        $purses = $this->getAvailableTrading($address, $trading['vin']);
        if(!$purses['IsSuccess']){
            return returnError($purses['Message']);
        }
        $purses = $purses['Data'];
//        $purses = $this->PurseModel->getPurse($address, $purse_trading);
        $this->Using = CatCacheRpcProxy::getRpc()->offsetGet('Using');
//        if(empty($purses)){
//            $purses = $this->overloadPurse()['Data'];
//            $overload = false;
//        }
        //获取最新的区块高度
        $top_block_height = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getTopBlockHeight();
        //查看缓存中是否有
        $availabler_ecords = [//初始化可用交易记录
            'vin'   =>  [],
            'vout'  =>  [],
            'total_cost'    =>  0,
            'total_val'     =>  0,
        ];
        /**
         * 同时循环vin与vout进行交易验证
         * $address用户钱包地址
         * $purses用户所用的交易输出
         * $availabler_ecords交易数据统计
         * $top_block_height当前最高区块
         * $type 1:正常交易， 2：不验证锁定时间，不进行交易处理，仅做交易可用性验证
         */
        array_map(function ($tx, $to) use ($address, &$purses, &$availabler_ecords, $top_block_height, $type)
        {
            //重新声明两个全局变量
            global $del_trading;
            if($tx != null && isset($purses[$tx['txId']])){
                var_dump($purses[$tx['txId']]['lockTime']);
                //判断交易是否被锁定
                if($top_block_height < $purses[$tx['txId']]['lockTime'])

//                    return returnError('当前交易不可用');

                //存储待销毁的交易
                $del_trading[] =  $tx['txId'];

                //解锁函数
                $check_res = $this->EncodeTrading->checkScriptSig(
                    $tx['txId'],
                    $tx['n'],
                    $purses[$tx['txId']]['reqSigs'],
                    $tx['scriptSig']
                );
                //赋值组成vin,
                $availabler_ecords['vin'][] = [
                    'txId'  =>  $tx['txId'],
                    'n'     =>  $tx['n'],
                    'scriptSig'     =>  $purses[$tx['txId']],
                ];
                $availabler_ecords['total_val'] += $purses[$tx['txId']]['value'];
                //把被使用的交易写入Using
                $this->Using[$tx['txId']][$tx['n']] = $purses[$tx['txId']];
                if(count($purses[$tx['txId']]) === 1){
                    unset($purses[$tx['txId']]);
                }else{
                    unset($purses[$tx['txId']]);
                }
            }elseif ($tx != null){
//                $purses = $this->overloadPurse()['Data'];
            }
            //处理地址以及金额
            if($to != null){
                $availabler_ecords['vout'][]    =  [
                    'value'     =>  $to['value'],
                    'type'      =>  1,
                    'address'   =>  $to['address'],
                ];
                $availabler_ecords['total_cost'] += $to['value'];
            }
        }, $trading['vin'], $trading['vout']);
        //正常交易验证
        if($type == 1){
            //判断交易金额是否充足
            if($availabler_ecords['total_val'] < $availabler_ecords['total_cost']){
                return returnError('金额不足.', 1001);
            }elseif($availabler_ecords['total_val'] > $availabler_ecords['total_cost']){
                $availabler_ecords['vout'][]    =  [
                    'value'     =>  $availabler_ecords['total_val'] - $availabler_ecords['total_cost'],
                    'type'      =>  1,
                    'address'   =>  $address,
                ];
            }
            //清理缓存
            $purses = $this->PurseModel->rushPurse($address, $purses, $del_trading);
            //存入撤回用缓存
            CatCacheRpcProxy::getRpc()['Using'] = $this->Using;
        }

        //删除全局变量
        $del_trading = [];
        return returnSuccess($availabler_ecords);
    }

    /**
     * 获取需要的钱
     * @param array $address
     * @param array $vin
     * @return array
     */
    protected function getAvailableTrading($address = '', $vin = [])
    {
        //先从缓存中获取数据
        $txids = [];//存储需要的tx
//        $need_txids = [];//需要用到的交易
        foreach ($vin as $v_key => $v_val){
            $txids[] = $v_val['txId'];
//            $need_txids[$v_val['txId']] = $purses[$v_val['txId']];
        }
        $purses = $this->PurseModel->getPurse($address, $txids);
        if($purses == false){
            return returnError('交易输出不存在，请刷新钱包.');
        }
        if(empty($purses)){
            return returnError('钱包为空!');
        }
        return returnSuccess($purses);
    }

    /**
     * 获取需要的钱(归结到一个文档里面的版本)
     * @param array $address
     * @param array $vin
     * @return array
     */
    protected function getPurseTrading($address = '', $vin = [])
    {
        //先从缓存中获取数据
        $txids = [];//存储需要的tx
        $need_txids = [];//需要用到的交易
        $purses = $this->PurseModel->getPurse($address);
        if(!empty($purses)){
            foreach ($vin as $v_key => $v_val){
                if(!empty($purses[$v_val['txId']])){
                    $txids[] = $v_val['txId'];
                    $need_txids[$v_val['txId']] = $purses[$v_val['txId']];
                }
            }
        }
        if(count($vin) == count($txids)){
            return returnSuccess($need_txids);
        }
        //查询不在缓存中的txId
        $where = [
            '_id' => $address,
            'trading' => [
                '$elemMatch' => [
                    'txId' => [
                        '$in' => $txids
                    ]
                ],
            ],
        ];
        $data = [
            'trading' => [
                '$slice' => [
                    1, count($vin) - count($txids)
                ]
            ]
        ];
        $purse_res = ProcessManager::getInstance()
                                ->getRpcCall(PurseProcess::class)
                                ->getPurseList($where, $data, 1, 1);
        if(empty($purse_res['Data']))    return;

        foreach ($purse_res['Data'][0]['trading'] as $pr_key => $pr_val){
            $need_txids[$pr_val['txId']] = $pr_val;
        }

        return returnSuccess($need_txids);
    }

    /**
     * 验证交易是否在钱包里
     * @param array $trading
     * @return array|bool
     */
    public function checkTradingInPursers($trading = [], $address = '')
    {
        //定义全局变量
        $overload = true;
        if(empty($trading)){
            return returnError('请传入交易内容.');
        }
        //获取缓存的utxo
        //改成从方法中获取

        $purses = CatCacheRpcProxy::getRpc()->offsetGet('purses');

        if(empty($purses) && !isset($purses)){
            $purses = $this->overloadPurse()['Data'];
            $overload = false;
        }
        array_map(function ($tx, $to) use ($address, &$purses, &$availabler_ecords, $top_block_height){
            //重新声明两个全局变量
            if($tx != null && isset($purses[$tx['txId']][$tx['n']])){
                //判断交易是否被锁定
                if($top_block_height > $purses[$tx['txId']][$tx['n']]['lockTime']) return returnError('当前交易不可用');
                //解锁函数
                $check_res = $this->EncodeTrading->checkScriptSig(
                    $tx['txId'],
                    $tx['n'],
                    $purses[$tx['txId']][$tx['n']]['reqSigs'],
                    $tx['scriptSig']
                );
                if(!$check_res['IsSuccess']){
                    return returnError($check_res['Message']);
                }
            }else{
                return returnError('交易不可用!');
            }
        }, $trading['vin'], $trading['vout']);
        //刷新钱包以及撤回用的utxo
        //删除全局变量
        return returnSuccess();
    }

    /**
     * 插入单条数据
     * @param array $trading
     * @return bool
     */
    public function insertTrading($trading = [])
    {
        if(empty($trading)) return returnError('交易内容不能为空.');
        $insert_res = $this->Trading->insertOne($trading);
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
    public function insertTradingMany($trading = [], $get_ids = false)
    {
        if(empty($trading)) return returnError('交易内容不能为空.');
//        try {
            $insert_res = $this->Trading->insertMany($trading);

//        } catch (\Exception $e) {
//            throw new \Exception($e);
//        }
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
        $delete_res = $this->Trading->deleteOne($delete_where);
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
        $delete_res = $this->Trading->deleteMany($delete_where);
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
        echo "交易进程关闭.";
    }
}
