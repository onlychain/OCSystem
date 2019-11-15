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


}