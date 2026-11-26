IO_WebP
=======

Japanese version: [README.md](README.md)

This is a library for interpreting and dumping the chunk structure of the WebP (RIFF container) binary. It requires IO_Bit.
It lists the chunks and reads the header values of VP8 (lossy), VP8L (lossless), VP8X (extended) and ALPH (alpha).
It does not decode images or write WebP (no build).
Not limited in use, free to copy and modify by MIT license.
Please contact me if it doesn't work in your environment or you want new features.

# License

MIT License

# Install

```
% composer require yoya/io_webp
```

# Usage

- webpdump.php (dump of the chunk structure)

```
% php vendor/yoya/io_webp/sample/webpdump.php
Usage: php webpdump.php [-h] -f <webpfile>
% php vendor/yoya/io_webp/sample/webpdump.php -f input.webp
FourCC:RIFF Size:1610 Signature:WEBP
Header:VP8  TagSize:1598
    key_frame:0 version:0 show_frame:1 first_part_size:942
    width:640 horizontal_scale:0 height:480 vertical_scale:0
% php vendor/yoya/io_webp/sample/webpdump.php -f alpha.webp
FourCC:RIFF Size:982 Signature:WEBP
Header:VP8X TagSize:10
    I(icc):0 L(alpha):1 E(exif):0 X(xmp):0 A(anim):0
    image_width:640 image_height:480
Header:ALPH TagSize:202
    P(process):0 F(filter):0 C(compress):1
    (alpha bitstream)
Header:VP8  TagSize:742
    key_frame:0 version:0 show_frame:1 first_part_size:587
    width:640 horizontal_scale:0 height:480 vertical_scale:0
```

- From PHP

```php
require_once 'IO/WebP.php';

$webp = new IO_WebP();
$webp->parse(file_get_contents('alpha.webp'));
foreach ($webp->ChunkList as $chunk) {
    echo $chunk['Header'], ' ', $chunk['TagSize'], PHP_EOL;  // "VP8X 10", "ALPH 202", "VP8  742"
}
$vp8x = $webp->ChunkList[0];
echo $vp8x['image_width'], 'x', $vp8x['image_height'], ' alpha:', $vp8x['L'], PHP_EOL;  // 640x480 alpha:1
```

# Design overview

| Class | Role |
|---|---|
| `IO_WebP` | The whole WebP. `parse` / `dump` |
| `IO_Bit` (separate package) | Byte- and bit-level reading and writing |

- `parse()` stores the RIFF header in `FileHeader` (`FourCC`, `Size`, `Signature`) and lists the chunks in `ChunkList`.
  Each chunk has `Header` (FourCC) and `TagSize`, and the following types also have their header values. The chunk payload bytes are not kept.
  - `VP8 `: `key_frame` (the frame type bit as is; 0 is a key frame) `version` `show_frame` `first_part_size` `width` `height` `horizontal_scale` `vertical_scale`
  - `VP8L`: `image_width` `image_height` `alpha_is_used` `version_number`
  - `VP8X`: flags `I` (ICC) `L` (alpha) `E` (Exif) `X` (XMP) `A` (animation), and the canvas `image_width` `image_height`
  - `ALPH`: `P` (preprocessing) `F` (filtering) `C` (compression)
- The 1-byte padding after an odd-sized chunk is skipped.
- Other chunks (ICCP, EXIF, XMP, ANIM, ANMF, etc.) have only `Header` and `TagSize`.

# Known limitations

- The VP8L header values (`image_width` `image_height` `alpha_is_used` `version_number`) are not correct: the leading signature byte is not skipped and the bits are read in the wrong order.
- The chunks inside ANMF (animation frames) are not parsed.
- `-h` of `webpdump.php` does nothing (`dump()` ignores its options).

# Test

```
% php tests/run.php
```

IO_Bit is loaded from vendor/ (composer install) or include_path.

# Reference

- https://developers.google.com/speed/webp/docs/riff_container
- https://developers.google.com/speed/webp/docs/webp_lossless_bitstream_specification
- https://datatracker.ietf.org/doc/html/rfc6386 (VP8)
