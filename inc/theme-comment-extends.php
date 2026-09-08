<?php

/**
 * 扩展功能
 * @author Dylan Li
 * @license GPL-3.0 License
 * @version 2025.12.02
 */
 // 防止直接访问
 if (!defined('ABSPATH')) {
     exit;
 }

// UA 图标：文件位于 assets/img/svg/，键 → 文件名映射见 wpcdi_get_svg_icon()

/**
 * 核心UA解析方法（替换原有解析逻辑）
 * @param string|null $u_agent 用户代理字符串
 * @return array 包含platform/browser/version的解析结果
 * @throws InvalidArgumentException
 */
function kratos_parse_user_agent( $u_agent = null ) {
	if( $u_agent === null && isset($_SERVER['HTTP_USER_AGENT']) ) {
		$u_agent = $_SERVER['HTTP_USER_AGENT'];
	}

	if( $u_agent === null ) {
		throw new \InvalidArgumentException('kratos_parse_user_agent requires a user agent');
	}

	$platform = null;
	$browser  = null;
	$version  = null;

	$empty = array( 'platform' => $platform, 'browser' => $browser, 'version' => $version );

	if( !$u_agent ) {
		return $empty;
	}

	if( preg_match('/\((.*?)\)/m', $u_agent, $parent_matches) ) {
		preg_match_all('/(?P<platform>BB\d+;|Android|CrOS|Tizen|iPhone|iPad|iPod|Linux|(Open|Net|Free)BSD|Macintosh|Windows(\ Phone)?|Silk|linux-gnu|BlackBerry|PlayBook|X11|(New\ )?Nintendo\ (WiiU?|3?DS|Switch)|Xbox(\ One)?)
				(?:\ [^;]*)?
				(?:;|$)/imx', $parent_matches[1], $result);

		$priority = array( 'Xbox One', 'Xbox', 'Windows Phone', 'Tizen', 'Android', 'FreeBSD', 'NetBSD', 'OpenBSD', 'CrOS', 'X11' );

		$result['platform'] = array_unique($result['platform']);
		if( count($result['platform']) > 1 ) {
			if( $keys = array_intersect($priority, $result['platform']) ) {
				$platform = reset($keys);
			} else {
				$platform = $result['platform'][0];
			}
		} elseif( isset($result['platform'][0]) ) {
			$platform = $result['platform'][0];
		}
	}

	if( $platform == 'linux-gnu' || $platform == 'X11' ) {
		$platform = 'Linux';
	} elseif( $platform == 'CrOS' ) {
		$platform = 'Chrome OS';
	}

	preg_match_all('%(?P<browser>Camino|Kindle(\ Fire)?|Firefox|Iceweasel|IceCat|Safari|MSIE|Trident|AppleWebKit|
				TizenBrowser|(?:Headless)?Chrome|YaBrowser|Vivaldi|IEMobile|Opera|OPR|Silk|Midori|Edge|Edg|CriOS|UCBrowser|Puffin|OculusBrowser|SamsungBrowser|
				Baiduspider|Googlebot|YandexBot|bingbot|Lynx|Version|Wget|curl|
				Valve\ Steam\ Tenfoot|
				NintendoBrowser|PLAYSTATION\ (\d|Vita)+)
				(?:\)?;?)
				(?:(?:[:/ ])(?P<version>[0-9A-Z.]+)|/(?:[A-Z]*))%ix',
		$u_agent, $result);

	// If nothing matched, return null (to avoid undefined index errors)
	if( !isset($result['browser'][0]) || !isset($result['version'][0]) ) {
		if( preg_match('%^(?!Mozilla)(?P<browser>[A-Z0-9\-]+)(/(?P<version>[0-9A-Z.]+))?%ix', $u_agent, $result) ) {
			return array( 'platform' => $platform ?: null, 'browser' => $result['browser'], 'version' => isset($result['version']) ? $result['version'] ?: null : null );
		}

		return $empty;
	}

	if( preg_match('/rv:(?P<version>[0-9A-Z.]+)/i', $u_agent, $rv_result) ) {
		$rv_result = $rv_result['version'];
	}

	$browser = $result['browser'][0];
	$version = $result['version'][0];

	$lowerBrowser = array_map('strtolower', $result['browser']);

	$find = function ( $search, &$key = null, &$value = null ) use ( $lowerBrowser ) {
		$search = (array)$search;

		foreach( $search as $val ) {
			$xkey = array_search(strtolower($val), $lowerBrowser);
			if( $xkey !== false ) {
				$value = $val;
				$key   = $xkey;

				return true;
			}
		}

		return false;
	};

	$findT = function ( array $search, &$key = null, &$value = null ) use ( $find ) {
		$value2 = null;
		if( $find(array_keys($search), $key, $value2) ) {
			$value = $search[$value2];

			return true;
		}

		return false;
	};

	$key = 0;
	$val = '';
	if( $findT(array( 'OPR' => 'Opera', 'UCBrowser' => 'UC Browser', 'YaBrowser' => 'Yandex', 'Iceweasel' => 'Firefox', 'Icecat' => 'Firefox', 'CriOS' => 'Chrome', 'Edg' => 'Edge' ), $key, $browser) ) {
		$version = $result['version'][$key];
	}elseif( $find('Playstation Vita', $key, $platform) ) {
		$platform = 'PlayStation Vita';
		$browser  = 'Browser';
	} elseif( $find(array( 'Kindle Fire', 'Silk' ), $key, $val) ) {
		$browser  = $val == 'Silk' ? 'Silk' : 'Kindle';
		$platform = 'Kindle Fire';
		if( !($version = $result['version'][$key]) || !is_numeric($version[0]) ) {
			$version = $result['version'][array_search('Version', $result['browser'])];
		}
	} elseif( $find('NintendoBrowser', $key) || $platform == 'Nintendo 3DS' ) {
		$browser = 'NintendoBrowser';
		$version = $result['version'][$key];
	} elseif( $find('Kindle', $key, $platform) ) {
		$browser = $result['browser'][$key];
		$version = $result['version'][$key];
	} elseif( $find('Opera', $key, $browser) ) {
		$find('Version', $key);
		$version = $result['version'][$key];
	} elseif( $find('Puffin', $key, $browser) ) {
		$version = $result['version'][$key];
		if( strlen($version) > 3 ) {
			$part = substr($version, -2);
			if( ctype_upper($part) ) {
				$version = substr($version, 0, -2);

				$flags = array( 'IP' => 'iPhone', 'IT' => 'iPad', 'AP' => 'Android', 'AT' => 'Android', 'WP' => 'Windows Phone', 'WT' => 'Windows' );
				if( isset($flags[$part]) ) {
					$platform = $flags[$part];
				}
			}
		}
	} elseif( $find(array( 'IEMobile', 'Edge', 'Midori', 'Vivaldi', 'OculusBrowser', 'SamsungBrowser', 'Valve Steam Tenfoot', 'Chrome', 'HeadlessChrome' ), $key, $browser) ) {
		$version = $result['version'][$key];
	} elseif( $rv_result && $find('Trident') ) {
		$browser = 'MSIE';
		$version = $rv_result;
	} elseif( $browser == 'AppleWebKit' ) {
		if( $platform == 'Android' ) {
			$browser = 'Android Browser';
		} elseif( strpos($platform, 'BB') === 0 ) {
			$browser  = 'BlackBerry Browser';
			$platform = 'BlackBerry';
		} elseif( $platform == 'BlackBerry' || $platform == 'PlayBook' ) {
			$browser = 'BlackBerry Browser';
		} else {
			$find('Safari', $key, $browser) || $find('TizenBrowser', $key, $browser);
		}

		$find('Version', $key);
		$version = $result['version'][$key];
	} elseif( $pKey = preg_grep('/playstation \d/i', $result['browser']) ) {
		$pKey = reset($pKey);

		$platform = 'PlayStation ' . preg_replace('/\D/', '', $pKey);
		$browser  = 'NetFront';
	}

	return array( 'platform' => $platform ?: null, 'browser' => $browser ?: null, 'version' => $version ?: null );
}

/**
 * 输出浏览器/系统的 SVG 图标 <img> 标签。SVG 文件位于 assets/img/svg/，
 * 未匹配到（含 IE/Opera/HuaweiBrowser/UCBrowser/SamsungBrowser/HarmonyOS/
 * BlackBerry/KindleFire 等尚未提供的图标）走 unknown.svg 兜底。
 *
 * @param string $type browser|os
 * @param string $name kratos_parse_user_agent 返回的名称
 * @return string <img> HTML
 */
function wpcdi_get_svg_icon($type, $name) {
    $file = 'unknown';

    if ($type === 'browser') {
        if (strpos($name, 'Chrome') !== false || strpos($name, 'CriOS') !== false) {
            $file = 'chrome';
        } elseif (strpos($name, 'Firefox') !== false || strpos($name, 'Iceweasel') !== false || strpos($name, 'IceCat') !== false) {
            $file = 'firefox';
        } elseif (strpos($name, 'Safari') !== false) {
            $file = 'safari';
        } elseif (strpos($name, 'Edge') !== false || strpos($name, 'Edg') !== false) {
            $file = 'edge';
        } elseif (strpos($name, 'Opera') !== false || strpos($name, 'OPR') !== false) {
            $file = 'opera';
        } elseif (strpos($name, '华为浏览器') !== false || strpos($name, 'HuaweiBrowser') !== false) {
            $file = 'huaweibrowser';
        } elseif (strpos($name, 'UC Browser') !== false || strpos($name, 'UCBrowser') !== false) {
            $file = 'uc';
        } elseif (strpos($name, 'SamsungBrowser') !== false) {
            $file = 'samsung';
        }
    } elseif ($type === 'os') {
        if (strpos($name, 'Windows') !== false) {
            $file = 'windows';
        } elseif (strpos($name, 'macOS') !== false || strpos($name, 'Macintosh') !== false
            || strpos($name, 'iPhone') !== false || strpos($name, 'iPad') !== false
            || strpos($name, 'iPod') !== false || strpos($name, 'iOS') !== false) {
            $file = 'apple';
        } elseif (strpos($name, '鸿蒙') !== false || strpos($name, 'HarmonyOS') !== false) {
            $file = 'harmonyos';
        } elseif (strpos($name, 'Android') !== false) {
            $file = 'android';
        } elseif (strpos($name, 'Linux') !== false || strpos($name, 'Chrome OS') !== false) {
            $file = 'linux';
        }
    }

    $url = get_template_directory_uri() . '/assets/img/svg/' . $file . '.svg';
    return '<img src="' . esc_url($url) . '" width="14" height="14" alt="" style="vertical-align:middle;margin-right:2px;">';
}

/**
 * 把 ip2region 的原始地名（英文或中文，含「省/市/自治区」后缀）本地化成中文短名。
 *
 * 入参就是 kratos_ip2region_lookup() 的返回值；出参三个字段都用 '' 表示未知，
 * 供评论信息条（wpcdi_get_comment_info）与地域统计（inc/theme-comment-geo.php）
 * 共用，避免任何一方再去反解「中国-江苏-南京」这种展示串导致省市错位。
 *
 * @param array $raw ['country' => .., 'region' => .., 'city' => ..]
 * @return array{country:string, region:string, city:string}
 */
function kratos_comment_geo_localize($raw)
{
    $raw = is_array($raw) ? $raw : array();

				$country_map = [
					// 亚洲
					'China' => '中国',
					'Hong Kong' => '中国香港',
					'Taiwan' => '中国台湾',
					'Macao' => '中国澳门',
					'Japan' => '日本',
					'South Korea' => '韩国',
					'Singapore' => '新加坡',
					'Thailand' => '泰国',
					'Malaysia' => '马来西亚',
					'Indonesia' => '印度尼西亚',
					'Philippines' => '菲律宾',
					'Vietnam' => '越南',
					'Myanmar' => '缅甸',
					'Cambodia' => '柬埔寨',
					'India' => '印度',
					'Pakistan' => '巴基斯坦',
					'Bangladesh' => '孟加拉国',
					'Sri Lanka' => '斯里兰卡',
					'Nepal' => '尼泊尔',
					'Iran' => '伊朗',
					'Saudi Arabia' => '沙特阿拉伯',
					'United Arab Emirates' => '阿联酋',
					'Kuwait' => '科威特',
					'Oman' => '阿曼',
					'Israel' => '以色列',
					'Turkey' => '土耳其',
					'Russian Federation' => '俄罗斯', // 俄罗斯标准英文全称

					// 欧洲（注：美国/加拿大实际属北美洲，按你的原分类保留）
					'United States of America' => '美国', // 完整全称（兼容简写 USA/United States）
					'United States' => '美国', // 简写兼容
					'Canada' => '加拿大',
					'United Kingdom of Great Britain and Northern Ireland' => '英国', // 完整全称
					'United Kingdom' => '英国', // 简写兼容
					'Germany' => '德国',
					'France' => '法国',
					'Italy' => '意大利',
					'Spain' => '西班牙',
					'Portugal' => '葡萄牙',
					'Netherlands' => '荷兰',
					'Belgium' => '比利时',
					'Luxembourg' => '卢森堡',
					'Switzerland' => '瑞士',
					'Austria' => '奥地利',
					'Sweden' => '瑞典',
					'Norway' => '挪威',
					'Denmark' => '丹麦',
					'Finland' => '芬兰',
					'Ireland' => '爱尔兰',
					'Greece' => '希腊',
					'Poland' => '波兰',
					'Czech Republic' => '捷克',
					'Hungary' => '匈牙利',
					'Romania' => '罗马尼亚',
					'Bulgaria' => '保加利亚',

					// 大洋洲
					'Australia' => '澳大利亚',
					'New Zealand' => '新西兰',

					// 非洲
					'South Africa' => '南非',
					'Egypt' => '埃及',
					'Nigeria' => '尼日利亚',
					'Kenya' => '肯尼亚',

					// 美洲
					'Brazil' => '巴西',
					'Argentina' => '阿根廷',
					'Mexico' => '墨西哥',
					'Chile' => '智利',
					'Colombia' => '哥伦比亚'
				];

				/************************** 2. 中国省份/直辖市/自治区/特别行政区映射表 **************************/
				$province_map = [
					// 直辖市
					'Beijing' => '北京', 'Shanghai' => '上海', 'Tianjin' => '天津', 'Chongqing' => '重庆',
					// 省
					'Hebei' => '河北', 'Shanxi' => '山西', 'Liaoning' => '辽宁', 'Jilin' => '吉林',
					'Heilongjiang' => '黑龙江', 'Jiangsu' => '江苏', 'Zhejiang' => '浙江', 'Anhui' => '安徽',
					'Fujian' => '福建', 'Jiangxi' => '江西', 'Shandong' => '山东', 'Henan' => '河南',
					'Hubei' => '湖北', 'Hunan' => '湖南', 'Guangdong' => '广东', 'Hainan' => '海南',
					'Sichuan' => '四川', 'Guizhou' => '贵州', 'Yunnan' => '云南', 'Shaanxi' => '陕西',
					'Gansu' => '甘肃', 'Qinghai' => '青海', 'Taiwan' => '台湾',
					// 自治区
					'Inner Mongolia' => '内蒙古', 'Guangxi' => '广西', 'Tibet' => '西藏', 'Ningxia' => '宁夏', 'Xinjiang' => '新疆',
					// 特别行政区
					'Hong Kong' => '中国香港', 'Macao' => '中国澳门',
					// 兼容简写/拼音变体
					'Nei Mongol' => '内蒙古', 'Xizang' => '西藏', 'Ningxia Hui' => '宁夏', 'Xinjiang Uygur' => '新疆'
				];

				/************************** 3. 中国主要城市映射表（覆盖各省核心城市）**************************/
				$city_map = [
					// 直辖市
					'Beijing' => '北京', 'Shanghai' => '上海', 'Tianjin' => '天津', 'Chongqing' => '重庆',
					// 河北省
					'Shijiazhuang' => '石家庄', 'Tangshan' => '唐山', 'Qinhuangdao' => '秦皇岛', 'Handan' => '邯郸',
					'Baoding' => '保定', 'Zhangjiakou' => '张家口', 'Chengde' => '承德', 'Langfang' => '廊坊',
					// 山西省
					'Taiyuan' => '太原', 'Datong' => '大同', 'Xinzhou' => '忻州', 'Yangquan' => '阳泉', 'Jinzhong' => '晋中',
					// 辽宁省
					'Shenyang' => '沈阳', 'Dalian' => '大连', 'Anshan' => '鞍山', 'Fushun' => '抚顺', 'Benxi' => '本溪',
					// 吉林省
					'Changchun' => '长春', 'Jilin' => '吉林', 'Siping' => '四平', 'Yanbian' => '延边',
					// 黑龙江省
					'Harbin' => '哈尔滨', 'Qiqihar' => '齐齐哈尔', 'Daqing' => '大庆', 'Jiamusi' => '佳木斯', 'Mudanjiang' => '牡丹江',
					// 江苏省
					'Nanjing' => '南京', 'Suzhou' => '苏州', 'Wuxi' => '无锡', 'Changzhou' => '常州', 'Zhenjiang' => '镇江',
					'Nantong' => '南通', 'Yangzhou' => '扬州', 'Taizhou' => '泰州', 'Xuzhou' => '徐州', 'Lianyungang' => '连云港',
					// 浙江省
					'Hangzhou' => '杭州', 'Ningbo' => '宁波', 'Wenzhou' => '温州', 'Jiaxing' => '嘉兴', 'Huzhou' => '湖州',
					'Shaoxing' => '绍兴', 'Jinhua' => '金华', 'Zhoushan' => '舟山', 'Taizhou' => '台州', 'Lishui' => '丽水',
					// 安徽省
					'Hefei' => '合肥', 'Wuhu' => '芜湖', 'Bengbu' => '蚌埠', 'Huainan' => '淮南', 'Maanshan' => '马鞍山',
					'Huaibei' => '淮北', 'Tongling' => '铜陵', 'Anqing' => '安庆', 'Huangshan' => '黄山',
					// 福建省
					'Fuzhou' => '福州', 'Xiamen' => '厦门', 'Putian' => '莆田', 'Sanming' => '三明', 'Quanzhou' => '泉州',
					'Zhangzhou' => '漳州', 'Nanping' => '南平', 'Longyan' => '龙岩', 'Ningde' => '宁德',
					// 江西省
					'Nanchang' => '南昌', 'Jiujiang' => '九江', 'Ganzhou' => '赣州', 'Jian' => '吉安', 'Yichun' => '宜春',
					// 山东省
					'Jinan' => '济南', 'Qingdao' => '青岛', 'Zibo' => '淄博', 'Zaozhuang' => '枣庄', 'Dongying' => '东营',
					'Yantai' => '烟台', 'Weifang' => '潍坊', 'Jining' => '济宁', 'Taian' => '泰安', 'Weihai' => '威海',
					// 河南省
					'Zhengzhou' => '郑州', 'Kaifeng' => '开封', 'Luoyang' => '洛阳', 'Pingdingshan' => '平顶山', 'Anyang' => '安阳',
					'Hebi' => '鹤壁', 'Xinxiang' => '新乡', 'Jiaozuo' => '焦作', 'Puyang' => '濮阳', 'Xuchang' => '许昌',
					// 湖北省
					'Wuhan' => '武汉', 'Huangshi' => '黄石', 'Shiyan' => '十堰', 'Yichang' => '宜昌', 'Xiangyang' => '襄阳',
					// 湖南省
					'Changsha' => '长沙', 'Zhuzhou' => '株洲', 'Xiangtan' => '湘潭', 'Hengyang' => '衡阳', 'Shaoyang' => '邵阳',
					'Yueyang' => '岳阳', 'Changde' => '常德', 'Zhangjiajie' => '张家界',
					// 广东省
					'Guangzhou' => '广州', 'Shenzhen' => '深圳', 'Zhuhai' => '珠海', 'Shantou' => '汕头', 'Foshan' => '佛山',
					'Jiangmen' => '江门', 'Zhaoqing' => '肇庆', 'Huizhou' => '惠州', 'Meizhou' => '梅州', 'Shanwei' => '汕尾',
					'Dongguan' => '东莞', 'Zhongshan' => '中山', 'Jiangmen' => '江门', 'Qingyuan' => '清远', 'Chaozhou' => '潮州',
					// 海南省
					'Haikou' => '海口', 'Sanya' => '三亚', 'Sansha' => '三沙', 'Danzhou' => '儋州',
					// 四川省
					'Chengdu' => '成都', 'Mianyang' => '绵阳', 'Deyang' => '德阳', 'Guangyuan' => '广元', 'Suining' => '遂宁',
					'Neijiang' => '内江', 'Leshan' => '乐山', 'Nanchong' => '南充', 'Meishan' => '眉山',
					// 贵州省
					'Guiyang' => '贵阳', 'Liupanshui' => '六盘水', 'Zunyi' => '遵义', 'Anshun' => '安顺',
					// 云南省
					'Kunming' => '昆明', 'Qujing' => '曲靖', 'Yuxi' => '玉溪', 'Baoshan' => '保山', 'Zhaotong' => '昭通',
					// 陕西省
					'Xi\'an' => '西安', 'Tongchuan' => '铜川', 'Baoji' => '宝鸡', 'Xianyang' => '咸阳', 'Weinan' => '渭南',
					// 甘肃省
					'Lanzhou' => '兰州', 'Jiayuguan' => '嘉峪关', 'Jinchang' => '金昌', 'Baiyin' => '白银', 'Tianshui' => '天水',
					// 青海省
					'Xining' => '西宁', 'Haixi' => '海西',
					// 内蒙古
					'Hohhot' => '呼和浩特', 'Baotou' => '包头', 'Wuhai' => '乌海', 'Chifeng' => '赤峰',
					// 广西
					'Nanning' => '南宁', 'Liuzhou' => '柳州', 'Guilin' => '桂林', 'Wuzhou' => '梧州', 'Beihai' => '北海',
					// 西藏
					'Lhasa' => '拉萨', 'Shigatse' => '日喀则', 'Nyingchi' => '林芝',
					// 宁夏
					'Yinchuan' => '银川', 'Shizuishan' => '石嘴山', 'Wuzhong' => '吴忠',
					// 新疆
					'Urumqi' => '乌鲁木齐', 'Karamay' => '克拉玛依', 'Turpan' => '吐鲁番', 'Hami' => '哈密',
					// 港澳台
					'Hong Kong' => '香港', 'Macao' => '澳门', 'Taipei' => '台北', 'Kaohsiung' => '高雄', 'Taichung' => '台中'
				];

    // 中文原始值不在映射表里时直接沿用，只去掉行政区划后缀，保证与表内短名同形
    // （否则「北京」与「北京市」会被当成两个地名，省份榜里也会出现重复条目）
    $trim_suffix = function ($v) {
        $v = trim((string) $v);
        if ($v === '' || $v === '未知' || $v === '0') {
            return '';
        }
        return preg_replace('/(省|市|特别行政区|(?:壮族|回族|维吾尔|)自治区)$/u', '', $v);
    };

    $pick = function ($map, $value) use ($trim_suffix) {
        $value = trim((string) $value);
        if (isset($map[$value])) {
            return $map[$value];
        }
        $short = $trim_suffix($value);
        return isset($map[$short]) ? $map[$short] : $short;
    };

    return array(
        'country' => $pick($country_map, isset($raw['country']) ? $raw['country'] : ''),
        'region'  => $pick($province_map, isset($raw['region']) ? $raw['region'] : ''),
        'city'    => $pick($city_map, isset($raw['city']) ? $raw['city'] : ''),
    );
}


/**
 * 核心功能：获取评论者的设备/地理信息
 * @param string $comment_ip 评论者IP
 * @param string $user_agent 评论者UA
 * @return array 信息数组
 */
function wpcdi_get_comment_info($comment_ip, $user_agent) {
    $info = array(
        'browser' => '未知浏览器',
        'os'      => '未知系统',
        'location'=> '未知位置'
    );

    // 1. 使用新的kratos_parse_user_agent解析UA
    try {
        $parsed = kratos_parse_user_agent($user_agent);

        // 拼接浏览器信息
        if (!empty($parsed['browser'])) {
            $info['browser'] = $parsed['browser'] . (empty($parsed['version']) ? '' : ' ' . $parsed['version']);
        }

        // 拼接系统信息
        if (!empty($parsed['platform'])) {
            // 优化系统名称显示
            $os_map = array(
                'Windows Phone' => 'Windows Phone',
                'Windows' => 'Windows',
                'Macintosh' => 'macOS',
                'Chrome OS' => 'Chrome OS',
                'Linux' => 'Linux',
                'Android' => 'Android',
                'iPhone' => 'iOS',
                'iPad' => 'iOS',
                'iPod' => 'iOS',
                'BlackBerry' => 'BlackBerry',
                'Kindle Fire' => 'Kindle Fire',
                'Tizen' => 'Tizen',
                'PlayStation Vita' => 'PlayStation Vita',
                'PlayStation 4' => 'PlayStation 4',
                'PlayStation 5' => 'PlayStation 5'
            );
            $info['os'] = isset($os_map[$parsed['platform']]) ? $os_map[$parsed['platform']] : $parsed['platform'];
        }
    } catch (Exception $e) {
        // 解析失败时保留默认值
        $info['browser'] = '未知浏览器';
        $info['os'] = '未知系统';
    }

    // 2. 解析地理位置（基于 ip2region 离线数据库，由 inc/ip2region/ip2region-updater.php 维护）
    try {
        $location_data = function_exists('kratos_ip2region_lookup')
            ? kratos_ip2region_lookup($comment_ip)
            : ['country' => '', 'region' => '', 'city' => ''];

        if (!empty($location_data['country']) || !empty($location_data['region']) || !empty($location_data['city'])) {

            $localized = kratos_comment_geo_localize($location_data);
            $country = $localized['country'] !== '' ? $localized['country'] : '未知';
            $region  = $localized['region']  !== '' ? $localized['region']  : '未知';
            $city    = $localized['city']    !== '' ? $localized['city']    : '未知';

            $location_parts = [];
            if (!empty($country) && $country !== '未知') {
					$location_parts[] = $country;
				}

				if (!empty($region) && $region !== '未知') {
					// 若城市和省份相同，仅添加省份；否则分别添加（先省后市）
					if (!empty($city) && $city !== '未知' && $city !== $region) {
						$location_parts[] = $region;
						$location_parts[] = $city;
					} else {
						$location_parts[] = $region;
					}
				} elseif (!empty($city) && $city !== '未知') {
					$location_parts[] = $city;
				}

            // 有数据则拼接，无数据则保留默认值
            if (!empty($location_parts)) {
                $info['location'] = implode('-', $location_parts);
            }
        }
    } catch (Exception $e) {
        // 捕获异常，避免影响整体功能
        $info['location'] = '定位失败';
    }

    return $info;
}

/**
 * 核心修改：在评论内容后追加带精美SVG图标的设备/地理信息
 * @param string $comment_text 原始评论内容
 * @param WP_Comment $comment 评论对象
 * @return string 追加后的评论内容
 */
function wpcdi_add_info_after_comment_content($comment_text, $comment) {
    // 总开关：关闭则原样返回
    if (!kratos_option('g_comment_info_enabled', true)) {
        return $comment_text;
    }

    // 哪些项要展示：浏览器 / 系统 / 归属地
    $display = kratos_option('g_comment_info_display', array('browser', 'os', 'location'));
    if (!is_array($display)) {
        $display = array('browser', 'os', 'location');
    }
    $display = array_filter($display); // 去掉 CSF checkbox 取消选中后存的空字符串
    if (empty($display)) {
        return $comment_text;
    }

    $show_browser  = in_array('browser', $display, true);
    $show_os       = in_array('os', $display, true);
    $show_location = in_array('location', $display, true);

    // 获取评论者 IP、UA
    $comment_ip = sanitize_text_field($comment->comment_author_IP);
    $user_agent = sanitize_text_field($comment->comment_agent);

    $info = wpcdi_get_comment_info($comment_ip, $user_agent);

    $location_icon = '<img src="' . esc_url(get_template_directory_uri() . '/assets/img/svg/location.svg') . '" width="16" height="16" alt="" style="vertical-align:middle;">';

    $item_style = 'white-space: nowrap;display: inline-flex; align-items: center;';
    $items = array();

    if ($show_os) {
        $os_icon = wpcdi_get_svg_icon('os', $info['os']);
        $items[] = '<span style="' . $item_style . '">' . $os_icon . '<span>' . esc_html($info['os']) . '</span></span>';
    }
    if ($show_browser) {
        $browser_icon = wpcdi_get_svg_icon('browser', $info['browser']);
        $items[] = '<span style="' . $item_style . '">' . $browser_icon . '<span>' . esc_html($info['browser']) . '</span></span>';
    }
    if ($show_location) {
        $items[] = '<span style="' . $item_style . '">' . $location_icon . '<span>' . esc_html($info['location']) . '</span></span>';
    }

    if (empty($items)) {
        return $comment_text;
    }

    $info_html = '<div class="wpcdi-comment-info" style="flex-wrap: wrap;white-space: normal;padding-top:10px; font-size: 12px; color: #495057; line-height: 1.5; display: flex; align-items: center; gap: 5px;">'
        . implode('', $items)
        . '</div>';

    return $comment_text . $info_html;
}
// 绑定评论内容钩子，优先级10，参数2
add_filter('comment_text', 'wpcdi_add_info_after_comment_content', 10, 2);
