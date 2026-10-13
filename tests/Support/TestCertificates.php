<?php

namespace Tests\Support;

/* Certificates made on the fly for the TLS tests: self-signed ones, and CAs that sign others */
class TestCertificates
{
    /**
     * A certificate for $names (DNS names and IP addresses), signed by $issuer or by itself.
     * @param array|null $issuer what make() returned for the signing CA
     * @return array ['cert' => PEM, 'key' => PEM]
     */
    public static function make(string $cn, array $names = [], ?array $issuer = null, bool $ca = false, int $days = 30): array
    {
        $san = implode(', ', array_map(function ($name) {
            return (filter_var($name, FILTER_VALIDATE_IP) ? 'IP:' : 'DNS:').$name;
        }, $names));
        $config = tempnam(sys_get_temp_dir(), 'cmp-cnf');
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\n"
            .($ca ? "basicConstraints = critical, CA:TRUE\nkeyUsage = critical, keyCertSign, cRLSign, digitalSignature\n"
                  : "basicConstraints = critical, CA:FALSE\n")
            .($san !== '' ? "subjectAltName = $san\n" : ''));
        try
        {
            $options = ['config' => $config, 'digest_alg' => 'sha256'];
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $csr = openssl_csr_new(['commonName' => $cn], $key, $options);
            $cert = openssl_csr_sign($csr, $issuer['cert'] ?? null, $issuer['key'] ?? $key, $days,
                                     $options + ['x509_extensions' => 'ext'], random_int(1, PHP_INT_MAX));
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($key, $keyPem, null, $options);
            return ['cert' => $certPem, 'key' => $keyPem];
        }
        finally
        {
            unlink($config);
        }
    }
}
