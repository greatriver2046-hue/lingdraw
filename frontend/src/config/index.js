// 获取外部配置，如果不存在则使用环境变量作为降级方案
const getExternalConfig = () => {
  if (window.__APP_CONFIG__) {
    return window.__APP_CONFIG__;
  }
  return {
    API_BASE_URL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:9998',
    WS_URL: import.meta.env.VITE_WS_URL || 'ws://localhost:2348'
  };
};

const externalConfig = getExternalConfig();

export const config = {
  API_BASE_URL: externalConfig.API_BASE_URL,
  WS_URL: externalConfig.WS_URL
};
