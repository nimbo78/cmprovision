<?php

namespace App\Support;

/** Only these static messages may cross the credential-delivery boundary. */
class ImageAccessFailure extends \RuntimeException
{
    public const MESSAGES = [
        'invalid_settings' => 'The installed OS access settings are invalid. Review the project.',
        'unsafe_path' => 'The image contains an unsupported filesystem path. Use a compatible image.',
        'unsupported_image' => 'The image needs arm64 Raspberry Pi OS-compatible tools: Python 3, shadow, sudo, OpenSSH and systemd.',
        'account_missing' => 'The Existing Linux user was not found. Review its name in the project.',
        'account_conflict' => 'The requested Linux login belongs to another account. Choose a different login or source user.',
        'account_ambiguous' => 'Several Linux accounts were found. Set Existing Linux user in the project.',
        'account_unsafe' => 'The selected account is a system account or has an unsupported home. Choose an interactive Linux user.',
        'rename_references' => 'Services or tasks depend on the existing login. Keep that login or adapt the image before renaming it.',
        'receipt_conflict' => 'This image already has another setup receipt. Start a fresh verified image write.',
        'ssh_policy' => 'The image SSH rules prevent the requested login. Review user/group restrictions, forced commands and authentication policy.',
        'command_failed' => 'An installed OS setup command failed. Check image compatibility and retry the provisioning run.',
        'identity_mismatch' => 'The board identity does not match this setup run. Start a fresh provisioning run.',
        'busy' => 'Installed OS access setup is already running. Retry after it finishes.',
    ];

    public function __construct(public readonly string $failureCode)
    {
        if (!isset(self::MESSAGES[$failureCode])) throw new \InvalidArgumentException('Unknown image access failure.');
        parent::__construct(self::MESSAGES[$failureCode]);
    }

    public function response(): string { return 'image-access:error:'.$this->failureCode; }

    public static function fromResponse(string $response): ?self
    {
        if (!preg_match('/^image-access:error:([a-z_]+)$/D', $response, $match) || !isset(self::MESSAGES[$match[1]])) return null;
        return new self($match[1]);
    }
}
