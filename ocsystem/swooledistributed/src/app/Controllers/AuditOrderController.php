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
    public $bitcoinECDSA;
    protected $Validation;//交易验证模型
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
        $this->Validation = $this->loader->model('Trading/ValidationModel', $this);
        $this->BaseData = $this->loader->mysql("basePool", $this);//初始化基础库连接池
        $this->BaseNewData = $this->loader->mysql("baseNewPool", $this);//初始化商品库连接池
    }

    public function http_delUserData()
    {
        $user_data1 = $this->BaseData->Select('*')->from('t_user')->query();
        $user_data2 = $this->BaseNewData->Select('*')->from('t_user')->query();
        $user_data = [];
        foreach($user_data1['result'] as $ud1_key => $ud1_val){
            $user_data[$ud1_val['open_id'].'|'.$ud1_val['user_uuid']] = $ud1_val;
        }
        foreach ($user_data2['result'] as $ud2_key => $ud2_val){
            if(!isset($user_data[$ud2_val['open_id'].'|'.$ud2_val['user_uuid']]))
                $user_data[$ud2_val['open_id'].'|'.$ud2_val['user_uuid']] = $ud1_val;
        }
//        var_dump($user_data2['result']);
        $insert_data = [
//            'user_id',
            'user_uuid',
            'nickname',
            'sex',
            'province',
            'city',
            'headimgurl',
            'country',
            'unionid',
            'mobile',
            'open_id',
            'privilege',
            'created',
            'modified',
        ];
        $insert = [];
        foreach ($user_data as $ud_key => $ud_val){
            $insert[] = [
//                $ud_val['user_id'],
                $ud_val['user_uuid'],
                $ud_val['nickname'],
                $ud_val['sex'],
                $ud_val['province'],
                $ud_val['city'],
                $ud_val['headimgurl'],
                $ud_val['country'],
                $ud_val['unionid'],
                $ud_val['mobile'],
                $ud_val['open_id'],
                $ud_val['privilege'],
                $ud_val['created'],
                $ud_val['modified'],
            ];
        }
        $res = $this->BaseNewData->insertInto('t_user_copy')->intoColumns($insert_data)->intoValues($insert)->query();
//        var_dump(count($user_data1['result']));
//        var_dump(count($user_data2['result']));
        var_dump(count($user_data));
        var_dump($res);
    }



    /**
     *企业审核查看列表
     */
    public function http_auditInit()
    {
        return $this->http_output->end(1);
    }

    /**
     * 生成私钥以及地址
     */
    public function http_createdAccount()
    {
        //生成私钥
        $this->bitcoinECDSA->generateRandomPrivateKey();
        //获取地址
        $address = $this->bitcoinECDSA->getAddress();
        //获取公钥
        $publick = $this->bitcoinECDSA->getPubKey();
        //获取私钥
        $privatek = $this->bitcoinECDSA->getPrivateKey();
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
//        return $this->http_output->lists(['sign' => $sign]);
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
        var_dump($res['Data']);
        return $this->http_output->end($res['Data']);
//        return $this->http_output->lists($res['Data']);
    }

}