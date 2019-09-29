<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;

use MongoDB;

//自定义进程
use app\Process\NodeProcess;
use app\Process\VoteProcess;
use app\Process\ConsensusProcess;
use Server\Components\Process\Process;
use Server\Components\Process\ProcessManager;


class TimeClockProcess extends Process
{

    /**
     * 时间钟
     * @var int
     */
    private $clock = 125;

    /**
     * 时间钟状态
     * @var bool
     */
    private $clockState = false;

    /**
     * 打包轮次
     * @var int
     */
    private $rounds = 1;

    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('TimeClockProcess');
        $this->clock = 125;//初始化时间钟
        $this->clockState = true;//时间钟状态
//        $this->runTimeClock();
    }

    /**
     * 获取当前时间钟时间
     * @return int
     */
    public function getTimeClock() : int
    {
        return $this->clock;
    }

    /**
     * 设置当前时间钟
     * @param int $time
     */
    public function setTimeClock(int $time = 125)
    {
        $this->clock = $time;
    }

    /**
     * 关闭时间钟,进行校准确认
     */
    public function closeClock()
    {
        $this->clockState = false;
    }

    /**
     * 开启时间钟,用于时间校准之后
     */
    public function openClock()
    {
        $this->clockState = true;
    }

    public function setRounds(int $round = 1)
    {
        $this->rounds = $round;
    }

    public function getRounds() : int
    {
        return $this->rounds;
    }

    /**
     * 运行时间钟
     * @oneWay
     */
    public function runTimeClock()
    {

//        while (true){
//            var_dump($this->clock);
//            if($this->clockState){
//                if($this->clock <= 0 || $this->clock == NULL){
//                    $this->clock = 125;
                    ++$this->rounds;
                    /**
                     * 更新备选超级节点数据
                     */
                    $examination_res = ProcessManager::getInstance()
                                                    ->getRpcCall(NodeProcess::class)
                                                    ->examinationNode();
                    if($examination_res['IsSuccess']){
//                        continue;
                    }
                    /**
                     * 开始统计投票，决定下一轮的超级节点
                     */
                    $rotation_res = ProcessManager::getInstance()
                                                    ->getRpcCall(NodeProcess::class)
                                                    ->rotationSuperNode($this->rounds);
                    if(empty($rotation_res['Data'])){
//                        continue;
                    }
                    /**
                     * 更新当前节点信息
                     */
                    if($rotation_res['Data'] == 0){
                        //设置节点身份
                        ProcessManager::getInstance()
                                        ->getRpcCall(ConsensusProcess::class)
                                        ->setNodeIdentity('ordinary');
                        //关闭工作
                        ProcessManager::getInstance()
                                        ->getRpcCall(ConsensusProcess::class)
                                        ->closeConsensus();
                    }elseif($rotation_res['Data'] > 1){
                        //设置节点身份
                        ProcessManager::getInstance()
                                        ->getRpcCall(ConsensusProcess::class)
                                        ->setNodeIdentity('alternative');
                        //开启工作
                        ProcessManager::getInstance()
                                        ->getRpcCall(ConsensusProcess::class)
                                        ->openConsensus();
                    }else{
                        //设置节点身份
                        ProcessManager::getInstance()
                                        ->getRpcCall(ConsensusProcess::class)
                                        ->setNodeIdentity('core');
                        //开启节点
                        ProcessManager::getInstance()
                                            ->getRpcCall(ConsensusProcess::class)
                                            ->openConsensus();
                    }
                    //设置节点次序
                    ProcessManager::getInstance()
                                    ->getRpcCall(ConsensusProcess::class)
                                    ->setIndex($rotation_res['Data']);

//                }else{
//                    --$this->clock;
//                }
//            }
//            //一秒确认一次
//            sleepCoroutine(1000);
//        }
    }

    /**
     * 运行时间钟
     * @oneWay
     */
    public function runTimerClock()
    {
            var_dump($this->clock);
            if($this->clockState){
                if($this->clock <= 0 || $this->clock == NULL){
                    $this->clock = 125;
                    ++$this->rounds;
                    //更新备选超级节点数据

                    //开始统计投票，决定下一轮的超级节点，请求不需要返回

                }else{
                    --$this->clock;
                }
            }
    }
    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "时间钟进程关闭.";
    }
}
