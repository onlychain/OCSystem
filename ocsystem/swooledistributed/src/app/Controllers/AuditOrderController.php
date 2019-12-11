<?php
namespace app\Controllers;

use app\Models\AppModel;
//use app\Process\MyProcess;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use Server\Components\Process\ProcessManager;
use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;

class AuditOrderController extends Controller
{
    /**
     * 存储椭圆曲线加密函数
     * @var
     */
    protected $bitcoinECDSA;

    /**
     * 交易验证模型
     * @var
     */
    protected $Validation;
    /**
     * 初始化函数
     * @param string $controller_name
     * @param string $method_name
     * @throws \Exception
     */
    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        //实例化椭圆曲线加密算法
        $this->bitcoinECDSA = new BitcoinECDSA();
        //实例化验签函数
        $this->Validation = $this->loader->model('Trading/ValidationModel', $this);
    }

    /**
     * 生成私钥以及地址
     */
    public function http_createdAccount()
    {
        $address_type = $this->http_input->getAllPostGet() ?? 1;
        //生成私钥
        $this->bitcoinECDSA->generateRandomPrivateKey();
//        $this->bitcoinECDSA->setPrivateKey();
        //获取私钥
        $privatek = $this->bitcoinECDSA->getPrivateKey();

        $address_type['addressType'] = $address_type['addressType'] ?? 2;
        //生成地址
        $address = '';
        switch ($address_type['addressType']){
            case 1 :
                $address = $this->bitcoinECDSA->getAddress();
                //获取公钥
                $publick = $this->bitcoinECDSA->getPubKey();
                break;
            case 2 :
                //获取公钥
                $publick = bin2hex(secp256k1_pubkey_create(hex2bin($privatek), true));
                $address = hash('ripemd160', hash('sha256', hex2bin($publick), true));
                break;
            default :
                //获取公钥
                $publick = bin2hex(secp256k1_pubkey_create(hex2bin($privatek), true));
                $address = hash('ripemd160', hash('sha256', hex2bin($publick), true));
                break;
        }

        //组装返回结果
        $res = [
            'privateKey'    =>  $privatek,
            'publicKey'     =>  $publick,
            'address'       =>  $address,
        ];
        return $this->http_output->lists($res);
    }



    /**
     * 生成数据验签
     */
    public function http_generateSign()
    {
        $sign_data = $this->http_input->getAllPostGet();
        $ttt = $sign_data['message'];
        $this->bitcoinECDSA->setPrivateKey($sign_data['privateKey']);
        $sign = $this->bitcoinECDSA->signMessage($ttt);
        return $this->http_output->end($sign);
    }


    public function http_testActionEncode()
    {

//        $sign_data = $this->http_input->getAllPostGet();
        $msg = '6';;
        $privateKey = '66e5d90ddcb85090d13cc9f9bd2794fdb9958a608b8bd27092783faa2a7ed7c8';
//        $trading_sign = $this->Validation->getScript($sign_data['message']);
//        $trading = $this->Validation->getScriptSig($trading_sign['Data'], $sign_data['privateKey'], $sign_data['publicKey']);
//        foreach ($ttt['tx'] as $ts_key => $ts_val){
//            $ttt['tx'][$ts_key]['scriptSig'] = $trading['Data'][$ts_key];
//        }
        var_dump('加签内容:');
        var_dump($msg);
        var_dump('使用的私钥:');
        var_dump($privateKey);
        $this->bitcoinECDSA->setPrivateKey($privateKey);
        $sign = $this->bitcoinECDSA->signMessage($msg, false, true, '999');
        var_dump('加密后的消息体:');
        var_dump($sign);
        $sign = $this->bitcoinECDSA->checkSignatureForRawMessage($sign);
        var_dump('验证用的验签:');
//        var_dump($sign);

        $sign = $this->bitcoinECDSA->signMessage($msg, true, true, '999');
        var_dump($sign);
        return;
        $res = $this->bitcoinECDSA->checkSignatureForMessage('792b9a33bebdd38114fa12c2699ed7112be40b95', $sign, $msg);
        var_dump($res);
        var_dump(bin2hex($sign));
        var_dump(hex2bin(bin2hex($sign)));
        return $this->http_output->end($sign);
    }

    public function http_testActionDncode()
    {
        $sign = 'Hy5DvnoSkWz28xKlE/y2yYtwjOLdGNxOv3KoB8nIoxsNPKmmLnZSGGmY1o0EfbEJCcaiXt5IJ60OVypO5sX6tAQ=';
        $msg = '6';
        $res = $this->bitcoinECDSA->checkSignatureForMessage('792b9a33bebdd38114fa12c2699ed7112be40b95', $sign, $msg);
        var_dump($res);
        var_dump(bin2hex($sign));
        var_dump(hex2bin(bin2hex($sign)));
        return $this->http_output->end($sign);
    }

    /**
     * 验证验签
     */
    public function http_verifySign()
    {
        $sign = $this->http_input->getAllPostGet('text');
        var_dump($sign);
        $res = $this->bitcoinECDSA->checkSignatureForRawMessage($sign);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', '验证失败!');
        }
        return $this->http_output->end($res['Data']);
    }

}