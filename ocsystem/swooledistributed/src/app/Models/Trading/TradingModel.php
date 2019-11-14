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
use app\Process\PeerProcess;
use app\Process\TradingProcess;
use app\Process\TradingPoolProcess;
use app\Process\ConsensusProcess;
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
     * 交易验证模型
     * @var
     */
    protected $Validation;

    /**
     * 区块模型
     * @var
     */
    protected $BlockModel;

    /**
     * 交易处理模型
     * @var
     */
    protected $TradingModel;

    /**
     * 生成交易模型
     * @var
     */
    protected $CreateTradingModel;
    /**
     * 初始化函数
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        $this->utxoModel = $this->loader->model('Trading/TradingUTXOModel', $this);
        //调用验证模型
        $this->Validation = $this->loader->model('Trading/ValidationModel', $this);
        //调用交易模型
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        //调用区块模型
        $this->BlockModel = $this->loader->model('Block/BlockBaseModel', $this);
        //调用创建交易模型
        $this->CreateTradingModel = $this->loader->model('Trading/CreateTradingModel', $this);
        //调用交易序列化模型
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
     * 插入交易池
     * @param array $trading
     * @return bool
     */
    public function createTradingEecode($trading = [])
    {
        //验证交易
        if(empty($trading)) return returnError('请输入交易内容!');
        //把交易存入交易池数据库
        $new_utxo['_id']    = bin2hex(hash('sha256', hex2bin($trading['trading']), true));
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
    public function createTradingMany($tradings = [], $one_way = false)
    {
        $new_utxos = [];//新的交易
        //验证交易
        if(empty($tradings)) return returnError('请输入交易内容!');

        //把交易存入交易池数据库
        foreach ($tradings as $t_key => $t_val){
            $new_utxos[] = [
                '_id'       =>  bin2hex(hash('sha256',  hex2bin($t_val), true)),
                'trading'   =>  $t_val,
                'noce'      =>  'ffffffff',
                'time'      =>  time()
            ];
        }
        $insert_res = ProcessManager::getInstance()
                                    ->getRpcCall(TradingPoolProcess::class, $one_way)
                                    ->insertTradingPoolMany($new_utxos);
        if($one_way){
            return returnSuccess();
        }
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
            if($trading_res == false){
                return returnError('交易有误.');
            }
        }
        return returnSuccess($trading_res);
    }

    /**
     * 查询交易池中的交易
     * @param string $tx_id
     * @param string $noce
     */
    public function queryTradingPool($trading_where = [], $data = [])
    {
        $where = [];
        $trading_pool = [];
        //拼接查询条件
        if(isset($trading_where['txId'])){
            $where['_id'] = $trading_where['txId'];
        }

        if(isset($trading_where['noce'])){
            $where['noce'] = $trading_where['noce'];
        }

        if(empty($where)){
            return returnError('请输入查询条件.');
        }
        $trading_pool = ProcessManager::getInstance()
                                    ->getRpcCall(TradingPoolProcess::class)
                                    ->getTradingPoolInfo($where, $data);
        return returnSuccess($trading_pool['Data']);
    }

    /**
     * 验证交易请求
     * @param array $trading_data
     * @return bool
     */
    public function checkTradingRequest(array $trading_data = [], $type = 1, $is_broadcast = 1)
    {
        //做交易所有权验证
//        $validation = $this->Validation->varifySign($trading_data);
//        if(!$validation['IsSuccess']){
//            return $this->http_output->notPut($validation['Code'], $validation['Message']);
//        }
        //广播交易


        //反序列化交易
        $decode_trading = $this->TradingEncodeModel->decodeTrading($trading_data['trading']);
        if($decode_trading == false){
            return returnError('交易有误.');
        }
        //判断是否是coinbase交易
        if(!empty($decode_trading['vin'][0]['coinbase'])){
            if($type == 2){
                //同步到coinbase交易处理
                $this->delSycnCoinbase($trading_data['trading']);
                return returnSuccess();
            }
            return returnError('交易有误，不能提交coinbase交易.');
        }
        //空着等对接
        if(!empty($trading_data['renoce']) || $trading_data['renoce'] != ''){
            //判断交易质押类型是否可以撤销
            if(in_array($decode_trading['lockType'],[2,3,4])){
                return returnError('该交易无法重置.');
            }
            //执行撤回交易
            $recall = ProcessManager::getInstance()
                                    ->getRpcCall(TradingPoolProcess::class)
                                    ->recallTrading($trading_data, $trading_data['address']);
            if(!$recall['IsSuccess']){
                return returnError('该交易无法重置.');
            }
        }else{
            //查看交易是否已经提交过了
            $check_where = [
                'txId' => $decode_trading['txId'],
            ];
            $check_res = $this->queryTradingPool($check_where);
            if(!empty($check_res['Data'])){
                return returnError('请勿重复提交交易.');
            }
        }
        //验证交易是否可用$decode_trading;
        $check_res = ProcessManager::getInstance()
                                    ->getRpcCall(TradingProcess::class)
                                    ->checkTrading($decode_trading, $trading_data['address']);
        if(!$check_res['IsSuccess']){
            return returnError($check_res['Message'], $check_res['Code']);
        }
        //交易入库
        $insert_res = $this->createTradingEecode($trading_data);
        if(!$insert_res['IsSuccess']){
            return returnError($insert_res['Message'], $insert_res['Code']);
        }
        if($is_broadcast == 2){
            ProcessManager::getInstance()
                            ->getRpcCall(PeerProcess::class, true)
                            ->broadcast(json_encode(['broadcastType' => 'Trading', 'Data' => $trading_data]));
        }
        return returnSuccess();
    }

    /**
     * coinbase交易直接入库
     * @param array $coinbase
     */
    protected function delSycnCoinbase(string $coinbase = '')
    {
        //查看交易是否存在，不存在则存入
        $tx_id = bin2hex(hash('sha256', hex2bin($coinbase), true));
        $check_repeat = $this->queryTrading($tx_id);
        if(!empty($check_repeat['Data'])){
            return returnError('交易已存在');
        }
        //插入交易
        $insert_res = ProcessManager::getInstance()
                                    ->getRpcCall(TradingProcess::class)
                                    ->insertTrading(['_id' => $tx_id, 'trading' => $coinbase]);
        if(!$insert_res['IsSuccess']){
            return returnError('交易同步失败!');
        }
        ProcessManager::getInstance()
                        ->getRpcCall(ConsensusProcess::class, true)
                        ->bookedPurse([$coinbase]);
        return returnSuccess();
    }
}
