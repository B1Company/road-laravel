<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * Payment terms of an {@see EduzzProduct}. Wire shape of `@b1-road/types`
 * `EduzzProductPayment`.
 */
final class EduzzProductPayment extends Data
{
    /**
     * @param  array{currency:string,value:int|float}  $price  ISO 4217 currency + amount.
     * @param  array{bankslip:bool,pix:bool,card:bool,multipleCards:bool}  $methods  Accepted payment methods.
     */
    public function __construct(
        /** oneTime | subscription | free | open */
        public string $type,
        public array $price,
        public array $methods,
    ) {}
}
