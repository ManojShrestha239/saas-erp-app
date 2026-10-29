<?php

declare(strict_types=1);

namespace Shared\Services;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

final class PdfRendererService
{
    /**
     * @param  array{
     *     format?: string,
     *     landscape?: bool,
     *     margins?: array{top?: int|float, right?: int|float, bottom?: int|float, left?: int|float},
     *     timeout?: int,
     *     chrome_path?: string,
     *     node_path?: string,
     *     node_modules_path?: string
     * }  $options
     */
    public function renderHtml(string $html, array $options = []): string
    {
        $browsershot = Browsershot::html($html)
            ->noSandbox()
            ->showBackground()
            ->format($options['format'] ?? 'A4')
            ->margins(
                $options['margins']['top'] ?? 10,
                $options['margins']['right'] ?? 10,
                $options['margins']['bottom'] ?? 10,
                $options['margins']['left'] ?? 10,
            )
            ->addChromiumArguments([
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--no-sandbox',
                '--disable-setuid-sandbox',
            ]);

        if (! empty($options['landscape'])) {
            $browsershot->landscape();
        }

        if (! empty($options['timeout'])) {
            $browsershot->timeout($options['timeout']);
        }

        /** @var string $chromePath */
        $chromePath = $options['chrome_path'] ?? config('services.browsershot.chrome_path', '/usr/bin/chromium');
        if (file_exists($chromePath)) {
            $browsershot->setChromePath($chromePath);
        }

        /** @var string $nodeModulePath */
        $nodeModulePath = $options['node_modules_path'] ?? config('services.browsershot.node_modules_path', '/usr/local/lib/node_modules');
        if (is_dir($nodeModulePath)) {
            $browsershot->setNodeModulePath($nodeModulePath);
        }

        /** @var string $nodePath */
        $nodePath = $options['node_path'] ?? config('services.browsershot.node_path', '/usr/bin/node');
        if (file_exists($nodePath)) {
            $browsershot->setNodeBinary($nodePath);
        }

        return $browsershot->pdf();
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     * @param  array{
     *     format?: string,
     *     landscape?: bool,
     *     margins?: array{top?: int|float, right?: int|float, bottom?: int|float, left?: int|float},
     *     timeout?: int,
     *     chrome_path?: string,
     *     node_path?: string,
     *     node_modules_path?: string
     * }  $options
     */
    public function renderView(string $view, array $data = [], array $options = []): string
    {
        $html = View::make($view, $data)->render();

        return $this->renderHtml($html, $options);
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     * @param  array{
     *     format?: string,
     *     landscape?: bool,
     *     margins?: array{top?: int|float, right?: int|float, bottom?: int|float, left?: int|float},
     *     timeout?: int,
     *     chrome_path?: string,
     *     node_path?: string,
     *     node_modules_path?: string
     * }  $options
     */
    public function streamView(string $view, array $data = [], string $filename = 'document.pdf', array $options = []): Response
    {
        $pdfBytes = $this->renderView($view, $data, $options);

        return new Response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-transform, no-store, must-revalidate',
        ]);
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     * @param  array{
     *     format?: string,
     *     landscape?: bool,
     *     margins?: array{top?: int|float, right?: int|float, bottom?: int|float, left?: int|float},
     *     timeout?: int,
     *     chrome_path?: string,
     *     node_path?: string,
     *     node_modules_path?: string
     * }  $options
     */
    public function downloadView(string $view, array $data = [], string $filename = 'document.pdf', array $options = []): Response
    {
        $pdfBytes = $this->renderView($view, $data, $options);

        return new Response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-transform, no-store, must-revalidate',
        ]);
    }
}
