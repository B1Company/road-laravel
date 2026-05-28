<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

/**
 * Structured "why" record for an authorization decision. Same shape
 * Road's `/iam/authorization/authorize` returns under the debug
 * header, and what `RoadAuthzException::trace()` exposes. Mirrors
 * `apps/sdks/road-nestjs/src/permissions/decision-trace.ts` 1:1 so
 * the rendered message is identical across SDKs (SDK_DX_BAR §11).
 */
final readonly class DecisionTrace
{
    /**
     * @param  list<string>  $required
     * @param  list<array{via:string, permissions:list<string>}>  $grants
     * @param  list<string>|null  $evaluatedScopes
     */
    public function __construct(
        public string $subject,
        public string $scope,
        public array $required,
        public array $grants,
        public string $verdict,
        public string $reason,
        public ?array $evaluatedScopes = null,
    ) {
    }

    /** @param  array<string,mixed>  $data */
    public static function fromArray(array $data): self
    {
        /** @var list<array{via:string, permissions:list<string>}> $grants */
        $grants = [];
        foreach ((array) ($data['grants'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $perms = [];
            foreach ((array) ($entry['permissions'] ?? []) as $perm) {
                if (is_string($perm)) {
                    $perms[] = $perm;
                }
            }
            $grants[] = [
                'via'         => (string) ($entry['via'] ?? ''),
                'permissions' => $perms,
            ];
        }

        /** @var list<string> $required */
        $required = [];
        foreach ((array) ($data['required'] ?? []) as $r) {
            if (is_string($r)) {
                $required[] = $r;
            }
        }

        /** @var list<string>|null $evaluated */
        $evaluated = null;
        if (isset($data['evaluatedScopes']) && is_array($data['evaluatedScopes'])) {
            $evaluated = [];
            foreach ($data['evaluatedScopes'] as $s) {
                if (is_string($s)) {
                    $evaluated[] = $s;
                }
            }
        }

        return new self(
            subject: (string) ($data['subject'] ?? ''),
            scope: (string) ($data['scope'] ?? ''),
            required: $required,
            grants: $grants,
            verdict: (string) ($data['verdict'] ?? 'deny'),
            reason: (string) ($data['reason'] ?? ''),
            evaluatedScopes: $evaluated,
        );
    }

    /**
     * Render the trace as a human-friendly multi-line string. Line-for-
     * line identical to NestJS's `formatDecisionTrace` so support tickets
     * can be diffed across SDKs.
     */
    public function format(): string
    {
        $lines = [];
        $lines[] = '  Required:   '.implode(', ', $this->required);
        $lines[] = '  Subject:    '.$this->subject;
        $lines[] = '  Scope:      '.$this->scope;
        if ($this->grants === []) {
            $lines[] = '  Grants:     (none)';
        } else {
            $lines[] = '  Grants:';
            foreach ($this->grants as $grant) {
                $lines[] = '    - via '.$grant['via'].': '.implode(', ', $grant['permissions']);
            }
        }
        $lines[] = '  Reason:     '.$this->reason;

        return implode("\n", $lines);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = [
            'subject'  => $this->subject,
            'scope'    => $this->scope,
            'required' => $this->required,
            'grants'   => $this->grants,
            'verdict'  => $this->verdict,
            'reason'   => $this->reason,
        ];
        if ($this->evaluatedScopes !== null) {
            $data['evaluatedScopes'] = $this->evaluatedScopes;
        }

        return $data;
    }
}
