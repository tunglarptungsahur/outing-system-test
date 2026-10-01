<?php
// Student photos. Files live OUTSIDE the web root (private folder,
// storage/photos/<std_no>.jpg) and are only served through
// student_photo.php after a login check. Uploaded by an admin
// (manage_photos.php); students can't change their own picture, so the
// guard can trust it when checking identity at the gate.

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui_helpers.php';

const PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const PHOTO_MAX_SIDE  = 480; // px, longest side after resize

function photo_dir(): string
{
    return __DIR__ . '/storage/photos';
}

function valid_std_no_for_file(string $stdNo): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{1,20}$/', $stdNo);
}

function student_photo_file(string $stdNo): ?string
{
    if (!valid_std_no_for_file($stdNo)) {
        return null;
    }
    $file = photo_dir() . '/' . $stdNo . '.jpg';
    return is_file($file) ? $file : null;
}

/**
 * Validates an uploaded image and stores it as a JPEG (resized when GD
 * is available). Throws InvalidArgumentException with a safe message.
 */
function save_student_photo(string $stdNo, string $tmpFile): void
{
    if (!valid_std_no_for_file($stdNo)) {
        throw new InvalidArgumentException('Invalid student number in file name.');
    }
    if (filesize($tmpFile) > PHOTO_MAX_BYTES) {
        throw new InvalidArgumentException('Image is larger than 5 MB.');
    }
    $info = @getimagesize($tmpFile);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new InvalidArgumentException('Not a JPEG, PNG or WebP image.');
    }
    if (!is_dir(photo_dir()) && !mkdir(photo_dir(), 0775, true) && !is_dir(photo_dir())) {
        throw new RuntimeException('Cannot create the photo folder.');
    }
    $dest = photo_dir() . '/' . $stdNo . '.jpg';

    if (!function_exists('imagecreatefromjpeg')) {
        // No GD: only a real JPEG can be stored as-is.
        if ($info[2] !== IMAGETYPE_JPEG) {
            throw new InvalidArgumentException('Server has no GD extension; please upload a JPEG.');
        }
        if (!copy($tmpFile, $dest)) {
            throw new RuntimeException('Could not save the image.');
        }
        return;
    }

    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpFile),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmpFile),
        default        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : false,
    };
    if (!$img) {
        throw new InvalidArgumentException('Image could not be read.');
    }

    // Phones store rotation in EXIF; re-encoding drops it, so apply it first.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmpFile);
        $rot  = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
        if ($rot !== 0 && ($rotated = imagerotate($img, $rot, 0))) {
            $img = $rotated;
        }
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, PHOTO_MAX_SIDE / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // PNG transparency -> white
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (!imagejpeg($out, $dest, 85)) {
        throw new RuntimeException('Could not save the image.');
    }
}

function count_student_photos(): int
{
    return is_dir(photo_dir()) ? count(glob(photo_dir() . '/*.jpg') ?: []) : 0;
}

/** <img> if the student has a photo, otherwise an initials box. */
function student_photo_html(string $stdNo, string $name, int $size = 96): string
{
    $style = 'width:' . $size . 'px;height:' . $size . 'px;border-radius:8px;';
    if (student_photo_file($stdNo) !== null) {
        return '<img src="student_photo.php?std_no=' . urlencode($stdNo) . '" alt="' . h($name)
             . '" style="' . $style . 'object-fit:cover;background:#eee;">';
    }
    return '<div title="No photo on file" style="' . $style . 'background:#e2e8f0;color:#4a5568;display:flex;'
         . 'align-items:center;justify-content:center;font-weight:bold;font-size:' . (int) ($size / 3) . 'px;">'
         . h(initials($name)) . '</div>';
}
