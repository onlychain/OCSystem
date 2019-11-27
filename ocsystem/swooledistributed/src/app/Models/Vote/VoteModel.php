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



}