<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;


use app\Models\Block\BlockBaseModel;
use app\Models\Trading\TradingModel;
use app\Models\Node\NodeModel;
use app\Models\Node\VoteModel;
use app\Models\Block\BlockHeadModel;
use app\Models\Block\MerkleTreeModel;
use Server\Components\Process\Process;
use Server\Components\CatCache\CatCacheRpcProxy;
use MongoDB;

use Server\Components\Process\ProcessManager;
use app\Process\TradingProcess;
use app\Process\BlockProcess;
use app\Process\PurseProcess;

class PeerProcess extends Process
{
    /**
     * K桶状态
     * @var int
     * 1:未初始化，2:初始化中,3:初始化结束
     */
    private $KADState = 1;

    /**
     * 交易模型
     * @var
     */
    private $TradingModel;

    /**
     * 区块模型
     * @var
     */
    private $BlockModel;

    /**
     * 投票模型
     * @var
     */
    private $VoteModel;

    /**
     * 节点(质押)模型
     * @var
     */
    private $NodeModel;

    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('PeerProcess');
        //实例化交易模型
        $this->TradingModel = new TradingModel();
        //实例化区块模型
        $this->BlockModel = new BlockBaseModel();
        //实例化节点模型
        $this->NodeModel = new NodeModel();
        //实例化投票模型
        $this->VoteModel = new VoteModel();
        $this->init();

        p2p_initialized([$this, 'KADInitialize']);
        p2p_set_broadcast_handler([$this, 'getBroadcast']);
        p2p_set_store_handler([$this, 'getStore']);
        p2p_set_find_hash_handler([$this, 'setFindHash']);
        p2p_set_get_value_handler([$this, 'setGetValue']);
        $this->loading();
    }

    /**
     * 监听回调
     */
    public function loading()
    {
        swoole_timer_tick(10, function (){
            while (p2p_run_one()) {
//				var_dump('空闲等待监听.');
			}
        });
    }

    /**
     * 初始化网络节点
     */
    protected function init()
    {
        //获取节点名称，也就是地址
        $node_name = get_instance()->config['address'];
        if ($node_name == '' || ! is_string($node_name)){
            throw new \InvalidArgumentException('nodes name is null');
        }
        //获取种子列表
        $seed_nodes = get_instance()->config['seedsNodes'];
        if (empty($seed_nodes)){
            //如果种子节点列表为空，代表本节点就是种子节点
            $seed_nodes = [];
        }
        //初始化P2P网络节点
        p2p_init($node_name, $seed_nodes, 8997);
        return true;
    }

    /**
     * 获取推送的数据
     * @param $sender
     * @param $key
     * @param $val_sha1
     * @param $val_len
     * @return bool|\Closure
     */
    public function getStore($sender, $key, $val_sha1, $val_len)
    {
        // 检查$sender是否有发布存储命令的权限
        if (!check_sender($sender)) return false;
        // 检查本地是否已经存储key-value
        if (database_contains($key, $val_sha1)) return false;
        return function($val) use($key, $val_sha1) {
            // 验证value内容合法性
            if (!check_value($val)) return;
            // 把$key、$val_sha1、$val存到数据库
            database_write_key_value($key, $val_sha1, $val);
        };
    }

    /**
     * 获取指定的数据
     * @param string $key
     * @param string $data
     * @return mixed
     */
    public function p2pGetVal($key = '', $data = [])
    {
        var_dump('发送');
//        var_dump($this->getNodes());
		p2p_get($key, $data, function ($values) use($key) {
		    var_dump('接收回调数据');
            $kes = explode('-', $key);
            //根据key执行回调方法
            switch ($kes[0]){
                case 'Block' :
                    var_dump('回调区块同步方法');
                    //执行区块同步函数
//                var_dump($values);
                    ProcessManager::getInstance()
                                ->getRpcCall(BlockProcess::class, true)
                                ->syncBlock($values);
                    break;
                case 'Trading' :
                    //执行交易同步函数
                    ProcessManager::getInstance()
                                ->getRpcCall(TradingProcess::class, true)
                                ->syncTrading($values);
                    break;
                default : return;
            }
		});
    }

    /**
     * 获取连接的节点列表
     * @return mixed
     */
    public function getNodes()
    {
		return p2p_nodes();
    }

    /**
     * 获取本节点ID,也就是节点名称
     * @return string
     */
    public function getNodeID() : string
    {
        return p2p_id();
    }


    /**
     * 获取本节点监听的UDP端口
     * @return int
     */
    public function getNodePore() : int
    {
        return p2p_port();
    }

    /**
     * 监听K桶初始化结果
     */
    public function KADInitialize()
    {
        echo('K桶初始化完成');
        $this->KADState = 3;
    }

    /**
     * 监听返回获取数据时需要的valsha1
     */
    public function setFindHash($sender, $key, $args)
    {
        var_dump('接收hash' . $key);
        sort($args);
        if($sender == $this->getNodeID()){
            return false;
        }
        // 通过$key和$args决定返回哪些value的sha1值(从数据库中检索)
        //先根据-拆分Key,判断要获取的数据类型以及字段
        $kes = explode('-', $key);
        $result_to_val = [];
        switch ($kes[0]){
            case 'Block' :
                //获取区块数据，key示例Block-1-1000
                $where = ['height' => ['$gte' => intval($kes[1]), '$lte' => intval($kes[2])]];
                $data = ['headHash' => 1, '_id' => 0];
                $result = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getBloclHeadList($where, $data, 1, intval($kes[2]) - intval($kes[1]) + 1);
                if(!empty($result['Data'])){
                    foreach ($result['Data'] as $r_key => $r_val){
                        $result_to_val[] = $r_val['headHash'];
                    }
                }
                break;
            case 'Trading' :
                //获取交易数据，key示例Trading-100
                $where = ['_id' => ['$in' => $args]];
                $data = ['_id' => 1];
                $result = ProcessManager::getInstance()
                                        ->getRpcCall(TradingProcess::class)
                                        ->getTradingList($where, $data, 1, count($args));
                if(!empty($result['Data'])){
                    foreach ($result['Data'] as $r_key => $r_val){
                        $result_to_val[] = $r_val['_id'];
                    }
                }
                break;
            case 'Purse' :
                //获取钱包数据，key示例Purse-address-100
                $where = ['address' => $kes[1], 'txId' => ['$in' => $args]];
                $data = ['txId' => 1];
                $result = ProcessManager::getInstance()
                                        ->getRpcCall(PurseProcess::class)
                                        ->getPurseList($where, $data, 1, intval($kes[2]));
                break;
//            case 'BlockTopHeight' :
//                //没有sha1作为key，直接返回任意的hash值，val函数根据key来获取数据
//                $result['Data'] = [hash('sha1', time())];
//                break;
            //获取最高的区块

            default : $result['Data'] = [];
        }
        if(empty($result['Data']))
            return false;

        var_dump('发送hash');
        return $result_to_val;
    }

    /**
     * 监听返回请求的值
     */
    public function setGetValue(string $sender, string $key, string $val_sha1)
    {
        var_dump('接收val' . $key);
//        var_dump($val_sha1);
        if($sender == $this->getNodeID()){
            return false;
        }
        $kes = explode('-', $key);
        switch ($kes[0]){
            case 'Block' :
                //获取区块数据，key示例Block-1-1000
                $where = ['headHash' => $val_sha1];
                $data = ['_id' => 0];
                $full_trading = [];
                $block = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getBlockHeadInfo($where, $data)['Data'];
                $trading = ProcessManager::getInstance()
                                        ->getRpcCall(TradingProcess::class)
                                        ->getTradingList(['_id' => ['$in' => $block['tradingInfo']]], [], 1, count($block['tradingInfo']))['Data'];
                foreach ($trading as $t_key => $t_val){
                    $tkey = array_search($t_val['_id'], $block['tradingInfo']);
                    $full_trading[intval($tkey)] = $t_val['trading'];
                }
                ksort($full_trading);
                $block['tradingInfo'] = $full_trading;
//                $block['tradingInfo'] = array_column($trading, 'trading');
                $result = json_encode($block);
                break;
            case 'Trading' :
                //获取交易数据，key示例Trading-100
                $where = ['_id' => $val_sha1];
                $data = ['_id' => 0];
                $result = ProcessManager::getInstance()
                                        ->getRpcCall(TradingProcess::class)
                                        ->getTradingInfo($where, $data)['Data']['trading'];
                break;
            case 'Purse' :
                //获取钱包数据，key示例Purse-address-100
                $where = ['address' => $kes[1], 'txId' => $val_sha1];
                $data = ['_id' => 0];
                $result = json_encode(ProcessManager::getInstance()
                                        ->getRpcCall(PurseProcess::class)
                                        ->getPurseInfo($where, $data)['Data']);
                break;
//            case 'BlockTopHeight' :
//                //获取最高的区块
//                $result['Data'] = (string)ProcessManager::getInstance()
//                                                ->getRpcCall(BlockProcess::class)
//                                                ->getTopBlockHeight();
                break;
            default : $result['Data'] = [];
        }
        if ($result == '' || $result == null)
            return false;


        var_dump('发送val');
        return $result;
    }


    /**
     * 监听广播
     */
    public function getBroadcast($sender, $TTL, $content)
    {
//        var_dump($content);
        $context = getNullContext();
//        var_dump($content);
        //反序列化数据
        $res = [];//验证返回结果
        $decode_content = json_decode($content, true);
        var_dump('收到广播数据广播类型：'.$decode_content['broadcastType']);
//        return;
        //根据具体的广播数据进行处理，不合法就不再进行广播
        switch ($decode_content['broadcastType']){
            case 'Block' :
                $this->BlockModel->initialization($context);
                $res = $this->BlockModel->checkBlockRequest($decode_content['Data'], 2, 2);
                break;
            case 'Trading' :
                $this->TradingModel->initialization($context);
                $res = $this->TradingModel->checkTradingRequest($decode_content['Data'], 2, 2);
                break;
            case 'Vote' :
                $this->VoteModel->initialization($context);
                $res = $this->VoteModel->checkVoteRequest($decode_content['Data'], 2);
                break;
            case 'Pledge' :
                $this->NodeModel->initialization($context);
                $res = $this->NodeModel->checkNodeRequest($decode_content['Data'], 1, 2);
                break;
            case 'Node' :
                $this->NodeModel->initialization($context);
                $res = $this->NodeModel->syncNode($decode_content['Data']);
                break;
            case 'SuperNode' :
                $this->NodeModel->initialization($context);
                $res = $this->NodeModel->syncSuperNode($decode_content['Data']);
                break;

            default :
                return false;
                break;
        }
//        if($res['IsSuccess']){
//            go(function() use ($content){
//                var_dump('发送广播');
//                p2p_broadcast($content);
//            });
//
//        }
        //一切都ok之后，广播数据

    }

    /**
     * 发送广播数据
     * @oneWay
     */
    public function broadcast(string $content = '')
    {
        var_dump('发送广播');
//        var_dump($this->getNodes());
        p2p_broadcast($content);
    }

    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "P2P网络进程关闭.";
        //关闭网络
        p2p_close();
    }

}
