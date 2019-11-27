<?php
namespace app\Controllers;

use app\Models\AppModel;
use Server\CoreBase\Controller;
use Server\Asyn\TcpClient\SdTcpRpcPool;
use Server\CoreBase\SwooleException;
use MongoDB;
//自定义进程
use app\Process\BlockProcess;
use app\Process\PurseProcess;
use app\Process\TradingProcess;
use app\Process\TradingPoolProcess;
use app\Process\ConsensusProcess;
use app\Process\TimeClockProcess;
use app\Process\CoreNetworkProcess;
use app\Process\NodeProcess;
use app\Process\PeerProcess;
use Server\Components\Process\ProcessManager;

use Server\Components\CatCache\CatCacheRpcProxy;

class TestController extends Controller
{
    protected $Validation;//交易验证模型
    protected $TradingModel;//交易处理模型
    protected $TradingEncodeModel;//交易序列化模型
    protected $Index;//交易序列化模型
    protected function initialization($controller_name, $method_name)
    {
        parent::initialization($controller_name, $method_name);
        $this->Validation = $this->loader->model('Trading/ValidationModel', $this);
        $this->Index = $this->loader->model('Index/IndexModel', $this);
        $this->TradingModel = $this->loader->model('Trading/TradingModel', $this);
        $this->TradingEncodeModel = $this->loader->model('Trading/TradingEncodeModel', $this);
    }

    public function http_testAddress()
    {
//        $private = $this->http_input->getAllPostGet();
//        if(empty($private)){
//            return $this->http_output->notPut('', '请输入32字节/64个字符的十六进制字符串');
//        }
//        //$private = '303e020100301006072a8648ce3d020106052b8104000a042730250201010420eb2f8e66a246ed9b586f460cbca6df26e9fe8927616ec319e440addb6494bfc9';
//        $private_key = hex2bin($private['address']); // 假设这是自己的私钥
//        $public_key = bin2hex(secp256k1_pubkey_create($private_key, true)); // 假设这是自己的公钥

        $public_key_hash160 = hash('ripemd160', hash('sha256', '03e837b30166c858e2c7b5899330391038ee0532e5c8d10cc8448c300b8599d4d5'), true);
        var_dump(bin2hex($public_key_hash160));


        $hash256 = hash('sha256', '431ecec94e0a920a7972b084dcfabbd69f616912');
        var_dump($hash256);


        $public_key_hash160 = hash('ripemd160', 'a6dd4a855f74a00df0e21a328c25cd3d6bc8a50b1144a03d195996ea5b2f40d0', true);
        var_dump(bin2hex($public_key_hash160));


        $public_key_hash160 = hash('ripemd160', $hash256, true);
        var_dump(bin2hex($public_key_hash160));
        $res = [
//            'private_key' => $private,
//            'public_key' => $public_key,
            'address' => $public_key_hash160,
        ];
        return $this->http_output->lists($res);
    }

    public function http_testAddress2()
    {
        $private = $this->http_input->getAllPostGet();
        if(empty($private)){
            return $this->http_output->notPut('', '请输入32字节/64个字符的十六进制字符串');
        }
        //$private = '303e020100301006072a8648ce3d020106052b8104000a042730250201010420eb2f8e66a246ed9b586f460cbca6df26e9fe8927616ec319e440addb6494bfc9';
        $private_key = hex2bin($private['address']); // 假设这是自己的私钥
        $public_key = bin2hex(secp256k1_pubkey_create($private_key, true)); // 假设这是自己的公钥
        $public_key_hash160 = hash('ripemd160', hash('sha256', hex2bin($public_key), true));
        $res = [
            'private_key' => $private['address'],
            'public_key' => $public_key,
            'address' => $public_key_hash160,
        ];
        var_dump($res);
        return $this->http_output->lists($res);
    }

    /**
     * 查看钱包接口
     */
    public function http_checkProus()
    {
        $purses = CatCacheRpcProxy::getRpc()->offsetGet('purses');
        return $this->http_output->lists($purses);
    }

    /**
     * 序列化交易接口
     */
    public function http_encodeTrading()
    {
        $trading = $this->http_input->getAllPostGet();
        $res = $this->TradingEncodeModel->setVin($trading['tx'])
                                        ->setVout($trading['to'])
                                        ->setIns($trading['ins'])
                                        ->setTime($trading['time'])
                                        ->setLockTime($trading['lockTime'])
                                        ->setPrivateKey($trading['privateKey'])
                                        ->setPublicKey($trading['publicKey'])
                                        ->encodeTrading($trading);
        if($res == false){
            return $this->http_output->notPut('', '交易有误');
        }
        return $this->http_output->lists($res);
    }

    /**
     * 反序列化交易接口
     */
    public function http_decodeTrading()
    {
        $trading = $this->http_input->getAllPostGet();
        $res = $this->TradingEncodeModel->decodeTrading($trading['trading']);
        if($res == false){
            return $this->http_output->notPut('', '交易有误.');
        }
        return $this->http_output->lists($res);
    }

    /**
     * 接收交易接口
     */
    public function http_receivingTransactions()
    {
        $test_utxo = [];
        $trading = [];//存入
        $insert_res = [];//插入数据库结果
//        $trading_data = $this->http_input->getAllPostGet('text');
        $trading_data = $this->http_input->getAllPostGet();
        //验证是否有上传接口数据
        if(empty($trading_data)){
            return $this->http_output->notPut(1004);
        }

        //做交易所有权验证
//        $validation = $this->Validation->varifySign($trading_data);
//        if(!$validation['IsSuccess']){
//            return $this->http_output->notPut($validation['Code'], $validation['Message']);
//        }
        //广播交易

        //空着等对接
        if($trading_data['renoce'] != ''){
            //执行撤回交易
            $recall = ProcessManager::getInstance()
                                    ->getRpcCall(TradingPoolProcess::class)
                                    ->recallTrading($trading_data);
            if(!$recall['IsSuccess']){
                return $this->http_output->notPut('', '该交易无法重置.');
            }
        }
        //反序列化交易
        $decode_trading = $this->TradingEncodeModel->decodeTrading($trading_data['trading']);
        if($decode_trading == false){
            return $this->http_output->notPut('', '交易有误.');
        }
        //验证交易是否可用$decode_trading;
        $check_res = ProcessManager::getInstance()
                                ->getRpcCall(TradingProcess::class)
                                ->checkTrading($decode_trading, $trading_data['address']);
        if(!$check_res['IsSuccess']){
            return $this->http_output->notPut($check_res['Code'], $check_res['Message']);
        }
        //交易入库
        $insert_res = $this->TradingModel->createTradingEecode($trading_data);
        if(!$insert_res['IsSuccess']){
            return $this->http_output->notPut($insert_res['Code'], $insert_res['Message']);
        }
        return $this->http_output->yesPut();
    }

    /**
     *
     */
    public function http_pack()
    {

        var_dump($this->context);

        $check_res = ProcessManager::getInstance()
                    ->getRpcCall(ConsensusProcess::class, true)
                    ->coreNode();
    }

    /**
     *  测试接口
     */
    public function http_auditInit()
    {
//        $check_res = ProcessManager::getInstance()
//            ->getRpcCall(TradingProcess::class)
//            ->overloadPurse();
        $purses = CatCacheRpcProxy::getRpc()->offsetGet('purses');
        return $this->http_output->lists($purses);


    }

    public function http_checkScriptSig()
    {
        // ======签名过程======
        $a = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $private_key = hex2bin('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b999'); // 假设这是自己的私钥
        $public_key = bin2hex(secp256k1_pubkey_create($private_key, true)); // 假设这是自己的公钥

        $public_key_hash160 = hash('ripemd160', hash('sha256', hex2bin($public_key), true)); // 再进行base58就是钱包地址

        $scriptPubKey_bytecode = script_compile("DUP HASH160 [$public_key_hash160] EQUALVERIFY CHECKSIG"); // 假设这是前一输出的脚本
        $temp_script = script_remove_codeseparator($scriptPubKey_bytecode); // 移除脚本中所有OP_CODESEPARATOR
        // 把上面的$temp_script放到本次交易对应的输入上，清空其他输入的脚本，并序列化整个交易单，图解参考：https://www.jianshu.com/p/3fa4bb1899ec
        $raw_tx = ''; // 假设序列化结果是这样（列举具体示例太麻烦，这里结果无论是什么都不会影响验证结果）
        $msg = hash('sha256', hash('sha256', hex2bin($raw_tx), true), true); // 进行2次sha256，得到32字节的消息
        $signature = bin2hex(secp256k1_sign($private_key, $msg)); // 得到签名
        var_dump(bin2hex($private_key));
        var_dump($public_key);
        var_dump($public_key_hash160);
        var_dump($signature);


        $scriptSig_bytecode = script_compile("[$signature] [$public_key]"); // 得到输入脚本，并写到对应的输入上


        // ======验证过程======
        $ctx = script_create_context();
        script_set_checksig_callback($ctx, function($subscript) {
            // 在这个回调里对交易单进行序列化，这里的$subscript相当于上面的$temp_script
            $raw_tx = ''; // 序列化结果应该和签名过程一样，否则验证失败
            $msg = hex2bin('5df6e0e2761359d30a8275058e299fcc0381534545f55cf43e41983f5d4c9456');//hash('sha256', hash('sha256', hex2bin($raw_tx), true), true);
            return $msg; // OP_CHECKSIG需要这个消息才能工作，因为内部调用了secp256k1_verify($public_key, $msg, $signature)
        });
        if (!script_eval($ctx, $scriptSig_bytecode)) die('验证失败1');
        if (!script_eval($ctx, $scriptPubKey_bytecode)) die('验证失败2');
        if (!script_verify($ctx)) die('验证失败3'); // 所有的script_eval不能失败，并且script_verify为true才算验证通过
        echo '验证通过' . PHP_EOL;
    }

    public function http_testEncode()
    {
        $private = '0b6ebd47c7557890f37814a6c0fb1fa7de7ad9bc0be87a6f1d805e7bf254a0a5';
        $private_key = hex2bin($private); // 假设这是自己的私钥
        $public_key = bin2hex(secp256k1_pubkey_create($private_key, true)); // 假设这是自己的公钥
        $public_key_hash160 = hash('ripemd160', hash('sha256', hex2bin($public_key), true));
        $scriptPubKey_bytecode = script_compile("DUP HASH160 [$public_key_hash160] EQUALVERIFY CHECKSIG"); // 假设这是前一输出的脚本
        $temp_script = script_remove_codeseparator($scriptPubKey_bytecode);
        $aaa = '6666';
        $raw_tx = $aaa.bin2hex($temp_script); // 假设序列化结果是这样（列举具体示例太麻烦，这里结果无论是什么都不会影响验证结果）
        $msg = hash('sha256', hash('sha256', hex2bin($raw_tx), true), true); // 进行2次sha256，得到32字节的消息
        $signature = bin2hex(secp256k1_sign($private_key, $msg)); // 得到签名
        $scriptSig_bytecode = script_compile("[$signature] [$public_key]"); // 得到输入脚本，并写到对应的输入上


        return $this->http_output->lists([bin2hex($scriptSig_bytecode), bin2hex($scriptPubKey_bytecode)]);
    }

    public function http_testDecode()
    {
        $scriptSig_bytecode = hex2bin('463044022032fd4fcb3cca02c326a0eff275999513d51d959d2b60b7843774b4f9a275dd2302206448e12f1f8f314d347ac5025095851206de9b17b2c1a1dfc5d1677f1892592b210215eb10904fe9cb1603aaf639bd63db3b01e63ab69b3c3f931a0a0df917d7833d');
        $scriptPubKey_bytecode = hex2bin('76a914c610fb6d667856b729fbe8cff851bc791dcb8f1688ac');
        $aaa = '6666';
        $ctx = script_create_context();
        script_set_checksig_callback($ctx, function($subscript) use($aaa){
            var_dump($subscript);
//            // 在这个回调里对交易单进行序列化，这里的$subscript相当于上面的$temp_script
            $raw_tx = $aaa.bin2hex($subscript); // 序列化结果应该和签名过程一样，否则验证失败
            $msg = hash('sha256', hash('sha256', hex2bin($raw_tx), true), true);
            return $msg; // OP_CHECKSIG需要这个消息才能工作，因为内部调用了secp256k1_verify($public_key, $msg, $signature)
        });
        if (!script_eval($ctx, $scriptSig_bytecode)) echo('验证失败1');
        if (!script_eval($ctx, $scriptPubKey_bytecode)) echo('验证失败2');
        if (!script_verify($ctx)) echo('验证失败3'); // 所有的script_eval不能失败，并且script_verify为true才算验证通过
        echo '验证通过' . PHP_EOL;

    }

    public function http_generateSign()
    {
        $this->MongoUrl = 'mongodb://127.0.0.1:27017';
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->Trading = $this->MongoDB->selectCollection('tradings', 'trading');
        $ret = $this->Trading->updateOne(array('_id' => '8d25e232eea10ca0f98b103c662e12016a3741c54f70c215b0f710874a863731'), array('$set' => array('noce' => 123)));
        var_dump($ret);
    }

    public function http_overloadPurse()
    {
        $check_res = ProcessManager::getInstance()
            ->getRpcCall(TradingProcess::class)
            ->overloadPurse();
        return $this->http_output->lists($check_res);
    }

    public function http_p2pdemo()
    {
        bitnet_connected_node_listener(function($ipandport){
            echo "php--bitnet_connected_node_listener: " . $ipandport."\n";
            bitnet_send_node_message($ipandport,"testcommandrecv","testdata",strlen("testdata"));
            return true;
        });

/// 监听节点掉线回调
/// 回调函数： void(std::string) 参数：ipAndPort 无返回值
        bitnet_disconnected_node_listener(function($ipandport){
            echo "php--bitnet_disconnected_node_listener: " . $ipandport."\n";
        });

/// 监听数据回调
///回调函数：void(std::string,std::string, char*, int)有四个参数  ipAndPort, command, data， datalen
/// command：   自定义的指令信息
/// data：      传输的数据
/// datalen：   数据长度
        bitnet_message_listener(function($ipandport,$command,$data,$datalen){
            echo "php--addr: " . $ipandport."\n";
            echo "php--command: " . $command."\n";
            echo "php--data: " . $data."\n";
            echo "php--datalen: " . $datalen."\n";
            //disconnect_node($ipandport);
        });

//初始化节点IP
        bitnet_set_seednode("120.79.242.5");
/// 初始化网络
        bitnet_start_network();
        $t = 10;
        while($t<=15){
            sleep(5);
            $t+=5;
            echo "php--t: " . $t." max=15\n";
        }
/// 关闭网络
//bitnet_stop_network();

        while(true){
            sleep(5);
        }
    }

    /**
     * 测试更新节点
     */
    public function http_rushNode()
    {
        ProcessManager::getInstance()
                        ->getRpcCall(NodeProcess::class)
                        ->examinationNode();

        ProcessManager::getInstance()
                        ->getRpcCall(NodeProcess::class)
                        ->rotationSuperNode(4);
    }

    public function http_openClock()
    {
        ProcessManager::getInstance()
            ->getRpcCall(TimeClockProcess::class, true)
            ->runTimeClock();
    }

    public function http_testCache()
    {
        CatCacheRpcProxy::getRpc()->offsetGet('Purse');
        CatCacheRpcProxy::getRpc()['Purse'];

    }

    public function http_testNewCache()
    {
        CatCacheRpcProxy::getRpc()->offsetGet('Purse');
        CatCacheRpcProxy::getRpc()['Purse'];
        for($i =0; $i <= 100; ++$i){
            CatCacheRpcProxy::getRpc()['test'.$i] = [$i => $i];
        }

        for($i =0; $i <= 100; ++$i){
//            var_dump(CatCacheRpcProxy::getRpc()->offsetGet('test'.$i));
            unset(CatCacheRpcProxy::getRpc()['test'.$i]);
        }

//        foreach (CatCacheRpcProxy::getRpc()['test'] as $test_key => $test_val){
//            unset(CatCacheRpcProxy::getRpc()['test'][$test_key]);
//        }
    }

    public function http_testPurse()
    {
        $where = [
            '_id' => '1muH6KmEJv6tnWaY7h6ZrWUi5vdVrrfzp',
            'trading' => [
                '$elemMatch' => [
                    'txId' => [
                        '$nin' => []
                    ]
                ],

            ],
        ];
        $data = ['trading' =>
            [
            '$slice' => [
                1,6
            ]
            ]
        ];


        $purse_res = ProcessManager::getInstance()
            ->getRpcCall(PurseProcess::class)
            ->getPurseList($where, $data, 1, 1);

    }

    public function http_testInc()
    {
        $data = ['$push' => ['trading' => ['txId' => '111']]];
        $where = ['_id' => '1muH6KmEJv6tnWaY7h6ZrWUi5vdVrrfzp'];
        ProcessManager::getInstance()
                        ->getRpcCall(PurseProcess::class)
                        ->updatePurse($where, $data);
    }

    public function http_testBroadcast()
    {
//        $nodes = ProcessManager::getInstance()
//                                ->getRpcCall(PeerProcess::class)
//                                ->getNodes();
//        var_dump($nodes);
        ProcessManager::getInstance()
                        ->getRpcCall(PeerProcess::class)
                        ->broadcast(json_encode(['broadcastType' => 'Trading', 'Data' => 'hello this']));

    }

    public function http_testGetData()
    {
        $nodes = ProcessManager::getInstance()
            ->getRpcCall(PeerProcess::class)
            ->getNodes();
        var_dump($nodes);

        ProcessManager::getInstance()
            ->getRpcCall(PeerProcess::class, true)
            ->p2pgetVal('BlockTopHeight', []);
    }

    public function http_testIndex()
    {
        var_dump('begin');
        $aa = $this->Index->index();
        var_dump($aa);
    }

    public function http_testTimeClock()
    {
        ProcessManager::getInstance()
                        ->getRpcCall(TimeClockProcess::class, true)
                        ->runTimeClock();
    }

    public function http_testSuperRush()
    {
        $res = ProcessManager::getInstance()
                        ->getRpcCall(CoreNetworkProcess::class)
                        ->rushSuperNodeLink();
        var_dump($res);
    }

    public function http_testPoolSend()
    {
        $count = 1;
        while(true){
//            var_dump(date('Yms his', time()));
            ProcessManager::getInstance()
                        ->getRpcCall(CoreNetworkProcess::class)
                        ->rushSuperNodeLink();
            $aa = ProcessManager::getInstance()
                    ->getRpcCall(CoreNetworkProcess::class)
                    ->sendToSuperNode(json_encode([111=>111]), $this->context, 'TestController', 'testPool', true);
            var_dump($aa);
            if ($count % 126 == 0){
                var_dump('asjkdhaklhjsdgajhg');
                ProcessManager::getInstance()
                            ->getRpcCall(CoreNetworkProcess::class)
                            ->clearSuperNode(['1muH6KmEJv6tnWaY7h6ZrWUi5vdVrrfzp']);
            }
            $count++;
            sleepCoroutine(1000);
        }

    }

    public function tcp_testPool($parem)
    {
        var_dump($parem);
        var_dump('收到');
        return $this->send($parem);
    }

    public function http_getKADList()
    {
		var_dump('begin get_nodes');
		$inst = ProcessManager::getInstance();
		var_dump('getInstance');
        $rpc = $inst->getRpcCall(PeerProcess::class);
		var_dump('getRpcCall');
		$nodes = $rpc->getNodes();
		/*$nodes = ProcessManager::getInstance()
                                ->getRpcCall(PeerProcess::class)
                                ->getNodes();*/
		var_dump('end get_nodes');
		var_dump($nodes);
       return $this->http_output->lists($nodes);
    }

    public function http_testDropPool()
    {
        ProcessManager::getInstance()
            ->getRpcCall(CoreNetworkProcess::class)
            ->clearSuperNode(['1muH6KmEJv6tnWaY7h6ZrWUi5vdVrrfzp']);
    }

    public function http_broadcastBlock()
    {
        //获取当前最高的区块
        $block = ProcessManager::getInstance()
                                ->getRpcCall(BlockProcess::class)
                                ->getBlockHeadInfo(['height' => 547], ['_id' => 0]);
        var_dump($block);
        //广播该区块
        $data = [
            'broadcastType' =>  'Block',
            'Data'          =>  $block['Data'],
        ];
        ProcessManager::getInstance()
            ->getRpcCall(PeerProcess::class)
            ->broadcast(json_encode($data));
    }

    public function http_broadcastBlock2()
    {
        //获取当前最高的区块
        $block = ProcessManager::getInstance()
            ->getRpcCall(BlockProcess::class)
            ->getBlockHeadInfo(['height' => 548], ['_id' => 0]);
        var_dump($block);
        //广播该区块
        $data = [
            'broadcastType' =>  'Block',
            'Data'          =>  $block['Data'],
        ];
        ProcessManager::getInstance()
            ->getRpcCall(PeerProcess::class)
            ->broadcast(json_encode($data));
    }

    public function http_broadcastTrading()
    {
        $args = [
            'b9225b4eeaa9fe086a2583f5ebace8fb2294fdec20bab244d0b1190cd8c7c469',
//            'c3175fd9d3442c46973e2561633664cb1719701404baa850b137e8dd0c9eae70'
        ];
        $where = ['_id' => ['$in' => $args]];
        $data = ['trading' => 1, '_id' => 0];
        $result = ProcessManager::getInstance()
                                ->getRpcCall(TradingProcess::class)
                                ->getTradingList($where, $data, 1, count($args));

        var_dump($result['Data']);
        $data = [
            'broadcastType' =>  'Trading',
            'Data'          =>  [
                'trading' => $result['Data'][0]['trading'],
                'renoce' => '',
                'noce'  =>  'hjkhjkhuiphuiphuopiph',
                'address'   =>  '1muH6KmEJv6tnWaY7h6ZrWUi5vdVrrfzp'
            ]
        ];
        ProcessManager::getInstance()
            ->getRpcCall(PeerProcess::class)
            ->broadcast(json_encode($data));
    }

    public function http_testSig()
    {
        // ======签名过程======
        $private_key = hex2bin('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b999'); // 假设这是自己的私钥
        $public_key = bin2hex(secp256k1_pubkey_create($private_key, true)); // 假设这是自己的公钥

        $public_key_hash160 = hash('ripemd160', hash('sha256', hex2bin($public_key), true)); // 再进行base58就是钱包地址

        $scriptPubKey_bytecode = script_compile("DUP HASH160 [$public_key_hash160] EQUALVERIFY CHECKSIG"); // 假设这是前一输出的脚本
        $temp_script = script_remove_codeseparator($scriptPubKey_bytecode); // 移除脚本中所有OP_CODESEPARATOR
        // 把上面的$temp_script放到本次交易对应的输入上，清空其他输入的脚本，并序列化整个交易单，图解参考：https://www.jianshu.com/p/3fa4bb1899ec
        $raw_tx = '6666'; // 假设序列化结果是这样（列举具体示例太麻烦，这里结果无论是什么都不会影响验证结果）
        $msg = hash('sha256', hash('sha256', hex2bin($raw_tx), true), true); // 进行2次sha256，得到32字节的消息
        $signature = bin2hex(secp256k1_sign($private_key, $msg)); // 得到签名

        var_dump(bin2hex($private_key));
        var_dump(bin2hex($public_key));
        var_dump(bin2hex($public_key_hash160));


        $scriptSig_bytecode = script_compile("[$signature] [$public_key]"); // 得到输入脚本，并写到对应的输入上

        return $this->http_output->lists(['sig' => $signature, 'scriptSig' => bin2hex($scriptSig_bytecode),'scriptPubKey'=>bin2hex($scriptPubKey_bytecode)]);

    }

    public function http_testCheck()
    {

        $p = $this->http_input->getAllPostGet();
        var_dump($p);
        // ======验证过程======
        $ctx = script_create_context();
        script_set_checksig_callback($ctx, function($subscript) {
            // 在这个回调里对交易单进行序列化，这里的$subscript相当于上面的$temp_script
            $raw_tx = '6666'; // 序列化结果应该和签名过程一样，否则验证失败
            //$msg = hex2bin('5df6e0e2761359d30a8275058e299fcc0381534545f55cf43e41983f5d4c9456');//hash('sha256', hash('sha256', hex2bin($raw_tx), true), true);
            $msg = hash('sha256', hash('sha256', hex2bin($raw_tx), true), true);
            return $msg; // OP_CHECKSIG需要这个消息才能工作，因为内部调用了secp256k1_verify($public_key, $msg, $signature)
        });
        if (!script_eval($ctx, hex2bin($p['scriptSig']))) return $this->http_output->notPut('','scriptSig验证失败');
        if (!script_eval($ctx, hex2bin($p['scriptPubKey']))) return $this->http_output->notPut('','scriptPubKey验证失败');
        if (!script_verify($ctx)) return $this->http_output->notPut('','sig验证失败'); // 所有的script_eval不能失败，并且script_verify为true才算验证通过
        return $this->http_output->yesPut();
    }
}