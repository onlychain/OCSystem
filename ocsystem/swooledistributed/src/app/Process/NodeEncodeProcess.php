<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;



use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;


use Server\Components\Process\Process;
use Server\Components\CatCache\CatCacheRpcProxy;
use Server\Components\Process\ProcessManager;

use app\Process\PeerProcess;
use app\Models\Action\ActionEncodeModel;


class NodeEncodeProcess extends Process
{
    /**
     * 节点私钥
     * @var string
     */
    private $PrivateKey = '';

    /**
     * 加签规则
     * @var
     */
    private $BitcoinECDSA;

    /**
     * 私钥签名状态
     * @var int
     */
    private $PrivateKeyState = 1;

    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('NodeEncodeProcess');
        //实例化椭圆曲线加密算法
        $this->BitcoinECDSA = new BitcoinECDSA();
    }

    /**
     * 生成助记词
     * @return bool
     * @throws \BitWasp\Bitcoin\Exceptions\RandomBytesFailure
     */
    public function getKeyStore()
    {
        // Bip39
        $random = new Random();
        // 生成随机数
        $entropy = $random->bytes(Bip39Mnemonic::MIN_ENTROPY_BYTE_LEN);
        $bip39 = MnemonicFactory::bip39();
        // 通过随机数生成助记词
        $mnemonic = $bip39->entropyToMnemonic($entropy);
        $random = null;
        return returnSuccess($mnemonic);
    }

    /**
     * 根据助记词跟明文密码获取私钥
     * @param string $mnemonic
     * @param string $pwd
     */
    public function getPrivateKey($mnemonic = '', $pwd = '')
    {
        if ($mnemonic == ''){
            return returnError('请传入助记词');
        }
        if ($pwd == ''){
            return returnError('请传入明文密码');
        }
        $seedGenerator = new Bip39SeedGenerator();
        // 通过助记词生成种子，传入可选加密串
        $seed = $seedGenerator->getSeed($mnemonic, $pwd);
        $hdFactory = new HierarchicalKeyFactory();
        $master = $hdFactory->fromEntropy($seed);
        //返回私钥
        $this->setPrivateKey($master->getPrivateKey()->getHex());

    }

    /**
     * 设置私钥以及地址
     * @param string $private
     */
    private function setPrivateKey($private = '')
    {   //设置本地私钥
        $this->PrivateKey = $private;
        //根据私钥计算出公钥以及地址
        $this->BitcoinECDSA->setPrivateKey($this->getPrivateKey());
        //获取公钥跟地址
        $public_key = bin2hex(secp256k1_pubkey_create(hex2bin($this->PrivateKey), true));
        $address = hash('ripemd160', hash('sha256', hex2bin($public_key), true));
        //设置p2p节点信息
        ProcessManager::getInstance()->getRpcCall(PeerProcess::class)->init($address);
        //开始监听
        ProcessManager::getInstance()->getRpcCall(PeerProcess::class, true)->loading();
        //把地址写入配置文件缓存
        get_instance()->config['seedsNodes'] = $address;
    }

    /**
     * 给字符串加签
     * @param string $str
     * @return string
     */
    public function encodeNodeData(string $str = '')
    {
//        $this->BitcoinECDSA->setPrivateKey(bin2hex($this->getPrivateKey()));
        $encode_str = bin2hex($this->BitcoinECDSA->signMessage($str, true));
        return $encode_str;
    }


    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "NodeEncodeProcess关闭.";
    }

}
