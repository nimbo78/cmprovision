<?php

namespace Tests\Unit;

use App\Services\TlsTrust;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestCertificates;

/** Trust in a notification server's certificate: Guzzle options, certificates described, names, hints. */
class TlsTrustTest extends TestCase
{
    public function test_options_turn_the_check_off_or_trust_the_given_certificates()
    {
        $pem = TestCertificates::make('mm.line.local', ['mm.line.local'], null, true)['cert'];

        $this->assertSame(['verify' => false], TlsTrust::options($pem, true), 'no check at all wins');
        $this->assertSame(['curl' => [CURLOPT_CAINFO_BLOB => $pem]], TlsTrust::options($pem, false));
        $this->assertSame([], TlsTrust::options(null, false));
        $this->assertSame([], TlsTrust::options(" \n", false));
    }

    public function test_a_bundle_is_described_certificate_by_certificate()
    {
        $root = TestCertificates::make('Line Root CA', [], null, true);
        $server = TestCertificates::make('mm.line.local', ['mm.line.local', '10.0.0.5'], $root);

        $certs = TlsTrust::describe("text before\n".$server['cert']."\n".$root['cert']);

        $this->assertCount(2, $certs);
        $this->assertSame(['mm.line.local', 'Line Root CA', ['mm.line.local', '10.0.0.5'], false],
                          [$certs[0]['subject'], $certs[0]['issuer'], $certs[0]['names'], $certs[0]['self_signed']]);
        $this->assertTrue($certs[1]['self_signed']);
        $this->assertSame(strtoupper(implode(':', str_split(openssl_x509_fingerprint($server['cert'], 'sha256'), 2))), $certs[0]['sha256']);
        $this->assertGreaterThan(time() + 29 * 86400, $certs[0]['valid_to']);
        $this->assertSame(trim($server['cert']), trim($certs[0]['pem']));
    }

    public function test_text_without_a_certificate_or_with_a_private_key_is_no_trust()
    {
        $made = TestCertificates::make('mm.line.local', ['mm.line.local'], null, true);

        $this->assertNull(TlsTrust::describe('hello'));
        $this->assertNull(TlsTrust::describe("-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----"));
        $this->assertNull(TlsTrust::normalize($made['cert'].$made['key']), 'a private key is never stored');
        $this->assertSame(trim($made['cert'])."\n", TlsTrust::normalize("  \n".$made['cert']."\n\n"));
    }

    public function test_the_names_of_a_certificate_cover_its_hosts()
    {
        $cert = TlsTrust::describe(TestCertificates::make('mm', ['mm.line.local', '*.apps.line.local', '10.0.0.5', '::1'])['cert'])[0];

        foreach (['mm.line.local', 'MM.Line.Local', 'chat.apps.line.local', '10.0.0.5', '::1', '[::1]'] as $host)
            $this->assertTrue(TlsTrust::covers($cert, $host), $host);
        foreach (['line.local', 'a.b.apps.line.local', 'apps.line.local', '10.0.0.6', 'mm'] as $host)
            $this->assertFalse(TlsTrust::covers($cert, $host), $host);
        // a certificate without names stands for the host in its common name
        $this->assertTrue(TlsTrust::covers(TlsTrust::describe(TestCertificates::make('mm.line.local')['cert'])[0], 'mm.line.local'));
    }

    public function test_the_trust_is_bound_to_the_https_host_and_port()
    {
        $this->assertSame('mm.line.local:443', TlsTrust::addressKey('https://MM.line.local/'));
        $this->assertSame('10.0.0.5:8065', TlsTrust::addressKey('https://10.0.0.5:8065/hooks/x'));
        $this->assertSame('[::1]:443', TlsTrust::addressKey('https://[::1]/'));
        $this->assertNull(TlsTrust::addressKey('http://mm.line.local'));
        $this->assertNull(TlsTrust::addressKey('not a url'));
        $this->assertNull(TlsTrust::addressKey(null));
    }

    public function test_certificate_errors_say_what_to_do()
    {
        $untrusted = TlsTrust::explain(new \RuntimeException('cURL error 60: SSL certificate problem: self-signed certificate (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://10.0.0.5/api/v4/posts'));
        // the hint first: the list of channels shows only the start of an error
        $this->assertStringStartsWith("The server's certificate is not trusted", $untrusted);
        $this->assertStringContainsString('Fetch from the server', $untrusted);
        $this->assertStringContainsString('cURL error 60: SSL certificate problem: self-signed certificate', $untrusted);

        $name = TlsTrust::explain(new \RuntimeException("cURL error 60: SSL: no alternative certificate subject name matches target ipv4 address '10.0.0.5'"));
        $this->assertStringContainsString('the name it was issued for', $name);

        $this->assertSame('cURL error 7: Failed to connect', TlsTrust::explain(new \RuntimeException('cURL error 7: Failed to connect')));

        // out of its validity period: neither fetching nor trusting it helps
        foreach (['certificate has expired', 'certificate is not yet valid'] as $problem)
            foreach ([true, false] as $trustable)
                $this->assertStringStartsWith(TlsTrust::HINT_VALIDITY,
                    TlsTrust::explain(new \RuntimeException("cURL error 60: SSL certificate problem: $problem"), $trustable), $problem);

        // a channel without a certificate of its own (Telegram) gets no advice about one
        foreach (['cURL error 60: SSL certificate problem: unable to get local issuer certificate',
                  "cURL error 60: SSL: no alternative certificate subject name matches target host name 'api.telegram.org'"] as $message)
            $this->assertSame($message, TlsTrust::explain(new \RuntimeException($message), false));
    }
}
