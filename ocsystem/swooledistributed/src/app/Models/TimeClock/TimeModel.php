<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\TimeClock;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\PurseProcess;
use app\Process\TimeClockProcess;
use app\Process\ConsensusProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class TimeModel extends Model
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
     * 返回当前轮次与时间钟
     * @return array
     */
    public function getRoundAndTime()
    {
        $identity = rocessManager::getInstance()
                                ->getRpcCall(ConsensusProcess::class)
                                ->setNodeIdentity();
        if($identity != 'core'){
            $time = false;
            $round = false;
        }else{
            $time = ProcessManager::getInstance()
                ->getRpcCall(TimeClockProcess::class)
                ->getTimeClock();

            $round = ProcessManager::getInstance()
                ->getRpcCall(TimeClockProcess::class)
                ->getRounds();
        }



        return [
            'time'      =>  $time,
            'rounds'    =>  $round,
            'id'        =>  get_instance()->config['address'],
        ];
    }

}
