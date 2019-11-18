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


}