<?php
/**
 * Telegram Bot API client for one bot token.
 */
final class Tg
{
    /** Set TG_API_BASE to point at a fake API server in tests. */
    private string $base;

    public function __construct(public readonly string $token)
    {
        $this->base = rtrim((string) App::env('TG_API_BASE', 'https://api.telegram.org'), '/');
    }

    /**
     * Call a Bot API method.
     * $files: ['field' => '/path/to/file'] for multipart uploads.
     * Returns the decoded response: ['ok' => bool, 'result' => ..., 'description' => ..., 'error_code' => ...].
     */
    public function call(string $method, array $params = [], array $files = []): array
    {
        $ch = curl_init($this->base . '/bot' . $this->token . '/' . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);

        if ($files) {
            $post = [];
            foreach ($params as $key => $value) {
                if (is_array($value)) {
                    $post[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_bool($value)) {
                    $post[$key] = $value ? 'true' : 'false';
                } elseif ($value !== null) {
                    $post[$key] = (string) $value;
                }
            }
            foreach ($files as $key => $path) {
                $post[$key] = new CURLFile($path);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        } else {
            $params = array_filter($params, fn($v) => $v !== null);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $result = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($result)) {
            $result = ['ok' => false, 'error_code' => 0, 'description' => 'network error: ' . $error];
        }
        if (!$result['ok']) {
            App::log("tg $method failed: " . ($result['description'] ?? '?'));
        }
        return $result;
    }

    /** Shortcut: return 'result' or null. */
    public function get(string $method, array $params = [], array $files = []): mixed
    {
        $res = $this->call($method, $params, $files);
        return $res['ok'] ? $res['result'] : null;
    }
}
