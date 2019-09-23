<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * Date: 19-07-26
 * Time: 下午4:07
 */
$config['coinbase'] = [
    'privateKey'    =>  '008e9ae3d432ec2ef04828229f95ecdacf45144fa042560435a9cc0f6d9103b6',
    'publicKey'     =>  '03cc3b20704aa730d643a8d3ec329a40f40d9a82b434ec684e2e03d86006185ce3',
    'txId'  => '',
    'lockTime' => 0,
    'vin' => [
        0   =>  [
            'coinbase' => 'No one breather who is worthier.',
            'sequence' => 0
        ],
    ],
    'vout' => [
        0   =>  [
            'value' => 52000000000,
            'type' => '1',
//            'address' => '0x552204a2A68A43E17523B44aEE190B76eD583C61'
            'address' => '1muH6KmEJv6tnWaY7h6ZrWUi5vdVrrfzp'
        ],
    ],
    'ins'   =>  '',
    'time' => 0
];
return $config;