<?php


namespace app\Models\Vote;


use Server\CoreBase\Model;
use MongoDB\Client as MongoClient;

class VoteNoteModel extends Model
{
    /**
     * 基础库
     * @var
     */
    protected $VoteData;

    /**
     * 投票器的异步连接池
     * @var
     */
    protected $VotePool;

    /**
     * 数据库
     * @var
     */
    public $db;
    public $mongo_client;
    public $mongdb_operation;
    public $mongdb_wallet_money;
    public $mongdb_votelist_log;


    /**
     * 钱包
     * @var
     */
    public $vote_wallet_money;
    public $vote_list_log;
    public $vote_block;


    public function __construct()
    {
        parent::__construct();
        $this->VotePool = get_instance()->getAsynPool("voteServer");
        $config = get_instance()->config['MongoBD'];
        $server = sprintf("mongodb://%s:%s", $config['host'], $config['port']);
        $this->mongo_client = new MongoClient($server);
        $this->db = $config['db'];
        $Mongodb = $this->db; //数据库名
        $this->vote_block = 'vote_block';//出快下发集合的txid
        $this->vote_wallet_money = 'vote_wallet_money';
        $this->vote_list_log = 'vote_list_log';//用户投票记录
        $vote_block = $this->vote_block;//集合名称
        $wallet_money = $this->vote_wallet_money;//集合名称
        $votelistlog = $this->vote_list_log;//用户投票记录
        $this->mongdb_operation = $this->mongo_client->$Mongodb->$vote_block;
        $this->mongdb_wallet_money = $this->mongo_client->$Mongodb->$wallet_money;
        $this->mongdb_votelist_log = $this->mongo_client->$Mongodb->$votelistlog;
    }

    /**
     * 当被loader时会调用这个方法进行初始化
     *
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
        $this->VoteData = $this->loader->mysql("votePool", $this);//初始化基础库连接池
    }

    /**
     * 将Mongodb对象数据转换为数组
     * @param array->object    $list   Mongodb对象数据
     * @return array           $list   转换为数组后的数据
     */
    protected function mongoObjectToArray($list)
    {
        if ($list) {
            array_walk($list, function (&$item, $key) {
                if (is_object($item)) $item = get_object_vars($item);
                array_walk($item, function (&$value, &$kkey) {
                    if (is_object($value)) {
                        $value = get_object_vars($value);
                        $value = array_shift($value);
                        if (is_float($value)) {
                            $value = round($value, 5);
                        }
                    }
                });
            });
        }
        return $list;
    }

    /**
     * 获取区间高度查询下发交易tradingInfo(根据查询区块2秒请求)
     * @param array $data
     * @return mixed
     */
    public function getRounds($data = [])
    {
        $res = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/Node/getSystemInfo');
        $res = json_decode($res['body'], true);
        if ($res['code'] == 200) {
            //获取查询节点列表存取数据
            $queryData['height'] = $res['record']['blockHeight'];
            $this->queryBlock($queryData);
        }
        $this->VoteList();
        $this->queryTrading();
        return $res;
    }

    /**
     * 下发的交易tradingInfo存库
     */
    public function queryBlock($data = [])
    {
        $end_result = [];
        $insertData = [];
        $res = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/Block/queryBlock');
        $res = json_decode($res['body'], true);
        if ($res['code'] == 200) {
            if ($res['record']) {
                foreach ($res['record']['tradingInfo'] as $val) {
                    $end_result [] = $this->txIdHash($val);
                    $insertData [] = ['trading' => $this->txIdHash($val),'signadreess'=>$res['record']['signature']];
                }
                //查询txid是否有重复的 txid
                $test = $this->mongdb_operation->find(['trading' => ['$in' => $end_result]])->toArray();
                if ($test) {
                    $new_data = [];
                    $insertData = [];
                    foreach ($test as $te_key => $te_val) {
                        $new_data[] =  $this->txIdHash($te_val->trading);
                    }
                    foreach ($res['record']['tradingInfo'] as $rt_val) {
                        !in_array($this->txIdHash($rt_val), $new_data) && $insertData[] = $this->txIdHash($rt_val);
                    }
                    if (empty($insertData)) return returnError('两个地址是错误的');
                }

            }
            $insert_res = $this->mongdb_operation->insertMany($insertData);
            var_dump('下发的交易之后要存库'.json_encode($insertData));
        }
    }

    /**
     * 用2秒来监听下发交易trading去查询交易的状况
     * 有coinbase是有激励奖励 他的only是他的奖励
     * 有出现2个不一样的txId 要根据txId 和 n去查询是否存在 要更新
     * 不会出现重复也不会出现空值
     * @return bool
     */
    public function queryTrading()
    {
        $end_result = [];
        $res = $this->mongdb_operation->find()->toArray();
        foreach ($res as $key => $val) {
            $list['txId'] = $val->trading;
            $res = $this->Trading(json_encode($list));
            if ($res['code'] == 200) {
                if (is_array($res['record'])){
                    var_dump("<br/>");
                    var_dump("---------------------------------------------------------------------------");
                    var_dump('查询交易trading状况'.json_encode($res['record']));
                    var_dump("<br/>");
                    $end_result[] = $res['record']['trading'];
                    $data =[
                        'actionType'=>$res['record']['actionType'],
                        'createdBlock'=>$res['record']['createdBlock'],
                        'lockTime'=>$res['record']['trading']['lockTime'],
                    ];
                    $this->walletMongodb($end_result,$res['record']['txId'],$data);
                }
            } else {
                return returnError('查询txId请求超时');
            }
        }
    }

    /**
     * 查询出来的交易要根据钱包存库
     * @param array $end_result
     */
    public function walletMongodb($end_result = [],$txIdData = '',$data = [])
    {
        $addressDataMongo = [];
        $txIdList =[];//拼接txId集合
        $coinbase ='';//把coinbase取出来
        $txId = '';//把txid取出来
        $txIdresData = [];
        //通过查询到的是否有存在coinbase有的话更改奖励状态 再把vout的这个金额放进钱包里
        foreach (array_filter($end_result) as $enr_key => $enr_val) {
            var_dump("<br/>");
            var_dump('接收过来的值'.json_encode($enr_val));
            var_dump("<br/>");
            if (array_filter($enr_val['vin'])) {
                foreach ($enr_val['vin'] as $er_key => $er_val) {
                    //vin是否有coinbase
                    if (!empty($er_val['coinbase'])) {
                        //更新是否有激励奖金
                        $coinbase = '有存在';
                    }
                    // vin是否有txId 和 n 是普通交易 是转入的情况
                    if (!empty($er_val['txId'])) {
                        var_dump("<br/>");
                        var_dump('-------------------------------------------------------------');
                        var_dump('是否有txId'.$er_val['txId']);
                        var_dump("<br/>");
                        var_dump('-------------------------------------------------------------');
                        //转入的信息要插入转账记录表
                        $wireData = [
                            $enr_val['txId']['vout'][0]['address'],
                            $enr_val['txId']['vout'][0]['address'],
                            $enr_val['txId']['vout'][0]['value'],
                            4,
                            1,
                            time(),
                            $er_val['txId'],
                            1
                        ];
                        $this->VoteData->insertInto('t_wire_transfer')
                            ->intoColumns(['address', 'from', 'value', 'status', 'is_del', 'created', 'txId', 'transfer'])
                            ->intoValues($wireData)
                            ->query();
                    }
                }
            }
            //vout是否存在数组
            if ($txIdData) {
                var_dump('-------------------------------------------------------------');
                var_dump("<br>");
                var_dump('接收过来txId的值'.$txIdData);
                var_dump("<br>");
                if (is_array($enr_val['vout'])) {
                    foreach ($enr_val['vout'] as $key => $val) {
                        $address = $val['address'];
                        //拼接mongo数据
                        $addressDataMongo  = [
                            'txId'     => $txIdData,
                            'n'        => $val['n'],
                            'value'    => intval($val['value']),
                            'reqSigs'  => $val['reqSigs'],
                            'lockTime' => $data['lockTime'],
                            'address'  => $val['address'],
                            'created'  => time(),
                            'is_del'   => 1,
                            'award'    => $coinbase !=null?1:0,
                            'lockType' => intval($data['actionType']),
                            'createdBlock' => intval($data['createdBlock'])
                        ];
                    }
                }
                $txIdList [] = $txIdData;
                //有coinbase存在的情况下
                if ($coinbase){
                    $this->mongdb_wallet_money->updateOne(['address' => $address,'txId'=>$txIdData], ['$set'=>['award' => 1]]);
                }
            }
        }
        //钱包存取再mongodb数据里
        $walletList_res_mongodb = $this->mongdb_wallet_money->find(['txId' => $txIdData])->toArray();
        if (empty($walletList_res_mongodb)){
            $insert_res =  $this->mongdb_wallet_money->insertOne($addressDataMongo);
            if (!$insert_res) return returnError('添加失败');
        }
    }


    /**
     * 生成trading去查询交易是否存在
     * @param array $data
     * @return mixed
     */
    public function Trading($data = [])
    {
        $res = $this->VotePool->httpClient->setData($data)->coroutineExecute('/Trading/queryTrading');
        $res = json_decode($res['body'], true);
        return $res;
    }


    /**
     * 监听转账的记录
     * 查询转账成功查询的状态
     */
    public function transferInfoMoney()
    {
        $end_result = [];//最终结果
        $transfer = $this->VoteData->select('txId')
            ->from('t_wire_transfer')
            ->where(""," `status` =  1 ","RAW")
            ->query();
        $transfer = $transfer['result'];
        if ($transfer){
            foreach ($transfer as $key => $val){
                $txId['txId'] = $val['txId'];
                //查询交易是否成功
                $res = $this->Trading(json_encode($txId));
                if ($res['code'] == 200){
                    if (is_array($res['record'])){
                        $end_result[] = $res['record'];
                        $this->transferDetails($end_result);
                    }
                }
            }
        }
    }


    /**
     * 交易过程查询转账详情
     * @param array $data
     */
    public function transferDetails($end_result = [])
    {
        foreach (array_filter($end_result) as $enr_key => $enr_val) {
            if (array_filter($enr_val['vin'])) {
                foreach ($enr_val['vin'] as $er_key => $er_val) {
                    // 出现2笔交易txId 要替换
                    if (!empty($er_val['txId'])) {
                        if ($er_val['txId'] != $enr_val['txId']) {
                            //首先要查询转账的记录
                            $res = $this->VoteData->info('txId')
                                ->from('t_wire_transfer')
                                ->where("", " `txId` = '" . $enr_key['txId'] . "'", "RAW")
                                ->query();
                            $res = $res['result'];
                            if ($res) {
                                //存在的话就替换原先交易情况
                                $this->VoteData->update('t_wire_transfer')
                                    ->set('txId', $er_val['txId'])
                                    ->set('status', 4)
                                    ->where("", " `txId` = '" . $enr_key['txId'] . "'", "RAW")
                                    ->query();
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * 获取节点的是否稳定状态 每个字节是有3块是稳定的
     * 区间关联是signadreess(签名地址)也就是区块的节点地址
     */
    public function VoteList()
    {
        $res = $this->VoteData->select('*')
                              ->from('t_pledge_node')
                              ->query();
        $res = $res['result'];
        var_dump('监听查询区块');
        var_dump("------------------------------------------------------------------------------------");
        foreach ($res as $rs_key => $rs_val){
            $Sign = $this->mongdb_operation->find(['signadreess'=>$rs_val['node_address']])->toArray();
            $countSign = count($Sign);
            if ($countSign >= 3){
                $this->VoteData->update('t_pledge_node')
                    ->set('status',1)
                    ->where(""," `node_address` = '".$rs_val['node_address']."'","RAW")
                    ->query();
            }
        }
    }

    /**
     * 下发的交易要转换成2次哈希
     */
    public function txIdHash($trading = '')
    {
        $trading = bin2hex(hash('sha256', hash('sha256', hex2bin($trading), true), true));
        return $trading;
    }

    /**
     * 设置自动投票（126秒）
     * 通过前端传值过来的trading
     */
    public function setVoteUser($data = [])
    {
        $voteList = [];
        $address = [];
        //请求获取多少轮次
        $query = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/Node/getSystemInfo');
        $query = json_decode($query['body'], true);
        if ($query['code'] == 200) {
            //一个轮次只能投票一次
            $votelistLog = $this->mongdb_votelist_log->find(['rounds'=>$data['rounds'],'address'=>$data['address']]);
            if ($votelistLog) return returnError('一个轮次只能投票一次');
            //设置自动投票
            $res = $this->VoteData->select('*')
                ->from('t_pledge_node_user')
                ->query();
            $res = $res['result'];
            foreach ($res as $re_key => $re_val) {
                $voteList[$re_val['address']][] = &$re_val['node_address'];
            }
            foreach ($voteList as $key => $val) {
                //是否有质押交易
                $deal = $this->dealInfo($key);
                if ($deal){
                    $address[$key]['message'] = $deal;
                    $voteNew = new VoteNewsModel();
                    $resultData = $voteNew->receiveAction($address);
                    var_dump('---------------------自动投票的有质押的交易------------------');
                    var_dump($resultData);
                    $end_result =[
                        'rounds'  => $query['record']['rounds'],
                        'address' => $key,
                        'trading' => $deal,
                    ];
                    $res = $this->mongdb_votelist_log->insertOne($end_result);
                }else{
                    //无质押交易的投票
                    $encodeData = [
                        'privateKey'=>'',
                        'publicKey'=>'',
                        'tx' =>[],
                        'to' =>[],
                        'ins' => '',
                        'time' => time(),
                        'lockTime' => 0,
                        'actionType' =>2,
                        'rounds' => $query['record']['rounds'],
                        'voteAgain' => 1,
                        'candidate' => $val,
                        'voter' => $key
                    ];
                    $vote = new VoteModel();
                    $tradingres = $vote->encodeAction($encodeData);
                    $tradingres = json_decode($tradingres,true);
                    if ($tradingres['code'] == 200){
                        $trading['message'] = $tradingres['record'];
                        $voteNew = new  VoteNewsModel();
                        $voteNew ->receiveAction($trading);
                        $end_result =[
                            'rounds'  => $query['record']['rounds'],
                            'address' => $key,
                            'trading' => $tradingres['record'],
                        ];
                        $res = $this->mongdb_votelist_log->insertOne($end_result);
                    }else{
                        var_dump('==============设置自动投票节点=======================');
                        var_dump('设置自动投票节点失败');
                    }

                }
            }
        }


    }

    /**
     * 查看交易生成trading
     * @param string $address
     */
    public function dealInfo($address = ''){
        $res = $this->VoteData->select('*')
                              ->from('t_pledge_deal')
                              ->where(""," `address` ='".$address."' AND is_del = 1 AND txId != '' AND status = 1","RAW")
                              ->query();
        $res = $res['result'];
        if ($res) {
            return $res[0]['txId'];
        }else{
            return '';
        }
    }
}