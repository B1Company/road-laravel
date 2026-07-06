<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * A single Eduzz product owned by the authenticated user (the "producer").
 * Wire shape of `@b1-road/types` `EduzzProduct` — the `GET /me/eduzz/products`
 * item. The string fields carry Eduzz's own enum values (type, status,
 * moderation); they're kept as strings so a new upstream value never breaks
 * decoding.
 */
final class EduzzProduct extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        /** Eduzz producer (account) id that owns the product. */
        public string $producerId,
        /** digital | physical | service | ticket | ecommerce | package | project */
        public string $type,
        /** active | inactive */
        public string $status,
        public string $author,
        /** new | approved | pending | refused */
        public string $moderation,
        /** Product thumbnail URL — the only media field (products have no file). */
        public string $imageUrl,
        /** ISO-8601. */
        public string $createdAt,
        /** ISO-8601. */
        public string $updatedAt,
        public EduzzProductPayment $payment,
    ) {}
}
