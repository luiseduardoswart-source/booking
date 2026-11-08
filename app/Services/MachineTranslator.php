<?php

namespace App\Services;

interface MachineTranslator
{
    /**
     * Translate a text from one language to another.
     *
     * @throws \RuntimeException if no translation service is configured
     */
    public function translate(string $text, string $from, string $to): string;
}
