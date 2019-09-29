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
        //获取公钥
        $publick = $this->bitcoinECDSA->getPubKey();
        //获取私钥
        $privatek = $this->bitcoinECDSA->getPrivateKey();

        //生成地址
        $address = '';
        switch ($address_type['addressType']){
            case 1 : $address = $this->bitcoinECDSA->getAddress();
                    break;
            case 2 : $address = hash('ripemd160', hash('sha256', hex2bin($publick), true));;
                break;
            default : $address = hash('ripemd160', hash('sha256', hex2bin($publick), true));;
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
        $trading_sign = $this->Validation->getScript($sign_data['message']);
        $trading = $this->Validation->getScriptSig($trading_sign['Data'], $sign_data['privateKey'], $sign_data['publicKey']);
        foreach ($ttt['tx'] as $ts_key => $ts_val){
            $ttt['tx'][$ts_key]['scriptSig'] = $trading['Data'][$ts_key];
        }

        $this->bitcoinECDSA->setPrivateKey($sign_data['privateKey']);
        $sign = $this->bitcoinECDSA->signMessage(json_encode($ttt));
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