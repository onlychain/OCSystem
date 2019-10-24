<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易生成UTXO
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Trading;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
use MongoDB;

//自定义进程
use app\Process\BlockProcess;
use Server\Components\Process\Process;
use Server\Components\Process\ProcessManager;

class CreateTradingModel extends Model
{
    protected $PurseModel;//钱包模型
    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        $this->PurseModel = $this->loader->model('Purse/PurseModel', $this);

    }

    /**
     * 组装交易输入
     * 优先从缓存中获取交易，再从数据库中获取
     * 数据库中优先获取最小的交易金，如果不足转账，再获取十比最大的交易记录
     * 如果十比最大的交易记录仍不满足条件，则不再进行组装，让交易直接失败
     * @param array $trading
     */
    public function toSendMoney($address = '', $value = 0)
    {
        if($address == ''){
            return returnError('地址不能为空!');
        }
        if($value <= 0){
            return returnError('金额有误!');
        }
        //存储交易输入
        $vin = [];
        $vout = [];//找零交易输出
        //用来做金额统计
        $total_value = 0;
        //存储获取的txId
        $txid = [];
        //先从缓存中获取钱包的utxo
        $purse = $this->PurseModel->getPurse($address);
        if(!$purse){
            //直接从数据库中获取,尽量查询最小的几个utxo
            $purse_where = ['address' => $address];
            $purse_data = ['_id' => 0];
            $purse = $this->PurseModel->getPurseFromMongoDb($purse_where, $purse_data, 1, 255, ['value' => -1]);
            if(empty($purse)){
                return returnError('该账户没有交易!');
            }
        }
        //循环拼接交易输入
        foreach ($purse as $p_key => $p_val){
            $vin[] = [
                'txId'       =>  $p_val['txId'],
                'n'          =>  $p_val['n'],
                'scriptSig'  =>  $p_val['reqSigs'],//锁定脚本
            ];
            $txid[] = $p_val['txId'];
            $total_value += $p_val['value'];
            //判断金额是否足够
            if($total_value >= $value){
                break;
            }
        }
        //判断当前获取的交易是否足够使用
        if($total_value < $value && count($txid) < 255 && count($txid) > 0){
            return returnError('交易发起人金额不足.');
        }elseif ($total_value < $value && (count($purse) >= 255 || count($txid) == 0)){
            //从数据库中再获取数据
            $purse_where = ['address' => $address, 'txId' => ['$nin' => $txid]];
            $purse_data = ['_id' => 0];
            $purse = $this->PurseModel->getPurseFromMongoDb($purse_where, $purse_data, 1, 10, ['value' => 1]);
            if(empty($purse)){
                return returnError('该账户没有足够的token!');
            }
            foreach ($purse as $p_key2 => $p_val2){
                //清除交易输入最后一个引用
                array_pop($vin);
                //在交易输入头部插入新的引用
                array_unshift($vin, [
                    'txId'       =>  $p_val2['txId'],
                    'n'          =>  $p_val2['n'],
                  'scriptSig'    =>  $p_val2['reqSigs'],//锁定脚本
                ]);
                $total_value += $p_val2['value'];
                if($total_value >= $value){
                    break;
                }
            }
            //判断金额是否足够
            if($total_value < $value){
                return returnError('该账户没有足够的token!');
            }
        }
        //拼接找零交易输出
        if($total_value > $value){
            $vout = [
                'address'   => $address,
                'value'     => $total_value - $value,
                'type'      =>  1,
            ];
        }
        //返回交易输出
        return returnSuccess(['vin' => $vin, 'vout' => $vout]);
    }

    /**
     * 组装交易输出
     * 输入数组key为地址，val为金额
     * 交易加密方式暂时只有1中，所以先写死
     * @param array $trading
     */
    public function collectMoney($vout_list = [])
    {
        if(empty($vout_list)){
            return returnError('请传入交易输出');
        }
        $total_money = 0;//存储转账总额
        $vout = [];//存储整理好的交易输出
        foreach ($vout_list as $vl_key => $vl_val){
            $vout[] = [
                'address'   => $vl_key,
                'value'     => $vl_val,
                'type'      =>  1,
            ];
            $total_money += abs($vl_val);
        }
        return returnSuccess(['vout' => $vout, 'value' => $total_money]);
    }

    /**
     * 根据锁定方式计算锁定时间
     * @param array $trading
     */
    public function getLockTime($lock_type = 1, $lock_time = 0, $value = 0)
    {
        //获取当前区块的高度
        $top_block_height = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getTopBlockHeight();
        switch ($lock_type){
            //自定义锁仓时间
            case 1: $lock_time = 0;
                    break;
            //投票质押锁仓
            case 2: $lock_time = (floor($value / 100000000) * 300) + $top_block_height;
                break;
            //超级节点质押锁仓
            case 3: $lock_time = $top_block_height + 15768000;
                break;
            //锁死，目前锁200年
            case 4: $lock_time = 4294967295;
                break;
            //默认情况，默认为类型1
            default : $lock_time = 0;
                      $lock_type = 1;
                break;
        }
        return returnSuccess($lock_time);
    }

    /**
     * 生成随机值noce
     * @param string $address用户地址
     * @param int $time交易时间戳
     */
    public function getNoce($address = '', $time = 0)
    {
        return md5($address . $time . mt_rand(100, 999));
    }

    /**
     * 组装交易输入，需要提供相应的交易信息
     * @param array $trading_data组装的交易数据
     * @param int $time交易时间戳
     */
    public function assemblyVin($trading_data = [], $address = '')
    {
        if(empty($trading_data))
            return returnError('请传入要组装的交易.');

        $txids = [];//存储txId
        $purses = [];//存储数据钱包
        //查询交易输入数据
        foreach ($trading_data as $td_key => $td_val){
            $txids[] = $td_val['txId'];
        }

        $purses = $this->PurseModel->getPurse($address, $txids);
        $vin = [];//交易输出
        foreach ($trading_data as $td_key => $td_val){
            $vin[] = [
                'txId'       =>  $td_val['txId'],
                'n'          =>  $td_val['n'],
                'scriptSig'  =>  $td_val['reqSigs'],//锁定脚本
            ];
        }
        return returnSuccess($vin);
    }
}
