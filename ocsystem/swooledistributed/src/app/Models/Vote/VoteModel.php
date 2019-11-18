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
     *
     * @return bool
     */
    public function selectProus($address = [])
    {
        if (empty($address['address'])) return returnError('地址不能为空');
        $res = $this->walletMoney($address['address']);
        return returnSuccess($res, '请求成功');
    }
}