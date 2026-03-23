<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRMarkupSVG;
use RobThree\Auth\Providers\Qr\IQRCodeProvider;

/**
 * QR code provider for robthree/twofactorauth that uses chillerlan/php-qrcode.
 * Pure PHP, no imagick/GD extensions required for SVG output.
 */
class ChillerlanQrProvider implements IQRCodeProvider
{
    public function getMimeType(): string
    {
        return 'image/svg+xml';
    }

    public function getQRCodeImage(string $qrText, int $size): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64'    => false,
            'scale'           => 4,
            'quietzoneSize'   => 2,
        ]);

        return (new QRCode($options))->render($qrText);
    }
}
