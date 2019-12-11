<?php

namespace app\Models\Vote;

use Server\CoreBase\Model;
use MongoDB\Client as MongoClient;

class VoteModel extends Model
{
    /**
     * 数据库
     * @var
     */
    public $db;
    public $mongo_client;
    public $mongdb_operation;
    public $mongdb_vote_node;
    public $mongdb_votelist_log;
    /**
     * 命名monogdb
     * @var
     */
    public $vote_wallet_money;
    public $vote_user_node;
    public $vote_list_log;
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

    public function __construct()
    {
        parent::__construct();

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
        $this->VotePool = get_instance()->getAsynPool("voteServer");
        //$this->VoteConfig = get_instance()->config->get('site');
        $config = get_instance()->config['MongoBD'];
        $server = sprintf("mongodb://%s:%s", $config['host'], $config['port']);
        $this->mongo_client = new MongoClient($server);
        $this->db = $config['db'];
        $Mongodb = $this->db; //数据库名
        $this->vote_wallet_money = 'vote_wallet_money';//钱包用户
        $this->vote_user_node = 'vote_user_node';//用户投票节点
        $this->vote_list_log = 'vote_list_log';//用户投票记录
        $collection = $this->vote_wallet_money;//集合名称
        $uservote = $this->vote_user_node;//集合名称
        $votelistlog = $this->vote_list_log;//用户投票记录
        $this->mongdb_operation = $this->mongo_client->$Mongodb->$collection;
        $this->mongdb_vote_node = $this->mongo_client->$Mongodb->$uservote;
        $this->mongdb_votelist_log = $this->mongo_client->$Mongodb->$votelistlog;
    }

    /**
     * 请求创建地址及密钥，公钥(保留着)
     * @param array $data
     * @return bool|mixed
     */
    public function accessServer($data = [])
    {
        $res = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/AuditOrder/createdAccount');
        if ($res) {
            $res = json_decode(json_encode($res['body']), true);
        } else {
            return returnError('请求投票器失败');
        }
        return $res;
    }


    /**
     * 拥有only
     * @param array $address
     * @return bool
     */
    public function selectProus($address = [])
    {
        if (empty($address['address'])) return returnError('地址不能为空');
        $res = $this->walletMoney($address['address']);
        if ($res == 1001) {
            return returnError('用户请求不存在');
        } elseif ($res == 1002) {
            return returnError('请求失败');
        } else {
            return returnSuccess($res, '请求成功');

        }
    }

    /**
     * 请求查看钱包的接口
     * @param $addree
     * @param int $page
     * @param int $pagesize
     *
     * @return bool|float|int
     */
    public function walletMoney($addree, $page = 1, $pagesize = 10)
    {
        $numList = [];//数据取出非锁仓的value值
        $listData = [];
        $mongData = [];
        $data = [
            'address'  => $addree,
            'page'     => $page,
            'pagesize' => $pagesize,
        ];
        $res = $this->VotePool->httpClient->setData(json_encode($data))->coroutineExecute('/Trading/selectProus');
        $res = json_decode($res['body'], true);
        if ($res['code'] == 200) {
            if (is_array($res['record'])) {
                foreach ($res['record'] as $ld_key => $ld_val) {
                    if ($ld_val['lockTime'] == 0) {
                        $numList[] = $ld_val;
                    }
                    //钱包是否存在数据里
                    $where = ['txId' => $ld_val['txId']];
                    $walletListRes = $this->mongdb_operation->find($where)->toArray();
                    if (!$walletListRes) {
                        $mongData [] = [
                            'txId'     => $ld_val['txId'],
                            'n'        => $ld_val['n'],
                            'value'    => $ld_val['value'],
                            'reqSigs'  => $ld_val['reqSigs'],
                            'lockTime' => $ld_val['lockTime'],
                            'address'  => $addree,
                            'created'  => time(),
                            'is_del'   => 1,
                            'award'    => 0,
                            'createdBlock'=> $ld_val['createdBlock'],
                            'actionType' => $ld_val['actionType']
                        ];
                    }
                }
                if ($mongData) {
                    $insert_res = $this->mongdb_operation->insertMany($mongData);
                    if (!$insert_res) return returnError('添加失败');
                }
                $onlyMony['onlyMony'] = array_sum(array_column($numList, 'value')) / 100000000;
                //查看钱包的数据
                $where = ['address' => $addree,'award' => 0,'lockTime'=>0];
                $walletData = $this->mongdb_operation->find($where)->toArray();
                $walletData = $this->mongoObjectToArray($walletData);
                foreach ($walletData as $wa_key => $wa_val) {
                    $listData[$wa_key]['id'] = $wa_key + 1;
                    $listData[$wa_key]['currencyName'] = 'only';
                    $listData[$wa_key]['balanceNum'] = intval($wa_val['value']) / 100000000;
                    $listData[$wa_key]['balanceCNY'] = 0;
                }
            }
            $useraddress_res = $this->VoteData->info('*')
                ->from('t_user_address')
                ->where("", " `address` = '".$addree."'", "RAW")
                ->query();
            $useraddress_res = $useraddress_res['result'];
            if ($useraddress_res) {
                $onlyMony['status'] = $useraddress_res['status'] == 1 ? true : false;
                $pledgeTime = $useraddress_res['equity_time'] == 0? 0:date('Y-m-d',$useraddress_res['equity_time']);
                $unlockTime = $useraddress_res['equity_time'] == 0? 0:date('Y-m-d',$useraddress_res['equity_time']+365*24*60*60) ?? 0;
            } else {
                $onlyMony['status'] = false;
                $unlockTime = '';
                $pledgeTime = '';
            }
            $resData = [
                'list'   => $listData,
                'isOpenRights' => $onlyMony['status'],
                'totalAssets'   => $onlyMony['onlyMony'],
                'unlockTime' => $unlockTime ?? '',
                'pledgeTime' => $pledgeTime ?? ''
            ];
            return $resData;
        } else {
            return 1002;
        }
    }


    /**
     * 生成序列化接口
     * @param array $data
     */
    public function encodeTrading($data = [])
    {
        if (empty($data)) return returnError('数组不能为空');
        $res = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/Trading/encodeTrading');
        return $res;
    }

    /**
     * 新版生成序列化接口
     * 转账和开通权益 actionType 1:转账 2:质押投票 3:超级节点 4:开通权益
     * @param array $data
     */
    public function encodeAction($data = [])
    {
        if (empty($data)) return returnError('数组不能为空');
        $res = $this->VotePool->httpClient->setData($data)->coroutineExecute('/Test/encodeAction');
        return $res;
    }


    /**
     * 提交交易
     * @param array $data
     */
    public function receivingTransactions($data = [])
    {
        if (empty($data)) return returnError('数组不能为空');
        $res = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/Trading/receivingTransactions');
        return $res;
    }

    /**
     * 生成交易
     * @param array $data
     */
    public function createTrading($data = [])
    {
        if (empty($data)) return returnError('数组不能为空');
        $res = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/Trading/createTrading');
        return $res;
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
     * 添加用户地址
     * @param $address
     * @return bool
     */
    public function insertAddress($address)
    {
        //地址不能重复
        $result = $this->VoteData->info('*')
            ->from('t_user_address')
            ->where("", " `address` = '" . $address . "'", 'RAW')
            ->query();
        $result = $result['result'];
        if (empty($result)) {
            $data = [
                $address,
                time()
            ];
            $res = $this->VoteData->insertInto('t_user_address')
                ->intoColumns(['address', 'created'])
                ->intoValues($data)
                ->query();
            if ($res) {
                return returnSuccess('', '添加地址成功');
            } else {
                return returnError('添加地址失败');
            }
        } else {
            return returnError('地址已存在');
        }
    }


    /**
     * 下发的交易要转换成2次哈希
     */
    public function txIdHash($trading = '')
    {
        $trading = bin2hex(hash('sha256', hash('sha256', hex2bin($trading), true), true));
        // hash('ripemd160', hash('sha256', hex2bin($trading), true));//给的公钥生成地址
        return $trading;
    }

    /**
     * 所有提交交易的入口
     * 投票，质押，转账，开通权益
     * @param array $message
     * @return bool
     */
    public function receiveAction($message = []){
        if (empty($message)) return returnError('交易序列化不能为空');
        $res = $this->VotePool->httpClient->setQuery($message)->coroutineExecute('/Action/receiveAction');
        return $res;
    }



    /**
     *                                        以上都是请求接口
     * =====================================================================================================================
     */




    /**
     * 转账交易(有问题)要保留
     * @param array $address
     * @param int $page
     * @param int $pagesize
     *
     * @return bool
     */
    public function wireTransfer($address = [])
    {
        $end_result = [];
        $moneyData = [];//拼接钱包
        if (empty($address['address'])) return returnError('请给我地址');
        if (empty($address['privateKey'])) return returnError('请给我私钥');
        if (empty($address['publicKey'])) return returnError('请给我公钥');
        if (empty($address['from'])) return returnError('请输入发送者地址');
        if (empty(intval($address['value']))) return returnError('请输入only金额');
        if (strstr($address['from'],'oc')){
            $address['from']  = substr($address['from'],2);
        }
        //查看钱包的数据
        $txId = $this->getWalletMoney($address['address'],intval($address['value']) * 100000000);
        if (!$txId['IsSuccess']){
            return  returnError($txId['Message']);
        }else{
            $txId = $txId['Data'];
        }
        foreach ($txId as $key => $value) {
            $moneyData[$key]['txId'] = $value['txId'];
            $moneyData[$key]['n'] = $value['n'];
            $moneyData[$key]['scriptSig'] = $value['reqSigs'];
        }
        $toData = [
            [
                'address' => $address['address'],
                'value'   => intval($address['value']) * 100000000,
                'type'    => 1,
            ]
        ];
        $end_result['privateKey'] = $address['privateKey'];
        $end_result['publicKey'] = $address['publicKey'];
        $end_result['tx'] = $moneyData;
        $end_result['from'] = $address['from'];
        $end_result['to'] = $toData;
        $end_result['ins'] = '';
        $end_result['time'] = 0;
        $end_result['lockTime'] = 0;
        $end_result['actionType'] = 4;
        $res = $this->encodeAction(json_encode($end_result));
        $res = json_decode($res['body'], true);
        if ($res['code'] == 200) {
            //取出序列化
            $trading['message'] = $res['record'];
            $trading['txId'] = bin2hex(hash('sha256', hash('sha256', hex2bin($res['record']), true), true));
        } else {
            return returnError('获取序列化请求超时');
        }
        $result = $this->receiveAction($trading);
        $result = json_decode($result['body'], true);
        if ($result['code'] == 200) {
            //转账交易存取数据库
            $wireData = [
                $address['address'],
                $end_result['from'],
                intval($address['value']),
                $trading['message'],
                1,
                time(),
                $trading['txId'],
                $address['remark']
            ];
            $this->insertWireTrading($wireData);
            //提交成功要把钱包的txId给删除
            $this->walletMoneyDetel($moneyData);
            return returnSuccess('', '打包中');
        } else {
            //转账交易存取数据库
            $wireData = [
                $address['address'],
                $end_result['from'],
                intval($address['value']),
                $trading['message'],
                2,
                time(),
                $trading['txId'],
                $address['remark']
            ];
            $this->insertWireTrading($wireData);
            return returnError($result['msg']);
        }

    }


    /**
     * 转账交易添加数据库
     *
     * @param array $data
     */
    public function insertWireTrading($data = [])
    {
        if (empty($data)) return returnError('数组不能为空');
        $res = $this->VoteData->insertInto('t_wire_transfer')
            ->intoColumns(['address', 'from', 'value', 'trading', 'status', 'created','txId','remark'])
            ->intoValues($data)
            ->query();
        if (!$res) return returnError('添加失败');

    }

    /**
     * 开通权益的状态(需要传私钥和密钥)
     * @param array $data
     * @return bool
     */
    public function equity($data = [])
    {
        $end_result = [];//最终结果
        $moneyData = [];
        if (empty($data['address'])) return returnError('地址不能为空');
        if (empty($data['privateKey'])) return returnError('私钥不能为空');
        if (empty($data['publicKey'])) return returnError('公钥不能为空');
        //首次开通权益的时候，要把地址存库
        $this->insertAddress($data['address']);
        //获取钱包地址列表
        $data['value'] = 500;
        //判断该用户是否存在
        $result = $this->VoteData->info('*')
            ->from('t_user_address')
            ->where("", "`address` = '" . $data['address'] . "'", 'RAW')
            ->query();
        $result = $result['result'];
        if ($result) {
            //查看钱包是否存在锁仓的时间不算
            $txId = $this->getWalletMoney($data['address'],$data['value'] * 100000000);
            if (!$txId['IsSuccess']){
                return  returnError($txId['Message']);
            }else{
                $txId = $txId['Data'];
            }
            foreach ($txId as $key => $value) {
                $moneyData[$key]['txId'] = $value['txId'];
                $moneyData[$key]['n'] = $value['n'];
                $moneyData[$key]['scriptSig'] = $value['reqSigs'];
            }
            $toData = [
                [
                    'address' => $data['address'],
                    'value'   => intval($data['value']) * 100000000,
                    'type'    => 1,
                ]
            ];
            $end_result['privateKey'] = $data['privateKey'];
            $end_result['publicKey'] = $data['publicKey'];
            $end_result['tx'] = $moneyData;
            $end_result['from'] = $data['address'];
            $end_result['to'] = $toData;
            $end_result['ins'] = '';
            $end_result['time'] = time();
            $end_result['lockTime'] = time() + 365 * 24 * 60 * 60;//开通权益质押一年
            $end_result['actionType'] = 4;
            $res = $this->encodeAction(json_encode($end_result));
            $res = json_decode($res['body'], true);
            if ($res['code'] == 200) {
                //取出序列化
                $trading['message'] = $res['record'];
                $trading['txId'] = bin2hex(hash('sha256', hash('sha256', hex2bin($res['record']), true), true));
            } else {
                return returnError('获取序列化请求超时');
            }
            $result = $this->receiveAction($trading);
            $result = json_decode($result['body'], true);
            if ($result['code'] == 200) {
                //转账交易存取数据库
                $wireData = [
                    $data['address'],
                    $end_result['from'],
                    intval($data['value']),
                    $trading['message'],
                    1,
                    time(),
                    $trading['txId'],
                    $data['remark'] ?? ''
                ];
                $this->insertWireTrading($wireData);
                //提交成功要把钱包的txId给删除
                $this->walletMoneyDetel($moneyData);
                //更新开通权益的状态
                $res = $this->VoteData->update('t_user_address')
                    ->set('status', 1)
                    ->set('equity_time', time())
                    ->where('', " `address` = '" . $data['address'] . "'", 'RAW')
                    ->query();
                $resultList['pledgeTime'] = date('Y-m-d H:i:s', time());
                $resultList['unlockTime'] = date('Y-m-d H:i:s', time() + 365 * 24 * 60 * 60);
                if ($res) return returnSuccess($resultList, '权益开通');
            } else {
                //转账交易存取数据库
                $wireData = [
                    $data['address'],
                    $end_result['from'],
                    intval($data['value']),
                    $trading['message'],
                    2,
                    time(),
                    $trading['txId'],
                    $data['remark'] ?? ''
                ];
                $this->insertWireTrading($wireData);
                return returnError($result['msg']);
            }
        } else {
            return returnError('该地址不存在');
        }
    }


    /**
     * 开通权益的状态
     * @param array $data
     * @return bool
     */
    public function equitys($data = [])
    {
        $end_result = [];//最终结果
        if (empty($data['address'])) return returnError('地址不能为空');
        if (empty($data['trading'])) return returnError('序列号不能为空');
        //首次开通权益的时候，要把地址存库
        $this->insertAddress($data['address']);
        //获取钱包地址列表
        $data['value'] = 500;
        //判断该用户是否存在
        $result = $this->VoteData->info('*')
            ->from('t_user_address')
            ->where("", "`address` = '" . $data['address'] . "'", 'RAW')
            ->query();
        $result = $result['result'];
        if ($result) {
            //提交交易
            $tranding['message'] = $data['trading'];
            $results = $this->receiveAction($tranding);
            $results = json_decode($results['body'], true);
            if ($results['code'] == 200) {
                if ($results['record']['IsSuccess'] == false) {
                    return returnSuccess('', $results['Message']);
                } else {
                    //开通权益
                    $res = $this->VoteData->update('t_user_address')
                        ->set('status', 1)
                        ->set('equity_time', time())
                        ->where('', " `address` = '" . $data['address'] . "'", 'RAW')
                        ->query();
                    $resultList['pledgeTime'] = date('Y-m-d H:i:s', time());
                    $resultList['unlockTime'] = date('Y-m-d H:i:s', time() + 365 * 24 * 60 * 60);
                    //转账交易存取数据库
                    $wireData = [
                        $data['address'],
                        $data['address'],
                        intval($data['value']),
                        $data['trading'],
                        1,
                        time(),
                        $this->txIdHash($data['trading'])
                    ];
                    $this->insertWireTrading($wireData);
                    if ($res) return returnSuccess($resultList, '权益开通');
                }
            } else {
                //转账交易存取数据库
                $wireData = [
                    $data['address'],
                    $data['address'],
                    intval($data['value']),
                    $data['trading'],
                    2,
                    time(),
                    $this->txIdHash($data['trading'])
                ];
                $this->insertWireTrading($wireData);
                return returnError($results['msg']);
            }
        } else {
            return returnError('该地址不存在');
        }
    }

    /**
     * 查询区间高度，系统时间，已投
     * @return bool
     */
    public function getCountOnly($address = '')
    {
        $end_result = [];
        if (empty($address)) return returnError('请给我地址');
        //查询当前轮次
        $rounds = $this->getRounds();
        if ($rounds['code'] == 200) {
            $end_result['rounds'] = $rounds['record']['rounds'];
            $end_result['sysTime'] = $rounds['record']['sysTime'];
            $end_result['thisTime'] = floor($rounds['record']['thisTime']/126*100);
            $end_result['blockHeight'] = $rounds['record']['blockHeight'];//区间高度
        }
        //查询已投的数量
        $count = $this->VoteData->select('*')
            ->from('t_user_vote')
            ->query();
        $count = $count['result'];
        $end_result['countVote'] = count($count) ?? 0;
        //拥有only的奖励
        $countonly = $this->VoteData->select('*')
            ->from('t_user_vote')
            ->where(""," `vote` = '".$address."'","RAW")
            ->query();
        $countonly = $countonly['result'];
        $end_result['onlyAward'] =  array_sum(array_column($countonly,'value'))/100000000 ?? 0;
        if ($end_result['onlyAward'] == 0){
            $end_result['onlyType'] = 1;
        }else{
            $end_result['onlyType'] = 2;
        }
        return returnSuccess($end_result, '获取成功');
    }

    /**
     * 获取系统时间、轮次、轮次时间
     * @param array $data
     * @return mixed
     */
    public function getRounds($data = [])
    {
        $res = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/Node/getSystemInfo');
        $res = json_decode($res['body'], true);
        if ($res['code'] == 200) {
            //获取查询节点列表存取数据
            $queryData['rounds'] = $res['record']['rounds'];
            $this->insertNoteList($queryData);
        }
        return $res;
    }

    /**
     * 请求获取节点列表的数据
     * @param array $data
     */
    public function insertNoteList($data = [])
    {
        $NoteList = [];//获取节点列表统计
        $res = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/Node/getNodeList');
        $res = json_decode($res['body'], true);
        if ($res['code'] == 200) {
            foreach ($res['record'] as $re_key => $re_val) {
                foreach ($re_val['pledge'] as $key => $val) {
                    $NoteList[$key]['name'] = '节点名称' . md5(time());
                    $NoteList[$key]['content'] = '节点内容节点内容' . md5(time());
                    $NoteList[$key]['value'] = $val['value'];
                    $NoteList[$key]['txId'] = $val['txId'];
                    $NoteList[$key]['lockTime'] = $val['lockTime'];
                    $NoteList[$key]['node_address'] = $re_val['address'];
                    $NoteList[$key]['created'] = time();
                    $NoteList[$key]['votecount'] = $re_val['totalVote'];
                    //存取节点数据
                    $this->insertAddNote($NoteList, $val['txId']);
                }
                //存取投票数据
                if ($re_val['voters'] != null) {
                    $this->insertVoteList($re_val['voters'], $re_val['address'], $data['rounds']);
                }
            }
        }
    }

    /**
     * 保存节点数据
     *
     * @param array $data
     * @param $txId
     *
     * @return bool
     */
    public function insertAddNote($data = [], $txId)
    {
        if (empty($txId)) return returnError('txid 没有数据');
        //根据txId值保留一个
        $txId_res = $this->VoteData->info('*')
            ->from('t_pledge_node')
            ->where("", "`txId` = '" . $txId . "'", "RAW")
            ->query();
        $txId_res = $txId_res['result'];
        if (!$txId_res) {
            $res = $this->VoteData->insertInto('t_pledge_node')
                ->intoColumns(['name', 'content', 'value', 'txId', 'lockTime', 'node_address', 'created', 'votecount'])
                ->intoValues($data)
                ->query();
            if (!$res) return returnError('添加失败');
        }
    }


    /**
     * 保存投票者数据
     *
     * @param array $data
     * @param string $node_address
     * @param $rounds
     *
     * @return bool
     */
    public function insertVoteList($data = [], $node_address = '', $rounds)
    {
        $resData = [];
        if (empty($data)) returnError('投票数据不能为空');
        foreach ($data as $key => $val) {
            $resData[] = [
                'vote' => $val['address'],
                'value' => $val['value'],
                'node_address' => $node_address,
                'rounds' => $rounds,
                'created' => time(),
            ];
        }
        $res = $this->mongdb_vote_node->insertMany($resData);
        //添加数据库
        $this->VoteData->insertInto('t_user_vote')
            ->intoColumns(['vote','value','node_address','rounds','created'])
            ->intoValues($resData)
            ->query();
        if (!$res) return  returnError('用户投票节点失败');
    }

    /**
     * 获取节点投票列表
     *
     * @param string $type
     *
     * @return bool
     */
    public function getVoteList()
    {
        $end_result = [];
        $result = $this->VoteData->select('*')
            ->from('t_pledge_node')
            ->query();
        $result = $result['result'];
        foreach ($result as $key => $value) {
            $end_result[$key]['id'] = $key + 1;
            $end_result[$key]['name'] = $value['name'];
            $end_result[$key]['content'] = $value['content'];
            $end_result[$key]['value'] = intval($value['value']);
            $end_result[$key]['votecount'] = intval($value['votecount']);
            $end_result[$key]['nodeAddress'] = $value['node_address'];
            $end_result[$key]['status'] = intval($value['status']);
            $end_result[$key]['click'] = false;
        }
        $resultData = [
            'list' => $end_result,
        ];
        return returnSuccess($resultData, '获取成功');
    }

    /**
     * 随机打乱
     *
     * @param $list
     *
     * @return array
     */
    public function shuffle_assoc($list)
    {
        if (!is_array($list)) {
            return $list;
        }
        $keys = array_keys($list);
        shuffle($keys);
        $random = array();
        foreach ($keys as $key) {
            $random[] = $list[$key];
        }
        return $random;
    }

    /**
     * 开始投票（保留着）
     * 这个是通过传递过来公钥和私钥 生成trading
     * @param array $data
     */
    public function surrenders($data = [])
    {
        $end_result = [];
        if (empty($data['privateKey'])) return returnError('请给我私钥');
        if (empty($data['publicKey'])) return returnError('请给我公钥');
        if (empty($data['address'])) return returnError('请给我地址');
        if (empty($data['rounds'])) return returnError('请给我当前几轮');
        //一个轮次只能投票一次
        $votelistLog = $this->mongdb_votelist_log->find(['rounds'=>$data['rounds'],'address'=>$data['address']])->toArray();
        if ($votelistLog) return returnError('一个轮次只能投票一次');
        $list['privateKey'] = $data['privateKey'];
        $list['publicKey'] = $data['publicKey'];
        $list['from'] = $data['address'];
        $list['address'] = $data['address'];
        //查看钱包的数据
        $where = ['address' => $data['address']];
        $txId = $this->mongdb_operation->find($where)->toArray();
        $txId = $this->mongoObjectToArray($txId);
        $onlyMony = array_sum(array_column($txId, 'value')) / 100000000;
        $list['value'] = $onlyMony;
        if (!$txId) {
            $this->walletMoney($data['address']);
            return returnError('该用户没有钱包');
        } else {
            //生成trading
            $voteData =[
                'round' => $data['rounds'] + 2,
                'voteAgain' => 2,
                'candidate' => $data['voter'],
                'voter' => $data['address']
            ];
            $trading = $this->getEncodeTrading($voteData,$list);
            if (!$trading['IsSuccess']){
                return returnError($trading['Message']);
            }else{
                $end_result['message'] = $trading['Data'];
                $query = $this->receiveAction($end_result);
                $res = json_decode($query['body'], true);
                if ($res['code'] == 200) {
                    //用户投票的记录存起来
                    $this->voteListLog($voteData,$trading);
                    return returnSuccess('', '请求成功');
                } else {
                    return returnError($res['msg']);
                }
            }
        }
    }


    /**
     * 开始投票(传递过来有trading)
     * @param array $data
     * @return bool
     */
    public function surrender($data = [])
    {
        $end_result = [];
        if (empty($data['trading'])) return returnError('请给我序列号');
        if (empty($data['noce'])) return returnError('请给我交易随机值');
        if (empty($data['address'])) return returnError('请给我地址');
        if (empty($data['rounds'])) return returnError('请给我当前几轮');
        //一个轮次只能投票一次
        $votelistLog = $this->mongdb_votelist_log->find(['rounds'=>$data['rounds'],'address'=>$data['address']])->toArray();
        if ($votelistLog) return returnError('一个轮次只能投票一次');
        //查看钱包的数据
        $where = ['address' => $data['address']];
        $txId = $this->mongdb_operation->find($where)->toArray();
        $txId = $this->mongoObjectToArray($txId);
        if (!$txId) {
            $this->walletMoney($data['address']);
            return returnError('该用户没有钱包');
        } else {
            //生成trading
            $end_result['message'] = $data['trading'];
            $end_result['rounds'] = $data['rounds'] + 2;
            $end_result['noder'] = $data['voter'];
            $end_result['pledge']['trading'] = $data['trading'];
            $end_result['pledge']['noce'] = $data['noce'];
            $end_result['pledge']['renoce'] = $data['renoce'] ?? '';
            $end_result['voteAgain'] = 2;
            //请求投票接口
            $query = $this->VotePool->httpClient->setQuery($end_result)->coroutineExecute('/Vote/vote');
            $res = json_decode($query['body'], true);
            if ($res['code'] == 200) {
                $voteData = [
                    'rounds' => $data['rounds'],
                    'address' => $data['address'],
                    'trading' => $data['trading']
                ];
                $this->voteListLog($voteData);
                return returnSuccess($res['record'], '请求成功');
            } else {
                return returnError($res['msg']);
            }
        }
    }


    /**
     * 获取节点详情
     * @param string $address
     */
    public function getVoteDetail($address = '')
    {
        if (empty($address)) return returnError('节点绑定地址不能为空');
        $res = $this->VoteData->info('name,content')
            ->from('t_pledge_node')
            ->where("", " `node_address` = '" . $address . "'", "RAW")
            ->query();
        $res = $res['result'];
        if ($res) {
            return returnSuccess($res, '获取成功');
        } else {
            return returnError('获取失败');
        }
    }

    /**
     * 查看投票规则
     * @return array
     */
    public function getVoteRule()
    {
        $config['voteName'] = get_instance()->config->get('goods.shopLing_name');
        return [
            'IsSuccess' => true,
            'Data'      => $config,
        ];
    }

    /**
     * 质押交易之后存取(废弃)
     * @param array $data
     */
    public function inserPledgeDeal($data = [], $address = [], $onlyMoney)
    {
        $res = $this->createTrading($data);
        $res = json_decode($res['body'], true);
        if ($res['code'] == 200) {
            //质押交易记录
            $end_result = [
                $onlyMoney,
                $address['address'],
                $res['record']['time'],
                $res['record']['noce'],
                $onlyMoney / 3000000,
                $res['record']['lockTime'],
                time(),
                1,
                $res['record']['tx'][0]['txId'],
                $res['record']['tx'][0]['scriptSig']
            ];
//            var_dump($end_result);
            $result = $this->VoteData->insertInto('t_pledge_deal')
                ->intoColumns(['value', 'address', 'time', 'noce', 'pledge_token', 'locktime', 'created', 'is_del','txId','scriptSig'])
                ->intoValues($end_result)
                ->query();
            //请求质押交易的序列化
            // $trading = $this->encodeTrading($res);
            if ($result['result']) {
                return returnSuccess('', '质押成功');
            } else {
                return returnError('质押失败');
            }
        } else {
            return returnError($res['msg']);
        }
    }

    /**
     * 质押记录
     *
     * @param $address
     *
     * @return bool
     */
    public function getPledgeList($address,$page,$pagesize = 20,$sort,$type)
    {
        $end_result = [];//最终结果
        if (empty($address)) return returnError('请给我用户地址');
        if (empty($page)) return returnError('请给我页数');
        if ($sort == 1) {
            $res = $this->VoteData->list('*', $page, $pagesize)
                ->from('t_pledge_deal')
                ->where("", " `address` = '" . $address . "' AND is_del = 1 ", "RAW")
                ->orderBy("value", 'DESC')
                ->query();
        }else{
            $res = $this->VoteData->list('*', $page, $pagesize)
                ->from('t_pledge_deal')
                ->where("", "`address` = '" . $address . "' AND is_del = 1 ", "RAW")
                ->orderBy("value", 'ASC')
                ->query();
        }
        if ($type == 1) {
            $res = $this->VoteData->list('*',$page,$pagesize)
                ->from('t_pledge_deal')
                ->where("", " `address` = '" . $address . "' AND is_del = 1 ", "RAW")
                ->orderBy('time', 'DESC')
                ->query();
        } else {
            $res = $this->VoteData->list('*',$page,$pagesize)
                ->from('t_pledge_deal')
                ->where("", " `address` = '" . $address . "'AND is_del = 1 ", "RAW")
                ->orderBy('time', 'ASC')
                ->query();
        }
        $pageinfo = $res['pageinfo'];
        $res = $res['result'];
        foreach ($res as $key => $val) {
            $end_result[$key]['id'] = $key + 1;
            $end_result[$key]['value'] = intval($val['pledge_token']);
            $end_result[$key]['time'] = date('Y-m-d H:i:s', $val['time']);
        }
        $resultData = [
            'list' => $end_result,
            'pageInfo' =>$pageinfo
        ];
        return returnSuccess($resultData, '质押列表');
    }

    /**
     * 质押页面
     *
     * @param $address
     *
     * @return bool
     */
    public function getPledgeInfo($address,$page, $pagesize = 20)
    {
        $end_result = [];//最终结果
        $list = [];
        $user = [];
        if (empty($address)) return returnError('请给我用户地址');
        if (empty($page)) return returnError('请给我页数');
        var_dump($address);
        $res = $this->VoteData->list('*',$page, $pagesize)
            ->from('t_pledge_node')
            ->query();
        $pageinfo = $res['pageinfo'];
        $res = $res['result'];
        foreach ($res as $key => $val) {
            $list[$key]['id'] = $key + 1;
            $list[$key]['value'] = intval($val['value']);
            $list[$key]['nodeAddress'] = $val['node_address'];
            //查找排名第几个
            if ($address == $val['node_address']) {
                $user['id'] = $val['id'] ?? '';
                $user['value'] = intval($val['value']) ?? '';
                $user['address'] = $val['node_address'] ?? '';
            }
        }
        //默认
        $user['id'] = 1;
        $user['value'] = 0;
        $user['address'] = "";
        $resultData = [
            'list' => $list,
            'me' => $user,
            'totalOnly' => array_sum(array_column($res, 'value')) / 100000000,
            'pageInfo' =>$pageinfo
        ];
        return returnSuccess($resultData);
    }

    /**
     * 获取质押的解锁时间
     *
     * @param string $address
     */
    public function getPledgeTimes($address = '', $sort = 1, $type = 1, $page, $pagesize = 20)
    {

        $end_result = [];
        //获取当前高度
        $top_block_height  = $this->getRounds();
        if (empty($address)) return returnError('地址不能为空');
        if (empty($page)) return returnError('页数不能为空');
        if ($sort == 1) {
            $res = $this->VoteData->list('*',$page,$pagesize)
                ->from('t_pledge_deal')
                ->where("", " `address` = '" . $address . "'", "RAW")
                ->orderBy('value', 'DESC')
                ->query();
            $pageinfo = $res['pageinfo'];
            $res = $res['result'];
        } else {
            $res = $this->VoteData->list('*',$page,$pagesize)
                ->from('t_pledge_deal')
                ->where("", " `address` = '" . $address . "'", "RAW")
                ->orderBy('value', 'ASC')
                ->query();
            $pageinfo = $res['pageinfo'];
            $res = $res['result'];
        }
        if ($type == 1) {
            $res = $this->VoteData->list('*',$page,$pagesize)
                ->from('t_pledge_deal')
                ->where("", " `address` = '" . $address . "'", "RAW")
                ->orderBy('time', 'DESC')
                ->query();
            $pageinfo = $res['pageinfo'];
            $res = $res['result'];
        } else {
            $res = $this->VoteData->list('*',$page,$pagesize)
                ->from('t_pledge_deal')
                ->where("", " `address` = '" . $address . "'", "RAW")
                ->orderBy('time', 'ASC')
                ->query();
            $pageinfo = $res['pageinfo'];
            $res = $res['result'];
        }
        foreach ($res as $key => $val) {
            $end_result[$key]['id'] = $key + 1;
            $end_result[$key]['value'] = intval($val['pledge_token']);
            $end_result[$key]['time'] = (floor($val['value'] / 100000000) * 300) + $top_block_height['record']['blockHeight'];
        }
        $resultData = [
            'list' => $end_result,
            'pageInfo' => $pageinfo
        ];
        return returnSuccess($resultData, '获取成功');
    }

    /**
     * 转账记录
     * @param array $address
     * @param $page
     * @param $pagesize 默认为20条数据
     *
     * @return bool
     */
    public function transferList($address = [],$page,$pagesize = 20)
    {
        $end_result = [];
        if (empty($address['address'])) return returnError('请给我地址');
        if (empty($address['type']))  return returnError('请给我类型');
        switch ($address['type']){
            case 1:
                $where = " `address` = '" . $address['address'] . "'";
                break;
            case 2:
                $where =" `address` = '" . $address['address'] . "' AND `transfer` = 1";
                break;
            case 3:
                $where = " `from` = '".$address['address']."' AND `status` = 4";
                break;
            case  4:
                $where = " `address` = '" . $address['address'] . "' AND `status` = 2 ";
                break;
        }
        $res = $this->VoteData->list('*',$page,$pagesize)
            ->from('t_wire_transfer')
            ->where("", $where, "RAW")
            ->orderBy('created', 'DESC')
            ->query();
        $pageinfo = $res['pageinfo'];
        $res = $res['result'];
        foreach ($res as $key => $val){
            $end_result[$key]['id'] = $key+1;
            $end_result[$key]['trading'] = $val['id'];
            $end_result[$key]['value'] = intval($val['value']);
            $end_result[$key]['status'] = intval($val['status']);
            $end_result[$key]['transfer'] = intval($val['transfer']);
            $end_result[$key]['address'] = $val['address'];
            $end_result[$key]['from'] = $val['from'];
            $end_result[$key]['created'] = date('Y-m-d H:i:s',$val['created']);
        }
        $resData =[
            'list' =>$end_result,
            'pageInfo' =>  $pageinfo
        ];
        return returnSuccess($resData,'转账记录');
    }

    /**
     * 自动投票设置
     * @param array $address
     */
    public function automaticVote($address = [])
    {
        $end_result = [];//最终结果
        if (empty($address['address'])) return returnError('请给我用户地址');
        if (empty($address['status'])) return returnError('请给设置是否启动状态');
        if (empty($address['voter'])) return returnError('请给节点绑定地址');
        //判断下该用户是否开启自动投票
        $useraddress = $this->VoteData->info('*')
            ->from('t_user_address')
            ->where(""," `address` = '".$address['address']."' AND is_del = 1","RAW")
            ->query();
        $useraddress = $useraddress['result'];
        if (!$useraddress) return returnError('该用户地址不存在');
        if ($useraddress['status'] != 1) return returnError('权益没有开通,无法进行投票');
        //是否有自动投票的设置
        if ($address['status']){
            $this->VoteData->update('t_user_address')
                ->set('auto',$address['status'])
                ->where(""," `address` = '".$address['address']."'","RAW")
                ->query();
            //查看钱包的数据
            $this->walletMoney($address['address']);
            $where = ['address' => $address['address']];
            $txId = $this->mongdb_operation->find($where)->toArray();
            $txId = $this->mongoObjectToArray($txId);
            if (!$txId) {
                return returnError('该用户没有钱包，是无法选择自动投票');
            }
            //该用户是否保存有自动投票,要删除重新添加
            $pledgeList = $this->VoteData->select('*')
                ->from('t_pledge_node_user')
                ->where(""," `address` = '".$address['address']."'","RAW")
                ->query();
            if ($pledgeList){
                $this->VoteData->delete()
                    ->from('t_pledge_node_user')
                    ->where("", " `address` = '" . $address['address'] . "'", "RAW")
                    ->query();
            }
            if (is_array($address['voter'])) {
                foreach ($address['voter'] as $val) {
                    $end_result [] = [
                        $address['address'],
                        $val,
                        time()
                    ];
                }
            }
            $result  = $this->VoteData->insertInto('t_pledge_node_user')
                ->intoColumns(['address', 'node_address', 'created'])
                ->intoValues($end_result)
                ->query();
            if ($result) return returnSuccess('自动投票设置成功');
        }
    }


    /**
     * 提交转账的接口
     * @param array $data
     */
    public function transferAccountsinfo($data = [])
    {
        if (empty($data['trading'])) return returnError('请给我交易序列号');
        if (empty($data['noce'])) return returnError('请给我交易随机值');
        if (empty($data['address'])) return returnError('请给我用户地址');
        if (empty($data['from'])) return returnError('你需要转给谁地址');
        if (empty($data['value'])) return returnError('请给我转账是多少');
        //查看钱包的数据
        $where = ['address' => $data['address']];
        $txId = $this->mongdb_operation->find($where)->toArray();
        $txId = $this->mongoObjectToArray($txId);
        if (!$txId) {
            $this->walletMoney($data['address']);
            return returnError('该用户没有钱包,无法转账');
        }
        //在交易过程中请勿重新交易
        $tradingData = $this->VoteData->info('trading')
            ->from('t_wire_transfer')
            ->where(""," `trading` = '".$data['trading']."'","RAW")
            ->query();
        if ($tradingData['result']) return returnError('请不要重复提交交易');
        $trading ['message'] = $data['trading'];
        $result = $this->receiveAction($trading);
        $result = json_decode($result['body'], true);
        if ($result['code'] == 200) {
            //转账交易存取数据库
            $wireData = [
                $data['address'],
                $data['from'],
                intval($data['value']),
                $trading['message'],
                1,//反馈给我是打包中
                time()
            ];
            $this->insertWireTrading($wireData);
            return returnSuccess('', $result['msg']);
        } else {
            //转账交易存取数据库
            $wireData = [
                $data['address'],
                $data['from'],
                intval($data['value']),
                $trading['message'],
                2,
                time()
            ];
            $this->insertWireTrading($wireData);
            return returnError($result['msg']);
        }
    }

    /**
     * 自动投票的页面
     * @param string $address
     */
    public function voteUserList($address = '')
    {
        $end_result = [];
        if (empty($address)) return returnError('请给我用户地址');
        //是否设置自动投票设置
        $userAddress = $this->VoteData->info('*')
            ->from('t_user_address')
            ->where(""," `address` = '".$address."'","RAW")
            ->query();
        $userAddress = $userAddress['result'];
        if (!$userAddress) return returnError('该地址不存在');
        //查看设置的自动投票
        $result = $this->VoteData->select('*')
            ->from('t_pledge_node')
            ->query();
        $result = $result['result'];
        foreach ($result as $key => $value) {
            $end_result[$key]['id'] = $key + 1;
            $end_result[$key]['name'] = $value['name'];
            $end_result[$key]['content'] = $value['content'];
            $end_result[$key]['value'] = intval($value['value']);
            $end_result[$key]['votecount'] = intval($value['votecount']);
            $end_result[$key]['nodeAddress'] = $value['node_address'];
            $end_result[$key]['status'] = intval($value['status']);
            $end_result[$key]['click'] = $this->getVoteStatus($value['node_address']);
        }
        //选中的下发的投票列表
        $voteList = $this->getVoteStatus('',$address,2);
        $resultData = [
            'list' => $end_result,
            'auto' => intval($userAddress['auto']),
            'vote' => $voteList
        ];
        return returnSuccess($resultData, '获取成功');
    }

    /**
     * 是否有选中的自动投票
     */
    public function getVoteStatus($voteAddress = '',$address = '',$type = 1)
    {
        $voteList = [];
        if ($type == 1){
            $autoStatus = $this->VoteData->info('*')
                ->from('t_pledge_node_user')
                ->where(""," `node_address` = '".$voteAddress."'","RAW")
                ->query();
            $autoStatus = $autoStatus['result'];
            if ($autoStatus)  return true;
            else return false;
        }else{
            $autoStatus = $this->VoteData->select('*')
                ->from('t_pledge_node_user')
                ->where(""," `address` = '".$address."'","RAW")
                ->query();
            $autoStatus = $autoStatus['result'];
            foreach ($autoStatus as $val){
                $voteList[] = $val['node_address'];
            }
            return $voteList;
        }
    }


    /**
     * 转账详情
     * @param string $trading 转账的主键
     * @param string $address 用户地址
     *
     * @return bool
     */
    public function transferDetails($trading = '', $address = '')
    {
        $end_result = [];//最终结果
        if (empty($address)) return returnError('请给我地址');
        if (empty($trading)) return returnError('请给我主键');
        $res = $this->VoteData->info('*')
            ->from('t_wire_transfer')
            ->where(""," `id` = ".$trading."","RAW")
            ->query();
        $res = $res['result'];
        if (!$res) return returnError('转账记录不存在');
        $end_result['orderId'] = $this->txIdHash($res['trading']);
        $end_result['logoStatus'] = intval($res['status']);
        $end_result['num'] = intval($res['value']);
        $end_result['receiptAddress'] = $res['from'];
        $end_result['payAddress'] = $res['address'];
        $end_result['remarkInfo'] = $res['remark'];
        $end_result['block'] = $res['txId'];
        $end_result['transfer'] = $res['transfer'];
        $end_result['qrCode'] = $res['qrcode'] ?? '';
        $end_result['orderTime'] = date("Y-m-d H:i:s", $res['created']);
        return returnSuccess($end_result,'获取成功');
    }

    /**
     * 投票奖励列表
     * @param string $address
     * @param $page
     * @param int $pagesize
     */
    public function voteReward($address = '',$page, $pagesize = 20)
    {
        $end_result =[];
        if (empty($address)) return returnError('请给我用户地址');
//        $res = $this->mongdb_vote_node->find(['address'=>$address])->toArray();
//        $res = $this->mongoObjectToArray($res);
//        $page['endPage'] = count($res);
//        $page['totalNum'] = count($res);
        $res = $this->VoteData->list('*',$page,$pagesize)
            ->from('t_user_vote')
            ->where(""," `vote` = '".$address."'","RAW")
            ->query();
        $pageinfo = $res['pageinfo'];
        $res = $res['result'];
        foreach ($res as $re_key =>$re_val){
            $end_result[$re_key]['id'] = $re_key + 1;
            $end_result[$re_key]['voteId'] = $re_val['id'];
            $end_result[$re_key]['value'] = intval($re_val['value']);
            $end_result[$re_key]['theory'] = intval($re_val['value']);
            $end_result[$re_key]['nodeAddress'] = $re_val['node_address'];
            $end_result[$re_key]['rounds'] = intval($re_val['rounds']);
            $end_result[$re_key]['created'] = date('Y-m-d H:i:s',$re_val['created']);
        }
        //获取总共投票的人数的奖励池
        $countOnly = $this->VoteData->select('*')
            ->from('t_user_vote')
            ->query();
        //从钱包获取奖励是多少
        $where = ['address' => $address,'award'=>1];
        $vote_wallet_mony = $this->mongdb_operation->find($where)->toArray();
        $vote_wallet_mony = $this->mongoObjectToArray($vote_wallet_mony);
        $sumMoney  = array_sum(array_column($vote_wallet_mony, 'value')) / 100000000;

        $countOnlyMoney  = array_sum(array_column($countOnly['result'], 'value')) / 100000000;
        //当前第几轮进行中
        $round = $this->getRounds();
        $resultData = [
            'list' => $end_result,
            'pageInfo' => $pageinfo,
            'sumMoney' => $sumMoney,
            'countOnlyMoney' => $countOnlyMoney,
            'round' =>$round['record']['rounds']
        ];
        return returnSuccess($resultData);
    }

    /**
     * 投票奖励详情
     * @param string $id 投票奖励的主键
     * @param string $address 用户地址
     *
     * @return bool
     */
    public function voteRewardInfo($id = '', $address = '')
    {
        $end_result = [];
        $voteList = [];
        if (empty($id)) return returnError('请给我id');
        if (empty($address)) return returnError('用户地址不能为空');
        $res = $this->VoteData->info('*')
            ->from('t_user_vote')
            ->where(""," `id` ='".$id."'","RAW")
            ->query();
        $res = $res['result'];
        if (!$res){
            return returnError('奖励不存在');
        }
        $end_result['value'] = intval($res['value']);
        $end_result['theory'] = intval($res['value']);
        $end_result['nodeAddress'] = $res['node_address'];
        $end_result['rounds'] = intval($res['rounds']);
        $end_result['created'] = date('Y-m-d H:i:s',$res['created']);
        $list = $this->VoteData->select('*')
            ->from('t_user_vote')
            ->where(""," `vote` = '".$address."'","RAW")
            ->query();
        $list = $list['result'];
        foreach ($list as $ls_key => $ls_val){
            $res =$this->getVoteInfo($ls_val['node_address']);
            $voteList[$ls_key]['id'] = $ls_key + 1;
            $voteList[$ls_key]['nodeAddress'] = $res['name'] ?? '--';
            $voteList[$ls_key]['value'] = intval($ls_val['value']);
            $voteList[$ls_key]['status'] = intval($res['status']) ?? 0;
        };

        $end_result['list']  = $voteList ?? '';
        return  returnSuccess($end_result);
    }

    /**
     * 获取投票节点信息
     * @param string $nodeAddress
     */
    public function getVoteInfo($nodeAddress = '')
    {
        $res = $this->VoteData->info('*')
            ->from('t_pledge_node')
            ->where(""," `node_address` = '".$nodeAddress."'","RAW")
            ->query();
        $res = $res['result'];
        return $res;
    }

    /**
     * 货币单位
     */
    public function monetaryUnit()
    {
        $list[0]['currencyName'] = 'CNY';
        $list[1]['currencyName'] = 'ETH';
        $list[2]['currencyName'] = 'USDT';
        $res['list'] = $list;
        return returnSuccess($res,'请求成功');
    }

    /**
     * 网络设置
     */
    public function networkSettings()
    {
        $list[0]['netName'] = 'Mainnet(主网)';
        $list[1]['netName'] = 'khuiivvhjgj(测试网)';
        $list[2]['netName'] = 'shfuiyfiubb(测试网络)';
        $list[3]['netName'] = '本地测试网络';
        $res['list'] = $list;
        return returnSuccess($res,'请求成功');
    }

    /**
     * 新版序列号投票数据质押投票
     * @param array $data
     */
    public function getEncodeTrading($data = [], $address = [])
    {
        $moneyData = [];//拼接钱包
        $toData = [];//拼接钱包
        $type = 2;
        //是否有质押的交易
        $dealData = $this->VoteData->select('*')
            ->from('t_pledge_deal')
            ->where(""," `address` = '".$address['address']."' AND is_del = 1 AND status = 1","RAW")
            ->query();
        $dealData = $dealData['result'];
        if ($dealData) {
            foreach ($dealData as $key => $value) {
                $moneyData[$key]['txId'] = $value['txId'];
                $moneyData[$key]['n'] = $value['n'];
                $moneyData[$key]['scriptSig'] = $value['reqSigs'];
            }
            $toData = [
                [
                    'address' => $address['address'],
                    'value'   => intval($address['value']),
                    'type'    => 1,
                ]
            ];
        }

        $end_result['privateKey'] = $address['privateKey'];
        $end_result['publicKey'] = $address['publicKey'];
        $end_result['tx'] = $moneyData;
        $end_result['to'] = $toData ;
        $end_result['ins'] = '';
        $end_result['time'] = time();
        $end_result['lockTime'] = $dealData[0]['locktime'] ?? 0;
        $end_result['actionType'] = $type;
        $end_result['rounds'] = $data['round'];
        $end_result['voteAgain'] = $type;
        $end_result['candidate'] = $data['candidate'];
        $end_result['voter'] = $data['voter'];
        $res = $this->encodeAction(json_encode($end_result));
        $res = json_decode($res['body'], true);
        if ($res['code'] == 200) {
            //取出序列化
            return returnSuccess($res['record'],'请求trading成功');
        } else {
            return returnError('获取序列化请求超时');
        }
    }


    /**
     * 用户投票节点记录
     * @param array $data
     * @param string $trading
     */
    public function voteListLog($data = [],$trading = '')
    {
        $end_result =[
            'rounds' =>$data['round'],
            'address' =>$data['voter'],//投票者
            'trading' =>$data['trading'] ?? $trading,
        ];
        $res = $this->mongdb_votelist_log->insertOne($end_result);
    }

    /**
     * 新版确定质押进行交易
     * 直接质押
     * @param array $data
     * @return bool
     */
    public function getPledgeDeal($data = [])
    {
        $moneyData = [];//拼接钱包
        if (empty($data['address'])) return returnError('地址不能为空');
        if (empty($data['privateKey'])) return returnError('私钥不能为空');
        if (empty($data['publicKey'])) return returnError('公钥不能为空');
        if (empty(intval($data['value']))) return returnError('质押数量不能为空');
        $onlyMoney = intval($data['value']) * 3000000;
        //判断下该用户是否有钱包 没有钱包是无法交易
        $txId = $this->getWalletMoney($data['address'],$onlyMoney);
        if (!$txId['IsSuccess']){
            return  returnError($txId['Message']);
        }else {
            //首次质押是否有10000个only
            $only_res = $this->VoteData->info('*')
                ->from('t_pledge_deal')
                ->where("", " `address` = '" . $data['address'] . "' AND pledge_token = 10000 ", "RAW")
                ->query();
            $only_res = $only_res['result'];
            if (!$only_res) {
                if (intval($data['value']) < 10000) return returnError('首次质押至少是10000个only');
                $only_res_data = [
                    $onlyMoney,
                    $data['address'],
                    intval($data['value'])
                ];
                $this->VoteData->insertInto('t_pledge_deal')
                    ->intoColumns(['value', 'address', 'pledge_token'])
                    ->intoValues($only_res_data)
                    ->query();
            }
            //钱包获取交易
            foreach ($txId['Data'] as $key => $value) {
                $moneyData[$key]['txId'] = $value['txId'];
                $moneyData[$key]['n'] = $value['n'];
                $moneyData[$key]['scriptSig'] = $value['reqSigs'];
            }
            var_dump($moneyData);
            $toData = [
                [
                    'address' => $data['address'],
                    'value' => $onlyMoney,
                    'type' => 1,
                ]
            ];
            //生成质押交易
            $end_result['privateKey'] = $data['privateKey'];
            $end_result['publicKey'] = $data['publicKey'];
            $end_result['tx'] = $moneyData ;
            $end_result['to'] = $toData;
            $end_result['ins'] = '';
            $end_result['time'] = time();
            $end_result['lockTime'] = time();
            $end_result['actionType'] = 1;
            $res = $this->encodeAction(json_encode($end_result));
            $res = json_decode($res['body'], true);
            if ($res['code'] == 200) {
                //取出序列化
                $trading['message'] = $res['record'];
                $txIdData['txId'] = $this->txIdHash($res['record']);
                $resData = $this->receiveAction($trading);
                $resData = json_decode($resData['body'], true);
                if ($resData['code'] == 200){
                    //删除钱包的交易txId
                    $this->walletMoneyDetel($moneyData);
                    //质押记录存取
                    $sqlData = [
                        $onlyMoney,
                        $data['address'],
                        intval($data['value']),
                        time(),
                        time(),
                        1,
                        $trading['message'],
                        $txIdData['txId']
                    ];
                    $result = $this->VoteData->insertInto('t_pledge_deal')
                        ->intoColumns(['value', 'address', 'pledge_token','time', 'created','is_del','trading','txId'])
                        ->intoValues($sqlData)
                        ->query();
                    if ($result) return returnSuccess('','质押成功');
                    else return  returnError('质押失败');
                }else{
                    return  returnError($resData['msg']);
                }
            } else {
                return returnError('获取序列化请求超时');
            }


        }
    }


    /**
     * 用户查看钱包和获取的交易
     * @param string $address
     * @param float|int $money
     *
     * @return array|bool
     */
    public function getWalletMoney($address = '',$money = 357946549 *2){
        if (empty($address)) return returnError('请传给我地址');
        $where = ['address' => $address,'lockTime' =>0];
        $txId = $this->mongdb_operation->find($where)->toArray();
        $txId = $this->mongoObjectToArray($txId);
        if ($txId) {
            $onlyMony = array_sum(array_column($txId, 'value'));
            if ($onlyMony < $money) {
                return returnError('你的钱包金额不够');
            }
            $sum = 0;
            foreach ($txId as $key => $value){
                if ($sum < $money){
                    $sum += $value['value'];
                    $list[] = $value;
                }
            }
            return returnSuccess($list,'获取成功');
        }else{
            return returnError('该用户没有钱包');
        }
    }

    /**
     * 交易成功钱包交易txId删除
     * @param array $data
     */
    public function walletMoneyDetel($data = []){
        foreach ($data as $k => $v){
            $res = $this->mongdb_operation->deleteOne(['txId'=>$v['txId']]);
            if(!$res->getDeletedCount()){
                return returnError('删除失败');
            }
        }
    }

}