<?php

namespace Cinderwatch\WordPress\Health;

use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Wire\SnapshotSerializer;
use Cinderwatch\WordPress\Config\WordPressConfigResolver;
use Closure;
use WP_REST_Response;

/**
 * Serves the health snapshot at `/cinder/v1/health`, answered on `parse_request`
 * so pretty permalinks are not needed, and as the REST route `cinder/v1/health`.
 * Both answer 404 unless the request sends `Authorization: Bearer <secret>`.
 */
class HealthEndpoint
{
    public const PATH = '/cinder/v1/health';

    /** Apache only passes the header on under the second name after a rewrite. */
    private const AUTHORIZATION_SERVER_KEYS = ['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'];

    /**
     * @param  Closure(): Inspector  $inspector  resolved lazily so probes are only built when polled
     * @param  int  $reuseForSeconds  serve a stored snapshot younger than this instead of inspecting again
     */
    public function __construct(
        private readonly Closure $inspector,
        private readonly SnapshotTransient $transient = new SnapshotTransient,
        private readonly SnapshotSerializer $serializer = new SnapshotSerializer,
        private readonly int $reuseForSeconds = 300,
    ) {}

    public function register(): void
    {
        if (! WordPressConfigResolver::healthEndpointEnabled()) {
            return;
        }

        add_action('parse_request', $this->interceptPrettyPath(...), 0);
        add_action('rest_api_init', $this->registerRestRoute(...));
    }

    public function interceptPrettyPath(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        $path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '';

        if (! str_ends_with(rtrim($path, '/'), self::PATH)) {
            return;
        }

        if (! $this->isAuthorised()) {
            status_header(404);

            exit; // @codeCoverageIgnore
        }

        wp_send_json($this->payload(), 200);
    }

    public function registerRestRoute(): void
    {
        register_rest_route('cinder/v1', '/health', [
            'methods' => 'GET',
            'permission_callback' => fn (): bool => $this->isAuthorised(),
            'callback' => fn (): WP_REST_Response => new WP_REST_Response($this->payload(), 200, ['Content-Type' => 'application/json']),
        ]);
    }

    public function isAuthorised(): bool
    {
        $expected = WordPressConfigResolver::healthCheckSecret();
        $provided = $this->bearerToken();

        return $expected !== '' && $provided !== null && hash_equals($expected, $provided);
    }

    private function bearerToken(): ?string
    {
        foreach (self::AUTHORIZATION_SERVER_KEYS as $key) {
            $header = $_SERVER[$key] ?? null;

            if (is_string($header) && preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->serializer->snapshotToPayload($this->currentSnapshot());
    }

    private function currentSnapshot(): HealthSnapshot
    {
        $stored = $this->transient->load();

        if ($stored !== null && $stored->ageInSeconds() <= $this->reuseForSeconds) {
            return $stored;
        }

        $snapshot = ($this->inspector)()->inspect();
        $this->transient->save($snapshot);

        return $snapshot;
    }
}
