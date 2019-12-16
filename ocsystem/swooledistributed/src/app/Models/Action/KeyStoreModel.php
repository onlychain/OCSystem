<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Action;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39Mnemonic;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Mnemonic\MnemonicFactory;
use Web3p\EthereumUtil\Util;
//自定义进程
use app\Process\PurseProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class KeyStoreModel extends Model
{
    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
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
        return returnSuccess($master->getPrivateKey()->getHex());
    }
}
