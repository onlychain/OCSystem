<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Trading;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;

use MongoDB;

class TradingEncodeModel extends Model
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
    protected $ins = '';

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
     * 区块编号（打包之后添加）
     * @var type
     */
    protected $blockNumber = 0;

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
    protected $lockType = '01';

    /**
     * 交易手续费
     * @var string
     */
    protected $cost = '0000000000000000';

    /**
     * 交易生成时的区块高度
     * @var string
     */
    protected $lockBlock = '00000000';

    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        $this->clearModel();
    }

    /**
     * 序列化交易同时处理交易锁定脚本与解锁脚本
     * @param array $trading
     */
    public function encodeTrading()
    {
        $hex_trading_tem = '';//临时存储序列化的交易，用来做解锁脚本
        $hex_trading = '';
        $encode_head_str = '';//头部固定字符
        $encode_vin_str = '';//交易输入序列化字符
        $encode_vout_str = '';//交易输出序列化字符
        $encode_end_str = '';//交易尾部序列化字符
        //序列化交易头部公共部分
        $encode_head_str .= $this->getVersion();//版本号
        $encode_head_str .= $this->getVinNum();//交易输入数量
        //序列化交易输出部分
        $encode_vout_str .= $this->getVoutNum();
        //序列化交易尾部公共部分
        $encode_end_str = $this->getTime() . $this->getLockTime() . $this->getLockBlock() . $this->getLockType() . $this->getCost() . $this->getIns();
        //拼接交易输出
        if(!empty($this->vout)){
            foreach ($this->vout as $vout_key => $vout_val){
                //拼接交易金额,最小粒度为亿分之一，称为一个汤圆
                $encode_vout_str .= str_pad(dechex(abs($vout_val['value'])), 16, '0', STR_PAD_LEFT);
                //处理锁定脚本，目前只支持一种序列化形式
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
                $encode_vout_str .= str_pad(dechex(strlen($public_key) / 2), 4, '0', STR_PAD_LEFT);
                //拼接锁定脚本
                $encode_vout_str .= $public_key;
            }
        }
        if(count($this->vin) > 500){
            return false;
        }
        //拼接交易输入,方便做验证
        foreach ($this->vin as $vin_key => $vin_val){
            $temp_vin = '';
            $script_hex = '';//存储十六进制后的脚本
            //如果引用的是coinbase
            if(isset($vin_val['coinbase'])){
                //交易txId默认全为0
                $encode_vin_str .= '0000000000000000000000000000000000000000000000000000000000000000';
                //交易索引为1个字节，默认都是ff
                $encode_vin_str .= 'ff';
                //计算内容大小
                $script_hex = bin2hex($vin_val['coinbase']);
                //脚本内容或自定义内容不准大于2个字节
                if(count($script_hex) > 65536)  return returnError('自定义内容过长');
                //拼接脚本序列化内容
                $encode_vin_str .= str_pad(dechex(strlen($script_hex) / 2), 4, '0', STR_PAD_LEFT) . $script_hex;
            }else{
                $script_temp = '';//临时存储解锁脚本
                //如果是正常的交易引入
                $encode_vin_str.= $vin_val['txId'];
                //加入序列
                $encode_vin_str.= $this->encodeLEB128($vin_val['n']);
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
                $encode_vin_str .=str_pad(dechex(strlen($scriptsig_bytecode) / 2), 4, '0', STR_PAD_LEFT) . $scriptsig_bytecode;
            }
        }
        return $encode_head_str . $encode_vin_str . $encode_vout_str . $encode_end_str;
    }

    /**
     * 反序列化交易
     * @param array $trading
     */
    public function decodeTrading(string $trading = '')
    {
        if($trading == ''){
            return returnError('请输入交易!');
        }
        $decode_trading = [];//存储反序列化后的内容
        //解析版本号
        $decode_trading['version'] = hexdec(substr($trading, 0, 2));
        //获取交易输入数量(循环解析)
//        $vin_num = hexdec(substr($trading, 2, 4));
        $str_index = 2;
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
            if($txId == '0000000000000000000000000000000000000000000000000000000000000000'){
                //为coinbase交易
                //索引递进64个字符
                $str_index += 64;
                $decode_trading['vin'][$count_vin]['n'] = hexdec(substr($trading, $str_index, 4));
                //索引递进4个字符
                $str_index += 4;
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
                $decode_trading['vin'][$count_vin]['n'] = hexdec(substr($trading, $str_index, 4));
                //索引递进4个字符
                $str_index += 4;
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
//        $vout_num = hexdec(substr($trading, $str_index, 4));
//        $str_index += 4;
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
        //解析交易时间
        $decode_trading['time'] = hexdec(substr($trading, $str_index, 8));
        //索引递进8个字符
        $str_index += 8;
        //解析交易锁定区块
        $decode_trading['lockTime'] = hexdec(substr($trading, $str_index, 8));
        //索引递进8个字符
        $str_index += 8;
        //解析交易锁定区块
        $decode_trading['lockBlock'] = hexdec(substr($trading, $str_index, 8));
        //索引递进8个字符
        $str_index += 8;
        //解析交易锁定类型
        $decode_trading['lockType'] = hexdec(substr($trading, $str_index, 2));
        //索引递进2个字符1个字节
        $str_index += 2;
        //解析手续费
        $decode_trading['cost'] = hexdec(substr($trading, $str_index, 16));
        //索引递进16个字符
        $str_index += 16;
        //解析自定义信息内容
        $ins_len = hexdec(substr($trading, $str_index, 2));
        //索引递进2个字符
        $str_index += 2;
        $decode_trading['ins'] = substr($trading, $str_index, $ins_len);
        $decode_trading['txId'] = bin2hex(hash('sha256', hex2bin($trading), true));
        return $decode_trading;
    }

    /**
     * 设置交易输入
     * @param type $vin_data
     */
    public function setVin(array $vin_data = [])
    {
        if(empty($vin_data)) return false;
        $this->vin = $vin_data;
        //设置交易输入数量
        $this->vinNum = $this->encodeLEB128(count($vin_data));

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
    public function setVout(array $vout_data = [])
    {
        if(empty($vout_data)){
            $this->vout = [];
            $this->voutNum = '0000';
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

        $ins_hex = bin2hex($ins_data);
        $ins_len = dechex(strlen($ins_hex)) / 2;
        if($ins_len > 0) return false;
        $this->ins = str_pad($ins_len, 2, '0', STR_PAD_LEFT) . $ins_hex;
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
    public function setLockType(int $lock_type = 1)
    {
        if($lock_type >= 255 || $lock_type <= 0){
            return false;
        }
        $hex_type = dechex($lock_type);
        $this->lockType = str_pad($hex_type, 2, '0', STR_PAD_LEFT);
        return $this;
    }

    /**
     * 获取脚本锁定类型
     * @return int
     */
    public function getLockType()
    {
        return $this->lockType;
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
        while ($value != 0) {
            $leb[$pos++] = $value & 0x7F | 0x80;
            $value >>= 7;
        }
        if($pos > 0)
            $leb[$pos -1] &= 0x7F;

        foreach ($leb as $leb_key => $leb_val){
            $str .= str_pad(dechex($leb_val), 2, '0', STR_PAD_LEFT);
        }
        return $str;
    }

    /**
     * 设置交易锁定时的区块时间
     * @param int $this_block
     * @return $this
     */
    public function setLockBlock($this_block = 0)
    {
        $this->lockBlock = str_pad(dechex($this_block), 8, '0', STR_PAD_LEFT);
        return $this;
    }

    public function getLockBlock()
    {
        if($this->lockBlock == '00000000'){
            $top_height = CatCacheRpcProxy::getRpc()->offsetGet('topBlockHeight');
            return str_pad(dechex($top_height), 8, '0', STR_PAD_LEFT);
        }
        return $this->lockBlock;
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
        $this->ins = '00';
        $this->time = "00000000";
        $this->hex = "";
        $this->blockHash = "";
        $this->blockNumber = 0;
        $this->version = 1;
        $this->lockTime = '00000000';
        $this->lockBlock = '00000000';
        $this->privateKey = '';
        $this->publicKey = '';
        $this->lockType = '01';

    }

}
