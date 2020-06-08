<?php
// +----------------------------------------------------------------------
// | AliCloud扩展设置
// +----------------------------------------------------------------------

use think\facade\Env;

return [
    // 阿里云AccessKeyId
    'access_key_id' => Env::get('AliCloud.AccessKeyId', ''),
    // 阿里云AccessSecret
    'access_secret' => Env::get('AliCloud.AccessSecret', ''),

    // 阿里云短信SignName
    'captcha_sign_name' => Env::get('AliCloudSms.CaptchaSignName', ''),
    // 阿里云短信TemplateCode
    'captcha_template_code' => Env::get('AliCloudSms.CaptchaTemplateCode', ''),

    // 阿里云邮件签名
    'account_name' => Env::get('AliCloudMail.AccountName', ''),
    // 阿里云邮件发件人邮箱
    'from_alias' => Env::get('AliCloudMail.FromAlias', ''),

    // 阿里云OSS bucket
    'bucket' => Env::get('AliCloudOss.Bucket', ''),
    // 阿里云OSS endpoint
    'endpoint' => Env::get('AliCloudOss.Endpoint', ''),
    // 阿里云OSS 访问链接
    'url' => Env::get('AliCloudOss.Url', ''),
];