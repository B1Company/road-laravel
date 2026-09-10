<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Payloads;

use Spatie\LaravelData\Data;

/**
 * Payload for `extension.install.{created,uninstalled}`. Wire shape of
 * `@b1-road/types` `ExtensionInstallWebhookData`.
 *
 * The tenant-scoped counterpart of {@see BridgeGrantWebhookData}: an extension
 * acts inside one business unit, so its access changes per install rather than
 * per platform. `uninstalled` is the one that matters most — the install's IAM
 * scope is soft-deleted, so a cached context would otherwise keep authorizing
 * a tenant that has removed the extension.
 */
final class ExtensionInstallWebhookData extends Data
{
    public function __construct(
        /** The extension (`ext_…`). */
        public string $extensionPublicId,
        /** The install (`exti_…`) — the tenant selector the data leg takes. */
        public string $installPublicId,
        /** The business unit the install belongs to. */
        public string $businessUnitId,
        /** The host platform the extension targets (`plat_…`). */
        public string $platformPublicId,
        /**
         * The role templates the install grants. Empty on `uninstalled`.
         *
         * @var list<string>
         */
        public array $grantedScopes,
    ) {}
}
