<?php

// +----------------------------------------------------------------------
// | ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2019 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: http://demo.thinkadmin.top
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | gitee 代码仓库：https://gitee.com/zoujingli/ThinkAdmin
// | github 代码仓库：https://github.com/zoujingli/ThinkAdmin
// +----------------------------------------------------------------------

return [
    // 去除HTML空格换行
    'strip_space'        => true,
    // 开启模板编译缓存
    'tpl_cache'          => !config('app_debug'),
    // 定义模板替换字符串
    'tpl_replace_string' => [
        '__APP__'    => rtrim(url('@'), '\\/'),
        '__ROOT__'   => rtrim(dirname(request()->basefile()), '\\/'),
        '__PUBLIC__' => rtrim(dirname(request()->basefile(true)), '\\/'),
        //GatewayWorker连接域名
        '__GATEWAYWORKER__' => '127.0.0.1',
    ],
];
