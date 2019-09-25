<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * mongoDB相关操作，mongo操作是同步的，因此会有阻塞的情况，需要注意使用
 * Date: 18-7-31
 * Time: 下午1:44
 */

namespace app\Tasks;
use Server\CoreBase\Task;
use Server\Test\TestModule;
use Server\Asyn\TcpClient\SdTcpRpcPool;

class LinkPoolTask extends Task
{
    
    /**
     * 存放链接对象池
     * @var type 
     */
    private $linkPool = [];
    
    /**
     * 设置单个链接对象
     * @param type $name
     * @param string $ip
     * @param type $port
     * @return type
     */
    public function setLinkPool($name = "", string $ip = "", $port = "9091")
    {
        if($name == "" || $ip == ""){
            return false;
        }
        if (array_key_exists($name, $this->linkPool)) {
            return false;
        }
        try {
            $link_client = new SdTcpRpcPool(get_instance()->config, "test", "$ip:$port");
            $this->linkPool[$name] = $link_client;
            $link_client = null;
        } catch (Exception $exc) {
            throw  new SwooleException('创建链接对象出错！');
        } finally {
            return $link_client;
        }
    }
    
    /**
     * 批量设置链接对象
     * @param type $nodes
     * @return boolean
     */
    public function setLonkPools(array $nodes = array())
    {
        $link_clien;
        try {
            foreach ($nodes as $no_key => $no_val) {
                if (array_key_exists($no_val["name"], $this->linkPool)) {
                    continue;
                }
                $link_client = new SdTcpRpcPool(get_instance()->config, "test", $no_val["ip"].':'.$no_val["port"]);
                $this->linkPool[$no_val["name"]] = $link_client;
                $link_client = null;
            }
        } catch (Exception $exc) {
            throw  new SwooleException('批量创建链接对象出错！');
        } finally {
             return true;
        }
    }
    
    /**
     * 获取所有连接对象
     * @return type
     */
    public function getLinkPools()
    {
        return $this->linkPool;
    }
    
    /**
     * 获取单个链接对象
     * @param type $name
     * @return type
     */
    public function getLinkPool($name = "")
    {
        return $this->linkPool[$name];
    }

    /**
     * 获取所有链接名
     * @return type
     */
    public function getLinkNames()
    {
        return array_keys($this->linkPool);
    }
    
    /**
     * 删除单个链接对象
     * @param type $name
     * @return boolean
     */
    public function dropLinkPool($name = "")
    {
        if($name == ""){
            return false;
        }
        try {
            if(isset($this->linkPool[$name])){
                $this->linkPool[$name] = null;
                unset($this->linkPool[$name]);
            }
        } catch (Exception $exc) {
            throw  new SwooleException('移除链接对象出错！');
        } finally {
            return true;
        }
    }
    
    /**
     * 批量清除链接对象
     * @param type $nodes
     * @return boolean
     */
    public function dropLinkPools($nodes = array())
    {
        try {
            foreach ($nodes as $nds_key => $nds_val){
                if($nds_val["name"] == "" || isset($this->linkPool[$nds_val['name']])){
                    $this->linkPool[$nds_val['name']] = null;
                    unset($this->linkPool[$nds_val['name']]);
                }
            }
        } catch (Exception $exc) {
            throw  new SwooleException('批量移除链接对象出错！');
        } finally {
            return true;
        }
    }
}