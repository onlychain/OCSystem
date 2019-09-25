<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 生成utxo
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Trading;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程

use app\Process\BlockProcess;
use app\Process\TradingProcess;
use app\Process\TradingPoolProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class TradingModel extends Model
{
    /**
     * 生成utxo模型
     * @var
     */
    protected $utxoModel;

    /**
     * 交易序列化与反序列化
     * @var
     */
    protected $TradingEncodeModel;

    /**
     * 初始化函数
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        $this->utxoModel = $this->loader->model('Trading/TradingUTXOModel', $this);
        $this->TradingEncodeModel = $this->loader->model('Trading/TradingEncodeModel', $this);
    }

    /**
     * 拼装成utxo同时插入交易
     * @param array $trading
     * @return bool
     */
    public function createTradingDecode($trading = [])
    {
        //验证交易
        if(empty($trading)) return returnError('请输入交易内容!');
        //组装成utxo
        $new_utxo = $this->utxoModel->setVin($trading['vin'])
                                        ->setVout($trading['vout'])
                                        ->setIns($trading['ins'])
                                        ->setVersion(get_instance()->config['coinbase']['version'])
                                        ->setTime($trading['time'])
                                        ->setHex($trading['hex'])
                                        ->encryptionUTXO();
        //把交易存入交易池数据库
        $insert_res = ProcessManager::getInstance()
                                ->getRpcCall(TradingPoolProcess::class)
                                ->insertTradingPool($new_utxo);
        if(!$insert_res['IsSuccess']){
            return returnError('交易异常!', 1005);
        }

        return returnSuccess($new_utxo);
    }

    /**
     * 插入交易
     * @param array $trading
     * @return bool
     */
    public function createTradingEecode($trading = [])
    {
        //验证交易
        if(empty($trading)) return returnError('请输入交易内容!');
        //把交易存入交易池数据库
        $new_utxo['_id']    = bin2hex(hash('sha256', hash('sha256', hex2bin($trading['trading']), true), true));
        $new_utxo['trading'] = $trading['trading'];
        $new_utxo['noce'] = $trading['noce'];
        $insert_res = ProcessManager::getInstance()
                            ->getRpcCall(TradingPoolProcess::class)
                            ->insertTradingPool($new_utxo);
        if(!$insert_res['IsSuccess']){
            return returnError('交易异常!', 1005);
        }

        return returnSuccess($new_utxo);
    }

    /**
     * 批量插入交易
     * @param array $trading
     * @return bool
     */
    public function createTradingMany($tradings = [])
    {
        $new_utxos = [];//新的交易
        //验证交易
        if(empty($tradings)) return returnError('请输入交易内容!');

        //把交易存入交易池数据库
        foreach ($tradings as $t_key => $t_val){
            $new_utxos[] = [
                '_id'       =>  bin2hex(hash('sha256', hash('sha256', hex2bin($t_val), true), true)),
                'trading'   =>  $t_val,
                'noce'      =>  'ffffffff',
            ];
        }
        $insert_res = ProcessManager::getInstance()
                                    ->getRpcCall(TradingPoolProcess::class)
                                    ->insertTradingPoolMany($new_utxos);
        if(!$insert_res['IsSuccess']){
            return returnError('交易异常!', 1005);
        }

        return returnSuccess($new_utxos);
    }

    /**
     * 查询交易
     * @param string $trading_txid
     * @return bool
     */
    public function queryTrading($trading_txid = '')
    {
        if($trading_txid == ''){
            return returnError('txId不能为空');
        }
        $trading_res = [];//交易返回结果
        //设置查询条件
        $where = ['_id' => $trading_txid];
        //设置查询字段
        $data = [];
        //查询交易
        $trading = ProcessManager::getInstance()
                                ->getRpcCall(TradingProcess::class)
                                ->getTradingInfo($where, $data);
        if(!empty($trading['Data'])){
            //把交易反序列化
            $trading_res = $this->TradingEncodeModel->decodeTrading($trading['Data']['trading']);
        }
        return returnSuccess($trading_res);
    }
}
