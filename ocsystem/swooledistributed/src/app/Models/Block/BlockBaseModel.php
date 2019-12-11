<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部相关操作
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Block;

use app\Models\Purse\PurseModel;
use app\Process\ConsensusProcess;
use app\Process\NodeProcess;
use app\Process\VoteProcess;
use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use app\Models\Action\ActionEncodeModel;
use app\Models\Node\NodeModel;
use app\Models\Node\VoteModel;
use app\Models\Trading\TradingModel;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\BlockProcess;
use app\Process\PeerProcess;
use app\Process\TradingProcess;
use app\Process\PurseProcess;
use app\Process\TradingPoolProcess;
use Server\Components\Process\ProcessManager;

class BlockBaseModel extends Model
{

    /**
     * 构建区块头部
     * @var
     */
    protected $BlockHead;

    /**
     * 构建默克尔树
     * @var
     */
    protected $MerkleTree;

    /**
     * 交易方法
     * @var
     */
    protected $TradingModel;
    /**
     * 交易序列化方法
     * @var
     */
    protected $TradingEncode;

    /**
     * 节点相关方法
     * @var
     */
    protected $NodeModel;

    /**
     * 投票相关方法
     * @var
     */
    protected $VoteModel;

    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        $this->BlockHead = $this->loader->model('Block/BlockHeadModel', $this);
        $this->MerkleTree = $this->loader->model('Block/MerkleTreeModel', $this);
        //action序列化方法
        $this->TradingEncode = $this->loader->model('Action/ActionEncodeModel', $this);;
        //交易方法
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        //节点方法
        $this->NodeModel = $this->loader->model('Node/NodeModel', $this);
        //投票方法
        $this->VoteModel = $this->loader->model('Node/VoteModel', $this);
    }

    /**
     * 查询区块信息
     * @param string $head_hash
     * @return bool
     */
    public function queryBlock($head_hash = [], $type = 1)
    {

        $block_res = [];
        isset($head_hash['headHash']) && $where['headHash'] = $head_hash['headHash'];
        isset($head_hash['height']) && $where['height'] = intval($head_hash['height']);
        if(empty($where)){
            return returnError('请传入区块哈希.');
        }
        $data = ['_id' => 0];
        $block_res = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getBlockHeadInfo($where, $data);
        if($type == 2 && !empty($block_res['Data'])){
            $trading_where = ['_id' => ['$in' => $block_res['Data']['tradingInfo']]];
            $trading_data = ['_id' => 0];
            $trading_info = ProcessManager::getInstance()
                                        ->getRpcCall(TradingProcess::class)
                                        ->getTradingList($trading_where, $trading_data, 1, count($block_res['Data']['tradingInfo']));
            $block_res['Data']['tradingInfo'] = array_column($trading_info['Data'], 'trading');
        }
        return $block_res;
    }

    /**
     * 验证区块函数
     * @param array $block_head
     * @param array $trading_type  1：不验证交易   2：验证交易
     * @param array $is_broadcast 1：正常验证区块  2：广播验证区块
     * @return bool
     */
    public function checkBlockRequest(array $block_head = [], $trading_type = 1, $is_broadcast = 1)
    {
//        var_dump($block_head);
        $trading_info_hashs = [];//存储区块确认的交易摘要
        $trading_info_all = [];//存储交易摘要和整交易
        $merkle_leaves = [];//存储默克尔树叶子节点
        //判断区块状态,决定是否要同步数据
//        if($is_broadcast == 1){
//            $check_block_state = $this->getBlockSituation($block_head);
//            if (!$check_block_state['IsSuccess']){
////                var_dump($check_block_state);
//                return returnError($check_block_state['Message']);
//            }
//        }

        //验证上一个区块的哈希是否存在
        $block_where = ['headHash' => ['$in' => [$block_head['parentHash'], $block_head['headHash']]]];
        $block_data = ['headHash' => 1, 'parentHash' => 1];
        $block_res = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getBloclHeadList($block_where, $block_data, 1, 2, []);
        if(empty($block_res['Data'])){
//            var_dump($block_res['Data']);
//            var_dump($block_where);
//            var_dump($block_head);
            var_dump('区块数据有误，请重启系统进行同步.');
            $is_broadcast == 2 && ProcessManager::getInstance()
                                            ->getRpcCall(BlockProcess::class, true)
                                            ->setBlockState(1);
            $this->getBlockSituation($block_head);
            return returnError('区块数据有误，请重启.');
//            var_dump(2);
//            //判断数据库是否有区块数据
//            $check_res = $this->checkBlockSync($block_head);
//            var_dump(3);
//            var_dump($check_res);
//            if(!$check_res['IsSuccess']){
//                return returnError('区块同步中.');
//            }
//            var_dump(4);
//            return returnError('数据缺失.');
        }elseif (count($block_res['Data']) == 1 && $block_res['Data'][0]['headHash'] == $block_head['headHash']){
            var_dump('区块有误，需要重新同步区块.');
            return returnError('区块有误，需要重新同步区块.');
        }elseif (count($block_res['Data']) == 1 && $block_res['Data'][0]['headHash'] == $block_head['parentHash']){
            //正常执行逻辑
            var_dump('正常逻辑');
        }elseif ($block_res['Data'][0]['headHash'] == $block_head['parentHash']
                &&
                $block_res['Data'][1]['headHash'] == $block_head['headHash']){
            //已经有区块数据，跳过
            var_dump('区块已存在');
            return returnError('区块已存在');
        }elseif ($block_res['Data'][0]['headHash'] != $block_head['parentHash']
            &&
            $block_res['Data'][1]['headHash'] != $block_head['headHash']){
            var_dump('区块数据有误');
            return returnError('区块数据有误');
        }
        if($trading_type == 1) {
            //验证交易是否都存在
            $trading_info_hashs = $block_head['tradingInfo'];
            $trading_where = ['_id' => ['$in' => $trading_info_hashs]];
            $trading_data = ['time' => 0, '_id' => 0, 'noce' => 0];
            $trading_res = [];
            //查询交易池内容
            $trading_res = ProcessManager::getInstance()
                                        ->getRpcCall(TradingPoolProcess::class)
                                        ->getTradingPoolList($trading_where, $trading_data, 1, count($trading_info_hashs));
            if (empty($trading_res['Data']) || count($trading_res['Data']) != count($trading_info_hashs)) {
                var_dump('区块验证失败!');
                return returnError('区块验证失败!');
            }
//            $block_head['tradingInfo'] = array_values($trading_res['Data']);
            $block_head['tradingInfo'] = array_column($trading_res['Data'], 'trading');
            $trading_info_all = $trading_res['Data'];
        }else{
            foreach ($block_head['tradingInfo'] as $bh_key => $bh_val){
//                $tx_id = bin2hex(hash('sha256',  hash('sha256', hex2bin($bh_val), true), true));
                $decode_action = $this->TradingEncode->decodeAction($bh_val);
                if($decode_action == false){
                    var_dump('action数据有误!');
                    return returnError('action数据有误!');
                }
                if ($trading_type == 3 && !in_array($decode_action['actionType'], [5, 6, 7, 8])){
                    var_dump(31);
                    //解析action后，执行相应的验证操作
//                    $decode_action = $this->TradingEncode->decodeAction($bh_val);
//                    if($decode_action == false){
//                        continue;
//                    }
                    var_dump(32);
                    switch ($decode_action['actionType']){
                        case 2 :
                            $res = $this->VoteModel->checkVoteRequest(['action' => $decode_action], $bh_val, 1, 2);
                            break;
                        case 3 :
                            $res = $this->NodeModel->checkNodeRequest(['action' => $decode_action], $bh_val, 2, 1);
                            break;
                        default:
                            var_dump(33);
                            $res = $this->TradingModel->checkTradingRequest(['action' => $decode_action], $bh_val, 3, 1);
                            break;
                    }
                    if(!$res['IsSuccess']){
                        var_dump($res);
                        //验证不通过直接跳过
                        continue;
                    }
                    var_dump(34);
                }
                $trading_info_hashs[] = $decode_action['txId'];
                $trading_info_all[] = [
                    '_id'       =>  $decode_action['txId'],
                    'trading'   =>  $bh_val
                ];

                $merkle_leaves[] = $decode_action['txId'];

            }
        }
        $merker_tree = $this->MerkleTree->setNodeData($merkle_leaves)
                                        ->bulidMerkleTreeSimple();
        //获取默克尔根
        $morker_tree_root = array_pop($merker_tree);
        //构建区块头部
        $check_head = $this->BlockHead->setMerkleRoot($morker_tree_root)
                                        ->setParentHash($block_head['parentHash'])//上一个区块的哈希
                                        ->setThisTime($block_head['thisTime'])
                                        ->setHeight($block_head['height'])//区块高度先暂存
                                        ->setTxNum(count($merkle_leaves))
                                        ->setTradingInfo($block_head['tradingInfo'])
                                        ->setSignature($block_head['signature'])
                                        ->setVersion($block_head['version'])
                                        ->packBlockHead();
//        var_dump($block_head);
//        var_dump($trading_info_hashs);
////        var_dump('end');
        if($check_head['headHash'] !== $block_head['headHash']){
            var_dump('区块验证不通过：'.$block_head['height']);
            return returnError('区块验证不通过!');
        }
        if ($is_broadcast != 1){
            var_dump(4);
            //处理交易
            //删除交易
//            var_dump($block_head);

            !empty($trading_info_all) && ProcessManager::getInstance()
                                                        ->getRpcCall(BlockProcess::class)
                                                        ->checkTreading($trading_info_all, $trading_info_hashs);
            var_dump(5);
            $block_state = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getBlockState();
            $block_state == 3 && ProcessManager::getInstance()
                                            ->getRpcCall(ConsensusProcess::class, true)
                                            ->bookedPurse($block_head['tradingInfo']);
            var_dump(6);
            $block_head['tradingInfo'] = $merkle_leaves;
            //写入区块
            ProcessManager::getInstance()
                            ->getRpcCall(BlockProcess::class, true)
                            ->insertBlockHead($block_head);
            //清空缓存数据
            ProcessManager::getInstance()
                ->getRpcCall(NodeProcess::class, true)
                ->clearNodeCache();
            ProcessManager::getInstance()
                ->getRpcCall(VoteProcess::class, true)
                ->clearVoteCache();

            var_dump(7);
            ProcessManager::getInstance()
                        ->getRpcCall(PeerProcess::class, true)
                        ->broadcast(json_encode(['broadcastType' => 'Block', 'Data' => $block_head]));
        }
        return returnSuccess($merkle_leaves);
    }

    /**
     * 处理未同步区块时接收到区块广播
     * @param array $block
     * @return bool
     */
    public function checkBlockSync(array $block = [])
    {
        $block_state = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getBlockState();
        if($block_state === 3){
            //区块未同步结束
            $block_res = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getBlockHeadInfo([], []);
            $this_top_height = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getTopBlockHeight();
            if(empty($block_res['Data']) || ($block['height'] - $this_top_height) >= 2){
                ProcessManager::getInstance()
                                ->getRpcCall(BlockProcess::class, true)
                                ->setBlockState(1);
                ProcessManager::getInstance()
                                ->getRpcCall(TradingProcess::class, true)
                                ->setTradingState(1);
                ProcessManager::getInstance()
                                ->getRpcCall(PurseProcess::class, true)
                                ->setPurseState(1);
            }
        }
        //把当前块的高度存入进程
        ProcessManager::getInstance()
                    ->getRpcCall(BlockProcess::class, true)
                    ->setSyncBlockTopHeight($block['height']);
        //把当前区块存入数据库
        ProcessManager::getInstance()
                        ->getRpcCall(BlockProcess::class, true)
                        ->insertBlockHead($block);
        return returnError('区块未同步');
    }

    /**
     * 判断是否在同步区块
     * @param array $block
     */
    public function getBlockSituation(array $block = [])
    {
        //获取同步的区块高度
        $sync_block_top_height = ProcessManager::getInstance()
                                            ->getRpcCall(BlockProcess::class)
                                            ->getSyncBlockTopHeight();
        //获取同步状态
        $block_state = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getBlockState();
        if($block_state == 1 || $block_state == 2){
            //区块同步未完成状态
            if($sync_block_top_height < $block['height']){
                var_dump('更新区块目标');
                var_dump($sync_block_top_height);
                //把当前块的高度存入进程
                ProcessManager::getInstance()
                            ->getRpcCall(BlockProcess::class, true)
                            ->setSyncBlockTopHeight($block['height']);
                //把当前区块存入数据库
                $txid_hashs = [];
                foreach ($block['tradingInfo'] as $b_key => $b_val){
                    $txid_hash = bin2hex(hash('sha256', hash('sha256', hex2bin($b_val), true), true));
                    $txid_hashs[] = $txid_hash;
                    $trading_infos[] = [
                        '_id'       =>  $txid_hash,
                        'trading'   =>  $b_val,
                    ];
                }
                $block['tradingInfo'] = $txid_hashs;
                ProcessManager::getInstance()
                            ->getRpcCall(BlockProcess::class, true)
                            ->insertBlockHead($block);
                ProcessManager::getInstance()
                    ->getRpcCall(BlockProcess::class, true)
                    ->checkTreading($trading_infos, $txid_hashs);
            }
            return returnError('区块同步中');
        }

        return returnSuccess();
    }


}
