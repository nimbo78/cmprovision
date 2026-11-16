<?php
namespace App\Http\Controllers;

use App\Models\Cm;
use App\Models\UsbDiagnosticReport;
use App\Services\ModuleReport;

class ModuleReportController extends Controller
{
    public function module(string $mac, string $format, ModuleReport $reports)
    {
        $cm = Cm::findByKey($mac);
        abort_unless($cm, 404);
        return $this->download($reports->forModule($cm), $format);
    }

    public function usb(int $id, string $format, ModuleReport $reports)
    {
        return $this->download($reports->forUsb(UsbDiagnosticReport::findOrFail($id)), $format);
    }

    private function download(array $report, string $format)
    {
        $name = 'cm-'.preg_replace('/[^A-Za-z0-9_-]/', '', substr($report['identity']['serial'] ?? 'unknown', 0, 32)).'-report.'.$format;
        $headers = ['Cache-Control'=>'no-store', 'X-Content-Type-Options'=>'nosniff',
            'Content-Disposition'=>'attachment; filename="'.$name.'"'];
        if ($format === 'json') return response()->json($report, 200, $headers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        abort_unless($format === 'html', 404);
        // Embed the actual project palette and font, so downloaded reports remain self-contained.
        preg_match('/:root,\s*:root\[data-style="warm"\]\[data-mode="light"\]\s*\{[^}]+\}/s',
            file_get_contents(resource_path('css/app.css')), $palette);
        $style = ($palette[0] ?? '').file_get_contents(public_path('css/module-report.css'));
        $font = base64_encode(file_get_contents(public_path('fonts/nunito-v16-latin-regular.woff2')));
        $style .= "@font-face{font-family:Nunito;src:url(data:font/woff2;base64,$font) format('woff2')}";
        $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'";
        return response()->view('reports.module', ['report'=>$report, 'style'=>$style], 200, $headers);
    }
}
