<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块相关操作
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Block;


use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;



use app\Process\ConsensusProcess;
use Server\Components\Process\Process;
use Server\Components\Process\ProcessManager;

class BlockHeadModel extends Model
{
    /**
     * 区块头部哈希
     * @var
     */
    protected $headHash;
    /**
     * 上一个区块哈希
     * @var
     */
    protected $parentHash;
    /**
     * 默克尔树根节点
     * @var
     */
    protected $merkleRoot;
    /**
     * 时间戳
     * @var
     */
    protected $thisTime;
    /**
     * 区块高度
     * @var
     */
    protected $height;
    /**
     * 交易数
     * @var
     */
    protected $txNum;
    /**
     * 签名
     * @var
     */
    protected $signature;
    /**
     * 版本
     * @var string
     */
    protected $version = 1;
    /**
     * 交易内容（UTXO）
     * @var array
     */
    protected $tradingInfo = [];

    /**
     * 构造函数，整个项目运行期间只会执行一次
     * BlockHeadModel constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
    }

    /**
     * 设置上一区块哈希值
     * @return type
     */
    public function setParentHash(string $parent_hash)
    {
        $this->parentHash = $parent_hash;
        return $this;
    }

    /**
     * 设置默克尔根的值
     * @param type $merkle_root
     * @return $this
     */
    public function setMerkleRoot(string $merkle_root)
    {
        $this->merkleRoot = $merkle_root;
        return $this;
    }

    /**
     * 设置版本号
     * @param type $version
     * @return $this
     */
    public function setVersion(int $version = 1)
    {
        $this->version = $version;
        return $this;
    }

    /**
     * 获取版本号
     * @param type $version
     * @return $this
     */
    public function getVersion() : int
    {
        return $this->version;
    }
    /**
     * 设置当前时间戳
     * @param type $this_time
     * @return $this
     */
    public function setThisTime(int $this_time)
    {
        $this->thisTime = $this_time;
        return $this;
    }

    /**
     * 设置区块高度
     * @param type $height
     * @return $this
     */
    public function setHeight(int $height)
    {
        $this->height = $height;
        return $this;
    }

    /**
     * 设置交易数量
     * @param type $trading_num
     * @return $this
     */
    public function setTxNum(int $trading_num)
    {
        $this->txNum = $trading_num;
        return $this;
    }


    /**
     * 存储交易哈希值（UTXO）
     * @param $trading_hash
     * @return $this
     */
    public function setTradingInfo(array $trading_hash)
    {
        $this->tradingInfo = $trading_hash;
        return $this;
    }

    /**
     * 获取交易Hash
     * @return array
     */
    public function getTradingInfo() : array
    {
        return $this->tradingInfo;
    }

    /** 设置交易签发人员
     * @param string $signature
     * @return $this
     */
    public function setSignature(string $signature = '')
    {
        if($signature == ''){
            $this->signature = getServerName();
        }else{
            $this->signature = $signature;
        }
        return $this;
    }

    /**
     * 获取区块签名
     * @return string
     */
    public function getSignature() : string
    {
        return $this->signature;
    }

    /**
     * 构建区块哈希头
     * @return array
     */
    public function packBlockHead($type = 1)
    {
        $head_hash = array();//定义哈希头部
        $head_hash = array(
            "parentHash"    =>  $this->parentHash,//上一个区块Hash
            "merkleRoot"    =>  $this->merkleRoot,//交易数据默克尔跟根
            "version"       =>  $this->version,//在配置或在代码中写死
            "thisTime"      =>  $this->thisTime,//生成时间戳
            "height"        =>  $this->height,//区块高度
            "tradingNum"    =>  $this->txNum,//交易笔数
            "tradingInfo"   =>  $this->tradingInfo,//获取创世区块内的所有交易ID
            "signature"     =>  $this->signature,//签名获取服务器配置
        );
        $json_hash = json_encode($head_hash);
        $head_hash["headHash"] = hash("sha3-256", $json_hash);
        if($type != 1){
            var_dump($head_hash["headHash"]);
            $head_hash["blockSign"] = ProcessManager::getInstance()
                                ->getRpcCall(ConsensusProcess::class)
                                ->encodeNodeData($head_hash["headHash"]);
        }
        //返回，后续操作在调用函数中进行
        $this->clearBlockHead();
        return $head_hash;
    }

    /**
     * 清理构建区块缓存
     */
    public function clearBlockHead()
    {
        $this->headHash = '';
        $this->parentHash = '';
        $this->merkleRoot = '';
        $this->thisTime = 0;
        $this->height = 0;
        $this->txNum = 0;
        $this->signature = '';
        $this->version = 1;
        $this->tradingInfo = [];
    }
}
