<?php

namespace App\Deploy\Webhooks;

use Illuminate\Http\Request;

/**
 * Reads a push webhook from GitHub, GitLab, Bitbucket Cloud, Gitea/Forgejo or
 * any sender that signs the body the GitHub way. Everything in the request is
 * untrusted until verify() has passed.
 */
final class PushWebhook
{
    /** Headers carrying "sha256=<hex HMAC of the body>" (Gitea's has no prefix). */
    private const SIGNATURE_HEADERS = ['X-Hub-Signature-256', 'X-Gitea-Signature', 'X-Hub-Signature'];

    public function __construct(private readonly Request $request) {}

    /**
     * Whether the sender knows the secret. Compared in constant time; SHA-1
     * signatures are refused.
     */
    public function verify(string $secret): bool
    {
        $gitlab = $this->request->header('X-Gitlab-Token');

        if (is_string($gitlab)) {
            return hash_equals($secret, $gitlab);
        }

        foreach (self::SIGNATURE_HEADERS as $header) {
            $signature = $this->request->header($header);

            if (! is_string($signature)) {
                continue;
            }

            $signature = strtolower(trim($signature));

            if (str_contains($signature, '=') && ! str_starts_with($signature, 'sha256=')) {
                return false;
            }

            $expected = hash_hmac('sha256', $this->request->getContent(), $secret);

            return hash_equals($expected, preg_replace('/^sha256=/', '', $signature) ?? '');
        }

        return false;
    }

    /**
     * The test event GitHub sends when the webhook is created.
     */
    public function isPing(): bool
    {
        return strtolower((string) $this->request->header('X-GitHub-Event')) === 'ping';
    }

    /**
     * False for events that are clearly not pushes (issues, tags, merge requests ...).
     */
    public function isPush(): bool
    {
        $events = [
            'X-GitHub-Event' => 'push',
            'X-Gitea-Event' => 'push',
            'X-Gitlab-Event' => 'push hook',
            'X-Event-Key' => 'repo:push',
        ];

        foreach ($events as $header => $push) {
            $value = $this->request->header($header);

            if (is_string($value) && $value !== '') {
                return strtolower($value) === $push;
            }
        }

        return true;
    }

    /**
     * The branch the push went to, or null when it was not a branch push
     * (a tag, a deleted branch, an unknown payload).
     */
    public function branch(): ?string
    {
        $payload = $this->request->json()->all();

        if (isset($payload['ref']) && is_string($payload['ref'])) {
            if (($payload['deleted'] ?? false) === true) {
                return null;
            }

            return str_starts_with($payload['ref'], 'refs/heads/') ? substr($payload['ref'], 11) : null;
        }

        $new = $payload['push']['changes'][0]['new'] ?? null;

        return is_array($new) && ($new['type'] ?? null) === 'branch' && is_string($new['name'] ?? null) ? $new['name'] : null;
    }

    /**
     * The commit pushed, for the audit log, when the payload says.
     */
    public function commit(): ?string
    {
        $payload = $this->request->json()->all();
        $sha = $payload['after'] ?? $payload['checkout_sha'] ?? $payload['push']['changes'][0]['new']['target']['hash'] ?? null;

        return is_string($sha) && preg_match('/^[0-9a-f]{7,64}$/', $sha) === 1 ? $sha : null;
    }
}
