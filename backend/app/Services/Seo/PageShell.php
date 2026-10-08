<?php

namespace App\Services\Seo;

/**
 * Renders a public catalog page into the website's own page shell (catalog-shell.html, built
 * by Vite with the site's header, footer, styles and scripts), so these pages look and behave
 * like the rest of the site while their content comes from the database.
 */
class PageShell
{
    private const HEAD = '/<!--seo:head-->.*?<!--\/seo:head-->/s';

    private const MAIN = '<!--seo:main-->';

    public function render(SeoPage $page): string
    {
        $head = view('seo.head', ['page' => $page])->render();
        $shell = $this->shell();
        if ($shell === null || ! str_contains($shell, self::MAIN) || preg_match(self::HEAD, $shell) !== 1) {
            return view('seo.fallback', ['head' => $head, 'main' => $page->main])->render();
        }

        // Callbacks, so "$1"-like text in the content is never read as a backreference.
        $html = preg_replace_callback(self::HEAD, fn () => $head, $shell, 1);

        return str_replace(self::MAIN, $page->main, (string) $html);
    }

    private function shell(): ?string
    {
        $path = (string) config('seo.shell_path');
        $html = $path !== '' && is_file($path) ? file_get_contents($path) : false;

        return $html === false ? null : $html;
    }
}
