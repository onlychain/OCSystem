<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易合法性验证
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Trading;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
use BitcoinPHP\BitcoinECDSA\BitcoinECDSA;

class ValidationModel extends Model
{
    /**
     *
     * @var
     */
    private $BitcoinECDSA;
    /**
     * 构造函数，整个项目运行期间只会执行一次
     * BlockHeadModel constructor.
     */
    public function __construct()
    {
        parent::__construct();
        //实例化椭圆曲线加密算法
        $this->BitcoinECDSA = new BitcoinECDSA();
    }

    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
//        $this->MongoDbModel = $this->loader->model("MongoDb/MongoDbModel", $this);
    }

    /**
     * 验证交易是否有效
     * @param array $trading
     */
    public function verifyTrading($trading = array())
    {
        //验证交易是否有足够的币来执行
        if (empty($trading['UTXO'])) {
            return array(
                'IsSuccess' => false,
                "Message"   =>  '没有utxo',
            );
        }
        //获取所有可用的UTXO
        $remaining = $this->traceTradeOrderAll();
        $totalValue = 0;
        $vin = [];
        $vout = [];
        foreach ($trading['UTXO'] as $tutxo_key => $tutxo_val){
            if(isset($remaining[$tutxo_val['txId']][$tutxo_val['n']])){
                $totalValue += $remaining[$tutxo_val['txId']][$tutxo_val['n']]['value'];
                $vin[] = [
                    'txId' => $tutxo_val['txId'],
                    'n' => $tutxo_val['n'],
                ];
            }
        }
        if($totalValue < $trading['trading']['value']){
            return array(
                'IsSuccess' => false,
                "Message"   =>  '金额不足',
            );
        }elseif($totalValue > $trading['trading']['value']){
            $vout[] = [
                'value' => $totalValue - $trading['trading']['value'],
                'address' => $trading['address']
            ];
        }
        $vout[] = [
            'value' => $trading['trading']['value'],
            'address' => $trading['trading']['toAddress'],
        ];
        return array(
            'IsSuccess' => true,
            "Message"   =>  '',
            "Data"      =>  ['vin' => $vin, 'vout' => $vout],
        );
    }

    /**
     * 追溯交易记录(验证交易的合法性)
     * 如果没有提交区块头跟区块高度，就先找交易记录，然后获取区块哈希跟高度
     * @param type $trade交易信息
     */
    public function traceTradeOrder($trade = array())
    {
        $hashIndex = "";//区块哈希索引
        $slectFlag = true;
        $utxoRecode = '';
        $balance = [];
        $firstFilter = [];
        $firstOptions = ['sort' => ['time' => -1], 'limit' => 1000];
        $tradingAggregation = 'tradings.trading';//查询交易库集合
        $blockAggregation = 'blocks.block';//查询区块库集合
        $heightIndex = 0;//区块高度索引
        $remaining = 0;//剩余金额
        if (empty($trade)) {
            return false;
        }
        //存储索引，方便接下来的查询操作
        $hashIndex = $trade['blackHash'] ?? $trade['blackHash'];
        $heightIndex = $trade['blackHeight'] ?? $trade['blackHeight'];
        $utxoRecode = $trade['utxoHash'] ?? $trade['utxoHash'];
        $firstFilter['addresses'] = $trade['fromAddress'] ?? $trade['fromAddress'];
        //用mb_strwidth统计数据，数据限制暂时不写
        while ($slectFlag) {
            //如果有提交区块头跟区块高度，直接从区块高度进行验证查询
            $hashIndex != '' && $firstFilter['blockhash'] = $hashIndex;//如果有hash头，则加入查询条件
            $heightIndex != '' && $firstFilter['height'] = ['$gt' => $heightIndex];//如果有hash头，则加入查询条件
            $utxoRecode != '' && $firstFilter['txid'] = $utxoRecode;//如果有utxo，则加入查询条件
            $firstRecord = array();
            $utxoRecode = '';//utxo记录
            //通过地址查询交易记录
            $firstRecord = $this->MongoDbModel->mongoQuery($tradingAggregation, $firstFilter, $firstOptions);
            if (empty($firstRecord)) {
                $slectFlag = false;
            }
            //判断输入与输出交易，进行账目统计
            foreach ($firstRecord as $fr_key => $fr_val){
                if(!isset($fr_val['vin']->coinbase)){
                    foreach ($fr_val['vin'] as $frv_key => $frv_val){
                        if(!isset($balance[$frv_val['txid']]['vout'])){
                            $slectFlag = false;
                            break;
                        }
                        unset($balance[$frv_val['txid']]['vout']);
                        if(count($balance[$frv_val['txid']]) == 0){
                            unset($balance[$frv_val['txid']]);
                        }
                    }
                }
                foreach ($fr_val['vout'] as $fro_key => $fro_val){
                    $balance[$fr_key][] = [
                        'value' => $fro_val['value'],
                        'asm'   => $fro_val['asm'],
                        'hex'   => $fro_val['hex'],
                    ];
                }
            }
            //把交易加密成UTXO
            $firstFilter = [];
            $hashIndex = $firstRecord['blockhash'];
            $heightIndex = $firstRecord['height'];
            $utxoRecode = $firstRecord['txId'];;
        }
        return $balance;
    }

    /**
     * 追溯交易记录(验证交易的合法性)
     * 从第一个区块开始查询统计utxo
     * @return array|bool
     */
    public function traceTradeOrderAll()
    {
        $slectFlag = true;
        $balance = [];
        $firstFilter = [];
        $tradingAggregation = 'tradings.trading';//查询交易库集合
        $pages = 0;//查询分页范围
        $pagesize = 1000;
        //用mb_strwidth统计数据，数据限制暂时不写
        while ($slectFlag) {
            $firstRecord = array();
            $firstOptions = ['sort' => ['time' => 1], 'limit' => intval($pagesize), 'skip' => intval($pages)];
            //通过地址查询交易记录
            $firstRecord = $this->MongoDbModel->mongoQuery($tradingAggregation, $firstFilter, $firstOptions);
            if (empty($firstRecord)) {
                $slectFlag = false;
                break;
            }
            //判断输入与输出交易，进行账目统计
//            var_dump($firstRecord);
            foreach ($firstRecord as $fr_key => $fr_val){
                if(empty($fr_val->vin->coinbase)){
                    foreach ($fr_val->vin as $frv_key => $frv_val){
                        if(empty($balance[$frv_val->txId][$frv_val->vout])){
                            unset($balance[$frv_val->txId][$frv_val->vout]);
//                            $slectFlag = false;
//                            break;
                        }
                        if(count($balance[$frv_val->txId]) == 0){
                            unset($balance[$frv_val->txId]);
                        }
                    }
                }
                foreach ($fr_val->vout as $fro_key => $fro_val){
                    $balance[$fr_val->txId][$fro_val->n] = [
                        'value' => $fro_val->value,
                        'asm'   => $fro_val->scriptPubKey->asm,
                        'hex'   => $fro_val->scriptPubKey->hex,
                    ];
                }
            }
            $pages += $pagesize;
            $pagesize += $pagesize;
        }
        return $balance;
    }

    /**
     * 验证交易所有权
     * @param string $address
     * @param string $sign
     * @param string $trading
     * @return bool
     */
    public function varifySign($sign = '')
    {
        if(empty($sign)){
            return returnError();
        }
        $check_res = $this->BitcoinECDSA->checkSignatureForRawMessage($sign);
        if(!$check_res['IsSuccess']){
            return returnError('交易有误!', 1003);
        }
        return returnSuccess(json_decode($check_res['Data']['message'], TRUE));
    }

    /**
     * 交易解锁
     * @param array $script
     * @return bool
     */
    public function varifyScript(array $script = [])
    {
        if(empty($script['scrypt_sig'])) return returnError('输入脚本为空!');

        if(empty($script['scrypt_pubkey'])) return returnError('输出脚本为空!');

        if(empty($script['scrypt'])) return returnError('待验证的序列化数据为空!');

        //存储上下文
        $context = script_create_context();
        global $raw_tx;
        //去除脚本
        $subscript = script_remove_codeseparator($script['scrypt_pubkey']);
        $raw_tx = $script['scrypt'];
        script_set_checksig_callback($context, function($subscript) {
            global $raw_tx;
            $msg = hash('sha256', hash('sha256', hex2bin($raw_tx), true), true);
            return $msg;
        });
        if (!script_eval($context, $script['scrypt_sig'])) return returnError('输入脚本验证失败!');

        if (!script_eval($context, $script['scrypt_pubkey'])) return returnError('输出脚本验证失败!');

        if (!script_verify($context)) return returnError('交易解锁失败!');

        return returnSuccess();
    }

    /**
     * 生成交易输出
     * @param string $script
     * @param string $private_key
     * @param string $public_key
     * @return bool
     */
    public function getScriptSig($script = [], string $private_key = '', string $public_key = '')
    {
        if(empty($script)) return returnError('请输入需要加密的序列化交易单.');

        if($private_key == '') return returnError('请输入私钥.');

        if($public_key == '') return returnError('请输入公钥.');

        $scriptSig = [];
        foreach ($script as $s_key => $s_val){
            $msg = hash('sha256', hash('sha256', $s_val, true), true);
            $signature = bin2hex(secp256k1_sign(hex2bin($private_key), $msg)); // 得到签名
            $scriptSig_bytecode = script_compile("[$signature] [$public_key]");
            $scriptSig[] = bin2hex($scriptSig_bytecode);
        }
        //开始加密

        //将结果转成十六进制返回
        return returnSuccess($scriptSig);
    }

    /**
     * 批量序列化交易输出
     * @param array $trading_table
     * @return bool
     */
    public function getScript(array $trading_table = [])
    {
        //存储临时的交易单
        $temp_table = $trading_table;
        $script = [];//存储
        //设置默认交易输出脚本
        $address = bin2hex($trading_table['from']);
        $default_out = "DUP HASH160 [$address] EQUALVERIFY CHECKSIG";
        foreach ($trading_table['tx'] as $tt_key => $tt_val){
            $scriptPubKey_bytecode = script_compile($default_out);
            $temp_script = bin2hex(script_remove_codeseparator($scriptPubKey_bytecode));
            $temp_table['tx'][$tt_key]['scriptSig'] = $temp_script;
            $script[] = json_encode($temp_table);
            $temp_table['tx'][$tt_key]['scriptSig'] = '';
        }
        return returnSuccess($script);
    }

}
