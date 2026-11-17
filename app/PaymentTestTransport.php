<?php
declare(strict_types=1);
namespace Piscina;
interface PaymentTestTransport {
    public function request(string $method,string $path,?string $body=null,array $headers=[]): array;
}
