<?php

namespace app\Models\Vote;

use Server\CoreBase\Model;

class VoteModel extends Model
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
        $this->VoteConfig = get_instance()->config->get('site');
    }


    /**
     * 请求创建地址及密钥，公钥(保留着)
     * @param array $data
     *
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
     * 获取地址本列表
     *
     * @param $address
     *
     * @return bool
     */
    public function getAddressList($address)
    {
        if (empty($address)) return returnError('地址不能为空');
        $result = $this->VoteData->select('*')
            ->from('t_address_book')
            ->where("", " `address_uuid` = '" . $address . "' AND is_del = 1", 'RAW')
            ->query();
        $result = $result['result'];
        return returnSuccess($result, '查询地址列表');
    }


    /**
     * 添加地址本
     *
     * @param $data
     *
     * @return bool
     */
    public function inserAddressBook($data)
    {
        if (empty($data['address'])) return returnError('地址不能为空');
        if (empty($data['addressBook'])) return returnError('地址本不能为空');
        if (empty($data['remark'])) return returnError('备注不能为空');
        $result = $this->VoteData->info('*')
                                 ->from('t_address_book')
                                 ->where("", "`address_uuid` = '" . $data['address'] . "' AND `address_book` = '" . $data['addressBook'] . "'", 'RAW')
                                 ->query();
        $result = $result['result'];
        if (!$result) {
            $addressBook = [
                $data['address'],
                $data['addressBook'],
                $data['remark'],
                time()
            ];
            $res = $this->VoteData->insertInto('t_address_book')
                                  ->intoColumns(['address_uuid', 'address_book', 'remark', 'created'])
                                  ->intoValues($addressBook)
                                  ->query();
            if ($res) {
                return returnSuccess('', '添加地址成功');
            } else {
                return returnError('添加地址失败');
            }
        } else {
            return returnError('该地址存在');
        }
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
        $res = $this->VotePool->httpClient->setQuery($data)->coroutineExecute('/Trading/selectProus');
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
                            'award'    => 0
                        ];
                    }
                }
                if ($mongData) {
                    $insert_res = $this->mongdb_operation->insertMany($mongData);
                    if (!$insert_res) return returnError('添加失败');
                }
                $onlyMony['onlyMony'] = array_sum(array_column($numList, 'value')) / 100000000;
                //查看钱包的数据
                $where = ['address' => $addree];
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
                $pledgeTime = date('Y-m-d',$useraddress_res['equity_time']);
                $unlockTime = date('Y-m-d',$useraddress_res['equity_time']+365*24*60*60);
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
     *                                        以上都是请求接口
     * =====================================================================================================================
     */

    /**
     * 转账交易(要保留)
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
        if (empty($address['privateKey'])) return returnError('请给我私钥');
        if (empty($address['publicKey'])) return returnError('请给我公钥');
        if (empty($address['from'])) return returnError('请输入发送者地址');
        if (empty(intval($address['value']))) return returnError('请输入only金额');
        //查看钱包的数据
        $where = ['address' => $address['address']];
        $txId = $this->mongdb_operation->find($where)->toArray();
        $txId = $this->mongoObjectToArray($txId);
        $onlyMony = array_sum(array_column($txId, 'value')) / 100000000;
        if ($onlyMony < intval($address['value'])) {
            return returnError('你的钱包金额不够');
        }
        foreach ($txId as $key => $value) {
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
        $end_result['privateKey'] = $address['privateKey'];
        $end_result['publicKey'] = $address['publicKey'];
        $end_result['tx'] = $moneyData;
        $end_result['from'] = $address['from'];
        $end_result['to'] = $toData;
        $end_result['ins'] = '';
        $end_result['time'] = 0;
        $end_result['lockTime'] = 0;
        $end_result['lockType'] = 1;
        $res = $this->encodeTrading($end_result);
        $res = json_decode($res['body'], true);
        if ($res['code'] == 200) {
            //取出序列化
            $trading['trading'] = $res['record'];
            $trading['txId'] = bin2hex(hash('sha256', hash('sha256', hex2bin($res['record']), true), true));
        } else {
            return returnError('获取序列化请求超时');
        }
        $trading['noce'] = str_shuffle($end_result['from'] . time());
        $trading['address'] = $address['address'];
        $trading['renoce'] = '';
        $result = $this->receivingTransactions($trading);
        $result = json_decode($result['body'], true);
        if ($result['code'] == 200) {
            if ($result['record']['IsSuccess'] == false) {
                return returnSuccess('', $result['Message']);
            } else {
                //转账交易存取数据库
                $wireData = [
                    $address['address'],
                    $end_result['from'],
                    intval($address['value']),
                    $trading['trading'],
                    1,
                    time(),
                    $trading['txId'],
                    $address['remark']
                ];
                $this->insertWireTrading($wireData);
                return returnSuccess('', '打包中');
            }
        } else {
            //转账交易存取数据库
            $wireData = [
                $address['address'],
                $end_result['from'],
                intval($address['value']),
                $trading['trading'],
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
     * @param array $data
     */
    public function insertWireTrading($data = [])
    {
        if (empty($data)) return returnError('数组不能为空');
        $res = $this->VoteData->insertInto('t_wire_transfer')
            ->intoColumns(['address', 'from', 'value', 'trading', 'status', 'created','txId'])
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
            //去进行生产交易，这笔交易类型为1
            $end_result['from'] = $data['address'];
            $end_result['to'] = [$data['address'] => intval($data['value']) * 10000000];
            $end_result['lockType'] = 1;
            $query_result = $this->createTrading($end_result);
            $query_result = json_decode($query_result['body'], true);
            if ($query_result['code'] == 200) {
                //生成交易之后查询有多少交易可以
                $addressData = [
                    'address' => $data['address'],
                    'value' => $data['value'],
                    'type' => 1,
                    'privateKey' => $data['privateKey'],
                    'publicKey' => $data['privateKey'],
                ];
                $transacBack=$this->transacTion($query_result['record'],$addressData);
                if($transacBack['IsSuccess']  == false){
                    return  returnError($transacBack['Message']);
                }else {
                    $res = $this->VoteData->update('t_user_address')
                        ->set('status', 1)
                        ->set('equity_time', time())
                        ->where('', " `address` = '" . $data['address'] . "'", 'RAW')
                        ->query();
                    $resultList['pledgeTime'] = date('Y-m-d H:i:s', time());
                    $resultList['unlockTime'] = date('Y-m-d H:i:s', time() + 365 * 24 * 60 * 60);
                    if ($res) return returnSuccess($resultList, '权益开通');
                }
            } else {
                return returnError($query_result['msg']);
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
            $tranding['trading'] = $data['trading'];
            $tranding['noce'] = $data['noce'];
            $tranding['renoce'] = $data['renoce'] ?? '';
            $tranding['address'] = $data['address'];
            $results = $this->receivingTransactions($tranding);
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


}