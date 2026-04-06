import React, { useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { userApi } from '../api/user';
import { useAuthStore, useUIStore } from '../store';

export const OidcCallback: React.FC = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { setToken, setUser } = useAuthStore();
  const { addToast } = useUIStore();

  useEffect(() => {
    const token = searchParams.get('token');
    const error = searchParams.get('error');

    if (error) {
      addToast(error, 'error');
      navigate(`/login?oidc_error=${encodeURIComponent(error)}`, { replace: true });
      return;
    }

    if (!token) {
      const msg = 'OIDC登录失败，缺少登录凭证';
      addToast(msg, 'error');
      navigate(`/login?oidc_error=${encodeURIComponent(msg)}`, { replace: true });
      return;
    }

    const loginByToken = async () => {
      try {
        setToken(token);
        const userRes: any = await userApi.info();
        setUser(userRes.data);
        addToast('登录成功', 'success');
        navigate('/', { replace: true });
      } catch {
        useAuthStore.getState().logout();
        const msg = 'OIDC登录失败，请重试';
        addToast(msg, 'error');
        navigate(`/login?oidc_error=${encodeURIComponent(msg)}`, { replace: true });
      }
    };

    loginByToken();
  }, [addToast, navigate, searchParams, setToken, setUser]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-background">
      <div className="text-center text-foreground/70">正在完成OIDC登录...</div>
    </div>
  );
};

export default OidcCallback;

