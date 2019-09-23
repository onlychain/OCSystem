<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Index;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\TimeClockProcess;
use app\Process\ConsensusProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class IndexModel extends Model
{
    /**
     *
     * @var int
     */
    public $threshold = 1;


    /**
     * 初始化函数，每次启动都要执行，用来开启项目进程
     * 每次执行之前，都要检查时间钟同步、数据、身份
     */
    public function index()
    {
        var_dump('==================初始函数==================');
        //验证区块数据是否同步完成

        //验证时间钟是否同步

        //开启时间钟开关
//        $this->runTimeClock();openClock
        ProcessManager::getInstance()
                        ->getRpcCall(TimeClockProcess::class, true)
                        ->openClock();
        ProcessManager::getInstance()
                        ->getRpcCall(TimeClockProcess::class, true)
                        ->runTimeClock();
        //开启共识开关
        $identity = ProcessManager::getInstance()
                                    ->getRpcCall(ConsensusProcess::class, true)
                                    ->setOpenConsensus();

        $identity = ProcessManager::getInstance()
                                    ->getRpcCall(ConsensusProcess::class, true)
                                    ->coreNode();


    }

    /**
     * 执行工作程序
     */
    public function runConsensus()
    {
        ProcessManager::getInstance()
                        ->getRpcCall(ConsensusProcess::class, true)
                        ->chooseWork();
    }

    /**
     * 启动时间钟
     */
    public function runTimeClock()
    {
        ProcessManager::getInstance()
                ->getRpcCall(TimeClockProcess::class, true)
                ->runTimerClock();
        return true;
    }
}
