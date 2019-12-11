<?php
namespace app\Controllers;

use app\Models\AppModel;
use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use MongoDB;
//自定义进程

use app\Process\PeerProcess;
use app\Process\VoteProcess;
use app\Process\NodeProcess;
use app\Process\BlockProcess;
use app\Process\TimeClockProcess;
use app\Process\TradingProcess;
use app\Process\SuperNodeProcess;
use app\Process\ConsensusProcess;
use app\Process\TradingPoolProcess;
use Server\Components\Process\ProcessManager;

use Server\Components\CatCache\CatCacheRpcProxy;

class ActionController extends Controller
{
    /**
     * 验签函数
     * @var
     */
    protected $Validation;

    /**
     * 交易处理模型
     * @var
     */
    protected $TradingModel;

    /**
     * 投票处理模型
     * @var
     */
    protected $VoteModel;

    /**
     * 节点质押处理模型
     * @var
     */
    protected $NodeModel;

    /**
     * 加签规则
     * @var
     */
    protected $BitcoinECDSA;


    /**
     * action序列化模型
     * @var
     */
    protected $ActionEncodeModel;

    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        //实例化验签模型
        $this->Validation = $this->loader->model('Trading/ValidationModel', $this);
        //实例化交易模型
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        //实例化交易序列化模型
        $this->ActionEncodeModel = $this->loader->model('Action/ActionEncodeModel', $this);
        //实例化节点模型
        $this->NodeModel = $this->loader->model('Node/NodeModel', $this);
        //实例化投票模型
        $this->VoteModel = $this->loader->model('Node/VoteModel', $this);
        //实例化椭圆曲线加密算法
        $this->BitcoinECDSA = new BitcoinECDSA();

    }

    /**
     * 接收action接口
     */
    public function http_receiveAction()
    {
        //获取action数据
        $action_text = $this->http_input->getAllPostGet()['message'];
        if(empty($action_text)){
            return $this->http_output->notPut(1004);
        }
        //解析数据
        $decode_action['action'] = $this->ActionEncodeModel->decodeAction($action_text);
        if($decode_action['action'] == false){
            return $this->http_output->notPut('', '签名验证失败.');
        }
        //先验证这个action是否提交过
        $check = ProcessManager::getInstance()
                                ->getRpcCall(TradingPoolProcess::class)
                                ->removalDuplicate('', $decode_action['action']['txId']);
        if(!$check['IsSuccess']){
            return $this->http_output->notPut('', '请不要提交重复的action');
        }
        //多余的操作，有时间再优化掉
        $decode_action['address'] = $decode_action['action']['address'];
        switch ($decode_action['action']['actionType']){
            case 2 :
                $res = $this->VoteModel->checkVoteRequest($decode_action, $action_text);
                break;
            case 3 :
                $res = $this->NodeModel->checkNodeRequest($decode_action, $action_text);
                break;
            default:
                $res = $this->TradingModel->checkTradingRequest($decode_action, $action_text);
                break;
        }

        if(!$res['IsSuccess']){
            return $this->http_output->notPut($res['Code'], $res['Message']);
        }
        ProcessManager::getInstance()
                    ->getRpcCall(PeerProcess::class, true)
                    ->broadcast(json_encode(['broadcastType' => 'Action', 'Data' => $action_text]));
        return $this->http_output->yesPut('action提交成功!');
    }

    /**
     * 序列化action
     */
    public function http_encodeAction()
    {
        $action = $this->http_input->getAllPostGet();
        if(empty($action)){
            return $this->http_output->notPut('', '请出入需要序列化的数据.');
        }
        if(empty($action['actionType'])){
            return $this->http_output->notPut('', '请选择action类型.');
        }
        $res = [];
        try{
            switch ($action['actionType']){
                case  2 :
                    $res = $this->ActionEncodeModel->setVout($action['to'])
                        ->setVin($action['tx'])
                        ->setIns($action['ins'])
                        ->setTime(time())
                        ->setLockTime($action['lockTime'])
                        ->setCandidate($action['candidate'])
                        ->setRounds($action['rounds'])
                        ->setVoter($action['voter'])
                        ->setAgain($action['voteAgain'])
                        ->setPublicKey($action['publicKey'])
                        ->setPrivateKey($action['privateKey'])
                        ->setActionType($action['actionType'])
                        ->encodeAction();
                    break;
                case  3 :
                    $res = $this->ActionEncodeModel->setVout($action['to'])
                        ->setVin($action['tx'])
                        ->setIns($action['ins'])
                        ->setTime(time())
                        ->setLockTime($action['lockTime'])
                        ->setPledge($action['pledge'])
                        ->setPledgeNode($action['pledgeNode'])
                        ->setIp($action['ip'])
                        ->setPort($action['port'])
                        ->setPublicKey($action['publicKey'])
                        ->setPrivateKey($action['privateKey'])
                        ->setActionType($action['actionType'])
                        ->encodeAction();
                    break;
                default :
                    $res = $this->ActionEncodeModel->setVout($action['to'])
                        ->setVin($action['tx'])
                        ->setIns($action['ins'])
                        ->setTime(time())
                        ->setLockTime($action['lockTime'])
                        ->setPublicKey($action['publicKey'])
                        ->setPrivateKey($action['privateKey'])
                        ->setActionType($action['actionType'])
                        ->encodeAction();
                    break;
            }
        }catch(\Exception $e){
            return $this->http_output->notPut('', '序列化出错，请传入合规的序列化数据.');
        }
        return $this->http_output->lists($res);
    }

    /**
     * 解析action
     */
    public function http_decodeAction()
    {
        $action = $this->http_input->getAllPostGet();
        $res = $this->ActionEncodeModel->decodeAction($action['action']);
        return $this->http_output->lists($res);
    }



}