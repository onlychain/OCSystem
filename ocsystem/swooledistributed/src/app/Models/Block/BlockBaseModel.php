<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部相关操作
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Block;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\BlockProcess;
use app\Process\TradingProcess;
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
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        $this->BlockHead = $this->loader->model('Block/BlockHeadModel', $this);
        $this->MerkleTree = $this->loader->model('Block/MerkleTreeModel', $this);
    }

    /**
     * 查询区块信息
     * @param string $head_hash
     * @return bool
     */
    public function queryBlock($head_hash = '')
    {
        if($head_hash == ''){
            return returnError('请传入区块哈希.');
        }
        $block_res = [];
        $where = ['headHash' => $head_hash];
        $data = ['_id' => 0];
        $block_res = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getBlockHeadInfo($where, $data);
        return $block_res;
    }

    /**
     * 验证区块函数
     * @param array $block_head
     * @return bool
     */
    public function checkBlockRequest(array $block_head = [], $trading_type = 1)
    {

        //验证上一个区块的哈希是否存在
        $block_where = ['headHash' => $block_head['parentHash']];
        $block_data = ['headHash' => 1];
        $block_res = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getBlockHeadInfo($block_where, $block_data);
        if(empty($block_res['Data'])){
            //判断数据库是否有区块数据，
            $check_res = $this->checkBlockSync($block_head);
            if(!$check_res['IsSuccess']){
                return;
            }
            return returnError('parent区块不存在');
        }
        //验证交易是否都存在
        $trading_where = ['_id' => ['$in' => $block_head['tradingInfo']]];
        $trading_data = ['trading' => 0, 'time' => 0];
        $trading_res = [];
        if($trading_type == 1){
            //查询交易内容
            $trading_res = ProcessManager::getInstance()
                                        ->getRpcCall(TradingProcess::class)
                                        ->getTradingList($trading_where, $trading_data, 1, count($block_head['tradingInfo']));
        }else{
            //查询交易池内容
            $trading_res = ProcessManager::getInstance()
                                        ->getRpcCall(TradingPoolProcess::class)
                                        ->getTradingPoolList($trading_where, $trading_data, 1, count($block_head['tradingInfo']));
        }
        var_dump(count($trading_res['Data']));
        var_dump(count($block_head['tradingInfo']));
        if(empty($trading_res['Data']) ||  count($trading_res['Data']) != count($block_head['tradingInfo'])){
            return returnError('区块验证失败!');
        }
        $merker_tree = $this->MerkleTree->setNodeData($block_head['tradingInfo'])
                                        ->bulidMerkleTreeSimple();
        //获取默克尔根
        $morker_tree_root = array_pop($merker_tree);
        //构建区块头部
        $check_head = $this->BlockHead->setMerkleRoot($morker_tree_root)
                                        ->setParentHash($block_head['parentHash'])//上一个区块的哈希
                                        ->setThisTime($block_head['thisTime'])
                                        ->setHeight($block_head['height'])//区块高度先暂存，后期不上
                                        ->setTxNum(count($block_head['tradingInfo']))
                                        ->setTradingInfo($block_head['tradingInfo'])
                                        ->setSignature($block_head['signature'])
                                        ->setVersion($block_head['version'])
                                        ->packBlockHead();
        if($check_head['headHash'] !== $block_head['headHash']){
            return returnError('区块验证不通过!');
        }
        return returnSuccess($check_head);
    }

    /**
     * 处理未同步区块时接收到区块广播
     * @param array $block
     * @return bool
     */
    public function checkBlockSync(array $block = [])
    {
        $block_res = ProcessManager::getInstance()
                                ->getRpcCall(BlockProcess::class)
                                ->getBlockHeadInfo([], []);
        if(empty($block_res['Data'])){
            //数据库中没有数据
            $block_state = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getBlockState();
            if($block_state == 3){
                ProcessManager::getInstance()
                            ->getRpcCall(BlockProcess::class, true)
                            ->setBlockState(1);
            }
            //把当前块的高度存入进程
            ProcessManager::getInstance()
                        ->getRpcCall(BlockProcess::class, true)
                        ->setSyncBlockTopHeight($block['height']);
            //把当前区块存入数据库
            var_dump(11);
            ProcessManager::getInstance()
                            ->getRpcCall(BlockProcess::class, true)
                            ->insertBloclHead($block);
            return returnError('区块未同步');
        }
        return returnSuccess();
    }


}
