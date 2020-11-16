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

namespace app\index\controller;

use library\Controller;
use think\Db;

/**
 * 应用入口
 * Class Index
 * @package app\index\controller
 */
class Index extends Base
{
    /**
     * 入口跳转链接
     */
    public function index()
    {
        $this->redirect('home');
    }

    public function home()
    {
        $this->info = Db::name('xy_index_msg')->field('content')->select();
        $this->balance = Db::name('xy_users')->where('id',session('user_id'))->sum('balance');
        $this->banner = Db::name('xy_banner')->select();
        //if($this->banner) $this->banner = explode('|',$this->banner);
        $this->notice = db('xy_index_msg')->where('id',1)->value('content');
        $this->hezuo = db('xy_index_msg')->where('id',4)->value('content');;
        $this->jianjie = db('xy_index_msg')->where('id',2)->value('content');;
        $this->guize = db('xy_index_msg')->where('id',3)->value('content');;;
        $this->gundong = db('xy_index_msg')->where('id',8)->value('content');;;
        $this->tanchunag = db('xy_index_msg')->where('id',11)->value('content');;;


        $dev = new \org\Mobile();
        $t = $dev->isMobile();
        if (!$t) {
            if (config('app_only')) {
                header('Location:/app');
            }
        }



        //var_dump($this->banner);die;
        //model('admin/Users')->create_qrcode('',);
        $list = db('xy_convey')
            ->alias('xc')
            ->leftJoin('xy_users u','u.id=xc.uid')
            ->field('xc.*,u.username,u.tel')
            ->where('xc.status',1)
            ->limit(15)
            ->order('xc.id desc')
           ->select();
        
        //var_dump($list);die;


        $list2 = [
            ['tel' => '139456123698', 'num' =>  23.98, 'addtime' =>  time() - rand(1000,999999)],
            ['tel' => '173456129020', 'num' =>  103.02, 'addtime' =>  time() - rand(1000,999999)],
            ['tel' => '131551220000', 'num' =>  3.00, 'addtime' =>  time() - rand(1000,999999)],
            ['tel' => '181456125024', 'num' =>  9.5, 'addtime' =>  time() - rand(1000,999999)],
            ['tel' => '138852362105', 'num' =>  19.05, 'addtime' =>  time() - rand(1000,999999)],
        ];
        if (count($list) < 5 ) {
            $list = array_merge($list,$list2);
        }



        if ($list) {
            foreach ($list as &$item) {
                $item['tel'] = substr_replace($item['tel'], '****', 3, 4);
                $item['num'] ='获得返佣'.$item['commission'] ;
                $item['addtime'] = date('m-d H:i', $item['addtime']); ;
            }
        }

        $this->list = $list;

        $this->assign('pic','/upload/qrcode/user/'.(session('user_id')%20).'/'.session('user_id').'-1.png');
        $this->cate = db('xy_goods_cate')->alias('c')
            ->leftJoin('xy_level u','u.id=c.level_id')
            ->field('c.name,c.id,c.cate_info,c.cate_pic,u.name as levelname,u.pic,u.level,u.bili,c.level as levels')
            ->order('c.sort asc')->select();
        $uid = session('user_id');
        //一天的
        $this->lixibao = db('xy_lixibao_list')->order('id asc')->find();

        //
        $yes1 = strtotime( date("Y-m-d 00:00:00",strtotime("-1 day")) );
        $yes2 = strtotime( date("Y-m-d 23:59:59",strtotime("-1 day")) );
        $this->tod_user_yongjin = db('xy_convey')->where('uid',$uid)->where('status',1)->where('addtime','between',[strtotime('Y-m-d 00:00:00'),time()])->sum('commission');
        $this->yes_user_yongjin = db('xy_convey')->where('uid',$uid)->where('status',1)->where('addtime','between',[$yes1,$yes2])->sum('commission');
        $this->user_yongjin = db('xy_convey')->where('uid',$uid)->where('status',1)->sum('commission');



        $this->info = db('xy_users')->find($uid);

        return $this->fetch();
    }

    //获取首页图文
    public function get_msg()
    {
        $type = input('post.type/d',1);
        $data = Db::name('xy_index_msg')->find($type);
        if($data)
            return json(['code'=>0,'info'=>'请求成功','data'=>$data]);
        else
            return json(['code'=>1,'info'=>'暂无数据']);
    }




    //获取首页图文
    public function getTongji()
    {
        $type = input('post.type/d',1);
        $data = array();

        $data['user'] = db('xy_users')->where('status',1)->where('addtime','between',[strtotime(date('Y-m-d'))-24*3600,time()])->count('id');
        $data['goods'] = db('xy_goods_list')->count('id');;
        $data['price'] = db('xy_convey')->where('status',1)->where('endtime','between',[strtotime(date('Y-m-d'))-24*3600,strtotime(date('Y-m-d'))])->sum('num');
        $user_order = db('xy_convey')->where('status',1)->where('addtime','between',[strtotime(date('Y-m-d')),time()])->field('uid')->Distinct(true)->select();
        $data['num'] = count($user_order);

        if($data){
            return json(['code'=>0,'info'=>'请求成功','data'=>$data]);
        } else {
            return json(['code' => 1, 'info' => '暂无数据']);
        }
    }




    function getDanmu()
    {
        $barrages=    //弹幕内容
            array(
                array(
                    'info'   => '用户173***4985开通会员成功',
                    'href'   => '',

                ),
                array(
                    'info'   => '用户136***1524开通会员成功',
                    'href'   => '',
                    'color'  =>  '#ff6600'

                ),
                array(
                    'info'   => '用户139***7878开通会员成功',
                    'href'   => '',
                    'bottom' => 450 ,
                ),
                array(
                    'info'   => '用户159***7888开通会员成功',
                    'href'   => '',
                    'close'  =>false,

                ),array(
                'info'   => '用户151***7799开通会员成功',
                'href'   => '',

                )
            );

        echo   json_encode($barrages);
    }

}
