<?php
/**
 * Kratos_AI_SSRF — base_url 保存时校验 + 请求前 IP 二次校验
 *
 * 注意：请求前的复查是「尽力而为」，不等于封堵 DNS rebinding —— WP 发请求时会再解析一次，
 * 中间仍有 TOCTOU 窗口。要真正钉死解析结果得用 curl 的 CURLOPT_RESOLVE。
 */
if (!defined('ABSPATH')) exit;

class Kratos_AI_SSRF {

    /** 保存 base_url 时调用；返回 true 或 WP_Error */
    public static function validate_base_url($url) {
        if (!is_string($url) || $url === '') {
            return new WP_Error('ai_url_empty', __('base_url 为空', 'kratos'));
        }
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return new WP_Error('ai_url_malformed', __('base_url 格式错误', 'kratos'));
        }
        $allow_insecure = defined('KRATOS_AI_ALLOW_INSECURE') && KRATOS_AI_ALLOW_INSECURE;
        if ($parts['scheme'] !== 'https' && !$allow_insecure) {
            return new WP_Error('ai_url_insecure', __('必须使用 https', 'kratos'));
        }
        if (!wp_http_validate_url($url)) {
            return new WP_Error('ai_url_invalid', __('URL 校验失败', 'kratos'));
        }
        $ip_check = self::host_ips_safe($parts['host']);
        if (is_wp_error($ip_check)) return $ip_check;
        return true;
    }

    /**
     * 解析并检查 host 的所有 IP 都不在私有段
     * 结果按请求缓存：每次生成都跑一遍 A + AAAA 查询会给调用链白加一次 DNS RTT。
     */
    public static function host_ips_safe($host) {
        static $memo = array();
        if (isset($memo[$host])) return $memo[$host];
        $memo[$host] = self::resolve_and_check($host);
        return $memo[$host];
    }

    protected static function resolve_and_check($host) {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = array($host);
        } else {
            $v4 = @dns_get_record($host, DNS_A);
            $v6 = @dns_get_record($host, DNS_AAAA);
            $ips = array();
            if (is_array($v4)) foreach ($v4 as $r) if (!empty($r['ip'])) $ips[] = $r['ip'];
            if (is_array($v6)) foreach ($v6 as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            if (!$ips) {
                $ip = gethostbyname($host);
                if ($ip && $ip !== $host) $ips[] = $ip;
            }
        }
        if (!$ips) {
            return new WP_Error('ai_dns_failed', __('DNS 解析失败', 'kratos'));
        }
        foreach ($ips as $ip) {
            if (!self::is_public_ip($ip)) {
                return new WP_Error('ai_url_private_ip', sprintf(__('目标 %s 指向私有地址 %s', 'kratos'), $host, $ip));
            }
        }
        return true;
    }

    public static function is_public_ip($ip) {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /** 挂到 pre_http_request：命中 AI 请求时，对目标 host 再解析一次 */
    public static function pre_http_request($pre, $args, $url) {
        if (empty($args['_kratos_ai'])) return $pre;
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) return new WP_Error('ai_url_malformed', 'malformed url');
        $check = self::host_ips_safe($parts['host']);
        if (is_wp_error($check)) return $check;
        return $pre;
    }
}
