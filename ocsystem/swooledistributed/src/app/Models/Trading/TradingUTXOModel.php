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

class TradingUTXOModel extends Model
{
    /**
     * 存储UTXO来源
     * @var type
     */
    protected $vin = array();

    /**
     * 存储UTXO去向
     * @var type
     */
    protected $vout = array();

    /**
     * 存储备注信息
     * @var type
     */
    protected $ins = array();

    /**
     * 交易确认时间
     * @var type
     */
    protected $time = "";

    /**
     * 交易发起人私钥
     * @var type
     */
    protected $hex = "";

    /**
     * 区块哈希地址（打包之后添加）
     * @var type
     */
    protected $blockHash = "";

    /**
     * 区块编号（打包之后添加）
     * @var type
     */
    protected $blockNumber = 0;

    /**
     * 系统版本
     * @var type
     */
    protected $version = 1;


    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
    }

    /**
     * 设置交易输入
     * @param type $vin_data
     */
    public function setVin($vin_data)
    {
        if (empty($vin_data)) {
            return false;
        }
        //循环拼接交易输出内容
        if(!isset($vin_data['coinbase'])){
            foreach ($vin_data as $vd_k => $vd_val) {
                $this->vin[] = [
                    'txId'  => $vd_val['txId'],
                    'n'     => $vd_val['n'],
                    'scriptSig' => $vd_val['scriptSig'],
                ];
            }
        }else{
            $this->vin[] = $vin_data;
        }
        return $this;
    }

    /**
     * 设置UTXO输出
     * @param type $vout_data
     */
    public function setVout($vout_data)
    {
        if (empty($vout_data)) {
            return false;
        }
        $num = 0;
        foreach ($vout_data as $od_k => $od_v) {
            $this->vout[] = array(
                'value' => $od_v['value'],
                'n' => $num,
                'scriptPubKey' => array(
                    'asm' => $od_v['scriptPubKey']['asm'] ?? '',
                    'hex' => $od_v['scriptPubKey']['hex'] ?? '',
                    'reqSigs' => $od_v['scriptPubKey']['reqSigs'] ?? 1,
                    'type' => $od_v['scriptPubKey']['type'] ?? 'pubkeyhash',
                    'address' => $od_v['scriptPubKey']['address'] ?? $od_v['address'],
                ),
            );
            ++$num;
        }
        return $this;
    }

    /**
     * 设置交易备注信息
     * @param type $ins_data
     */
    public function setIns($ins_data = array())
    {
        if (!empty($ins_data)) {
            $this->ins = array(
                'sideChainHash' => '',//侧链块哈希
                'sideChainNum' => 0,//侧链块编号
                'sideChainMsg' => '',//侧链上传说明信息
            );
        }
        return $this;
    }

    /**
     * 设置交易确认时间
     * @param type $time
     */
    public function setTime($time = 0)
    {
        $this->time = $time;
        return $this;
    }

    /**
     * 设置交易信息hex数据
     * @param type $hex
     */
    public function setHex($hex = '')
    {
        $this->hex = $hex;
        return $this;
    }

    public function setVersion(int $version = 1)
    {
        $this->version = $version;
        return $this;
    }

    /**
     * 加密成UTXO
     * @return array|bool
     */
    public function encryptionUTXO()
    {
        $encryption = array();//加密数据
        if (empty($this->vin)) {
            return false;
        }
        if (empty($this->vout)) {
            return false;
        }
        $encryption = array(
            'hex' => $this->hex,
            'txId'  =>  '',
            'version' => $this->version,
            'lockTime' => 0,
            'vin' => $this->vin,
            'vout' => $this->vout,
            'blockHash' => '',
            'time' => $this->time,
            'blockTime' =>  $this->time,
        );
        $this->ins && $encryption['ins'] = $this->ins;
        $encryption['txId'] = hash('sha3-256', json_encode($encryption));
        $this->resetUtco();
        return $encryption;
    }

    /**
     * 重置对象
     */
    public function resetUtco()
    {
        $this->vin = [];
        $this->vout = [];
        $this->ins = [];
        $this->time = 0;
        $this->hex = '';
        $this->blockHash = '';
        $this->blockNumber = 0;
    }

}
