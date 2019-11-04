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
use app\Process\PeerProcess;
use app\Process\VoteProcess;
use app\Process\TimeClockProcess;
use app\Process\BlockProcess;
use app\Process\TradingProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class VoteModel extends Model
{
    /**
     * 交易处理模型
     * @var
     */
    protected $TradingModel;

    /**
     * 交易序列化模型
     * @var
     */
    protected $TradingEncodeModel;

    /**
     * 组装交易模型
     * @var
     */
    protected $CreateTradingModel;

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
        //调用交易模型
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        //调用交易序列化模型
        $this->TradingEncodeModel = $this->loader->model('Trading/TradingEncodeModel', $this);
        //调用生成交易模型
        $this->CreateTradingModel = $this->loader->model('Trading/CreateTradingModel', $this);
    }


    /**
     * 插入投票数据到数据库
     * @param array $vote_data
     * @return bool
     */
    public function submitVote2($vote_data = [])
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
     * 更新数据库投票
     * @param array $vote_data
     * @return bool
     */
    public function submitVote($vote_data = [], $type = 1)
    {
        if(empty($vote_data)){
            return returnError('请输入节点数据.');
        }
        $vote_res = [];//投票插入结果
        $vote = [];//质押内容
        var_dump(1222222);
        var_dump($type);
        if($type == 1){
            //有投过票
            $vote_where = [
                'rounds'    => $vote_data['rounds'],
                'voter'     => $vote_data['voter'],
            ];
            $vote = [
                '$inc'  => ['value' =>  $vote_data['trading']['value']],
                '$push' => ['txId' => ['$each' => $vote_data['trading']['txId']]]
            ];
            //修改数据
            $vote_res = ProcessManager::getInstance()
                                    ->getRpcCall(VoteProcess::class)
                                    ->updateVoteMany($vote_where, $vote);
        }else{
            //没有投过票
            foreach ($vote_data['address'] as $vd_key => $vd_val){
                $insert_vote[] = [
                    'address'       =>  $vd_val,
                    'rounds'        =>  $vote_data['rounds'],
                    'voter'         =>  $vote_data['voter'],
                    'value'         =>  $vote_data['trading']['value'] ?? 0,
                    'txId'          =>  isset($vote_data['trading']['txId']) ? [$vote_data['trading']['txId']] : [],
                ];
            }
            //插入数据
            $vote_res = ProcessManager::getInstance()
                                        ->getRpcCall(VoteProcess::class)
                                        ->insertVoteMany($insert_vote);
        }

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
        $flag = 2;
        //最新区块的高度
        $now_top_height = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getTopBlockHeight();
        //当前轮次
        $now_round = ProcessManager::getInstance()
                                    ->getRpcCall(TimeClockProcess::class)
                                    ->getRounds();

        if(empty($vote_data)){
            return returnError('请传入投票验证信息');
        }

        //验证投的区块高度是否有问题
        $vote_rounds = $vote_data['rounds'] - $now_round;
        if($vote_rounds <= 1 || $vote_rounds > 3)
            return returnError('投票轮次有误!当前轮次:'.$now_round);

        var_dump($vote_data['trading']);
        if(!empty($vote_data['trading'])){
            if($type == 1){
                /**
                 * 验证锁定时间，一般提前两轮进行投票
                 */
                if(!is_numeric($vote_data['trading']['value']) || strpos($vote_data['trading']['value'], ".") !== false)
                    return returnError('质押金额必须是整数.');
                /**
                 * 质押时间必须是质押金额乘以300个块加上所投轮次的结束时间
                 * type != 1 用已经锁定的交易重新进行投票，只需要判断是否过期
                 * 允许有2个块的误差时间
                 */
                if($vote_data['trading']['lockTime'] - ((floor($vote_data['trading']['value'] / 100000000) * 300) + $now_top_height)  < 0){
                    return returnError('质押时间有误.');
                }

            }else{
                if($now_top_height >= $vote_data['trading']['lockTime'])
                    return returnError('该交易已经解锁，请重新质押.');
            }
        }
        /**
         * 质押金额必须是10000以上，测试2
         * 质押追加改为10000的倍数，因此不需要再查库验证
         * 如果本身就有质押，则视为追加质押，质押总数必须大于500only
         */
        $votes_where = [
            'rounds'    => $vote_data['rounds'],
            'voter'     =>  $vote_data['voter'],
        ];
        $votes_data = ['_id' => 0];
        $vote_res = ProcessManager::getInstance()
                                ->getRpcCall(VoteProcess::class)
                                ->getVoteInfo($votes_where, $votes_data);
        //质押追加最低为10000only
        if (empty($vote_res['Data']) && !empty($vote_data['trading'])){
            //没有提交交易，也没有投过票
            if($vote_data['trading']['value'] < 1000000000000)
                return returnError('首次质押必须大于10000个Only');

        }elseif (!empty($vote_res['Data']) && empty($vote_data['trading'])){
            //投过票但是没有提交交易
            $flag = 1;
        }elseif (!empty($vote_res['Data']) && !empty($vote_data['trading'])){
            //投过票又提交交易
            $flag = 1;
            if(($vote_res['Data']['value'] + $vote_data['trading']['value']) < 1000000000000)
                return returnError('总质押量必须大于10000个Only');

            if (!empty($vote_res['Data']['txId'])){
                //证交易是否已经被使用
                foreach ($vote_res['Data']['txId'] as $vr_val){
                    if(!empty($vote_data['trading']['txId'][$vr_val])){
                        return returnError('该交易已经用于投票.');
                    }
                }
            }
        }

        return returnSuccess(['flag' => $flag]);
    }

    /**
     * 验证投标重投
     * @param array $vote_data
     * @return bool
     */
    public function checkVoteAgain($vote_data = [])
    {
        //最新区块的高度
        $now_top_height = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getTopBlockHeight();
        //当前轮次
        $now_round = ProcessManager::getInstance()
                                    ->getRpcCall(TimeClockProcess::class)
                                    ->getRounds();

        if(empty($vote_data)){
            return returnError('请传入投票验证信息');
        }

        //验证投的区块高度是否有问题
        $vote_rounds = $vote_data['rounds'] - $now_round;
        if($vote_rounds <= 1 || $vote_rounds > 3)
            return returnError('投票轮次有误!当前轮次:'.$now_round);


        if($now_top_height >= $vote_data['lockTime'])
            return returnError('该交易已经解锁，请重新质押.');

        $votes_where = [
            'rounds' => $vote_data['rounds'],
            'voter'     =>  $vote_data['voter'],
        ];
        $votes_data = ['_id' => 0];
        $vote_res = ProcessManager::getInstance()
                                    ->getRpcCall(VoteProcess::class)
                                    ->getVoteInfo($votes_where, $votes_data);
        //质押追加最低为100only
        if(empty($vote_data['value']) && $vote_data['value'] < 1000000000000){
            return returnError('首次质押金额必须大于10000Only');
        }elseif(!empty($vote_res['Data']) && ($vote_res['Data']['value'] + $vote_data['value']) < 1000000000000){
            return returnError('质押总金额必须大于10000Only');
        }
        return returnSuccess();
    }

    /**
     * 验证投票请求
     * @param array $vote_data
     * @return bool
     */
    public function checkVoteRequest($vote_data = [], $is_broadcast = 1)
    {
        var_dump($vote_data);
        $vote_res = [];//投票操作结果
        $check_vote = [];//需要验证的投票数据
        $check_vote_res = [];//投票验证结果
        $check_trading_res = [];//交易验证结果
        $trading_res = [];//交易操作验证结果

        //做交易所有权验证
//        $validation = $this->Validation->varifySign($trading_data);
//        if(!$validation['IsSuccess']){
//            return $this->http_output->notPut($validation['Code'], $validation['Message']);
//        }

        $check_vote['rounds'] = $vote_data['rounds'];//所投轮次
        $check_vote['voter'] = $vote_data['voter'];//质押人员
        $vote_type = $vote_data['voteAgain'] ?? 1;//投票类型

        //反序列化交易
        if(!empty($vote_data['pledge']['trading'])){
            $decode_trading = $this->TradingEncodeModel->decodeTrading($vote_data['pledge']['trading']);
            if($decode_trading['lockType'] != 2){
                return returnError('质押类型有误.');
            }
            $check_vote['trading']['value'] = $decode_trading['vout'][0]['value'] ?? 0;//质押金额(循环获取)
            $check_vote['trading']['lockTime'] = $decode_trading['lockTime'];//质押时间
            //根据投票类型，插入质押的txId
            if($vote_type == 1){
                $check_vote['trading']['txId'][$decode_trading['txId']] = $decode_trading['txId'];
            }else{
                //重质押获取vin中的txId
                $check_vote['trading']['txId'][$decode_trading['txId']] = $decode_trading['txId'];
                foreach ($decode_trading['vin'] as $dt_val){
                    $check_vote['trading']['txId'][$dt_val['txId']] = $dt_val['txId'];
                }
            }
            //重置序号
            sort($check_vote['trading']['txId']);
        }else{
            $check_vote['trading'] = [];
            $decode_trading = [];
        }

        $check_vote_res = $this->checkVote($check_vote, $vote_type);
        if(!$check_vote_res['IsSuccess']) {
            return returnError($check_vote_res['Message']);
        }
        //如果没有交易将交易数据赋值为0
        //验证交易是否可用
        $check_trading_res = ProcessManager::getInstance()
                                        ->getRpcCall(TradingProcess::class)
                                        ->checkTrading($decode_trading, $vote_data['voter'], $vote_type);
        if(!$check_trading_res['IsSuccess']){
            return returnError($check_trading_res['Message'], $check_trading_res['Code']);
        }

        if($vote_type == 1 && !empty($vote_data['pledge']['trading'])){
            //交易入库
            $trading_res = $this->TradingModel->createTradingEecode($vote_data['pledge']);
            if(!$trading_res['IsSuccess']){
                return returnError($trading_res['Message']);
            }
        }

        //交易验证成功，投票写入数据库
        $check_vote['address'] = $vote_data['address'];
        $vote_res = $this->submitVote($check_vote, $check_vote_res['Data']['flag']);
        if(!$vote_res['IsSuccess']){
            return returnError($vote_res['Message']);
        }
        var_dump('over');
        if($is_broadcast == 2){
            ProcessManager::getInstance()
                            ->getRpcCall(PeerProcess::class, true)
                            ->broadcast(json_encode(['broadcastType' => 'Vote', 'Data' => $vote_data]));
        }
        return returnSuccess();
    }

}
