<?php

// +----------------------------------------------------------------------
// | ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2019 
// +----------------------------------------------------------------------

// +----------------------------------------------------------------------

// +----------------------------------------------------------------------
// | 

// +----------------------------------------------------------------------

namespace app\admin\controller;

use app\admin\service\NodeService;
use library\Controller;
use library\tools\Data;
use think\Db;
use PHPExcel;//tp5.1用法
use PHPExcel_IOFactory;
use think\facade\Request;

/**
 * 交易中心
 * Class Users
 * @package app\admin\controller
 */
class Deal extends Controller
{

    /**
     * 订单列表
     *@auth true
     *@menu true
     */
    public function order_list()
    {
        $this->title = '订单列表';
        $where = [];
        if(input('oid/s','')) $where[] = ['u.tel','like','%'.input('oid','').'%'];
        if(input('username/s','')) $where[] = ['u.username','like','%' . input('username/s','') . '%'];
        if(input('addtime/s','')){
            $arr = explode(' - ',input('addtime/s',''));
            $where[] = ['xc.addtime','between',[strtotime($arr[0]),strtotime($arr[1])]];
        }

        $user = session('admin_user');
        if($user['authorize']==2  && !empty($user['nodes']) ){
            //获取直属下级
            $mobile = $user['phone'];
            $uid = db('xy_users')->where('tel', $mobile)->value('id');

            $ids1  = db('xy_users')->where('parent_id', $uid)->field('id')->column('id');

            $ids1 ? $ids2  = db('xy_users')->where('parent_id','in', $ids1)->field('id')->column('id') : $ids2 = [];

            $ids2 ? $ids3  = db('xy_users')->where('parent_id','in', $ids2)->field('id')->column('id') : $ids3 = [];

            $ids3 ? $ids4  = db('xy_users')->where('parent_id','in', $ids3)->field('id')->column('id') : $ids4 = [];

            $idsAll = array_merge([$uid],$ids1,$ids2 ,$ids3 ,$ids4);  //所有ids
            $where[] = ['xc.uid','in',$idsAll];


            //echo '<pre>';
            //var_dump($where,$idsAll,$ids3,$ids4);die;
        }

        $this->_query('xy_convey')
            ->alias('xc')
            ->leftJoin('xy_users u','u.id=xc.uid')
            
            ->leftJoin('xy_goods_list g','g.id=xc.goods_id')
            ->leftJoin('xy_goods_cate bg','bg.id=g.cid')
            ->leftJoin('xy_bankinfo b','b.uid=xc.uid')
            ->field('xc.*,u.username,u.balance,g.goods_name,g.goods_price,bg.level,u.id uid,u.tel,b.username as usernames')
            
            ->where('bg.level',0)
          
            ->whereOr('xc.goods_id','')
            ->where($where)
           
            ->order('addtime desc')
            ->page();
    }

    /**
     * 审核通过
     * @auth true
     * @menu true
     */
    public function jiedong()
    {
        $this->applyCsrfToken();
        $oid = input('post.id/s','');

        if ($oid) {
            $oinfo = Db::name('xy_convey')->where('id',$oid)->find();
            if ( $oinfo['status'] != 5 ) {
                return $this->error('该订单未冻结!');
            }
            Db::name('xy_convey')->where('id',$oinfo['id'])->update(['status'=>1]);
            //
            $res1 = Db::name('xy_users')
                ->where('id', $oinfo['uid'])
                ->inc('balance',$oinfo['num']+$oinfo['commission'])
                ->dec('freeze_balance',$oinfo['num']+$oinfo['commission']) //冻结商品金额 + 佣金
                ->update(['deal_status'=>1]);
            $this->deal_reward($oinfo['uid'],$oinfo['id'],$oinfo['num'],$oinfo['commission']);
            return $this->success('解冻成功!');
        }
        return $this->success('解冻成功!');
    }

        /**
     * 手动解冻
     * @auth true
     * @menu true
     */
    public function bohui()
    {
        $this->applyCsrfToken();
        $oid = input('post.id/s','');

        if ($oid) {
            $oinfo = Db::name('xy_convey')->where('id',$oid)->find();
            if ( $oinfo['status'] == 6 ) {
                return $this->error('该订单以驳回!');
            }
            Db::name('xy_convey')->where('id',$oinfo['id'])->update(['status'=>1]);
            //
            $res1 = Db::name('xy_users')
                ->where('id', $oinfo['uid'])
                ->inc('balance',$oinfo['num'])
                ->dec('freeze_balance',$oinfo['num']) //冻结商品金额 + 佣金
                ->update(['deal_status'=>1]);
            $this->deal_reward($oinfo['uid'],$oinfo['id'],$oinfo['num'],$oinfo['commission']);
            return $this->success('审核驳回!');
        }
        return $this->success('审核驳回!');
    }
    /**
     * 交易返佣
     * @return void
     */
    public function deal_reward($uid,$oid,$num,$cnum)
    {

        Db::name('xy_balance_log')->where('oid',$oid)->update(['status'=>1]);

        //将订单状态改为已返回佣金
        Db::name('xy_convey')->where('id',$oid)->update(['c_status'=>1]);
        Db::name('xy_reward_log')->insert(['oid'=>$oid,'uid'=>$uid,'num'=>$num,'addtime'=>time(),'type'=>2]);//记录充值返佣订单
        /************* 发放交易奖励 *********/
        $userList = model('admin/Users')->parent_user($uid,5);
        //echo '<pre>';
        //var_dump($userList);die;
        if($userList){
            foreach($userList as $v){
                if($v['status']===1){
                    Db::name('xy_reward_log')
                        ->insert([
                            'uid'       => $v['id'],
                            'sid'       => $v['pid'],
                            'oid'       => $oid,
                            'num'       => $num*config($v['lv'].'_d_reward'),
                            'lv'        => $v['lv'],
                            'type'      => 2,
                            'status'    => 1,
                            'addtime'   => time(),
                        ]);
                }

                //
                $num3 = $num*config($v['lv'].'_d_reward'); //佣金
                $res = Db::name('xy_users')->where('id',$v['id'])->where('status',1)->setInc('balance',$num3);
                $res2 = Db::name('xy_balance_log')->insert([
                    'uid'           => $v['id'],
                    'oid'           => $oid,
                    'num'           => $num3,
                    'type'          => 6,
                    'status'        => 1,
                    'addtime'       => time()
                ]);

            }
        }
        /************* 发放交易奖励 *********/
    }


    /**
     * 处理用户交易订单
     */
    public function do_user_order()
    {
        $this->applyCsrfToken();
        $oid = input('post.id/s','');
        $status = input('post.status/d',1);

        
        if(!\in_array($status,[3,4])) return $this->error('参数错误');
        $res = model('Convey')->do_order($oid,$status);
        if($res['code']===0)
            return $this->success('操作成功');
        else
            return $this->error($res['info']);
    }

    /**
     * 交易控制
     * @auth true
     * @menu true
     */
    public function deal_console()
    {
        $this->title = '交易控制';
        if(request()->isPost()){
            $deal_min_balance = input('post.deal_min_balance/d',0);
            $deal_timeout     = input('post.deal_timeout/d',0);
            $deal_min_num     = input('post.deal_min_num/d',0);   
            $deal_max_num     = input('post.deal_max_num/d',0);
            $deal_count       = input('post.deal_count/d',0);
            $deal_reward_count= input('post.deal_reward_count/d',0);
            $deal_feedze      = input('post.deal_feedze/d',0);
            $deal_error       = input('post.deal_error/d',0);
            $deal_commission  = input('post.deal_commission/f',0);
            $_1reward  = input('post.1_reward/f',0);
            $_2reward  = input('post.2_reward/f',0);
            $_3reward  = input('post.3_reward/f',0);
            $_1_d_reward  = input('post.1_d_reward/f',0);
            $_2_d_reward  = input('post.2_d_reward/f',0);
            $_3_d_reward  = input('post.3_d_reward/f',0);
            $_4_d_reward  = input('post.4_d_reward/f',0);
            $_5_d_reward  = input('post.5_d_reward/f',0);

            //可以加上限制条件
            if($deal_commission>1||$deal_commission<0) return $this->error('参数错误'); 
            setconfig(['deal_min_balance'],[$deal_min_balance]);
            setconfig(['deal_timeout'],[$deal_timeout]);
            setconfig(['deal_min_num'],[$deal_min_num]);
            setconfig(['deal_max_num'],[$deal_max_num]);
            setconfig(['deal_reward_count'],[$deal_reward_count]);
            setconfig(['deal_count'],[$deal_count]);
            setconfig(['deal_feedze'],[$deal_feedze]);
            setconfig(['deal_error'],[$deal_error]);
            setconfig(['deal_commission'],[$deal_commission]);
            setconfig(['1_reward'],[$_1reward]);
            setconfig(['2_reward'],[$_2reward]);
            setconfig(['3_reward'],[$_3reward]);
            setconfig(['1_d_reward'],[$_1_d_reward]);
            setconfig(['2_d_reward'],[$_2_d_reward]);
            setconfig(['3_d_reward'],[$_3_d_reward]);
            setconfig(['4_d_reward'],[$_4_d_reward]);
            setconfig(['5_d_reward'],[$_5_d_reward]);
            setconfig(['vip_1_commission'],[input('post.vip_1_commission/f')]);
            setconfig(['vip_2_commission'],[input('post.vip_2_commission/f')]);
            setconfig(['vip_2_num'],[input('post.vip_2_num/f')]);
            setconfig(['vip_3_commission'],[input('post.vip_3_commission/f')]);
            setconfig(['vip_3_num'],[input('post.vip_3_num/f')]);
            setconfig(['master_cardnum'],[input('post.master_cardnum')]);
            setconfig(['master_name'],[input('post.master_name')]);
            setconfig(['master_bank'],[input('post.master_bank')]);
            setconfig(['master_bk_address'],[input('post.master_bk_address')]);
            setconfig(['deal_zhuji_time'],[input('post.deal_zhuji_time')]);
            setconfig(['deal_shop_time'],[input('post.deal_shop_time')]);
            setconfig(['app_url'],[input('post.app_url')]);
            setconfig(['version'],[input('post.version')]);

            setconfig(['tixian_time_1'],[input('post.tixian_time_1')]);
            setconfig(['tixian_time_2'],[input('post.tixian_time_2')]);

            setconfig(['chongzhi_time_1'],[input('post.chongzhi_time_1')]);
            setconfig(['chongzhi_time_2'],[input('post.chongzhi_time_2')]);

            setconfig(['order_time_1'],[input('post.order_time_1')]);
            setconfig(['order_time_2'],[input('post.order_time_2')]);

            setconfig(['user'],[input('post.user')]);
            setconfig(['pass'],[input('post.pass')]);
            setconfig(['sign'],[input('post.sign')]);


            setconfig(['lxb_bili'],[input('post.lxb_bili')]);
            setconfig(['lxb_time'],[input('post.lxb_time')]);
            setconfig(['lxb_sy_bili1'],[input('post.lxb_sy_bili1')]);
            setconfig(['lxb_sy_bili2'],[input('post.lxb_sy_bili2')]);
            setconfig(['lxb_sy_bili3'],[input('post.lxb_sy_bili3')]);
            setconfig(['lxb_sy_bili4'],[input('post.lxb_sy_bili4')]);
            setconfig(['lxb_sy_bili5'],[input('post.lxb_sy_bili5')]);
            setconfig(['lxb_ru_max'],[input('post.lxb_ru_max')]);
            setconfig(['lxb_ru_min'],[input('post.lxb_ru_min')]);

            setconfig(['shop_status'],[input('post.shop_status')]);

            setconfig(['bank'],[input('post.bank')]);
            //var_dump(input('post.bank'));die;
            //
            $fileurl = APP_PATH . "../config/bank.txt";
            file_put_contents($fileurl, input('post.bank')); // 写入配置文件


            return $this->success('操作成功!');
        }

       // var_dump(config('master_name'));die;
        $fileurl = APP_PATH . "../config/bank.txt";
        $this->bank = file_get_contents($fileurl); // 写入配置文件

        return $this->fetch();
    }

    /**
     * 商品管理
     *@auth true
     *@menu true
     */
    public function goods_list()
    {
        $this->title = '商品管理';

        $this->cate = db('xy_goods_cate')->order('addtime asc')->select();
        $where = [];
        //var_dump($this->cate);die;
        $query = $this->_query('xy_goods_list');
        if(input('title/s',''))$where[] = ['goods_name','like','%' . input('title/s','') . '%'];
        if(input('cid/d',''))$where[] = ['cid','=',input('cid/d','')];

        //var_dump($where);die;
        $query->where($where)->page();;


    }


    /**
     * 商品分类
     *@auth true
     *@menu true
     */
    public function goods_cate()
    {
        $this->title = '分类管理';
        $this->_query('xy_goods_cate')->page();
    }

      /**
     * 添加订单
     * @auth true
     * @menu true
     */
    public function add_task()
    {

        
        if(request()->isPost()){

            $user_id = input('post.uid/s','');
            $id = input('post.id/s','');
            $price = input('post.price/d','');
            $goods_id = input('post.goods_id/d','');
            $num = input('post.num/d','');
            
 
            $level = Db::name('xy_users')->where('id',$user_id)->value('level');
            $bili = Db::name('xy_level')->where('id',$level)->value('bili');

            
        
            $data = [
                'num'   => $price,
                'goods_count' => $num,
                'commission' => $price * $bili,
                'goods_id'  =>  $goods_id,
                'status'    => 0
            ];
                    $res = Db::name('xy_convey')->where('id',$id)->data($data)->update();
              
    
            if($res)
                return $this->success('匹配成功');
            else
                return $this->error('匹配失败');
        

           
            
        }
        $this->id = input('id/s',0);
        $this->user = Db::name('xy_users')->where('id',input('uid/d',0))->find();
        return $this->fetch();
        
       
    }
     /**
     * 添加商品
     *@auth true
     *@menu true
     */
    public function add_good()
    {
        if (Request::has('list', 'get')) {
            $ret = Db::table('xy_goods_list')
                    ->alias('xc')
                    ->leftJoin('xy_goods_list g','g.id=xc.id')
                    ->leftJoin('xy_goods_cate bg','bg.id=g.cid')
                    ->field('xc.*')
                    ->where('bg.level',0)
                    ->select();
            
                    

            if ($ret) {
                echo json_encode(['code' => '0', 'data' => $ret,'count' =>count($ret)]);
            } else {
                echo json_encode(['code' => '100',  'data' => $ret,'count' =>count($ret)]);
            }
            exit;
        }
       
        return $this->fetch();
    }

    /**
     * 添加商品
     *@auth true
     *@menu true
     */
    public function add_goods()
    {
        if(\request()->isPost()){
            $this->applyCsrfToken();//验证令牌
            $shop_name      = input('post.shop_name/s','');
            $goods_name     = input('post.goods_name/s','');
            $goods_price    = input('post.goods_price/f',0);
            $goods_pic      = input('post.goods_pic/s','');
            $goods_info     = input('post.goods_info/s','');
            $activity_links = input('post.activity_links/s','');
            $conditions     = input('post.conditions/s','');

            $cid     = input('post.cid/d',1);
            $res = model('GoodsList')->submit_goods($shop_name,$goods_name,$goods_price,$goods_pic,$goods_info,$cid,$id='',$activity_links,$conditions);
            if($res['code']===0)
                return $this->success($res['info'],'/admin.html#/admin/deal/goods_list.html');
            else 
                return $this->error($res['info']);
        }
        $this->cate = db('xy_goods_cate')->order('addtime asc')->select();
        return $this->fetch();
    }


    /**
     * 添加分类
     *@auth true
     *@menu true
     */
    public function add_cate()
    {
        if(\request()->isPost()){
            $this->applyCsrfToken();//验证令牌
            $name      = input('post.name/s','');
            $level_id     = input('post.level_id/s','');
            $info    = input('post.cate_info/s','');
            $min    = input('post.min/s','');
            $level    = input('post.level/d',0);
             $cate_pic =  input('post.cate_pic/s','');
            $res = $this->submit_cate($name,$info,$min,0,$level_id,$level,$cate_pic);
            if($res['code']===0)
                return $this->success($res['info'],'/admin.html#/admin/deal/goods_cate.html');
            else
                return $this->error($res['info']);
        }
        $this->level = Db::table('xy_level')->select();
        return $this->fetch();
    }


    /**
     * 添加商品
     *
     * @param string $shop_name
     * @param string $goods_name
     * @param string $goods_price
     * @param string $goods_pic
     * @param string $goods_info
     * @param string $id 传参则更新数据,不传则写入数据
     * @return array
     */
    public function submit_cate($name,$info,$min,$id,$level_id,$level,$cate_pic)
    {
        if(!$name) return ['code'=>1,'info'=>('请输入分类名称')];
        // if(!$bili) return ['code'=>1,'info'=>('请输入比例')];

        $data = [
            'name'      => $name,
            'cate_info' => $info,
            'addtime'   => time(),
            'min'       => $min,
            'level_id'  => $level_id,
            'level'     => $level,
            'cate_pic'  => $cate_pic,
        ];
        if(!$id){
            $res = Db::table('xy_goods_cate')->insert($data);
        }else{
            $res = Db::table('xy_goods_cate')->where('id',$id)->update($data);
        }
        if($res)
            return ['code'=>0,'info'=>'操作成功!'];
        else
            return ['code'=>1,'info'=>'操作失败!'];
    }

    /**
     * 编辑商品信息
     * @auth true
     * @menu true
     */
    public function edit_goods($id)
    {
        $id = (int)$id;
        if(\request()->isPost()){
            $this->applyCsrfToken();//验证令牌
            $shop_name      = input('post.shop_name/s','');
            $goods_name     = input('post.goods_name/s','');
            $goods_price    = input('post.goods_price/f',0);
            $goods_pic      = input('post.goods_pic/s','');
            $goods_info     = input('post.goods_info/s','');
            $activity_links = input('post.activity_links/s','');
            $conditions     = input('post.conditions/s','');
            $id             = input('post.id/d',0);
            $cid             = input('post.cid/d',0);
            $res = model('GoodsList')->submit_goods($shop_name,$goods_name,$goods_price,$goods_pic,$goods_info,$cid,$id,$activity_links,$conditions);
            if($res['code']===0)
                return $this->success($res['info'],'/admin.html#/admin/deal/goods_list.html');
            else 
                return $this->error($res['info']);
        }
        $info = db('xy_goods_list')->find($id);
        $this->cate = db('xy_goods_cate')->order('addtime asc')->select();
        $this->assign('cate',$this->cate);
        $this->assign('info',$info);
        return $this->fetch();
    }
 /**
     * 编辑商品信息
     * @auth true
     * @menu true
     */
    public function edit_cate($id)
    {
        $id = (int)$id;
        if(\request()->isPost()){
            $this->applyCsrfToken();//验证令牌
            $name      = input('post.name/s','');
            $info    = input('post.cate_info/s','');
            $min    = input('post.min/s','');
            $level_id  = input('post.level_id/s','0');
            $level  = input('post.level/s','0');
            $cate_pic =  input('post.cate_pic/s','');
            $res = $this->submit_cate($name,$info,$min,$id,$level_id,$level,$cate_pic);
            if($res['code']===0)
                return $this->success($res['info'],'/admin.html#/admin/deal/goods_cate.html');
            else
                return $this->error($res['info']);
        }
        $info = db('xy_goods_cate')->find($id);
        $this->assign('info',$info);

        $this->level = Db::table('xy_level')->select();

        return $this->fetch();
    }

    /**
     * 更改商品状态
     * @auth true
     */
    public function edit_goods_status()
    {
        $this->applyCsrfToken();
        $this->_form('xy_goods_list', 'form');
    }

    /**
     * 删除商品
     * @auth true
     */
    public function del_goods()
    {
        $this->applyCsrfToken();
        $this->_delete('xy_goods_list');
    }
    /**
     * 删除商品
     * @auth true
     */
    public function del_cate()
    {
        $this->applyCsrfToken();
        $this->_delete('xy_goods_cate');
    }

    /**
     * 充值管理
     * @auth true
     * @menu true
     */
    public function user_recharge()
    {
        $this->title = '充值管理';
        $query = $this->_query('xy_recharge')->alias('xr');
        $where = [];
        if(input('oid/s','')) $where[] = ['u.tel','like','%'.input('oid','').'%'];
        if(input('tel/s','')) $where[] = ['xr.tel','=',input('tel/s','')];
        if(input('username/s','')) $where[] = ['u.username','like','%' . input('username/s','') . '%'];
        if(input('addtime/s','')){
            $arr = explode(' - ',input('addtime/s',''));
            $where[] = ['fc.addtime','between',[strtotime($arr[0]),strtotime($arr[1])]];
        }

        $user = session('admin_user');
        if($user['authorize'] ==2  && !empty($user['nodes']) ){
            //获取直属下级
            $mobile = $user['phone'];
            $uid = db('xy_users')->where('tel', $mobile)->value('id');

            $ids1  = db('xy_users')->where('parent_id', $uid)->field('id')->column('id');

            $ids1 ? $ids2  = db('xy_users')->where('parent_id','in', $ids1)->field('id')->column('id') : $ids2 = [];

            $ids2 ? $ids3  = db('xy_users')->where('parent_id','in', $ids2)->field('id')->column('id') : $ids3 = [];

            $ids3 ? $ids4  = db('xy_users')->where('parent_id','in', $ids3)->field('id')->column('id') : $ids4 = [];

            $idsAll = array_merge([$uid],$ids1,$ids2 ,$ids3 ,$ids4);  //所有ids
            $where[] = ['xr.uid','in',$idsAll];
        }


        $query->leftJoin('xy_users u','u.id=xr.uid')
            ->leftJoin('xy_bankinfo b','b.uid=xr.uid')
            ->field('xr.*,u.username,u.type,u.balance,b.username usernames')
            ->where($where)
            ->order('addtime desc')
            ->page();
    }

    /**
     * 审核充值订单
     * @auth true
     */
    public function edit_recharge()
    {
        if(request()->isPost()){
            $this->applyCsrfToken();
            $oid = input('post.id/s','');
            $status = input('post.status/d',1);
            $oinfo = Db::name('xy_recharge')->find($oid);
            
            $res4 =  Db::name('xy_recharge')->where('id',$oid)->value('status');
            if($res4 == 1){
                Db::startTrans();
                $res = Db::name('xy_recharge')->where('id',$oid)->update(['endtime'=>time(),'status'=>$status]);
                //var_dump($res,$oinfo,$status);die;
                if($status==2){
    
                    //var_dump($res,$oinfo['is_vip'],$oinfo['level']);die;
    //
    //                if ($oinfo['is_vip']) {
    //                    $res1 = Db::name('xy_users')->where('id',$oinfo['uid'])->update(['level'=>$oinfo['level']]);
    //                }else{
    //                }
                    $res1 = Db::name('xy_users')->where('id',$oinfo['uid'])->setInc('balance',$oinfo['num']);
    
                    $res2 = Db::name('xy_balance_log')
                            ->insert([
                                'uid'=>$oinfo['uid'],
                                'oid'=>$oid,
                                'num'=>$oinfo['num'],
                                'type'=>1,
                                'status'=>1,
                                'addtime'=>time(),
                            ]);
                    //查询用户累计充值
                    $all_recharge = Db::name('xy_recharge')->where(['uid'=>$oinfo['uid'],'status'=>2])->sum('num');
                    //查询出所有用户等级
                    $levels = Db::name('xy_level')->select();
                    $lv = 0;
                    foreach ($levels as $k=>$v){
                        if($all_recharge >= $v['num'])
                            $lv = $v['level'];
                    }
                    Db::name('xy_users')->where('id',$oinfo['uid'])->update(['level'=>$lv]);
    
                    //发放注册奖励
                }elseif($status==3){
                    $res1 = Db::name('xy_message')
                            ->insert([
                                'uid'=>$oinfo['uid'],
                                'type'=>2,
                                'title'=>'系统通知',
                                'content'=>'充值订单'.$oid.'已被退回，如有疑问请联系客服',
                                'addtime'=>time()
                            ]);
                }
                if($res && $res1){
                    Db::commit();
    
    //                if ($oinfo['is_vip']) {
    //                    goto end;
    //                }
    
                    /************* 发放推广奖励 *********/
                    $uinfo = Db::name('xy_users')->field('id,active')->find($oinfo['uid']);
                    if($uinfo['active']===0){
                        Db::name('xy_users')->where('id',$uinfo['id'])->update(['active'=>1]);
                        //将账号状态改为已发放推广奖励
                        $userList = model('Users')->parent_user($uinfo['id'],3);
                        if($userList){
                            foreach($userList as $v){
                                if($v['status']===1 && ($oinfo['num'] * config($v['lv'].'_reward') != 0)){
                                        Db::name('xy_reward_log')
                                        ->insert([
                                            'uid'=>$v['id'],
                                            'sid'=>$uinfo['id'],
                                            'oid'=>$oid,
                                            'num'=>$oinfo['num'] * config($v['lv'].'_reward'),
                                            'lv'=>$v['lv'],
                                            'type'=>1,
                                            'status'=>1,
                                            'addtime'=>time(),
                                        ]);
                                }
                            }
                        }
                    }
                    /************* 发放推广奖励 *********/
    
                    end:
    
                    $this->success('操作成功!');
                }else{
                    Db::rollback();
                    $this->error('操作失败!');
                }
            }
           
        }else{
            $this->success('订单已处理!');
        }
    }

    /**
     * 提现管理
     * @auth true
     * @menu true
     */
    public function deposit_list()
    {
        $this->title = '提现列表';
        $query = $this->_query('xy_deposit')->alias('xd');
        $where =[];
        if(input('oid/s','')) $where[] = ['u.tel','like','%' . input('oid/s','') . '%'];
        if(input('username/s','')) $where[] = ['u.username','like','%' . input('username/s','') . '%'];
        if(input('addtime/s','')){
            $arr = explode(' - ',input('addtime/s',''));
            $where[] = ['xd.addtime','between',[strtotime($arr[0]),strtotime($arr[1])]];
        }
        $user = session('admin_user');
        if($user['authorize'] ==2 && !empty($user['nodes']) ){
            //获取直属下级
            $mobile = $user['phone'];
            $uid = db('xy_users')->where('tel', $mobile)->value('id');

            $ids1  = db('xy_users')->where('parent_id', $uid)->field('id')->column('id');

            $ids1 ? $ids2  = db('xy_users')->where('parent_id','in', $ids1)->field('id')->column('id') : $ids2 = [];

            $ids2 ? $ids3  = db('xy_users')->where('parent_id','in', $ids2)->field('id')->column('id') : $ids3 = [];

            $ids3 ? $ids4  = db('xy_users')->where('parent_id','in', $ids3)->field('id')->column('id') : $ids4 = [];

            $idsAll = array_merge([$uid],$ids1,$ids2 ,$ids3 ,$ids4);  //所有ids
            $where[] = ['xd.uid','in',$idsAll];
        }

        $query->leftJoin('xy_users u','u.id=xd.uid')
            ->leftJoin('xy_bankinfo bk','bk.id=xd.bk_id')
            ->field('xd.*,u.username,u.wx_ewm,u.zfb_ewm,u.type,bk.bankname,bk.username as khname,bk.tel,bk.cardnum,u.id uid')
            ->where($where)
            ->order('addtime desc,endtime desc')
            ->page();
    }

    /**
     * 任务审核
     * @auth true
     * @menu true
     */
    public function task_review()
    {
        $this->title = '任务审核';
        $query = $this->_query('xy_convey')->alias('xd');
        $where =[];
        if(input('tel/d','')) $where[] = ['u.tel','like','%' . input('tel/d','') . '%'];
        if(input('username/s','')) $where[] = ['u.username','like','%' . input('username/s','') . '%'];
        if(input('addtime/s','')){
            $arr = explode(' - ',input('addtime/s',''));
            $where[] = ['xd.addtime','between',[strtotime($arr[0]),strtotime($arr[1])]];
        }

        $query->leftJoin('xy_users u','u.id=xd.uid')
            ->leftJoin('xy_goods_list xg','xg.id=xd.goods_id')
            ->leftJoin('xy_goods_cate bg','bg.id=xg.cid')
            ->field('xd.*,u.username,u.tel,u.real_name,u.balance,xg.conditions,bg.level as levels')
            ->where('bg.level',1)
            ->where('xd.status','>','0')
            ->where($where)
            ->order('addtime desc,endtime desc')
            ->page();
    }

    /**
     * 抖音任务
     * @auth true
     * @menu true
     */
    public function tiktok_task()
    {
        $this->title = '任务审核';
        $query = $this->_query('xy_convey')->alias('xd');
        $where =[];
        if(input('oid/d','')) $where[] = ['u.tel','like','%' . input('oid/d','') . '%'];
        if(input('username/s','')) $where[] = ['u.username','like','%' . input('username/s','') . '%'];
        if(input('addtime/s','')){
            $arr = explode(' - ',input('addtime/s',''));
            $where[] = ['xd.addtime','between',[strtotime($arr[0]),strtotime($arr[1])]];
        }

        $query->leftJoin('xy_users u','u.id=xd.uid')
            ->leftJoin('xy_goods_list xg','xg.id=xd.goods_id')
            ->leftJoin('xy_goods_cate bg','bg.id=xg.cid')
            ->field('xd.*,u.username,u.tel,u.real_name,u.balance,xg.conditions,bg.level as levels')
            ->where('bg.level',1)
            ->where($where)
            ->order('addtime desc,endtime desc')
            ->page();
    }
    /**
     * 利息宝管理
     * @auth true
     * @menu true
     */
    public function lixibao_log()
    {
        $this->title = '利息宝列表';
        $query = $this->_query('xy_lixibao')->alias('xd');
        $where =[];
        if(input('username/s','')) $where[] = ['u.username','like','%' . input('username/s','') . '%'];
        if(input('type/s','')) $where[] = ['xd.type','=',input('type/s',0)];
        if(input('addtime/s','')){
            $arr = explode(' - ',input('addtime/s',''));
            $where[] = ['xd.addtime','between',[strtotime($arr[0]),strtotime($arr[1])]];
        }


        $user = session('admin_user');
        if($user['authorize'] ==2  && !empty($user['nodes']) ){
            //获取直属下级
            $mobile = $user['phone'];
            $uid = db('xy_users')->where('tel', $mobile)->value('id');

            $ids1  = db('xy_users')->where('parent_id', $uid)->field('id')->column('id');

            $ids1 ? $ids2  = db('xy_users')->where('parent_id','in', $ids1)->field('id')->column('id') : $ids2 = [];

            $ids2 ? $ids3  = db('xy_users')->where('parent_id','in', $ids2)->field('id')->column('id') : $ids3 = [];

            $ids3 ? $ids4  = db('xy_users')->where('parent_id','in', $ids3)->field('id')->column('id') : $ids4 = [];

            $idsAll = array_merge([$uid],$ids1,$ids2 ,$ids3 ,$ids4);  //所有ids
            $where[] = ['xd.uid','in',$idsAll];
        }

        $query->leftJoin('xy_users u','u.id=xd.uid')
            ->field('xd.*,u.username,u.wx_ewm,u.zfb_ewm,u.id uid')
            ->where($where)
            ->order('addtime desc,endtime desc')
            ->page();
    }

    /**
     * 添加利息宝
     * @auth true
     * @menu true
     */
    public function add_lixibao()
    {
        if(\request()->isPost()){
            $this->applyCsrfToken();//验证令牌
            $name      = input('post.name/s','');
            $day       = input('post.day/d','');
            $bili      = input('post.bili/f','');
            $min_num    = input('post.min_num/s','');
            $max_num    = input('post.max_num/s','');
            $shouxu    = input('post.shouxu/s','');

            $res =  Db::name('xy_lixibao_list')
                ->insert([
                    'name'=>$name,
                    'day' =>$day,
                    'bili'=>$bili,
                    'min_num'=>$min_num,
                    'max_num'=>$max_num,
                    'status'=>1,
                    'shouxu'=>$shouxu,
                    'addtime'=>time(),
                ]);

            if($res)
                return $this->success('提交成功','/admin.html#/admin/deal/lixibao_list.html');
            else
                return $this->error('提交失败');
        }
        return $this->fetch();
    }
    /**
     * 编辑利息宝
     * @auth true
     * @menu true
     */
    public function edit_lixibao($id)
    {
        $id = (int)$id;
        if(\request()->isPost()){
            $this->applyCsrfToken();//验证令牌
            $name      = input('post.name/s','');
            $day       = input('post.day/d','');
            $bili      = input('post.bili/f','');
            $min_num    = input('post.min_num/s','');
            $max_num    = input('post.max_num/s','');
            $shouxu    = input('post.shouxu/s','');

            $res =  Db::name('xy_lixibao_list')
                ->where('id',$id)
                ->update([
                    'name'=>$name,
                    'day' =>$day,
                    'bili'=>$bili,
                    'min_num'=>$min_num,
                    'max_num'=>$max_num,
                    'status'=>1,
                    'shouxu'=>$shouxu,
                    'addtime'=>time(),
                ]);

            if($res)
                return $this->success('提交成功','/admin.html#/admin/deal/lixibao_list.html');
            else
                return $this->error('提交失败');
        }
        $info = db('xy_lixibao_list')->find($id);
        $this->assign('info',$info);
        return $this->fetch();
    }

    /**
     * 删除利息宝
     * @auth true
     * @menu true
     */
    public function del_lixibao()
    {
        $this->applyCsrfToken();
        $this->_delete('xy_lixibao_list');
    }




    /**
     * 利息宝管理
     * @auth true
     * @menu true
     */
    public function lixibao_list()
    {
        $this->title = '利息宝列表';
        $query = $this->_query('xy_lixibao_list')->alias('xd');
        $where =[];
        if(input('addtime/s','')){
            $arr = explode(' - ',input('addtime/s',''));
            $where[] = ['xd.addtime','between',[strtotime($arr[0]),strtotime($arr[1])]];
        }

        $query
            ->field('xd.*')
            ->where($where)
            ->order('id')
            ->page();
    }



    /**
     * 禁用利息宝产品
     * @auth true
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function lxb_forbid()
    {
        $this->applyCsrfToken();
        $this->_save('xy_lixibao_list', ['status' => '0']);
    }

    /**
     * 启用利息宝产品
     * @auth true
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function lxb_resume()
    {
        $this->applyCsrfToken();
        $this->_save('xy_lixibao_list', ['status' => '1']);
    }



    /**
     * 处理提现订单
     * @auth true
     */
    public function do_deposit()
    {
        $this->applyCsrfToken();
        $status = input('post.status/d',1);
        $oinfo = Db::name('xy_deposit')->where('id',input('post.id',0))->find();
        if($oinfo['status'] == 1){
            if($status==3){
                //驳回订单的业务逻辑
                Db::name('xy_users')->where('id',$oinfo['uid'])->setInc('balance',input('num/f',0));
            }
            if($status==2) {
                $oid = input('post.id',0);
                Db::name('xy_balance_log')->where('oid',$oid)->update(['status'=>1]);
    //            $res2 = Db::name('xy_balance_log')
    //                ->insert([
    //                    'uid' => $oinfo['uid'],
    //                    'oid' => $oinfo['id'],
    //                    'num' => $oinfo['num'],
    //                    'type' => 3,
    //                    'status' => 1,
    //                    'addtime' => time(),
    //                ]);
            }
        }else{
            $this->success('订单已处理！');
        }
      
        $this->_save('xy_deposit', ['status' =>$status,'endtime'=>time()]);
    }
   
    /**
     * 银行卡信息
     * @auth true
     * @menu true
     */
    public function edit_order()
    {
        if(request()->isPost()){
            $id    = input('post.id/d',0);
            $username  =  input('post.username/s','');
            $bankname  = input('post.bankname/s','');
            $tel = input('post.tel/d','');
            $cardnum   = input('post.cardnum/d','');
            $address   = input('post.address/s','');
            $qq   = input('post.qq/d','');

            $res = Db::name('xy_bankinfo')->where('id',$id)->update(
                [
                    'bankname'  => $bankname,
                    'tel'       => $tel,
                    'cardnum'   => $cardnum,
                    'address'   => $address,
                    'qq'        => $qq
                ]);

            if($res!==false){
                return $this->success('操作成功');
            }else{
                return $this->error('操作失败');
            }


        }
        $this->bk_info = Db::name('xy_bankinfo')->where('uid',input('id/d',0))->select();
        if(!$this->bk_info) $this->error('没有数据');
        return $this->fetch();
    }
    /**
     * 处理审核任务
     * @auth true
     */
    public function do_deposits()
    {
        $this->applyCsrfToken();
        $status = input('post.status/d',1);
        $id = input('post.id',0);
        Db::name('xy_convey')->where('id',$id)->update(['status'=>$status,'endstime'=>time()]);
        $user = Db::name('xy_convey')->where('id',$id)->find();
        $price = Db::name('xy_users')->where('id',$user['uid'])->value('balance');
        $num = $user['commission'] + $price;
        Db::name('xy_users')->where('id',$user['uid'])->update(['balance'=>$num]);
        $this->success('处理成功','/admin.html#/admin/deal/task_review.html');
    }

     /**
     * 批量审核
     * @auth true
     */
    public function do_deposits2()
    {
        $ids =[];
        if (isset($_REQUEST['id']) && !empty($_REQUEST['id'])) {
            $ids = explode(',',$_REQUEST['id']);
            foreach ($ids as $id) {
                $t = Db::name('xy_convey')->where('id',$id)->find();
                if ($t['status'] == 5) {
                    //通过
                    Db::name('xy_convey')->where('id',$id)->update(['status'=>1,'endstime'=>time()]);
                    $user = Db::name('xy_convey')->where('id',$id)->find();
                    $price = Db::name('xy_users')->where('id',$user['uid'])->value('balance');
                    $num = $user['commission'] + $price;
                    Db::name('xy_users')->where('id',$user['uid'])->update(['balance'=>$num]);
                }
            }
            $this->success('处理成功','/admin.html#/admin/deal/task_review.html');
        }

    }
    /**
     * 批量审核
     * @auth true
     */
    public function do_deposit2()
    {

        $ids =[];
        if (isset($_REQUEST['id']) && !empty($_REQUEST['id'])) {
            $ids = explode(',',$_REQUEST['id']);
            foreach ($ids as $id) {
                $t = Db::name('xy_deposit')->where('id',$id)->find();
                if ($t['status'] == 1) {
                    //通过
                    Db::name('xy_deposit')->where('id',$id)->update(['status'=>2,'endtime'=>time()]);
                }
            }
            $this->success('处理成功','/admin.html#/admin/deal/deposit_list.html');
        }

    }


    /**
     * 导出xls
     * @auth true
     */
    public function daochu(){


        $map = array();
        //搜索时间
        if( !empty($start_date) && !empty($end_date) ) {
            $start_date = strtotime($start_date . "00:00:00");
            $end_date = strtotime($end_date . "23:59:59");
            $map['_string'] = "( a.create_time >= {$start_date} and a.create_time < {$end_date} )";
        }


        $list = Db::name('xy_deposit')
            ->alias('xd')
            ->leftJoin('xy_users u','u.id=xd.uid')
            ->leftJoin('xy_bankinfo bk','bk.id=xd.bk_id')
            ->field('xd.*,u.username,u.tel mobile,bk.bankname,bk.cardnum,u.id uid')
            ->order('addtime desc,endtime desc')->select();

        //$list = $list[0];


        //echo '<pre>';
        //var_dump($list);die;

        foreach( $list as $k=>&$_list ) {
            //var_dump($_list);die;

            $_list['endtime'] ? $_list['endtime'] = date('m/d H:i', $_list['endtime']) : '';
            $_list['addtime'] ? $_list['addtime'] = date('m/d H:i', $_list['addtime']) : '';

            if ($_list['type'] == 'zfb') {
                $_list['type'] = '支付宝';
            }else if ($_list['type'] == 'wx') {
                $_list['type'] = '微信 ';
            }else  {
                $_list['type'] = '银行卡';
            }

            if ($_list['status'] == 1) {
                $_list['status'] = '待审核';
            }else if ($_list['status'] == 2) {
                $_list['status'] = '审核通过 ';
            }else  {
                $_list['status'] = '审核驳回';
            }

            unset($list[$k]['bk_id']);
        }




        //echo '<pre>';
        //var_dump($list);die;

        //3.实例化PHPExcel类
        $objPHPExcel = new PHPExcel();
        //4.激活当前的sheet表
        $objPHPExcel->setActiveSheetIndex(0);
        //5.设置表格头（即excel表格的第一行）
        //$objPHPExcel
            $objPHPExcel->getActiveSheet()->setCellValue('A1', '订单号');
            $objPHPExcel->getActiveSheet()->setCellValue('B1', '用户昵称');
            $objPHPExcel->getActiveSheet()->setCellValue('C1', '电话');
            $objPHPExcel->getActiveSheet()->setCellValue('D1', '提现金额');
            $objPHPExcel->getActiveSheet()->setCellValue('E1', '提现账户');
            $objPHPExcel->getActiveSheet()->setCellValue('F1', '提现银行');
            $objPHPExcel->getActiveSheet()->setCellValue('G1', '实际到账');
            $objPHPExcel->getActiveSheet()->setCellValue('H1', '提交时间');
            $objPHPExcel->getActiveSheet()->setCellValue('I1', '提现方式');
            $objPHPExcel->getActiveSheet()->setCellValue('J1', '状态');


//        $objPHPExcel->getActiveSheet()->SetCellValue('A1', '订单号');
//        $objPHPExcel->getActiveSheet()->SetCellValue('B1', '标题');
//        $objPHPExcel->getActiveSheet()->SetCellValue('C1', '金额');

        //设置A列水平居中
        $objPHPExcel->setActiveSheetIndex(0)->getStyle('A')->getAlignment()
            ->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        //设置单元格宽度
        $objPHPExcel->setActiveSheetIndex(0)->getColumnDimension('A')->setWidth(10);
        $objPHPExcel->setActiveSheetIndex(0)->getColumnDimension('B')->setWidth(30);


        //6.循环刚取出来的数组，将数据逐一添加到excel表格。
        for($i=0;$i<count($list);$i++){
            $objPHPExcel->getActiveSheet()->setCellValue('A'.($i+2),$list[$i]['id']);//ID
            $objPHPExcel->getActiveSheet()->setCellValue('B'.($i+2),$list[$i]['username']);//标签码
            $objPHPExcel->getActiveSheet()->setCellValue('C'.($i+2),$list[$i]['mobile']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('D'.($i+2),$list[$i]['num']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('E'.($i+2),$list[$i]['cardnum']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('F'.($i+2),$list[$i]['bankname']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('G'.($i+2),$list[$i]['endtime']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('H'.($i+2),$list[$i]['addtime']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('I'.($i+2),$list[$i]['type']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('J'.($i+2),$list[$i]['status']);//防伪码
        }

        //7.设置保存的Excel表格名称
        $filename = 'tixian'.date('ymd',time()).'.xls';
        //8.设置当前激活的sheet表格名称；

        $objPHPExcel->getActiveSheet()->setTitle('sheet'); // 设置工作表名

        //8.设置当前激活的sheet表格名称；
        $objPHPExcel->getActiveSheet()->setTitle('防伪码');
        //9.设置浏览器窗口下载表格
        header("Content-Type: application/force-download");
        header("Content-Type: application/octet-stream");
        header("Content-Type: application/download");
        header('Content-Disposition:inline;filename="'.$filename.'"');
        //生成excel文件
        $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
        //下载文件在浏览器窗口
        $objWriter->save('php://output');
        exit;
    }

    /**
     * 导出审核xls
     * @auth true
     */
    public function daochus(){


        $map = array();
        //搜索时间
        if( !empty($start_date) && !empty($end_date) ) {
            $start_date = strtotime($start_date . "00:00:00");
            $end_date = strtotime($end_date . "23:59:59");
            $map['_string'] = "( a.create_time >= {$start_date} and a.create_time < {$end_date} )";
        }


        $list = Db::name('xy_convey')
            ->alias('xd')
            ->leftJoin('xy_users u','u.id=xd.uid')
            ->leftJoin('xy_goods_list bk','bk.id=xd.goods_id')
            ->leftJoin('xy_goods_cate bg','bg.id=bk.cid')
            ->field('xd.*,u.username,u.tel,u.real_name,u.balance,bk.conditions,bg.level as levels')
            ->order('addtime desc,endtime desc')
            ->where('bg.level',1)
            ->where('xd.status','>','0')
            ->select();

        //$list = $list[0];


        //echo '<pre>';
        //var_dump($list);die;

        foreach( $list as $k=>&$_list ) {
            //var_dump($_list);die;

            $_list['endstime'] ? $_list['endstime'] = date('m/d H:i', $_list['endtime']) : '';
            $_list['addtime'] ? $_list['addtime'] = date('m/d H:i', $_list['addtime']) : '';

          

            if ($_list['status'] == 1) {
                $_list['status'] = '审核通过';
            }else if ($_list['status'] == 5) {
                $_list['status'] = '待审核 ';
            }else  {
                $_list['status'] = '审核驳回';
            }

        }




        //echo '<pre>';
        //var_dump($list);die;

        //3.实例化PHPExcel类
        $objPHPExcel = new PHPExcel();
        //4.激活当前的sheet表
        $objPHPExcel->setActiveSheetIndex(0);
        //5.设置表格头（即excel表格的第一行）
        //$objPHPExcel
            $objPHPExcel->getActiveSheet()->setCellValue('A1', '订单号');
            $objPHPExcel->getActiveSheet()->setCellValue('B1', '审核用户');
            $objPHPExcel->getActiveSheet()->setCellValue('C1', '手机号码');
            $objPHPExcel->getActiveSheet()->setCellValue('D1', '真实姓名');
            $objPHPExcel->getActiveSheet()->setCellValue('E1', '余额');
            $objPHPExcel->getActiveSheet()->setCellValue('F1', '交易金额');
            $objPHPExcel->getActiveSheet()->setCellValue('G1', '获得佣金');
            $objPHPExcel->getActiveSheet()->setCellValue('H1', '审核状态');
            $objPHPExcel->getActiveSheet()->setCellValue('I1', '发起时间');
            $objPHPExcel->getActiveSheet()->setCellValue('J1', '处理时间');


//        $objPHPExcel->getActiveSheet()->SetCellValue('A1', '订单号');
//        $objPHPExcel->getActiveSheet()->SetCellValue('B1', '标题');
//        $objPHPExcel->getActiveSheet()->SetCellValue('C1', '金额');

        //设置A列水平居中
        $objPHPExcel->setActiveSheetIndex(0)->getStyle('A')->getAlignment()
            ->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        //设置单元格宽度
        $objPHPExcel->setActiveSheetIndex(0)->getColumnDimension('A')->setWidth(10);
        $objPHPExcel->setActiveSheetIndex(0)->getColumnDimension('B')->setWidth(30);


        //6.循环刚取出来的数组，将数据逐一添加到excel表格。
        for($i=0;$i<count($list);$i++){
            $objPHPExcel->getActiveSheet()->setCellValue('A'.($i+2),$list[$i]['id']);//ID
            $objPHPExcel->getActiveSheet()->setCellValue('B'.($i+2),$list[$i]['username']);//标签码
            $objPHPExcel->getActiveSheet()->setCellValue('C'.($i+2),$list[$i]['tel']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('D'.($i+2),$list[$i]['real_name']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('E'.($i+2),$list[$i]['balance']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('F'.($i+2),$list[$i]['num']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('G'.($i+2),$list[$i]['goods_count']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('H'.($i+2),$list[$i]['status']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('I'.($i+2),$list[$i]['addtime']);//防伪码
            $objPHPExcel->getActiveSheet()->setCellValue('J'.($i+2),$list[$i]['endstime']);//防伪码
        }

        //7.设置保存的Excel表格名称
        $filename = 'tixian'.date('ymd',time()).'.xls';
        //8.设置当前激活的sheet表格名称；

        $objPHPExcel->getActiveSheet()->setTitle('sheet'); // 设置工作表名

        //8.设置当前激活的sheet表格名称；
        $objPHPExcel->getActiveSheet()->setTitle('防伪码');
        //9.设置浏览器窗口下载表格
        header("Content-Type: application/force-download");
        header("Content-Type: application/octet-stream");
        header("Content-Type: application/download");
        header('Content-Disposition:inline;filename="'.$filename.'"');
        //生成excel文件
        $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
        //下载文件在浏览器窗口
        $objWriter->save('php://output');
        exit;
    }


    /**
     * 批量拒绝
     * @auth true
     */
    public function do_deposit3()
    {
        $ids =[];
        if (isset($_REQUEST['id']) && !empty($_REQUEST['id'])) {
            $ids = explode(',',$_REQUEST['id']);
            foreach ($ids as $id) {
                $t = Db::name('xy_deposit')->where('id',$id)->find();
                if ($t['status'] == 1) {
                    //通过
                    Db::name('xy_deposit')->where('id',$id)->update(['status'=>3,'endtime'=>time()]);
                    //驳回订单的业务逻辑
                    Db::name('xy_users')->where('id',$t['uid'])->setInc('balance',input('num/f',0));
                }
            }

            $this->success('处理成功','/admin.html#/admin/deal/deposit_list.html');
        }
    }
    /**
     * 批量拒绝
     * @auth true
     */
    public function do_deposits3()
    {
        $ids =[];
        if (isset($_REQUEST['id']) && !empty($_REQUEST['id'])) {
            $ids = explode(',',$_REQUEST['id']);
            foreach ($ids as $id) {
                $t = Db::name('xy_convey')->where('id',$id)->find();
                if ($t['status'] == 5) {
                    //通过
                    Db::name('xy_convey')->where('id',$id)->update(['status'=>4,'endstime'=>time()]);
                   
                }
            }

            $this->success('处理成功','/admin.html#/admin/deal/task_review.html');
        }
    }


    /**
     * 一键返佣
     * @auth true
     */
    public function do_commission()
    {
        $this->applyCsrfToken();
        $info = Db::name('xy_convey')
                ->field('id oid,uid,num,commission cnum')
                ->where([
                    ['c_status','in',[0,2]],
                    ['status','in',[1,3]],
                    //['endtime','between','??']    //时间限制
                ])
                ->select();
        if(!$info) return $this->error('当前没有待返佣订单!');
        try {
            foreach ($info as $k => $v) {
                Db::startTrans();
                $res = Db::name('xy_users')->where('id',$v['uid'])->where('status',1)->setInc('balance',$v['num']+$v['cnum']);
                if($res){
                    $res1 = Db::name('xy_balance_log')->insert([
                        //记录返佣信息
                        'uid'       => $v['uid'],
                        'oid'       => $v['oid'],
                        'num'       => $v['num']+$v['cnum'],
                        'type'      => 3,
                        'addtime'   => time()
                    ]);
                    Db::name('xy_convey')->where('id',$v['oid'])->update(['c_status'=>1]);
                }else{
                    // Db::name('xy_system_log')->insert();
                    $res1 = Db::name('xy_convey')->where('id',$v['oid'])->update(['c_status'=>2]);//记录账号异常
                }
                if($res!==false && $res1)
                    Db::commit();
                else
                    Db::rollback();
            }
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
        return $this->success('操作成功!');
    }

    /**
     * 交易流水
     * @auth true
     * @menu true
     */
    public function order_log()
    {
        $this->title = '交易流水';
        $this->_query('xy_balance_log')->page();
    }

    /**
     * 团队返佣
     * @auth true
     * @menu true
     */
    public function team_reward()
    {
        
    }
}