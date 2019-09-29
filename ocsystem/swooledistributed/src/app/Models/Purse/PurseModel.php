<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易转成十六进制编码
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Purse;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\PurseProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class PurseModel extends Model
{

    /**
     * 设置钱包缓存上限
     * @var int
     */
    private $LimitLens = 1000;

    /**
     * 每个钱包存储的utxo数
     * @var int
     */
    private $TradingLens = 100;

    /**
     * 缓存前缀
     * @var string
     */
    private $PurseCacheName = 'Purse_';

    /**
     * 超时时间,单位是秒
     * @var int
     */
    private $TimeOut = 3600;

    /**
     * 所以缓存名称
     * @var string
     */
    private $IndexCacheName = 'PurseTable';
    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);

    }

    /**
     * 根据key获取值，会判断是否过期
     * @param $address  钱包地址
     * @return mixed
     */
    public function getPurse(string $address = '', $trading = [])
    {
        $assets = $this->getItem($address, $trading);
        if($assets === false || !is_array($assets)) return false;

        return $assets;
    }

    /**
     * 添加或覆盖一个key
     * @param $address  钱包地址
     * @param $trading  交易内容
     * @param $expire   过期时间
     * @return mixed
     */
    public function setPurse($address = '', $trading = [], $expire = 0)
    {
        return $this->setItem($address, $trading, time(), $expire);
    }

    /**
     * 设置包含元数据的信息
     * @param $address
     * @param $trading 交易数据必须是二维数组
     * @param $time
     * @param $expire
     * @return bool
     */
    private function setItem($address = '', $trading = [], $time = 0, $expire = 0)
    {
        //从缓存中获取数据
        $purse = [];
        $purse_table = [];
        //给要新加入的缓存添加时间
        foreach ($trading as $t_key => &$t_val){
            $t_val['time'] = time();
        }
        //清理缓存
        $this->clearPurseExpire();
        $purse_table = CatCacheRpcProxy::getRpc()->offsetGet($this->IndexCacheName);
        //判断缓存是否已经存在
        if($purse_table === false ||
            (empty($purse_table[$address]) &&
            count($purse_table) < $this->LimitLens)){
            //钱包缓存不存在，并且有钱包空位,把数据写入缓存
            CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $address] = $trading;
            //超时时间写入缓存索引表
            $purse_table[$address] = time();
            CatCacheRpcProxy::getRpc()[$this->IndexCacheName] = $purse_table;
        }else{
            //从缓存中获取数据
            $clear_res = $this->clearTradingExpire($address);
            if(!$clear_res['IsSuccess']){
                return ;
            }
            $purse = array_merge($clear_res['Data'], $trading);

            CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $address] = $purse;

        }
        return true;
    }

    /**
     * 从数据库中获取一条或多条交易记录(写入文档)
     * @param $adderss地址
     * @param $trading交易数据
     * @return mixed
     */
    public function getPurseTrading($address = '', $trading = [])
    {
        $match = [
            '_id' => $address,
            'trading' => [
                '$elemMatch' => [
                    'txId' => [
                        '$in' => $trading
                    ]
                ]
            ],
        ];
        $purse_res = ProcessManager::getInstance()
                            ->getRpcCall(PurseProcess::class)
                            ->getPurseAggregation($match);
        if(empty($purse_res['Data'])){
            return false;
        }
        $this->setItem($address, $purse_res['Data']);
        return $purse_res['Data'];
    }

    /**
     * 把交易写入数据库中
     * @param $adderss地址
     * @param $trading交易数据
     * @return mixed
     */
    public function setPurseTrading($address = '', $trading = [])
    {
        //验证账户是否在数据库中
        if(!$this->checkPurseExist($address)){
            //写入数据库
            ProcessManager::getInstance()
                            ->getRpcCall(PurseProcess::class)
                            ->insertPurse(['_id' => $address, 'trading' => $trading]);
        }else{
            $data = ['$push' => ['trading' => $trading]];
//            $data = ['$inc' => ['trading' => $trading]];
            $where = ['_id' => $address];
            ProcessManager::getInstance()
                        ->getRpcCall(PurseProcess::class)
                        ->updatePurse($where, $data);
        }
        var_dump('处理缓存');
        //刷新缓存
        $this->refreshPurse($address, [$trading]);
    }

    /**
     * 把钱包数据批量写入数据库中（根据需求进行优化）
     * @param $adderss地址
     * @param $trading交易数据
     * @return mixed
     * $oneWay
     */
    public function addPurseTradings($purse = [])
    {
        if(empty($purse)){
            return true;
        }
        ProcessManager::getInstance()
                        ->getRpcCall(PurseProcess::class)
                        ->insertPurseMany($purse);
        return true;
    }

    /**
     * 刷新钱包1
     * @param $adderss地址
     * @param $purse新钱包数据
     * @param $del_trading待删除的钱包数据
     * 直接更新整个钱包，需要传入更新的钱包的值
     * @return mixed
     */
    public function rushPurse($address = '', $new_purse = [], $del_trading = [])
    {
        $purse_table = CatCacheRpcProxy::getRpc()->offsetGet($this->IndexCacheName);
        if(empty($new_purse)){
            //如果钱包为空，从钱包列表中删除钱包
            unset($purse_table[$address]);
        }else{
            $purse = CatCacheRpcProxy::getRpc()->offsetGet($this->PurseCacheName . $address);
            if (empty($purse)) {
                //没有数据,先清理列表
                $this->clearPurseExpire();
                //如果钱包缓存有空余，直接赋值当前钱包
                if(count($purse_table) >= $this->LimitLens){
                    $purse_table[$address] = time();
                }
                //如果没有直接执行删除交易方法
            }else{
                //有交易存在,直接刷新该交易
                CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $address] = $new_purse;
            }
        }
        CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $address] = $new_purse;

        //删除待处理交易
        $this->delPurseTrading($address, $del_trading);
        return true;

    }

    /**
     * 刷新钱包缓存
     * @param $adderss地址
     * @param $purse新钱包数据
     * @param $del_trading待删除的钱包数据
     * 直接更新整个钱包，需要传入更新的钱包的值
     * @return mixed
     * @oneWay
     */
    public function refreshPurse($address = '', $trading = [])
    {
        $purse_table = CatCacheRpcProxy::getRpc()->offsetGet($this->IndexCacheName);
        //如果缓存达到上限，或没有当前钱包直接返回
        if(count($purse_table) >= $this->LimitLens || empty($purse_table[$address]))
            return;

        //获取当前钱包并刷新
        $purse = CatCacheRpcProxy::getRpc()->offsetGet($this->PurseCacheName . $address);
        if(count($purse) >= $this->TradingLens)
            return;

        $trading = [];
        if(!empty($trading)){
            //如果有传入交易内容
            foreach ($trading as $pr_key => $pr_val){
                $purse[$pr_val['txId']] = $pr_val;
            }
        }else{
            //没有传入交易内容则随机取差额补上
            $txids = [];
            foreach ($purse as $p_key => $p_val){
                $txids[] = $p_key;
            }
            $where = [
                '_id' => $address,
                'trading' => [
                    '$elemMatch' => [
                        'txId' => [
                            '$nin' => $txids
                        ]
                    ],
                ],
            ];
            $data = [
                'trading' => [
                    '$slice' => [
                        1, $this->TradingLens - count($purse)
                    ]
                ]
            ];
            $purse_res = ProcessManager::getInstance()
                                    ->getRpcCall(PurseProcess::class)
                                    ->getPurseList($where, $data, 1, 1);
            if(empty($purse_res['Data']))    return;

            foreach ($purse_res['Data'][0]['trading'] as $pr_key => $pr_val){
                $purse[$pr_val['txId']] = $pr_val;
            }
        }
        //更新交易
        CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $address] = $purse;

        return true;
    }

    /**
     * 刷新钱包缓存(不做把数据归集到一个文档中)
     * @param $adderss地址
     * @param $purse新钱包数据
     * @param $del_trading待删除的钱包数据
     * 直接更新整个钱包，需要传入更新的钱包的值
     * @return mixed
     * @oneWay
     */
    public function refreshPurseTrading($cache_purse = [])
    {
        if(empty($cache_purse)){
            return true;
        }
        $purse = [];
        $purse_table = CatCacheRpcProxy::getRpc()->offsetGet($this->IndexCacheName);
        //如果缓存达到上限，或没有当前钱包直接返回
        if(count($purse_table) >= $this->LimitLens)    return;
        //循环获取当前钱包并刷新
        foreach ($cache_purse as $cp_key => $cp_val){
            $purse = CatCacheRpcProxy::getRpc()->offsetGet($this->PurseCacheName . $cp_key);
            //如果该账号没有在缓存里，不进行更新
            if($purse == null || count($purse) >= $this->TradingLens) continue;
            //该账号如果在缓存里，用新数据填充
            $count = 1;
            foreach ($cp_val as $cv_key => $cv_val){
                if($count >= $this->TradingLens - count($purse)){
                    break;
                }
                $purse[$cv_val['txId']] = $cv_val;
            }
            CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $cp_key] = $purse;
        }
        return true;
    }

    /**
     * 判断钱包是否存在
     * @param $adderss
     * @return mixed
     */
    public function cheakPurse($adderss = '')
    {
        $value = $this->getPurse($adderss);
        if ($value === false) return false;

        return true;
    }

    /**
     * 删除一个钱包，只删除缓存中的钱包
     * @param $key
     * @return mixed
     */
    public function delete($address = '')
    {
        //删除钱包缓存
        unset(CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $address]);

        //删除索引列表信息
        unset(CatCacheRpcProxy::getRpc()[$this->IndexCacheName][$address]);
        return true;
    }

    /**
     * 从钱包中删除一笔交易(一个文档版)
     * @param $key
     * @oneWay
     */
    public function deletePurseTrading($address = '', $trading = [])
    {
        $txId = [];
        foreach ($trading as $t_key => $t_val){
            $txId[] = $t_val['txId'];
        }
        //把新交易从数据库中删除
        $where = ['_id' => $address];
        $data = ['$pull' => ['txId' => ['$in' => $txId]]];
        ProcessManager::getInstance()
                        ->getRpcCall(PurseProcess::class)
                        ->updatePurse($where, $data);
    }

    /**
     * 从钱包中删除一笔交易
     * @param $key
     * @oneWay
     */
    public function delPurseTrading($address = '', $trading = [])
    {
//        $txId = [];
//        foreach ($trading as $t_key => $t_val){
//            $txId[] = $t_val['txId'];
//        }
        //把新交易从数据库中删除
        $where = ['address' => $address, 'txId' => ['$in' => $trading]];
        var_dump($where);
//        $data = ['$pull' => ['txId' => ['$in' => $txId]]];
        $aaa = ProcessManager::getInstance()
                                ->getRpcCall(PurseProcess::class)
                                ->deletePurse($where);
    }

    /**
     * 清除所有缓存
     * @return mixed
     */
    public function flush()
    {
        //删除所有钱包缓存
        foreach (CatCacheRpcProxy::getRpc()->offsetGet($this->IndexCacheName) as $key){
            unset(CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $key]);
        }

        //删除索引列表
        unset(CatCacheRpcProxy::getRpc()[$this->IndexCacheName]);

        return true;
    }

    /**
     * 获取含有元数据的信息
     * @param $address
     * @return bool|mixed|string
     */
    protected function getItem($address = '', $trading = [])
    {
        $purse = [];
        $txids = [];
        $purse = CatCacheRpcProxy::getRpc()->offsetGet($this->PurseCacheName . $address);
        if(!empty($trading) && $purse != null){
            //两个都不为空，判断需要的数据是否在缓存中
            foreach ($trading as $t_key => $t_val){
                if(isset($purse[$t_val])) continue;

                $txids[] = $t_val;
            }
        }elseif(!empty($trading) && $purse === null){
            //如果没有缓存数据
            $txids = $trading;
        }elseif(empty($trading) && $purse != null){
            return $purse;
        }
        //如果有数据，把数据代入
        $purse_where = ['address' => $address];
        $purse_data = ['_id' => 0];
        $pagesize = $this->TradingLens;
        if(!empty($txids)){
            $purse_where['txId'] = ['$in' => $txids];
            $pagesize = count($trading) + 10;
        }
        $new_purse = $this->getPurseFromMongoDb($purse_where, $purse_data, 1, $pagesize);

        $purse = $purse != null ? array_merge($purse, $new_purse) : $new_purse;
        //如果没有数据
        if(empty($purse)){
            return false;
        }
        //执行设置方法
        $this->setPurse($address, $purse);
        return $purse;
    }

    /**
     * 检查钱包是否过期
     * @param $purse
     * @return bool
     */
    protected function checkPurseExpire($address = '')
    {
        $time = time();
        //获取对应时间
        $last_time = CatCacheRpcProxy::getRpc()->offsetGet($this->PurseCacheName . $address);
        //判断是否过期
        $is_expire = $last_time + $this->TimeOut < $time;
        if ($is_expire) return false;

        return true;
    }

    /**
     * 检查交易是否过期
     * @param $trading
     * @return bool
     */
    protected function checkTradingExpire($trading = [])
    {
        $time = time();
        $is_expire = $trading['time'] + $this->TimeOut < $time;
        if ($is_expire) return false;

        return true;
    }

    /**
     * 剔除缓存里的过期钱包
     * @param $purse
     * @return bool
     */
    protected function clearPurseExpire()
    {
        //获取钱包缓存列表
        $purse_table = CatCacheRpcProxy::getRpc()->offsetGet($this->IndexCacheName);
        if(empty($purse_table)){
            return true;
        }
        $time_out_purse = $new_purse = [];
        $time = time();
        foreach ($purse_table as $pt_key => $pt_val){
            if($pt_val  + $this->TimeOut < $time){
                $time_out_purse[] = $pt_key;
            }
            $new_purse[$pt_key] = $pt_val;
        }
        if(!empty($time_out_purse)){
            //存储新的缓存索引
            CatCacheRpcProxy::getRpc()[$this->IndexCacheName] = $new_purse;

            //删除过期钱包
            foreach ($time_out_purse as $top_key => $top_val){
                unset(CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $top_val]);
            }
        }
        return true;
    }

    /**
     * 剔除钱包内的过期交易
     * @param $trading
     * @return bool
     */
    protected function clearTradingExpire($address = '', $purse = [])
    {
        if(empty($purse)){
            $purse = CatCacheRpcProxy::getRpc()->offsetGet($this->PurseCacheName . $address);
        }
        if(empty($purse)){
            return returnError();
        }
        $time = time();
        foreach ($purse as $p_key => &$p_val){
            if($p_val['time'] + $this->TimeOut < $time){
                unset($p_val);
            }
        }
        if(count($purse) >= $this->TradingLens){
            return returnError();
        }
        CatCacheRpcProxy::getRpc()[$this->PurseCacheName . $address] = $purse;
        //重新整理key
        return returnSuccess($purse);
    }



    /**
     * 从数据库中获取钱包数据，只获取最大上限的交易记录(交易数据在一个文档里面的版本)
     * @param $address
     * @param $trading
     * @return bool
     */
    public function getPurseFromDb($address = '', $trading = [])
    {
        $where = ['_id' => $address];
        $purse = [];
        $purse_res = ProcessManager::getInstance()
                                    ->getRpcCall(PurseProcess::class)
                                    ->getPurseInfo($where);
        if(!empty($purse_res['Data'])){
            $count = 0;
            foreach ($purse_res['Data']['trading'] as $pr_key => $pr_val){
                if($count >= ($this->TradingLens - 1)){
                    if(!empty($trading) &&
                       $pr_val['txId'] == $trading['txId'] &&
                       $pr_val['n'] == $trading['n']){

                       $purse[$pr_val['txId']] = $pr_val;
                       break;
                    }
                    continue;
                }
                if(!empty($trading) &&
                   $pr_val['txId'] == $trading['txId'] &&
                   $pr_val['n'] == $trading['n']){
                    $trading = [];
                }
                $purse[$pr_val['txId']] = $pr_val;
                ++$count;
            }
        }
        return $purse;
    }

    /**
     * 从数据库中获取钱包数据，只获取最大上限的交易记录
     * @param $purse_where  查询条件
     * @param $purse_data  查询数据
     * @return bool
     */
    public function getPurseFromMongoDb($purse_where = [], $purse_data = [], $page = 1, $pagesize = 0, $sort = [])
    {
        $where = [];
        $where = $purse_where;
        $purse = [];
        $purse_res = ProcessManager::getInstance()
                                ->getRpcCall(PurseProcess::class)
                                ->getPurseList($where, $purse_data, $page, $pagesize, $sort);
        if(!empty($purse_res['Data'])){
            foreach ($purse_res['Data'] as $pr_key => $pr_val){
                $purse[$pr_val['txId']] = [
                    'txId'      =>  $pr_val['txId'],
                    'n'         =>  $pr_val['n'],
                    'value'     =>  $pr_val['value'],
                    'reqSigs'   =>  $pr_val['reqSigs'],
                    'lockTime'  =>  $pr_val['lockTime'],
                ];
            }
        }
        return $purse;
    }

    /**
     * 确认钱包是否在数据库中
     * @param $address
     * @return bool
     */
    public function checkPurseExist($address = '')
    {
        $where = ['_id' => $address];
        $purse_res = ProcessManager::getInstance()
                                    ->getRpcCall(PurseProcess::class)
                                    ->getPurseInfo($where);
        if(empty($purse_res['Data'])){
            return false;
        }
        return true;
    }
}
