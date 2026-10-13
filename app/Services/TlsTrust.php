<?php

namespace App\Services;

/**
 * Trust in the TLS certificate of a notification server (Mattermost, a webhook), set per channel: the certificates
 * the server presents (its own self-signed one, or the CA that issued it), or no check at all. curl trusts the
 * certificates besides the system's CAs (its CA directory stays); the host name is still checked.
 */
class TlsTrust
{
    const TIMEOUT = 5;
    const HINT_UNTRUSTED = "The server's certificate is not trusted: use Fetch from the server under Server certificate, or accept any certificate.";
    const HINT_NAME = 'The certificate is not for this host name: put the name it was issued for in the address, or accept any certificate.';
    const HINT_VALIDITY = "The server's certificate has expired or is not valid yet: check the clock of this system, or renew the certificate on the server.";

    /* Guzzle options for a channel's requests: no check at all, these certificates besides the system's, or the defaults */
    public static function options(?string $pem, bool $insecure): array
    {
        if ($insecure)
            return ['verify' => false];
        if ($pem === null || trim($pem) === '')
            return [];
        if (!defined('CURLOPT_CAINFO_BLOB'))
            throw new \RuntimeException("A trusted certificate needs PHP's curl extension (php-curl)");
        return ['curl' => [CURLOPT_CAINFO_BLOB => $pem]];
    }

    /**
     * The certificates of a PEM text in their order; null when it holds none or a broken one
     * @return array[]|null ['subject', 'issuer', 'names' (DNS names and IP addresses), 'valid_to', 'sha256', 'self_signed', 'pem']
     */
    public static function describe(string $text): ?array
    {
        if (!preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $text, $m))
            return null;
        $certs = [];
        foreach ($m[0] as $block)
        {
            $cert = @openssl_x509_read($block);
            if ($cert === false)
                return null;
            $certs[] = self::certificate($cert);
        }
        return $certs;
    }

    /* The certificates of a PEM text as the bundle to store; null without any, with a broken one or with a private key */
    public static function normalize(string $text): ?string
    {
        if (strpos($text, 'PRIVATE KEY') !== false)
            return null;
        $certs = self::describe($text);
        return $certs ? implode('', array_column($certs, 'pem')) : null;
    }

    /* Whether a certificate stands for the host: one of its names (a wildcard covers one label), or the common name
       of a certificate without names */
    public static function covers(array $cert, string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        $ip = @inet_pton($host);
        foreach ($cert['names'] ?: [strtolower($cert['subject'])] as $name)
        {
            if ($ip !== false)
            {
                if (@inet_pton($name) === $ip)
                    return true;
                continue;
            }
            if ($name === $host)
                return true;
            if (strncmp($name, '*.', 2) === 0)
            {
                $suffix = substr($name, 1);
                $label = substr($host, 0, -strlen($suffix));
                if (substr($host, -strlen($suffix)) === $suffix && $label !== '' && strpos($label, '.') === false)
                    return true;
            }
        }
        return false;
    }

    /* "host:port" of an https address: the trust in a certificate is kept for that server only; null for other addresses */
    public static function addressKey(?string $url): ?string
    {
        $parts = $url ? parse_url($url) : false;
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']))
            return null;
        return strtolower($parts['host']).':'.($parts['port'] ?? 443);
    }

    /**
     * What the https server at $url presents: its certificate first, then the CAs it sends along; and whether the
     * system trusts it as it is. Throws a RuntimeException for an address without https or a server that does not answer.
     * The one method that goes to the network, and the one taken from the container: a test stands in for it.
     * @return array ['trusted' => bool, 'chain' => describe() entries]
     */
    public function fetch(string $url): array
    {
        $key = self::addressKey($url);
        if ($key === null)
            throw new \RuntimeException('Only an https:// address has a certificate to fetch.');
        $host = trim(substr($key, 0, strrpos($key, ':')), '[]');

        $stream = self::connect($key, ['capture_peer_cert_chain' => true, 'verify_peer' => false, 'verify_peer_name' => false,
                                       'peer_name' => $host], $error);
        if (!$stream)
            throw new \RuntimeException('Could not connect to '.$key.': '.$error);
        $chain = stream_context_get_params($stream)['options']['ssl']['peer_certificate_chain'] ?? [];
        fclose($stream);

        $trusted = self::connect($key, ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host], $error);
        if ($trusted)
            fclose($trusted);
        return ['trusted' => (bool) $trusted, 'chain' => array_map([self::class, 'certificate'], $chain)];
    }

    /* The message of a failed request, after what to do when the server's certificate was the reason; advice about a
       certificate of its own only for a channel that can have one ($trustable) */
    public static function explain(\Throwable $e, bool $trustable = true): string
    {
        $message = $e->getMessage();
        if (preg_match('/certificate has expired|certificate is not yet valid/i', $message))
            return self::HINT_VALIDITY.' '.$message;
        if (!$trustable)
            return $message;
        if (preg_match('/no alternative certificate subject name|subject name .* does not match target/i', $message))
            return self::HINT_NAME.' '.$message;
        if (preg_match('/cURL error 60|SSL certificate problem|certificate verify failed/i', $message))
            return self::HINT_UNTRUSTED.' '.$message;
        return $message;
    }

    /* A TLS connection to "host:port", or false with the reason in $error */
    protected static function connect(string $key, array $ssl, &$error)
    {
        error_clear_last();
        $stream = @stream_socket_client('ssl://'.$key, $errno, $message, self::TIMEOUT, STREAM_CLIENT_CONNECT,
                                        stream_context_create(['ssl' => $ssl]));
        if (!$stream)
            $error = trim($message.' '.(error_get_last()['message'] ?? '')) ?: 'no answer';
        return $stream;
    }

    protected static function certificate(\OpenSSLCertificate $cert): array
    {
        $info = openssl_x509_parse($cert);
        openssl_x509_export($cert, $pem);
        $names = [];
        foreach (explode(',', $info['extensions']['subjectAltName'] ?? '') as $entry)
            if (preg_match('/^\s*(DNS|IP Address):\s*(.+?)\s*$/', $entry, $m))
            {
                $ip = $m[1] === 'IP Address' ? @inet_pton($m[2]) : false;
                $names[] = $ip !== false ? inet_ntop($ip) : strtolower($m[2]);
            }
        return [
            'subject' => self::name($info['subject'] ?? []),
            'issuer' => self::name($info['issuer'] ?? []),
            'names' => $names,
            'valid_to' => $info['validTo_time_t'] ?? null,
            'sha256' => strtoupper(implode(':', str_split(openssl_x509_fingerprint($cert, 'sha256'), 2))),
            'self_signed' => ($info['subject'] ?? null) == ($info['issuer'] ?? null),
            'pem' => $pem,
        ];
    }

    /* The common name, or the other parts of a name without one: "O=Line, OU=IT" */
    protected static function name(array $dn): string
    {
        if (isset($dn['CN']))
            return is_array($dn['CN']) ? implode(', ', $dn['CN']) : (string) $dn['CN'];
        $parts = [];
        foreach ($dn as $key => $value)
            $parts[] = $key.'='.(is_array($value) ? implode('+', $value) : $value);
        return implode(', ', $parts);
    }
}
