<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 构建默克尔树
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Models\Block;

use Server\CoreBase\Model;
use Server\CoreBase\ChildProxy;
use Server\CoreBase\SwooleException;
use Server\Components\CatCache\TimerCallBack;
use Server\Components\CatCache\CatCacheRpcProxy;

class MerkleTreeModel extends Model
{
    /**
     * 节点数据
     * @var type
     */
    protected $nodeData = array();
    /**
     *  叶子节点数量
     * @var type
     */
    protected $nodeNum = 0;
    /**
     * 默克尔节点
     * @var array
     */
    protected $node = [];
    /**
     * 当被loader时会调用这个方法进行初始化
     * @param $context
     */
    public function initialization(&$context)
    {

        $this->setContext($context);
    }

    /**
     * 设置叶子节点
     * @param type $node
     */
    public function setNodeData(array $node = array())
    {
        if(count($node) == 1){
            $this->nodeData[0] = $node;
            $this->nodeData[1] = $node;
        }
        $this->nodeData = $node;
        return $this;
    }

    /**
     * 设置节点数量
     * @param int $node_num
     * @return $this
     */
    public function setNodeNum($node_num = 0)
    {
        $this->nodeNum = $node_num;
        return $this;
    }

    /**
     * Hash出所有节点数据
     * @param type $data
     * @param type $start
     * @param type $end
     * @return type
     */
    public function merkleStartData()
    {
        $data_size = 0;
        $tree_size = 0;
        if($this->nodeNum == 1){
            $this->nodeData[1] = $this->nodeData[0];
            $this->setNodeNum(2);
        }
        $data_size = $this->nodeNum;
        //先将所有的交易进行一次哈希
        while($data_size > 1){
            for($node_num = 0; $node_num < $data_size; $node_num = $node_num + 2){
                $pointer = $tree_size + $node_num;
                $hsah_str = $this->nodeData[$pointer] . $this->nodeData[$pointer + 1];
                $this->nodeData[] = hash("sha3-256", $hsah_str);
            }
            $tree_size += $data_size;
            $data_size = intval(($data_size + 1) / 2);
        }
        return $this;
    }

    /**
     * 构建默克尔树,从后往前制作树
     * @param type $tree
     */
    public function bulidMerkleTree()
    {
        $merkle_tree = array();
        $total_node = count($this->nodeData);
        $end_point = $this->nodeNum - 1;
        $child_point = 0;
        $father_point = $node_num = $this->nodeNum;
        $tem_tree = array();
        for($child = 0; $child < $this->nodeNum; ++$child){
            $tem_tree[] = $this->nodeData[$child];
        }
        if(count($tem_tree) == 1){
            $merkle_tree = $tem_tree;
        }
        while($father_point < $total_node){
            $merkle_tree[$father_point]["nodeHash"] = $this->nodeData[$father_point];
            $tem_tree[$child_point] && $merkle_tree[$father_point][$child_point] = $tem_tree[$child_point];
            if(empty($tem_tree[$child_point + 1])){
                $child_point += 1;
            } else {
                $merkle_tree[$father_point][$child_point + 1] = $tem_tree[$child_point + 1];
                $child_point += 2;
            }
            ++$father_point;
            if($end_point <= ($child_point - 1) && $father_point < $total_node){
                $tem_tree = $merkle_tree;
                $merkle_tree = array();
                $node_num = intval(($node_num + 1) / 2);
                $end_point += $node_num;
            }
        }
        return $merkle_tree;
    }

    /**
     * 构建默克尔树不需要知道节点数量以及事先哈希
     * @param type $tree
     */
    public function bulidMerkleTreeSimple()
    {
        $merker_tree = [];
        $leaves = [];
        $stems = [];
        $merker_tree = $leaves = $this->nodeData;
        $index = 0;
        while(count($leaves) != 1){
            $stems_hash = '';
            $stems_hash = $leaves[$index];
            $stems_hash .= isset($leaves[$index + 1]) ? $leaves[$index + 1] : $leaves[$index];
            $merker_tree[] = $stems[] = hash("sha3-256", $stems_hash);
            if(!isset($leaves[$index + 1]) || !isset($leaves[$index + 2])){
                $index = 0;
                $leaves = $stems;
                $stems = [];
                continue;
            }
            $index += 2;
        }
        return $merker_tree;
    }
}
