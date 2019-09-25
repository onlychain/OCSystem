<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部相关操作
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Block;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\BlockProcess;
use Server\Components\Process\ProcessManager;

class BlockBaseModel extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
    }

    /**
     * 查询区块信息
     * @param string $head_hash
     * @return bool
     */
    public function queryBlock($head_hash = '')
    {
        if($head_hash == ''){
            return returnError('请传入区块哈希.');
        }
        $block_res = [];
        $where = ['headHash' => $head_hash];
        $data = ['_id' => 0];
        $block_res = ProcessManager::getInstance()
                                    ->getRpcCall(BlockProcess::class)
                                    ->getBlockHeadInfo($where, $data);
        return $block_res;
    }


}
