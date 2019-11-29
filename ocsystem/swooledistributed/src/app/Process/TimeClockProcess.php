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
     * 时间钟差值
     * @var int
     */
    private $difference = 0;

    /**
     * 时间钟状态
     * @var bool
     */
    private $clockState = false;


    /**
     * 出块轮次
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
    }

    /**
     * 获取当前时间钟时间
     * @return int
     */
    public function getTimeClock() : int
    {
        return ceil(getTickTime() / 1000) - $this->getDifference() % 126;

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

    /**
     * 获取当前时间钟状态
     * @return bool
     */
    public function getClockState() :bool
    {
        return $this->clockState;
    }

    /**
     * 获取区块轮次
     * @return int
     */
    public function setRounds(int $rounds)
    {
        $this->rounds = $rounds;
    }

    /**
     * 获取区块轮次
     * @return int
     */
    public function getRounds() : int
    {
        return ceil(intval(ceil(getTickTime() / 1000) - $this->getDifference()) / 126) + 1;
    }

    /**
     * 获取创始时间(项目运行至今的秒数)
     * @return int
     */
    public function getCreationTime() :int
    {
        return ceil(getTickTime() / 1000) - $this->getDifference();
    }

    /**
     * 设置时间钟差值
     * @param int $difference_time
     */
    public function setDifference(int $difference_time = 0)
    {
        $this->difference = $difference_time;
    }

    /**
     * 获取时间钟差值
     * @return int
     */
    public function getDifference() : int
    {
        return $this->difference;
    }

    /**
     * 获取时间钟差值
     * @return int
     */
    public function getNowTime() : int
    {
        return (ceil(getTickTime() / 1000) - $this->getDifference()) % 126;
    }

    /**
     * 运行时间钟
     * @oneWay
     */
    public function runTimeClock()
    {
            if ($this->clockState) {
//        if (true) {
                var_dump('当前时间'.(ceil(getTickTime() / 1000) - $this->getDifference()) % 126);
                if ((ceil(getTickTime() / 1000) - $this->getDifference()) % 126 <= 0){
//            if (true){
                    //先关闭节点
                    //关闭工作
                    ProcessManager::getInstance()
                                    ->getRpcCall(ConsensusProcess::class)
                                    ->closeConsensus();
                    var_dump('开启新一轮节点更新');
                    var_dump('当前轮次:' . $this->getRounds());
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
                                        ->rotationSuperNode($this->getRounds());
                    if (empty($rotation_res['Data'])) {
                        return;
//                        continue;
                    }
                    /**
                     * 更新当前节点信息
                     */
                    var_dump('当前节点次序:' . $rotation_res['Data']['index']);
                    //设置节点次序
                    ProcessManager::getInstance()
                                ->getRpcCall(ConsensusProcess::class)
                                ->setIndex($rotation_res['Data']['index']);

                    /**
                     * 刷新超级节点连接池
                     */
                    ProcessManager::getInstance()
                                ->getRpcCall(CoreNetworkProcess::class)
                                ->rushSuperNodeLink();
                    if ($rotation_res['Data']['index'] == 0) {
                        //设置节点身份
                        ProcessManager::getInstance()
                            ->getRpcCall(ConsensusProcess::class)
                            ->setNodeIdentity('ordinary');
                    } elseif ($rotation_res['Data']['index'] > 3) {
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
//                    //广播节点数据
                    ProcessManager::getInstance()
                        ->getRpcCall(PeerProcess::class, true)
                        ->broadcast(json_encode(['broadcastType' => 'Node', 'Data' => $examination_res['Data']['node']]));

                    //广播超级节点
                    ProcessManager::getInstance()
                        ->getRpcCall(PeerProcess::class, true)
                        ->broadcast(json_encode(['broadcastType' => 'SuperNode', 'Data' => $rotation_res['Data']['superNode']]));

                    var_dump('round change over.');
                }
                ProcessManager::getInstance()
                    ->getRpcCall(ConsensusProcess::class, true)
                    ->chooseWork(ceil(getTickTime() / 1000) - $this->getDifference() % 126);
//                var_dump((ceil(getTickTime() / 1000) - $this->getDifference()) % 126);
            }
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
                                ->sendToSuperNode('time', getNullContext(), 'TimeController', 'getTimeClock');
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
     * 节点启动后获取到广播的轮次与时间进行设置
     * @param int $time
     * @param int $round
     * @return bool
     */
    public function delClock($time = 0)
    {
        if($this->clockState){
            return returnError('时间钟已同步完成.');
        }
//        if($time == 0){
//            return returnError('同步时间有误');
//        }
        $systime = ceil(getTickTime() / 1000);
        $work_time =  $time % 126;//0 ? ($time % 126) - 1 :$time == 0 ? ($time % 126) - 1 :
        //设置时间钟差值
        $this->setDifference($systime - $work_time);
        //开启时间钟
        var_dump('开启时间钟.');
        var_dump($systime);
        var_dump($time);
        var_dump($work_time);
        var_dump($this->difference);
        $this->openClock();
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
