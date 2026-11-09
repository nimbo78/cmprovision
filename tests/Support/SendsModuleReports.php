<?php

namespace Tests\Support;

use App\Models\Cm;
use Illuminate\Testing\TestResponse;

/** Existing lifecycle tests simulate reports from the current script; rejection tests send explicit headers instead. */
trait SendsModuleReports
{
    protected function moduleReportHeaders(string $uri): array
    {
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);
        $token = Cm::where('serial', $query['serial'] ?? '')->value('report_token');
        return $token === null ? [] : ['X-Cmprovision-Run' => $token];
    }

    protected function moduleGet(string $uri): TestResponse
    {
        return $this->get($uri, $this->moduleReportHeaders($uri));
    }

    protected function modulePost(string $uri, array $data = []): TestResponse
    {
        return $this->post($uri, $data, $this->moduleReportHeaders($uri));
    }
}
