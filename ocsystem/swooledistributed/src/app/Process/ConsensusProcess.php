<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;

use app\Models\Purse\PurseModel;
use app\Models\Trading\TradingModel;
use app\Models\Block\BlockHeadModel;
use app\Models\Block\MerkleTreeModel;
use app\Models\Trading\TradingUTXOModel;
use app\Models\Trading\TradingEncodeModel;
use Server\Components\CatCache\CatCacheRpcProxy;

use MongoDB;

//椭圆曲线加密算法
use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;

//自定义进程

use app\Process\PurseProcess;
use app\Process\BlockProcess;
use app\Process\TradingProcess;
use app\Process\TimeClockProcess;
use app\Process\SuperNodeProcess;
use app\Process\IncentivesProcess;
use app\Process\TradingPoolProcess;
use Server\Components\Process\Process;
use Server\Components\Process\ProcessManager;

class ConsensusProcess extends Process
{
    /**
     * 工作次序
     * @var
     */

    private $index = 1;

    /**
     * 区块头部方法
     * @var
     */
    private $BlockHead;

    /**
     * 交易方法
     * @var
     */
    private $TradingModel;

    /**
     * 默克尔树方法
     * @var
     */
    private $MerkleTree;

    /**
     * utxo加密算法
     * @var
     */
    private $TradingUTXO;

    /**
     * 钱包类
     * @var
     */
    private $PurseModel;

    /**
     * 交易序列化方法
     * @var
     */
    private $TradingEncode;

    /**
     * 节点身份，分为core（核心）,alternative（备选）,ordinary（普通）
     * @var string
     */
    private $Identity = "core";

    /**
     * 是否开启共识
     * @var bool
     */
    private $openConsensus = false;

    /**
     * 每年出块数（比实际出块数多，主要用于计算）
     * @var int
     */
    private $yearBlockNum = 15768000;

    /**
     * 每年轮次数，取整数
     * @var int
     */
    private $yearRoundsNum = 250285;
    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('ConsensusProcess');
//        $this->MongoUrl = 'mongodb://localhost:27017';
//        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
//        $this->Block = $this->MongoDB->selectCollection('blicks', 'block');
        //区块头部相关方法
        $this->BlockHead = new BlockHeadModel();
        //区块头部相关方法
        $this->MerkleTree = new MerkleTreeModel();
        //utxo加密算法
        $this->TradingUTXO = new TradingUTXOModel();
        //utxo加密算法
        $this->TradingEncode = new TradingEncodeModel();
        //交易方法
        $this->TradingModel = new TradingModel();
        //钱包类
        $this->PurseModel = new PurseModel();

//        try {
//            $this->chooseWork();
//        } catch (\Exception $e) {
//            throw new \Exception($e);
//        }

    }


    /**
     * 根据角色选择工作工作
     * @return bool
     * @oneWay
     */
    public function chooseWork()
    {
        $threshold = 0;
        if($this->openConsensus){
//        while ($threshold < 10){
            switch ($this->Identity){
                case 'core' :
                    $this->coreNode();
                    break;
                case 'alternative' :
                    $this->alternativeNode();
                    break;
                case 'ordinary' :
                    $this->ordinaryNode();
                    break;
                default :
                    ++$threshold;
                    break;
            }
            if($threshold >= 10){
                throw new \Exception("节点身份异常，工作进程已停止!");
//                break;
            }
//            sleepCoroutine(1000);
//        }
        }
    }

    /**
     * 核心节点工作
     * @oneWay
     */
    public function coreNode()
    {
//        $now_time = CatCacheRpcProxy::getRpc()->offsetGet('topBlockHash');
        var_dump($this->Identity);
        while ($this->Identity == 'core'){
            //获取当前时间钟时间
            var_dump('获取时间');
            $now_time = ProcessManager::getInstance()
                                    ->getRpcCall(TimeClockProcess::class)
                                    ->getTimeClock();


            var_dump('获取成功'.$now_time);
            //判断是否到自己出块
//            $is_work = $this->getIsCore($now_time, 21);
            $is_work = true;
            var_dump(date('Y-m-d H:i:s'));
            if($is_work){
                var_dump("=========================================开始出块=========================================");
                var_dump(date('Y-m-d H:i:s'));
                $merker_tree = [];//存储默克尔树
                $morker_tree_root = '';//存储默克尔树根
                $block_head = [];//存储构建的区块头部
                $trading_num = 0;//存储交易笔数
                $trading_where = [];//搜索交易条件
                $trading_data = [];//搜索交易字段
                $trading_res = [];//交易查询结果
                $block_head_res = [];//存储区块头结果
                $tradings = [];//存储交易哈希
                $top_block_hash = '';//最新的区块头哈希
                $top_block_height = 0;//区块高度
//                $decode_trading = [];//已序列化交易
                $encode_trading = [];//未序列化交易
                $ids = [];//交易编号
                //执行激励策略
                $this->incentive();
                $page = 1;
                $pagesize = 20000;
                $trading_data = ['trading' => 1, '_id' => 1];
                var_dump(1);
                $trading_res = ProcessManager::getInstance()
                                            ->getRpcCall(TradingPoolProcess::class)
                                            ->getTradingPoolList($trading_where, $trading_data, $page, $pagesize);
                if(!$trading_res['IsSuccess']){
//                    return returnError('数据获取失败!');
                }
                foreach ($trading_res['Data'] as $tr_key => $tr_val){
//                    $decode_trading[] = $this->TradingEncode->decodeTrading($tr_val['trading']);
                    $encode_trading[] = $tr_val['trading'];
                    $tradings[] = $tr_val['_id'];
                }
                //验证数据是否为空
                if(!empty($trading_res['Data'])){
                    //获取交易数量
                    $trading_num = count($tradings);
                    //生成默克尔树
                    $merker_tree = $this->MerkleTree->setNodeData($tradings)
                                                    ->bulidMerkleTreeSimple();
                    //获取默克尔根
                    $morker_tree_root = array_pop($merker_tree);
                    //获取最新的区块哈希
                    $top_block_hash = ProcessManager::getInstance()
                                                    ->getRpcCall(BlockProcess::class)
                                                    ->getTopBlockHash();
                    //获取最新的区块高度
                    $top_block_height = ProcessManager::getInstance()
                                                        ->getRpcCall(BlockProcess::class)
                                                        ->getTopBlockHeight();
                    //构建区块头部
                    $black_head = $this->BlockHead->setMerkleRoot($morker_tree_root)
                                                    ->setParentHash($top_block_hash)//上一个区块的哈希
                                                    ->setThisTime(time())//区块生成时间
                                                    ->setSignature(get_instance()->config['name'])//工作者签名
                                                    ->setHeight($top_block_height + 1)//区块高度先暂存，后期不上
                                                    ->setTxNum($trading_num)
                                                    ->setTradingInfo($tradings)
                                                    ->packBlockHead();
                    //发起共识

                    //先空着

                    $consensus_res['IsSuccess'] = false;
                    if(!$consensus_res['IsSuccess']){
                        //共识不通过处理
                    }
                    //区块头上链
                    $block_head_res = ProcessManager::getInstance()
                                                ->getRpcCall(BlockProcess::class)
                                                ->insertBloclHead($black_head);
                    //删除交易
                    $del_trading = ProcessManager::getInstance()
                                                ->getRpcCall(BlockProcess::class)
                                                ->checkTreading($trading_res['Data'], $tradings);

                    if($del_trading['IsSuccess']){
                        //操作成功,设置当前最新区块的高度跟哈希
                        ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->setTopBlockHash($black_head['headHash']);
                        ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->setTopBlockHeight($black_head['height']);

                    }
                    //刷新钱包
                    $this->bookedPurse($encode_trading);
//                    ProcessManager::getInstance()
//                                    ->getRpcCall(TradingProcess::class)
//                                    ->refreshPurse($decode_trading);
                    //清空被使用的交易缓存
                    CatCacheRpcProxy::getRpc()['Using'] = [];
                    var_dump("=========================================区块哈希=========================================");
                    var_dump($black_head['headHash']);
                    //休眠1秒
                    var_dump(date('Y-m-d H:i:s', time()));
                    var_dump("=========================================开始休眠=========================================");
                }

            }
            sleepCoroutine(2000);
        }

    }

    /**
     * 备选节点工作
     */
    public function alternativeNode()
    {

    }

    /**
     * 普通节点工作
     */
    public function ordinaryNode()
    {

    }


    /**
     * 激励功能(根据需求进行拓展)
     */
    public function incentive2()
    {
        //获取激励utxo并进行调整
        $incetive_new = get_instance()->config->get('coinbase');;
        //序列化
        $incentive_trading = $this->TradingEncode->setVin($incetive_new['vin'])
                                                    ->setVout($incetive_new['vout'])
                                                    ->setIns($incetive_new['ins'])
                                                    ->setTime(time())
                                                    ->setLockTime($incetive_new['lockTime'])
                                                    ->setPrivateKey($incetive_new['privateKey'])
                                                    ->setPublicKey($incetive_new['publicKey'])
                                                    ->encodeTrading();
        $insert_data['trading'] = $incentive_trading;
        $insert_data['_id']     =  bin2hex(hash('sha256', hash('sha256', hex2bin($incentive_trading), true), true));
        //插入数据库
        $check_res = ProcessManager::getInstance()
                                    ->getRpcCall(TradingPoolProcess::class)
                                    ->insertTradingPool($insert_data);
        //广播数据

        //空着待补充
        return returnSuccess($check_res['Data']);
    }

    /**
     * 激励功能
     */
    public function incentive()
    {
        $node = [];//存储节点数据
        $voter = [];//存储投票者数据
        $tradings = [];//存储激励交易
        $tradings_temp = [];//存储临时结果
        //获取轮次
        $now_round = ProcessManager::getInstance()
                                    ->getRpcCall(TimeClockProcess::class)
                                    ->getRounds();
        var_dump('round');
//        var_dump($now_round);
        //获取奖励列表
        $incentives = ProcessManager::getInstance()
                                    ->getRpcCall(IncentivesProcess::class)
                                    ->getIncentivesTable([],['_id' => 0])['Data'];
        var_dump('incentives');
        $table_num = ceil($now_round / $this->yearRoundsNum);
        if(($now_round > 0) && ($now_round % $this->yearRoundsNum) == 0){
            //跨年时段，对累积字段进行规整
            //选中的前30个节点
            $incentives[$table_num]['noder']['balance'] += $incentives[$table_num - 1]['noder']['balance'];
            $incentives[$table_num - 1]['noder']['balance'] = 0;
            //修改激励
            ProcessManager::getInstance()
                            ->getRpcCall(IncentivesProcess::class)
                            ->updateIncentivesTable($incentives[$table_num - 1]);

        }

        //获取本轮投票数据
        $super_node = ProcessManager::getInstance()
                                    ->getRpcCall(SuperNodeProcess::class)
                                    ->getSuperNodeList();
        if(!$super_node['IsSuccess']){
            return returnError('', $super_node['Message']);
        }
        //获取当前节点投票者数据
//        $voter = $super_node['Data']['voters'];
        //获取三十个节点数据，去掉数组内的投票者信息
        foreach ($super_node['Data'] as $sn_key => $sn_val){
            $node[$sn_val['address']]['value'] = array_sum(array_column($sn_val['voters'], 'value'));
            if($sn_val['address'] == get_instance()->config['address']){
                $voter = $sn_val['voters'];
            }
        }
        //计算需要给当前节点前1000用户的代币
        if(!empty($voter)){
            $tradings_temp = $this->calculateVoterReward($table_num, array_slice($voter, 0, 1000), $incentives);
            $tradings[] = $tradings_temp['Data']['trading'][0];
            count($tradings_temp['Data']['trading']) == 2 && $tradings[] = $tradings_temp['Data']['trading'][1];
        }
        //计算节点自身可以获得的代币
        $tradings[] = $this->calculateWorkerReward($table_num, $incentives)['Data']['trading'];
        //计算30个主节点21核心节点+9备选节点可以获得的代币
        !empty($node) && $tradings[] = $this->calculateNoderReward($table_num, $node, $incentives)['Data']['trading'];
        //计算社群可以获得的代币
        $tradings[] = $this->calculateCommunityReward($table_num, $incentives, get_instance()->config['address'])['Data']['trading'];
        //更新激励数据
        ProcessManager::getInstance()
                        ->getRpcCall(IncentivesProcess::class)
                        ->updateIncentivesTable($incentives, $table_num);
        var_dump($incentives[1]);
        var_dump($tradings);
        $this->TradingModel->createTradingMany($tradings);
    }

    /**
     * 投票者激励
     */
    public function calculateVoterReward($table_num = 0, $voters = [], &$incentives = [])
    {
        var_dump('voter');
        $voter_count = count($voters);//投票者数量
        $tradings = [];//存储交易
        $tokens = $incentives[$table_num]['voter'];
        /**
         * 开始计算激励交易金额
         * 一天的块数（86400 /126 *63）*365 0
         */
        $tokens = $tokens / $this->yearBlockNum;
        $total_pledge = array_sum(array_column($voters, 'value'));
        //组装成交易

        $trading = [];//存储交易内容
        //最多只有前1000个用户
        $trading['tx'][0]['coinbase'] = 'No one breather who is worthier.';
        $trading['from'] = get_instance()->config['address'];
        $count = $voter_count > 500 ? 500 : $voter_count;
        var_dump($voters);
        for($i = 0; $i < $count; ++$i){
            $trading['to'][$i] = [
                'address'   =>  $voters[$i]['address'],
                'value'     =>  floor($tokens * ($voters[$i]['value'] / $total_pledge)),
                'type'      =>  1,
            ];
        }
        //进行序列化
        $tradings[] = $this->TradingEncode->setVin($trading['tx'])
                                            ->setVout($trading['to'])
                                            ->setTime(time())
                                            ->setLockTime(0)
                                            ->encodeTrading();

        if($voter_count > 500){
            //大于500个人，拆成两笔交易
            $trading = [];
            $count = $voter_count > 1000 ? 1000 : $voter_count;
            for($i = 500; $i < $count; ++$i){
                $trading['to'][$i] = [
                    'address'   =>  $voters[$i]['address'],
                    'value'     =>  $tokens,
                ];
            }
            $tradings[] = $this->TradingEncode->setVin($trading['tx'])
                                                ->setVout($trading['to'])
                                                ->setTime(time())
                                                ->setLockTime(0)
                                                ->encodeTrading();
        }
        $total = floor($tokens * $count);
        $incentives[$table_num]['voter'] -= $total;
        //返回交易以及处理好的数据
        return returnSuccess(['trading' => $tradings, 'incentives' => $incentives]);
    }

    /**
     * 工作节点激励
     */
    public function calculateWorkerReward($table_num = 0, &$incentives = [])
    {
        var_dump('worker');
        //计算可获得的汤圆
        $tokens = $incentives[$table_num]['worker'];
        /**
         * 每年块数（86400/126） * 63（一轮的块数） * 365
         */
        $tokens = floor($tokens / $this->yearBlockNum);
        //组装成交易
        $trading = [];//存储交易内容
        //最多只有前1000个用户
        $trading['tx'][0]['coinbase'] = 'No one breather who is worthier.';
        $trading['from'] = get_instance()->config['address'];
        $trading['to'][0]['address'] = get_instance()->config['address'];
        $trading['to'][0]['value'] = $tokens;
        $trading['to'][0]['type'] = 1;
        //把交易序列化
        $trading = $this->TradingEncode->setVin($trading['tx'])
                                            ->setVout($trading['to'])
                                            ->setTime(time())
                                            ->setLockTime(0)
                                            ->encodeTrading();
        return returnSuccess(['trading' => $trading]);
    }

    /**
     * 30个节点激励
     */
    public function calculateNoderReward($table_num = 0, $nodes = [], &$incentives = [])
    {
        var_dump('noder');
        $balance = 0;//激励结余
        $reward = 0;//奖励数
        //计算可获得的汤圆
        $tokens = $incentives[$table_num]['noder']['quantitative'];
        //开始计算激励交易金额
        $tokens = floor($tokens / $this->yearRoundsNum);
        //加上上一轮的结余
        $tokens += $incentives[$table_num - 1]['noder']['balance'];
        //组装成交易
        $trading = [];//存储交易内容
        //排名前30个节点，按照比例进行分配
        $trading['tx'][0]['coinbase'] = 'No one breather who is worthier.';
        $trading['from'] = get_instance()->config['address'];
        $trading['to'] = [];
        $total_vote = array_sum(array_column($nodes, 'value'));
        if($total_vote * 0.001 > $tokens){
            //大于激励总量
            $balance = floor($tokens * 0.2);
            $scale_tokens = floor($tokens * 0.8);
            //按照比例进行分配
            foreach ($nodes as $nd_key => $nd_val){
                $trading['to'][] = [
                    'address'   =>  $nd_key,
                    'type'      =>  1,
                    'value'     =>  floor($nd_val['value'] * $scale_tokens),
                ];
            }
        }else{
            //按照1:0.001比例进行分配
            foreach ($nodes as $nd_key => $nd_val){
                $reward += $nd_val['value'] / 1000;
                $trading['to'][] = [
                    'address'   =>  $nd_key,
                    'type'      =>  1,
                    'value'     =>  $nd_val['value'] / 1000,
                ];
            }
        }
        //进行序列化
        $trading = $this->TradingEncode->setVin($trading['tx'])
                                        ->setVout($trading['to'])
                                        ->setTime(time())
                                        ->setLockTime(0)
                                        ->encodeTrading();
        //处理结余数据
        $incentives[$table_num]['noder']['balance'] = floor($balance
                                                      + $tokens
                                                      - $reward);
        //返回交易以及处理好的数据
        return returnSuccess(['trading' => $trading, 'incentives' => $incentives]);
    }

    /**
     * 社群激励
     */
    public function calculateCommunityReward($table_num = 0, &$incentives = [], $community_adderss = 'testAddress')
    {
        var_dump('community');
        //计算可获得的汤圆
        $tokens = $incentives[$table_num]['community'];
        /**
         * 每年块数（86400/126） * 63（一轮的块数） * 365
         */
        $tokens = floor($tokens / $this->yearBlockNum);

        //组装成交易
        $trading = [];//存储交易内容
        //最多只有前1000个用户
        $trading['tx'][0]['coinbase'] = 'No one breather who is worthier.';
        $trading['from'] = get_instance()->config['address'];
        $trading['to'][0]['address'] = $community_adderss;
        $trading['to'][0]['value'] = $tokens;
        $trading['to'][0]['type'] = 1;
        //把交易序列化
        $trading = $this->TradingEncode->setVin($trading['tx'])
                                        ->setVout($trading['to'])
                                        ->setTime(time())
                                        ->setLockTime(0)
                                        ->encodeTrading();
        return returnSuccess(['trading' => $trading]);
    }

    /**
     * 把已经确认的交易打入各自的钱包中
     * @param type $trading 交易合集
     */
    public function bookedPurse(array $trading = [])
    {
        $insert_purse = [];//插入钱包集合
        $cache_purse = [];//更新缓存
        foreach ($trading as $t_key => $t_val){
            $decode_trading = $this->TradingEncode->decodeTrading($t_val);
            foreach ($decode_trading['vout'] as $dt_key => $dt_val){
                //处理插入集合的数据
                $insert_purse[] = [
                    'address'   =>  $dt_val['address'],
                    'txId'      =>  $decode_trading['txId'],
                    'n'         =>  $dt_key,
                    'value'     =>  $dt_val['value'],
                    'reqSigs'   =>  $dt_val['reqSigs'],
                    'lockTime'  =>  $decode_trading['lockTime'],
                ];
                //处理缓存数据
                $cache_purse[$dt_val['address']][] = [
                    'txId'      =>  $decode_trading['txId'],
                    'n'         =>  $dt_key,
                    'value'     =>  $dt_val['value'],
                    'reqSigs'   =>  $dt_val['reqSigs'],
                    'lockTime'  =>  $decode_trading['lockTime'],
                ];
            }
        }
        $this->PurseModel->addPurseTradings($insert_purse);
        $this->PurseModel->refreshPurseTrading($cache_purse);
        return true;
    }

    /**
     * 判断是否到出块时间
     * @param type $time
     * @param type $node
     */
    public function getIsCore(int $time = 0, int $node = 21)
    {
        $scope = 0;
        $scope = $time % ($node * 2);
        if ($scope === (2 * ($this->index - 1))) {
            return true;
        }
        return false;
    }

    /**
     * 获取节点身份
     * @return string
     */
    public function getNodeIdentity() : string
    {
        return $this->Identity;
    }

    /**
     * 设置节点身份
     * @return string
     */
    public function setNodeIdentity(string $identity = 'ordinary')
    {
        return $this->Identity = $identity;
    }

    /**
     * 开启节点
     * @return string
     */
    public function openConsensus() : bool
    {
        return $this->openConsensus = true;
    }

    /**
     * 关闭节点
     * @return string
     */
    public function closeConsensus()
    {
        return $this->openConsensus = false;
    }

    /**
     * 获取节点工作次序
     * @return int
     */
    public function getIndex() : int
    {
        return $this->index;
    }

    /**
     * 设置节点身份
     * @return int
     * @oneWay
     */
    public function setIndex(int $index = 0)
    {
        return $this->index = $index;
    }

    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "区块头进程关闭.";
    }
}
