<?php

namespace App\Support;

use App\Models\Booking;
use GdImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A digital signature under the rental terms: drawn with mouse or
 * finger in the booking form, with the guest's name next to it.
 *
 * Legally an ordinary electronic signature (eIDAS). The evidentiary value
 * comes from what is recorded: who, when, from which IP address and with
 * which browser, plus a fingerprint of exactly the signed text. The
 * image is kept in protected storage (disk "local"), not in public/.
 */
final class Signature
{
    /** Limits on what we accept: no huge or odd files. */
    private const MAX_CHARACTERS = 400_000;

    private const MAX_WIDTH = 2400;

    private const MAX_HEIGHT = 1200;

    /** Minimum number of drawn pixels (in the sample): not an empty box. */
    private const MIN_INK = 40;

    /**
     * Minimum size of the drawing as a share of the box, so not a dot or a
     * dash: as a share, the same on every screen (a phone has far more
     * pixels per millimetre). The drawing box checks the same (signature.js).
     */
    public const MIN_WIDTH_SHARE = 0.25;

    public const MIN_HEIGHT_SHARE = 0.15;

    private function __construct(
        private readonly string $png,
        public readonly string $name,
        private readonly ?string $ip,
        private readonly ?string $browser,
    ) {}

    /**
     * From the booking form. Checks the image and re-encodes it,
     * so that nothing but the pixels themselves is ever stored.
     *
     * @throws ValidationException for a missing, unreadable or empty signature
     */
    public static function offRequest(Request $request, string $name): self
    {
        $data = (string) $request->input('signature', '');
        $prefix = 'data:image/png;base64,';

        if ($data === '') {
            throw self::error(__('Sign the rental terms below.'));
        }

        if (strlen($data) > self::MAX_CHARACTERS || ! str_starts_with($data, $prefix)) {
            throw self::error(__('The signature could not be read. Please sign again.'));
        }

        $bytes = base64_decode(substr($data, strlen($prefix)), true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);

        if ($info === false
            || $info[2] !== IMAGETYPE_PNG
            || $info[0] > self::MAX_WIDTH
            || $info[1] > self::MAX_HEIGHT) {
            throw self::error(__('The signature could not be read. Please sign again.'));
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw self::error(__('The signature could not be read. Please sign again.'));
        }

        // A palette PNG returns a palette index per pixel instead of a
        // colour; convert it first, otherwise the count below is wrong.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        [$ink, $width, $height] = self::measure($image);

        // Nothing drawn at all, or something too small to be a signature (a dot, a dash).
        if ($ink === 0) {
            throw self::error(__('Sign the rental terms below.'));
        }

        if ($ink < self::MIN_INK
            || $width < self::MIN_WIDTH_SHARE * imagesx($image)
            || $height < self::MIN_HEIGHT_SHARE * imagesy($image)) {
            throw self::error(__('Your signature is too small. Please sign again using more of the box.'));
        }

        // Re-encode: only the pixels are kept, nothing else from the file.
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $clean = (string) ob_get_clean();

        return new self($clean, trim($name), $request->ip(), Str::limit((string) $request->userAgent(), 500, ''));
    }

    /** Fingerprint (SHA-256) of a text. */
    public static function fingerprint(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * Stores the image protected and records everything on the booking.
     * Should run inside the booking's transaction, after saving
     * the accepted text (terms_snapshot).
     */
    public function record(Booking $booking): void
    {
        // Random part in the name: not guessable, even with the booking number.
        $path = 'signatures/'.$booking->booking_number.'-'.Str::random(20).'.png';
        Storage::disk('local')->put($path, $this->png);

        $booking->forceFill([
            'signature_name' => $this->name,
            'signature_path' => $path,
            'signature_hash' => self::fingerprint((string) $booking->terms_snapshot),
            'signed_at' => now(),
            'signature_ip' => $this->ip,
            'signature_user_agent' => $this->browser,
        ])->save();
    }

    /**
     * The pixels that were drawn on (not transparent and not nearly white, so
     * that a fully white picture also counts as empty), and the width and
     * height of the drawing in pixels. Sampled every other pixel, since more
     * is not needed for either.
     *
     * @return array{0: int, 1: int, 2: int} number of drawn pixels, width, height
     */
    private static function measure(GdImage $image): array
    {
        $count = 0;
        $left = $top = PHP_INT_MAX;
        $right = $bottom = -1;

        for ($x = 0, $width = imagesx($image); $x < $width; $x += 2) {
            for ($y = 0, $height = imagesy($image); $y < $height; $y += 2) {
                $color = imagecolorat($image, $x, $y);
                $transparent = ($color >> 24) & 0x7F; // 0 = opaque, 127 = transparent
                $brightness = ((($color >> 16) & 0xFF) + (($color >> 8) & 0xFF) + ($color & 0xFF)) / 3;

                if ($transparent < 64 && $brightness < 200) {
                    $count++;
                    $left = min($left, $x);
                    $right = max($right, $x);
                    $top = min($top, $y);
                    $bottom = max($bottom, $y);
                }
            }
        }

        return $count === 0 ? [0, 0, 0] : [$count, $right - $left + 1, $bottom - $top + 1];
    }

    private static function error(string $notification): ValidationException
    {
        return ValidationException::withMessages(['signature' => $notification]);
    }
}
