<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 业务规则异常：返回 422，由全局异常处理渲染为 JSON
 */
class BusinessException extends RuntimeException
{
    public function __construct(public string $errorMessage, public array $context = [])
    {
        parent::__construct($errorMessage);
    }

    public function render($request)
    {
        return response()->json([
            'message' => $this->errorMessage,
            'error' => $this->errorMessage,
            'context' => $this->context,
        ], 422);
    }
}
