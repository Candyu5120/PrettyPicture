import React, { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Button, Card } from '../components/ui';
import { accountApi } from '../api/account';
import { useConfigStore, useUIStore } from '../store';

const LOGO_URL = 'https://img.ocyo.cn/PrettyPicture/2026/04/ee0b80da6f7706a7.png';

export const Login: React.FC = () => {
  const [searchParams] = useSearchParams();
  const { config } = useConfigStore();
  const { addToast } = useUIStore();
  const siteName = config.site_name || 'CY图床';
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const hasError = !loading && !!error;

  const handleOidcLogin = async () => {
    setError('');
    setLoading(true);
    try {
      const res: any = await accountApi.oidcStart();
      if (!res?.data?.url) {
        throw new Error('OIDC登录地址为空');
      }
      window.location.href = res.data.url;
    } catch (err: any) {
      const msg = err.msg || err.message || 'OIDC登录发起失败';
      setError(msg);
      addToast(msg, 'error');
      setLoading(false);
    }
  };

  useEffect(() => {
    const callbackError = searchParams.get('oidc_error');
    if (callbackError) {
      setLoading(false);
      setError(callbackError);
      return;
    }

    if (config.oidc_enabled === 1 || String(config.oidc_enabled) === '1') {
      handleOidcLogin();
      return;
    }

    setLoading(false);
    setError('OIDC登录未开启，请联系管理员。');
  }, [config.oidc_enabled, searchParams]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md p-8 text-center space-y-4">
        <div className="flex justify-center">
          <img src={LOGO_URL} alt={siteName} className="w-16 h-16 rounded-2xl object-cover shadow-lg" />
        </div>
        <h1 className={`text-2xl font-bold ${hasError ? 'text-danger' : 'text-foreground'}`}>
          {hasError ? '发生错误' : `正在跳转 ${siteName} 统一身份认证`}
        </h1>
        <p className="text-foreground/60">
          {loading ? '请稍候，正在发起 OIDC 登录...' : (error || '请点击下方按钮继续登录')}
        </p>
        <Button
          type="button"
          color="primary"
          className="w-full"
          isLoading={loading}
          onClick={handleOidcLogin}
          isDisabled={loading || !(config.oidc_enabled === 1 || String(config.oidc_enabled) === '1')}
        >
          {hasError ? '重试' : (config.oidc_button_text || '使用OIDC登录')}
        </Button>
      </Card>
    </div>
  );
};

export default Login;
