<?php
// 全局中间件定义文件
return [
    // 跨域中间件（优先执行，处理 CORS 预检请求）
    \app\middleware\Cors::class,
    
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    // \think\middleware\SessionInit::class
];
