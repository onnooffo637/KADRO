import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import PasskeyVerify from '@/components/passkey-verify';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="تسجيل الدخول" />

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6 rounded-2xl border border-[#e8eaf2] bg-white p-6 shadow-[0_22px_50px_-32px_rgba(29,35,86,.5)] dark:border-white/10 dark:bg-[#191d35]"
            >
                {({ processing, errors }) => (
                    <div dir="rtl">
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">البريد الإلكتروني</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="name@example.com"
                                    dir="ltr"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">كلمة المرور</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="mr-auto text-sm"
                                            tabIndex={5}
                                        >
                                            نسيت كلمة المرور؟
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="كلمة المرور"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">تذكرني</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full bg-[#655de8] text-white hover:bg-[#5148d4]"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                تسجيل الدخول
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            ليس لديك حساب؟{' '}
                            <TextLink href={register()} tabIndex={5}>
                                أنشئ حسابًا
                            </TextLink>
                        </div>
                    </div>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'تسجيل الدخول إلى Manga Gate',
    description: 'أدخل بريدك الإلكتروني وكلمة المرور للوصول إلى حسابك.',
};
