<?php
/**
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 16-7-14
 * Time: 下午1:58
 */

/**
 * 服务器设置
 */
$config['name'] = 'weecot3';
$config['address'] = 'c610fb6d667856b729fbe8cff851bc791dcb8f16';//1muH6KmEJv6tnWaY7h6ZrWUi5vdVrrfzp  fb9d615e334d11787ae06265ed6d90ddf1a894e7
$config['node']["ip"] = '120.79.242.5';
$config['server']['send_use_task_num'] = 500;
$config['server']['set'] = [
    'log_file' => LOG_DIR."/swoole.log",
    'pid_file' => PID_DIR . '/server.pid',
    'log_level' => 5,
    'reactor_num' => 4, //reactor thread num
    'worker_num' => 10,    //worker process num
    'backlog' => 128,   //listen backlog
    'open_tcp_nodelay' => 1,
    'socket_buffer_size' => 1024 * 1024 * 1024,
    'dispatch_mode' => 2,
    'task_worker_num' => 1,
    'task_max_request' => 5000,
    'enable_reuse_port' => true,
    'heartbeat_idle_time' => 480,//2分钟后没消息自动释放连接
    'heartbeat_check_interval' => 60,//1分钟检测一次
    'max_connection' => 65535
];
//协程超时时间
$config['coroution']['timerOut'] = 2000;

//是否启用自动reload
$config['auto_reload_enable'] = false;

//是否允许访问Server中的Controller，如果不允许将禁止调用Server包中的Controller
$config['allow_ServerController'] = true;
//是否允许监控流量数据
$config['allow_MonitorFlowData'] = true;
return $config;