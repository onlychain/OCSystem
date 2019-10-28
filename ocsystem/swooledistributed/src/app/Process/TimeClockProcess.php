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
use app\Models\Consensus\ConsensusModel;

//自定义进程
use app\Process\NodeProcess;
use app\Process\VoteProcess;
use app\Process\ConsensusProcess;
use app\Process\CoreNetworkProcess;
use Server\Components\Process\Process;
use Server\Components\Process\ProcessManager;




class TimeClockProcess extends Process
{

    /**
     * 时间钟
     * @var int
     */
    private $clock = 0;

    /**
     * 时间钟状态
     * @var bool
     */
    private $clockState = false;

    /**
     * 打包轮次
     * @var int
     */
    private $rounds = 0;

    /**
     * 共识验证算法模型
     * @var
     */
    private $ConsensusModel;

    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('TimeClockProcess');
        $this->clock = 0;//初始化时间钟
        $this->clockState = false;//时间钟状态
        $this->ConsensusModel = new ConsensusModel();//实例化共识模型
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
//        while (true) {
            if ($this->clockState) {
                var_dump('当前时间' . $this->clock);
                if ($this->clock <= 1 || $this->clock == NULL) {
                    //先关闭节点
                    //关闭工作
                    ProcessManager::getInstance()
                                    ->getRpcCall(ConsensusProcess::class)
                                    ->closeConsensus();
                    //判断是否到
                    $this->correctTimeClock();

                    var_dump('开启新一轮节点更新');
                    var_dump('当前轮次:' . ($this->rounds +1));
                    $this->clock = 125;
                    ++$this->rounds;
                    /**
                     * 更新备选超级节点数据
                     */
                    $examination_res = ProcessManager::getInstance()
                        ->getRpcCall(NodeProcess::class)
                        ->examinationNode();
                    if (!$examination_res['IsSuccess']) {
                        return;
//                        continue;
                    }
                    /**
                     * 开始统计投票，决定下一轮的超级节点
                     */
                    $rotation_res = ProcessManager::getInstance()
                        ->getRpcCall(NodeProcess::class)
                        ->rotationSuperNode($this->rounds);
                    if (empty($rotation_res['Data'])) {
                        return;
//                        continue;
                    }
                    /**
                     * 更新当前节点信息
                     */
                    var_dump('当前节点次序:' . $rotation_res['Data']);
                    //设置节点次序
                    ProcessManager::getInstance()
                                ->getRpcCall(ConsensusProcess::class)
                                ->setIndex($rotation_res['Data']);

                    /**
                     * 刷新超级节点连接池
                     */
                    ProcessManager::getInstance()
                                ->getRpcCall(CoreNetworkProcess::class)
                                ->rushSuperNodeLink();
                    if ($rotation_res['Data'] == 0) {
                        //设置节点身份
                        ProcessManager::getInstance()
                            ->getRpcCall(ConsensusProcess::class)
                            ->setNodeIdentity('ordinary');
                    } elseif ($rotation_res['Data'] > 3) {
                        //设置节点身份
                        ProcessManager::getInstance()
                            ->getRpcCall(ConsensusProcess::class)
                            ->setNodeIdentity('alternative');
                        //开启工作
                        ProcessManager::getInstance()
                            ->getRpcCall(ConsensusProcess::class)
                            ->openConsensus();
                    } else {
                        //设置节点身份
                        ProcessManager::getInstance()
                            ->getRpcCall(ConsensusProcess::class)
                            ->setNodeIdentity('core');
                        //开启工作
                        ProcessManager::getInstance()
                            ->getRpcCall(ConsensusProcess::class)
                            ->openConsensus();
                    }

//                    ProcessManager::getInstance()
//                        ->getRpcCall(ConsensusProcess::class, true)
//                        ->chooseWork($this->clock);
                    var_dump('round change over.');
//                    return;
                }
                ProcessManager::getInstance()
                    ->getRpcCall(ConsensusProcess::class, true)
                    ->chooseWork($this->clock);
                $this->clock = $this->clock - 2;


                //一秒确认一次
//                sleepCoroutine(1000);
            }
//        }
    }

    protected function correctTimeClock()
    {

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
     * 同步时间钟
     * @return bool
     * @oneWay
     */
    public function checkTimeClock()
    {
        $super_clock = [];//存储其他超级节点的时间数据
        $check_res = [];//存储通过共识验证算法的结果
        //获取其他超级节点的时间钟
        $super_clock = [];
        //从其他超级节点获取数据

        $super_clock = ProcessManager::getInstance()
                                ->getRpcCall(CoreNetworkProcess::class)
                                ->sendToSuperNode('time', get_instance()->getNullContext(), 'TimeController', 'getTimeClock');
        //使用共识验证算法进行验证
        $check_res = $this->ConsensusModel->verifyTwoThirds($super_clock['Data']);
        if($check_res['IsSuccess']){
            //验证通过,开启时间钟状态
            $this->openClock();
        }else{
            //先关闭时间钟
            $this->closeClock();
            //循环数组，获取计数最大的数字
            $temp = 0;
            foreach ($super_clock['Data'] as $sc_key => $sc_val){
                $sc_val > $temp && $temp = $sc_val;
            }
            //修正时间钟时间
            $this->setTimeClock($temp);
            $this->openClock();
        }
        return returnSuccess();
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
