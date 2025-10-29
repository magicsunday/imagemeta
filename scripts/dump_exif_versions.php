<?php
require __DIR__ . '/../.build/vendor/autoload.php';

use MagicSunday\ImageMeta\Exif\ExifReader;
use MagicSunday\ImageMeta\MetadataReader;

$fixtureDir = __DIR__ . '/../tests/Fixtures/Images/ExifVersions';
$files = array_filter(scandir($fixtureDir), static fn ($name) => str_ends_with($name, '.jpg'));
sort($files);

$reader = new MetadataReader();
$apiReader = new ExifReader();

$result = [];

foreach ($files as $file) {
    $path = $fixtureDir . '/' . $file;
    $metadata = $reader->read($path);
    $structured = $metadata->structured();
    $api = $apiReader->read($path);
    $raw = $metadata->exifDoc;
    $maker = $metadata->makerNotes;

    $standards = $structured->technical->standards;
    $preview = $structured->media->preview;
    $interop = $structured->technical->interop;
    $image = $structured->media->image;
    $capture = $structured->capture->temporal;

    $makerInfo = null;
    if ($maker !== null) {
        $makerInfo = [
            'vendor' => $maker->vendor(),
            'length' => $maker->length(),
            'sha1' => $maker->sha1(),
            'isSafe' => $maker->isSafe(),
        ];
    }

    $sensor = $structured->sensor->hardware;

    $env = [
        'temperatureC' => $raw?->temperatureCelsius(),
        'humidityPercent' => $raw?->humidityPercent(),
        'pressureHpa' => $raw?->pressureHPa(),
    ];

    $sfr = $sensor->spatialFrequencyResponse;

    $result[$file] = [
        'structured' => [
            'standards' => [
                'exifVersion' => $standards->exifVersion(),
                'profile' => $standards->profile(),
                'flashpixVersion' => $standards->flashpixVersion(),
                'tiffEpStandardId' => $standards->tiffEpStandardId(),
                'tiffEpStandardString' => $standards->tiffEpStandardString(),
            ],
            'exposure' => [
                'iso' => $structured->exposure->iso,
            ],
            'capture' => [
                'dateTimeOriginal' => $capture->original?->format(DATE_ATOM),
                'offsetTimeOriginal' => $capture->offsetTimeOriginal,
                'subSecTimeOriginal' => $capture->subSecTimeOriginal,
            ],
            'image' => [
                'userComment' => $image->userComment(),
                'userCommentEncoding' => $image->userCommentEncoding(),
            ],
            'interop' => [
                'index' => $interop->index(),
                'version' => $interop->version(),
                'fileFormat' => $interop->relatedImageFileFormat(),
                'width' => $interop->relatedImageWidth(),
                'length' => $interop->relatedImageLength(),
            ],
            'preview' => [
                'hasThumbnail' => $preview->hasThumbnail(),
                'hasPreview' => $preview->hasPreview(),
                'previewOffset' => $preview->previewOffset(),
                'previewLength' => $preview->previewLength(),
                'previewWidth' => $preview->previewWidth(),
                'previewHeight' => $preview->previewHeight(),
                'previewBitDepth' => $preview->previewBitDepth(),
                'previewCompression' => $preview->previewCompression()?->value,
                'previewCompressionName' => $preview->previewCompression()?->name,
                'previewColorSpace' => $preview->previewColorSpace()?->value,
                'previewColorSpaceName' => $preview->previewColorSpace()?->name,
                'previewEncoding' => $preview->previewEncoding(),
                'previewMimeType' => $preview->previewMimeType(),
                'previewScale' => $preview->previewScale(),
            ],
            'makerNotes' => $makerInfo,
            'environment' => $env,
            'sensor' => [
                'spatialFrequencyResponse' => $sfr,
            ],
        ],
        'api' => [
            'iso' => $api->iso(),
            'dateTimeOriginal' => $api->dateTimeOriginal()?->format(DATE_ATOM),
            'userComment' => $api->userComment(),
            'userCommentEncoding' => $api->userCommentEncoding(),
            'interop' => [
                'index' => $api->interop()->index(),
                'version' => $api->interop()->version(),
                'fileFormat' => $api->interop()->relatedImageFileFormat(),
                'width' => $api->interop()->relatedImageWidth(),
                'length' => $api->interop()->relatedImageLength(),
            ],
            'preview' => [
                'hasThumbnail' => $api->preview()->hasThumbnail(),
                'hasPreview' => $api->preview()->hasPreview(),
                'previewOffset' => $api->preview()->previewOffset(),
                'previewLength' => $api->preview()->previewLength(),
                'previewWidth' => $api->preview()->previewWidth(),
                'previewHeight' => $api->preview()->previewHeight(),
                'previewBitDepth' => $api->preview()->previewBitDepth(),
                'previewCompression' => $api->preview()->previewCompression()?->value,
                'previewCompressionName' => $api->preview()->previewCompression()?->name,
                'previewColorSpace' => $api->preview()->previewColorSpace()?->value,
                'previewColorSpaceName' => $api->preview()->previewColorSpace()?->name,
                'previewEncoding' => $api->preview()->previewEncoding(),
                'previewMimeType' => $api->preview()->previewMimeType(),
                'previewScale' => $api->preview()->previewScale(),
            ],
        ],
        'model' => [
            'exifVersion' => $raw?->exifVersion(),
            'exifProfile' => $raw?->exifProfile(),
            'flashpixVersion' => $raw?->flashpixVersion(),
            'tiffEpStandardId' => $raw?->tiffEpStandardId(),
            'tiffEpStandardString' => $raw?->tiffEpStandardIdString(),
        ],
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
