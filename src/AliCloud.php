<?php
/**
 * 阿里云接口扩展类
 * @Author LuoYan<51085726@qq.com>
 * @date 2020.02.12
 */

declare (strict_types=1);

namespace LuoYan;

use app\model\SmsLog;
use LuoYan\Random;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Request;

class AliCloud
{
    /**
     * 验证用户输入的短信验证码
     * @param string $phone 手机号
     * @param string $captcha 验证码
     * @return array
     */
    public static function checkCaptcha(string $phone, string $captcha)
    {
        $sms_captcha = Cache::get('smsCaptcha_' . $phone);
        if (!$sms_captcha) {
            return ret_array(2, '您的短信验证码已失效，请重新获取！');
        } elseif ($captcha <> $sms_captcha) {
            return ret_array(1, '您输入的验证码有误！');
        } else {
            // 清空验证码缓存，避免原验证码重复使用
            Cache::delete('smsCaptcha_' . $phone);
            return ret_array(0);
        }
    }

    /**
     * 发送短信验证码
     * @param string $phone 手机号
     * @param string|null $captcha 验证码
     * @return array
     */
    public static function sendSmsCaptcha(string $phone, string $captcha = '')
    {
        $phone = strval(intval($phone));
        if (!preg_match('/^1[3456789]\d{9}$/', $phone)) {
            return ret_array(1, '手机号不正确，请重新输入！');
        }

        $time = Cache::get('smsCaptchaSendTimePhone_' . $phone);
        if ($time + 60 > time()) {
            return ret_array(2, '您请求的太快啦，请稍后');
        } else {
            Cache::set('smsCaptchaSendTimePhone_' . $phone, time(), 60);
        }
        unset($time);

        // 限制单手机号一天仅能请求5次
        $times = Cache::get('smsCaptchaTimesPhone_' . $phone);
        if ($times) {
            if ($times > 5) return ret_array(2, '您今天已经发送了太多次短信啦');
            Cache::set('smsCaptchaTimesPhone_' . $phone, $times + 1, 86400);
        } else {
            Cache::set('smsCaptchaTimesPhone_' . $phone, 1, 86400);
        }
        unset($times);

        // 限制单IP地址一天仅能请求10次
        $ip = Request::ip();
        $times = Cache::get('smsCaptchaTimesIp_' . $ip);
        if ($times) {
            if ($times > 10) return ret_array(2, '您今天已经发送了太多次短信啦');
            Cache::set('smsCaptchaTimesIp_' . $ip, $times + 1, 86400);
        } else {
            Cache::set('smsCaptchaTimesIp_' . $ip, 1, 86400);
        }
        unset($times);

        if (!$captcha) {
            $str = '0123456789';
            $str_len = strlen($str);
            $captcha = '';
            for ($i = 0; $i < 6; $i++) {
                $captcha .= $str[mt_rand(0, $str_len - 1)];
            }
        }

        $data = [
            'Action' => 'SendSms',
            'PhoneNumbers' => $phone,
            'SignName' => Config::get('ali_cloud.captcha_sign_name'),
            'TemplateCode' => Config::get('ali_cloud.captcha_template_code'),
            'TemplateParam' => json_encode(['code' => $captcha]),
            'Format' => 'JSON',
            'RegionId' => 'cn-hangzhou',
            'Version' => '2017-05-25',
            'AccessKeyId' => Config::get('ali_cloud.access_key_id'),
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => Random::random(8)
        ];

        // 计算阿里云签名
        ksort($data);
        $query_string = '';
        foreach ($data as $key => $value) {
            $key = urlencode((string)$key);
            $key = preg_replace('/\+/', '%20', $key);
            $key = preg_replace('/\*/', '%2A', $key);
            $key = preg_replace('/%7E/', '~', $key);
            $value = urlencode((string)$value);
            $value = preg_replace('/\+/', '%20', $value);
            $value = preg_replace('/\*/', '%2A', $value);
            $value = preg_replace('/%7E/', '~', $value);
            $query_string .= '&' . $key . '=' . $value;
        }
        $query_string = substr($query_string, 1);
        $query_string = urlencode($query_string);
        $query_string = preg_replace('/\+/', '%20', $query_string);
        $query_string = preg_replace('/\*/', '%2A', $query_string);
        $query_string = preg_replace('/%7E/', '~', $query_string);
        $sign_string = 'POST&%2F&' . $query_string;
        $access_secret = Config::get('ali_cloud.access_secret');
        $data['Signature'] = base64_encode(hash_hmac("sha1", $sign_string, $access_secret . "&", true));

        // 请求阿里云接口
        $url = 'https://dysmsapi.aliyuncs.com/';
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        $result = curl_exec($curl);
        curl_close($curl);
        $result = json_decode($result, true);

        if ($result['Code'] == 'OK') {
            // 将验证码缓存起来备用
            Cache::set('smsCaptcha_' . $phone, $captcha, 1800);
            // 写短信发送日志
            SmsLog::write(request()->userId ?: 0, $phone, '', $captcha);
            return ret_array(0, '验证码发送成功', ['captcha' => $captcha]);
        } else {
            return ret_array(1, $result['Message']);
        }
    }

    /**
     * 发送邮件
     * @param string $toAddress 对方邮件地址
     * @param string $subject 邮件主题
     * @param string $htmlBody 邮件正文
     * @return bool|string 成功返回true，失败返回错误详情
     */
    public static function sendMail(string $toAddress, string $subject, string $htmlBody)
    {
        $data = [
            'Action' => 'SingleSendMail',
            'AccountName' => Config::get('ali_cloud.account_name'),
            'AddressType' => 1,
            'ReplyToAddress' => 'false',
            'ToAddress' => $toAddress,
            'FromAlias' => Config::get('ali_cloud.from_alias'),
            'Subject' => $subject,
            'HtmlBody' => $htmlBody,
            'ClickTrace' => '0',
            'Format' => 'JSON',
            'Version' => '2015-11-23',
            'AccessKeyId' => Config::get('ali_cloud.access_key_id'),
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => Random::random(8)
        ];

        // 计算阿里云签名
        ksort($data);
        $query_string = '';
        foreach ($data as $key => $value) {
            $key = urlencode((string)$key);
            $key = preg_replace('/\+/', '%20', $key);
            $key = preg_replace('/\*/', '%2A', $key);
            $key = preg_replace('/%7E/', '~', $key);
            $value = urlencode((string)$value);
            $value = preg_replace('/\+/', '%20', $value);
            $value = preg_replace('/\*/', '%2A', $value);
            $value = preg_replace('/%7E/', '~', $value);
            $query_string .= '&' . $key . '=' . $value;
        }
        $query_string = substr($query_string, 1);
        $query_string = urlencode($query_string);
        $query_string = preg_replace('/\+/', '%20', $query_string);
        $query_string = preg_replace('/\*/', '%2A', $query_string);
        $query_string = preg_replace('/%7E/', '~', $query_string);
        $sign_string = 'POST&%2F&' . $query_string;
        $access_secret = Config::get('ali_cloud.access_secret');
        $data['Signature'] = base64_encode(hash_hmac("sha1", $sign_string, $access_secret . "&", true));

        // 请求阿里云接口
        $url = 'https://dm.aliyuncs.com/';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $json = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode == 200) {
            return true;
        } else {
            $arr = json_decode($json, true);
            return $arr['Message'];
        }
    }
}