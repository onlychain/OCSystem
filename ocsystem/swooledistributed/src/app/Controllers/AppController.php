<?php
namespace app\Controllers;

use app\Models\AppModel;
use app\Process\MyProcess;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use Server\Components\Process\ProcessManager;

class AppController extends Controller{
    protected function initialization($controller_name, $method_name){
        parent::initialization($controller_name,$method_name);
    }
    public function tcp_onClose()
    {
        return $this->send(1);
    }

    public function tcp_onConnect()
    {
        return $this->send(1);
    }

    public function ws_onClose()
    {
        return $this->send(1);
    }

    public function ws_onConnect()
    {
        return $this->send(1);
    }
}
?>