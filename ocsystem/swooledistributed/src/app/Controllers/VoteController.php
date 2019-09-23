<?php
namespace app\Controllers;

use app\Models\AppModel;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use MongoDB;
//自定义进程
use app\Process\TradingProcess;
use app\Process\BlockProcess;
use app\Process\TradingPoolProcess;
use app\Process\ConsensusProcess;
use app\Process\VoteProcess;
use Server\Components\Process\ProcessManager;

use Server\Components\CatCache\CatCacheRpcProxy;

class VoteController extends Controller
{
    protected $TradingModel;//交易处理模型
    protected $TradingEncodeModel;//交易序列化模型
    protected $VoteModel;//交易序列化模型
    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        $this->TradingEncodeModel = $this->loader->model('Trading/TradingEncodeModel', $this);
        $this->VoteModel = $this->loader->model('Node/VoteModel', $this);
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
        $check_vote['value'] = $decode_trading['vout'][0]['value'];//质押金额
        $check_vote['rounds'] = $vote_data['rounds'];//所投轮次
        $check_vote['lockTime'] = $decode_trading['lockTime'];//质押时间
        $check_vote['voter'] = $vote_data['voter'];//质押人员
        $check_vote_res = $this->VoteModel->checkVote($check_vote);
        if(!$check_vote_res['IsSuccess']) {
            return $this->http_output->notPut('', $check_vote_res['Message']);
        }
        //验证交易是否可用
        $check_trading_res = ProcessManager::getInstance()
                                    ->getRpcCall(TradingProcess::class)
                                    ->checkTrading($decode_trading, $vote_data['voter']);
        if(!$check_trading_res['IsSuccess']){
            return $this->http_output->notPut($check_trading_res['Code'], $check_trading_res['Message']);
        }

        //交易入库
        $trading_res = $this->TradingModel->createTradingEecode($vote_data['pledge']);
        if(!$trading_res['IsSuccess']){
            return $this->http_output->notPut('', $trading_res['Message']);
        }

        //交易验证成功，投票写入数据库
        $check_vote['address'] = $vote_data['address'];
        $vote_res = $this->VoteModel->submitVote($check_vote);
        if(!$vote_res['IsSuccess']){
            return $this->http_output->notPut('', $vote_res['Message']);
        }
        return $this->http_output->yesPut();
    }

}