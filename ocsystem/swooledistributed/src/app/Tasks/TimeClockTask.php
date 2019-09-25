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

class TimeClockTask extends Task
{
    /**
     * 时间钟是否开启
     * @var type 
     */
    private $clockState = false;
    /**
     * 本地时间钟，默认126秒
     * @var type 
     */
    private $timeClock = 125;
    /**
     * 运行时间钟，每秒-1，当时间钟为0的时候重置
     */
    public function runTimeClock()
    {
        if($this->clockState){
            if($this->timeClock <= 0 || $this->timeClock == NULL){
                $this->timeClock = 125;
            }else{
                $this->timeClock--;
            }
        }
        var_dump("=============================当前时间钟时间$this->timeClock=================================");
//         var_dump($this->timeClock);
    }
    
    /**
     * 获取当前时间钟时间
     * @return type
     */
    public function getTimeClock()
    {
        return intval($this->timeClock);
    }
    
    /**
     * 直接修改时间钟
     * @param type $edit_time
     */
    public function editTimeClock($edit_time = 125)
    {
        $this->timeClock = $edit_time;
    }
    
    /**
     * 关闭时间钟,进行校准确认
     */
    public function closeClock()
    {
        $this->clockState = false;
    }
    
    /**
     * 开启时间钟,用于时间校准之后
     */
    public function openClock()
    {
        $this->clockState = true;
    }
    
    /**
     * 获取当前时间钟状态
     * @return type
     */
    public function getClock()
    {
        return $this->clockState;
    }
}