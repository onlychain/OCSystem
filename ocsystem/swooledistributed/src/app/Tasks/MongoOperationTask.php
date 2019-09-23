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

class MongoOperationTask extends Task
{
    /**
     * 存储链接mongodb句柄
     * @var Object
     */
    protected $mongoDb;

    /**
     * 构建操作命令
     * @var Object
     */
    protected $bulk;

    /**
     * 操作写模式下的错误返回
     * @var Object
     */
    protected $writeConcern;
    
    /**
     * 操作读模式下的数据回调
     * @var Object
     */
    protected $readConcern;
    
//    public function __construct()
//    {
//        parent::__construct();
////        $this->mongoDb = get_instance()->getMongoDb();
////        $this->bulk = get_instance()->getBulk();
////        $this->writeConcern = get_instance()->getWriteConcern();
////        $this->readConcern = get_instance()->getReadConcern();
//    }
    
    /**
     * mongo查询操作
     * @param type $aggregation数据库与数据集合
     * @param type $filter查询的条件
     * @param type $options排序，字段，索引查找等操作
     */
    public function mongoQuery($aggregation = "", $filter = array(), $options = array())
    {
        var_dump(101);
        $return_result = array();//存储返回数据
        //创建查询对象
        $query = new \MongoDB\Driver\Query($filter, $options);
//        $query = get_instance()->getMongoQuery($filter, $options);
        $result = get_instance()->getMongoDb()->executeQuery($aggregation, $query);
        $query = null;
        if(!empty($result)){
            foreach ($result as $k_r => $v_r) {
                $return_result[$k_r] = $v_r;
            }
        }
        return $return_result;
    }
    
    /**
     * mongo插入数据
     * @param type $aggregation数据库与数据集合
     * @param type $data插入数据
     * @param type $is_id是否要启用系统自定义无重复key，1代表启用
     */
    public function mongoInsert($aggregation = "", $insert_data = array(), $is_id = 1)
    {
        if($aggregation == "" || empty($insert_data)){
            return false;
        }
        //是否启用系统自定义随机ID
        if($is_id === 1){
            $_id = new \MongoDB\BSON\ObjectID;
            $insert_data["_id"] = $_id;
        }
        $insert;//构建插入命令
        $result;//存储执行结果
        //构建插入语句
        $insert = get_instance()->getBulk()->insert($insert_data);
        //执行插入命令
        $result = get_instance()->getMongoDb()->executeBulkWrite($aggregation,
                                                                get_instance()->getBulk(),
                                                                get_instance()->getWriteConcern());
        return $result;
    }
    
    /**
     * mongo更新操作
     * @param type $aggregation数据库与数据集合
     * @param type $update_data更新数据与条件
     */
    public function mongoUpdate($aggregation = "", $where = array(), $update_data = array())
    {
        if($aggregation == "" || empty($update_data)){
            return false;
        }
        $update;//构建更新语句
        $result;//执行返回结果
        //构建更新语句
        $update = get_instance()->getBulk();
        $update->update(
            $where,
            ['$set' => $update_data],
            ['multi' => false, 'upsert' => false]
        );
        var_dump($update);

        //执行数据操作
        $result = get_instance()->getMongoDb()->executeBulkWrite($aggregation,
                                                    $update,
                                                    get_instance()->getWriteConcern());
        return $result;
    }
    
    /**
     * mongo删除操作
     * @param type $aggregation数据库与数据集合
     * @param type $where删除条件
     * @param type $limit删除数目，0代表全部删除
     */
    public function mongoDelete($aggregation = "", $where = array(), $limit = 0)
    {
        if($aggregation == "" || empty($where)){
            return false;
        }
        $delete;//用于构建删除语句
        $result;//用于存储返回结果
        $delete = get_instance()->getBulk()->delete(
            $where,
            ['limit' => $limit]
        );
        $result = get_instance()->getMongoDb()->executeBulkWrite( $aggregation,
                                                    get_instance()->getBulk(),
                                                    get_instance()->getWriteConcern()
                                                   );
        return $result;
    }
}