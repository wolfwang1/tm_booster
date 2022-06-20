<?php
/**
 * -------------------------------------------------------------------------------------
 * Copyright (c) 2014-2017 Beijing Chinaway Technologies Co., Ltd. All rights reserved.
 * -------------------------------------------------------------------------------------
 * TmBooster服务器直出Hybrid框架通用SDK
 *
 * PHP version 5.4.0 or above
 *
 * @category Truckmanager
 * @package  TmBooster
 * @author   Wolf Wang <wangyu@g7.com.cn>
 * @license  http://www.huoyunren.com/ None
 * @link     http://www.huoyunren.com/
 */
namespace TmBooster;
/**
 * TmBooster主逻辑类
 *
 * @category TmBooster
 * @package  TmBooster
 * @author   Wolf Wang <wangyu@g7.com.cn>
 * @license  http://www.huoyunren.com/ None
 * @link     http://www.huoyunren.com/
 */
class TmBooster
{
    /**
     * 用于匹配模板中title标签的正则表达式
     *
     * @var const string
     */
    const TMB_TITLE_TAG_PATTERN = '/<title(.*?)<\/title>/i';
    
    /**
     * 模板中用于标识title数据块的关键字
     *
     * @var const string
     */
    const TMB_TITLE_TAG_KEY = '{title}';
    
    /**
     * 模板中数据块关键字的开始符号
     *
     * @var const string
     */
    const TMB_TITLE_TAG_KEY_START = '{';
    
    /**
     * 模板中数据块关键字的结束符号
     *
     * @var const string
     */
    const TMB_TITLE_TAG_KEY_END = '}';
    
    /**
     * 用于匹配模板中数据块标签的正则表达式
     * 兼容sonic差异数据块标签
     *
     * @var const string
     */
    const TMB_DIFF_TAG_PATTERN = '/<!--(tmbdiff|sonicdiff)-?(\w*)-->[\s\S]+?<!--(tmbdiff|sonicdiff)-?\w*-end-->/i';
    
    /**
     * 离线缓存模式(必须是字符型)
     * 可选模式为'true', 'false', 'store', 'http'
     *
     * @var static string
     */
    protected static $offline = 'true';
    
    /**
     * 打开TmBooster输出缓冲
     * 在代码输出内容之前调用, 启动后脚本将不会再输出内容(http头除外)
     *
     * @return none
     */
    public static function start()
    {
        ob_start();
    }
    
    /**
     * 获取TmBooster输出缓冲区内容并执行主要直出逻辑
     * 缓冲区内容获取后将被清除
     *
     * @return none
     */
    public static function end()
    {
        $content = ob_get_clean();
        $headers = self::getHeaders();
        
        //客户端header中包含accept-diff且内容为true(字符型), 则表示客户端支持TmB加速协议
        if (isset($headers['accept-diff']) && $headers['accept-diff'] === 'true') {
            $etag = null;
            //http标准条件式请求头, 由客户端发送, 内容为客户端缓存内容的etag
            if (!empty($headers['if-none-match'])) {
                $etag = $headers['if-none-match'];
            }
            
            if (self::$offline !== 'false') {
                $hash = sha1($content);
                if ($hash == $etag) {
                    header('Cache-Offline: store');
                    header('Content-Length: 0');
                    header('HTTP/1.1 304 Not Modified');
                    exit;
                }
                header('Etag: '.$hash);
            }
            
            header("Cache-Offline: ".self::$offline);
            //将缓冲区内容进行分离作业, 分离出直出内容的模板和数据块部分
            $content = self::contentSeparater($content);
            header('Content-Length: '.strlen($content));
        }
        
        echo $content;
    }
    
    /**
     * 缓冲区内容分离逻辑, 用于分离直出内容的数据块和模板
     *
     * @param string $content 缓冲区内容
     * 
     * @return string $result 差异数据json或缓冲区内容
     */
    public static function contentSeparater($content)
    {
        $hash = sha1($content);
        $headers = self::getHeaders();
        
        $clientTemplateTag = null;
        if (!empty($headers['template-tag'])) {
            $clientTemplateTag = $headers['template-tag'];
        }
        
        //对模板的标题部分做单独处理
        //主要针对html的title标签, 可通过常量配置成其他标题格式
        $title = '';
        preg_match(self::TMB_TITLE_TAG_PATTERN, $content, $matches);
        if (!empty($matches[0])) {
            $title = $matches[0];
        }
        //将标题部分替换成模板格式
        $template = preg_replace(self::TMB_TITLE_TAG_PATTERN, self::TMB_TITLE_TAG_KEY, $content);
        //处理其他数据块部分
        $templateReplace = new TemplateReplace();
        $template = preg_replace_callback(self::TMB_DIFF_TAG_PATTERN, array($templateReplace, 'handler'), $template);
        //通过正则匹配和替换之后形成纯粹的模板数据
        $templateHash = sha1($template);
        header('template-tag: '.$templateHash);
        
        $result = '';
        if ($templateHash === $clientTemplateTag) {
            //模板相同时通过json方式输出数据块内容
            header('template-change: false');
            $result = array(
                'data' => array(
                    self::TMB_TITLE_TAG_KEY => $title,
                ),
                'template-tag' => $templateHash,
                'html-sha1' => $hash,
                'diff' => '',//用于兼容VasSonic的ios版SDK, 安卓中无实际意义
            );
            //获取所有匹配的数据块并构造成可供客户端解析的数据结构
            $matchedTags = TemplateReplace::$matchedTags;
            foreach ($matchedTags as $tagName=>$matchedTag) {
                if (!empty($matchedTag)) {
                    $tagKey = self::TMB_TITLE_TAG_KEY_START.$tagName.self::TMB_TITLE_TAG_KEY_END;
                    $result['data'][$tagKey] = $matchedTag;
                }
            }
            $result = json_encode($result);
        } else {
            //模板变化时直接输出所有缓冲区内容
            header('template-change: true');
            $result = $content;
        }
        return $result;
    }
    
    /**
     * 获取Http头信息
     *
     * @return array $headers http头信息
     */
    public static function getHeaders()
    {
        if (empty($_SERVER) || !is_array($_SERVER)) {
            return array();
        }
        $headers = array();
        //将SERVER超全局变量里的http头信息字段名转化成内部逻辑所使用的格式
        //下划线转中划线, 大写转小写
        foreach ($_SERVER as $key=>$value) {
            if (substr($key, 0, 5) == 'HTTP_') {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        return $headers;
    }
}

/**
 * TmBooster模板替换类
 *
 * @category TemplateReplace
 * @package  TmBooster
 * @author   Wolf Wang <wangyu@g7.com.cn>
 * @license  http://www.huoyunren.com/ None
 * @link     http://www.huoyunren.com/
 */
class TemplateReplace
{
    /**
     * 数据块未取名时的自动命名前缀
     *
     * @var static string
     */
    protected static $autoPrefix = 'auto';
    
    /**
     * 数据块未取名时的自动命名后缀(递增id)
     *
     * @var static string
     */
    protected static $autoIndex = 0;
    
    /**
     * 所有匹配到的数据块
     *
     * @var static array
     */
    public static $matchedTags = array();
    
    /**
     * 回调函数, 通过正则匹配查找模板中存在的数据块
     *
     * @param array $matches 正则匹配数组
     * 
     * @return string 匹配到的数据块关键字
     */
    public function handler($matches)
    {
        //$matches[0]为匹配到的完整内容, $matches[1]为数据块注释标签关键字
        //注释标签关键字可以是tmbdiff或sonicdiff
        //$matches[2]为正则匹配到的数据块名称
        if (!empty($matches) && !empty($matches[0])) {
            if (!empty($matches[2])) {
                $tagName = $matches[2];
            } else {
                //如果正则匹配不到相应的数据块名称
                //则通过自动前缀和递增id为数据块命名
                $tagName = self::$autoPrefix.self::$autoIndex++;
            }
            self::$matchedTags[$tagName] = $matches[0];
            //通过数据块关键字前缀和关键字后缀组合成数据块关键字
            //例如默认的{ + 数据块名 + }组合方式
            return TmBooster::TMB_TITLE_TAG_KEY_START.$tagName.TmBooster::TMB_TITLE_TAG_KEY_END;
        }
    }
}