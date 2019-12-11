<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Action;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\PurseProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class ActionModel extends Model
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


    private $IndexCacheName = 'PurseTable';
    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
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
    }

    public function checkAction($action_str = '')
    {
        if($action_str == ''){
            return returnError('请传入序列化的action请求.');
        }
        //解析action
        $decode_action = $this->ActionEncodeModel->decodeAction($action_str);
        switch ($decode_action['actionType']){
            case 2 :
                $res = $this->VoteModel->checkVoteRequest($decode_action);
                break;
            case 3 :
                $res = $this->NodeModel->checkNodeRequest($decode_action);
                break;
            default:
                $res = $this->TradingModel->checkTradingRequest($decode_action);
                break;
        }
        if($res['IsSuccess']){
            return returnError($res['Message']);
        }
        return returnSuccess();
    }

}
