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
     * 是否检查过区块
     * @var bool
     */
    private $checkState = false;


    /**
     * 初始化函数，每次启动都要执行，用来开启项目进程
     * 每次执行之前，都要检查时间钟同步、数据、身份
     */
    public function index()
    {
        var_dump('==================初始函数==================');
        if(!$this->checkState){
            //判断创世区块是否存在，不存在则插入
            $genesis_block = ProcessManager::getInstance()
                                            ->getRpcCall(BlockProcess::class)
                                            ->checkGenesisBlock();
            if ($genesis_block['IsSuccess']){
                $this->checkState = true;
            }
        }

        $clock_state = ProcessManager::getInstance()
                                    ->getRpcCall(TimeClockProcess::class)
                                    ->getClockState();
        $sync_clock_height = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getSyncBlockTopHeight();

        //如果到时间了仍然没有同步到数据，则作为初始节点启动
        $time = date('Y-m-d-H-i-s', time());
        $time = explode('-', $time);
        var_dump('当前秒针' . $time[5]);
        if($time[5] == '00' || $time[5] == '30'){
            if(!$clock_state && $sync_clock_height < 10){
                ProcessManager::getInstance()
                                ->getRpcCall(BlockProcess::class, true)
                                ->setBlockState(3);
                ProcessManager::getInstance()
                                ->getRpcCall(TradingProcess::class, true)
                                ->setTradingState(3);
                ProcessManager::getInstance()
                                ->getRpcCall(PurseProcess::class, true)
                                ->setPurseState(3);
                var_dump('初始节点,开启时间钟');
                ProcessManager::getInstance()
                                ->getRpcCall(TimeClockProcess::class, true)
                                ->delClock(0);
            }
        }

        if($sync_clock_height > 1){
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
//        /**
//         * ============================================同步交易数据============================================
//         */
//        var_dump('同步交易');
//        $trading_state = ProcessManager::getInstance()
//                                    ->getRpcCall(TradingProcess::class)
//                                    ->getTradingState();
//        var_dump($trading_state);
//        if($trading_state == 1){
//            //交易未同步，开始同步函数
//            ProcessManager::getInstance()
//                            ->getRpcCall(TradingProcess::class, true)
//                            ->syncTrading();
//
//            return;
//        }elseif($trading_state != 3){
//            //交易同步中，未同步完成，等待同步结束
//            var_dump('交易同步未完成');
//            return;
//        }
//
//        /**
//         * ============================================同步钱包数据============================================
//         */
//        var_dump('同步钱包');
//        $purse_state = ProcessManager::getInstance()
//                                    ->getRpcCall(PurseProcess::class)
//                                    ->getPurseState();
//        var_dump($purse_state);
//        if($purse_state == 1){
//            var_dump('========================================');
//            //交易未同步，开始同步函数
//            $block_state = ProcessManager::getInstance()
//                                        ->getRpcCall(PurseProcess::class, true)
//                                        ->syncPurse();
//            return;
//        }elseif($purse_state != 3){
//            //交易同步中，未同步完成，等待同步结束
//            var_dump('钱包同步未完成');
//            return;
//        }
            //如果同步到数据了且时间钟没有启动，开启时间钟
            if(!$clock_state){
                //获取最高的区块的时间
                $system_time = 0;
                $top_block = ProcessManager::getInstance()
                    ->getRpcCall(BlockProcess::class)
                    ->getBloclHeadList([], [], 1, 1, ['height' => -1]);//getBlockHeadInfo
                $system_time = $top_block['Data'][0]['thisTime'];
                ProcessManager::getInstance()
                    ->getRpcCall(TimeClockProcess::class, true)
                    ->delClock($system_time);
            }
        }
        //启动任务
        ProcessManager::getInstance()
                        ->getRpcCall(TimeClockProcess::class, true)
                        ->runTimeClock();

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

    /**
     * 创世区块使用，判断是否要启动节点
     */
    public function openState()
    {
        $time = date('Y-m-d-H-i-s', time());
        $time = explode('-', $time);
        var_dump('当前秒针' . $time[5]);
        if($time[5] == '00' || $time[5] == '30'){

            $clock_state = ProcessManager::getInstance()
                                ->getRpcCall(TimeClockProcess::class)
                                ->getClockState();
            $sync_clock_height = ProcessManager::getInstance()
                                            ->getRpcCall(BlockProcess::class)
                                            ->getSyncBlockTopHeight();//getTopBlockHeight
            var_dump($sync_clock_height);
            if(!$clock_state && $sync_clock_height < 10){
                ProcessManager::getInstance()
                                ->getRpcCall(BlockProcess::class, true)
                                ->setBlockState(3);
                ProcessManager::getInstance()
                                ->getRpcCall(TradingProcess::class, true)
                                ->setTradingState(3);
                ProcessManager::getInstance()
                                    ->getRpcCall(PurseProcess::class, true)
                                    ->setPurseState(3);
                var_dump('初始节点,开启时间钟');
                ProcessManager::getInstance()
                                ->getRpcCall(TimeClockProcess::class, true)
                                ->delClock(0);
            }
        }
    }
}
