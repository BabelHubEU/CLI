<?php

declare(strict_types=1);

namespace BabelHub\Cli\Checks;

/**
 * Ustalenie kontroli — jedna pozycja do pokazania człowiekowi i do rozgałęzienia w CI.
 *
 * Kształt jest celowo ten sam co przy ustaleniach QA z serwisu (`code`, `severity`, `key`,
 * `message`), bo dla pipeline'u to **ten sam rodzaj danych**: kod jest kontraktem, waga decyduje
 * o bramce. Różnica jest jedna i istotna: tutaj zamiast `key`/`locale` jest `file`/`line`,
 * bo kontrola dotyczy **kodu**, a nie katalogu tłumaczeń — i dlatego ten typ oraz
 * `TranslationFinding` są dwiema realizacjami `FindingInterface`, a nie jednym typem
 * z czterema opcjonalnymi polami.
 *
 * Pola zostają publiczne (czyta je skaner i testy), a `FindingInterface` dostaje metody —
 * dzięki temu wydruk i bramka są wspólne dla wszystkich kontroli, mimo różnych źródeł ustaleń.
 */
final readonly class CheckFinding implements FindingInterface
{
    public function __construct(
        public string $code,
        public CheckSeverity $severity,
        public string $file,
        public int $line,
        public string $text,
        public string $message,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function severity(): CheckSeverity
    {
        return $this->severity;
    }

    public function location(): string
    {
        return $this->file . ':' . $this->line;
    }

    public function message(): string
    {
        return $this->message;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'file' => $this->file,
            'line' => $this->line,
            'text' => $this->text,
            'message' => $this->message,
        ];
    }
}
