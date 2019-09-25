<?php
/**
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 16-7-14
 * Time: 下午1:58
 */

use Server\CoreBase\PortManager;

$config['ports'][] = [
    'socket_type' => PortManager::SOCK_TCP,
    'socket_name' => '0.0.0.0',
    'socket_port' => 9084,
    'pack_tool' => 'LenJsonPack',
    'route_tool' => 'NormalRoute',
    'middlewares' => ['MonitorMiddleware'],
    'method_prefix' => 'tcp_',
];
//溯源api端
$config['ports'][] = [
    'socket_type' => PortManager::SOCK_HTTP,
    'socket_name' => '0.0.0.0',
    'socket_port' => 9082,
    'route_tool' => 'WeecotRoute',
    'pack_tool' => 'WeecotPack',
    'middlewares' => ['MonitorMiddleware', 'NormalHttpMiddleware'],
    'method_prefix' => 'http_'
];
////企业端
//$config['ports'][] = [
//    'socket_type' => PortManager::SOCK_HTTP,
//    'socket_name' => '0.0.0.0',
//    'socket_port' => 9080,
//    'route_tool' => 'WeecotCompanyRoute',
//    'pack_tool' => 'WeecotPack',
//    'middlewares' => ['MonitorMiddleware', 'NormalHttpMiddleware'],
//    'method_prefix' => 'http_'
//];
////平台端
//$config['ports'][] = [
//    'socket_type' => PortManager::SOCK_HTTP,
//    'socket_name' => '0.0.0.0',
//    'socket_port' => 9081,
//    'route_tool' => 'WeecotSysRoute',
//    'pack_tool' => 'WeecotPack',
//    'middlewares' => ['MonitorMiddleware', 'NormalHttpMiddleware'],
//    'method_prefix' => 'http_'
//];

$config['ports'][] = [
    'socket_type' => PortManager::SOCK_WS,
    'socket_name' => '0.0.0.0',
    'socket_port' => 9083,
    'route_tool' => 'NormalRoute',
    'pack_tool' => 'NonJsonPack',
    'opcode' => PortManager::WEBSOCKET_OPCODE_TEXT,
    'middlewares' => ['MonitorMiddleware', 'NormalHttpMiddleware'],
    'method_prefix' => 'ws_'
];

return $config;