<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Action;

use Noodlehaus\Exception;
use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;

class ActionEncodeModel extends Model
{
    /**
     * 存储交易输入
     * @var type
     */
    protected $vin = array();

    /**
     * 存储交易输入数量
     * @var type
     */
    protected $vinNum = '';

    /**
     * 存储交易输出
     * @var type
     */
    protected $vout = array();

    /**
     * 存储交易输出数量
     * @var type
     */
    protected $voutNum = '';

    /**
     * 存储备注信息
     * @var type
     */
    protected $ins = '0001';

    /**
     * 交易确认时间
     * @var type
     */
    protected $time = "";

    /**
     * 交易发起人私钥
     * @var type
     */
    protected $hex = "";

    /**
     * 区块哈希地址（打包之后添加）
     * @var type
     */
    protected $blockHash = "";

    /**
     * 系统版本
     * @var type
     */
    protected $version = 1;

    /**
     * 交易锁定时间
     * @var type
     */
    protected $lockTime = '';

    /**
     * 设置私钥
     * @var string
     */
    protected $privateKey = '';

    /**
     * 设置公钥
     * @var string
     */
    protected $publicKey = '';

    /**
     * 交易锁定类型
     * @var string
     */
    protected $actionType = '01';

    /**
     * 投票者地址
     * @var string
     */
    protected $voter = '0000000000000000000000000000000000000000';

    /**
     * 是否重新投票质押
     * @var string
     */
    protected $again = '01';//01正常质押，02重新质押

    /**
     * 投票节点
     * @var array
     */
    protected $candidate = '010000000000000000000000000000000000000000';

    /**
     * 投票的轮次（LEB128）
     * @var string
     */
    protected $rounds = '01';

    /**
     * 超级节点质押的地址
     * @var string
     */
    protected $pledge = '0000000000000000000000000000000000000000';

    /**
     * 超级节点开放的Ip
     * @var string
     */
    protected $ip = 'ffffffff';

    /**
     * 超级节点开放的端口
     * @var string
     */
    protected $port = 'ffff';

    /**
     * 交易手续费
     * @var string
     */
    protected $cost = '0000000000000000';

    /**
     * 交易生成时的区块高度
     * @var string
     */
    protected $createdBlock = '00000000';

    /**
     * action发起人地址
     * @var string
     */
    protected $address = '';
    /**
     * 存储椭圆曲线加密函数
     * @var
     */
    protected $BitcoinECDSA = null;

    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        //实例化椭圆曲线加密算法
//        $this->BitcoinECDSA = new BitcoinECDSA();
        $this->clearModel();
    }

    /**
     * 反序列化交易
     * @param array $trading
     */
    public function decodeTrading(string $trading = '', $action_index = 0)
    {
        $decode_trading = [];//存储反序列化后的内容
        $str_index = $action_index;
        $flag = true;
        $offset = 0;
        $vin_num = 0;
        while ($flag) {
            $val = hexdec(substr($trading, $str_index, 2));
            if ($val == false) {
                break;
            }
            $str_index += 2;//交易偏移量
            $vin_num |= ($val & 0x7F) << $offset;
            $offset += 7;
            if (($val & 0x80) == 0 ){
                $flag = false;
                break;
            }
        }
        //拼接交易输入
        for ($count_vin = 0; $count_vin < $vin_num; ++$count_vin){
            $txId = '';//引用的交易输入
            $n = '';//交易输入索引
            $script_len = 0;//解锁脚本长度
            $script = '';//解锁脚本
            $txId = substr($trading, $str_index, 64);
            if ($txId == false){
                return returnError('当前交易数据有误.');
            }
            if($txId == '0000000000000000000000000000000000000000000000000000000000000000'){
                //为coinbase交易
                //索引递进64个字符
                $str_index += 64;
                $decode_trading['vin'][$count_vin]['n'] = hexdec(substr($trading, $str_index, 2));
                //索引递进4个字符
                $str_index += 2;
                //获取coinbase内容长度
                $script_len = hexdec(substr($trading, $str_index, 4)) * 2;
                //索引递进4个字符
                $str_index += 4;
                //获取coinbase内容
                $decode_trading['vin'][$count_vin]['coinbase'] = hex2bin(substr($trading, $str_index, $script_len));
                //索引递进
                $str_index += $script_len;
            }else{
                //为普通交易交易
                $decode_trading['vin'][$count_vin]['txId'] = $txId;
                //索引递进64个字符
                $str_index += 64;
                //$decode_trading['vin'][$count_vin]['n'] = hexdec(substr($trading, $str_index, 4));
                $flag = true;
                $offset = 0;
                $decode_trading['vin'][$count_vin]['n'] = 0;
                while ($flag) {
                    $val = hexdec(substr($trading, $str_index, 2));
                    //索引递进2个字符
                    $str_index += 2;//交易偏移量
                    $decode_trading['vin'][$count_vin]['n'] |= ($val & 0x7F) << $offset;
                    $offset += 7;
                    if (($val & 0x80) == 0 ){
                        $flag = false;
                        break;
                    }
                }
                //获取解锁脚本内容长度
                $script_len = hexdec(substr($trading, $str_index, 4)) * 2;
                //索引递进4个字符
                $str_index += 4;
                //获取解锁脚本内容
                $decode_trading['vin'][$count_vin]['scriptSig'] = substr($trading, $str_index, $script_len);
                //索引递进
                $str_index += $script_len;
            }
        }
        //处理交易输出
        $flag = true;
        $offset = 0;
        $vout_num = 0;
        while ($flag) {
            $val = hexdec(substr($trading, $str_index, 2));
            if ($val == false) {
                break;
            }
            $str_index += 2;//交易偏移量
            $vout_num |= ($val & 0x7F) << $offset;
            $offset += 7;
            if (($val & 0x80) == 0 ){
                $flag = false;
                break;
            }
        }
        //拼接交易输出
        for($count_vout = 0; $count_vout < $vout_num; ++$count_vout){
            //交易金额
            $decode_trading['vout'][$count_vout]['value'] = abs(hexdec(substr($trading, $str_index, 16)));
            if($decode_trading['vout'][$count_vout]['value'] == 0){
                return returnError('交易数据有误.');
            }
            //索引递进16个字符
            $str_index += 16;
            //第几个交易输出
            $decode_trading['vout'][$count_vout]['n'] = $count_vout;
            //交易解锁脚本长度
            $unlock_len = hexdec(substr($trading, $str_index, 4)) * 2;
            //索引递进4个字符
            $str_index += 4;
            //交易锁定脚本
            $decode_trading['vout'][$count_vout]['reqSigs'] = substr($trading, $str_index, $unlock_len);
            //索引递进
            $str_index += $unlock_len;
            //拼上地址
            preg_match_all("/(?:\[)(.*)(?:\])/i", script_decompile(hex2bin($decode_trading['vout'][$count_vout]['reqSigs'])), $address);
            $decode_trading['vout'][$count_vout]['address'] = $address[1][0];
        }
        //解析交易锁定区块
        $decode_trading['lockTime'] = hexdec(substr($trading, $str_index, 8));
        //索引递进8个字符
        $str_index += 8;
        //解析手续费
        $decode_trading['cost'] = hexdec(substr($trading, $str_index, 16));
        //索引递进16个字符
        $str_index += 16;
        return ['trading' => $decode_trading, 'index' => $str_index];
    }

    /**
     * 序列化action请求
     * @return string
     */
    public function encodeAction()
    {
//        if($this->BitcoinECDSA == null){
//            $this->BitcoinECDSA = new BitcoinECDSA();
//        }
        $encode_action_str = '';//存储序列化的action内容
        $encode_action_str .= $this->getVersion();//拼接版本号
        $encode_action_str .= $this->getActionType();//拼接action类型
        $encode_action_str .= $this->getTime();//拼接时间(unix时间戳)
        $encode_action_str .= $this->getCreatedBlock();//拼接创建交易时的区块
        //拼接是否有交易标识，00代表没有交易数据，01代表有交易数据
        if (empty($this->vin)){
            $encode_action_str .= '00';
        }else{
            $encode_action_str .= '01';
            $encode_action_str .= $this->encodeTrading();
//            if(!$encode_res['IsSuccess']){
//                return $encode_res;
//            }
        }
        switch ($this->getActionType()){
            case '02' : $encode_action_str .= '01';//00代表没有action数据，01代表有action数据
                        $encode_action_str .= $this->encodeVoteAction();
                        break;
            case '03' : $encode_action_str .= '01';//00代表没有action数据，01代表有action数据
                        $encode_action_str .= $this->encodePledgeAction();
                        break;
            default :  $encode_action_str .= '00';//00代表没有action数据，01代表有action数据
//                       $encode_action_str .= $this->encodeTradingAction();
                       break;
        }
        //拼接地址
//        $encode_action_str .= $this->getAddress();
        $encode_action_str .= $this->getPublicKey();
        //拼接自定义信息
        $encode_action_str .= $this->getIns();
        $action_msg = hash('sha256', hash('sha256', hex2bin($encode_action_str), true), true);
        //用私钥加密得到签名
        $signa_ture = bin2hex(secp256k1_sign($this->getPrivateKey(), $action_msg));
        $encode_action_str .= $signa_ture;
        return $encode_action_str;
    }

    /**
     * 解析action数据
     * @param string $encode_action
     * @return bool
     */
    public function decodeAction(string $encode_action = '', int $type = 1)
    {
        if($encode_action == ''){
            return returnError('请输入action!');
        }
//        if($this->BitcoinECDSA == null){
//            $this->BitcoinECDSA = new BitcoinECDSA();
//        }
        $str_index = 0;//序列化索引
        $action_arr = [];//存储序列化后的action数据
        //解析版本号
        $action_arr['version'] = hexdec(substr($encode_action, $str_index, 2));
        //索引递进两个字符
        $str_index += 2;
        //解析action类型
        $action_arr['actionType'] = hexdec(substr($encode_action, $str_index, 2));
        //索引递进两个字符
        $str_index += 2;
        //解析action生成的unix时间戳
        $action_arr['time'] = hexdec(substr($encode_action, $str_index, 8));
        //索引递进两个字符
        $str_index += 8;
        //解析action生成时的区块高度
        $action_arr['createdBlock'] = hexdec(substr($encode_action, $str_index, 8));
        //索引递进8个字符
        $str_index += 8;
        //解析是否有交易
        $is_trading = hexdec(substr($encode_action, $str_index, 2));
        //索引递进2个字符
        $str_index += 2;
        if($is_trading == 0){
            //没有交易
            $action_arr['trading'] = [];
        }else{
            //有交易，进行交易反序列化
            $trading = $this->decodeTrading($encode_action, $str_index);
            if(isset($trading['IsSuccess']) && !$trading['IsSuccess']){
                return false;
            }
            $action_arr['trading'] = $trading['trading'];
            $str_index = $trading['index'];
        }
        //解析是否有action数据
        $is_action_data = hexdec(substr($encode_action, $str_index, 2));
        //索引递进2个字符
        $str_index += 2;
        $action_arr['action'] = [];
        switch ($action_arr['actionType']){
            case 2 ://投票action
                    $voter_data = $this->decodeVoteAction($encode_action, $str_index);
                    $action_arr['action'] = $voter_data['voter'];
                    $str_index = $voter_data['index'];
                    break;
            case 3 ://质押action
                    $pledge_data = $this->decodePledgeAction($encode_action, $str_index);
                    $action_arr['action'] = $pledge_data['pledge'];
                    $str_index = $pledge_data['index'];
                    break;
            default : //其他类型的action
                    break;
        }
        //解析地址
//        $action_arr['address'] = substr($encode_action, $str_index, 40);
//        $str_index += 40;
        //解析公钥
        $action_arr['originator'] = substr($encode_action, $str_index, 66);
        $str_index += 66;
        //解析自定义信息内容
        $ins_len = hexdec(substr($encode_action, $str_index, 2));
        //索引递进2个字符
        $str_index += 2;
        $action_arr['ins'] = '00';substr($encode_action, $str_index, $ins_len * 2);
        //索引递进2个字符
        $str_index += 2;

        //解析actionSign
//        $action_sign = hex2bin(substr($encode_action, $str_index, 176));
//        $check_res = $this->BitcoinECDSA->checkSignatureForMessage($action_arr['address'], $action_sign, substr($encode_action,0,strlen($encode_action)-176));
//        if(!$check_res){
//            return false;
//        }
//        $action_arr['actionSign'] = $action_sign;
        //去除30固定内容,获取验签字节长度
        if ($type == 1){
            $sign_len = hexdec(substr($encode_action, $str_index + 2, 2)) * 2;

            //索引递进2个字符
            $sign = substr($encode_action, $str_index, $sign_len + 4);
            $action_arr['actionSign'] = $sign;
            //验证签名
            $msg = substr($encode_action, 0, $str_index);
            $msg = hash('sha256', hash('sha256', hex2bin($msg), true), true);
            try{
                if(!secp256k1_verify(hex2bin($action_arr['originator']), $msg, hex2bin($sign))){
                    return false;
                }
            }catch (\Exception $e){
                return false;
            }
            //获取
            //目前ins固定为00
            $action_arr['txId'] = bin2hex(hash('sha256', hash('sha256', hex2bin($encode_action), true), true));
        }
        return $action_arr;
    }

    /**
     * 序列化交易第二版，作为工具使用
     * @return string
     */
    public function encodeTrading()
    {
        $hex_trading = '';//序列化交易的内容提供
        $trading_vin = '';//拼接交易输入
        $trading_vout = '';//拼接交易输出
        //拼接交易输出
//        $remove_vout = [];//去除重复的交易输出
        if(!empty($this->vout)){
            foreach ($this->vout as $vout_key => $vout_val){
                //拼接交易金额,最小粒度为亿分之一，称为一个汤圆
                $trading_vout .= str_pad(dechex(abs($vout_val['value'])), 16, '0', STR_PAD_LEFT);
                //处理锁定脚本，目前只支持一种序列化形式
//                if(isset($remove_vout[$vout_val['address']])){
//                    return returnError('交易地址输出重复.');
//                }else{
//                    $remove_vout[$vout_val['address']] = 1;
//                };
                $public_key_hash160 = $vout_val['address'];
                switch($vout_val['type']){
                    case 1 ://使用HASH160交易
                        $public_key = bin2hex(script_compile('DUP HASH160 ['.$public_key_hash160.'] EQUALVERIFY CHECKSIG'));
                        break;
                    default :
                        $public_key = bin2hex(script_compile('DUP HASH160 ['.$public_key_hash160.'] EQUALVERIFY CHECKSIG'));
                        break;
                }
                //计算锁定脚本长度，最长为2个字节
                $trading_vout .= str_pad(dechex(strlen($public_key) / 2), 4, '0', STR_PAD_LEFT);
                //拼接锁定脚本
                $trading_vout .= $public_key;
            }
        }
        //拼接交易输入,方便做验证
//        $remove_vin = [];//去除重复的交易输入
        foreach ($this->vin as $vin_key => $vin_val){

            $temp_vin = '';
            $script_hex = '';//存储十六进制后的脚本
            //如果引用的是coinbase
            if(isset($vin_val['coinbase'])){
                //交易txId默认全为0
                $trading_vin .= '0000000000000000000000000000000000000000000000000000000000000000';
                //交易索引为1个字节，默认都是ff
                $trading_vin .= 'ff';
                //计算内容大小
                $script_hex = bin2hex($vin_val['coinbase']);
                //脚本内容或自定义内容不准大于2个字节
                if(count($script_hex) > 65536)  return returnError('自定义内容过长');
                //拼接脚本序列化内容
                $trading_vin .= str_pad(dechex(strlen($script_hex) / 2), 4, '0', STR_PAD_LEFT) . $script_hex;
            }else{
//                if(isset($remove_vin[$vin_val['txId'] . $vin_val['n']])){
//                    return returnError('交易输入重复.');
//                }else{
//                    $remove_vin[$vin_val['txId'] . $vin_val['n']] = 1;
//                }
                $script_temp = '';//临时存储解锁脚本
                //如果是正常的交易引入
                $trading_vin.= $vin_val['txId'];
                //加入序列
                $trading_vin.= $this->encodeLEB128($vin_val['n']);
                //生成解锁脚本
                //1：去除锁定脚本内的脚本代码
                $script_remove = bin2hex(script_remove_codeseparator(hex2bin($vin_val['scriptSig'])));
                //拼接序列化交易(修改序列化规则方便校验)
                $scrializa_trading = $vin_val['txId'] . $this->encodeLEB128($vin_val['n']) . $script_remove;
                //把过滤后的脚本拼接到序列化的交易单中，并执行2次哈希
                $trading_msg = hash('sha256', hash('sha256', hex2bin($scrializa_trading), true), true);
                //用私钥加密得到签名
                $signa_ture = bin2hex(secp256k1_sign($this->getPrivateKey(), $trading_msg));
                //生成输出脚本
                $scriptsig_bytecode = bin2hex(script_compile("[$signa_ture] [".$this->getPublicKey()."]"));
                //计算输出脚本长度
                $trading_vin .=str_pad(dechex(strlen($scriptsig_bytecode) / 2), 4, '0', STR_PAD_LEFT) . $scriptsig_bytecode;
            }
        }
        $hex_trading .= $this->getVinNum();//拼接交易输入数量
        $hex_trading .= $trading_vin;//拼接交易输入
        $hex_trading .= $this->getVoutNum();//拼接交易输出数量
        $hex_trading .= $trading_vout;//拼接交易输出
        $hex_trading .= $this->getLockTime();//拼接交易输出
        $hex_trading .= $this->getCost();//拼接交易输出
        return $hex_trading;//returnSuccess($hex_trading);
    }

    public function encodeTradingAction()
    {
        $trading_action = '';
        $trading_action = '';
    }

    /**
     * 获取投票action的action数据
     * @return string
     */
    public function encodeVoteAction()
    {
        $vote_action = '';
        $vote_action .= $this->getVoter();//拼接投票者地址
        $vote_action .= $this->getAgain();//拼接投票者质押类型
        $vote_action .= $this->getCandidate();//拼接所投节点信息
        $vote_action .= $this->getRounds();//拼接轮次
        return $vote_action;
    }

    /**
     * 解析投票action的action数据
     * @return string
     */
    public function decodeVoteAction($action = '', $action_index = 0)
    {
        $vote_action = [];//存储投票解析后的action数据
        //获取投票者地址
        $vote_action['voter'] = substr($action, $action_index, 40);
        //索引递进40个字符
        $action_index += 40;
        //获取投票者质押类型
        $vote_action['again'] = hexdec(substr($action, $action_index, 2));
        //索引递进2个字符
        $action_index += 2;
        //获取所投节点的数量
        $candidate_num = hexdec(substr($action, $action_index, 2));
        //索引递进2个字符
        $action_index += 2;
        //获取所投节点地址
        $vote_action['candidate'] = [];
        for ($num = 0; $num < $candidate_num; ++$num){
            //获取所投节点的地址
            $vote_action['candidate'][$num] = substr($action, $action_index, 40);
            //索引递进40个字符
            $action_index += 40;
        }
        //获取所投轮次
        $flag = true;
        $offset = 0;
        $rounds = 0;
        while ($flag) {
            $val = hexdec(substr($action, $action_index, 2));
            if ($val == false) {
                break;
            }
            $action_index += 2;//交易偏移量
            $rounds |= ($val & 0x7F) << $offset;
            $offset += 7;
            if (($val & 0x80) == 0 ){
                $flag = false;
                break;
            }
        }
        $vote_action['rounds'] = $rounds;
        return ['voter' => $vote_action, 'index' => $action_index];
    }

    /**
     * 获取超级节点质押action的action数据
     * @return string
     */
    public function encodePledgeAction()
    {
        $pledge = '';//质押action
        $pledge .= $this->getPledge();//拼接质押者信息
        $pledge .= $this->getPledgeNode();//拼接质押节点信息
        $pledge .= $this->getIp();//拼接质押者开放的Ip
        $pledge .= $this->getPort();//拼接质押者开放的端口
        return $pledge;
    }

    /**
     * 解析超级节点质押action的action数据
     * @return string
     */
    public function decodePledgeAction($action = '', $action_index = 0)
    {
        $pledge_data = [];//存储解析后的质押action
        //解析质押人地址
        $pledge_data['pledge'] = substr($action, $action_index, 40);
        //索引递进40个字符
        $action_index += 40;
        //解析质押的节点地址
        $pledge_data['pledgeNode'] = substr($action, $action_index, 40);
        //索引递进40个字符
        $action_index += 40;
        //解析质押的节点提供的ip地址
        $ip = '';
        for ($ip_num = 0; $ip_num < 4; ++$ip_num){
            $ip .= hexdec(substr($action, $action_index, 2)) . '.';
            //索引递进2个字符
            $action_index += 2;
        }
        $pledge_data['ip'] = substr($ip,0,strlen($ip) - 1);
        //处理超级节点质押的端口
        $pledge_data['port'] = hexdec(substr($action, $action_index, 4));
        //索引递进4个字符
        $action_index += 4;
        return ['pledge' => $pledge_data, 'index' => $action_index];
    }

    public function encodeIncentivesAction()
    {

    }

    /**
     * 设置交易输入
     * @param type $vin_data
     */
    public function setVin(array $vin_data = [])
    {
        if(empty($vin_data)){
            $this->vin = [];
            $this->vinNum = '00';
        }else{
            //判断是否有重复的交易
            $this->vin = $vin_data;
            //设置交易输入数量
            $this->vinNum = $this->encodeLEB128(count($vin_data));
        }
        return $this;
    }

    /**
     * 获取交易输入
     * @return array
     */
    public function getVin() : array
    {
        return $this->vin;
    }

    /**
     * 获取交易输入数量
     * @return array
     */
    public function getVinNum() : string
    {
        return $this->vinNum;
    }

    /**
     * 设置交易输出
     * @param type $vin_data
     */
    public function setVout($vout_data = [])
    {
        if(empty($vout_data)){
            $this->vout = [];
            $this->voutNum = '00';
        }else{
//            if(count($vout_data) > 250) return false;
            $this->vout = $vout_data;
            //设置交易输入数量
            $this->voutNum = $this->encodeLEB128(count($vout_data));
        }
        return $this;
    }

    /**
     * 获取交易输出
     * @return array
     */
    public function getVout() : array
    {
        return $this->vout;
    }

    /**
     * 获取交易输出数量
     * @return array
     */
    public function getVoutNum() : string
    {
        return $this->voutNum;
    }

    /**
     * 设置自定义信息内容
     * @param type $vin_data
     */
    public function setIns(string $ins_data = '')
    {
//        if($ins_data == ''){
//            $ins_hex = '00';
//        }else{
//            $ins_hex = bin2hex($ins_data);
//        }
//        $ins_len = dechex(strlen($ins_hex)) / 2;
        $ins_hex = '00';
        $ins_len = '01';
//        if($ins_len > 0) return false;
//        $this->ins = str_pad($ins_len, 2, '0', STR_PAD_LEFT) . $ins_hex;
        $this->ins = $ins_len . $ins_hex;
        return $this;
    }

    /**
     * 获取获取自定义信息
     * @return string
     */
    public function getIns() : string
    {
        return $this->ins;
    }

    /**
     * 设置交易时间
     * @param type $time
     */
    public function setTime(int $time = 0)
    {
        if($time < 0) return false;
        $hex_time = dechex($time);
        $this->time = str_pad($hex_time, 8, '0', STR_PAD_LEFT);
        return $this;
    }

    /**
     * 获取交易时间
     * @return string
     */
    public function getTime() : string
    {
        return $this->time;
    }

    /**
     * 获取版本信息
     * @return string
     */
    public function getVersion() : string
    {
        if(gettype($this->version) == 'integer'){
            //如果是数字，转化为十六进制返回
            return str_pad(dechex($this->version), 2, '0', STR_PAD_LEFT);
        }
        return $this->version;
    }

    /**
     * 设置交易锁定时间
     * @param type $lock_time
     */
    public function setLockTime(float $lock_time = 0)
    {
        $lock_time_hex = str_pad(dechex($lock_time), 8, '0', STR_PAD_LEFT);
        $this->lockTime = $lock_time_hex;
        return $this;
    }

    /**
     * 获取交易锁定时间
     * @return string
     */
    public function getLockTime() :string
    {
        return $this->lockTime;
    }

    /**
     * 设置私钥
     * @param type $lock_time
     */
    public function setPrivateKey(string $private_key = '')
    {
        $this->privateKey = $private_key;
        return $this;
    }

    /**
     * 获取私钥
     * @return string
     */
    public function getPrivateKey() :string
    {
        return hex2bin($this->privateKey);
    }

    /**
     * 设置公钥
     * @param type $lock_time
     */
    public function setPublicKey(string $publick_key = '')
    {
        $this->publicKey = $publick_key;
        return $this;
    }

    /**
     * 获取手续费
     * @return int
     */
    public function getCost() : string
    {
        return $this->cost;
    }

    /**
     * 设置手续费
     * @param int $cost
     * @return $this
     */
    public function setCost(int $cost = 0)
    {
        $this->cost = str_pad(dechex(abs($cost)), 16, '0', STR_PAD_LEFT);;
        return $this;
    }

    /**
     * 获取公钥
     * @return string
     */
    public function getPublicKey() :string
    {
        if($this->publicKey == ''){
            return bin2hex(secp256k1_pubkey_create($this->getPrivateKey(), true));
        }
        return $this->publicKey;
    }

    /**
     * 验证交易锁定脚本
     * @param type $tx_id引用的交易utxo
     * @param type $n引用的第几个交易输出
     * @param type $script_sig交易锁定脚本
     * @param type $script_pub_key交易解锁脚本
     * @return array
     */
    public function checkScriptSig($tx_id = '', $n = '',$script_sig = '', $script_pub_key = '')
    {
        if($tx_id == ''){
            return returnError('请输入引用的交易!');
        }
        if($n < 0){
            return returnError('请输入引用的交易序号!');
        }
        if($script_sig == ''){
            return returnError('请输入引用的锁定脚本!');
        }
        if($script_pub_key == ''){
            return returnError('请输入引用的解锁脚本!');
        }
        $ctx = script_create_context();
        script_set_checksig_callback($ctx, function($subscript) use ($tx_id, $n){
            // 序列化结果应该和签名过程一样，否则验证失败
            $scrializa_trading = $tx_id .$this->encodeLEB128($n) . bin2hex($subscript);
            //把过滤后的脚本拼接到序列化的交易单中，并执行2次哈希
            $msg = hash('sha256', hash('sha256', hex2bin($scrializa_trading), true), true);
            return $msg;
        });
        // 所有的script_eval不能失败，并且script_verify为true才算验证通过
        if (!script_eval($ctx, hex2bin($script_pub_key))) return returnError('解锁脚本验证失败!');
        if (!script_eval($ctx, hex2bin($script_sig))) return returnError('锁定脚本验证失败');
        if (!script_verify($ctx)) return returnError('交易验证失败3!');
        return returnSuccess();
    }


    /**
     * 设置脚本锁定类型
     * @param int $lock_type
     * @return $this
     */
    public function setActionType(int $lock_type = 1)
    {
        if($lock_type >= 255 || $lock_type <= 0){
            return false;
        }
        $hex_type = dechex($lock_type);
        $this->actionType = str_pad($hex_type, 2, '0', STR_PAD_LEFT);
        return $this;
    }

    /**
     * 获取脚本锁定类型
     * @return int
     */
    public function getActionType()
    {
        return $this->actionType;
    }

    /**
     * leb128编码
     * @param int $value
     * @return array
     */
    public function encodeLEB128(int $value = 0)
    {
        $pos = 0;
        $str = '';
        $leb = [];
        while ($value != 0) {
            $leb[$pos++] = $value & 0x7F | 0x80;
            $value >>= 7;
        }
        if($pos > 0)
            $leb[$pos -1] &= 0x7F;

        if(empty($leb)){
            $str = '00';
        }else{
            foreach ($leb as $leb_key => $leb_val){
                $str .= str_pad(dechex($leb_val), 2, '0', STR_PAD_LEFT);
            }
        }

        return $str;
    }

    /**
     * 设置交易锁定时的区块时间
     * @param int $this_block
     * @return $this
     */
    public function setCreatedBlock($this_block = 0)
    {
        $this->createdBlock = str_pad(dechex($this_block), 8, '0', STR_PAD_LEFT);
        return $this;
    }

    /**
     * 获取该action提交时的区块高度
     * @return string
     */
    public function getCreatedBlock()
    {
        if($this->createdBlock == '00000000'){
            $top_height = CatCacheRpcProxy::getRpc()->offsetGet('topBlockHeight');
            return str_pad(dechex($top_height), 8, '0', STR_PAD_LEFT);
        }
        return $this->createdBlock;
    }

    /**
     * 设置投票者地址
     * @param string $voter
     * @return $this
     */
    public function setVoter(string $voter = '')
    {
        if ($voter == '' || strlen($voter) != 40){
            $this->voter = '0000000000000000000000000000000000000000';
        }else{
            $this->voter = $voter;
        }
        return $this;
    }

    /**
     * 获取投票者地址
     * @return string
     */
    public function getVoter() : string
    {
        return $this->voter;
    }

    /**
     * 设置投票质押是重投还是第一次头
     * @param int $again
     * @return $this
     */
    public function setAgain(int $again = 1)
    {
        if(!in_array($again, [0, 1, 2])){
            return false;
        }
        $this->again = '0'.$again;
        return $this;
    }

    /**
     * 获取投票是否有质押
     * @return string
     */
    public function getAgain() : string
    {
        return $this->again;
    }

    /**
     * 设置投票节点
     * @param array $candidate
     * @return $this|bool
     */
    public function setCandidate(array $candidate = [])
    {
        if(empty($candidate) || count($candidate) > 30){
            return false;
        }
        $this->candidate = str_pad(dechex(count($candidate)), 2, '0', STR_PAD_LEFT);
        foreach ($candidate as $p_key => $p_val){
            $this->candidate .= $p_val;
        }
        return $this;
    }

    /**
     * 设置投票轮次
     * @param int $rounds
     * @return $this
     */
    public function setRounds(int $rounds = 1)
    {
        $this->rounds = $this->encodeLEB128($rounds);
        return $this;
    }

    /**
     * 获取投票轮次
     * @return string
     */
    public function getRounds() : string
    {
        return $this->rounds;
    }

    /**
     * 设置质押人地址
     * @param string $pledge
     * @return $this|bool
     */
    public function setPledge(string $pledge = '')
    {
        if($pledge == '' || strlen($pledge) != 40){
            return false;
        }
        $this->pledge = $pledge;
        return $this;
    }

    /**
     * 获取质押人地址
     * @return string
     */
    public function getPledgeNode() : string
    {
        return $this->pledgeNode;
    }

    /**
     * 设置质押人地址
     * @param string $pledge
     * @return $this|bool
     */
    public function setPledgeNode(string $pledge_node = '')
    {
        if($pledge_node == '' || strlen($pledge_node) != 40){
            return false;
        }
        $this->pledgeNode = $pledge_node;
        return $this;
    }

    /**
     * 获取质押人地址
     * @return string
     */
    public function getPledge() : string
    {
        return $this->pledge;
    }

    /**
     * 设置地址
     * @param string $address
     * @return bool
     */
    public function setAddress(string $address = '')
    {
        if(strlen($address) != 40){
            return returnError('地址有误.');
        }
        $this->address = $address;
        return $this;
    }

    /**
     * 获取地址
     * @return string
     */
    public function getAddress() : string
    {
        if($this->address == ''){
            //获取公钥
            $public_key = $this->getPublicKey();
            if($public_key == ''){
                return returnError('地址为空.');
            }
            return hash('ripemd160', hash('sha256', hex2bin($public_key), true));
        }
        return $this->address;
    }


    /**
     * 获取序列化后的ip
     * @return string
     */
    public function getIp(): string
    {
        return $this->ip;
    }

    /**
     * 序列化Ip
     * @param string $ip
     * @return $this
     */
    public function setIp(string $ip = '000.000.000.000')
    {
        $ip = explode('.', $ip);
        $this->ip = '';
        foreach ($ip as $ip_key => $ip_val){
            $this->ip .= str_pad(dechex($ip_val), 2, '0', STR_PAD_LEFT);
        }
        return $this;
    }

    /**
     * 设置节点绑定的端口
     * @param int $port
     * @return $this|bool
     */
    public function setPort(int $port = 0)
    {
        if($port > 65535 || $port < 0){
            return false;
        }
        $this->port = str_pad(dechex($port), 4, '0', STR_PAD_LEFT);
        return $this;
    }

    /**
     * 获取节点绑定的端口
     * @return string
     */
    public function getPort() : string
    {
        return $this->port;
    }

    /**
     * 获取投票节点
     * @return string
     */
    public function getCandidate() : string
    {
        return $this->candidate;
    }

    /**
     * 初始化脚本参数
     */
    public function clearModel()
    {
        $this->vin = array();
        $this->vinNum = '';
        $this->vout = array();
        $this->voutNum = '';
        $this->ins = '0001';
        $this->time = "00000000";
        $this->hex = "";
        $this->blockHash = "";
        $this->version = 1;
        $this->lockTime = '00000000';
        $this->createdBlock = '00000000';
        $this->privateKey = '';
        $this->publicKey = '';
        $this->actionType = '01';
        $this->voter = '0000000000000000000000000000000000000000';
        $this->again = '01';
        $this->candidate = '010000000000000000000000000000000000000000';
        $this->rounds = '01';
        $this->pledge = '0000000000000000000000000000000000000000';
        $this->ip = 'ffffffff';
        $this->port = 'ffff';
        $this->address = '';
    }

}
