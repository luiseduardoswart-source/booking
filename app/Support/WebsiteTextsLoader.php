<?php

namespace App\Support;

use Illuminate\Contracts\Translation\Loader;

/** Reads the language files and layers the texts from the admin on top. */
class WebsiteTextsLoader implements Loader
{
    public function __construct(private Loader $files) {}

    public function load($locale, $group, $namespace = null)
    {
        $rules = $this->files->load($locale, $group, $namespace);

        return $group === '*' && $namespace === '*'
            ? [...$rules, ...WebsiteTexts::custom($locale)]
            : $rules;
    }

    /** @return array<string, string> */
    public function offFile(string $locale): array
    {
        return $this->files->load($locale, '*', '*');
    }

    public function addNamespace($namespace, $hint)
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->files->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->files->namespaces();
    }
}
