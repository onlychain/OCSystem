<?php

namespace app;

use Server\CoreBase\HttpInput;
use Server\CoreBase\Loader;
use Server\SwooleDistributedServer;
use Server\Asyn\TcpClient\TcpClientPool;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\Asyn\HttpClient\HttpClientPool;
use Server\Asyn\Mysql\Miner;
use Server\Asyn\Mysql\MysqlAsynPool;
use Server\Asyn\Redis\RedisAsynPool;
use Server\Asyn\Redis\RedisLuaManager;
//自定义进程


use app\Process\PeerProcess;//P2P进程
use app\Process\VoteProcess;//投票进程
use app\Process\NodeProcess;//节点进程
use app\Process\BlockProcess;//区块进程
use app\Process\TradingProcess;//交易进程
use app\Process\SuperNodeProcess;//超级节点进程
use app\Process\ConsensusProcess;//共识进程
use app\Process\TimeClockProcess;//时间钟进程
use app\Process\IncentivesProcess;//激励进程
use app\Process\TradingPoolProcess;//交易池进程
use app\Process\PurseProcess;//钱包进程进程
use app\Process\CoreNetworkProcess;//核心节点共识网络进程
use Server\Components\Process\ProcessManager;

/**
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 16-9-19
 * Time: 下午2:36
 */
class AppServer extends SwooleDistributedServer
{
    /**
     * 可以在这里自定义Loader，但必须是ILoader接口
     * AppServer constructor.
     */
    public function __construct()
    {
        $this->setLoader(new Loader());
        parent::__construct();
    }

    /**
     * 开服初始化(支持协程)
     * @return mixed
     */
    public function onOpenServiceInitialization()
    {
        parent::onOpenServiceInitialization();
    }

    /**
     * 这里可以进行额外的异步连接池，比如另一组redis/mysql连接
     * @param $workerId
     * @return void
     * @throws \Server\CoreBase\SwooleException
     * @throws \Exception
     */
    public function initAsynPools($workerId)
    {
        parent::initAsynPools($workerId);
        if ($this->config->get('mysql.wwl_base_new', true)) {
            $this->addAsynPool('baseNewPool', new MysqlAsynPool($this->config, 'wwl_base_new'));
        }
        if ($this->config->get('mysql.wwl_order', true)) {
            $this->addAsynPool('orderPool', new MysqlAsynPool($this->config, 'wwl_order'));
        }
        if ($this->config->get('mysql.wwl_log', true)) {
            $this->addAsynPool('logPool', new MysqlAsynPool($this->config, 'wwl_log'));
        }
        if ($this->config->get('mysql.wwl_xinpian', true)) {
            $this->addAsynPool('xinpianPool', new MysqlAsynPool($this->config, 'wwl_xinpian'));
        }
    }

    /**
     * 用户进程
     * @throws \Exception
     */
    public function startProcess()
    {
        parent::startProcess();

        ProcessManager::getInstance()->addProcess(PurseProcess::class);

        ProcessManager::getInstance()->addProcess(NodeProcess::class);
        ProcessManager::getInstance()->addProcess(VoteProcess::class);
        ProcessManager::getInstance()->addProcess(BlockProcess::class);
        ProcessManager::getInstance()->addProcess(TradingProcess::class);
        ProcessManager::getInstance()->addProcess(SuperNodeProcess::class);

        ProcessManager::getInstance()->addProcess(ConsensusProcess::class);
        ProcessManager::getInstance()->addProcess(IncentivesProcess::class);
        ProcessManager::getInstance()->addProcess(TradingPoolProcess::class);

        ProcessManager::getInstance()->addProcess(TimeClockProcess::class);

        ProcessManager::getInstance()->addProcess(PeerProcess::class);
        ProcessManager::getInstance()->addProcess(CoreNetworkProcess::class);
    }

    /**
     * 可以在这验证WebSocket连接,return true代表可以握手，false代表拒绝
     * @param HttpInput $httpInput
     * @return bool
     */
    public function onWebSocketHandCheck(HttpInput $httpInput)
    {
        return true;
    }

    /**
     * @return string
     */
    public function getCloseMethodName()
    {
        return 'onClose';
    }

    /**
     * @return string
     */
    public function getEventControllerName()
    {
        return 'AppController';
    }

    /**
     * @return string
     */
    public function getConnectMethodName()
    {
        return 'onConnect';
    }

}
