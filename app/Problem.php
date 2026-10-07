<?php
declare(strict_types=1);
namespace Piscina;
class Problem extends \RuntimeException {
    public function __construct(string $message, public readonly int $status = 422, public readonly string $error = 'validation') { parent::__construct($message); }
}
