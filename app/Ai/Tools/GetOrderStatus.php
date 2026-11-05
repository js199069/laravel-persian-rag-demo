<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetOrderStatus implements Tool
{
    public function description(): Stringable|string
    {
        return 'وضعیت یک سفارش را با شماره‌ی آن برمی‌گرداند.';
    }

    public function handle(Request $request): Stringable|string
    {
        // کد رهگیری را فقط این ابزار می‌داند؛ اگر در جواب مدل آمد، یعنی ابزار واقعاً صدا زده شده
        return "سفارش {$request['order_id']}: تحویل پست شده، کد رهگیری TRK-9931";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->string()->required(),
        ];
    }
}