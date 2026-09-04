<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Http\HttpClient;
use App\Shared\Http\HttpResponse;

/**
 * A scripted {@see HttpClient}: the queue in, the requests out.
 *
 * ADR-0038's rule is that **no test opens a socket**, and this is what makes
 * that affordable for a transport whose whole behaviour is a conversation —
 * token, create session, PUT ranges, retry, list, delete. Scripting the
 * answers is also the only way to exercise the paths that matter most and are
 * hardest to provoke against a real server: a 429 with a `Retry-After`, a
 * session that dies mid-upload, an expired token halfway through.
 *
 * It is a spy as well as a stub, because *what was sent* is half the contract:
 * a resumable upload that sends the wrong `Content-Range` is wrong in a way no
 * status code reveals.
 *
 * Lives in tests/Support rather than inside one test file because #691's
 * transport, its retention and its expiry warning are three test classes
 * against the same conversation.
 *
 * Part of #691, epic #686.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpResponse> */
    private array $queued = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string, file: ?string}> */
    private array $sent = [];

    /**
     * The response the next request gets. Queued in order; a request with
     * nothing left queued gets a 500 that says so, rather than a null the test
     * would then blame on the transport.
     *
     * @param array<string, string> $headers
     */
    public function willAnswer(int $status, string $body = '', array $headers = []): self
    {
        $this->queued[] = new HttpResponse($status, $body, $headers);

        return $this;
    }

    /** The transport-level failure: no server, no status, no body. */
    public function willFailToConnect(string $why = 'Could not resolve host'): self
    {
        $this->queued[] = new HttpResponse(0, $why);

        return $this;
    }

    public function send(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 30,
    ): HttpResponse {
        $this->sent[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'file' => null,
        ];

        return array_shift($this->queued) ?? new HttpResponse(
            500,
            'FakeHttpClient: the code under test made more requests than the test scripted. '
            . 'Request ' . count($this->sent) . ' was ' . strtoupper($method) . ' ' . $url . '.'
        );
    }

    /**
     * A file upload, recorded with the path instead of the bytes.
     *
     * The path rather than the contents on purpose: an archive is megabytes of
     * ciphertext, and a test asserting on it would be asserting that
     * `file_get_contents()` works. What the contract actually is — which verb,
     * which URL, which headers, *which file* — is all here, and the file's own
     * size is what the transport's verification step reads back.
     */
    public function sendFile(
        string $method,
        string $url,
        string $filePath,
        array $headers = [],
        int $timeoutSeconds = 30,
    ): HttpResponse {
        $this->sent[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => '',
            'file' => $filePath,
        ];

        return array_shift($this->queued) ?? new HttpResponse(
            500,
            'FakeHttpClient: the code under test made more requests than the test scripted. '
            . 'Request ' . count($this->sent) . ' was ' . strtoupper($method) . ' ' . $url . '.'
        );
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: string, file: ?string}> */
    public function requests(): array
    {
        return $this->sent;
    }

    public function requestCount(): int
    {
        return count($this->sent);
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: string, file: ?string} */
    public function request(int $index): array
    {
        return $this->sent[$index] ?? throw new \OutOfBoundsException(sprintf(
            'No request %d was made; there %s %d.',
            $index,
            count($this->sent) === 1 ? 'was' : 'were',
            count($this->sent)
        ));
    }

    /** @return list<string> every URL, in order — the shape of the conversation */
    public function urls(): array
    {
        return array_column($this->sent, 'url');
    }

    /** Everything queued was used: a test that scripts more than it needs is asserting nothing. */
    public function everythingQueuedWasUsed(): bool
    {
        return $this->queued === [];
    }
}
