<?php

declare(strict_types=1);

namespace Ylog;

final class Config
{
    public bool $disabled = false;
    public bool $enableWrite = true;
    public bool $enableOutput = false;
    public bool $enableTimeByName = true;
    public bool $createNewSection = true;
    public bool $useNameAsDir = false;
    public bool $skipVendor = false;
    public bool $deferServerInfo = false;
    public bool $throwErrors = false;

    /**
     * @var list<string>
     */
    public array $disableUris = [];

    public string $basePath = './logs';
    public string $logDirectory = '';
    public string $logFilePrefix = '';
    public string $documentRoot = '/var/www/';
    public int $stackDepth = 3;
    public string $formatType = 'min'; // min|debug|proc
    public string $dataFormat = 'echo'; // echo|easy|print_r|php

    /**
     * Current method name (set by __callStatic proxy).
     */
    public ?string $currentMethod = null;
}
