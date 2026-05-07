<?php
namespace app\service;

use think\facade\Log;

class PaymentService
{
    /**
     * WeChat Pay Native (Scan QR Code)
     */
    public function wechatNative($order, $config)
    {
        // Config structure: ['mch_id' => '...', 'app_id' => '...', 'key' => '...', 'notify_url' => '...']
        if (empty($config['app_id']) || empty($config['mch_id']) || empty($config['api_key'])) {
            throw new \Exception('WeChat configuration incomplete. Please check AppID, MCHID, and API Key in Tenant Admin.');
        }

        $params = [
            'appid'            => $config['app_id'], // Assuming stored as app_id or you might need to get it from somewhere else if not in payment config
            'mch_id'           => $config['mch_id'],
            'nonce_str'        => md5(uniqid()),
            'body'             => 'Order-' . $order->order_no,
            'out_trade_no'     => $order->order_no,
            'total_fee'        => intval($order->amount * 100), // Fen
            'spbill_create_ip' => request()->ip(),
            'notify_url'       => $config['notify_url'],
            'trade_type'       => 'NATIVE',
        ];

        // Sign
        $params['sign'] = $this->generateWechatSign($params, $config['api_key']);

        // XML
        $xml = $this->arrayToXml($params);

        // Request
        $response = $this->postXml('https://api.mch.weixin.qq.com/pay/unifiedorder', $xml);
        $result = $this->xmlToArray($response);

        if (isset($result['return_code']) && $result['return_code'] == 'SUCCESS' && isset($result['result_code']) && $result['result_code'] == 'SUCCESS') {
            return [
                'type' => 'qrcode',
                'qr_code' => $result['code_url']
            ];
        } else {
            Log::error('WeChat Pay Error: ' . json_encode($result));
            $errorMsg = $result['return_msg'] ?? 'Unknown error';
            if (isset($result['err_code_des'])) {
                $errorMsg .= ' - ' . $result['err_code_des'];
            } elseif (isset($result['err_code'])) {
                $errorMsg .= ' - ' . $result['err_code'];
            }
            throw new \Exception('WeChat Pay Error: ' . $errorMsg);
        }
    }

    /**
     * Alipay Page Pay
     */
    public function alipayPage($order, $config)
    {
        // Config: ['app_id' => '...', 'private_key' => '...', 'alipay_public_key' => '...', 'notify_url' => '...']
        
        $bizContent = [
            'out_trade_no' => $order->order_no,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
            'total_amount' => $order->amount,
            'subject'      => 'Order-' . $order->order_no,
        ];

        $params = [
            'app_id'      => $config['app_id'],
            'method'      => 'alipay.trade.page.pay',
            'format'      => 'JSON',
            'return_url'  => request()->domain() . '/user/orders', // Redirect back to user orders
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => $config['notify_url'],
            'biz_content' => json_encode($bizContent),
        ];

        $params['sign'] = $this->generateAlipaySign($params, $config['private_key']);

        // Build URL
        $query = http_build_query($params);
        $url = 'https://openapi.alipay.com/gateway.do?' . $query;

        return [
            'type' => 'url',
            'url' => $url
        ];
    }

    private function generateWechatSign($params, $key)
    {
        ksort($params);
        $string = '';
        foreach ($params as $k => $v) {
            if ($k != 'sign' && $v != '' && !is_array($v)) {
                $string .= $k . '=' . $v . '&';
            }
        }
        $string .= 'key=' . $key;
        return strtoupper(md5($string));
    }

    private function generateAlipaySign($params, $privateKey)
    {
        ksort($params);
        $string = '';
        foreach ($params as $k => $v) {
            if ($k != 'sign' && $v !== '' && !is_null($v) && "@" != substr($v, 0, 1)) {
                $string .= $k . '=' . $v . '&';
            }
        }
        $string = substr($string, 0, -1);

        $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($privateKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";

        openssl_sign($string, $sign, $privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    private function arrayToXml($arr)
    {
        $xml = "<xml>";
        foreach ($arr as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else {
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
            }
        }
        $xml .= "</xml>";
        return $xml;
    }

    private function xmlToArray($xml)
    {
        if (!$xml) return [];
        $previousEntityLoader = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $previousEntityLoader = libxml_disable_entity_loader(true);
        }

        $options = LIBXML_NOCDATA;
        if (defined('LIBXML_NONET')) {
            $options |= LIBXML_NONET;
        }

        $element = simplexml_load_string($xml, 'SimpleXMLElement', $options);

        if (PHP_VERSION_ID < 80000 && $previousEntityLoader !== null) {
            libxml_disable_entity_loader($previousEntityLoader);
        }

        if ($element === false) return [];
        return json_decode(json_encode($element), true);
    }

    private function postXml($url, $xml)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }
}
