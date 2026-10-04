<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => '邮箱或密码错误'], 401);
        }
        if (! $user->is_active) {
            return response()->json(['message' => '账号已停用'], 403);
        }

        return response()->json([
            'token' => AuthenticateToken::issue($user->id),
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'display_name' => $user->display_name ?? $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'station_id' => $user->station_id,
            'station' => $user->station ? ['id' => $user->station->id, 'code' => $user->station->code, 'name' => $user->station->name] : null,
        ];
    }
}
