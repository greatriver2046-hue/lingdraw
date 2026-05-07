@echo off
title ThinkPHP默认队列监听

:: 获取日期时间
for /f "tokens=2 delims==" %%a in ('wmic os get localdatetime /value') do set "dt=%%a"
set "YYYY=%dt:~0,4%"
set "MM=%dt:~4,2%"
set "DD=%dt:~6,2%"
set "HH=%dt:~8,2%"
set "MI=%dt:~10,2%"
set "SS=%dt:~12,2%"
set "LOG_DIR=%cd%\queue_logs"
set "LOG_FILE=%LOG_DIR%\queue_listen_%YYYY%%MM%%DD%_%HH%%MI%%SS%.log"

:: 创建日志目录
if not exist "%LOG_DIR%" md "%LOG_DIR%"

:: 检查think文件
if not exist "think" (
    echo [ERROR] 未找到think文件！请确认批处理文件放在ThinkPHP根目录
    pause
    exit /b 1
)

:: 输出运行信息
echo ================================
echo 正在启动ThinkPHP队列监听...
echo 队列驱动：Redis
echo 队列名称：default
echo 日志文件：%LOG_FILE%
echo ================================
echo.

:: 启动队列监听
(
    echo [START] 队列监听启动时间：%YYYY%-%MM%-%DD% %HH%:%MI%:%SS%
    echo.
    php think queue:listen redis --queue=default --sleep=1 --tries=1 --timeout=0
)

:: 错误处理
if errorlevel 1 (
    echo.
    echo [ERROR] 队列监听启动失败！请检查：
    echo 1. Redis服务是否正常运行（redis-cli ping 测试）
    echo 2. TP项目的Redis配置是否正确
    echo 3. 命令行手动执行：php think queue:listen redis 能否运行
    pause
    exit /b 1
) else (
    echo.
    echo [INFO] 队列监听已正常退出
    pause
    exit /b 0
)
