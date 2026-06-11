<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\Service;

/** The two service-to-service credential flows Road supports. */
enum ServiceCredentialKind: string
{
    case ClientCredentials = 'client_credentials';
    case PrivateKeyJwt = 'private_key_jwt';
}
