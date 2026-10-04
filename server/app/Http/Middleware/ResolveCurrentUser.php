<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 简化身份识别:前端通过 X-User-Id 头携带当前用户。
 * 写操作(POST/PUT/PATCH/DELETE)必须携带;读操作可选。
 */
class ResolveCurrentUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->header('X-User-Id');

        if ($userId !== null) {
            $user = User::find($userId);
            if ($user === null) {
                return response()->json(['message' => '无效的用户标识'], 401);
            }
            auth()->setUser($user);
        } elseif (! $request->isMethod('GET')) {
            return response()->json(['message' => '缺少 X-User-Id 请求头,请先在页面右上角选择用户'], 401);
        }

        return $next($request);
    }
}
