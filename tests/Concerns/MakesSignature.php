<?php

namespace Tests\Concerns;

/**
 * Images as the drawing box delivers them (data URI with a PNG), to imitate
 * the digital signature in tests.
 */
trait MakesSignature
{
    /** A transparent box with a drawn line: a valid signature. */
    protected function signature(int $width = 600, int $height = 160): string
    {
        $image = $this->emptyBox($width, $height);
        imagesetthickness($image, 3);
        imageline($image, 20, (int) ($height * 0.7), $width - 20, (int) ($height * 0.3), imagecolorallocatealpha($image, 28, 25, 23, 0));

        return $this->asDataUri($image);
    }

    /** A transparent box without a line: what arrives when the guest draws nothing. */
    protected function emptySignatureBox(): string
    {
        return $this->asDataUri($this->emptyBox(600, 160));
    }

    /** Fully white and opaque: also empty, even though it is not transparent. */
    protected function whiteBox(): string
    {
        $image = imagecreatetruecolor(600, 160);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        return $this->asDataUri($image);
    }

    private function emptyBox(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }

    private function asDataUri(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
