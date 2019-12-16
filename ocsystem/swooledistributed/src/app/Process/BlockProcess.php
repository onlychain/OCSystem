<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;

use app\Models\Block\BlockHeadModel;
use app\Models\Node\NodeModel;
use app\Models\Block\MerkleTreeModel;
use app\Models\Consensus\ConsensusModel;
use app\Models\Node\VoteModel;
use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;
use app\Models\Trading\TradingEncodeModel;
use app\Models\Action\ActionEncodeModel;

use Server\Asyn\MQTT\Exception;
use Server\Components\Process\Process;
use Server\Components\Process\ProcessManager;
use Server\Components\CatCache\CatCacheRpcProxy;
use MongoDB;


use app\Process\TradingPoolProcess;
use app\Process\PeerProcess;
use app\Process\TradingProcess;
use app\Process\NodeProcess;
use app\Process\VoteProcess;
use app\Process\PurseProcess;
use app\Process\ConsensusProcess;

class BlockProcess extends Process
{
    /**
     * 数据同步状态
     * 1:区块未同步;2:区块同步中;3:区块同步完成;4:区块同步失败;
     * @var
     */
    private $BlockState = 1;

    /**
     * 存储数据库对象
     * @var
     */
    private $MongoDB;

    /**
     * 确认交易数据集合
     * @var
     */
    private $Block;

    /**
     * 存储数据库连接地址
     * @var
     */
    private $MongoUrl;

    /**
     * 区块头部方法
     * @var
     */
    private $BlockHead;

    /**
     * 默克尔树方法
     * @var
     */
    private $MerkleTree;

    /**
     * 交易序列化
     * @var
     */
    private $ActionEncodeModel;

    /**
     *
     * @var
     */
    private $NodeModel;

    /**
     * 同步区块的高度
     * @var int
     */
    private $SyncBlockTopHeight = 1;

    /**
     * 同步区块的高度
     * @var int
     */
    private $CurrentBlockTopHeight = 1;

    /**
     * 当前区块哈希
     * @var string
     */
    private $BlockTopHash = '564216eb2469219c64dcbfbeaca939d38cfb1cdb39c776548681ba5682b5cef1';
        //'564216eb2469219c64dcbfbeaca939d38cfb1cdb39c776548681ba5682b5cef1';//'bdec05be39801f477e15c73f67fa720f3ffc8803168616b819e1fdc4910554fd';

    /**
     * 每次获取区块的数量
     * @var int
     */
    private $Pagesize = 30;

    /**
     * 获取区块的游码
     * @var int
     */
    private $Limit = 1;

    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('BlockProcess');
//        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoUrl = 'mongodb://' . MONGO_IP . ":" . MONGO_PORT;
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->Block = $this->MongoDB->selectCollection('blocks', 'block');
        //区块头部相关方法
        $this->BlockHead = new BlockHeadModel();
        //区块头部相关方法
        $this->MerkleTree = new MerkleTreeModel();
        //交易序列化相关方法
//        $this->TradingEncodeModel = new TradingEncodeModel();
        $this->ActionEncodeModel = new ActionEncodeModel();
        //节点相关方法
        $this->NodeModel = new NodeModel();
        $this->BitcoinECDSA = new BitcoinECDSA();
    }

    /**
     * 获取区块头部数据
     * @param array $where
     * @param array $data
     * @param int $page
     * @param int $pagesize
     * @param array $sort
     * @return bool
     */
    public function getBloclHeadList($where = [], $data = [], $page = 1, $pagesize = 10000, $sort = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'limit'         =>  $pagesize,
            'skip'          =>  ($page - 1) * $pagesize,
            'sort'          =>  $sort,
        ];
        //获取数据
        $list_res = $this->Block->find($filter, $options)->toArray();
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 获取区块头部单条数据
     * @param array $where
     * @param array $data
     * @return bool
     */
    public function getBlockHeadInfo($where = [], $data = [], $order_by = [])
    {
        $info_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'sort'          =>  [],
        ];
        //获取数据
        $info_res = $this->Block->findOne($filter, $options);
        if(!empty($info_res)){
            //把数组对象转为数组
            $info_res = objectToArray($info_res);
        }
        return returnSuccess($info_res);
    }

    /**
     * 插入单条数据
     * @param array $block_head
     * @return bool
     */
    public function insertBlockHead($block_head = [])
    {
        if(empty($block_head)) return returnError('交易内容不能为空.');
        $insert_res = $this->Block->insertOne($block_head);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        if($block_head['height'] > $this->getTopBlockHeight()){
            //更新区块高度
            $this->setTopBlockHeight($block_head['height']);
            //更新最新区块哈希
            $this->setTopBlockHash($block_head['headHash']);
        }
        return returnSuccess(['id' => $insert_res->getInsertedId()->__toString()]);
    }

    /**
     * 插入多条数据
     * @param array $block
     * @return bool
     */
    public function insertBlockHeadMany($block = [], $get_ids = false)
    {
        if(empty($block)) return returnError('交易内容不能为空.');
        $insert_res = $this->Block->insertMany($block);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        $ids = [];
        if($get_ids){
            foreach ($insert_res->getInsertedIds() as $ir_val){
                $ids[] = $ir_val->__toString();
            }
        }
        $end_arr = array_pop($block);
        if($end_arr['height'] > $this->getTopBlockHeight()){
            //更新区块高度
            $this->setTopBlockHeight($end_arr['height']);
            //更新最新区块哈希
            $this->setTopBlockHash($end_arr['headHash']);
        }
        return returnSuccess(['ids' => $ids]);
    }

    /**
     * 删除单条数据
     * @param array $delete_where
     * @return bool
     */
    public function deleteBloclHead(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Block->deleteOne($delete_where);
        if(!$delete_res){
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     * 删除多条数据
     * @param array $delete_where
     * @return bool
     */
    public function deleteBloclHeadMany(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Block->deleteMany($delete_where);
        if(!$delete_res){
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     * 确认区块
     * @param array $block_head
     * @return bool
     */
    public function checkBlockHead(array $block_head = [])
    {
        $tradings = [];//存储交易数据
        $trading_where = [];//存储交易查询条件
        $trading_res = [];//存储交易查询结果
        $trading_num = 0;//存储交易数量
        $merker_tree = [];//存储默克树
        $morker_tree_root = '';//存储默克尔树树根
        $check_head = [];//存储验证用的区块头
        if(empty($block_head)){
            return returnError('请输入区块数据!');
        }
        //获取参与打包的交易
        $tradings = $block_head['tx'];
        $trading_where = ['txId' => ['$in' => $block_head['tx']]];
        $trading_res = ProcessManager::getInstance()
                                ->getRpcCall(TradingPoolProcess::class)
                                ->getTradingPoolList($trading_where);
        //判断交易数量是否正确

        $trading_num = count($trading_res['Data']);
        if($trading_num == count($block_head['tx'])){
            return returnError('交易数量有误!');
        }
        //生成默克尔树

        $check_block = $this->checkBlockData();

        //处理已经完成的交易
        $del_trading_res = $this->checkTreading($trading_res['Data']);
        if(!$del_trading_res['IsSuccess']){
            return returnError($del_trading_res['Message']);
        }
        return returnSuccess();
    }

    /**
     * 验证区块数据
     * @param array $block
     * @return bool
     */
    public function checkBlockData(array $block = [], array $trading_info_hash = [])
    {
        if(empty($block)){
            return returnError('区块不能为空.');
        }

        $merker_tree = $this->MerkleTree->setNodeData($trading_info_hash)
                                        ->bulidMerkleTreeSimple();
//        var_dump($merker_tree);
        //获取默克尔根
        $morker_tree_root = array_pop($merker_tree);
        //构建区块头部
        $check_head = $this->BlockHead->setMerkleRoot($morker_tree_root)
                                    ->setParentHash($block['parentHash'])//上一个区块的哈希
                                    ->setThisTime($block['thisTime'])
                                    ->setHeight($block['height'])//区块高度
                                    ->setTxNum(count($block['tradingInfo']))
                                    ->setTradingInfo($block['tradingInfo'])
                                    ->setSignature($block['signature'])
                                    ->setVersion($block['version'])
                                    ->packBlockHead();

//        var_dump($check_head);
//        var_dump($check_head['headHash']);
//        var_dump('=====================');
//        var_dump($block);
//        var_dump($block['headHash']);
        if($check_head['headHash'] !== $block['headHash']){
        var_dump($check_head);
        var_dump('=====================');
        var_dump($block);
            return returnError('区块验证失败');
        }
        return returnSuccess();
    }

    /**
     * 处理已经确认的交易
     * @param array $tradings
     * @param array $trading_hashs
     * @return bool
     */
    public function checkTreading(array $tradings = [], array $trading_hashs = [])
    {
        $trading_res = [];//交易集合操作结果
        $trading_pool_res = [];//交易池结果
        //确认传入交易
        if(empty($tradings)){
            return returnError('请传入交易数据!');
        }
        //确认传入交易哈希头
        if(empty($trading_hashs)){
            return returnError('请传入交易哈希头!');
        }
//        var_dump(7474);
//        var_dump($tradings);
        //将交易数据存入交易集合
        $trading_res =  ProcessManager::getInstance()
                                    ->getRpcCall(TradingProcess::class)
                                    ->insertTradingMany($tradings);
//        var_dump(6565);
        if(!$trading_res['IsSuccess']){
            return returnError($trading_res['Message']);
        }
//        if(empty($trading_hashs)){
//            foreach ($tradings as $t_key => $t_val){
//                $trading_hashs[] = bin2hex(hash('sha256', hash('sha256', hex2bin($t_val), true), true));
//            }
//        }
        //删除交易池内的交易数据
        $trading_pool_where = ['_id' => ['$in' => $trading_hashs]];
        $trading_pool_res = ProcessManager::getInstance()
                                ->getRpcCall(TradingPoolProcess::class)
                                ->deleteTradingPoolMany($trading_pool_where);
//        var_dump(7878);
        if(!$trading_pool_res['IsSuccess']){
            return returnError($trading_pool_res['Message']);
        }
        return returnSuccess();
    }

    /**
     * 设置区块头
     * @param string $block_hash
     * @return bool
     */
    public function setTopBlockHash(string $block_hash = '')
    {
        if($block_hash !== ''){
            CatCacheRpcProxy::getRpc()['topBlockHash'] = $block_hash;
        }else{
            //如果设置为空，去数据库中过去最新的区块
            $where = [];//查询条件
            $data = [];//查询字段
            $order_by = [
                'thisTime'  => -1,
            ];//排序
            $top_block_hash = $this->getBlockHeadInfo($where, $data, $order_by);
            if(!$top_block_hash['IsSuccess']){
                return returnError($top_block_hash['Message']);
            }
            CatCacheRpcProxy::getRpc()['topBlockHash'] =
                        $top_block_hash['Data']['headHash']
                        ?? '0000000000000000000000000000000000000000000000000000000000000000';
        }
    }

    /**
     * 获取最新的区块头
     * @return string
     */
    public function getTopBlockHash() : string
    {
        if(CatCacheRpcProxy::getRpc()->offsetGet('topBlockHash') == ''
           || CatCacheRpcProxy::getRpc()->offsetGet('topBlockHash') == NULL){
            $this->setTopBlockHash();
        }
        return CatCacheRpcProxy::getRpc()->offsetGet('topBlockHash');
    }

    /**
     * 设置区块高度
     * @param string $block_hash
     * @return bool
     */
    public function setTopBlockHeight(int $block_height = 0)
    {
        if($block_height !== 0){
            CatCacheRpcProxy::getRpc()['topBlockHeight'] = $block_height;
        }else{
            //如果设置为空，去数据库中过去最新的区块
            $where = [];//查询条件
            $data = [];//查询字段
            $order_by = [
                'thisTime'  => -1,
            ];//排序
            $top_block_height = $this->getBlockHeadInfo($where, $data, $order_by);
            if(!$top_block_height['IsSuccess']){
                return returnError($top_block_height['Message']);
            }
            CatCacheRpcProxy::getRpc()['topBlockHeight'] = isset($top_block_height['Data']['height']) ?? 1;
        }
    }

    /**
     * 获取区块高度
     * @return string
     */
    public function getTopBlockHeight() : int
    {

        if(CatCacheRpcProxy::getRpc()->offsetGet('topBlockHeight') == 0
            || CatCacheRpcProxy::getRpc()->offsetGet('topBlockHeight') == NULL){
            $this->setTopBlockHeight();
        }
        return CatCacheRpcProxy::getRpc()->offsetGet('topBlockHeight');
    }

    /**
     * 验证区块签名
     * @param array $block
     * @return bool
     */
    protected function checkBlockSign($block = [])
    {
//        if(empty($block['blockSign'])){
//            return returnError('区块签名缺失.');
//        }
//        $check_block_res = $this->BitcoinECDSA->checkSignatureForMessage($block['signature'],
//                                                                            $block['blockSign'],
//                                                                            $block['headHash']);
//        if (!$check_block_res){
//            return returnError('签名验证不通过.');
//        }
        try{
            if(!secp256k1_verify(hex2bin($block['signature']),
                hex2bin($block['headHash']),
                hex2bin($block['blockSign']))){
                var_dump('验签失败');
                return returnSuccess('验签失败');
            }
        }catch (\Exception $e){
            var_dump('验签失败2');
            return returnSuccess('验签失败');
        }
        return returnSuccess();
    }

    /**
     * 同步区块函数
     * @oneWay
     */
    public function syncBlock1(array $block_data = [])
    {
        $p2p_block = [];//存储同步过来的区块数据
        $flag = false;//判断是否要同步数据
        $this->setBlockState(2);
        //从别的节点获取最高的区块高度,同步验证至这一高度
        var_dump($this->SyncBlockTopHeight);
        if($this->SyncBlockTopHeight == 1){
            return returnError('等待获取高度.');
        }
        //循环同步区块,如果同步的区块高度大于同步前获取的区块高度一个片段，则认为同步完成
        while ($this->SyncBlockTopHeight - (($this->Limit - 1) * $this->Pagesize) > 0) {
            var_dump('本地获取');
            if (!empty($block_data)) {
                var_dump('回调获得数据');
                //有数据传入，证明是同步获取到的数据
                $block_hashs = [];//存储区块哈希，用于删除
                $blocks = [];//存储区块
                //进行区块验证
                foreach ($block_data as $ba_key => $ba_val) {
                    $ba_val_temp = json_decode($ba_val, true);
                    $check_block = [];
                    $check_block = $this->checkBlockData($ba_val_temp);
                    if (!$check_block['IsSuccess']) {
                        return returnError('区块数据有误!');
                    }
                    $block_hashs[] = $ba_val_temp['headHash'];
                    $blocks[] = $ba_val_temp;
                }
                $delete_where = ['headHash' => ['$in' => $block_hashs]];
                //删除数据
                $this->deleteBloclHeadMany($delete_where);
                //插入区块数据
                $this->insertBlockHeadMany($blocks);
                $block_data = [];
                ++$this->Limit;
            }
            $where = [
                'height' =>
                    [
                        '$gte' => ($this->Limit - 1) * $this->Pagesize + 1 ,
                        '$lte' => $this->Pagesize * $this->Limit
                    ]
            ];
            $data = ['_id' => 0];
            $block_res = $this->getBloclHeadList($where, $data, 1, $this->Pagesize, ['height' => 1]);
            if (count($block_res['Data']) == $this->Pagesize) {
                var_dump('本地有数据');
                //有数据，对数据进行检测
                foreach ($block_res['Data'] as $br_key => $br_val) {
                    //验证区块数据
                    $check_block = $this->checkBlockData($br_val);
                    //验证通过，赋值新的区块,否则执行请求函数
                    if(!$check_block['IsSuccess']){
                        //用于请求数据
                        $flag = true;
                        break;
                    }
                }
                ++$this->Limit;
            }else{
                $flag = true;
                break;
            }
        }
        var_dump('是否执行回调');
        var_dump($flag);
        if($flag){
            var_dump('执行回调');
            //请求区块数据
            $block_key = '';
            $begin = ($this->Limit - 1) * $this->Pagesize + 1;
            $end = $this->Pagesize * $this->Limit;
            if($begin == 1){
                $begin = 2;
            }
            $block_key = 'Block-' . $begin . '-' . $end;
            var_dump($block_key);
            ProcessManager::getInstance()
                            ->getRpcCall(PeerProcess::class, true)
                            ->p2pGetVal($block_key, []);
            return returnSuccess('等待请求数据回调.');
        }
        //区块同步完毕
        $this->setBlockState(3);
    }

    /**
     * 同步所有数据
     * @param array $block_data
     * @return bool
     */
    public function syncBlock(array $block_data = [])
    {
        $flag = false;//判断是否要同步数据
        $lock_flag = false;
        $this->setBlockState(2);
        //从别的节点获取最高的区块高度,同步验证至这一高度
        if($this->SyncBlockTopHeight == 1){
            return returnError('等待获取高度.');
        }
        //循环同步区块,如果同步的区块高度大于同步前获取的区块高度一个片段，则认为同步完成
//        var_dump('目标高度');
//        var_dump($this->SyncBlockTopHeight);
//        var_dump('当前高度');
//        var_dump($this->CurrentBlockTopHeight);
//        while ($this->SyncBlockTopHeight - (($this->Limit - 1) * $this->Pagesize) > 0) {
        while ($this->SyncBlockTopHeight  > $this->CurrentBlockTopHeight) {
            var_dump('同步区块数据');
            if (!empty($block_data)) {
                var_dump("====================");
                var_dump('回调获得数据');
                var_dump(count($block_data));
                //有数据传入，证明是同步获取到的数据
                $block_hashs = [];//存储区块哈希，用于删除
                $blocks = [];//存储区块
                $block_tradings = [];//存储区块交易数据
                $tradings_hash = [];//存储区块交易哈希
                //进行区块验证
                $block_top_hash = $this->BlockTopHash;
                $system_time = 0;
                foreach ($block_data as $ba_key => $ba_val) {
                    //先验证区块签名
                    $check_block_sign = $this->checkBlockSign($ba_val);
                    if(!$check_block_sign['IsSuccess']){
                        var_dump($check_block_sign['Message']);
                        $flag = true;
                        break 2;
                    }
//                    $ba_val_temp = json_decode($ba_val, true);
                    $trading_info_hashs = [];//存储交易哈希
                    if(empty($ba_val['tradingInfo'])){
                        var_dump($ba_val);
                    }
                    foreach ($ba_val['tradingInfo'] as $bvt_key => $bvt_val){
                        $tx_id = bin2hex( hash('sha256', hash('sha256', hex2bin($bvt_val), true), true));
                        $trading_info_hashs[] = $tx_id;
                        $block_tradings[] = [
                            '_id'       =>  $tx_id,
                            'trading'   =>  $bvt_val,
                        ];
                    }
                    $check_block = [];
                    $ba_val['parentHash'] = $block_top_hash;
                    $check_block = $this->checkBlockData($ba_val, $trading_info_hashs);
                    if (!$check_block['IsSuccess']) {
                        var_dump('区块数据有误');
                        $flag = true;
                        break 2;
//                        return returnError('区块数据有误!');
                    }

                    $system_time = $ba_val['thisTime'];
                    $block_top_hash = $ba_val['headHash'];
                    $ba_val['tradingInfo'] = $trading_info_hashs;
                    $block_hashs[] = $ba_val['headHash'];
                    $blocks[] = $ba_val;
                    $tradings_hash = array_merge($tradings_hash, $trading_info_hashs);
                }
//                $this->BlockTopHash = $block_top_hash;
                //删除action池数据
                ProcessManager::getInstance()->getRpcCall(TradingPoolProcess::class)->deleteTradingPoolMany(['_id' => ['$in' => $tradings_hash]]);
                //删除action
                ProcessManager::getInstance()->getRpcCall(TradingProcess::class)->deleteTradingPoolMany(['_id' => ['$in' => $tradings_hash]]);
                //插入交易数据
                ProcessManager::getInstance()->getRpcCall(TradingProcess::class)->insertTradingMany($block_tradings);
                $delete_where = ['headHash' => ['$in' => $block_hashs]];
                //删除区块数据
                $this->deleteBloclHeadMany($delete_where);
                //插入区块数据
                $this->insertBlockHeadMany($blocks);

//                $this->resetPurse(array_column($block_tradings,'trading'), $tradings_hash);
                //删除投票数据
                ProcessManager::getInstance()
                            ->getRpcCall(VoteProcess::class, true)
                            ->deleteVotePoolMany(['rounds' => ['$lt' => ceil($system_time / 126) + 1]]);
                //刷新本地缓存数据
                ProcessManager::getInstance()
                            ->getRpcCall(ConsensusProcess::class, true)
                            ->bookedPurse(array_column($block_tradings,'trading'), 2);

//                $this->CurrentBlockTopHeight += count($block_data);
                if(count($block_data) == $this->Pagesize){
                    ++$this->Limit;
                    $this->CurrentBlockTopHeight = ($this->Limit - 1) * $this->Pagesize;
                    $this->BlockTopHash = $block_top_hash;
                }elseif (count($block_data) < $this->Pagesize){
                    if($this->Limit == 1 && (count($block_data) == $this->Pagesize - 1)){
                        ++$this->Limit;
                        $this->CurrentBlockTopHeight += $this->Pagesize;
                        $this->BlockTopHash = $block_top_hash;
                    }else {
                        $lock_flag = true;
                        $this->CurrentBlockTopHeight = (($this->Limit - 1) * $this->Pagesize) + count($block_data);
                    }


                    var_dump('结束'.count($block_data));
//                    break;
                }
                $block_data = [];
            }
            $where = [
                'height' =>
                    [
                        '$gte' => (($this->Limit - 1) * $this->Pagesize) + 1 ,
                        '$lte' => $this->Pagesize * $this->Limit
                    ]
            ];
            $data = ['_id' => 0];
            $block_res = $this->getBloclHeadList($where, $data, 1, $this->Pagesize, ['height' => 1]);
            var_dump($where);
//            var_dump(count($block_res['Data']));
            if ($lock_flag || count($block_res['Data']) == $this->Pagesize) {
                var_dump('本地有数据');
                //有数据，对数据进行检测
                $block_tradings = [];//存储区块交易数据
                $tradings_hash = [];//存储区块交易哈希
                $block_top_hash = $this->BlockTopHash;
                $system_time = 0;
                foreach ($block_res['Data'] as $br_key => $br_val) {
                    //对签名进行验证
//                    var_dump($br_val);
                    $check_block_sign = $this->checkBlockSign($br_val);
                    if(!$check_block_sign['IsSuccess']){
//                        var_dump($br_val);
                        var_dump($check_block_sign['Message']);
                        $flag = true;
                        break 2;
                    }
                    //验证区块数据
                    $trading_info_hashs = [];//存储交易哈希
                    $trading_info = [];//存储整交易
                    $trading_info_hashs = $br_val['tradingInfo'];
                    $trading_info = ProcessManager::getInstance()
                                                ->getRpcCall(TradingProcess::class)
                                                ->getTradingList(['_id' => ['$in' => $trading_info_hashs]],
                            [],
                            1,
                            count($trading_info_hashs));
                    $full_trading = [];
                    foreach ($trading_info['Data'] as $ti_key => $tu_val){
                        //获取交易所在的key
                        $tkey = array_search($tu_val['_id'], $trading_info_hashs);
                        $full_trading[intval($tkey)] = $tu_val['trading'];
                    }
                    ksort($full_trading);
                    $br_val['tradingInfo'] = $full_trading;//array_column($trading_info['Data'], 'trading');
                    $br_val['parentHash'] = $block_top_hash;
                    $check_block = $this->checkBlockData($br_val, $trading_info_hashs);
                    //验证通过，赋值新的区块,否则执行请求函数
                    if(!$check_block['IsSuccess']){
                        var_dump('同步区块出错');
                        //用于请求数据
                        $flag = true;
                        break 2;
                    }
                    $block_top_hash = $br_val['headHash'];
                    $system_time = $br_val['thisTime'];
                    $tradings_hash = array_merge($tradings_hash, $trading_info_hashs);
                    $block_tradings = array_merge($block_tradings, $br_val['tradingInfo']);
                }
                $this->BlockTopHash = $block_top_hash;
//                $this->resetPurse($block_tradings, $tradings_hash);
                //删除旧的投票数据
                ProcessManager::getInstance()
                            ->getRpcCall(VoteProcess::class, true)
                            ->deleteVotePoolMany(['rounds' => ['$lt' => ceil($system_time / 126) + 1]]);
                //刷新本地缓存
                ProcessManager::getInstance()
                            ->getRpcCall(ConsensusProcess::class, true)
                            ->bookedPurse($block_tradings, 2);
                var_dump('limit++');
                $this->CurrentBlockTopHeight = $this->Pagesize * $this->Limit;
                ++$this->Limit;
            }else{
                var_dump('over3');
                $flag = true;
                break;
            }

        }
        var_dump('是否执行回调');
        var_dump($flag);
        if($flag){
            var_dump('执行回调');
            //请求区块数据
            $block_key = '';
            $begin = (($this->Limit - 1) * $this->Pagesize) + 1;
            $end = $this->Pagesize * $this->Limit;
            if($begin == 1){
                $begin = 2;
            }
            $block_key = 'Block-' . $begin . '-' . $end;
            var_dump($block_key);
            ProcessManager::getInstance()
                            ->getRpcCall(PeerProcess::class, true)
                            ->p2pGetVal($block_key, []);
            return returnSuccess('等待请求数据回调.');
        }
        //区块同步完毕
        $this->setBlockState(3);
    }


    /**
     * 重置钱包
     * @param array $tradings
     * @param array $trading_txid
     */
    public function resetPurse($tradings = [], $trading_txid = [])
    {
        //重新刷新钱包
        foreach ($tradings as $tr_key => $tr_val){
            $decode_trading = $this->TradingEncodeModel->decodeTrading($tr_val);
            $tx_id = $decode_trading['txId'];
            $lock_time = $decode_trading['lockTime'];
            $lockBlock = $decode_trading['lockBlock'];
            $lockType = $decode_trading['lockType'];
            ProcessManager::getInstance()
                            ->getRpcCall(PurseProcess::class, true)
                            ->deletePurseMany(['txId'   =>  $tx_id]);
            array_map(function ($vin, $vout) use (&$purses, $tx_id, $lock_time, $lockBlock, $lockType){

                //处理交易输入,用于删除钱包数据
                if($vin != null && isset($vin['txId'])){
                    //删除这笔钱包数据,想办法优化，目前没找到类似case when的方法
                    $delete_purse = ['txId' => $vin['txId'], 'n' => $vin['n']];
//                    $this->deletePurse($delete_purse);
                    ProcessManager::getInstance()
                                    ->getRpcCall(PurseProcess::class, true)
                                    ->deletePurse($delete_purse);
                }
                if($vout != null){
                    //新生成的钱包
                    $purses[] = [
                        'address'       => $vout['address'],
                        'txId'          => $tx_id,
                        'n'             => $vout['n'],
                        'value'         => $vout['value'],
                        'reqSigs'       => $vout['reqSigs'],
                        'lockTime'      => $lock_time,
                        'lockBlock'     => $lockBlock,
                        'lockType'      => $lockType,
                    ];
                }
            },$decode_trading['vin'], $decode_trading['vout']);
        }
        //把钱包数据插入数据库
        ProcessManager::getInstance()
                        ->getRpcCall(PurseProcess::class)
                        ->insertPurseMany($purses);
    }

    public function resetNode($action = [])
    {

    }

    public function resetVote($action = [])
    {

    }

    public function resetIncentives($action = [])
    {

    }

    /**
     * 获取区块同步状态false代表未同步完成true代表同步完成
     * @return bool
     */
    public function getBlockState() : int
    {
        return $this->BlockState;
    }

    /**
     * 获取区块同步状态false代表未同步完成true代表同步完成
     * @return bool
     */
    public function getCurrentBlock() : int
    {
        return $this->CurrentBlockTopHeight;
    }

    /**
     * 设置区块同步状态
     * @param bool $state
     */
    public function setBlockState(int $state = 1)
    {
        $this->BlockState = $state;
    }

    /**
     * 设置同步区块的高度
     * @param int $block_top_height
     */
    public function setSyncBlockTopHeight($block_top_height = 0)
    {
        $this->SyncBlockTopHeight = $block_top_height;
    }

    /**
     * 获取同步区块的高度
     * @return int
     */
    public function getSyncBlockTopHeight() : int
    {
        return $this->SyncBlockTopHeight;
    }

    /**
     * 验证创世区块，不存在或错误就创建
     * @return bool
     */
    public function checkGenesisBlock()
    {
        //获取配置文件内容
        $cenesis_trading = get_instance()->config['coinbase'];
        $trading = [];
        $tradings = [];
        $nodes = [];
        $tx_ids = [];
        $tx_id = '';
        $trading_info = '';
        $count = count($cenesis_trading);
        foreach($cenesis_trading as $ct_key => $ct_val){
            $tx_id = bin2hex( hash('sha256', hash('sha256', hex2bin($ct_val), true), true));
            $tx_ids[] = $tx_id;
            $trading[] = [
                '_id'   =>  $tx_id,
                'trading'   => $ct_val
            ];
            $tradings[] = $ct_val;
        }
        //生成创世区块
        //生成默克尔树
        $merker_tree = $this->MerkleTree->setNodeData($tx_ids)
                                        ->bulidMerkleTreeSimple();
        //获取默克尔根
        $morker_tree_root = array_pop($merker_tree);
        //获取最新的区块哈希
        $top_block_hash = '0000000000000000000000000000000000000000000000000000000000000000';
        //获取最新的区块高度
        $top_block_height = 0;
        //构建区块头部
        $black_head = $this->BlockHead->setMerkleRoot($morker_tree_root)
                                    ->setParentHash($top_block_hash)//上一个区块的哈希
                                    ->setThisTime(1)//区块生成时间
                                    ->setSignature('025fee0dc100cc5adcac3ad455ab28b8aa6c89e080a946b3ba085f1750ede9b503')//工作者签名
                                    ->setHeight($top_block_height + 1)
                                    ->setTxNum(count($tx_ids))
                                    ->setTradingInfo($tradings)
                                    ->setVersion(1)
                                    ->packBlockHead();
        //查询区块是否存在
        $check_block = $this->getBlockHeadInfo(['height' => 1]);
        if(!empty($check_block['Data'])) {
            //创世区块存在的话，判断交易是否正确
            if ($black_head['headHash'] == $check_block['Data']['headHash']) {
                //如果交易内容一致，返回true
                return returnSuccess();
            }
            //不一致删除区块
            $this->deleteBloclHead(['height' => 1]);
        }
//        var_dump($black_head);
        //设置区块签名
        $black_head['blockSign'] = '3045022100af1961f04e75efb1e86334a031dcd41bfc94e346330059a08ce6a11fef3318ba02204fd0f4ee2d4f805eed6ae62c5fc90ddb943fcd89f46f356f4487e8796cfeda3a';//'H+hp459Hd946jjnS6eyJjFEhUMkKyzOFCu5KcVvE+tnFHHYp2b+LUcDncwIkIt2WhJfRQpjk/vZ/4dysPEKV/yc=';
        //删除action
        ProcessManager::getInstance()
                        ->getRpcCall(TradingProcess::class, true)
                        ->deleteTradingPool(['_id' => ['$in' => $tx_ids]]);
        //删除钱包数据
        ProcessManager::getInstance()
                    ->getRpcCall(PurseProcess::class, true)
                    ->deletePurseMany(['txId' => ['$in' => $tx_ids]]);
        //删除质押节点
        ProcessManager::getInstance()
                    ->getRpcCall(NodeProcess::class)
                    ->deleteNodePoolMany();
        //开启区块同步
        $this->setBlockState(1);
        //开启交易同步
        ProcessManager::getInstance()
                    ->getRpcCall(TradingProcess::class, true)
                    ->setTradingState(1);
        //开启钱包同步
        ProcessManager::getInstance()
                    ->getRpcCall(PurseProcess::class, true)
                    ->setPurseState(1);

        //插入区块数据
        $black_head['tradingInfo'] = $tx_ids;
        $this->insertBlockHead($black_head);
        //设置区块高度
        $this->setTopBlockHeight(1);
        //设置最新区块的hash
        $this->setTopBlockHash($black_head['headHash']);
        //报文
        $context = [
            "start_time" => date('Y-m-d H:i:s'),
            'request_id'    => time() . crc32('null_controller' . 'null_method' . getTickTime() . rand(1, 10000000)),
            'controller_name'   => 'null_controller',
            'method_name'   => 'null_method',
            'ip'        =>  get_instance()->config['node']["ip"],
        ];
        //插入超级节点数据内容

//        $this->NodeModel->initialization($context);
//        //循环插入质押(改成直接提交submit)
//        foreach ($nodes as $n_key => $n_val){
//            $this->NodeModel->checkNodeRequest($n_val, 2);
//        }
//        ProcessManager::getInstance()
//                    ->getRpcCall(NodeProcess::class, true)
//                    ->insertNodeMany($nodes);

        //插入action数据(最后一笔非质押数据)
        ProcessManager::getInstance()
                    ->getRpcCall(TradingProcess::class, true)
                    ->insertTradingMany($trading);

        //插入钱包数据
        ProcessManager::getInstance()
                    ->getRpcCall(ConsensusProcess::class, true)
                    ->bookedPurse($tradings);


        //创建各个集合索引
        $this->createdIndexs();
        var_dump('初始化结束');
        return returnSuccess();

    }

    /**
     * 新节点启动时创建索引
     */
    public function createdIndexs()
    {
        //创建Block索引
        $this->createdBlockIndex();

        ProcessManager::getInstance()
            ->getRpcCall(TradingPoolProcess::class, true)
            ->createdTradingPoolIndex();

        ProcessManager::getInstance()
            ->getRpcCall(TradingProcess::class, true)
            ->createdTradingIndex();

        ProcessManager::getInstance()
            ->getRpcCall(NodeProcess::class, true)
            ->createdNodeIndex();

        ProcessManager::getInstance()
            ->getRpcCall(PurseProcess::class, true)
            ->createdPurseIndex();

        ProcessManager::getInstance()
            ->getRpcCall(VoteProcess::class, true)
            ->createdVoteIndex();
        return returnSuccess();
    }

    /**
     * 设置Block集合索引
     */
    public function createdBlockIndex()
    {
        $this->Block->createIndexes(
            [
                ['key' => ['headHash' => 1]],
                ['key' => ['merkleRoot' => 1]],
                ['key' => ['height' => 1]],
            ]
        );
        return returnSuccess();
    }

    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "区块进程关闭.";
    }
}
