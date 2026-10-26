<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class KadroCreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'kadro:create-admin 
                            {--email=admin@kadro.local : البريد الإلكتروني للمسؤول}
                            {--password=AdminPassword123! : كلمة المرور للمسؤول}
                            {--name=مدير المنصة : اسم المسؤول}';

    /**
     * The console command description.
     */
    protected $description = 'إنشاء حساب المسؤول وتجهيز مفاتيح لوحة التحكم لـ Railway';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->option('email');
        $password = (string) $this->option('password');
        $name = (string) $this->option('name');

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'name' => $name,
                'password' => bcrypt($password),
                'email_verified_at' => now(),
            ]);
            $this->info("✅ تم تحديث بيانات حساب المسؤول الموجود بالفعل.");
        } else {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt($password),
                'email_verified_at' => now(),
            ]);
            $this->info("🎉 تم إنشاء حساب المسؤول بنجاح!");
        }

        $this->newLine();
        $this->line("=================================================");
        $this->line("📋 ضع هذه المتغيرات في Railway (Variables):");
        $this->line("PLATFORM_ADMIN_USER_ID={$user->id}");
        $this->line("PLATFORM_ADMIN_EMAIL={$user->email}");
        $this->line("=================================================");
        $this->newLine();

        $this->line("بيانات الدخول للوحة الإدارة:");
        $this->line("البريد: {$user->email}");
        $this->line("كلمة المرور: {$password}");

        return self::SUCCESS;
    }
}
