<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 轻量无状态认证：Bearer {userId}.{hmac_sha256(userId, APP_KEY)}
 */
class AuthenticateToken
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $token = (string) $request->bearerToken();
        if (! str_contains($token, '.')) {
            return $this->fail('缺少认证令牌');
        }
        [$id, $sig] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $id, (string) config('app.key'));
        if (! hash_equals($expected, $sig)) {
            return $this->fail('认证令牌无效');
        }
        $user = User::find($id);
        if (! $user || ! $user->is_active) {
            return $this->fail('用户不存在或已停用');
        }
        if ($role) {
            $roles = explode(',', $role);
            if (! in_array($user->role, $roles, true)) {
                return response()->json([
                    'message' => '权限不足，需要角色：'.implode(' 或 ', $roles),
                ], 403);
            }
        }
        $request->setUserResolver(fn () => $user);
        return $next($request);
    }

    private function fail(string $msg): Response
    {
        return response()->json(['message' => $msg], 401);
    }

    public static function issue(int $userId): string
    {
        return $userId.'.'.hash_hmac('sha256', (string) $userId, (string) config('app.key'));
    }
}
