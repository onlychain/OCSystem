<?php


namespace app\Controllers;

use app\Models\AppModel;
use Server\CoreBase\Controller;

class OnlyvoteController extends Controller
{
    public $VoteClass;

    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        $this->VoteClass = $this->loader->model('Vote/VoteModel', $this);//商品公共方法
    }

    /**
     * 生成私钥和地址
     */
    public function http_createdAccount()
    {
        $res = $this->VoteClass->accessServer();
        $data = json_decode($res, true);
        $addressData = $data['record'];
        $address = $this->http_input->getPost('address') ?? $addressData['address'];
        $privateKey = $this->http_input->getPost('privateKey') ?? $addressData['publicKey'];
        $publicKey = $this->http_input->getPost('publicKey') ?? $addressData['publicKey'];
        //生成的地址存取
        $addressData = $this->VoteClass->insertAddress($address);
        $this->VoteClass->selectProus($address);
        //组装返回结果
        $result = [
            'privateKey' => $privateKey,
            'publicKey'  => $publicKey,
            'address'    => $address,
        ];
        return $this->http_output->lists($result);
    }


    /**
     * 获取地址
     */
    public function http_getAddressList()
    {
        $address = $this->http_input->getPost('address');
        $res = $this->VoteClass->getAddressList($address);
        if (!$res['IsSuccess']) {
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 添加地址本
     */
    public function http_inserAddressBook()
    {
        $data = $this->http_input->getAllPostGet();
        $res = $this->VoteClass->inserAddressBook($data);
        if (!$res['IsSuccess']) {
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res);
    }

    /**
     * 查看钱包获取only
     */
    public function http_onlyMoney()
    {
        $address = $this->http_input->getAllPostGet();
        $res = $this->VoteClass->selectProus($address);
        if (!$res['IsSuccess']) {
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 开通权益并且要有500个only进行交易
     */
    public function http_equity()
    {
        $address = $this->http_input->getAllPostGet();
        $res = $this->VoteClass->equity($address);
        if (!$res['IsSuccess']) {
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res);
    }

    /**
     * 转账交易
     */
    public function http_wireTransfer()
    {
        $address = $this->http_input->getAllPostGet();
        $res = $this->VoteClass->transferAccountsinfo($address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res);
    }

    /**
     * 转账交易(需要传私钥和公玥)
     */
    public function http_wireTransfers()
    {
        $address = $this->http_input->getAllPostGet();
        $res = $this->VoteClass->wireTransfer($address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res);
    }

    /**
     * 获取系统时间、轮次、轮次时间、区块高度，已投数量
     */
    public function http_getCountOnly()
    {
        $address = $this->http_input->getPost('address');
        $res = $this->VoteClass->getCountOnly($address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }


    /**
     * 获取投票列表
     */
    public function http_getVoteList(){
        $address = $this->http_input->getPost('type');
        $res = $this->VoteClass->getVoteList($address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 开始投票
     */
    public function http_surrender(){
        $address = $this->http_input->getAllPostGet();
        $res = $this->VoteClass->surrender($address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 开始投票(需要传私钥和公玥)
     */
    public function http_surrenders(){
        $address = $this->http_input->getAllPostGet();
        $res = $this->VoteClass->surrenders($address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 投票详情
     */
    public function http_getVoteDetail(){
        $address = $this->http_input->getPost('address');
        $res = $this->VoteClass->getVoteDetail($address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 投票查看规则
     */
    public function http_getVoteRule()
    {
        $res = $this->VoteClass->getVoteRule();
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 自动投票的页面
     */
    public function http_voteUserList()
    {
        $address = $this->http_input->getPost('address');
        $res = $this->VoteClass->voteUserList($address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 提交自动投票
     */
    public function http_automaticVote(){
        $address = $this->http_input->getAllPostGet();
        $data['address'] = $address['address'];
        $data['status'] = $address['status'];
        $data['voter'] = $address['voter']['values'];
        $res = $this->VoteClass->automaticVote($data);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }


    /**
     * 确定质押交易
     */
    public function http_getPledgeDeal(){
        $address = $this->http_input->getAllPostGet();
        $res = $this->VoteClass->getPledgeDeal($address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res);
    }

    /**
     * 质押列表
     */
    public function http_getPledgeList()
    {
        $address = $this->http_input->getPost('address');
        $page = $this->http_input->getPost('currentPage');
        $pagesize = $this->http_input->getPost('pageSize');
        $sort = $this->http_input->getPost('sort');
        $type = $this->http_input->getPost('type');
        $res = $this->VoteClass->getPledgeList($address,$page,$pagesize,intval($sort),intval($type));
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 质押页面
     */
    public function http_getPledgeInfo(){
        $address = $this->http_input->getPost('address');
        $page = $this->http_input->getPost('currentPage');
        $pagesize = $this->http_input->getPost('pageSize');
        $res = $this->VoteClass->getPledgeInfo($address,$page,$pagesize);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }


    /**
     * 获取质押的解锁时间
     */
    public function http_pledgeTimes(){
        $address = $this->http_input->getPost('address');
        $sort = $this->http_input->getPost('sort');
        $type = $this->http_input->getPost('type');
        $page = $this->http_input->getPost('currentPage') ?? 1;
        $pagesize = $this->http_input->getPost('pageSize');
        $res = $this->VoteClass->getPledgeTimes($address,$sort,$type,$page,$pagesize);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 转账记录
     */
    public function http_transferList()
    {
        $address = $this->http_input->getAllPostGet();
        $page = $this->http_input->getPost('page');
        $pagesize = $this->http_input->getPost('pagesize');
        $res = $this->VoteClass->transferList($address,$page ,$pagesize);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 转账详情
     */
    public function http_transferDetails()
    {
        $trading = $this->http_input->getPost('trading');//trading是查询主键ID
        $address = $this->http_input->getPost('address');
        $res = $this->VoteClass->transferDetails($trading, $address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 投票奖励的列表
     */
    public function http_voteReward()
    {
        $address = $this->http_input->getPost('address');
        $page = $this->http_input->getPost('currentPage');
        $pagesize = $this->http_input->getPost('pageSize');
        $res = $this->VoteClass->voteReward($address,$page,$pagesize);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }


    /**
     * 投票奖励的列表
     */
    public function http_voteRewardInfo()
    {
        $address = $this->http_input->getPost('address');
        $id = $this->http_input->getPost('id');
        $res = $this->VoteClass->voteRewardInfo($id, $address);
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }


    /**
     * 获取货币单位
     */
    public function http_monetaryUnit()
    {
        $res = $this->VoteClass->monetaryUnit();
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

    /**
     * 获取网络设置
     */
    public function http_networkSettings()
    {
        $res = $this->VoteClass->networkSettings();
        if(!$res['IsSuccess']){
            return $this->http_output->notPut('', $res['Message']);
        }
        return $this->http_output->lists($res['Data']);
    }

}