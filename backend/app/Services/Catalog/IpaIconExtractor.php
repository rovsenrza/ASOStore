<?php

namespace App\Services\Catalog;

use ZipArchive;

/**
 * The app icon an IPA carries as loose PNG files (the large one lives in Assets.car, which is
 * not read). The biggest loose icon is returned as a standard PNG; null when there is none.
 */
class IpaIconExtractor
{
    public function extract(string $ipaPath): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($ipaPath, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $best = null;
            $bestWidth = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (preg_match('#^Payload/[^/]+\.app/[^/]*(?:AppIcon|Icon)[^/]*\.png$#i', $name) !== 1) {
                    continue;
                }
                $stat = $zip->statIndex($index);
                if ($stat === false || $stat['size'] > 8 * 1024 * 1024) {
                    continue;
                }
                $png = CgbiPng::toStandard((string) $zip->getFromIndex($index));
                $size = $png === null ? false : @getimagesizefromstring($png);
                if ($size !== false && $size[0] > $bestWidth) {
                    [$best, $bestWidth] = [$png, $size[0]];
                }
            }

            return $best;
        } finally {
            $zip->close();
        }
    }
}
