<?php

namespace Tests\Feature;

use App\Http\Livewire\NotificationSettings;
use App\Models\NotificationChannel;
use App\Services\TlsTrust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\TestCertificates;
use Tests\TestCase;

/**
 * Trust in a notification server's certificate against a real TLS server: openssl s_server on 127.0.0.1 with
 * certificates made for the test. Skipped without the openssl command or PHP's curl.
 */
class TlsServerTest extends TestCase
{
    use RefreshDatabase;

    protected $dir, $servers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('curl_init') || trim((string) shell_exec('command -v openssl')) === '')
            $this->markTestSkipped('needs the openssl command and php-curl');
        $this->dir = sys_get_temp_dir().'/cmp-tls-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as [$process, $pipes])
        {
            proc_terminate($process);
            fclose($pipes[0]);
            proc_close($process);
        }
        if ($this->dir)
        {
            array_map('unlink', glob($this->dir.'/*'));
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    /* openssl s_server with this certificate (and the CAs it sends along), answering GET with a page; its address */
    protected function serve(array $made, ?string $chain = null): string
    {
        $n = count($this->servers);
        file_put_contents("$this->dir/cert$n.pem", $made['cert']);
        file_put_contents("$this->dir/key$n.pem", $made['key']);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $command = ['openssl', 's_server', '-quiet', '-www', '-accept', "127.0.0.1:$port",
                    '-cert', "$this->dir/cert$n.pem", '-key', "$this->dir/key$n.pem"];
        if ($chain !== null)
        {
            file_put_contents("$this->dir/chain$n.pem", $chain);
            array_push($command, '-cert_chain', "$this->dir/chain$n.pem");
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->servers[] = [$process, $pipes];
        for ($i = 0; $i < 50; $i++)
        {
            $socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 0.2);
            if ($socket)
            {
                fclose($socket);
                return "https://127.0.0.1:$port/";
            }
            usleep(100000);
        }
        $this->fail('openssl s_server did not start');
    }

    protected function page(string $url, array $options)
    {
        return Http::timeout(5)->withOptions($options)->get($url);
    }

    public function test_a_self_signed_server_needs_its_certificate_or_no_check_at_all()
    {
        $made = TestCertificates::make('mm.line.local', ['127.0.0.1'], null, true);
        $url = $this->serve($made);

        try
        {
            $this->page($url, TlsTrust::options(null, false));
            $this->fail('the system trusts no self-signed certificate');
        }
        catch (ConnectionException $e)
        {
            $this->assertStringStartsWith(TlsTrust::HINT_UNTRUSTED, TlsTrust::explain($e));
        }
        $this->assertTrue($this->page($url, TlsTrust::options($made['cert'], false))->successful());
        $this->assertTrue($this->page($url, TlsTrust::options(null, true))->successful());

        // another self-signed certificate, even with the same names, is no trust in this server
        $this->expectException(ConnectionException::class);
        $this->page($url, TlsTrust::options(TestCertificates::make('mm.line.local', ['127.0.0.1'], null, true)['cert'], false));
    }

    public function test_a_certificate_for_another_name_is_refused_with_what_to_do()
    {
        $made = TestCertificates::make('mm.line.local', ['mm.line.local'], null, true);
        $url = $this->serve($made);

        try
        {
            $this->page($url, TlsTrust::options($made['cert'], false));
            $this->fail('the host name is still checked');
        }
        catch (ConnectionException $e)
        {
            $this->assertStringStartsWith(TlsTrust::HINT_NAME, TlsTrust::explain($e));
        }
        $this->assertTrue($this->page($url, TlsTrust::options(null, true))->successful());
    }

    public function test_fetching_gives_what_the_server_presents()
    {
        $made = TestCertificates::make('mm.line.local', ['127.0.0.1'], null, true);

        $fetched = (new TlsTrust)->fetch($this->serve($made));

        $this->assertFalse($fetched['trusted']);
        $this->assertSame([TlsTrust::describe($made['cert'])[0]['sha256']], array_column($fetched['chain'], 'sha256'));
        $this->assertTrue(TlsTrust::covers($fetched['chain'][0], '127.0.0.1'));
    }

    public function test_the_issuer_fetched_with_the_certificate_outlives_its_renewal()
    {
        $root = TestCertificates::make('Line Root CA', [], null, true);
        $issuing = TestCertificates::make('Line Issuing CA', [], $root, true);

        // the server sends its certificate and the issuing CA, not the root
        $fetched = (new TlsTrust)->fetch($this->serve(TestCertificates::make('mm', ['127.0.0.1'], $issuing), $issuing['cert']));
        $this->assertSame(['mm', 'Line Issuing CA'], array_column($fetched['chain'], 'subject'));

        // renewed: a new key and certificate from the same issuing CA
        $url = $this->serve(TestCertificates::make('mm', ['127.0.0.1'], $issuing), $issuing['cert']);
        $this->assertTrue($this->page($url, TlsTrust::options(implode('', array_column($fetched['chain'], 'pem')), false))->successful());
    }

    public function test_the_settings_form_fetches_the_certificate_and_warns_about_another_name()
    {
        $made = TestCertificates::make('mm.line.local', ['mm.line.local'], null, true);
        $url = $this->serve($made);

        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'Line')->set('type', 'webhook')->set('url', $url.'hooks/x')
            ->call('fetchCertificate')
            ->assertHasNoErrors()
            ->assertSet('tlsCa', TlsTrust::normalize($made['cert']))
            ->assertSee(TlsTrust::describe($made['cert'])[0]['sha256'])
            ->assertSee('The certificate is for mm.line.local, not for 127.0.0.1')
            // fetched from one server, it is no trust in another
            ->set('url', 'https://chat.line.local/hooks/x')
            ->call('save')
            ->assertHasErrors(['tlsCa'])
            ->set('url', $url.'hooks/x')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('127.0.0.1:'.parse_url($url, PHP_URL_PORT), NotificationChannel::firstOrFail()->setting('tls_ca_for'));
    }

    public function test_fetching_from_nowhere_or_without_https_says_why()
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $closed = 'https://127.0.0.1:'.substr(strrchr(stream_socket_get_name($probe, false), ':'), 1).'/';
        fclose($probe);

        foreach (['http://127.0.0.1/' => 'https://', $closed => 'Could not connect'] as $url => $why)
        {
            try
            {
                (new TlsTrust)->fetch($url);
                $this->fail($url);
            }
            catch (\RuntimeException $e)
            {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
    }
}
