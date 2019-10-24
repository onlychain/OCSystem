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
use app\Models\Trading\TradingEncodeModel;
use Server\Components\Process\Process;
use Server\Components\CatCache\CatCacheRpcProxy;
use MongoDB;

use Server\Components\Process\ProcessManager;
use app\Process\TradingPoolProcess;
use app\Process\PeerProcess;
use app\Process\TradingProcess;
use app\Process\NodeProcess;
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
    private $TradingEncodeModel;

    /**
     *
     * @var
     */
    private $NodeModel;

    /**
     * 同步区块的高度
     * @var int
     */
    private $SyncBlockTopHeight = 548;

    /**
     * 当前区块哈希
     * @var string
     */
    private $BlockTopHash = 'fff79950887009985ab18c906abba11e22237b5582fee34e04756336bea0b6de';
    //'0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * 每次获取区块的数量
     * @var int
     */
    private $Pagesize = 40;

    /**
     * 获取区块的游码
     * @var int
     */
    private $Limit = 12;

    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('BlockProcess');
        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->Block = $this->MongoDB->selectCollection('blocks', 'block');
        //区块头部相关方法
        $this->BlockHead = new BlockHeadModel();
        //区块头部相关方法
        $this->MerkleTree = new MerkleTreeModel();
        //交易序列化相关方法
        $this->TradingEncodeModel = new TradingEncodeModel();
        //节点相关方法
        $this->NodeModel = new NodeModel();
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
    public function insertBloclHead($block_head = [])
    {
        if(empty($block_head)) return returnError('交易内容不能为空.');
        $insert_res = $this->Block->insertOne($block_head);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess(['id' => $insert_res->getInsertedId()->__toString()]);
    }

    /**
     * 插入多条数据
     * @param array $block
     * @return bool
     */
    public function insertBloclHeadMany($block = [], $get_ids = false)
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
    public function checkBlockData(array $block = [])
    {
        if(empty($block)){
            return returnError('区块不能为空.');
        }
        $merker_tree = $this->MerkleTree->setNodeData($block['tradingInfo'])
                                        ->bulidMerkleTreeSimple();
        //获取默克尔根
        $morker_tree_root = array_pop($merker_tree);
        //构建区块头部
        $check_head = $this->BlockHead->setMerkleRoot($morker_tree_root)
                                    ->setParentHash($block['parentHash'])//上一个区块的哈希
                                    ->setThisTime($block['thisTime'])
                                    ->setHeight($block['height'])//区块高度先暂存，后期不上
                                    ->setTxNum(count($block['tradingInfo']))
                                    ->setTradingInfo($block['tradingInfo'])
                                    ->setSignature($block['signature'])
                                    ->setVersion($block['version'])
                                    ->packBlockHead();
//        var_dump('=====================');
//        var_dump($block);
//        var_dump($check_head);
//        var_dump('确认区块');
//        var_dump($check_head['headHash']);
//        var_dump($block['headHash']);
        if($check_head['headHash'] !== $block['headHash']){
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
        //将交易数据存入交易集合
        $trading_res =  ProcessManager::getInstance()
                                    ->getRpcCall(TradingProcess::class)
                                    ->insertTradingMany($tradings);
        if(!$trading_res['IsSuccess']){
            return returnError($trading_res['Message']);
        }
        //删除交易池内的交易数据
        $trading_pool_where = ['_id' => ['$in' => $trading_hashs]];
        $trading_pool_res = ProcessManager::getInstance()
                                ->getRpcCall(TradingPoolProcess::class)
                                ->deleteTradingPoolMany($trading_pool_where);
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
     * 同步区块函数
     * @oneWay
     */
    public function syncBlock(array $block_data = [])
    {
        $p2p_block = [];//存储同步过来的区块数据
        $flag = false;//判断是否要同步数据
        $this->setBlockState(2);
        //从别的节点获取最高的区块高度,同步验证至这一高度
        if($this->SyncBlockTopHeight == 0){
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
                //删除数据
                $this->deleteBloclHeadMany($block_hashs);
                //插入区块数据
                $this->insertBloclHeadMany($blocks);
                $block_data = [];
                ++$this->Limit;
            }
            $where = [
                'height' =>
                    [
                        '$gt' => ($this->Limit - 1) * $this->Pagesize + 1 ,
                        '$lt' => $this->Pagesize * $this->Limit
                    ]
            ];
            $data = ['_id' => 0];
            $block_res = $this->getBloclHeadList($where, $data, 1, $this->Pagesize, ['height' => 1]);
            if (!empty($block_res['Data'])) {
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
            $block_key = 'Block-' . (($this->Limit - 1) * $this->Pagesize + 1) . '-' . ($this->Pagesize * $this->Limit);
            ProcessManager::getInstance()
                            ->getRpcCall(PeerProcess::class, true)
                            ->p2pgetVal($block_key, []);
            return returnSuccess('等待请求数据回调.');
        }
        //区块同步完毕
        $this->setBlockState(3);
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
    public function getSyncBlockTopHeight()
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
            if($ct_key + 1 < $count){
                $trading_info = $this->TradingEncodeModel->setVin($ct_val['tx'])
                                                        ->setVout($ct_val['to'])
                                                        ->setTime($ct_val['time'])
                                                        ->setLockTime(1 + 15768000)
                                                        ->setLockType($ct_val['lockType'])
                                                        ->setIns('')
                                                        ->encodeTrading();
                $nodes[] = [
                    'pledge' => [
                        'trading'   =>  $trading_info,
                        'noce'      =>  'ffffff',
                        'renoce'    =>  '',
                    ],
                    'address'       => $ct_val['to'][0]['address'],
                    'ip'            => $ct_val['ip'],
                    'port'          => $ct_val['port']
                ];
            }else{
                $trading_info = $this->TradingEncodeModel->setVin($ct_val['tx'])
                                                        ->setVout($ct_val['to'])
                                                        ->setTime($ct_val['time'])
                                                        ->setLockTime(0)
                                                        ->setLockType($ct_val['lockType'])
                                                        ->setIns('')
                                                        ->encodeTrading();
            }
            $tx_id = bin2hex(hash('sha256', hash('sha256', hex2bin($trading_info), true), true));
            $tx_ids[] = $tx_id;
            $trading[] = [
                '_id'   =>  $tx_id,
                'trading'   => $trading_info
            ];
            $tradings[] = $trading_info;
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
                                    ->setThisTime(1571316539)//区块生成时间
                                    ->setSignature('arnoldsaxon')//工作者签名
                                    ->setHeight($top_block_height + 1)
                                    ->setTxNum(count($tx_ids))
                                    ->setTradingInfo($tx_ids)
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
        //删除交易
        ProcessManager::getInstance()
                        ->getRpcCall(TradingProcess::class, true)
                        ->deleteTradingPool(['_id' => ['$in' => $black_head['tradingInfo']]]);
        //删除钱包数据
        ProcessManager::getInstance()
                    ->getRpcCall(PurseProcess::class, true)
                    ->deletePurseMany(['txId' => ['$in' => $black_head['tradingInfo']]]);
        //删除质押节点
        ProcessManager::getInstance()
                    ->getRpcCall(NodeProcess::class, true)
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
        $this->insertBloclHead($black_head);
        //报文
        $context = [
            "start_time" => date('Y-m-d H:i:s'),
            'request_id'    => time() . crc32('null_controller' . 'null_method' . getTickTime() . rand(1, 10000000)),
            'controller_name'   => 'null_controller',
            'method_name'   => 'null_method',
            'ip'        =>  get_instance()->config['node']["ip"],
        ];
        $this->NodeModel->initialization($context);
        //循环插入质押
        foreach ($nodes as $n_key => $n_val){
            $a = $this->NodeModel->checkNodeRequest($n_val, 2);
        }

        //插入交易数据(最后一笔非质押数据)
        ProcessManager::getInstance()
                    ->getRpcCall(TradingProcess::class, true)
                    ->insertTradingMany($trading);

        //插入钱包数据
        ProcessManager::getInstance()
                    ->getRpcCall(ConsensusProcess::class, true)
                    ->bookedPurse($tradings);

        //设置区块高度
        $this->setTopBlockHeight(1);
        //设置最新区块的hash
        $this->setTopBlockHash($black_head['headHash']);
        var_dump('初始化结束');
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
