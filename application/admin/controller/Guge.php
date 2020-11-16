<?php



namespace app\admin\controller;

use app\admin\model\GoogleAuthenticator;
class Guge 
{
    public function indexs()
    {
        // 创建新的"安全密匙SecretKey"
        // 把本次的"安全密匙SecretKey" 入库,和账户关系绑定,客户端也是绑定这同一个"安全密匙SecretKey"
        // 安全密匙SecretKey 可以和手机端绑定
        $ge = new GoogleAuthenticator();
        $secret = $ge->createSecret();

        echo "安全密匙SecretKey: " . $secret . "\n\n";

        //第一个参数是"标识",第二个参数为"安全密匙SecretKey" 生成二维码信息
        $qrCodeUrl = $ge->getQRCodeGoogleUrl('993826.cn', $secret);

        //Google Charts接口 生成的二维码图片,方便手机端扫描绑定安全密匙SecretKey
        echo "Google Charts URL for the QR-Code: " . $qrCodeUrl . "\n\n";
    }
}
