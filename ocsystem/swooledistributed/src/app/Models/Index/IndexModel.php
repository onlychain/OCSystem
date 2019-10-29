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
use app\Process\BlockProcess;
use app\Process\TradingProcess;
use app\Process\PurseProcess;
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
        //判断创世区块是否存在，不存在则插入
        $genesis_block = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->checkGenesisBlock();
        //定期循环检查








        //验证区块数据是否同步完成

        //验证时间钟是否同步

        //时间钟同步函数，验证时间钟是否同步
//        $this->runTimeClock();
//        $time_check_res = ProcessManager::getInstance()
//                                        ->getRpcCall(TimeClockProcess::class)
//                                        ->checkTimeClock();
//        if(!$time_check_res['IsSuccess']){
//            return;
//        }
//        ProcessManager::getInstance()
//                        ->getRpcCall(TimeClockProcess::class, true)
//                        ->runTimeClock();

        /**
         * 开启共识
         */
//        var_dump('chukuai');
//        $identity = ProcessManager::getInstance()
//                            ->getRpcCall(ConsensusProcess::class, true)
//                            ->coreNode();
        return;

        //验证数据是否同步完成
        /**
         * ============================================同步区块数据============================================
         */
        var_dump('同步区块');
        $block_state = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getBlockState();
        var_dump($block_state);
        if($block_state == 1){
            //交易未同步，开始同步函数
            $block_res = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class, true)
                                    ->syncBlock();

            return;
        }elseif($block_state != 3){
            //交易同步中，未同步完成，等待同步结束
            var_dump('区块同步未完成');
            return;
        }

        /**
         * ============================================同步交易数据============================================
         */
        var_dump('同步交易');
        $trading_state = ProcessManager::getInstance()
                                    ->getRpcCall(TradingProcess::class)
                                    ->getTradingState();
        var_dump($trading_state);
        if($trading_state == 1){
            //交易未同步，开始同步函数
            ProcessManager::getInstance()
                            ->getRpcCall(TradingProcess::class, true)
                            ->syncTrading();

            return;
        }elseif($trading_state != 3){
            //交易同步中，未同步完成，等待同步结束
            var_dump('交易同步未完成');
            return;
        }

        /**
         * ============================================同步钱包数据============================================
         */
        var_dump('同步钱包');
        $purse_state = ProcessManager::getInstance()
                                    ->getRpcCall(PurseProcess::class)
                                    ->getPurseState();
        var_dump($purse_state);
        if($purse_state == 1){
            var_dump('========================================');
            //交易未同步，开始同步函数
            $block_state = ProcessManager::getInstance()
                                        ->getRpcCall(PurseProcess::class, true)
                                        ->syncPurse();
            return;
        }elseif($purse_state == 2){
            //交易同步中，未同步完成，等待同步结束
            var_dump('钱包同步未完成');
            return;
        }


        //开启共识开关
//        $identity = ProcessManager::getInstance()
//                                    ->getRpcCall(ConsensusProcess::class, true)
//                                    ->setOpenConsensus();
//
//        $identity = ProcessManager::getInstance()
//                                    ->getRpcCall(ConsensusProcess::class, true)
//                                    ->coreNode();

        //每三十秒检查一次
//        sleepCoroutine(30000);

    }


    public function syncData()
    {

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
                ->runTimeClock();
        return true;
    }

    public function openState()
    {
        $time = date('Y-m-d-H-i-s', time());
        $time = explode('-', $time);
        var_dump('当前秒针' . $time[5]);
        if($time[5] == '30' || $time[5] == '00'){
            var_dump('开启状态');
            ProcessManager::getInstance()
                ->getRpcCall(TimeClockProcess::class, true)
                ->openClock();
//            ProcessManager::getInstance()
//                ->getRpcCall(ConsensusProcess::class, true)
//                ->openConsensus();
//            var_dump('出块');
//            ProcessManager::getInstance()
//                ->getRpcCall(ConsensusProcess::class, true)
//                ->coreNode();
        }
    }
}
