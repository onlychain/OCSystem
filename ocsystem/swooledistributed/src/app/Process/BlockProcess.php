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
use app\Models\Block\MerkleTreeModel;
use Server\Components\Process\Process;
use Server\Components\CatCache\CatCacheRpcProxy;
use MongoDB;

use Server\Components\Process\ProcessManager;
use app\Process\TradingPoolProcess;

class BlockProcess extends Process
{
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
     * @param array $trading
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
     * @param array $trading
     * @return bool
     */
    public function insertBloclHeadMany($trading = [], $get_ids = false)
    {
        if(empty($trading)) return returnError('交易内容不能为空.');
        $insert_res = $this->Block->insertMany($trading);
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
        $merker_tree = $this->MerkleTree->setNodeData($trading_res['Data'])
                                        ->setNodeNum(count($trading_res['Data']))
                                        ->bulidMerkleTree();
        //获取默克尔根
        $morker_tree_root = array_pop($merker_tree);
        //构建区块头部
        $check_head = $this->BlockHead->setMerkleRoot($morker_tree_root)
                                        ->setParentHash('123456')//上一个区块的哈希
                                        ->setThisTime($block_head['blockTime'])
                                        ->setHeight(10)//区块高度先暂存，后期不上
                                        ->setTxNum($trading_num)
                                        ->setTradingInfo($trading_res['Data'])
                                        ->packBlockHead();
        if($check_head != $block_head){
            return returnError('区块数据有误!');
        }
        //处理已经完成的交易
        $del_trading_res = $this->checkTreading($trading_res['Data']);
        if(!$del_trading_res['IsSuccess']){
            return returnError($del_trading_res['Message']);
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
        var_dump(10.1);
        //将交易数据存入交易集合
        $trading_res =  ProcessManager::getInstance()
                                    ->getRpcCall(TradingProcess::class)
                                    ->insertTradingMany($tradings);
        var_dump(10.2);
        if(!$trading_res['IsSuccess']){
            return returnError($trading_res['Message']);
        }
        //删除交易池内的交易数据
        $trading_pool_where = ['_id' => ['$in' => $trading_hashs]];
        var_dump(10.3);
        $trading_pool_res = ProcessManager::getInstance()
                                ->getRpcCall(TradingPoolProcess::class)
                                ->deleteTradingPoolMany($trading_pool_where);
        var_dump(10.4);
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
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "区块头进程关闭.";
    }
}
