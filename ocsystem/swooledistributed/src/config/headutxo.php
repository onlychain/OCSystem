<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * Date: 19-07-26
 * Time: 下午4:07
 */
$config['head'] = [
    'blockHash'     =>    '',//区块哈希
    'height'        =>     1,//区块高度
    'varsion'       =>     1,//版本号
    'merkleRoot'    =>    '',//默克尔根
    'blockTime'     =>    '',//区块生成时间
    'parentHash'    =>    '',//上一个区块哈希
    'tx'            =>    [],//存储utxo
];
$config['utxo'] = [
    'blockHash'     =>  '',//区块哈希
    'hex'           =>  '',//utxo16进制编码
    'txId'          =>  '',//utxo哈希内容
    'height'        =>   1,//所处区块高度
    'version'       =>   1,//版本号
    'time'          =>   0,//utxo生成时间
    'blockTime'     =>   0,//区块生成时间
    'vin'           =>   [//交易输入内容
        0               =>  [//第一笔交易输入
            'txId'          =>  '',//引用的交易输出的哈希
            'n'             =>   0,//引用的第几笔交易输出
            'scriptSig'     =>  [  //交易验证内容
                'asm'           =>  '',//交易验证密文
                'hex'           =>  '',//16进制密文内容
            ],
        ],
        1               =>  [//第二笔交易输入
            'txId'          =>  '',//引用的交易输出的哈希
            'n'             =>   2,//引用的第几笔交易输出
            'scriptSig'     =>  [//交易验证内容
                'asm'           =>  '',//交易验证密文
                'hex'           =>  '',//16进制密文内容
            ],
        ],
    ],
    'vout'          =>  [//生成的交易输出
        0               =>  [//第一笔交易输出
            'value'         =>  152.00000,//输出金额
            'n'             =>  0,//交易输出序号
            'scriptPubKey'      =>  [//交易输出验证内容
                'asm'               =>  '',//交易输出签名
                'hex'               =>  '',//16进制交易输出签名
                'reqSigs'           =>  1,//交易验证方式
                'type'              =>  '',//交易验证方式
                'address'           =>  [//收款人地址
                    ''
                ],
            ],
        ],
        1               =>  [//第二笔交易输出
            'value'         =>  151.00000,//输出金额
            'n'             =>  1,//交易输出序号
            'scriptPubKey'      =>  [//交易输出验证内容
                'asm'               =>  '',//交易输出签名
                'hex'               =>  '',//16进制交易输出签名
                'reqSigs'           =>  1,//交易验证方式
                'type'              =>  '',//交易验证方式
                'address'           =>  [//收款人地址
                    ''
                ],
            ],
        ],
    ],
];
$config['api'] = [
    'tx'    =>  [
        0   =>  [
            'txId'      =>  '',
            'n'         =>   0,
            'scriptSig' =>  '',
        ],
        1   =>  [
            'txId'      =>  '',
            'n'         =>   0,
            'scriptSig' =>  '',
        ],
    ],
    'from'  =>  '',
    'noce'  =>  123,
    'renoce'=>  0,
    'to'    =>  [
        0   =>  [
            'value'     =>  0.13,
            'scriptPubKey'  =>  'DUP HASH160 [13TweLcVSrsRdfAVsFm1iXLiCTF5KyCYpp] EQUALVERIFY CHECKSIG',
            'type'      =>  'pubkey',
        ],
        1   =>  [
            'address'   =>  '18ZLytQCSNG2fsCPeeZLTB3AitfmocpnMD',
            'value'     =>  0.14,
            'scriptPubKey'  =>  [
                'asm'   =>  '',
            ],
            'reqSigs'   =>  1,
            'type'      =>  'pubkey',
        ],
        2   =>  [
            'address'   =>  '164XrsKTkj7WYXexQgvETxZyEdMsP8xauu',
            'value'     =>  0.15,
            'scriptPubKey'  =>  [
                'asm'   =>  '',
            ],
            'reqSigs'   =>  1,
            'type'      =>  'pubkey',
        ],
    ],
];
return $config;