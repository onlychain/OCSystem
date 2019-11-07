<?php
namespace app\Controllers;

use app\Models\AppModel;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use MongoDB;
//自定义进程
use app\Process\PeerProcess;
use app\Process\TradingProcess;
use app\Process\BlockProcess;
use app\Process\TradingPoolProcess;
use app\Process\ConsensusProcess;
use app\Process\VoteProcess;
use Server\Components\Process\ProcessManager;

use Server\Components\CatCache\CatCacheRpcProxy;

class VoteController extends Controller
{
    /**
     * 交易处理模型
     * @var
     */
    protected $TradingModel;

    /**
     * 交易序列化模型
     * @var
     */
    protected $TradingEncodeModel;

    /**
     * 交易序列化模型
     * @var
     */
    protected $VoteModel;

    /**
     * 组装交易模型
     * @var
     */
    protected $CreateTradingModel;
    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        //调用交易模型
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        //调用交易序列化模型
        $this->TradingEncodeModel = $this->loader->model('Trading/TradingEncodeModel', $this);
        //调用投票模型
        $this->VoteModel = $this->loader->model('Node/VoteModel', $this);
        //调用生成交易模型
        $this->CreateTradingModel = $this->loader->model('Trading/CreateTradingModel', $this);
    }

    /**
     * 投票接口1
     */
    public function http_vote1()
    {
        $vote_data = $this->http_input->getAllPostGet();
        if(empty($vote_data)){
            return $this->http_output->notPut(1004);
        }
        $vote_res = [];//投票操作结果
        $check_vote = [];//需要验证的投票数据
        $check_vote_res = [];//投票验证结果
        $check_trading_res = [];//交易验证结果
        $trading_res = [];//交易操作验证结果
        //做交易所有权验证
//        $validation = $this->Validation->varifySign($trading_data);
//        if(!$validation['IsSuccess']){
//            return $this->http_output->notPut($validation['Code'], $validation['Message']);
//        }

        //广播交易

        //反序列化交易
        $decode_trading = $this->TradingEncodeModel->decodeTrading($vote_data['pledge']['trading']);
        if($decode_trading['lockType'] != 2){
            return $this->http_output->notPut('', '质押类型有误.');
        }
        $check_vote['value'] = $decode_trading['vout'][0]['value'] ?? 0;//质押金额(循环获取)
        $check_vote['rounds'] = $vote_data['rounds'];//所投轮次
        $check_vote['lockTime'] = $decode_trading['lockTime'];//质押时间
        $check_vote['voter'] = $vote_data['voter'];//质押人员
        $vote_type = $vote_data['voteAgain'] ?? 1;//投票类型
        //根据投票类型，插入质押的txId
        if($vote_type == 1){
            $check_vote['txId'][$decode_trading['txId']] = $decode_trading['txId'];
        }else{
            //重质押获取vin中的txId
            $check_vote['txId'][$decode_trading['txId']] = $decode_trading['txId'];
            foreach ($decode_trading['vin'] as $dt_val){
                $check_vote['txId'][$dt_val['txId']] = $dt_val['txId'];
            }
        }
        $check_vote_res = $this->VoteModel->checkVote($check_vote, $vote_type);
        if(!$check_vote_res['IsSuccess']) {
            return $this->http_output->notPut('', $check_vote_res['Message']);
        }
        //验证交易是否可用
        $check_trading_res = ProcessManager::getInstance()
                                            ->getRpcCall(TradingProcess::class)
                                            ->checkTrading($decode_trading, $vote_data['voter'], $vote_type);
        if(!$check_trading_res['IsSuccess']){
            return $this->http_output->notPut($check_trading_res['Code'], $check_trading_res['Message']);
        }

        if($vote_type == 1){
            //交易入库
            $trading_res = $this->TradingModel->createTradingEecode($vote_data['pledge']);
            if(!$trading_res['IsSuccess']){
                return $this->http_output->notPut('', $trading_res['Message']);
            }
        }
        //验证后再进行广播，防止垃圾信息污染网络
        p2p_broadcast(json_encode(['broadcastType' => 'Vote', 'Data' => $vote_data]));
        //交易验证成功，投票写入数据库
        $check_vote['address'] = $vote_data['address'];
        //重置序号
        sort($check_vote['txId']);

        $vote_res = $this->VoteModel->submitVote($check_vote);
        if(!$vote_res['IsSuccess']){
            return $this->http_output->notPut('', $vote_res['Message']);
        }
        return $this->http_output->yesPut();
    }

    /**
     * 投票接口
     */
    public function http_vote()
    {
        $vote_data = $this->http_input->getAllPostGet();
        if(empty($vote_data)){
            return $this->http_output->notPut(1004);
        }
        $res = $this->VoteModel->checkVoteRequest($vote_data);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut($res['Code'], $res['Message']);
        }
        //验证后再进行广播，防止垃圾信息污染网络
        ProcessManager::getInstance()
                    ->getRpcCall(PeerProcess::class, true)
                    ->broadcast(json_encode(['broadcastType' => 'Vote', 'Data' => $vote_data]));
        return $this->http_output->yesPut();
    }

    /**
     * 生成重复质押交易接口
     */
    public function http_createVoteAgain()
    {
        $vote_data = $this->http_input->getAllPostGet();
        if(empty($vote_data['lockTrading']))
            return $this->http_output->notPut('', '请输入质押的交易内容.');

        //组装交易输入
        $vote_res = $this->CreateTradingModel->assemblyVin($vote_data['lockTrading'], $vote_data['address']);
        if(!$vote_res['IsSuccess'])
            return $this->http_output->notPut('', $vote_res['Message']);

        //序列化交易
        $vote_trading = $this->TradingEncodeModel->setVin($vote_res['Data'])
                                                ->setVout($vote_data['to'])
                                                ->setIns('')
                                                ->setTime(time())
                                                ->setLockTime($vote_data['lockTime'])
                                                ->setLockType(2)
                                                ->setPrivateKey($vote_data['privateKey'])
                                                ->setPublicKey($vote_data['publicKey'])
                                                ->encodeTrading();
        return $this->http_output->lists($vote_trading);
    }

}