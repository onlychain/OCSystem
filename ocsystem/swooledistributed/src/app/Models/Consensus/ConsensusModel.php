<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 区块头部相关操作
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Consensus;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;
//自定义进程
use app\Process\BlockProcess;
use app\Process\CoreNetworkProcess;
use Server\Components\Process\ProcessManager;

class ConsensusModel extends Model
{

    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {
        $this->setContext($context);
    }

    /**
     * 共识验证算法
     * @param array $check_data用于验证的数据，一定要是奇数个
     * @param string $check_str用于验证的参照物，要是字符串
     * @return bool
     */
    public function verifyTwoThirds(array $check_data = [], string $check_str = '')
    {
        $count_num = 0;//计数器
        $count_res = [];//记录每个数据的结果
        //验证数据是否为空
        if(empty($check_data)){
            return returnError('验证数据不能为空.');
        }
        //验证数据是否是奇数个
        if((count($check_data) % 2) == 0){
            return returnError('必须是奇数个数据.');
        }
        //如果用于验证的字符串为空，取数组第一个数组作为参照物
        if($check_str == ''){
            $check_str = current($check_data);
            //如果元素不是字符串或者数字，则转为json后进行哈希
            if(!is_string($check_str) && !is_numeric($check_str)){
                $check_str = json_encode($check_str);
            }
        }
        $check_str = hash('sha3-256', $check_str);
        //循环数据，验证相同数据的数量
        foreach ($check_data as $cd_key => $cd_val){
            $hash_val = '';//值的哈希结果
            $temp_val = $cd_val;
            if(!is_string($temp_val) && !is_numeric($temp_val)){
                $temp_val = json_encode($check_str);
            }else{
                //如果值就是字符串跟数字，会返回结果数组
                empty($count_res[$cd_val]) ? $count_res[$cd_val] = 1 :  $count_res[$cd_val] += 1;
            }
            $hash_val = hash('sha3-256', $temp_val);
            //哈希后进行比对
            $check_str === $hash_val && ++$count_num;

        }
        //到这里，即使失败也返回数据
        if($count_num < (count($check_data) * 2) / 3)
            return returnError('验证不通过.', 9999, $count_res);

        return returnSuccess($count_res);
    }



}
