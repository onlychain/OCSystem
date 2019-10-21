<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易相关操作自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;


use app\Models\Trading\ValidationModel;
use app\Models\Trading\TradingEncodeModel;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程

use app\Process\SuperNodeProcess;
use Server\Components\Process\Process;
use Server\Components\Process\ProcessManager;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use MongoDB;

class CoreNetworkProcess extends Process
{
    /**
     * 是否可以传输数据
     * @var bool
     */
    private $IsOpen = true;

    /**
     * 超级节点地址列表
     * @var array
     */
    private $SuperNodeAddres = [];

    /**
     * 存储配置信息
     * @var array
     */
    private $Config = [];
    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('CoreNetworkProcess');
        //获取配置信息
        $this->Config = get_instance()->config;
    }

    /**
     * 更新超级节点
     * @return bool
     */
    public function rushSuperNodeLink()
    {
        $this->IsOpen = false;
        $super_nodes = [];//存储超级节点数据
        $super_address = [];//存储超级节点地址名
        $set_res = [];//设置超级节点结果
        //获取当前数据库中的核心节点数据，假定有开放端口以及IP
        //超级节点条件
        $nodes_where = [];
        //获取的字段
        $nodes_data = ['_id' => 0, 'voter'  => 0];
        //获取的结果
        $nodes_res = [];
        $nodes_res = ProcessManager::getInstance()
                                    ->getRpcCall(SuperNodeProcess::class)
                                    ->getSuperNodeList($nodes_where, $nodes_data, 1, 100);
        if(empty($nodes_res['Data'])){
            return returnError('没有超级节点.');
        }
        //整理需要的数据，方便节点设置
        foreach ($nodes_res['Data'] as $nr_key => $nr_val){
            $super_nodes[] = [
                'address'   =>  $nr_val['address'],
                'ip'        =>  $nr_val['ip'],
                'port'      =>  $nr_val['port'],
            ];
            $super_address[$nr_val['address']] = $nr_val['address'];
        }
        //清理落选的超级节点
        $this->clearSuperNode($super_address);
        //设置超级节点连接
        $set_res = $this->setSuperNode($super_nodes);
        if(!$set_res['IsSuccess']){
            return returnError('节点设置失败.');
        }
        $this->IsOpen = true;
        return returnSuccess('节点设置完成.');
    }

    /**
     * 清理落选的超级节点连接
     * @param array $super_address
     * @return bool
     */
    public function clearSuperNode(array $super_address = [])
    {
        if(empty($super_address)){
            return returnError('请输入要更新的节点信息.');
        }
        //关闭已经落选的超级节点连接
        foreach ($this->SuperNodeAddres as $sna_key => $sna_val){
            //如果旧的节点不在新的节点列表中，关闭这个节点的连接
            if(empty($super_address[$sna_val])){
                get_instance()->dropAsynPool($sna_val);
                $this->dropSuperAddresInfo($sna_val);
            }
        }
        return returnSuccess('清理完成.');
    }

    /**
     * 设置超级节点连接池
     * @param array $super_node
     * @return bool
     */
    protected function setSuperNode(array $super_node = [])
    {
        if(empty($super_node)){
            return returnError('节点数据不能为空!');
        }
        //设置连接池
        foreach ($super_node as $sn_key => $sn_val){
            if(!isset($this->SuperNodeAddres[$sn_val['address']])){
                get_instance()->addAsynPool($sn_val['address'],new SdTcpRpcPool($this->config,'super_node',$sn_val['ip'] . ":" . $sn_val['port']));
                $this->setSuperAddresInfo($sn_val['address']);
            }

        }
        return returnSuccess('设置成功.');
    }

    /**
     * 发送信息给所有的超级节点
     * @param array $message发送的数据
     * @param array $content报文
     * @param string $controller控制器名
     * @param string $method方法名
     * @return bool
     */
    public function sendToSuperNode($message = '', $content = [], $controller = '', $method = '', $one_way = false)
    {
        if(!$this->IsOpen){
            return returnError('当前不可传输数据');
        }
        if(empty($this->SuperNodeAddres)){
            return returnError('没有超级节点.');
        }
        //超级节点返回的数据
        $super_node_res = [];
        //循环发送给所有连接的节点
        foreach ($this->SuperNodeAddres as $sna_key => $sna_val){
            if($sna_val == get_instance()->config['address']){
                continue;
            }
            //初始化变量
            $client = [];
            $data = [];
            $client = get_instance()->getAsynPool($sna_val);
            $data = $client->helpToBuildSDControllerQuest($content, $controller, $method);
            $data['params'] = $message;
            $super_node_res[$sna_val] = $client->coroutineSend($data, $one_way);
        }
        return returnSuccess($super_node_res);
    }

    /**
     * 设置超级节点地址列表
     * @param array $super_address
     */
    public function setSuperAddress(array $super_address = [])
    {
        $this->SuperNodeAddres = $super_address;
    }

    /**
 * 设置单个超级节点信息
 * @param array $super_address
 */
    public function setSuperAddresInfo(string $super_address = '')
    {
        $this->SuperNodeAddres[$super_address] = $super_address;
    }

    /**
     * 去除单个超级节点信息
     * @param array $super_address
     */
    public function dropSuperAddresInfo(string $super_address = '')
    {
        unset($this->SuperNodeAddres[$super_address]);
    }

    /**
     * 获取超级节点地址列表
     * @return array
     */
    public function getSuperAddress() : array
    {
        return $this->SuperNodeAddres;
    }
    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "核心网络进程关闭.";
    }
}
