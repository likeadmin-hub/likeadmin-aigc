<?php
declare(strict_types=1);
namespace app\common\service\brand;
interface BrandPaymentGateway
{
    public function context(string $from,array $order,int $channel): array;
    public function prepay(array $intent,string $redirect): array;
    public function query(array $intent): ?array;
}
