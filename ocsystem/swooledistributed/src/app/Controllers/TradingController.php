<?php
namespace app\Controllers;

use app\Models\AppModel;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use MongoDB;
//自定义进程
use app\Process\TradingProcess;
use app\Process\TradingPoolProcess;
use app\Process\ConsensusProcess;
use Server\Components\Process\ProcessManager;

use Server\Components\CatCache\CatCacheRpcProxy;

class TradingController extends Controller
{
    protected $Validation;//交易验证模型
    protected $TradingModel;//交易处理模型
    protected $CreateTradingModel;//生成交易模型
    protected $TradingEncodeModel;//交易序列化模型
    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        $this->Validation = $this->loader->model('Trading/ValidationModel', $this);
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        $this->BlockModel = $this->loader->model('Block/BlockBaseModel', $this);
        $this->CreateTradingModel = $this->loader->model('Trading/CreateTradingModel', $this);
        $this->TradingEncodeModel = $this->loader->model('Trading/TradingEncodeModel', $this);
    }

    /**
     * 查看钱包接口
     */
    public function http_checkProus()
    {
        $purses = CatCacheRpcProxy::getRpc()->offsetGet('purses');
        return $this->http_output->lists($purses);
    }

    public function http_createTrading()
    {
        $trading = $this->http_input->getAllPostGet();
        if(empty($trading['from'])){
            return $this->http_output->notPut('', '请输入交易发起人.');
        }
        if(empty($trading['to'])){
            return $this->http_output->notPut('', '请输入交易接收方.');
        }
        if(empty($trading['lockType'])){
            return $this->http_output->notPut('', '请选择锁定方式.');
        }
        if(empty($trading['lockTime'])){
            $trading['lockTime'] = 0;
        }
        //先组装交易输出
        $vout_res = $this->CreateTradingModel->collectMoney($trading['to']);
        if(!$vout_res['IsSuccess']){
            return $this->http_output->notPut('', $vout_res['Message']);
        }
        //组装交易输入
        $vin_res = $this->CreateTradingModel->toSendMoney($trading['from'], $vout_res['Data']['value']);
        if(!$vin_res['IsSuccess']){
            return $this->http_output->notPut('', $vin_res['Message']);
        }
        //获取锁定时间
        $lock_time = $this->CreateTradingModel->getLockTime($trading['lockType'], abs($trading['lockTime']), $vout_res['Data']['value']);
        if(!$lock_time['IsSuccess']){
            return $this->http_output->notPut('', $lock_time['Message']);
        }
        //获取交易随机值
        $noce = $this->CreateTradingModel->getNoce($trading['from'], time());
        //组装
        $trading_res = [
            'tx'          =>  $vin_res['Data'],
            'to'          =>  $vout_res['Data']['vout'],
            'ins'         =>  '',
            'time'        =>  time(),
            'lockTime'    =>  $lock_time['Data'],
            'lockType'    =>  intval($trading['lockType']),
            'noce'        =>  $noce,
        ];
        return $this->http_output->lists($trading_res);
    }

    /**
     * 序列化交易接口
     */
    public function http_encodeTrading()
    {
        $trading = $this->http_input->getAllPostGet();
        $res = $this->TradingEncodeModel->setVin($trading['tx'])
                                        ->setVout($trading['to'])
                                        ->setIns($trading['ins'])
                                        ->setTime($trading['time'])
                                        ->setLockTime($trading['lockTime'])
                                        ->setLockType($trading['lockType'])
                                        ->setPrivateKey($trading['privateKey'])
                                        ->setPublicKey($trading['publicKey'])
                                        ->encodeTrading($trading);
        return $this->http_output->lists($res);
    }

    /**
     * 反序列化交易接口
     */
    public function http_decodeTrading()
    {
        $trading = $this->http_input->getAllPostGet();
        $res = $this->TradingEncodeModel->decodeTrading($trading['trading']);
        return $this->http_output->lists($res);
    }

    /**
     * 接收交易接口
     */
    public function http_receivingTransactions()
    {
        $test_utxo = [];
        $trading = [];//存入
        $insert_res = [];//插入数据库结果
//        $trading_data = $this->http_input->getAllPostGet('text');
        $trading_data = $this->http_input->getAllPostGet();
        //验证是否有上传接口数据
        if(empty($trading_data)){
            return $this->http_output->notPut(1004);
        }

        //做交易所有权验证
//        $validation = $this->Validation->varifySign($trading_data);
//        if(!$validation['IsSuccess']){
//            return $this->http_output->notPut($validation['Code'], $validation['Message']);
//        }
        //广播交易

        //反序列化交易
        $decode_trading = $this->TradingEncodeModel->decodeTrading($trading_data['trading']);
        //空着等对接
        if($trading_data['renoce'] != ''){
            //判断交易质押类型是否可以撤销
            if(in_array($decode_trading['lockType'],[2,3,4])){
                return $this->http_output->notPut('', '该交易无法重置.');
            }
            //执行撤回交易
            $recall = ProcessManager::getInstance()
                                    ->getRpcCall(TradingPoolProcess::class)
                                    ->recallTrading($trading_data, $trading_data['address']);
            if(!$recall['IsSuccess']){
                return $this->http_output->notPut('', '该交易无法重置.');
            }
        }

        //验证交易是否可用$decode_trading;
        $check_res = ProcessManager::getInstance()
                                ->getRpcCall(TradingProcess::class)
                                ->checkTrading($decode_trading, $trading_data['address']);
        if(!$check_res['IsSuccess']){
            return $this->http_output->notPut($check_res['Code'], $check_res['Message']);
        }
        //交易入库
        $insert_res = $this->TradingModel->createTradingEecode($trading_data);
        if(!$insert_res['IsSuccess']){
            return $this->http_output->notPut($insert_res['Code'], $insert_res['Message']);
        }
        return $this->http_output->yesPut();
    }

    /**
     * 查询交易接口
     */
    public function http_queryTrading()
    {
        $trading = $this->http_input->getAllPostGet();
        if(empty($trading['txId'])){
            return $this->http_output->notPut('', '请传入要查询的交易txId!');
        }
        $query_res = $this->TradingModel->queryTrading($trading['txId']);
        if(!$query_res['IsSuccess']) return $this->http_output->notPut('', '交易异常!');
        //返回查询结果
        return $this->http_output->lists($query_res['Data']);
    }

    /**
     * 查询区块接口
     */
    public function http_queryBlock()
    {
        $block = $this->http_input->getAllPostGet();
        if(empty($block['headHash'])){
            return $this->http_output->notPut('', '请传入要查询的区块hash!');
        }
        $query_res = $this->BlockModel->queryBlock($block['headHash']);
        if(!$query_res['IsSuccess']) return $this->http_output->notPut('', '交易异常!');
        //返回查询结果
        return $this->http_output->lists($query_res['Data']);
    }

}