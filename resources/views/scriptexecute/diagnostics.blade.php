{{-- One collector for Ethernet scripts and the independent USB fallback agent. --}}
DIAG_SERIAL={!! escapeshellarg($serial) !!}
DIAG_TARGET={!! escapeshellarg($storage ?: '/dev/mmcblk0') !!}
DIAG_TOKEN={!! escapeshellarg($diagnosticsToken ?? '') !!}
DIAG_URL={!! escapeshellarg('http://'.$server.'/scriptexecute?serial='.rawurlencode($serial).'&diagnostics=1') !!}
{!! file_get_contents(resource_path('shell/module-diagnostics.sh')) !!}
cmp_diag_bind
