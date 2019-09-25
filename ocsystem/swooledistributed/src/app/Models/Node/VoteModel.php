<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Node;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\VoteProcess;
use app\Process\TimeClockProcess;
use app\Process\BlockProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class VoteModel extends Model
{

    /**
     * 当前区块高度
     * @var
     */
    protected $now_top_height;

    /**
     * 当前轮次
     * @var
     */
    protected $now_round;

    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        //获取当前区块高度
        $this->now_top_height = ProcessManager::getInstance()
                                            ->getRpcCall(BlockProcess::class)
                                            ->getTopBlockHeight();
        //获取当前轮次
        $this->now_round = ProcessManager::getInstance()
                                        ->getRpcCall(TimeClockProcess::class)
                                        ->getRounds();
    }


    /**
     * 插入投票数据到数据库
     * @param array $vote_data
     * @return bool
     */
    public function submitVote($vote_data = [])
    {
        if(empty($vote_data)){
            return returnError('请输入节点数据.');
        }
        $vote_res = [];//投票插入结果
        $vote = [];//投票数据
        $vote = [
            'value'         =>  $vote_data['value'],
            'address'       =>  $vote_data['address'],
            'rounds'        =>  $vote_data['rounds'],
            'voter'         =>  $vote_data['voter'],
        ];
        //插入数据
        $vote_res = ProcessManager::getInstance()
                                    ->getRpcCall(VoteProcess::class)
                                    ->insertVote($vote);
        if(!$vote_res['IsSuccess'])
            return returnError($vote_res['Message']);

        return returnSuccess();
    }

    /**
     * 验证投票数据是否有误
     * @param array $vote_data
     * @return bool
     */
    public function checkVote($vote_data = [], $type = 1)
    {
        if(empty($vote_data)){
            return returnError('请传入投票验证信息');
        }

        //验证投的区块高度是否有问题
        $vote_rounds = $vote_data['rounds'] - $this->now_round;
        if($vote_rounds <= 0 || $vote_rounds > 2)
            return returnError('投票轮次有误!当前轮次:'.$this->now_round);

        /**
         * 验证锁定时间，一般提前两轮进行投票
         */
        if(!is_numeric($vote_data['value']) || strpos($vote_data['value'], ".") !== false)
            return returnError('质押金额必须是整数');
        /**
         * 质押时间必须是质押金额乘以300个块加上所投轮次的结束时间
         * type != 1 用已经锁定的交易重新进行投票，只需要判断是否过期
         * 允许有2个块的误差时间
         */
        if($type == 1){
            if(abs(((floor($vote_data['value'] / 100000000) * 300) + $this->now_top_height) - $vote_data['lockTime'])  > 2)
                return returnError('质押时间有误');

        }else{
            if($this->now_top_height >= $vote_data['lockTime'])
                return returnError('该交易已经解锁，请重新质押.');
        }

        /**
         * 质押金额必须是10000以上，测试2
         * 质押追加改为10000的倍数，因此不需要再查库验证
         * 如果本身就有质押，则视为追加质押，质押总数必须大于500only
         */
        $vote_res = [];//投票情况
        $vote_ops = [
            '_id' => ['value' => ['$sum' => '$value']],
        ];//查询条件
        $vote_march = [
            'rounds'    => $vote_data['rounds'],
            'voter'     =>  $vote_data['voter']
        ];//查询字段
        $vote_res = ProcessManager::getInstance()
                                    ->getRpcCall(VoteProcess::class)
                                    ->getVoteAggregation($vote_march, $vote_ops, 1, 1000000);
        //质押追加改为10000的倍数，因此不需要再查库验证
        if(empty($vote_data['value']) && $vote_data['value'] < 500000000){
            return returnError('质押数量有误');
        }elseif(!empty($vote_res['Data']) && ($vote_res['Data']['value'] + $vote_data['value']) < 200000000){
            return returnError('质押数量有误');
        }
        return returnSuccess();
    }

    /**
     * 验证重新投票的交易
     * @param array $vote_data
     * @return bool
     */
    public function checkVoteAgain($vote_data = [], $trading = [])
    {
        //验证输入的投票信息
        if(empty($vote_data))
            return returnError('请输入投票信息.');

        //验证质押交易是否为空
        if(empty($trading))
            return returnError('请输入质押交易.');

        //验证质押类型
        if($vote_data['lockType'] != 2)
            return returnError('交易质押类型有误!.');

        //验证投票轮次
        $vote_rounds = $vote_data['rounds'] - $this->now_round;
        if($vote_rounds <= 0 || $vote_rounds > 2)
            return returnError('投票轮次有误!当前轮次:'.$this->now_round);

        //验证质押是否到期
        if($vote_data['lockTime'] <= $this->now_top_height)
            return returnError('该交易已经解锁.');



    }
}
