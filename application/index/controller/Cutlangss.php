<?php
namespace app\index\controller;
use library\Controller;
use think\Db;
use \think\Lang;
use think\Cookie;
use \think\Config;
/**
 * 应用入口
 * Class Index
 * @package app\index\controller
 */
class Cutlangss extends controller
{
    /*语言切换*/
    public function cutlangs(){
        $lang = input('post.lang');
        if($lang =='zh-cn'){
            Cookie('lang','zh-cn');

        }else if($lang =='en-us'){
            Cookie('lang','en-us');
        }else{
            Cookie('lang','xh');
        }
        echo json_encode(['code'=>1]);
    }
}