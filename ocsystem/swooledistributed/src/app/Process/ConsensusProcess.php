<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;

use app\Models\Node\NodeModel;
use app\Models\Node\VoteModel;
use app\Models\Purse\PurseModel;
use app\Models\Trading\TradingModel;
use app\Models\Block\BlockHeadModel;
use app\Models\Block\BlockBaseModel;
use app\Models\Block\MerkleTreeModel;
use app\Models\Trading\TradingUTXOModel;
use app\Models\Trading\TradingEncodeModel;
use app\Models\Action\ActionEncodeModel;
use Server\Components\CatCache\CatCacheRpcProxy;

use MongoDB;

//椭圆曲线加密算法
use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;

//自定义进程

use app\Process\PurseProcess;
use app\Process\PeerProcess;
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
     * 存储当前区块信息
     * @var array
     */
    private $Block = [];

    /**
     * 存储共识结果,判断是否可以上链
     * @var array
     */
    private $ConsensusResult = [];

    /**
     * 工作次序
     * @var
     */

    private $index = 0;

    /**
     * 区块头部方法
     * @var
     */
    private $BlockHead;

    /**
     * 区块基类方法
     * @var
     */
    private $BlockBase;

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
     * 节点相关方法
     * @var
     */
    private $NodeModel;

    /**
     * 投票相关方法
     * @var
     */
    private $VoteModel;

    /**
     * 节点身份，分为core（核心）,alternative（备选）,ordinary（普通）
     * @var string
     */
    private $Identity = "ordinary";

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
        $context = get_instance()->getNullContext();
        //区块基类相关方法
        $this->BlockBase = new BlockBaseModel();
        $this->BlockBase->initialization($context);
        //区块头部相关方法
        $this->BlockHead = new BlockHeadModel();
        //区块头部相关方法
        $this->MerkleTree = new MerkleTreeModel();
        //utxo加密算法
        $this->TradingUTXO = new TradingUTXOModel();
        //utxo加密算法
//        $this->TradingEncode = new TradingEncodeModel();
        //action序列化方法
        $this->TradingEncode = new ActionEncodeModel();
        //交易方法
        $this->TradingModel = new TradingModel();
        $this->TradingModel->initialization($context);
        //钱包类
        $this->PurseModel = new PurseModel();
        $this->PurseModel->initialization($context);

        $this->NodeModel = new NodeModel();
        $this->NodeModel->initialization($context);

        $this->VoteModel = new VoteModel();
        $this->VoteModel->initialization($context);
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
    public function chooseWork($time = 0)
    {
        $threshold = 0;
        var_dump($this->Identity);
        if($this->openConsensus){
//        while ($threshold < 10){
            switch ($this->Identity){
                case 'core' :
                    $this->coreNode($time);
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
    public function coreNode($now_time = 0)
    {
        if ($this->Identity == 'core' && $this->openConsensus){
//        if (true){
            //判断是否到自己出块
            $is_work = $this->getIsCore($now_time, count(CatCacheRpcProxy::getRpc()->offsetGet('SuperNode')));
//            $is_work = true;
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
                $tradings = [];//存储交易哈希
                $top_block_hash = '';//最新的区块头哈希
                $top_block_height = 0;//区块高度
                $encode_trading = [];//未序列化交易
                $this_time = time();//打包出块时间
                $context = getNullContext();//报文
                //获取项目系统时间
                $system_time = ProcessManager::getInstance()
                                            ->getRpcCall(TimeClockProcess::class)
                                            ->getCreationTime();
                //执行激励策略
                $incenrive_data = $this->incentive(get_instance()->config['address'], $this_time);
                $page = 1;
                $pagesize = 20000;
                $trading_where = ['time' => ['$gte' => 1]];//time() - 2
                $trading_data = ['trading' => 1, '_id' => 1, 'time' => -1];
                $trading_res = ProcessManager::getInstance()
                                            ->getRpcCall(TradingPoolProcess::class)
                                            ->getTradingPoolList($trading_where, $trading_data, $page, $pagesize);
                if(!$trading_res['IsSuccess']){
//                    return returnError('数据获取失败!');
                }
                if (!empty($trading_res['Data'])){
                    foreach ($trading_res['Data'] as $tr_key => $tr_val){
//                    $decode_trading[] = $this->TradingEncode->decodeTrading($tr_val['trading']);
                        //解析action后，执行相应的验证操作
                        $decode_action = $this->TradingEncode->decodeAction($tr_val['trading']);
                        if($decode_action == false){
                            continue;
                        }
                        switch ($decode_action['actionType']){
                            case 2 :
                                $res = $this->VoteModel->checkVoteRequest(['action' => $decode_action], $tr_val['trading'], 1, 2);
                                break;
                            case 3 :
                                $res = $this->NodeModel->checkNodeRequest(['action' => $decode_action], $tr_val['trading'], 2, 1);
                                break;
                            default:
                                $res = $this->TradingModel->checkTradingRequest(['action' => $decode_action], $tr_val['trading'], 3, 1);
                                break;
                        }
                        if(!$res['IsSuccess']){
                            //验证不通过直接跳过
                            continue;
                        }
                        $encode_trading[] = $tr_val['trading'];
                        $tradings[] = $tr_val['_id'];
                    }
                }
                if (!empty($incenrive_data['Data'])){
                    foreach ($incenrive_data['Data'] as $id_key => $id_val){
                        $encode_trading[] = $id_val;
                        $tradings[] = bin2hex(hash('sha256',  hash('sha256', hex2bin($id_val), true), true));
                    }
                }
                //验证数据是否为空
                if(!empty($trading_res['Data']) || !empty($incenrive_data['Data'])){
                    //获取交易数量
                    $trading_num = count($tradings);
//                    var_dump('begin');
//                    var_dump($tradings);
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
                    $block_head = $this->BlockHead->setMerkleRoot($morker_tree_root)
                                                    ->setParentHash($top_block_hash)//上一个区块的哈希
                                                    ->setThisTime($system_time)//区块生成的项目时间
                                                    ->setSignature(get_instance()->config['address'])//工作者签名
                                                    ->setHeight($top_block_height + 1)//区块高度先暂存，后期不上
                                                    ->setTxNum($trading_num)
                                                    ->setTradingInfo($encode_trading)
                                                    ->packBlockHead();
                    //替换交易内容为交易hash
//                    $block_head['tradingInfo'] = $tradings;
                    $this->Block[$block_head['headHash']] = $block_head;
                    $this->Block[$block_head['headHash']]['tradingInfo'] = $tradings;
                    $this->Block[$block_head['headHash']]['state'] = true;
                    $this->Block[$block_head['headHash']]['out_time'] = time();
                    $this->ConsensusResult[$block_head['headHash']][get_instance()->config['address']] = true;
                    $this->ConsensusResult[$block_head['headHash']]['out_time'] = time();
                    $check_block = $this->getBlockMessage($block_head, $this_time, true);


                    //发起S共识
                    //广播区块数据
                    var_dump('区块创建完成，发送给其他超级节点确认.');
                    var_dump('区块hash:' . $block_head['headHash']);
//                    $this->superCheckBlock($check_block);
//                    return;
                    ProcessManager::getInstance()
                                    ->getRpcCall(CoreNetworkProcess::class, true)
                                    ->sendToSuperNode(json_encode($check_block), $context, 'NodeController', 'superConsensus', true);
                }
            }
//            sleepCoroutine(2000);
        }

    }

    /**
     * BFT共识
     * @param array $check_block
     * @return bool
     * @oneWay
     */
    public function superCheckBlock(array $check_block = [])
    {
        $clock_state = ProcessManager::getInstance()
                                    ->getRpcCall(TimeClockProcess::class)
                                    ->getClockState();
          $super_node = CatCacheRpcProxy::getRpc()->offsetGet('SuperNode');
        if (!$clock_state){
            return returnError('节点未启动');
        }
        if($this->Identity != 'core'){
            return returnError('节点未启动');
        }
        if(empty($check_block)){
            return returnError('非核心节点不验证区块.');
        }
        //判断消息是否已经过期
        if(($check_block['time'] + 60) < time()){
            var_dump('消息过期');
            return returnError('消息过期.');
        }
        if(!in_array($check_block['id'], $super_node) || !in_array($check_block['createder'], $super_node)){
            var_dump('区块节点身份有误.');
            return returnError('区块节点身份有误.');
        }
        if(isset($this->Block[$check_block['data']['headHash']]['state']) && !$this->Block[$check_block['data']['headHash']]['state']){
            var_dump('区块已确认!');
            return returnError('区块已确认!');
        }
        $check_res = true;//区块验证结果
        $error_msg = '';//区块失败原因
        //先判断是否是自身节点
        if($check_block['createder'] != get_instance()->config['address'] && $check_block['createder'] == $check_block['id']){
            //还没有验证过区块，先验证，只存储确认过的区块数据
            if(empty($this->Block[$check_block['data']['headHash']])){
//            if(1 == 1){
                $this->ConsensusResult[$check_block['data']['headHash']][$check_block['createder']] = true;
                $this->ConsensusResult[$check_block['data']['headHash']]['out_time'] = time();
                $this->Block[$check_block['data']['headHash']] = $check_block['data'];
                $this->Block[$check_block['data']['headHash']]['state'] = true;
                $this->Block[$check_block['data']['headHash']]['out_time'] = time();
                //获取这个发起者
                $incentive_res = $this->incentive($check_block['createder'], $check_block['time']);
                if(!$incentive_res['IsSuccess']){
                    return returnError($incentive_res['Message']);
                }
                //验证激励交易
                if(!empty(array_diff($incentive_res['Data'], $check_block['data']['tradingInfo']))){
                    $check_res = false;
                    $error_msg = '激励交易有误';
                }else{
                    //验证区块
                    $block_check_res = $this->BlockBase->checkBlockRequest($check_block['data'], 3);
                    if(!$block_check_res['IsSuccess']){
                        $check_res = false;
                        $error_msg = $block_check_res['Message'];
                    }
                }
//                $this->Block[$check_block['data']['headHash']] = $check_block['data'];
                $this->Block[$check_block['data']['headHash']]['tradingInfo'] = $block_check_res['Data'];
                //返回验证结果
                if(!$check_res){
                    var_dump("=========================================区块验证不通过=========================================");
                    var_dump('区块hash:' . $check_block['data']['headHash']);
                    var_dump('区块创建者:' . $check_block['createder']);
                    var_dump('区块发送者:' . $check_block['id']);
                    var_dump('原因:' . $error_msg);
                    $this->ConsensusResult[$check_block['data']['headHash']][get_instance()->config['address']] = $check_res;
                }else{
                    //存储结果
                    $this->ConsensusResult[$check_block['data']['headHash']][get_instance()->config['address']] = $check_res;
                }
                //广播结果
                $recheck_block = $this->getBlockMessage($check_block['data'], $check_block['time'], $check_res);
                $recheck_block['createder'] = $check_block['createder'];
                $context = getNullContext();
                //发起S共识
                ProcessManager::getInstance()
                            ->getRpcCall(CoreNetworkProcess::class, true)
                            ->sendToSuperNode(json_encode($recheck_block), $context, 'NodeController', 'superConsensus', true);
            }else{
                var_dump('异常2');
//                var_dump($check_block);
//                return;
            }
        }elseif($check_block['id'] != get_instance()->config['address']){
            $this->ConsensusResult[$check_block['data']['headHash']][$check_block['id']] = $check_block['res'];
            if(!isset($this->ConsensusResult[$check_block['data']['headHash']]['out_time'])){
                $this->ConsensusResult[$check_block['data']['headHash']]['out_time'] = time();
            }
            if($check_block['res']){
                var_dump($check_block['id'] . '确认区块');
                var_dump($check_block['data']['headHash']);
                var_dump('通过');
            }else{
                var_dump($check_block['id']);
                var_dump('确认区块' . $check_block['data']['headHash']);
                var_dump('不通过');
            }
        }else{
            var_dump('异常');
//            var_dump($check_block);
//            return;
        }
        //循环判断当前区块是否可以存库
        $check_count = 0;
        foreach ($this->ConsensusResult[$check_block['data']['headHash']] as $tc_key => $tc_val){
            if($tc_val && $tc_key !== 'out_time'){
                //验证为真，计数加1
                ++$check_count;
            }
        }
        var_dump('当前区块:'.$check_block['data']['headHash']);
        var_dump('确认数:'.$check_count);
        //判断是否可以上链
//        if($check_count > 1){
        if($check_count > (count(CatCacheRpcProxy::getRpc()->offsetGet('SuperNode')) * 2) / 3){
            //没有超过半数节点，不做记录，判断这个节点是否已经超时
            return returnError();
        }
        //超过半数节点通过，执行相应操作`
        //把超时时间设置为0
        $this->Block[$check_block['data']['headHash']]['state'] = false;
//        $this->Block[$check_block['data']['headHash']]['out_time'] = 0;
        //获取相应的交易数据
//        $incentive_deals = [];//存储激励交易
//        $encode_trading = [];//存储交易用于更新钱包
//        $trading_hashs  = [];//txId集合
        $tradings  = [];//交易集合
//        $page = 1;
//        $pagesize = count($this->Block[$check_block['data']['headHash']]['tradingInfo']);
//        $trading_where = ['_id' => ['$in' => $this->Block[$check_block['data']['headHash']]['tradingInfo']]];
//        $trading_data = ['trading' => 1, '_id' => 1];
//        $trading_res = ProcessManager::getInstance()
//                                ->getRpcCall(TradingPoolProcess::class)
//                                ->getTradingPoolList($trading_where, $trading_data, $page, $pagesize);
        foreach ($check_block['data']['tradingInfo'] as $tr_key => $tr_val){
            $tx_id = bin2hex(hash('sha256', hash('sha256', hex2bin($tr_val), true), true));
//            $encode_trading[] = $tr_val;
//            $trading_hashs[] = $tx_id;
            $tradings[] = [
                '_id'       =>   $tx_id,
                'trading'   =>  $tr_val,
            ];
            //不广播激励交易
//            $decode_action = $this->TradingEncode->decodeAction($tr_val);
////            if(preg_match('/^[A-Za-z0-9]{4}[0]{64}[A-Za-z0-9]+/', $tr_val) == 1){
//            if(in_array($decode_action['actionType'], [5, 6, 7, 8])){
//                //广播激励action
//                ProcessManager::getInstance()
//                            ->getRpcCall(PeerProcess::class, true)
//                            ->broadcast(json_encode(['broadcastType' => 'Action', 'Data' => $tr_val]));
//            }
        }
//        $tradings = $this->Block[$check_block['data']['headHash']]['tradingInfo'];
        $insert_block_data = $this->Block[$check_block['data']['headHash']];
//        $insert_block_data['tradingInfo'] = $encode_trading;
        unset($insert_block_data['out_time']);
        unset($insert_block_data['state']);
        //区块头上链
        $block_head_res = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->insertBlockHead($insert_block_data);
        //删除action,把action内容写入交易库中
        $trading_res =  ProcessManager::getInstance()
                                    ->getRpcCall(TradingProcess::class, true)
                                    ->insertTradingMany($tradings);

        var_dump(2);
        $delete_where = [
//                            '$and'  =>  [['_id'  => ['$in'  => $insert_block_data['tradingInfo']]]],
                            '$or'   =>  [['time' => ['$lte' => time() - 300]], ['_id'  => ['$in'  => $insert_block_data['tradingInfo']]]]
                        ];
        $trading_pool_res = ProcessManager::getInstance()
                                        ->getRpcCall(TradingPoolProcess::class)
                                        ->deleteTradingPoolMany($delete_where);
        var_dump(3);

//        $del_trading = ProcessManager::getInstance()
//                                    ->getRpcCall(BlockProcess::class)
//                                    ->checkTreading($tradings, $insert_block_data['tradingInfo']);
        var_dump(1);
        if($trading_pool_res['IsSuccess']){
            //操作成功,设置当前最新区块的高度跟哈希
//            ProcessManager::getInstance()
//                        ->getRpcCall(BlockProcess::class)
//                        ->setTopBlockHash($this->Block[$check_block['data']['headHash']]['headHash']);
//            ProcessManager::getInstance()
//                        ->getRpcCall(BlockProcess::class)
//                        ->setTopBlockHeight($this->Block[$check_block['data']['headHash']]['height']);
            //刷新action操作，包括投票、S质押、交易
            var_dump(2);
            $this->bookedPurse($check_block['data']['tradingInfo']);
            var_dump(3);
            //清空被使用的交易缓存
//            CatCacheRpcProxy::getRpc()['Using'] = [];
            //清理用户交易次数
            ProcessManager::getInstance()->getRpcCall(TradingProcess::class, true)->clearTradingNunm();
            //清理投票缓存
            ProcessManager::getInstance()->getRpcCall(VoteProcess::class, true)->clearVoteCache();
            //清理节点缓存
            ProcessManager::getInstance()->getRpcCall(NodeProcess::class, true)->clearNodeCache();
            var_dump("=========================================区块哈希=========================================");
            var_dump($this->Block[$check_block['data']['headHash']]['headHash']);
            //休眠1秒
            //清理过期数据
            var_dump("=========================================广播区块=========================================");
            //广播区块
            ProcessManager::getInstance()
                ->getRpcCall(PeerProcess::class, true)
                ->broadcast(json_encode(['broadcastType' => 'Block', 'Data' => $check_block['data']]));

            var_dump("=========================================清理缓存=========================================");
            var_dump(count($this->ConsensusResult));
            var_dump(count($this->Block));
            if(!empty($this->ConsensusResult)){
                foreach ($this->ConsensusResult as $cr_key => $cr_val){
                    if(!isset($cr_val['out_time'])){
                        unset($this->ConsensusResult[$cr_key]);
                        continue;
                    }
                    if($cr_val['out_time'] + 12 < time())
                        unset($this->ConsensusResult[$cr_key]);
                }
            }
            if(!empty($this->Block)){
                foreach ($this->Block as $b_key => $b_val){
                    if(!isset($b_val['out_time'])){
                        unset($this->Block[$b_key]);
                        continue;
                    }
                    if($b_val['out_time']  + 12 < time()){
                        //判断区块是否已经被确认，如果没有，需要返回区块交易数据
                        if($b_val['state']){
                            var_dump(10086);
                            //没有被确认的区块，返回交易数据
                            ProcessManager::getInstance()
                                            ->getRpcCall(TradingPoolProcess::class, true)
                                            ->refundTradingCache($b_val['tradingInfo']);
                        }
                        var_dump('clear');
                        unset($this->Block[$b_key]);
                    }else{
                        var_dump($b_val['out_time']);
                    }

                }
            }
            var_dump(date('Y-m-d H:i:s', time()));
            var_dump("=========================================区块确认结束=========================================");
            //广播激励交易
//            foreach ($incentive_deals as $id_key => $id_val){
//                ProcessManager::getInstance()
//                            ->getRpcCall(PeerProcess::class, true)
//                            ->broadcast(json_encode(['broadcastType' => 'Trading', 'Data' => ['trading' => $id_val]]));
//            }' => 'Block', 'Data' => $check_block['data']]));

        }else{
            var_dump('交易删除失败!');
        }

    }

    /**
     * 返回传输报文
     * @param $black_head
     * @return array
     */
    public function getNullContext()
    {
        return $context = [
            "start_time" => date('Y-m-d H:i:s'),
            'request_id'    => time() . crc32('null_controller' . 'null_method' . getTickTime() . rand(1, 10000000)),
            'controller_name'   => 'null_controller',
            'method_name'   => 'null_method',
            'ip'        =>  get_instance()->config['ip'],
        ];
    }

    /**
     * 返回区块信息
     * @return array
     */
    public function getBlockMessage($black_head, $time = 0, $res = true)
    {

        return $check_block = [
            'id'        => get_instance()->config['address'],
            'time'      =>  $time > 0 ? $time : time(),
            'createder'   => get_instance()->config['address'],
            'data'      =>  $black_head,
            'res'       =>  $res,
        ];
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
        $insert_data['_id']     =  bin2hex(hash('sha256', hash('sha256', hex2bin($incentive_trading),true), true));
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
    public function incentive($address = '', $work_time = 0)
    {
        $node  = [];//存储节点数据
        $voter = [];//存储投票者数据
        $tradings = [];//存储激励交易
        $tradings_temp = [];//存储临时结果
        if($work_time == 0){
            $work_time = time();
        }
        //获取轮次
//        $now_round = 1;
        $now_round = ProcessManager::getInstance()
            ->getRpcCall(TimeClockProcess::class)
            ->getRounds();
        $address = $address !== '' ?  $address : get_instance()->config['address'];
        //获取奖励列表
        $incentives = ProcessManager::getInstance()
            ->getRpcCall(IncentivesProcess::class)
            ->getIncentivesTable([],['_id' => 0])['Data'];
        $table_num = ceil($now_round / $this->yearRoundsNum);
        if(($now_round > 0) && ($now_round % $this->yearRoundsNum) == 0){
//        if (true){
            //跨年时段，对累积字段进行规整
            //选中的前30个节点
//            var_dump($table_num);
            $incentives[$table_num]['noder']['balance'] += $incentives[$table_num - 1]['noder']['balance'];
            $incentives[$table_num - 1]['noder']['balance'] = 0;
            //修改激励
            ProcessManager::getInstance()
                ->getRpcCall(IncentivesProcess::class)
                ->updateIncentivesTable($incentives, $table_num - 1);

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
            $node[$sn_val['address']]['voterNum'] = $sn_val['voterNum'] * 100000000;//array_sum(array_column($sn_val['voters'], 'value'));
            if($sn_val['address'] == $address){
                $voter = $sn_val['voters'];
            }
        }
//        if(empty($voter)){
//            return returnError('该节点不是超级节点.');
//        }
        //计算需要给当前节点前1000用户的代币
        if(!empty($voter)){
            $tradings_temp = $this->calculateVoterReward($table_num, array_slice($voter, 0, 1000), $incentives, $work_time, $address);
            $tradings[] = $tradings_temp['Data']['trading'][0];
            count($tradings_temp['Data']['trading']) == 2 && $tradings[] = $tradings_temp['Data']['trading'][1];
        }
        //计算节点自身可以获得的代币
        $tradings[] = $this->calculateWorkerReward($table_num, $address, $incentives, $work_time)['Data']['trading'];
        //计算30个主节点21核心节点+9备选节点可以获得的代币
        !empty($node) &&  $node_res = $this->calculateNoderReward($table_num, $node, $incentives, $work_time, $address);
        if($node_res['IsSuccess']){
            $tradings[] = $node_res['Data']['trading'];
        }
        //计算社群可以获得的代币(地址要换)
        $tradings[] = $this->calculateCommunityReward($table_num, $incentives, '792b9a33bebdd38114fa12c2699ed7112be40b95', $work_time, $address)['Data']['trading'];
//        //更新激励数据
//        ProcessManager::getInstance()
//                        ->getRpcCall(IncentivesProcess::class)
//                        ->updateIncentivesTable($incentives, $table_num);
        //插入交易
//        $this->TradingModel->createTradingMany($tradings, true);
        return returnSuccess($tradings);
    }

    /**
     * 投票者激励
     * 当前块投票者获得的奖励
     */
    public function calculateVoterReward($table_num = 0, $voters = [], &$incentives = [], $work_time = 0, $address)
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
        $count = $voter_count > 1000 ? 1000 : $voter_count;
        for($i = 0; $i < $count; ++$i){
            if($voters[$i]['value'] >= 1000000000000){
                $trading['to'][] = [
                    'address'   =>  $voters[$i]['address'],
                    'value'     =>  floor(($tokens * ($voters[$i]['value'] / $total_pledge)) / 1000),
                    'type'      =>  1,
                ];
            }
        }
        //进行序列化
        $tradings[] = $this->TradingEncode->setVin($trading['tx'])
            ->setVout($trading['to'])
            ->setTime($work_time)
            ->setLockTime(0)
            ->setActionType(5)
            ->setAddress($address)
            ->encodeAction();


//        var_dump(3);
//        if($voter_count > 1){
//            var_dump(4);
//            //大于500个人，拆成两笔交易
//            $trading = [];
//            $count = $voter_count > 1000 ? 1000 : $voter_count;
//            for($i = 1; $i < $count; ++$i){
//                if($voters[$i]['value'] >= 1000000000000){
//                    $trading['to'][] = [
//                        'address'   =>  $voters[$i]['address'],
//                        'value'     =>  floor(($tokens * ($voters[$i]['value'] / $total_pledge)) / 1000),
//                        'type'      =>  1,
//                    ];
//                }
//
//            }
//            var_dump($trading);
//            var_dump(5);
//            $tradings[] = $this->TradingEncode->setVin($trading['tx'])
//                                                ->setVout($trading['to'])
//                                                ->setTime($work_time)
//                                                ->setLockTime(0)
//                                                ->setActionType(5)
//                                                ->setAddress($address)
//                                                ->encodeAction();
//            var_dump(6);
//        }
        $total = floor($tokens * $count);
        $incentives[$table_num]['voter'] -= $total;
        //返回交易以及处理好的数据
        return returnSuccess(['trading' => $tradings, 'incentives' => $incentives]);
    }

    /**
     * 工作节点激励
     * 21个工作节点出块固定奖励
     */
    public function calculateWorkerReward($table_num = 0, $address = '', &$incentives = [], $work_time = 0)
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
        $trading['to'][0]['address'] = $address;
        $trading['to'][0]['value'] = $tokens;
        $trading['to'][0]['type'] = 1;
        //把交易序列化
        $trading = $this->TradingEncode->setVin($trading['tx'])
            ->setVout($trading['to'])
            ->setTime($work_time)
            ->setLockTime(0)
            ->setActionType(7)
            ->setAddress($address)
            ->encodeAction();
        return returnSuccess(['trading' => $trading]);
    }

    /**
     * 30个节点激励
     * 30个节点根据得票获得奖励
     */
    public function calculateNoderReward($table_num = 0, $nodes = [], &$incentives = [], $work_time = 0, $address)
    {
        var_dump('noder');
        $balance = 0;//激励结余
        $reward = 0;//奖励数
        //计算可获得的汤圆
        $tokens = $incentives[$table_num]['noder']['quantitative'];
        //开始计算激励交易金额
        $tokens = floor($tokens / $this->yearRoundsNum);
        //加上上一轮的结余
        $tokens += $incentives[$table_num]['noder']['balance'];
        //组装成交易
        $trading = [];//存储交易内容
        //排名前30个节点，按照比例进行分配
        $trading['tx'][0]['coinbase'] = 'No one breather who is worthier.';
        $trading['from'] = get_instance()->config['address'];
        $trading['to'] = [];
//        var_dump($nodes);
        $total_vote = array_sum(array_column($nodes, 'voterNum'));
//        if (true){
        if($total_vote * 0.001 > $tokens){
            //大于激励总量
            $balance = floor($tokens * 0.2);
            $scale_tokens = floor($tokens * 0.8);
            //按照比例进行分配
            foreach ($nodes as $nd_key => $nd_val){
                $trading['to'][] = [
                    'address'   =>  $nd_key,
                    'type'      =>  1,
                    'value'     =>  floor($scale_tokens * ($nd_val['voterNum'] / $total_vote)),
                ];
            }
        }elseif($total_vote == 0){
            return returnError();
        }else{
            //按照1:0.001比例进行分配
            foreach ($nodes as $nd_key => $nd_val){
                $reward += $nd_val['voterNum'] / 1000;
                if($nd_val['voterNum'] / 1000 <= 0){
                    continue;
                }
                $trading['to'][] = [
                    'address'   =>  $nd_key,
                    'type'      =>  1,
                    'value'     =>  $nd_val['voterNum'] / 1000,
                ];
            }
        }
        //进行序列化
        $trading = $this->TradingEncode->setVin($trading['tx'])
            ->setVout($trading['to'])
            ->setTime($work_time)
            ->setLockTime(0)
            ->setActionType(6)
            ->setAddress($address)
            ->encodeAction();
        //处理结余数据
        $incentives[$table_num]['noder']['balance'] = floor($balance
            + $tokens
            - $reward);
        //返回交易以及处理好的数据
        return returnSuccess(['trading' => $trading, 'incentives' => $incentives]);
    }

    /**
     * 社群激励
     * 社群固定奖励
     */
    public function calculateCommunityReward($table_num = 0, &$incentives = [], $community_adderss = '792b9a33bebdd38114fa12c2699ed7112be40b95', $work_time = 0, $address)
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
            ->setTime($work_time)
            ->setLockTime(0)
            ->setActionType(8)
            ->setAddress($address)
            ->encodeAction();
        return returnSuccess(['trading' => $trading]);
    }

    /**
     * 把已经确认的交易打入各自的钱包中
     * @param type $trading 交易合集
     * @oneWay
     */
    public function bookedPurse(array $trading = [])
    {
        $insert_purse = [];//插入钱包集合
        $cache_purse = [];//更新缓存
        foreach ($trading as $t_key => $t_val){
//            $decode_trading = $this->TradingEncode->decodeTrading($t_val);
            $decode_trading = $this->TradingEncode->decodeAction($t_val);
            if (!empty($decode_trading['trading'])){
                if($decode_trading['actionType'] == 2 && $decode_trading['action']['again'] == 2){
                    foreach ($decode_trading['trading']['vin'] as $dt_key => $dt_val){
                        $again_trading = CatCacheRpcProxy::getRpc()->offsetGet('Using'.$dt_val['txId'].$dt_val['n']);
                        $insert_purse[] = [
                            'address'   =>  $again_trading['address'],
                            'txId'      =>  $again_trading['txId'],
                            'n'         =>  $again_trading['n'],
                            'value'     =>  $again_trading['value'],
                            'reqSigs'   =>  $again_trading['reqSigs'],
                            'lockTime'  =>  $again_trading['lockTime'],
                            'createdBlock'  =>  $again_trading['createdBlock'],
                            'actionType'  =>  $again_trading['actionType'],
                        ];
                        //处理缓存数据
                        $cache_purse[$again_trading['address']][] = [
                            'txId'      =>  $again_trading,
                            'n'         =>  $again_trading['n'],
                            'value'     =>  $again_trading['value'],
                            'reqSigs'   =>  $again_trading['reqSigs'],
                            'lockTime'  =>  $again_trading['lockTime'],
                            'createdBlock' =>  $again_trading['createdBlock'],
                            'actionType'  =>  $again_trading['actionType'],
                        ];
                    }
                }else{
                    $tx_id = $decode_trading['txId'];
                    $lock_time = $decode_trading['trading']['lockTime'];
                    $created_block = $decode_trading['createdBlock'];
                    $action_type = $decode_trading['actionType'];
                    array_map(function ($vout, $vin) use ($tx_id, $lock_time, $created_block, $action_type, &$cache_purse, &$insert_purse){
                        if($vout != null){
                            //处理插入集合的数据
                            $insert_purse[] = [
                                'address'   =>  $vout['address'],
                                'txId'      =>  $tx_id,
                                'n'         =>  $vout['n'],
                                'value'     =>  $vout['value'],
                                'reqSigs'   =>  $vout['reqSigs'],
                                'lockTime'  =>  $lock_time,
                                'createdBlock'  =>  $created_block,
                                'actionType'  =>  $action_type,
                            ];
                            //处理缓存数据
                            $cache_purse[$vout['address']][] = [
                                'txId'      =>  $tx_id,
                                'n'         =>  $vout['n'],
                                'value'     =>  $vout['value'],
                                'reqSigs'   =>  $vout['reqSigs'],
                                'lockTime'  =>  $lock_time,
                                'createdBlock' =>  $created_block,
                                'actionType'  =>  $action_type,
                            ];
                        }
                        if($vin != null && !isset($vin['coinbase'])){
//                            var_dump(CatCacheRpcProxy::getRpc()->offsetGet('Using' . $vin['txId'] .  $vin['n']));
                            unset(CatCacheRpcProxy::getRpc()['Using' . $vin['txId'] .  $vin['n']]);
                        }
                    }, $decode_trading['trading']['vout'], $decode_trading['trading']['vin']);
                }
            }
            switch ($decode_trading['actionType']){
                case 2 :
                    //投票
                    $check_vote['rounds'] = $decode_trading['action']['rounds'];//所投轮次
                    $check_vote['voter'] = $decode_trading['action']['voter'];//质押人员
                    $vote_type = $decode_trading['action']['again'] ?? 1;//投票类型
                    var_dump(6);
                    //判断是否有提交质押的交易
                    if(!empty($decode_trading['trading'])){
                        $check_vote['trading']['value'] = $decode_trading['trading']['vout'][0]['value'] ?? 0;//质押金额
                        $check_vote['trading']['lockTime'] = $decode_trading['trading']['lockTime'];//质押时间
                        //根据投票类型，插入质押的txId
                        if($vote_type == 1){
                            $check_vote['trading']['txId'][$decode_trading['txId']] = $decode_trading['txId'];
                        }else{
                            //重质押获取vin中的txId
//                            $check_vote['trading']['txId'][$decode_trading['txId']] = $decode_trading['txId'];
                            foreach ($decode_trading['trading']['vin'] as $dt_val){
                                $check_vote['trading']['txId'][$dt_val['txId']] = $dt_val['txId'];
                            }
                        }
                        //重置序号
                        sort($check_vote['trading']['txId']);
                    }else{
                        $check_vote['trading'] = [];
                    }
                    $check_vote['address'] = $decode_trading['action']['candidate'];
                    $this->VoteModel->submitVote($check_vote);
                    break;
                case 3 :
                    //S节点质押
                    $check_node = [];
                    $check_node['value'] = $decode_trading['trading']['vout'][0]['value'];//质押金额
                    $check_node['lockTime'] = $decode_trading['trading']['lockTime'];//质押时间
                    $check_node['address'] = $decode_trading['action']['pledgeNode'];
                    $check_node['ip']   = $decode_trading['action']['ip'];
                    $check_node['port'] = $decode_trading['action']['port'];
                    $check_node['txId'] = $decode_trading['txId'];
                    $this->NodeModel->submitNode($check_node);
                    break;
                case 6 : //处理激励累积
                    //获取轮次
                    $now_round = ProcessManager::getInstance()
                                                ->getRpcCall(TimeClockProcess::class)
                                                ->getRounds();
                    //获取奖励列表
                    $incentives = ProcessManager::getInstance()
                                                ->getRpcCall(IncentivesProcess::class)
                                                ->getIncentivesTable([],['_id' => 0])['Data'];
                    $table_num = ceil($now_round / $this->yearRoundsNum);
                    $balance = $incentives[$table_num]['noder']['balance'];
                    $tokens = floor($incentives[$table_num]['noder']['quantitative'] / $this->yearRoundsNum);
                    $tokens += $balance;
                    $consumption = 0;
                    foreach ($decode_trading['trading']['vout'] as $dtt_key => $dtt_val){
                        $consumption += $dtt_val['value'];
                    }
                    if(($tokens - $consumption) > 0){
                        //还有余额，修改数据表
                        $incentives[$table_num]['noder']['balance'] = $tokens - $consumption;
                        ProcessManager::getInstance()
                                        ->getRpcCall(IncentivesProcess::class)
                                        ->updateIncentivesTable($incentives, $table_num);
                    }else{
                        $incentives[$table_num]['noder']['balance'] = 0;
                        ProcessManager::getInstance()
                                    ->getRpcCall(IncentivesProcess::class)
                                    ->updateIncentivesTable($incentives, $table_num);
                    }

                    break;
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
        if($node % 2 == 0){
            return false;
        }
        $scope = 0;
        $scope = $time % ($node * 2);
        var_dump('出块次序:'.$this->index);
        var_dump($scope === (2 * $this->index - 1));
        if ($scope === (2 * $this->index - 1)) {//$scope === (2 * ($this->index - 1)) ||
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
        echo "共识进程关闭.";
    }
}
