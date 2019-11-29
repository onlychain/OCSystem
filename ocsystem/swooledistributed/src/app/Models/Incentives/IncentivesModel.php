<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Incentives;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\IncentivesProcess;
use app\Process\ConsensusProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class IncentivesModel extends Model
{
    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
    }

    /**
     * 更新激励列表
     * @param array $incentives
     * @return bool
     */
    public function syncIncentives($incentives = [])
    {

        $identit = ProcessManager::getInstance()
                                ->getRpcCall(IncentivesProcess::class)
                                ->getNodeIdentity();
        if($identit == 'core'){
            return returnError('超级节点不需要同步激励数据');
        }
        if(empty($incentives)){
            return returnError('激励列表数据为空.');
        }
        //删除激励列表
        ProcessManager::getInstance()
                        ->getRpcCall(IncentivesProcess::class, true)
                        ->deleteIncentivesPoolMany([]);

        //写入新的激励数据
        ProcessManager::getInstance()
                    ->getRpcCall(IncentivesProcess::class, true)
                    ->insertIncentivesMany($incentives);

    }


}
