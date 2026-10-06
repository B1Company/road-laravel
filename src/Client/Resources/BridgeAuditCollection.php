<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\BridgeAuditEntry;

/**
 * A platform's own Bridge audit, newest first. Iterating walks every page;
 * `firstPage()` returns one. Mirrors `road.bridge.audit()` in
 * `@b1-road/node-core`.
 *
 * @extends Paginates<BridgeAuditEntry>
 */
final class BridgeAuditCollection extends Paginates
{
    public function __construct(
        HttpTransportInterface $http,
        private readonly string $platformId,
        private readonly string $direction,
        private readonly ?bool $allowed = null,
    ) {
        parent::__construct($http);
    }

    protected function listPath(): string
    {
        return '/developer/platforms/'.rawurlencode($this->platformId).'/bridge/audit';
    }

    protected function listQuery(): array
    {
        $query = ['direction' => $this->direction];
        if ($this->allowed !== null) {
            $query['allowed'] = $this->allowed ? 'true' : 'false';
        }

        return $query;
    }

    protected function mapRow(array $row): BridgeAuditEntry
    {
        return BridgeAuditEntry::from($row);
    }
}
